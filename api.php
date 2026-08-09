<?php
/**
 * 咸鱼多账号商品快速发布与管理系统 - PHP 后端 API 接口
 * 数据库连接配置与 RESTful 响应处理
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 数据库连接配置
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = 'root';
$db_name = 'xianyu_db';
$db_port = 3306;

try {
    $pdo = new PDO("mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '数据库连接失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
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
            jsonResponse(['status' => 'error', 'message' => '请输入账号 Key (用户名)'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM templates WHERE account_key = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            // 如果是 admin，自动创建 admin 初始账号
            if ($username === 'admin') {
                $stmt = $pdo->prepare("INSERT INTO templates (account_key, template_text) VALUES (?, ?)");
                $defaultAdminTpl = "【{{VAR_1}} ({{VAR_2}}) Steam正品游戏安装包 离线版/中文版】\n⚡ 自动发货 | 包含全套完整安装包+DLC扩展+终身更新\n✅ 送详细视频教程+远程协助安装 简体中文 免Steam繁琐步骤\n✅ 拍下即发 极速下载 随时随地畅玩！";
                $stmt->execute(['admin', $defaultAdminTpl]);

                $user = [
                    'account_key' => 'admin',
                    'template_text' => $defaultAdminTpl
                ];
            } else {
                jsonResponse(['status' => 'error', 'message' => '账号不存在，请联系管理员在控制台添加！'], 404);
            }
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
        $stmt = $pdo->query("SELECT id, account_key, template_text, created_at FROM templates ORDER BY id ASC");
        $accounts = $stmt->fetchAll();
        jsonResponse(['status' => 'success', 'accounts' => $accounts]);
        break;

    // ----------------------------------------------------
    // 3. 获取游戏列表及指定账号的隐藏状态
    // ----------------------------------------------------
    case 'get_games':
        $account_key = $_GET['account_key'] ?? 'admin';

        // 获取全部有效游戏
        $stmt = $pdo->query("SELECT id, game_name_cn, game_name_en, is_active FROM games WHERE is_active = 1 ORDER BY id ASC");
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
            jsonResponse(['status' => 'error', 'message' => '导入内容不能为空'], 400);
        }

        // 支持真实换行与字面量 \n 字符串
        $normalized_text = str_replace(['\n', "\r"], ["\n", ""], $raw_text);
        $lines = explode("\n", $normalized_text);
        $importedCount = 0;

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
                $stmtInsert->execute([$cn, $en]);
                $importedCount++;
            }
        }

        jsonResponse(['status' => 'success', 'message' => "成功批量导入 {$importedCount} 款商品"]);
        break;

    // ----------------------------------------------------
    // 9. 单个添加游戏
    // ----------------------------------------------------
    case 'add_game':
        $game_name_cn = trim($input['game_name_cn'] ?? '');
        $game_name_en = trim($input['game_name_en'] ?? '');

        if (empty($game_name_cn)) {
            jsonResponse(['status' => 'error', 'message' => '游戏中文名不能为空'], 400);
        }

        $stmt = $pdo->prepare("INSERT INTO games (game_name_cn, game_name_en, is_active) VALUES (?, ?, 1)");
        $stmt->execute([$game_name_cn, $game_name_en]);

        jsonResponse(['status' => 'success', 'message' => '游戏添加成功']);
        break;

    // ----------------------------------------------------
    // 10. 删除游戏
    // ----------------------------------------------------
    case 'delete_game':
        $game_id = (int)($input['game_id'] ?? 0);
        if ($game_id <= 0) {
            jsonResponse(['status' => 'error', 'message' => '参数错误'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM games WHERE id = ?");
        $stmt->execute([$game_id]);

        jsonResponse(['status' => 'success', 'message' => '游戏删除成功']);
        break;

    // ----------------------------------------------------
    // 11. 添加新子账号
    // ----------------------------------------------------
    case 'add_account':
        $account_key = trim($input['account_key'] ?? '');
        $template_text = trim($input['template_text'] ?? '');

        if (empty($account_key)) {
            jsonResponse(['status' => 'error', 'message' => '账号标识 (用户名) 不能为空'], 400);
        }

        if (empty($template_text)) {
            $template_text = "【{{GAME_CN}} ({{GAME_EN}}) 独立服务器开服出租】\n⚡ 秒级开服 | 极速延迟 | 7x24小时稳定";
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO templates (account_key, template_text) VALUES (?, ?)");
            $stmt->execute([$account_key, $template_text]);
            jsonResponse(['status' => 'success', 'message' => '账号创建成功']);
        } catch (Exception $e) {
            jsonResponse(['status' => 'error', 'message' => '账号标识 (用户名) 已存在，请换一个'], 400);
        }
        break;

    // ----------------------------------------------------
    // 12. 删除子账号
    // ----------------------------------------------------
    case 'delete_account':
        $account_key = trim($input['account_key'] ?? '');
        if (empty($account_key) || $account_key === 'admin') {
            jsonResponse(['status' => 'error', 'message' => '无法删除 admin 根账号'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM templates WHERE account_key = ?");
        $stmt->execute([$account_key]);

        jsonResponse(['status' => 'success', 'message' => '账号已成功删除']);
        break;

    // ----------------------------------------------------
    // 13. 管理员修改账号 Key (用户名)
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

            // 更新 templates 表
            $stmtUpdate = $pdo->prepare("UPDATE templates SET account_key = ? WHERE account_key = ?");
            $stmtUpdate->execute([$new_key, $old_key]);

            // 同步更新 published_logs 表关联
            $stmtLogs = $pdo->prepare("UPDATE published_logs SET account_key = ? WHERE account_key = ?");
            $stmtLogs->execute([$new_key, $old_key]);
        }

        jsonResponse(['status' => 'success', 'message' => '账号名称修改成功！', 'new_account_key' => $new_key]);
        break;

    default:
        jsonResponse(['status' => 'error', 'message' => '未知的请求接口 Action'], 404);
        break;
}
