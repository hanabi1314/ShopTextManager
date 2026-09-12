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
    // 识别最常见的“数据表不存在”错误
    if (strpos($msg, '1146 Table') !== false || strpos($msg, '42S02') !== false) {
        $msg = '数据库表不存在！请在宝塔面板的 MySQL 管理中导入项目的 schema.sql 脚本文件。(' . $msg . ')';
    }
    
    echo json_encode([
        'status' => 'error',
        'message' => '服务器接口错误: ' . $msg
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// 全局 Error 捕捉
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => "PHP 系统错误: {$errstr} (位于 {$errfile} 第 {$errline} 行)"
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
    echo json_encode([
        'status' => 'error',
        'message' => '数据库连接失败: ' . $e->getMessage() . '。请检查宝塔中 .env 文件或 api.php 的数据库连接配置（主机、用户名、密码、数据库名）。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 自动建表与初始数据检测（避免未导入 schema.sql 导致 1146 错误）
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'templates'")->fetch();
    if (!$checkTable) {
        $sqlFile = __DIR__ . '/schema.sql';
        if (file_exists($sqlFile)) {
            $sqlContent = file_get_contents($sqlFile);
            // 移除 CREATE DATABASE 和 USE 指令以直接导入当前 DB
            $sqlContent = preg_replace('/CREATE DATABASE.*?;/is', '', $sqlContent);
            $sqlContent = preg_replace('/USE `.*?`;/is', '', $sqlContent);
            $pdo->exec($sqlContent);
        }
    }
    // v2.0: 兼容旧版部署 —— 若 games 表缺少 cover_url 字段则自动补充
    // (schema.sql 只在 templates 表不存在时才会整体导入，老部署不会自动获得新字段)
    $checkCover = $pdo->query("SHOW COLUMNS FROM games LIKE 'cover_url'")->fetch();
    if (!$checkCover) {
        $pdo->exec("ALTER TABLE games ADD COLUMN cover_url VARCHAR(255) DEFAULT '' COMMENT '游戏封面图片链接'");
    }

    // v2.0: 自动创建定时发布相关新表 (兼容旧版部署，不会影响已有数据)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `xianyu_config` (
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
        `image_source` VARCHAR(10) DEFAULT 'auto',
        `custom_image_url` TEXT,
        `last_publish_at` DATETIME DEFAULT NULL,
        `last_publish_status` VARCHAR(20) DEFAULT '',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_publish_enabled` (`publish_enabled`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `xianyu_publish_logs` (
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
} catch (Exception $e) {
    // 静默处理建表检测过程中的异常
}

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
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => '删除失败: ' . $e->getMessage()], 500);
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

            $pdo->commit();
            jsonResponse(['status' => 'success', 'message' => '账号已成功删除']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['status' => 'error', 'message' => '删除失败: ' . $e->getMessage()], 500);
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

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                jsonResponse(['status' => 'error', 'message' => '修改失败: ' . $e->getMessage()], 500);
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
            $cfg['xy_secret_key'] = (string)($cfg['xy_secret_key'] ?? '');
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

        $fields = [
            'xy_server_url' => trim($input['xy_server_url'] ?? ''),
            'xy_secret_key' => trim($input['xy_secret_key'] ?? ''),
            'xy_account_id' => trim($input['xy_account_id'] ?? ''),
            'xy_account_remark' => trim($input['xy_account_remark'] ?? ''),
            'publish_enabled' => !empty($input['publish_enabled']) ? 1 : 0,
            'publish_times' => trim($input['publish_times'] ?? '09:00'),
            'publish_price' => (float)($input['publish_price'] ?? 9.90),
            'publish_original_price' => (float)($input['publish_original_price'] ?? 0),
            'publish_address' => trim($input['publish_address'] ?? ''),
            'publish_quantity' => (int)($input['publish_quantity'] ?? 1),
            'publish_shipping_method' => trim($input['publish_shipping_method'] ?? 'free'),
            'publish_category_id' => trim($input['publish_category_id'] ?? ''),
            'publish_category_name' => trim($input['publish_category_name'] ?? ''),
            'publish_channel_cat_id' => trim($input['publish_channel_cat_id'] ?? ''),
            'publish_channel_cat_name' => trim($input['publish_channel_cat_name'] ?? ''),
            'publish_leaf_id' => trim($input['publish_leaf_id'] ?? ''),
            'publish_tb_cat_id' => trim($input['publish_tb_cat_id'] ?? ''),
            'image_source' => trim($input['image_source'] ?? 'auto'),
            'custom_image_url' => trim($input['custom_image_url'] ?? ''),
        ];

        // UPSERT: 存在则更新，不存在则插入
        $check = $pdo->prepare("SELECT id FROM xianyu_config WHERE account_key = ?");
        $check->execute([$ak]);
        $exists = $check->fetch();

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

        $apiUrl = $server_url . '/api/v1/external/enabled-accounts';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['secret_key' => $secret_key]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
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
            // 说明：该接口返回的是「该分销秘钥所属用户」名下的全部账号。
            // 若账号数少于预期，通常是这些账号挂在 xianyu-auto-reply 的其它用户下。
            $hint = '';
            if ($total > 0 && $enabledTotal < $total) {
                $hint = "（其中 {$enabledTotal} 个启用、" . ($total - $enabledTotal) . " 个已禁用；禁用账号同样可用于发布）";
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

        foreach ($configs as $cfg) {
            // 单个用户处理异常不能中断整个调度循环，否则会影响其他用户
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
                    // 没有待发布商品了（使用北京时间保持时区一致）
                    $pdo->prepare("UPDATE xianyu_config SET last_publish_at = ?, last_publish_status = 'idle' WHERE account_key = ?")
                        ->execute([getBeijingNow(), $cfg['account_key']]);
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
            } catch (Exception $e) {
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
        try { $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$lockName]); } catch (Exception $e) { /* 忽略 */ }
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
                if (microtime(true) > $deadline) {
                    if ($lastErr === '') {
                        $lastErr = '图片处理已超时，请为该商品设置封面图 URL 以减少联网搜索耗时';
                    }
                    break;
                }
                $queue = searchImagesOnline($cn, $en, 8);
                continue;
            }
            if (microtime(true) > $deadline) {
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

            $img = downloadImageToTemp($candidateUrl);
            if (!$img) {
                $lastErr = '图片下载失败或内容不是有效图片: ' . safeTruncate($candidateUrl, 120);
                continue;
            }

            $tmpFile = $img['path'];
            $triedCount++;
            try {
                $mediaResp = uploadMediaToXianyu(
                    $serverUrl,
                    $cfg['xy_secret_key'],
                    $cfg['xy_account_id'],
                    'image',
                    $img['path'],
                    $img['mime'],
                    'image' . $img['ext']
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
            $errMsg = '未能获取商品图片，请先为该商品设置封面图 URL，或在配置中指定自定义图片 URL';
            logXianyuPublish($pdo, $account_key, $game_id, $cn, 'failed', $triggerType, $errMsg, '', '', []);
            updateConfigStatus($pdo, $account_key, 'failed');
            return ['success' => false, 'message' => '未能获取商品图片，请先在「单个商品管理」为该商品设置封面图 URL'];
        }
        $errMsg = $triedCount === 0
            ? '所有候选图片均无法下载（可能不是有效图片或链接失效），请为该商品设置封面图 URL'
            : $lastErr;
        logXianyuPublish($pdo, $account_key, $game_id, $cn, 'failed', $triggerType, $errMsg, '', '', []);
        updateConfigStatus($pdo, $account_key, 'failed');
        return ['success' => false, 'message' => '图片上传到闲鱼服务器失败: ' . $errMsg];
    }

    // 7. (可选) 获取分类推荐 - 如果未预设分类
    $categoryId = $cfg['publish_category_id'];
    $categoryName = $cfg['publish_category_name'];
    if (empty($categoryId)) {
        $catResp = getXianyuCategoryRecommend(
            $serverUrl,
            $cfg['xy_secret_key'],
            $cfg['xy_account_id'],
            $description
        );
        if (!empty($catResp['success']) && !empty($catResp['data']['candidates'])) {
            $candidates = $catResp['data']['candidates'];
            $best = $candidates[0]; // 取第一个推荐
            $categoryId = $best['cat_id'] ?? '';
            $categoryName = $best['cat_name'] ?? '';
        }
    }

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
    if (!empty($categoryId)) {
        $publishPayload['platform_category_id'] = $categoryId;
        $publishPayload['platform_category_name'] = $categoryName;
    }
    if (!empty($cfg['publish_channel_cat_id'])) {
        $publishPayload['platform_channel_category_id'] = $cfg['publish_channel_cat_id'];
        $publishPayload['platform_channel_category_name'] = $cfg['publish_channel_cat_name'];
    }
    if (!empty($cfg['publish_leaf_id'])) {
        $publishPayload['platform_leaf_id'] = $cfg['publish_leaf_id'];
    }
    if (!empty($cfg['publish_tb_cat_id'])) {
        $publishPayload['platform_tb_category_id'] = $cfg['publish_tb_cat_id'];
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
function safeHttpGet(string $url, int $timeout = 20, int $maxRedirects = 5): ?array {
    if (!function_exists('curl_init')) return null;
    $current = $url;
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
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $next = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($body === false) return null;
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
 * 联网搜索商品图片，返回候选 URL 列表（按可信度排序，去重后返回）。
 *
 * 与旧版只返回单个 URL 不同：搜索引擎结果里常有缩略图、占位图、失效链接，
 * 单个 URL 一旦不可用整个发布就会失败。这里返回多个候选，由调用方逐个下载校验，
 * 直到拿到真实可用的图片为止。
 *
 * @return string[] 候选图片 URL 列表
 */
function searchImagesOnline(string $cn, string $en, int $limit = 10): array {
    $keyword = trim($en ?: $cn);
    if ($keyword === '') return [];
    $query = urlencode($keyword);

    $candidates = [];

    // 1) Bing 图片异步接口：返回片段中含原始图 murl，比主页面结构更稳定
    $resp = safeHttpGet("https://www.bing.com/images/async?q={$query}&first=0&count=35&mmasync=1", 12);
    if ($resp && !empty($resp['body'])) {
        if (preg_match_all('/murl&quot;:&quot;(https?:\/\/.+?)&quot;/', $resp['body'], $m)) {
            foreach ($m[1] as $u) $candidates[] = html_entity_decode($u);
        }
    }

    // 2) Bing 图片主页面（异步接口无结果时的回退）
    if (count($candidates) < $limit) {
        $resp2 = safeHttpGet("https://www.bing.com/images/search?q={$query}&form=HDRSC2&first=1", 12);
        if ($resp2 && !empty($resp2['body'])) {
            if (preg_match_all('/murl&quot;:&quot;(https?:\/\/.+?)&quot;/', $resp2['body'], $m2)) {
                foreach ($m2[1] as $u) $candidates[] = html_entity_decode($u);
            }
            if (preg_match_all('/class="mimg"[^>]+src="(https?:\/\/[^"]+)"/', $resp2['body'], $m3)) {
                foreach ($m3[1] as $u) $candidates[] = html_entity_decode($u);
            }
        }
    }

    // 3) 英文关键词再搜一轮（中文结果质量差时经常能救回来）
    if (count($candidates) < 3 && $en !== '' && $cn !== '' && $en !== $cn) {
        $q2 = urlencode($cn);
        $resp3 = safeHttpGet("https://www.bing.com/images/async?q={$q2}&first=0&count=35&mmasync=1", 12);
        if ($resp3 && !empty($resp3['body'])) {
            if (preg_match_all('/murl&quot;:&quot;(https?:\/\/.+?)&quot;/', $resp3['body'], $m4)) {
                foreach ($m4[1] as $u) $candidates[] = html_entity_decode($u);
            }
        }
    }

    // 去重并过滤明显不可用的地址（data:URI、SVG 等非受支持格式）
    $seen = [];
    $out = [];
    foreach ($candidates as $u) {
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

    // 域名：必须同时解析 A(IPv4) 与 AAAA(IPv6) 并逐个校验。
    // 只用 gethostbyname() 只能拿到 A 记录 —— 攻击者让域名 A=公网IP、AAAA=::1，
    // 校验会误判通过，而 curl 可能走 IPv6 连上内网。
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (empty($records)) return false; // 解析失败直接拒绝

    $checked = 0;
    foreach ($records as $r) {
        if (!empty($r['ipv4']) && !isPublicIp($r['ipv4'])) return false;
        if (!empty($r['ipv6']) && !isPublicIp($r['ipv6'])) return false;
        if (!empty($r['ipv4']) || !empty($r['ipv6'])) $checked++;
    }
    // 一条有效地址都没拿到就拒绝，避免漏判
    return $checked > 0;
}

/**
 * 单个 IP 是否为可安全访问的公网地址。
 * 私有网段(10/8、172.16/12、192.168/16)、保留地址、链路本地(169.254/16)一律拒绝。
 */
function isPublicIp(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
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
function downloadImageToTemp(string $url): ?array {
    $resp = safeHttpGet($url, 30);
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
function uploadMediaToXianyu(string $serverUrl, string $secretKey, string $accountId, string $mediaType, string $filePath, string $mime = '', string $fileName = ''): array {
    if (!file_exists($filePath)) return ['success' => false, 'message' => '图片文件不存在'];

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
        CURLOPT_TIMEOUT => 60,
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
        CURLOPT_TIMEOUT => 30,
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
        CURLOPT_TIMEOUT => 120,
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
