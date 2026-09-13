<?php
/**
 * 卖家多账号商品快速发布与管理系统 - PHP 后端 API 接口
 * 数据库连接配置与 RESTful 响应处理
 */

// 禁用 HTML 错误信息直接输出（防止输出 <br /><b> 格式破坏前端 JSON 解析）
ini_set('display_errors', '0');
error_reporting(E_ALL);

// 全局异常捕捉：确保所有未捕获异常（如数据库 SQL 错误）均输出规范 JSON
set_exception_handler(function ($e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    
    $msg = $e->getMessage();
    // 原始异常信息里可能含 SQL 语句、字段名乃至连接账号，只写日志不回显
    @error_log('ShopTextManager uncaught: ' . $msg);

    // 识别最常见的“数据表不存在”错误，给出可操作的提示（不附带原始 SQL）
    $friendly = '服务器接口错误，详细原因已写入 PHP 错误日志';
    if (strpos($msg, '1146 Table') !== false || strpos($msg, '42S02') !== false) {
        $friendly = '数据库表不存在！请在宝塔面板的 MySQL 管理中导入项目的 schema.sql 脚本文件。';
    }

    echo json_encode([
        'status' => 'error',
        'message' => $friendly
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// 全局 Error 捕捉
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    // 只有真正的致命错误才中断请求。
    // E_WARNING / E_NOTICE / E_DEPRECATED 在老部署上很常见（例如数据库用 CREATE TABLE IF NOT EXISTS
    // 建表后不会补新列，读取 $cfg['image_source'] 之类新字段就会触发「未定义索引」）。
    // 若把这类告警也转成 500 并 exit，会让整个定时发布循环静默死掉，且日志里只有一句 Notice，极难排查。
    $fatalErrors = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array($errno, $fatalErrors, true)) {
        @error_log("ShopTextManager PHP[{$errno}]: {$errstr} in {$errfile}:{$errline}");
        return true; // 已处理，不再交给 PHP 内部处理器，请求继续执行
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    // 细节只写服务端日志：响应里带上绝对路径/源码片段等于给攻击者送地图
    @error_log("ShopTextManager FATAL[{$errno}]: {$errstr} in {$errfile}:{$errline}");
    echo json_encode([
        'status' => 'error',
        'message' => '服务器内部错误，详细原因已写入 PHP 错误日志'
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

$cors_origin = getenv('CORS_ALLOW_ORIGIN') ?: '*';
header("Access-Control-Allow-Origin: {$cors_origin}");
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 零依赖自动加载同目录下的 .env 文件 (若存在)
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim(trim($val), "\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

// 数据库连接配置 (可直接修改以下默认值，或通过 .env / 系统环境变量 DB_HOST / DB_USER / DB_PASS / DB_NAME 配置)
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: 'xianyu_db';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'root';

try {
    $pdo = new PDO("mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    // 连接异常信息含主机/库名/账号，不能回显给匿名请求者；只写日志
    @error_log('ShopTextManager DB connect failed: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => '数据库连接失败，请检查宝塔中 .env 文件或 api.php 顶部的数据库配置（主机、端口、用户名、密码、数据库名）。详细原因已写入 PHP 错误日志。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========================================================
// 自动建表 / 补列（自愈）
//
// ⚠️ 顺序至关重要：必须先 CREATE TABLE IF NOT EXISTS，再 SHOW COLUMNS。
// 旧实现把 `SHOW COLUMNS FROM xianyu_config` 放在建表语句之前，
// 而老部署（有 templates/games，但没有 v2.0 的 xianyu_config）执行它必然抛 42S02，
// 异常被同一个 try 吞掉 → 后面的建表语句永远不执行 →
// 该库永久停留在「表不存在」状态：每次请求都 500，且永远无法自愈。
// 这里改成：先建表、再补列，且每个步骤独立 try/catch 并记录日志，
// 任何一步失败都不会拖垮其余步骤。
// ========================================================

/**
 * 建表（IF NOT EXISTS，已存在则无操作）。
 */
function ensureTable(PDO $pdo, string $table, string $ddl): bool {
    try {
        $pdo->exec($ddl);
        return true;
    } catch (Throwable $e) {
        @error_log("ShopTextManager ensureTable({$table}) failed: " . $e->getMessage());
        return false;
    }
}

/**
 * 补列：表不存在或单列 ALTER 失败都只记日志，不影响其它步骤。
 *
 * @param array<string,string> $columns 列名 => DDL 片段
 */
function ensureColumns(PDO $pdo, string $table, array $columns): void {
    try {
        $exists = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll() as $col) {
            $exists[$col['Field']] = true;
        }
    } catch (Throwable $e) {
        // 表本身还不存在（例如全新安装且 schema.sql 也没导入成功），交给建表步骤处理
        @error_log("ShopTextManager ensureColumns({$table}) cannot read columns: " . $e->getMessage());
        return;
    }
    foreach ($columns as $colName => $ddl) {
        if (isset($exists[$colName])) continue;
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$colName}` {$ddl}");
        } catch (Throwable $e) {
            @error_log("ShopTextManager ADD COLUMN {$table}.{$colName} failed: " . $e->getMessage());
        }
    }
}

// 1) 全新安装：核心表基本都没建时，整体导入 schema.sql
try {
    $requiredTables = ['games', 'templates', 'published_logs', 'xianyu_config', 'xianyu_publish_logs'];
    $existingTables = [];
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $existingTables[strtolower((string)$t)] = true;
    }
    $missing = [];
    foreach ($requiredTables as $t) {
        if (!isset($existingTables[strtolower($t)])) $missing[] = $t;
    }
    // 缺 3 张以上视为"基本没导入过"（老部署最多只缺 v2.0 的两张）
    if (count($missing) >= 3) {
        $sqlFile = __DIR__ . '/schema.sql';
        if (file_exists($sqlFile)) {
            $sqlContent = (string)file_get_contents($sqlFile);
            // 移除 CREATE DATABASE 和 USE 指令，直接导入当前连接的 DB
            $sqlContent = (string)preg_replace('/CREATE\s+DATABASE.*?;/is', '', $sqlContent);
            $sqlContent = (string)preg_replace('/^\s*USE\s+`?[\w]+`?\s*;/im', '', $sqlContent);
            try {
                $pdo->exec($sqlContent);
            } catch (Throwable $e) {
                @error_log("ShopTextManager schema.sql import failed: " . $e->getMessage());
            }
        }
    } elseif (count($missing) > 0) {
        @error_log("ShopTextManager: missing tables " . implode(',', $missing) . " — 将由建表步骤补齐");
    }
} catch (Throwable $e) {
    @error_log("ShopTextManager table check failed: " . $e->getMessage());
}

// 2) 先建 v2.0 新表（老库一定没有，必须先于任何 SHOW COLUMNS）
ensureTable($pdo, 'xianyu_config', "CREATE TABLE IF NOT EXISTS `xianyu_config` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `account_key` VARCHAR(50) NOT NULL UNIQUE,
        `xy_server_url` VARCHAR(500) NOT NULL DEFAULT '',
        `xy_secret_key` VARCHAR(128) NOT NULL DEFAULT '',
        `xy_account_id` VARCHAR(80) NOT NULL DEFAULT '',
        `xy_account_remark` VARCHAR(100) DEFAULT '',
        `publish_enabled` TINYINT(1) NOT NULL DEFAULT 0,
        `publish_times` VARCHAR(200) DEFAULT '09:00',
        `publish_price` DECIMAL(10,2) NOT NULL DEFAULT 9.90,
        `publish_original_price` DECIMAL(10,2) DEFAULT 0,
        `publish_address` VARCHAR(200) DEFAULT '',
        `publish_quantity` INT NOT NULL DEFAULT 1,
        `publish_shipping_method` VARCHAR(20) DEFAULT 'free',
        `publish_category_id` VARCHAR(64) DEFAULT '',
        `publish_category_name` VARCHAR(100) DEFAULT '',
        `publish_channel_cat_id` VARCHAR(64) DEFAULT '',
        `publish_channel_cat_name` VARCHAR(100) DEFAULT '',
        `publish_leaf_id` VARCHAR(64) DEFAULT '',
        `publish_tb_cat_id` VARCHAR(64) DEFAULT '',
        `publish_category_auto` TINYINT(1) NOT NULL DEFAULT 1,
        `image_source` VARCHAR(10) DEFAULT 'auto',
        `custom_image_url` TEXT,
        `last_publish_at` DATETIME DEFAULT NULL,
        `last_publish_status` VARCHAR(20) DEFAULT '',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_publish_enabled` (`publish_enabled`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

ensureTable($pdo, 'xianyu_publish_logs', "CREATE TABLE IF NOT EXISTS `xianyu_publish_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `account_key` VARCHAR(50) NOT NULL,
        `game_id` INT NOT NULL,
        `game_name_cn` VARCHAR(100) DEFAULT '',
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `trigger_type` VARCHAR(20) DEFAULT 'scheduled',
        `message` TEXT,
        `item_url` VARCHAR(500) DEFAULT '',
        `item_id` VARCHAR(100) DEFAULT '',
        `api_response` TEXT,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_account_key` (`account_key`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 3) 再补列（此时表一定已存在）
// v2.0: games 表新增封面图字段
ensureColumns($pdo, 'games', [
    'cover_url' => "VARCHAR(255) DEFAULT '' COMMENT '游戏封面图片链接'",
]);

// 兼容中间版本部署：补齐 xianyu_config 后续新增的列，
// 否则 SELECT 引用不存在的列会直接抛异常，管理员控制台整块打不开。
ensureColumns($pdo, 'xianyu_config', [
    'xy_account_remark'        => "VARCHAR(100) DEFAULT ''",
    'publish_original_price'   => "DECIMAL(10,2) DEFAULT 0",
    'publish_category_id'      => "VARCHAR(64) DEFAULT ''",
    'publish_category_name'    => "VARCHAR(100) DEFAULT ''",
    'publish_channel_cat_id'   => "VARCHAR(64) DEFAULT ''",
    'publish_channel_cat_name' => "VARCHAR(100) DEFAULT ''",
    'publish_leaf_id'          => "VARCHAR(64) DEFAULT ''",
    'publish_tb_cat_id'        => "VARCHAR(64) DEFAULT ''",
    // 老库补列默认 0：存量配置里可能已有手工填好的分类，不能因为升级就被自动匹配覆盖。
    // 新装走 CREATE TABLE / schema.sql 的默认 1（每单自动匹配）。三件套为空时无论如何都会自动补全。
    'publish_category_auto'    => "TINYINT(1) NOT NULL DEFAULT 0",
    'image_source'             => "VARCHAR(10) DEFAULT 'auto'",
    'custom_image_url'         => "TEXT",
    'last_publish_at'          => "DATETIME DEFAULT NULL",
    'last_publish_status'      => "VARCHAR(20) DEFAULT ''",
]);

$action = $_GET['action'] ?? '';

// 工具函数：通用 JSON 输出
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取请求体数据
$input = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {
    // ----------------------------------------------------
    // 1. 免密登录 / 验证账号
    // ----------------------------------------------------
    case 'login':
        $username = trim($_GET['username'] ?? $input['username'] ?? '');
        if (empty($username)) {
            jsonResponse(['status' => 'error', 'message' => '请输入账号 Key (用户名)']);
        }

        $stmt = $pdo->prepare("SELECT * FROM templates WHERE account_key = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            jsonResponse(['status' => 'error', 'message' => '账号不存在，请联系管理员在控制台添加！']);
        }

        $isAdmin = ($user['account_key'] === 'admin' || !empty($user['is_admin']));
        jsonResponse([
            'status' => 'success',
            'user' => [
                'account_key' => $user['account_key'],
                'template_text' => $user['template_text'],
                'is_admin' => $isAdmin,
                'role' => $isAdmin ? 'admin' : 'sub_account'
            ]
        ]);
        break;

    // ----------------------------------------------------
    // 2. 获取账号列表及模版
    // ----------------------------------------------------
    case 'get_accounts':
        $stmt = $pdo->query("SELECT id, account_key, template_text, is_admin, created_at FROM templates ORDER BY id ASC");
        $accounts = $stmt->fetchAll();
        foreach ($accounts as &$a) {
            $a['is_admin'] = ($a['account_key'] === 'admin' || !empty($a['is_admin']));
        }
        jsonResponse(['status' => 'success', 'accounts' => $accounts]);
        break;

    // ----------------------------------------------------
    // 3. 获取游戏列表及指定账号的隐藏状态
    // ----------------------------------------------------
    case 'get_games':
        $account_key = $_GET['account_key'] ?? 'admin';

        // 获取全部有效游戏
        $stmt = $pdo->query("SELECT id, game_name_cn, game_name_en, COALESCE(cover_url, '') AS cover_url, is_active FROM games WHERE is_active = 1 ORDER BY id ASC");
        $games = $stmt->fetchAll();

        // 获取当前账号已经发布的记录
        $stmt = $pdo->prepare("SELECT game_id FROM published_logs WHERE account_key = ?");
        $stmt->execute([$account_key]);
        $publishedGameIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 拼接双语义名称与标志
        foreach ($games as &$g) {
            $g['is_published'] = in_array($g['id'], $publishedGameIds);
            // 兼容合成字段
            $g['game_name'] = !empty($g['game_name_en']) ? "{$g['game_name_cn']} ({$g['game_name_en']})" : $g['game_name_cn'];
        }

        jsonResponse(['status' => 'success', 'games' => $games]);
        break;

    // ----------------------------------------------------
    // 4. 管理员仪表板数据 (Dashboard Stats)
    // ----------------------------------------------------
    case 'get_dashboard_stats':
        // 游戏总量
        $totalGames = $pdo->query("SELECT COUNT(*) FROM games WHERE is_active = 1")->fetchColumn();
        // 账号总量
        $totalAccounts = $pdo->query("SELECT COUNT(*) FROM templates")->fetchColumn();
        // 总发布次数
        $totalPublishes = $pdo->query("SELECT COUNT(*) FROM published_logs")->fetchColumn();

        // 各账号发布详细分布
        $stmt = $pdo->query("
            SELECT t.account_key, COUNT(p.game_id) as published_count
            FROM templates t
            LEFT JOIN published_logs p ON t.account_key = p.account_key
            GROUP BY t.account_key
            ORDER BY t.id ASC
        ");
        $accountStats = $stmt->fetchAll();

        jsonResponse([
            'status' => 'success',
            'stats' => [
                'total_games' => (int)$totalGames,
                'total_accounts' => (int)$totalAccounts,
                'total_publishes' => (int)$totalPublishes,
                'account_details' => $accountStats
            ]
        ]);
        break;

    // ----------------------------------------------------
    // 5. 发布/隐退单个商品日志
    // ----------------------------------------------------
    case 'publish_game':
        $account_key = $input['account_key'] ?? '';
        $game_id = (int)($input['game_id'] ?? 0);

        if (empty($account_key) || $game_id <= 0) {
            jsonResponse(['status' => 'error', 'message' => '参数缺失'], 400);
        }

        $stmt = $pdo->prepare("INSERT IGNORE INTO published_logs (account_key, game_id) VALUES (?, ?)");
        $stmt->execute([$account_key, $game_id]);

        jsonResponse(['status' => 'success', 'message' => '已成功发布并记入归档！']);
        break;

    // ----------------------------------------------------
    // 5b. 取消隐藏 / 恢复单个游戏
    // ----------------------------------------------------
    case 'unpublish_game':
        $account_key = $input['account_key'] ?? '';
        $game_id = (int)($input['game_id'] ?? 0);

        if (empty($account_key) || $game_id <= 0) {
            jsonResponse(['status' => 'error', 'message' => '参数缺失'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM published_logs WHERE account_key = ? AND game_id = ?");
        $stmt->execute([$account_key, $game_id]);

        jsonResponse(['status' => 'success', 'message' => '已成功取消隐藏，恢复至待发布列表！']);
        break;

    // ----------------------------------------------------
    // 6. 重置/恢复指定账号的所有游戏显示
    // ----------------------------------------------------
    case 'reset_account_games':
        $account_key = $input['account_key'] ?? '';
        if (empty($account_key)) {
            jsonResponse(['status' => 'error', 'message' => '参数缺失'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM published_logs WHERE account_key = ?");
        $stmt->execute([$account_key]);

        jsonResponse(['status' => 'success', 'message' => '账号发布日志已重置，游戏列表已恢复显示！']);
        break;

    // ----------------------------------------------------
    // 7. 更新模板 (支持修改自身模板)
    // ----------------------------------------------------
    case 'update_template':
        $account_key = $input['account_key'] ?? '';
        $template_text = $input['template_text'] ?? '';

        if (empty($account_key)) {
            jsonResponse(['status' => 'error', 'message' => '参数缺失'], 400);
        }

        $stmt = $pdo->prepare("UPDATE templates SET template_text = ? WHERE account_key = ?");
        $stmt->execute([$template_text, $account_key]);

        jsonResponse(['status' => 'success', 'message' => '模板修改成功']);
        break;

    // ----------------------------------------------------
    // 8. 管理员一键导入商品 (格式: [参数1]+[参数2]，支持 \n 换行)
    // ----------------------------------------------------
    case 'import_games':
        $raw_text = $input['import_text'] ?? '';
        if (empty(trim($raw_text))) {
            jsonResponse(['status' => 'error', 'message' => '导入内容不能为空']);
        }

        // 查询数据库中已有商品建立对比映射表，防止重复写入
        $existingStmt = $pdo->query("SELECT game_name_cn, game_name_en FROM games");
        $existingMap = [];
        while ($row = $existingStmt->fetch()) {
            $key = trim($row['game_name_cn']) . '|||' . trim($row['game_name_en']);
            $existingMap[$key] = true;
        }

        // 支持真实换行与字面量 \n 字符串
        $normalized_text = str_replace(['\n', "\r"], ["\n", ""], $raw_text);
        $lines = explode("\n", $normalized_text);
        $importedCount = 0;
        $skippedCount = 0;
        $seenInBatch = [];

        $stmtInsert = $pdo->prepare("INSERT INTO games (game_name_cn, game_name_en, is_active) VALUES (?, ?, 1)");

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $cn = '';
            $en = '';

            // 仅采用 [参数1]+[参数2] 格式解析 (如 [黑神话：悟空]+[Black Myth: Wukong])
            if (preg_match('/^\[(.*?)\](?:\s*\+\s*\[(.*?)\])?$/u', $line, $matches)) {
                $cn = trim($matches[1]);
                $en = isset($matches[2]) ? trim($matches[2]) : '';
            } else if (preg_match('/\[(.*?)\](?:\s*\+\s*\[(.*?)\])?/u', $line, $matches)) {
                $cn = trim($matches[1]);
                $en = isset($matches[2]) ? trim($matches[2]) : '';
            } else {
                $cn = $line;
                $en = '';
            }

            if (!empty($cn)) {
                $uniqueKey = $cn . '|||' . $en;
                // 检测是否存在于已存储库或批次内重复
                if (isset($existingMap[$uniqueKey]) || isset($seenInBatch[$uniqueKey])) {
                    $skippedCount++;
                    continue;
                }
                $seenInBatch[$uniqueKey] = true;
                $existingMap[$uniqueKey] = true;

                $stmtInsert->execute([$cn, $en]);
                $importedCount++;
            }
        }

        if ($importedCount === 0 && $skippedCount > 0) {
            jsonResponse(['status' => 'info', 'message' => "导入列表中所有商品（共 {$skippedCount} 款）均已存在于库中，未重复添加"]);
        } else if ($skippedCount > 0) {
            jsonResponse(['status' => 'success', 'message' => "成功导入 {$importedCount} 款新商品，自动过滤并跳过 {$skippedCount} 款重复商品"]);
        } else {
            jsonResponse(['status' => 'success', 'message' => "成功批量导入 {$importedCount} 款商品"]);
        }
        break;

    // ----------------------------------------------------
    // 9. 单个添加商品
    // ----------------------------------------------------
    case 'add_game':
        $game_name_cn = trim($input['game_name_cn'] ?? '');
        $game_name_en = trim($input['game_name_en'] ?? '');

        if (empty($game_name_cn)) {
            jsonResponse(['status' => 'error', 'message' => '商品主名称不能为空']);
        }

        // 支持 \n 字符串与真实换行符
        $game_name_cn = str_replace('\n', "\n", $game_name_cn);
        $game_name_en = str_replace('\n', "\n", $game_name_en);

        // 重复校验：严格查重（主名称 + 副名称）
        $checkStmt = $pdo->prepare("SELECT id FROM games WHERE game_name_cn = ? AND game_name_en = ?");
        $checkStmt->execute([$game_name_cn, $game_name_en]);
        if ($checkStmt->fetch()) {
            jsonResponse(['status' => 'error', 'message' => '该商品（名称与型号完全一致）已存在，无需重复添加！']);
        }

        $stmt = $pdo->prepare("INSERT INTO games (game_name_cn, game_name_en, is_active) VALUES (?, ?, 1)");
        $stmt->execute([$game_name_cn, $game_name_en]);

        jsonResponse(['status' => 'success', 'message' => '商品添加成功']);
        break;

    // ----------------------------------------------------
    // 10. 删除游戏
    // ----------------------------------------------------
    case 'delete_game':
        $game_id = (int)($input['game_id'] ?? 0);
        if ($game_id <= 0) {
            jsonResponse(['status' => 'error', 'message' => '参数错误'], 400);
        }

        try {
            $pdo->beginTransaction();

            $stmtLogs = $pdo->prepare("DELETE FROM published_logs WHERE game_id = ?");
            $stmtLogs->execute([$game_id]);

            $stmtGame = $pdo->prepare("DELETE FROM games WHERE id = ?");
            $stmtGame->execute([$game_id]);

            $pdo->commit();
            jsonResponse(['status' => 'success', 'message' => '游戏及其发布隐藏日志已关联删除']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @error_log('ShopTextManager delete_game failed: ' . $e->getMessage());
            jsonResponse(['status' => 'error', 'message' => '删除失败，详细原因已写入 PHP 错误日志'], 500);
        }
        break;

    // ----------------------------------------------------
    // 11. 添加新账号 (支持设置是否为管理员)
    // ----------------------------------------------------
    case 'add_account':
        $account_key = trim($input['account_key'] ?? '');
        $template_text = trim($input['template_text'] ?? '');
        $is_admin = !empty($input['is_admin']) ? 1 : 0;

        if (empty($account_key)) {
            jsonResponse(['status' => 'error', 'message' => '账号标识 (用户名) 不能为空'], 400);
        }

        if (empty($template_text)) {
            $template_text = "【{{VAR_1}} ({{VAR_2}}) Steam正品游戏安装包 离线版/中文版】\n⚡ 自动发货 | 包含全套完整安装包+DLC扩展+终身更新\n✅ 送详细视频教程+远程协助安装 简体中文 免Steam繁琐步骤";
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO templates (account_key, template_text, is_admin) VALUES (?, ?, ?)");
            $stmt->execute([$account_key, $template_text, $is_admin]);
            jsonResponse(['status' => 'success', 'message' => '账号创建成功']);
        } catch (Exception $e) {
            jsonResponse(['status' => 'error', 'message' => '账号标识 (用户名) 已存在，请换一个'], 400);
        }
        break;

    // ----------------------------------------------------
    // 12. 设置或取消管理员权限 (保证至少保留一个管理员)
    // ----------------------------------------------------
    case 'toggle_admin':
        $account_key = trim($input['account_key'] ?? '');
        $is_admin = !empty($input['is_admin']);

        if (empty($account_key)) {
            jsonResponse(['status' => 'error', 'message' => '参数缺失'], 400);
        }

        // 查询所有账号统计当前管理员总数
        $stmtAdmins = $pdo->query("SELECT account_key, is_admin FROM templates");
        $allAccounts = $stmtAdmins->fetchAll();
        $adminCount = 0;
        $targetIsAdmin = false;
        foreach ($allAccounts as $acc) {
            if ($acc['account_key'] === 'admin' || !empty($acc['is_admin'])) {
                $adminCount++;
                if ($acc['account_key'] === $account_key) {
                    $targetIsAdmin = true;
                }
            }
        }

        // 如果要取消管理员且该账号本身是管理员，且当前只剩下 1 个管理员
        if (!$is_admin && $targetIsAdmin && $adminCount <= 1) {
            jsonResponse(['status' => 'error', 'message' => '操作失败：系统中至少需要保留一个管理员账号！'], 400);
        }

        $stmtUpdate = $pdo->prepare("UPDATE templates SET is_admin = ? WHERE account_key = ?");
        $stmtUpdate->execute([$is_admin ? 1 : 0, $account_key]);

        $msg = $is_admin ? '已成功设置为管理员！' : '已取消该账号的管理员权限！';
        jsonResponse(['status' => 'success', 'message' => $msg, 'is_admin' => $is_admin]);
        break;

    // ----------------------------------------------------
    // 13. 删除账号 (若目标为管理员，确保系统中至少保留一个管理员)
    // ----------------------------------------------------
    case 'delete_account':
        $account_key = trim($input['account_key'] ?? '');
        if (empty($account_key)) {
            jsonResponse(['status' => 'error', 'message' => '参数缺失'], 400);
        }

        // 查询所有账号统计当前管理员总数
        $stmtAdmins = $pdo->query("SELECT account_key, is_admin FROM templates");
        $allAccounts = $stmtAdmins->fetchAll();
        $adminCount = 0;
        $targetIsAdmin = false;
        foreach ($allAccounts as $acc) {
            if ($acc['account_key'] === 'admin' || !empty($acc['is_admin'])) {
                $adminCount++;
                if ($acc['account_key'] === $account_key) {
                    $targetIsAdmin = true;
                }
            }
        }

        if ($targetIsAdmin && $adminCount <= 1) {
            jsonResponse(['status' => 'error', 'message' => '无法删除：系统中至少需要保留一个管理员账号！'], 400);
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM templates WHERE account_key = ?");
            $stmt->execute([$account_key]);

            $stmtLogs = $pdo->prepare("DELETE FROM published_logs WHERE account_key = ?");
            $stmtLogs->execute([$account_key]);

            // 必须一并清理闲鱼配置与发布日志，否则：
            // 1) 残留行 publish_enabled=1 仍会被 cron 选中，但 templates 已没了文案模板，
            //    会用空描述发布商品；
            // 2) 更严重的是 —— 若之后又新建了同名 account_key，
            //    UPSERT 会命中这条残留记录，新账号直接继承上一任的 xy_secret_key / xy_account_id。
            $pdo->prepare("DELETE FROM xianyu_config WHERE account_key = ?")->execute([$account_key]);
            $pdo->prepare("DELETE FROM xianyu_publish_logs WHERE account_key = ?")->execute([$account_key]);

            $pdo->commit();
            jsonResponse(['status' => 'success', 'message' => '账号已成功删除']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @error_log('ShopTextManager delete_account failed: ' . $e->getMessage());
            jsonResponse(['status' => 'error', 'message' => '删除失败，详细原因已写入 PHP 错误日志'], 500);
        }
        break;

    // ----------------------------------------------------
    // 14. 管理员修改账号 Key (用户名)
    // ----------------------------------------------------
    case 'update_account_key':
        $old_key = trim($input['old_account_key'] ?? '');
        $new_key = trim($input['new_account_key'] ?? '');

        if (empty($old_key) || empty($new_key)) {
            jsonResponse(['status' => 'error', 'message' => '新旧账号 Key 不能为空'], 400);
        }

        if ($old_key !== $new_key) {
            // 检查新 Key 是否存在
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM templates WHERE account_key = ?");
            $stmtCheck->execute([$new_key]);
            if ($stmtCheck->fetchColumn() > 0) {
                jsonResponse(['status' => 'error', 'message' => '该账号 Key 已存在，请使用其他名称'], 400);
            }

            try {
                $pdo->beginTransaction();

                // 更新 templates 表
                $stmtUpdate = $pdo->prepare("UPDATE templates SET account_key = ? WHERE account_key = ?");
                $stmtUpdate->execute([$new_key, $old_key]);

                // 同步更新 published_logs 表关联
                $stmtLogs = $pdo->prepare("UPDATE published_logs SET account_key = ? WHERE account_key = ?");
                $stmtLogs->execute([$new_key, $old_key]);

                // 同步改名闲鱼配置与发布日志，否则改名后该账号的定时发布配置会"消失"
                // （配置还挂在旧 key 上），管理员会以为是 Bug。
                $pdo->prepare("UPDATE xianyu_config SET account_key = ? WHERE account_key = ?")
                    ->execute([$new_key, $old_key]);
                $pdo->prepare("UPDATE xianyu_publish_logs SET account_key = ? WHERE account_key = ?")
                    ->execute([$new_key, $old_key]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                @error_log('ShopTextManager update_account_key failed: ' . $e->getMessage());
                jsonResponse(['status' => 'error', 'message' => '修改失败，详细原因已写入 PHP 错误日志'], 500);
            }
        }

        jsonResponse(['status' => 'success', 'message' => '账号名称修改成功！', 'new_account_key' => $new_key]);
        break;

    // ========================================================
    // 15. 更新游戏封面图片 URL (用于定时发布商品图片)
    // ========================================================
    case 'update_game_cover':
        $game_id = (int)($input['game_id'] ?? 0);
        $cover_url = trim($input['cover_url'] ?? '');
        if ($game_id <= 0) {
            jsonResponse(['status' => 'error', 'message' => '参数错误'], 400);
        }
        $stmt = $pdo->prepare("UPDATE games SET cover_url = ? WHERE id = ?");
        $stmt->execute([$cover_url, $game_id]);
        jsonResponse(['status' => 'success', 'message' => '封面图片已更新']);
        break;

    // ========================================================
    // 16. 获取所有用户的闲鱼定时发布配置 (管理员)
    // ========================================================
    case 'get_xianyu_configs':
        // 以 templates 为主表左连接，确保所有账号都会出现（即使尚未配置闲鱼信息）
        $stmt = $pdo->query("
            SELECT
                t.account_key,
                t.is_admin,
                c.xy_server_url, c.xy_secret_key, c.xy_account_id, c.xy_account_remark,
                c.publish_enabled, c.publish_times, c.publish_price, c.publish_original_price,
                c.publish_address, c.publish_quantity, c.publish_shipping_method,
                c.publish_category_id, c.publish_category_name, c.publish_channel_cat_id,
                c.publish_channel_cat_name, c.publish_leaf_id, c.publish_tb_cat_id,
                c.publish_category_auto,
                c.image_source, c.custom_image_url,
                c.last_publish_at, c.last_publish_status
            FROM templates t
            LEFT JOIN xianyu_config c ON c.account_key = t.account_key
            ORDER BY t.id ASC
        ");
        $configs = $stmt->fetchAll();

        // 补全未配置记录的默认值，并统一字段类型。
        // 类型统一很重要：不同 PHP 版本 / PDO 驱动下 TINYINT 可能返回 int、string 甚至 "\x01"，
        // 前端 `== 1` 判断在个别环境下会失效，导致「已启用」被显示成「未启用」。
        foreach ($configs as &$cfg) {
            $hasRow = $cfg['xy_server_url'] !== null; // LEFT JOIN 命中了配置行
            $cfg['xy_configured'] = $hasRow ? 1 : 0;
            $cfg['is_admin'] = ($cfg['account_key'] === 'admin' || !empty($cfg['is_admin'])) ? 1 : 0;
            $cfg['publish_enabled'] = (int)($cfg['publish_enabled'] ?? 0);
            $cfg['publish_price'] = (string)($cfg['publish_price'] ?? '9.90');
            $cfg['publish_original_price'] = (string)($cfg['publish_original_price'] ?? '0');
            $cfg['publish_times'] = (string)($cfg['publish_times'] ?? '09:00');
            $cfg['publish_quantity'] = (int)($cfg['publish_quantity'] ?? 1);
            $cfg['xy_server_url'] = (string)($cfg['xy_server_url'] ?? '');
            // 只回显脱敏后的秘钥，避免通过公开接口泄露他人 xianyu-auto-reply 分销秘钥
            $rawSecret = (string)($cfg['xy_secret_key'] ?? '');
            $cfg['xy_secret_key'] = maskSecretKey($rawSecret);
            $cfg['xy_secret_key_set'] = $rawSecret !== '' ? 1 : 0;
            $cfg['xy_account_id'] = (string)($cfg['xy_account_id'] ?? '');
            $cfg['xy_account_remark'] = (string)($cfg['xy_account_remark'] ?? '');
            $cfg['publish_address'] = (string)($cfg['publish_address'] ?? '');
            $cfg['publish_shipping_method'] = (string)($cfg['publish_shipping_method'] ?? 'free');
            $cfg['publish_category_id'] = (string)($cfg['publish_category_id'] ?? '');
            $cfg['publish_category_name'] = (string)($cfg['publish_category_name'] ?? '');
            $cfg['publish_channel_cat_id'] = (string)($cfg['publish_channel_cat_id'] ?? '');
            $cfg['publish_channel_cat_name'] = (string)($cfg['publish_channel_cat_name'] ?? '');
            $cfg['publish_leaf_id'] = (string)($cfg['publish_leaf_id'] ?? '');
            $cfg['publish_tb_cat_id'] = (string)($cfg['publish_tb_cat_id'] ?? '');
            $cfg['publish_category_auto'] = (int)($cfg['publish_category_auto'] ?? 1);
            // 便于前端直接判断「分类是否已完整」，不必在 JS 里重复这套规则
            $cfg['publish_category_ready'] = xianyuCategoryComplete($cfg) ? 1 : 0;
            $cfg['image_source'] = (string)($cfg['image_source'] ?? 'auto');
            $cfg['custom_image_url'] = (string)($cfg['custom_image_url'] ?? '');
            $cfg['last_publish_status'] = (string)($cfg['last_publish_status'] ?? '');
        }
        unset($cfg);
        jsonResponse(['status' => 'success', 'configs' => $configs]);
        break;

    // ========================================================
    // 17. 保存用户闲鱼定时发布配置 (管理员)
    // ========================================================
    case 'save_xianyu_config':
        $ak = trim($input['account_key'] ?? '');
        if (empty($ak)) jsonResponse(['status' => 'error', 'message' => '账号不能为空'], 400);

        // 服务地址协议白名单：允许 http/https（含 127.0.0.1 与内网地址 —— xianyu-auto-reply
        // 常与本站同机部署，按 SSRF 规则拦截会直接废掉自建场景），
        // 但拒绝 file://、gopher:// 等，避免发布时把分销秘钥 POST 到非 HTTP 协议。
        if (isset($input['xy_server_url'])) {
            $submittedUrl = rtrim(trim((string)$input['xy_server_url']), '/');
            if ($submittedUrl !== '') {
                $scheme = strtolower((string)parse_url($submittedUrl, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true)) {
                    jsonResponse(['status' => 'error', 'message' => '服务地址必须以 http:// 或 https:// 开头'], 400);
                }
            }
        }

        // 先取旧配置：秘钥回填与「未提交字段保持原值」都要用到
        $prevStmt = $pdo->prepare("SELECT * FROM xianyu_config WHERE account_key = ?");
        $prevStmt->execute([$ak]);
        $prevCfg = $prevStmt->fetch();
        $exists = is_array($prevCfg);

        // 列表接口返回的是脱敏秘钥。若管理员打开配置后未改动直接保存，
        // 提交上来的就是脱敏串，此时必须保留库里的原值，否则会把真实秘钥覆盖成 "sk****abc"。
        $submittedSecret = trim($input['xy_secret_key'] ?? '');
        $prevSecret = $exists ? (string)$prevCfg['xy_secret_key'] : '';
        if ($submittedSecret !== '' && $submittedSecret === maskSecretKey($prevSecret)) {
            $submittedSecret = $prevSecret; // 未修改，沿用原值
        }

        /**
         * 取值策略：请求里带了这个键 → 用提交值（允许主动清空）；
         * 请求里没带 → 沿用库里的旧值；首次新增 → 用默认值。
         *
         * 不能一律写成 `$input['x'] ?? ''`：后台表单并非每个字段都有输入框
         * （例如 4 个分类 ID 只在需要时才展示），那样保存一次就会把库里的值静默清空。
         */
        $pick = static function (string $key, $default) use ($input, $prevCfg) {
            if (array_key_exists($key, $input)) {
                $v = $input[$key];
                return is_string($v) ? trim($v) : $v;
            }
            if (is_array($prevCfg) && array_key_exists($key, $prevCfg) && $prevCfg[$key] !== null) {
                return $prevCfg[$key];
            }
            return $default;
        };

        $fields = [
            'xy_server_url'            => rtrim((string)$pick('xy_server_url', ''), '/'),
            'xy_secret_key'            => $submittedSecret,
            'xy_account_id'            => (string)$pick('xy_account_id', ''),
            'xy_account_remark'        => (string)$pick('xy_account_remark', ''),
            'publish_enabled'          => array_key_exists('publish_enabled', $input)
                                            ? (!empty($input['publish_enabled']) ? 1 : 0)
                                            : (int)($prevCfg['publish_enabled'] ?? 0),
            'publish_times'            => (string)$pick('publish_times', '09:00'),
            'publish_price'            => (float)$pick('publish_price', 9.90),
            'publish_original_price'   => (float)$pick('publish_original_price', 0),
            'publish_address'          => (string)$pick('publish_address', ''),
            'publish_quantity'         => (int)$pick('publish_quantity', 1),
            'publish_shipping_method'  => (string)$pick('publish_shipping_method', 'free'),
            'publish_category_id'      => (string)$pick('publish_category_id', ''),
            'publish_category_name'    => (string)$pick('publish_category_name', ''),
            'publish_channel_cat_id'   => (string)$pick('publish_channel_cat_id', ''),
            'publish_channel_cat_name' => (string)$pick('publish_channel_cat_name', ''),
            'publish_leaf_id'          => (string)$pick('publish_leaf_id', ''),
            'publish_tb_cat_id'        => (string)$pick('publish_tb_cat_id', ''),
            'publish_category_auto'    => array_key_exists('publish_category_auto', $input)
                                            ? (!empty($input['publish_category_auto']) ? 1 : 0)
                                            : (int)($prevCfg['publish_category_auto'] ?? 1),
            'image_source'             => (string)$pick('image_source', 'auto'),
            'custom_image_url'         => (string)$pick('custom_image_url', ''),
        ];

        if ($exists) {
            $setParts = [];
            $params = [];
            foreach ($fields as $col => $val) {
                $setParts[] = "`$col` = ?";
                $params[] = $val;
            }
            $params[] = $ak;
            $sql = "UPDATE xianyu_config SET " . implode(', ', $setParts) . " WHERE account_key = ?";
            $pdo->prepare($sql)->execute($params);
        } else {
            $cols = array_keys($fields);
            $placeholders = array_fill(0, count($cols), '?');
            $params = array_values($fields);
            array_unshift($params, $ak);
            $sql = "INSERT INTO xianyu_config (account_key, " . implode(', ', $cols) . ") VALUES (" . implode(', ', array_merge(['?'], $placeholders)) . ")";
            $pdo->prepare($sql)->execute($params);
        }

        // 回读确认：把落库后的 publish_enabled 一并返回，前端直接用它渲染，
        // 彻底消除「保存成功但界面仍显示未启用」的观感问题。
        $verify = $pdo->prepare("SELECT publish_enabled FROM xianyu_config WHERE account_key = ?");
        $verify->execute([$ak]);
        $savedEnabled = (int)$verify->fetchColumn();

        jsonResponse([
            'status' => 'success',
            'message' => '闲鱼定时发布配置已保存',
            'publish_enabled' => $savedEnabled,
        ]);
        break;

    // ========================================================
    // 17b. 快速启用/停用某用户的定时发布 (管理员，配置表格内联开关)
    // ========================================================
    case 'toggle_xianyu_publish':
        $ak = trim($input['account_key'] ?? '');
        $enabled = !empty($input['publish_enabled']) ? 1 : 0;
        if (empty($ak)) jsonResponse(['status' => 'error', 'message' => '账号不能为空'], 400);

        $check = $pdo->prepare("SELECT id FROM xianyu_config WHERE account_key = ?");
        $check->execute([$ak]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE xianyu_config SET publish_enabled = ? WHERE account_key = ?")->execute([$enabled, $ak]);
        } else {
            $pdo->prepare("INSERT INTO xianyu_config (account_key, publish_enabled) VALUES (?, ?)")->execute([$ak, $enabled]);
        }
        // 回读确认：前端直接采用返回值渲染，杜绝「库里开了、界面显示未启用」的不一致
        $verify = $pdo->prepare("SELECT publish_enabled FROM xianyu_config WHERE account_key = ?");
        $verify->execute([$ak]);
        $nowEnabled = (int)$verify->fetchColumn();

        jsonResponse([
            'status' => 'success',
            'publish_enabled' => $nowEnabled,
            'message' => $nowEnabled ? '已启用定时发布' : '已停用定时发布',
        ]);
        break;

    // ========================================================
    // 18. 测试 xianyu-auto-reply 连接 (获取闲鱼账号列表)
    // ========================================================
    case 'test_xianyu_connection':
        $server_url = rtrim(trim($input['xy_server_url'] ?? ''), '/');
        $secret_key = trim($input['xy_secret_key'] ?? '');
        if (empty($server_url) || empty($secret_key)) {
            jsonResponse(['status' => 'error', 'message' => '服务地址和分销秘钥不能为空'], 400);
        }
        // 明确提示 curl 扩展缺失，避免抛出「未定义函数」被全局处理器转成 500
        if (!function_exists('curl_init')) {
            jsonResponse(['status' => 'error', 'message' => '服务器未安装或未启用 PHP curl 扩展，无法调用 xianyu-auto-reply 接口。请在 php.ini 中启用 curl 后重试。'], 500);
        }

        // 只做协议白名单校验。
        // 这里刻意**不**套用 isSafePublicUrl()：xianyu-auto-reply 常常与本站部署在同一台机器上，
        // 管理员填 127.0.0.1 / 内网地址是正常用法，按 SSRF 规则拦截会直接废掉自建场景。
        // 但必须挡掉 file://、gopher:// 等协议，避免被当成任意协议探测的跳板。
        $scheme = strtolower((string)parse_url($server_url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            jsonResponse(['status' => 'error', 'message' => '服务地址必须以 http:// 或 https:// 开头'], 400);
        }

        $apiUrl = $server_url . '/api/v1/external/enabled-accounts';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['secret_key' => $secret_key]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            jsonResponse(['status' => 'error', 'message' => '连接失败: ' . $curlErr], 500);
        }

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            jsonResponse(['status' => 'error', 'message' => '返回数据格式异常: ' . substr($resp, 0, 200)], 500);
        }
        if (!empty($data['success'])) {
            $accounts = $data['data']['accounts'] ?? [];
            $total = (int)($data['data']['total'] ?? count($accounts));
            $enabledTotal = (int)($data['data']['enabled_total'] ?? 0);
            if ($enabledTotal === 0) {
                // 老版本服务端不返回 enabled_total，按 enabled 字段自行统计
                foreach ($accounts as $a) {
                    if (!empty($a['enabled'])) $enabledTotal++;
                }
            }
            // 说明（已核对 xianyu-auto-reply 源码 external_account_service.list_accounts_by_secret）：
            // 该接口按 XYAccount.owner_id == 秘钥所属用户 查询，无分页、无数量上限、也不过滤禁用账号，
            // 因此这里返回的就是「该分销秘钥所属用户」名下的全部账号。
            // 若数量少于预期，原因只可能是：那些账号是在 xianyu-auto-reply 的**另一个用户**下登录的，
            // 需要用生成该分销秘钥的那个账号登录 xianyu-auto-reply，把账号移过来或改用那个用户的秘钥。
            $hint = '';
            if ($total > 0 && $enabledTotal < $total) {
                $hint = "（其中 {$enabledTotal} 个启用、" . ($total - $enabledTotal) . " 个已禁用；已禁用账号同样可以发布）";
            }
            jsonResponse([
                'status' => 'success',
                'message' => '连接成功！该分销秘钥下共 ' . $total . ' 个闲鱼账号' . ($hint ? '，' . $hint : ''),
                'accounts' => $accounts,
                'total' => $total,
                'enabled_total' => $enabledTotal,
                'disabled_total' => $total - $enabledTotal,
            ]);
        } else {
            jsonResponse([
                'status' => 'error',
                'message' => $data['message'] ?? '未知错误',
                'code' => $data['code'] ?? 0,
            ], 500);
        }
        break;

    // ========================================================
    // 19. 手动触发发布 (管理员即时发布指定商品到闲鱼)
    // ========================================================
    case 'manual_publish':
        $ak = trim($input['account_key'] ?? '');
        $game_id = (int)($input['game_id'] ?? 0);
        if (empty($ak) || $game_id <= 0) {
            jsonResponse(['status' => 'error', 'message' => '参数缺失'], 400);
        }

        $result = executeXianyuPublish($pdo, $ak, $game_id, 'manual');
        if ($result['success']) {
            jsonResponse(['status' => 'success', 'message' => $result['message'], 'data' => $result['data'] ?? []]);
        } else {
            jsonResponse(['status' => 'error', 'message' => $result['message']], 500);
        }
        break;

    // ========================================================
    // 19.1 自检：测试联网搜图（管理员排查「未能获取商品图片」用）
    //
    // 后台一键验证「搜索引擎是否可达 → 能否解析出候选 → 能否下载到真实图片」整条链路，
    // 并返回每个图源各自的成败，不需要登录服务器敲命令。
    // ========================================================
    /**
     * 拉取闲鱼平台分类候选，供后台「智能获取分类」下拉选择。
     * 只做查询不落库，用户点选后再由 save_xianyu_config 保存。
     */
    case 'recommend_xianyu_category': {
        $account_key = trim((string)($input['account_key'] ?? ''));
        $game_id = (int)($input['game_id'] ?? 0);
        $keyword = trim((string)($input['keyword'] ?? ''));

        if ($account_key === '') {
            jsonResponse(['status' => 'error', 'message' => '请先选择账号'], 400);
        }
        if (!function_exists('curl_init')) {
            jsonResponse(['status' => 'error', 'message' => '服务器未启用 PHP curl 扩展，无法调用分类推荐接口'], 500);
        }

        $stmt = $pdo->prepare("SELECT * FROM xianyu_config WHERE account_key = ?");
        $stmt->execute([$account_key]);
        $cfg = $stmt->fetch();
        if (!$cfg || empty($cfg['xy_server_url']) || empty($cfg['xy_secret_key']) || empty($cfg['xy_account_id'])) {
            jsonResponse(['status' => 'error', 'message' => '该账号尚未配置闲鱼服务地址 / 分销秘钥 / 账号ID'], 400);
        }

        // 商品描述：优先用选中商品的真实文案，其次用户手输关键词，最后退回账号模板
        $description = '';
        if ($game_id > 0) {
            $stmt = $pdo->prepare("SELECT game_name_cn, game_name_en FROM games WHERE id = ?");
            $stmt->execute([$game_id]);
            $g = $stmt->fetch();
            if ($g) {
                $description = trim((string)$g['game_name_cn'] . ' ' . (string)$g['game_name_en']);
            }
        }
        if ($description === '') $description = $keyword;
        if ($description === '') {
            $stmt = $pdo->prepare("SELECT template_text FROM templates WHERE account_key = ?");
            $stmt->execute([$account_key]);
            $tpl = $stmt->fetch();
            // 模板里可能有 {{VAR_1}} 之类的占位符，清掉后再交给推荐接口，避免干扰语义
            $description = trim(preg_replace('/\{\{[A-Z_]+\}\}/', '', (string)($tpl['template_text'] ?? '')));
        }
        if ($description === '') {
            jsonResponse(['status' => 'error', 'message' => '请先选择商品或输入商品描述关键词，分类推荐需要商品描述'], 400);
        }

        $resp = getXianyuCategoryRecommend(
            (string)$cfg['xy_server_url'],
            (string)$cfg['xy_secret_key'],
            (string)$cfg['xy_account_id'],
            $description
        );

        if (empty($resp['success'])) {
            jsonResponse([
                'status' => 'error',
                'message' => '分类推荐失败: ' . (string)($resp['message'] ?? '接口无响应'),
            ], 200);
        }

        $list = [];
        foreach ((array)($resp['data']['candidates'] ?? []) as $raw) {
            if (!is_array($raw)) continue;
            $c = normalizeXianyuCategoryCandidate($raw);
            $c['usable'] = xianyuCandidateUsable($c);
            $list[] = $c;
        }

        jsonResponse([
            'status' => 'success',
            'message' => '分类推荐成功，共 ' . count($list) . ' 个候选',
            'description' => safeTruncate($description, 120),
            'candidates' => $list,
        ]);
    }

    case 'test_image_search': {
        $game_id = (int)($input['game_id'] ?? 0);
        $keyword = trim((string)($input['keyword'] ?? ''));
        $cn = '';
        $en = '';

        if ($game_id > 0) {
            $stmt = $pdo->prepare("SELECT game_name_cn, game_name_en FROM games WHERE id = ?");
            $stmt->execute([$game_id]);
            $g = $stmt->fetch();
            if (!$g) {
                jsonResponse(['status' => 'error', 'message' => '商品不存在'], 404);
            }
            $cn = (string)($g['game_name_cn'] ?? '');
            $en = (string)($g['game_name_en'] ?? '');
        } else {
            $cn = $keyword;
            $en = $keyword;
        }

        if ($cn === '' && $en === '') {
            jsonResponse(['status' => 'error', 'message' => '请先选择商品或输入关键词'], 400);
        }
        if (!function_exists('curl_init')) {
            jsonResponse(['status' => 'error', 'message' => '服务器未启用 PHP curl 扩展，无法联网搜图'], 500);
        }

        $started = microtime(true);
        $detail = searchImagesOnlineDetailed($cn, $en, 6, 10);

        // 逐个下载校验：搜到 URL 不等于能用，必须确认能拿到真实图片字节
        $firstOk = null;
        foreach ($detail['urls'] as $u) {
            $img = downloadImageToTemp($u, 15);
            if ($img) {
                $firstOk = ['url' => $u, 'mime' => $img['mime'], 'size' => (int)$img['size']];
                @unlink($img['path']);
                break;
            }
        }

        $data = [
            'keywords' => $detail['keywords'],
            'providers' => $detail['providers'],
            'cached' => $detail['cached'],
            'candidate_count' => count($detail['urls']),
            'candidates' => array_slice($detail['urls'], 0, 6),
            'downloaded' => $firstOk,
            'elapsed' => round(microtime(true) - $started, 2),
        ];

        if ($firstOk !== null) {
            $provNames = [];
            foreach ($detail['providers'] as $p) {
                $provNames[] = $p['name'] . ($p['ok'] ? ('✓' . (int)$p['count']) : '✗');
            }
            jsonResponse([
                'status' => 'success',
                // 用 ASCII 分隔符，切到英文界面时不需要再处理全角标点
                'message' => sprintf(
                    '联网搜图正常: %d 个候选 | 首个可下载 (%s, %d KB) | 图源: %s%s',
                    count($detail['urls']),
                    $firstOk['mime'],
                    (int)round($firstOk['size'] / 1024),
                    implode(' ', $provNames),
                    $detail['cached'] ? ' | 命中缓存' : ''
                ),
                'data' => $data,
            ]);
        }

        $failedParts = [];
        foreach ($detail['providers'] as $p) {
            $failedParts[] = $p['name'] . ($p['ok'] ? ('有结果但下载失败') : ($p['error'] ?: '失败'));
        }
        jsonResponse([
            'status' => 'error',
            'message' => sprintf(
                '联网搜图失败: %s | 请检查服务器能否访问外网 (curl 是否被防火墙拦截)，或在「单个商品管理」为商品设置封面图 URL',
                empty($failedParts) ? '未产生任何候选' : implode('; ', $failedParts)
            ),
            'data' => $data,
        ], 500);
        break;
    }

    // ========================================================
    // 20. 获取闲鱼发布日志
    // ========================================================
    case 'get_xianyu_publish_logs':
        $ak = $_GET['account_key'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['page_size'] ?? 20)));

        $where = '';
        $params = [];
        if (!empty($ak)) {
            $where = "WHERE l.account_key = ?";
            $params[] = $ak;
        }

        $countSql = "SELECT COUNT(*) FROM xianyu_publish_logs l $where";
        $total = $pdo->prepare($countSql);
        $total->execute($params);
        $totalRows = (int)$total->fetchColumn();

        $offset = ($page - 1) * $pageSize;
        $sql = "SELECT l.* FROM xianyu_publish_logs l $where ORDER BY l.id DESC LIMIT $pageSize OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        jsonResponse([
            'status' => 'success',
            'logs' => $logs,
            'total' => $totalRows,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => max(1, ceil($totalRows / $pageSize)),
        ]);
        break;

    // ========================================================
    // 21. 定时发布调度入口 (由 cron 每分钟调用)
    // ========================================================
    case 'run_scheduled_publish':
        // 获取当前北京时间
        $now = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
        $currentTime = $now->format('H:i');
        $today = $now->format('Y-m-d');

        // 查询所有启用了定时发布的用户配置
        $stmt = $pdo->prepare("SELECT * FROM xianyu_config WHERE publish_enabled = 1");
        $stmt->execute();
        $configs = $stmt->fetchAll();

        $results = [];
        $publishedCount = 0;

        // 整体墙钟预算：cron 每分钟调一次，单个账号最坏可能耗掉 50s(图片) + 30s(分类) + 120s(发布)，
        // 若不设上限，排在数组前面的"慢账号"会一直饿死后面的账号（队头阻塞），
        // 并且总耗时可能超过 PHP max_execution_time 被中途杀掉。
        // 这里同时放宽 PHP 自身时限并给自己设一个更短的截止时间。
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        $runDeadline = microtime(true) + 50;

        foreach ($configs as $cfg) {
            // 预算耗尽：剩下的账号本轮直接跳过，下一分钟 cron 会继续处理
            if (microtime(true) > $runDeadline) {
                $results[] = [
                    'account_key' => $cfg['account_key'] ?? '',
                    'status' => 'skipped',
                    'message' => '本轮调度时间预算已耗尽，该账号将在下一分钟继续处理',
                ];
                continue;
            }
            // 单个用户处理异常不能中断整个调度循环，否则会影响其他用户
            // 注意必须是 Throwable：TypeError/Error 不是 Exception，
            // 只 catch Exception 会让一个账号的异常直接掀翻整个定时循环。
            try {
                // 规范化发布时间: 兼容 "9:00" / "9:5" 等写法，统一为 "09:00" 形式
                $publishTimes = array_filter(array_map(function ($pt) {
                    $pt = trim($pt);
                    if ($pt === '') return '';
                    if (preg_match('/^(\d{1,2})\s*:\s*(\d{1,2})$/', $pt, $m)) {
                        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
                    }
                    return $pt;
                }, explode(',', (string)($cfg['publish_times'] ?? ''))));

                $shouldPublish = false;
                foreach ($publishTimes as $pt) {
                    // 精确到分钟匹配
                    if ($pt === $currentTime) {
                        $shouldPublish = true;
                        break;
                    }
                }
                if (!$shouldPublish) continue;

                // 检查今天该用户是否已经在该时间点发布过 (避免重复)
                $lastPublish = $cfg['last_publish_at'] ?? null;
                if ($lastPublish && strpos($lastPublish, $today . ' ' . $currentTime) === 0) {
                    continue; // 已在该分钟发布过
                }

                // 获取该用户下一个待发布的商品 (不在 published_logs 中的)
                $gameStmt = $pdo->prepare("
                    SELECT g.* FROM games g
                    WHERE g.is_active = 1
                    AND g.id NOT IN (SELECT game_id FROM published_logs WHERE account_key = ?)
                    ORDER BY g.id ASC LIMIT 1
                ");
                $gameStmt->execute([$cfg['account_key']]);
                $game = $gameStmt->fetch();

                if (!$game) {
                    // 没有待发布商品了。
                    // 只更新状态、不写 last_publish_at：该字段同时被上面的"同分钟去重"判断使用，
                    // 若在这里写入，管理员刚好在這一分钟新增商品也会被判为"已发布"而跳过。
                    $pdo->prepare("UPDATE xianyu_config SET last_publish_status = 'idle' WHERE account_key = ?")
                        ->execute([$cfg['account_key']]);
                    $results[] = ['account_key' => $cfg['account_key'], 'status' => 'idle', 'message' => '无待发布商品'];
                    continue;
                }

                // 执行发布
                $result = executeXianyuPublish($pdo, $cfg['account_key'], $game['id'], 'scheduled');
                $results[] = [
                    'account_key' => $cfg['account_key'],
                    'game_id' => $game['id'],
                    'game_name' => $game['game_name_cn'],
                    'status' => $result['success'] ? 'success' : 'failed',
                    'message' => $result['message'],
                ];
                if ($result['success']) $publishedCount++;
            } catch (Throwable $e) {
                // 捕获单个用户的异常，记录后继续处理其他用户
                $results[] = [
                    'account_key' => $cfg['account_key'] ?? '',
                    'status' => 'failed',
                    'message' => '处理异常: ' . $e->getMessage(),
                ];
            }
        }

        jsonResponse([
            'status' => 'success',
            'message' => sprintf('定时发布调度完成，成功 %d / %d 个用户', $publishedCount, count($configs)),
            'time' => $currentTime,
            'results' => $results,
        ]);
        break;

    default:
        jsonResponse(['status' => 'error', 'message' => '未知的请求接口 Action']);
        break;
}

// ========================================================
// 核心函数: 执行闲鱼商品发布 (图片获取 + 媒体上传 + 发布)
// ========================================================
/**
 * 发布入口：加 MySQL 咨询锁后再执行，防止并发/重叠调用把同一商品重复发布。
 * 场景：cron 每分钟触发一次，而单次发布最长可能耗时 120 秒，
 *      若不加锁，上一次尚未完成时下一次会再次选中同一个待发布商品。
 */
function executeXianyuPublish(PDO $pdo, string $account_key, int $game_id, string $triggerType = 'scheduled'): array {
    $lockName = 'xy_pub_' . md5($account_key);
    $lockStmt = $pdo->prepare("SELECT GET_LOCK(?, 0)");
    $lockStmt->execute([$lockName]);
    $gotLock = (string)$lockStmt->fetchColumn();
    if ($gotLock !== '1') {
        return ['success' => false, 'message' => '该账号上一次发布仍在执行中，已跳过本次以避免重复发布'];
    }
    try {
        return executeXianyuPublishInner($pdo, $account_key, $game_id, $triggerType);
    } finally {
        // 无论成功、失败或异常，都必须释放锁
        try { $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$lockName]); } catch (Throwable $e) { /* 忽略 */ }
    }
}

function executeXianyuPublishInner(PDO $pdo, string $account_key, int $game_id, string $triggerType = 'scheduled'): array {
    // 0. 环境检查：curl 缺失时给出明确提示，而不是抛「未定义函数」中断整个定时循环
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => '服务器未安装或未启用 PHP curl 扩展，无法调用 xianyu-auto-reply 接口，请先在 php.ini 中启用 curl'];
    }

    // 1. 获取用户配置
    $stmt = $pdo->prepare("SELECT * FROM xianyu_config WHERE account_key = ?");
    $stmt->execute([$account_key]);
    $cfg = $stmt->fetch();
    if (!$cfg || empty($cfg['xy_server_url']) || empty($cfg['xy_secret_key']) || empty($cfg['xy_account_id'])) {
        return ['success' => false, 'message' => '用户闲鱼发布配置不完整，请先在管理后台配置'];
    }

    // 2. 获取商品信息
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
    $stmt->execute([$game_id]);
    $game = $stmt->fetch();
    if (!$game) {
        return ['success' => false, 'message' => '商品不存在'];
    }

    // 3. 获取用户模板并渲染文案
    $stmt = $pdo->prepare("SELECT template_text FROM templates WHERE account_key = ?");
    $stmt->execute([$account_key]);
    $tpl = $stmt->fetch();
    $templateText = $tpl ? $tpl['template_text'] : '';

    // 替换模板占位符
    $cn = $game['game_name_cn'];
    $en = $game['game_name_en'];
    $combined = $en ? "{$cn} ({$en})" : $cn;
    $description = str_replace(
        ['{{VAR_1}}', '{{VAR_2}}', '{{VAR}}'],
        [$cn, $en, $combined],
        $templateText
    );
    // 标题: 使用组合名
    $title = safeTruncate($combined, 60); // 闲鱼标题最长200，截断至60字符更安全

    // 4. 获取商品图片
    // 注意: 使用 ?? '' 做空值保护，老部署的 games 表可能尚无 cover_url 字段，
    //      直接访问会触发未定义索引告警，而全局 set_error_handler 会将其转为 500 并中断整个定时循环。
    $gameCover = $game['cover_url'] ?? '';
    $imageSource = $cfg['image_source'] ?: 'auto';

    // 组装"确定性候选"（封面 / 自定义 URL）。
    // 联网搜索改为惰性触发：只有确定性候选全部失败后才发起，
    // 避免每个商品都无条件打 3~4 次搜索引擎、把批量/定时发布整体拖到 PHP 超时。
    $primaryCandidates = [];
    if ($imageSource === 'custom' && !empty($cfg['custom_image_url'])) {
        $primaryCandidates[] = $cfg['custom_image_url'];
    } else {
        if (!empty($gameCover)) {
            $primaryCandidates[] = $gameCover;
        }
        // 选了"自定义图片"却没填 URL 时也保留封面，最终仍会回落到联网搜索兜底
    }

    // 5~6. 逐个候选下载并上传，直到成功为止
    $serverUrl = rtrim($cfg['xy_server_url'], '/');
    $mediaId = '';
    $tmpFile = '';
    $lastErr = '';
    $triedCount = 0;
    $candidateCount = 0;
    $searched = false;
    $searchDiag = null; // 联网搜图诊断（失败时用于生成可定位的报错）
    // 墙钟预算：图片解析 + 上传整体最多占用 N 秒，到点即停，保证定时任务能跑完剩余账号
    $deadline = microtime(true) + 50;

    try {
        $queue = $primaryCandidates;
        while (true) {
            if (empty($queue)) {
                if ($searched) {
                    break;
                }
                $searched = true;
                $left = (int)($deadline - microtime(true));
                if ($left <= 1) {
                    if ($lastErr === '') {
                        $lastErr = '图片处理已超时，请为该商品设置封面图 URL 以减少联网搜索耗时';
                    }
                    break;
                }
                // 把剩余预算下发给搜索，且只给它一小半，给后面的下载/上传留足时间
                // 单次请求超时上限 10s：配合内部 35s 硬上限，保证下载/上传仍有时间可用
                $detail = searchImagesOnlineDetailed($cn, $en, 8, (int)max(4, min(10, $left * 0.4)));
                $searchDiag = $detail;
                $queue = $detail['urls'];
                continue;
            }
            $left = (int)($deadline - microtime(true));
            if ($left <= 1) {
                if ($lastErr === '') {
                    $lastErr = '图片处理已超时，请为该商品设置封面图 URL 以减少联网搜索耗时';
                }
                break;
            }

            $candidateUrl = array_shift($queue);
            if (!is_string($candidateUrl) || trim($candidateUrl) === '') {
                continue;
            }
            $candidateUrl = trim($candidateUrl);
            $candidateCount++;

            // 下载最多用掉剩余预算的 40%，上传用掉剩余的全部（留 2 秒余量）
            $img = downloadImageToTemp($candidateUrl, (int)max(3, $left * 0.4));
            if (!$img) {
                $lastErr = '图片下载失败或内容不是有效图片: ' . safeTruncate($candidateUrl, 120);
                continue;
            }

            $tmpFile = $img['path'];
            $triedCount++;
            $left = (int)($deadline - microtime(true));
            try {
                $mediaResp = uploadMediaToXianyu(
                    $serverUrl,
                    $cfg['xy_secret_key'],
                    $cfg['xy_account_id'],
                    'image',
                    $img['path'],
                    $img['mime'],
                    'image' . $img['ext'],
                    max(5, $left - 2)
                );
            } finally {
                @unlink($tmpFile);
                $tmpFile = '';
            }

            if (!empty($mediaResp['success']) && !empty($mediaResp['data']['media_id'])) {
                $mediaId = $mediaResp['data']['media_id'];
                $lastErr = '';
                break;
            }
            $lastErr = ($mediaResp['message'] ?? '媒体上传失败') . ' (来源: ' . safeTruncate($candidateUrl, 80) . ')';
        }
    } finally {
        // 任何路径（含异常 / 提前 break / 超时）都不留临时文件
        if ($tmpFile !== '' && @file_exists($tmpFile)) {
            @unlink($tmpFile);
        }
    }

    if ($mediaId === '') {
        if ($candidateCount === 0) {
            // 无法获取任何图片：直接返回明确错误，而不是用可能不可达的占位图硬撑，
            // 避免每次定时都上传一张无关占位图、产生大量垃圾商品。
            // 报错里带上「试过哪些图源、各自结果如何」，方便在服务器上自查外网连通性。
            // 诊断段尽量用中英文通用的符号/数字，避免切到英文界面后整串中文
            $provPart = '';
            if (is_array($searchDiag) && !empty($searchDiag['providers'])) {
                $parts = [];
                foreach ($searchDiag['providers'] as $p) {
                    $parts[] = $p['name'] . ':' . ($p['ok'] ? ((int)$p['count'] . ' ok') : 'fail');
                }
                $provPart = ' | ' . implode(', ', $parts);
            }
            $kwPart = (is_array($searchDiag) && !empty($searchDiag['keywords']))
                ? ' | ' . implode(' / ', array_slice($searchDiag['keywords'], 0, 2))
                : '';
            $errMsg = '未能获取商品图片：联网搜索未返回结果' . $provPart . $kwPart
                . ' | 请检查服务器能否访问外网，或在「单个商品管理」为该商品设置封面图 URL';
            logXianyuPublish($pdo, $account_key, $game_id, $cn, 'failed', $triggerType, $errMsg, '', '', []);
            updateConfigStatus($pdo, $account_key, 'failed');
            return ['success' => false, 'message' => $errMsg];
        }
        $errMsg = $triedCount === 0
            ? '所有候选图片均无法下载（可能不是有效图片或链接失效），请为该商品设置封面图 URL'
            : $lastErr;
        logXianyuPublish($pdo, $account_key, $game_id, $cn, 'failed', $triggerType, $errMsg, '', '', []);
        updateConfigStatus($pdo, $account_key, 'failed');
        return ['success' => false, 'message' => '图片上传到闲鱼服务器失败: ' . $errMsg];
    }

    // 7. 解析平台分类。
    // 注意：闲鱼发布接口要求 channel_cat_id / channel_cat_name / tb_cat_id 三者齐全，
    // 只给 platform_category_id 会被判为「分类不完整」而拒绝发布。
    $catDiag = [];
    $catResult = resolveXianyuCategory($pdo, $cfg, $description, $catDiag);
    if (!$catResult['ok']) {
        $errMsg = $catResult['message'];
        logXianyuPublish($pdo, $account_key, $game_id, $cn, 'failed', $triggerType, $errMsg, '', '', $catDiag);
        updateConfigStatus($pdo, $account_key, 'failed');
        return ['success' => false, 'message' => $errMsg];
    }
    $categoryId         = $catResult['category']['category_id'];
    $categoryName       = $catResult['category']['category_name'];
    $channelCatId       = $catResult['category']['channel_cat_id'];
    $channelCatName     = $catResult['category']['channel_cat_name'];
    $leafId             = $catResult['category']['leaf_id'];
    $tbCatId            = $catResult['category']['tb_cat_id'];

    // 8. 发布商品
    $publishPayload = [
        'secret_key' => $cfg['xy_secret_key'],
        'account_id' => $cfg['xy_account_id'],
        'title' => $title,
        'description' => safeTruncate($description, 1500),
        'price' => (float)$cfg['publish_price'],
        'image_media_ids' => [$mediaId],
        'quantity' => (int)$cfg['publish_quantity'],
        'shipping_method' => $cfg['publish_shipping_method'] ?: 'free',
        'address' => $cfg['publish_address'] ?: '上海市',
    ];

    if ($cfg['publish_original_price'] > 0) {
        $publishPayload['original_price'] = (float)$cfg['publish_original_price'];
    }
    if ($categoryId !== '') {
        $publishPayload['platform_category_id'] = $categoryId;
        $publishPayload['platform_category_name'] = $categoryName;
    }
    // 这三个是发布的硬校验项，必须同时存在
    $publishPayload['platform_channel_category_id'] = $channelCatId;
    $publishPayload['platform_channel_category_name'] = $channelCatName;
    $publishPayload['platform_tb_category_id'] = $tbCatId;
    if ($leafId !== '') {
        $publishPayload['platform_leaf_id'] = $leafId;
    }

    $publishResp = publishSingleToXianyu($serverUrl, $publishPayload);

    if (!empty($publishResp['success'])) {
        // 发布成功: 记入 published_logs 和 xianyu_publish_logs
        $stmtLog = $pdo->prepare("INSERT IGNORE INTO published_logs (account_key, game_id) VALUES (?, ?)");
        $stmtLog->execute([$account_key, $game_id]);

        $itemUrl = $publishResp['data']['item_url'] ?? '';
        $itemId = $publishResp['data']['item_id'] ?? '';

        logXianyuPublish($pdo, $account_key, $game_id, $cn, 'success', $triggerType,
            $publishResp['message'] ?? '发布成功', $itemUrl, $itemId, $publishResp);
        updateConfigStatus($pdo, $account_key, 'success');

        return [
            'success' => true,
            'message' => sprintf('商品「%s」已成功发布到闲鱼！', $cn),
            'data' => [
                'item_url' => $itemUrl,
                'item_id' => $itemId,
                'game_name' => $cn,
            ],
        ];
    } else {
        $errMsg = $publishResp['message'] ?? '发布失败';
        logXianyuPublish($pdo, $account_key, $game_id, $cn, 'failed', $triggerType, $errMsg, '', '', $publishResp);
        updateConfigStatus($pdo, $account_key, 'failed');
        return ['success' => false, 'message' => '闲鱼发布失败: ' . $errMsg];
    }
}

// --- 辅助函数 ---

/**
 * 按魔术字节判断图片真实格式。
 *
 * 关键：绝不能信任远端返回的 Content-Type 或 URL 后缀。xianyu-auto-reply 上传接口
 * 会校验 content_type 必须以 image/ 开头，而我们提交 multipart 时也要显式给出 MIME，
 * 因此这里必须按内容判断，否则会把 HTML 错误页当图片上传，导致「只支持上传图片文件」报错。
 *
 * @return array{ext:string,mime:string}|null 无法识别时返回 null
 */
function sniffImageType(string $data): ?array {
    if (strlen($data) < 12) return null;
    if (strncmp($data, "\xFF\xD8\xFF", 3) === 0)            return ['ext' => '.jpg',  'mime' => 'image/jpeg'];
    if (strncmp($data, "\x89PNG\r\n\x1A\n", 8) === 0)       return ['ext' => '.png',  'mime' => 'image/png'];
    if (strncmp($data, 'GIF87a', 6) === 0)                  return ['ext' => '.gif',  'mime' => 'image/gif'];
    if (strncmp($data, 'GIF89a', 6) === 0)                  return ['ext' => '.gif',  'mime' => 'image/gif'];
    if (strncmp($data, 'RIFF', 4) === 0 && substr($data, 8, 4) === 'WEBP') return ['ext' => '.webp', 'mime' => 'image/webp'];
    if (strncmp($data, 'BM', 2) === 0)                      return ['ext' => '.bmp',  'mime' => 'image/bmp'];
    return null;
}

/**
 * 逐跳跟随重定向的安全 GET。
 *
 * 每一跳都重新做 SSRF 校验，防止公网 URL 通过 302 跳到 169.254.169.254 等内网/云元数据地址；
 * 同时保留正常 CDN 跳转能力（早期版本直接关闭 FOLLOWLOCATION，导致大量 CDN 图片下载失败）。
 *
 * @return array{body:string,code:int,content_type:string}|null
 */
function safeHttpGet(string $url, int $timeout = 20, int $maxRedirects = 5, array $headers = [], int $maxBytes = 0): ?array {
    if (!function_exists('curl_init')) return null;
    $current = $url;
    // 默认请求头：部分图床/搜索引擎会拒绝没有 Accept / Accept-Language 的请求
    $defaultHeaders = [
        'Accept: */*',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
    ];
    for ($hop = 0; $hop <= $maxRedirects; $hop++) {
        if (!isSafePublicUrl($current)) return null;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $current,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // 手动跟随，逐跳校验
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // 强制 IPv4：国内服务器普遍没有可用的 IPv6 出口，
            // 而 curl 在存在 AAAA 记录时会优先尝试 IPv6，失败后往往要等满超时才回落，
            // 表现为「联网搜图偶尔/经常超时返回 0 个候选」。
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_ENCODING => '', // 自动解压 gzip/br，减少传输耗时
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
        ]);

        // $maxBytes > 0：边收边累计，超过上限立刻中止传输。
        // 不能等收完再判断大小 —— 对方若返回一个几 GB 的响应，内存会先被打爆。
        $acc = '';
        $tooBig = false;
        $useWriteFn = $maxBytes > 0;
        if ($useWriteFn) {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, $chunk) use (&$acc, &$tooBig, $maxBytes) {
                if ($tooBig) return 0; // 通知 libcurl 中止
                $acc .= $chunk;
                if (strlen($acc) > $maxBytes) {
                    $tooBig = true;
                    $acc = '';
                    return 0;
                }
                return strlen($chunk);
            });
        }

        $execRet = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $next = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($useWriteFn) {
            if ($tooBig || $execRet === false) return null;
            $body = $acc;
        } else {
            if ($execRet === false) return null;
            $body = $execRet;
        }
        if (in_array($code, [301, 302, 303, 307, 308], true) && $next !== '') {
            $current = $next;
            continue;
        }
        if ($code < 200 || $code >= 300) return null;
        return ['body' => $body, 'code' => $code, 'content_type' => $ct];
    }
    return null;
}

/**
 * 归一化候选图片 URL：去重、去空、剔除 xianyu 不支持的格式（SVG）。
 *
 * @param string[] $list
 * @return string[]
 */
function normalizeImageUrls(array $list, int $limit = 10): array {
    $seen = [];
    $out = [];
    foreach ($list as $u) {
        if (!is_string($u)) continue;
        $u = trim($u);
        if ($u === '' || stripos($u, 'http') !== 0) continue;
        if (stripos($u, '.svg') !== false) continue; // xianyu 不支持 svg
        if (isset($seen[$u])) continue;
        $seen[$u] = true;
        $out[] = $u;
        if (count($out) >= $limit) break;
    }
    return $out;
}

/**
 * 构造联网搜图用的关键词序列（按优先级）。
 * 中英文各来一轮：中文结果质量差时英文常常能救回来，反之亦然。
 *
 * @return string[]
 */
function buildImageSearchKeywords(string $cn, string $en): array {
    $cn = trim($cn);
    $en = trim($en);
    $list = [];
    $add = static function ($k) use (&$list) {
        $k = trim((string)$k);
        if ($k !== '' && !in_array($k, $list, true)) $list[] = $k;
    };
    $add($en !== '' ? $en : $cn);
    if ($cn !== '' && $cn !== $en) $add($cn);
    if ($en !== '') $add($en . ' cover art');
    if ($cn !== '') $add($cn . ' 封面');
    return $list;
}

/**
 * 图源 1：Bing 图片（异步接口 + 主页面回退）。
 * @return string[]
 */
function searchProviderBing(string $keyword, int $timeout): array {
    $out = [];
    $timeout = max(3, min(20, $timeout));
    $q = urlencode($keyword);
    $html = ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];

    $resp = safeHttpGet("https://www.bing.com/images/async?q={$q}&first=0&count=35&mmasync=1", $timeout, 5, $html);
    if ($resp && !empty($resp['body'])) {
        if (preg_match_all('/murl&quot;:&quot;(https?:\/\/.+?)&quot;/', $resp['body'], $m)) {
            foreach ($m[1] as $u) $out[] = html_entity_decode($u);
        }
        if (preg_match_all('/"murl":"(https?:\\\\?\/\\\\?\/[^"]+)"/', $resp['body'], $m1b)) {
            foreach ($m1b[1] as $u) $out[] = html_entity_decode(stripslashes($u));
        }
    }

    if (count($out) < 5) {
        $resp2 = safeHttpGet("https://www.bing.com/images/search?q={$q}&form=HDRSC2&first=1", $timeout, 5, $html);
        if ($resp2 && !empty($resp2['body'])) {
            if (preg_match_all('/murl&quot;:&quot;(https?:\/\/.+?)&quot;/', $resp2['body'], $m2)) {
                foreach ($m2[1] as $u) $out[] = html_entity_decode($u);
            }
            if (preg_match_all('/class="mimg"[^>]+src="(https?:\/\/[^"]+)"/', $resp2['body'], $m3)) {
                foreach ($m3[1] as $u) $out[] = html_entity_decode($u);
            }
        }
    }
    return $out;
}

/**
 * 图源 2：DuckDuckGo 图片。Bing 在被墙/被限流的服务器上经常不可用，这是主要退路。
 * 接口需要先用搜索页换取一次性 vqd token，再调用 i.js。
 * @return string[]
 */
function searchProviderDuckDuckGo(string $keyword, int $timeout): array {
    $out = [];
    $timeout = max(3, min(20, $timeout));
    $q = urlencode($keyword);

    $page = safeHttpGet("https://duckduckgo.com/?q={$q}&iax=images&ia=images", $timeout, 5,
        ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);
    if (!$page || empty($page['body'])) return $out;

    $vqd = '';
    if (preg_match('/vqd=["\']([0-9a-zA-Z\-_]{10,})["\']/', $page['body'], $m)) {
        $vqd = $m[1];
    } elseif (preg_match('/vqd\s*[:=]\s*["\']?([0-9a-zA-Z\-_]{10,})/', $page['body'], $m2)) {
        $vqd = $m2[1];
    }
    if ($vqd === '') return $out;

    $json = safeHttpGet("https://duckduckgo.com/i.js?l=us-en&o=json&q={$q}&vqd={$vqd}&f=,,,&p=1", $timeout, 5, [
        'Referer: https://duckduckgo.com/',
        'Accept: application/json, text/javascript, */*; q=0.01',
    ]);
    if (!$json || empty($json['body'])) return $out;

    $data = json_decode($json['body'], true);
    if (!is_array($data) || empty($data['results']) || !is_array($data['results'])) return $out;
    foreach ($data['results'] as $r) {
        if (!empty($r['image']) && is_string($r['image'])) $out[] = $r['image'];
    }
    return $out;
}

/**
 * 图源 3：Wikimedia Commons 开放图库（免鉴权、无反爬，作为最后兜底）。
 * @return string[]
 */
function searchProviderWikimedia(string $keyword, int $timeout): array {
    $out = [];
    $timeout = max(3, min(20, $timeout));
    $url = 'https://commons.wikimedia.org/w/api.php?action=query&generator=search'
        . '&gsrsearch=' . urlencode($keyword)
        . '&gsrnamespace=6&gsrlimit=15&prop=imageinfo&iiprop=url|mime&iiurlwidth=1024&format=json';
    $resp = safeHttpGet($url, $timeout, 5, ['Accept: application/json']);
    if (!$resp || empty($resp['body'])) return $out;

    $data = json_decode($resp['body'], true);
    if (!is_array($data) || empty($data['query']['pages']) || !is_array($data['query']['pages'])) return $out;
    foreach ($data['query']['pages'] as $p) {
        $info = $p['imageinfo'][0] ?? null;
        if (!is_array($info)) continue;
        $u = (string)($info['thumburl'] ?: ($info['url'] ?? ''));
        $mime = strtolower((string)($info['mime'] ?? ''));
        if ($u !== '' && ($mime === '' || strpos($mime, 'image/') === 0)) $out[] = $u;
    }
    return $out;
}

/**
 * 搜图结果文件缓存（默认 6 小时）。
 *
 * 同一个商品会被多个账号/多次定时重复发布，若每次都重新联网搜索，
 * 既慢又容易被搜索引擎限流导致偶发失败。这里按关键词做一层轻量磁盘缓存，
 * 缓存目录不可写时静默降级，不影响主流程。
 */
function imageSearchCacheDir(): string {
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'stm_imgsearch';
}

function imageSearchCacheGet(string $key, int $ttl = 21600): ?array {
    try {
        $file = imageSearchCacheDir() . DIRECTORY_SEPARATOR . md5($key) . '.json';
        if (!@file_exists($file)) return null;
        if ((time() - (int)@filemtime($file)) > $ttl) return null;
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    } catch (Throwable $e) {
        return null;
    }
}

function imageSearchCacheSet(string $key, array $urls): void {
    try {
        $dir = imageSearchCacheDir();
        if (!@is_dir($dir) && !@mkdir($dir, 0777, true)) return;
        $file = $dir . DIRECTORY_SEPARATOR . md5($key) . '.json';
        @file_put_contents($file, json_encode($urls, JSON_UNESCAPED_UNICODE), LOCK_EX);
    } catch (Throwable $e) {
        /* 缓存失败不影响主流程 */
    }
}

/**
 * 多图源联网搜图（带诊断信息）。
 *
 * 依次尝试 Bing → DuckDuckGo → Wikimedia，每个图源再用多组关键词重试，
 * 直到凑够 $limit 个候选或耗尽时间预算。返回每个图源的成败情况，
 * 便于管理员在「测试联网搜图」里一眼看出是哪一步被卡住。
 *
 * @return array{urls:string[],providers:array<int,array{name:string,ok:bool,count:int,error:string}>,keywords:string[],cached:bool}
 */
function searchImagesOnlineDetailed(string $cn, string $en, int $limit = 10, int $timeout = 12): array {
    $keywords = buildImageSearchKeywords($cn, $en);
    if (empty($keywords)) {
        return ['urls' => [], 'providers' => [], 'keywords' => [], 'cached' => false];
    }

    $cacheKey = implode('|', $keywords);
    $cached = imageSearchCacheGet($cacheKey);
    if (is_array($cached) && !empty($cached)) {
        return [
            'urls' => array_slice($cached, 0, $limit),
            'providers' => [['name' => 'cache', 'ok' => true, 'count' => count($cached), 'error' => '']],
            'keywords' => $keywords,
            'cached' => true,
        ];
    }

    $providers = [
        ['name' => 'bing', 'fn' => 'searchProviderBing'],
        ['name' => 'duckduckgo', 'fn' => 'searchProviderDuckDuckGo'],
        ['name' => 'wikimedia', 'fn' => 'searchProviderWikimedia'],
    ];

    // 整体预算：三个图源 × 每源最多 2 个关键词，理论上限接近 $timeout 的 3 倍。
    // 必须再夹一个 35 秒的硬上限：调用方（发布主循环）的总预算只有 50 秒，
    // 若这里按 timeout*3 展开，单是搜图就可能吃掉 60 秒，把下载/上传的时间挤没了。
    $deadline = microtime(true) + max(6, min($timeout * 3, 35));
    $tried = [];
    $collected = [];

    foreach ($providers as $p) {
        if (count($collected) >= $limit) break;
        $left = (int)($deadline - microtime(true));
        if ($left <= 1) {
            $tried[] = ['name' => $p['name'], 'ok' => false, 'count' => 0, 'error' => '时间预算耗尽，未尝试'];
            continue;
        }
        $found = [];
        // 每个图源最多试 2 个关键词，避免无谓地把时间耗在冷门词上
        foreach (array_slice($keywords, 0, 2) as $kw) {
            if (count($found) >= $limit) break;
            $left2 = (int)($deadline - microtime(true));
            if ($left2 <= 1) break;
            try {
                $r = call_user_func($p['fn'], $kw, (int)max(3, min($timeout, $left2)));
                if (is_array($r)) $found = array_merge($found, $r);
            } catch (Throwable $e) {
                /* 单个图源异常不影响其它图源 */
            }
        }
        $tried[] = [
            'name' => $p['name'],
            'ok' => count($found) > 0,
            'count' => count($found),
            'error' => count($found) > 0 ? '' : '无结果或请求失败',
        ];
        $collected = array_merge($collected, $found);
    }

    $urls = normalizeImageUrls($collected, $limit);
    if (!empty($urls)) {
        imageSearchCacheSet($cacheKey, $urls);
    }
    return ['urls' => $urls, 'providers' => $tried, 'keywords' => $keywords, 'cached' => false];
}

/**
 * 联网搜索商品图片，返回候选 URL 列表（去重后返回）。
 *
 * 搜索引擎结果里常有缩略图、占位图、失效链接，单个 URL 一旦不可用整个发布就会失败，
 * 因此返回多个候选，由调用方逐个下载校验，直到拿到真实可用的图片为止。
 *
 * @return string[] 候选图片 URL 列表
 */
function searchImagesOnline(string $cn, string $en, int $limit = 10, int $timeout = 12): array {
    return searchImagesOnlineDetailed($cn, $en, $limit, $timeout)['urls'];
}

/**
 * 兼容旧调用：取第一个候选（保留函数签名，避免其它地方调用报错）。
 */
function searchImageOnline(string $cn, string $en): string {
    $list = searchImagesOnline($cn, $en, 1);
    return $list[0] ?? '';
}

/**
 * 校验 URL 是否允许访问 (SSRF 防护)。
 * 图片 URL 来自管理员配置或搜索引擎结果，必须阻止访问内网/本机/云元数据等地址。
 */
function isSafePublicUrl(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return false;
    $scheme = strtolower($parts['scheme'] ?? '');
    // 仅允许 http/https
    if (!in_array($scheme, ['http', 'https'], true)) return false;
    $host = strtolower(rtrim($parts['host'], '.'));

    // 阻止 localhost / 本机环回
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) return false;

    // 已经是 IP 字面量：直接校验
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return isPublicIp($host);
    }

    // 域名：必须同时校验 A(IPv4) 与 AAAA(IPv6) 的全部地址。
    // 攻击者可以让域名 A=内网IP、AAAA=公网IP，只校验其中一类就会被绕过。
    $ips = resolveHostIps($host);
    if (empty($ips)) return false; // 解析失败直接拒绝
    foreach ($ips as $ip) {
        if (!isPublicIp($ip)) return false;
    }
    return true;
}

/**
 * 解析域名对应的全部 IP（IPv4 + IPv6），失败返回空数组。
 *
 * ⚠️ 这里修复了一个长期存在的严重 bug：
 * PHP 的 dns_get_record() 对 **A 记录返回的键名是 `ip`**，只有 AAAA 记录才用 `ipv6`，
 * 代码里写的 `ipv4` 在 PHP 各版本中都不存在。后果有两个：
 *   1. 安全：IPv4 地址从未被校验 —— 若域名 A=169.254.169.254、AAAA=公网地址，
 *      旧逻辑会因为 AAAA 合法而整体放行，SSRF 防护失效；
 *   2. 功能：只有 A 记录的域名（国内服务器普遍不解析 AAAA）$checked 恒为 0，
 *      被整体判定为"不安全"而拒绝访问。这正是「联网搜图永远返回 0 个候选」→
 *      最终报错「未能获取商品图片」的根因。
 *
 * 另外 dns_get_record 在部分面板环境会被列入 disable_functions，
 * 因此额外提供 gethostbynamel() 作为回落；两个都不可用则解析失败（拒绝访问，安全优先）。
 *
 * @return string[]
 */
function resolveHostIps(string $host): array {
    $ips = [];

    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                $type = strtoupper((string)($r['type'] ?? ''));
                if ($type === 'A') {
                    $ip = (string)($r['ip'] ?? ($r['ipv4'] ?? '')); // A 记录的键是 ip
                    if ($ip !== '') $ips[] = $ip;
                } elseif ($type === 'AAAA') {
                    $ip = (string)($r['ipv6'] ?? '');
                    if ($ip !== '') $ips[] = $ip;
                }
            }
        }
    }

    if (empty($ips) && function_exists('gethostbynamel')) {
        $list = @gethostbynamel($host);
        if (is_array($list)) {
            foreach ($list as $ip) {
                if (is_string($ip) && $ip !== '') $ips[] = $ip;
            }
        }
    }

    $ips = array_values(array_unique(array_filter($ips, static function ($v) {
        return is_string($v) && $v !== '';
    })));
    return $ips;
}

/**
 * 单个 IP 是否为可安全访问的公网地址。
 * 私有网段(10/8、172.16/12、192.168/16)、保留地址、链路本地(169.254/16)一律拒绝。
 */
function isPublicIp(string $ip): bool {
    // NO_PRIV_RANGE 覆盖 10/8、172.16/12、192.168/16；
    // NO_RES_RANGE 覆盖 0/8、127/8、169.254/16、224/4、240/4 等保留段。
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    // 但 PHP 的过滤标志**不包含**运营商级 NAT 段 100.64.0.0/10 (RFC 6598)，
    // 这段在不少云主机/容器网络里能直达内网与元数据服务，必须显式拦掉。
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long !== false) {
            if ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255')) return false;
        }
    }
    return true;
}

/**
 * 下载图片到临时文件，并按魔术字节校验它确实是图片。
 *
 * 返回结构里带上真实 mime 与扩展名，供上传时显式声明 multipart 的 Content-Type。
 * 这一步是发布成功率的关键：搜索引擎返回的链接可能是 HTML 错误页 / 占位图，
 * 若不校验就上传，xianyu-auto-reply 会直接返回「只支持上传图片文件」。
 *
 * @return array{path:string,mime:string,ext:string,size:int}|null
 */
function downloadImageToTemp(string $url, int $timeout = 20): ?array {
    // 6MB 传输硬上限：xianyu 的上传上限是 5MB，超过就该换下一个候选，
    // 但必须留一点余量以便下面的判断能给出明确原因而不是"下载失败"。
    $resp = safeHttpGet($url, max(3, min(30, $timeout)), 5, [], 6 * 1024 * 1024);
    if (!$resp) return null;

    $data = $resp['body'];
    if (strlen($data) < 100) return null;

    // xianyu-auto-reply 上传上限 5MB，超限直接放弃换下一个候选
    if (strlen($data) > 5 * 1024 * 1024) return null;

    $info = sniffImageType($data);
    if (!$info) return null;

    $tmpFile = sys_get_temp_dir() . '/xianyu_publish_' . uniqid('', true) . $info['ext'];
    if (@file_put_contents($tmpFile, $data) === false) {
        return null;
    }
    return [
        'path' => $tmpFile,
        'mime' => $info['mime'],
        'ext'  => $info['ext'],
        'size' => strlen($data),
    ];
}

/**
 * 上传图片到 xianyu-auto-reply，获取 media_id。
 *
 * 必须显式传入 MIME 与文件名：PHP 的 new CURLFile($path) 默认会带
 * Content-Type: application/octet-stream，而服务端强制要求 image/* 开头，
 * 不显式声明会 100% 报「只支持上传图片文件」。
 */
function uploadMediaToXianyu(string $serverUrl, string $secretKey, string $accountId, string $mediaType, string $filePath, string $mime = '', string $fileName = '', int $timeout = 45): array {
    if (!file_exists($filePath)) return ['success' => false, 'message' => '图片文件不存在'];
    $timeout = max(5, min(120, $timeout));

    if ($mime === '' || stripos($mime, 'image/') !== 0) {
        // 兜底：按文件内容再嗅探一次，保证一定是 image/*
        $raw = @file_get_contents($filePath);
        $info = $raw !== false ? sniffImageType($raw) : null;
        $mime = $info ? $info['mime'] : 'image/jpeg';
    }
    if ($fileName === '') {
        $fileName = 'image' . (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === '' ? '.jpg' : '.' . pathinfo($filePath, PATHINFO_EXTENSION));
    }

    $postFields = [
        'secret_key' => $secretKey,
        'account_id' => $accountId,
        'media_type' => $mediaType,
        'file' => new CURLFile($filePath, $mime, $fileName),
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $serverUrl . '/api/v1/external/publish/media',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['success' => false, 'message' => '请求失败: ' . $err];

    $data = json_decode($resp, true);
    return is_array($data) ? $data : ['success' => false, 'message' => '响应格式异常: ' . substr($resp, 0, 200)];
}

/**
 * 判断一组分类字段是否满足 xianyu-auto-reply 发布校验。
 *
 * 对方 xianyu_item_payload_builder._build_category_label() 强制要求
 * platform_channel_category_id / platform_channel_category_name / platform_tb_category_id
 * 三个同时非空，缺任意一个就抛「请先根据商品描述重新选择完整的平台商品分类」。
 * 只填 platform_category_id（末级分类 ID）是不够的——这正是原来发布必失败的原因。
 */
function xianyuCategoryComplete($cfg): bool {
    return !empty($cfg['publish_channel_cat_id'])
        && !empty($cfg['publish_channel_cat_name'])
        && !empty($cfg['publish_tb_cat_id']);
}

/**
 * 把分类推荐候选归一化成 6 个字段，并过滤掉不完整（无法通过发布校验）的候选。
 * 推荐接口返回的字段名见 xianyu-auto-reply README：
 * cat_id / cat_name / channel_cat_id / channel_cat_name / leaf_id / tb_cat_id / path / score / is_selected
 */
function normalizeXianyuCategoryCandidate(array $c): array {
    $path = '';
    if (!empty($c['path']) && is_array($c['path'])) {
        $names = [];
        foreach ($c['path'] as $seg) {
            if (is_array($seg) && !empty($seg['name'])) $names[] = (string)$seg['name'];
        }
        $path = implode(' / ', $names);
    }
    return [
        'category_id'       => (string)($c['cat_id'] ?? ''),
        'category_name'     => (string)($c['cat_name'] ?? ''),
        'channel_cat_id'    => (string)($c['channel_cat_id'] ?? ''),
        'channel_cat_name'  => (string)($c['channel_cat_name'] ?? ''),
        'leaf_id'           => (string)($c['leaf_id'] ?? ''),
        'tb_cat_id'         => (string)($c['tb_cat_id'] ?? ''),
        'path'              => $path,
        'score'             => isset($c['score']) ? (float)$c['score'] : 0.0,
        'is_selected'       => !empty($c['is_selected']),
    ];
}

/** 候选是否满足发布所需的最小三件套 */
function xianyuCandidateUsable(array $c): bool {
    return $c['channel_cat_id'] !== '' && $c['channel_cat_name'] !== '' && $c['tb_cat_id'] !== '';
}

/** 在候选里挑最佳：优先平台已选中的，其次得分最高的，最后才是第一条 */
function pickBestXianyuCategory(array $candidates): ?array {
    $usable = array_values(array_filter($candidates, 'xianyuCandidateUsable'));
    if (!$usable) return null;
    $selected = array_values(array_filter($usable, static fn($c) => $c['is_selected']));
    if ($selected) return $selected[0];
    $best = $usable[0];
    foreach ($usable as $c) {
        if ($c['score'] > $best['score']) $best = $c;
    }
    return $best;
}

/**
 * 解析本次发布要用的分类。
 *
 * - 配置里分类三件套齐全 且 未开启「按商品描述自动匹配」→ 直接用配置（不发网络请求）
 * - 否则调用对方分类推荐接口，取最佳候选；推荐失败时若配置本身完整则回退到配置
 * - 解析结果若与配置不同则回写数据库，让管理员在后台能看到实际生效的分类
 *
 * @param array $cfg   xianyu_config 行（含 xy_* 与 publish_*）
 * @param array &$diag 输出诊断信息，便于把失败原因写进发布日志
 * @return array{ok:bool, message:string, category:array}
 */
function resolveXianyuCategory(PDO $pdo, array $cfg, string $description, array &$diag): array {
    $blank = ['category_id' => '', 'category_name' => '', 'channel_cat_id' => '',
              'channel_cat_name' => '', 'leaf_id' => '', 'tb_cat_id' => ''];
    $fromCfg = [
        'category_id'      => (string)($cfg['publish_category_id'] ?? ''),
        'category_name'    => (string)($cfg['publish_category_name'] ?? ''),
        'channel_cat_id'   => (string)($cfg['publish_channel_cat_id'] ?? ''),
        'channel_cat_name' => (string)($cfg['publish_channel_cat_name'] ?? ''),
        'leaf_id'          => (string)($cfg['publish_leaf_id'] ?? ''),
        'tb_cat_id'        => (string)($cfg['publish_tb_cat_id'] ?? ''),
    ];
    $cfgReady  = xianyuCategoryComplete($cfg);
    $autoMatch = !array_key_exists('publish_category_auto', $cfg) || !empty($cfg['publish_category_auto']);

    // 已手工固定了完整分类，且没开自动匹配：无需联网
    if ($cfgReady && !$autoMatch) {
        $diag['category_source'] = 'config';
        return ['ok' => true, 'message' => '', 'category' => $fromCfg];
    }

    $resp = getXianyuCategoryRecommend(
        (string)$cfg['xy_server_url'],
        (string)$cfg['xy_secret_key'],
        (string)$cfg['xy_account_id'],
        $description
    );
    $diag['category_api_message'] = safeTruncate((string)($resp['message'] ?? ''), 200);
    $diag['category_api_code'] = $resp['code'] ?? null;
    $diag['category_auto'] = $autoMatch ? 1 : 0;
    $diag['category_config_ready'] = $cfgReady ? 1 : 0;

    $candidates = [];
    if (!empty($resp['success']) && !empty($resp['data']['candidates']) && is_array($resp['data']['candidates'])) {
        foreach ($resp['data']['candidates'] as $raw) {
            if (is_array($raw)) $candidates[] = normalizeXianyuCategoryCandidate($raw);
        }
    }
    $diag['category_candidates'] = count($candidates);

    $best = pickBestXianyuCategory($candidates);
    if ($best) {
        $resolved = [
            'category_id'      => $best['category_id'],
            'category_name'    => $best['category_name'] ?: $best['channel_cat_name'],
            'channel_cat_id'   => $best['channel_cat_id'],
            'channel_cat_name' => $best['channel_cat_name'],
            'leaf_id'          => $best['leaf_id'],
            'tb_cat_id'        => $best['tb_cat_id'],
        ];
        $diag['category_source'] = 'recommend';
        $diag['category_name']   = $resolved['category_name'];
        // 回写配置，让后台能看到实际生效的分类；仅在变化时写，避免每次发布都产生一次 UPDATE
        if ($resolved !== $fromCfg) {
            try {
                $stmt = $pdo->prepare("UPDATE xianyu_config SET publish_category_id=?, publish_category_name=?, "
                    . "publish_channel_cat_id=?, publish_channel_cat_name=?, publish_leaf_id=?, publish_tb_cat_id=? "
                    . "WHERE account_key=?");
                $stmt->execute([
                    $resolved['category_id'], $resolved['category_name'],
                    $resolved['channel_cat_id'], $resolved['channel_cat_name'],
                    $resolved['leaf_id'], $resolved['tb_cat_id'],
                    (string)$cfg['account_key'],
                ]);
            } catch (Throwable $e) {
                @error_log('ShopTextManager resolveXianyuCategory persist failed: ' . $e->getMessage());
            }
        }
        return ['ok' => true, 'message' => '', 'category' => $resolved];
    }

    // 推荐拿不到可用候选：配置本身完整就退回配置，否则明确报错
    if ($cfgReady) {
        $diag['category_source'] = 'config_fallback';
        return ['ok' => true, 'message' => '', 'category' => $fromCfg];
    }

    $apiMsg = safeTruncate((string)($resp['message'] ?? ''), 200);
    $why = $apiMsg !== '' ? $apiMsg : '接口无响应或返回为空';
    if (count($candidates) > 0) {
        $why = '接口返回了 ' . count($candidates) . ' 个候选，但都缺少频道分类ID/名称或淘宝分类ID';
    }
    $diag['category_error'] = $why;
    return [
        'ok' => false,
        'message' => '平台商品分类不完整，无法发布（闲鱼要求频道分类ID、频道分类名称、淘宝分类ID 三者齐全）。'
            . '自动获取分类失败：' . $why
            . '。请在「闲鱼定时发布配置」里点「智能获取分类」重新选择，'
            . '或检查 xianyu-auto-reply 的分类推荐接口是否可用。',
        'category' => $blank,
    ];
}

function getXianyuCategoryRecommend(string $serverUrl, string $secretKey, string $accountId, string $description): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $serverUrl . '/api/v1/external/category/recommend',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'secret_key' => $secretKey,
            'account_id' => $accountId,
            'description' => safeTruncate($description, 1500),
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10, // 服务不可达时快速失败，避免占满定时调度的墙钟预算
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    return is_array($data) ? $data : ['success' => false];
}

function publishSingleToXianyu(string $serverUrl, array $payload): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $serverUrl . '/api/v1/external/publish/single',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        // 发布本身可能较慢（对方要真实调用闲鱼），但 120s 过久：
        // cron 每分钟一次，一次卡住就会饿死后续账号。60s 足够，另加连接超时快速失败。
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['success' => false, 'message' => '请求失败: ' . $err];

    $data = json_decode($resp, true);
    return is_array($data) ? $data : ['success' => false, 'message' => '响应格式异常'];
}

function logXianyuPublish(PDO $pdo, string $accountKey, int $gameId, string $gameName, string $status, string $triggerType, string $message, string $itemUrl, string $itemId, array $apiResp): void {
    // 显式写入北京时间，保证日志展示时间与用户设定的发布时间一致
    $stmt = $pdo->prepare("INSERT INTO xianyu_publish_logs (account_key, game_id, game_name_cn, status, trigger_type, message, item_url, item_id, api_response, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $accountKey, $gameId, $gameName, $status, $triggerType,
        safeTruncate($message, 5000),
        safeTruncate($itemUrl, 500),
        safeTruncate($itemId, 100),
        json_encode($apiResp, JSON_UNESCAPED_UNICODE),
        getBeijingNow(),
    ]);
}

function updateConfigStatus(PDO $pdo, string $accountKey, string $status): void {
    // 统一使用北京时间写入，避免 MySQL 服务器时区不同导致定时去重判断失效
    $stmt = $pdo->prepare("UPDATE xianyu_config SET last_publish_at = ?, last_publish_status = ? WHERE account_key = ?");
    $stmt->execute([getBeijingNow(), $status, $accountKey]);
}

/**
 * 获取当前北京时间 (Y-m-d H:i:s)，用于与定时发布时间点统一比较。
 * 不依赖 MySQL 服务器时区，确保去重逻辑在任何部署环境下都可靠。
 */
function getBeijingNow(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
}

/**
 * UTF-8 安全截断。
 * 优先使用 mb_substr；若服务器未安装 mbstring 扩展，则回退到 preg_split(//u)，
 * 避免直接用 substr 切断多字节字符产生非法 UTF-8 —— 非法 UTF-8 会让 json_encode
 * 返回 false，导致发出去的请求体为空，最终只看到「请求参数不能为空」这类
 * 极难排查的错误。
 */
function safeTruncate(string $s, int $max): string {
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max);
    }
    if (strlen($s) <= $max) return $s;
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) return substr($s, 0, $max);
    return implode('', array_slice($chars, 0, $max));
}

/**
 * 分销秘钥脱敏。
 *
 * get_xianyu_configs 是无身份校验的公开接口（项目整体采用"输入账号 Key 即登录"的免密设计），
 * 若把 secret_key 原样返回，任何匿名请求者都能拿到他人 xianyu-auto-reply 的分销秘钥。
 * 这里只回显首尾几位，保存时若前端原样提交了这个脱敏值，则视为"未修改"并保留原值。
 */
function maskSecretKey(?string $key): string {
    // 允许 null：老库里 xy_secret_key 可能是可空列，传 null 会让 string 形参抛 TypeError 掀翻整个接口
    $key = (string)$key;
    if ($key === '') return '';
    $len = strlen($key);
    if ($len <= 8) return str_repeat('*', $len);
    return substr($key, 0, 3) . str_repeat('*', 6) . substr($key, -3);
}
