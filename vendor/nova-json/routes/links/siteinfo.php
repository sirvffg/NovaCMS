<?php
/**
 * Links Site Info 路由
 * 命名空间: v1
 *
 * GET /v1/links/siteinfo - 获取本站信息（供其他网站添加友链时参考）
 */

register_rest_route('v1', '/links/siteinfo', [
    'methods'  => 'GET',
    'callback' => 'nova_get_siteinfo',
]);

function nova_get_siteinfo($request) {
    $db = getDB();

    $stmt = $db->query("SELECT * FROM website_config LIMIT 1");
    $config = $stmt->fetch();

    if (!$config) {
        return [
            'code'    => 'rest_error',
            'message' => '网站配置未找到',
            'data'    => ['status' => 404],
        ];
    }

    // 获取站点 URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $siteUrl = $protocol . '://' . $host . '/';

    // RSS 地址
    $rssUrl = $protocol . '://' . $host . '/license/rss.php';

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status'      => 200,
            'name'        => $config['website_name'] ?? '冷月笙寒的小窝',
            'url'         => $siteUrl,
            'description' => $config['website_intro'] ?? '',
            'rss_url'     => $rssUrl,
            'logo'        => $config['logo'] ?? '',
            'author'      => $config['website_author'] ?? '',
        ],
    ];
}
