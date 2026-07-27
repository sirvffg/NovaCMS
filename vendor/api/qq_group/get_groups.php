<?php
/**
 * 获取QQ群列表API
 * 返回数据库中启用了API显示的QQ群信息
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 引入数据库配置
$databasePath = dirname(dirname(__DIR__)) . '/config/database.php';
if (file_exists($databasePath)) {
    require_once $databasePath;
} else {
    // 如果找不到config/database.php，尝试使用相对路径
    require_once '../../../config/database.php';
}

try {
    $db = getDB();
    
    // 检查表是否存在
    $db->query("SELECT 1 FROM qq_groups LIMIT 1");
    
    // 获取所有启用了API的群组（不检查is_show字段）
    try {
        $db->query("SELECT api_show FROM qq_groups LIMIT 1");
        $sql = "SELECT * FROM qq_groups WHERE api_show = 1 ORDER BY sort_order ASC, id DESC";
    } catch (PDOException $e) {
        // api_show字段不存在时的容错处理，返回所有群组
        $sql = "SELECT * FROM qq_groups ORDER BY sort_order ASC, id DESC";
    }

    $groups = $db->query($sql)->fetchAll();

    // 获取全局通知
    $globalNotification = null;
    $closeWaitTime = 0;
    $closeButtonText = '我知道了';
    try {
        $db->query("SELECT 1 FROM qq_groups_notification LIMIT 1");
        $notificationData = $db->query("SELECT * FROM qq_groups_notification WHERE id=1 LIMIT 1")->fetch();
        if ($notificationData && ($notificationData['is_enabled'] ?? 0)) {
            $globalNotification = $notificationData['notification_content'] ?? null;
            $closeWaitTime = (int)($notificationData['close_wait_time'] ?? 0);
            $closeButtonText = $notificationData['close_button_text'] ?? '我知道了';
        }
    } catch (PDOException $e) {
        // 全局通知表不存在，忽略
    }

    // 获取基础URL（兼容反向代理）
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    $baseUrl = $protocol . $host . $scriptPath . '/';

    // 构建返回数据，返回群名称、加群链接、最大人数、群介绍
    $groupsData = [];
    foreach ($groups as $group) {
        $groupsData[] = [
            'name' => $group['name'],
            'link' => $group['link'],
            'max_members' => (int)($group['max_members'] ?? 200),
            'description' => $group['description'] ?? ''
        ];
    }
    
    // 返回成功响应
    $response = [
        'code' => 200,
        'msg' => '获取成功',
        'count' => count($groupsData),
        'api_url' => $baseUrl
    ];

    // 如果有启用的全局通知，添加到响应中
    if ($globalNotification !== null) {
        $response['notification'] = $globalNotification;
        $response['close_wait_time'] = $closeWaitTime;
        $response['close_button_text'] = $closeButtonText;
    }

    // 添加数据
    $response['data'] = $groupsData;

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    // 数据库错误
    echo json_encode([
        'code' => 500,
        'msg' => '数据库错误: ' . $e->getMessage(),
        'data' => []
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    // 其他错误
    echo json_encode([
        'code' => 500,
        'msg' => '服务器错误: ' . $e->getMessage(),
        'data' => []
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
