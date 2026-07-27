<?php
header('Content-Type: application/json; charset=utf-8');

// 引入数据库配置
require_once __DIR__ . '/../../../config/database.php';

try {
    $db = getDB();
    $config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

    // 爱发电配置
    $ifdianConfig = [
        'ifdian_user_id' => $config['ifdian_user_id'] ?? '',
        'ifdian_api_token' => $config['ifdian_api_token'] ?? '',
        'ifdian_cookie' => $config['ifdian_cookie'] ?? '',
        'ifdian_public_key' => $config['ifdian_public_key'] ?? '',
        'ifdian_show_sponsor' => (int)($config['ifdian_show_sponsor'] ?? 0),
        'ifdian_username' => $config['ifdian_username'] ?? '',
    ];

    // 是否配置了基本信息
    $isConfigured = !empty($ifdianConfig['ifdian_username']);

    // 生成赞助链接
    $sponsorUrl = '';
    if (!empty($ifdianConfig['ifdian_username'])) {
        $sponsorUrl = 'https://ifdian.net/a/' . htmlspecialchars($ifdianConfig['ifdian_username']);
    }

    $response = [
        'success' => true,
        'data' => $ifdianConfig,
        'is_configured' => $isConfigured,
        'sponsor_url' => $sponsorUrl
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    $response = [
        'success' => false,
        'error' => $e->getMessage()
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
