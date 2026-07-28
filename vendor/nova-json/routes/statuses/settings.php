<?php
/**
 * Settings 路由
 * 命名空间: v1
 */

register_rest_route('v1', '/statuses/settings', [
    'methods'  => 'GET',
    'callback' => 'nova_get_settings',
]);

function nova_get_settings($request) {
    $db = getDB();

    $stmt = $db->query("SELECT * FROM website_config LIMIT 1");
    $config = $stmt->fetch();

    if (!$config) {
        return [
            'code'    => 'settings_not_found',
            'message' => '站点配置不存在',
            'data'    => ['status' => 404],
        ];
    }

    $fields = [
        // ── 基础信息 ──
        'website_name',             // 站点名称
        'website_author',           // 站长
        'robot_description',        // 搜索引擎描述
        'logo',                     // Logo 地址
        'favicon',                  // 网站图标
        'website_start_time',       // 网站开办时间
        // ── 联系方式 ──
        'contact_email',            // 联系邮箱
        'contact_qq',               // QQ号
        'social_wechat',            // 微信号
        'social_github',            // GitHub
        'social_douyin',            // 抖音号
        'social_kuaishou',          // 快手号
        'social_bilibili',          // B站号
        'social_xiaohongshu',       // 小红书号
        'social_whatsapp',          // WhatsApp
        'social_x',                 // X (Twitter)
        'social_discord',           // Discord
        'social_youtube',           // YouTube
        // ── 页脚 ──
        'footer_extra',             // 页脚附加信息(HTML)
    ];

    $item = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $config)) {
            $item[$f] = $config[$f];
        }
    }

    // ── 备案信息 ──
    $item['icp_record'] = $config['icp_record'] ?? '';
    $item['public_security_record'] = $config['public_security_record'] ?? '';

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => ['status' => 200, 'item' => $item],
    ];
}
