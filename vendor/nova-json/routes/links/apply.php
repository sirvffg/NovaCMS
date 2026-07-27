<?php
/**
 * Links Apply 路由
 * 命名空间: v1
 *
 * POST /v1/links/apply - 提交友链申请
 */

register_rest_route('v1', '/links/apply', [
    'methods'  => 'POST',
    'callback' => 'nova_apply_link',
]);

function nova_apply_link($request) {
    $db = getDB();

    // 获取网站配置（用于发邮件通知站长）
    $configStmt = $db->query("SELECT * FROM website_config LIMIT 1");
    $config = $configStmt->fetch();

    $name         = trim(strip_tags($request->get_param('name') ?? ''));
    $url          = trim($request->get_param('url') ?? '');
    $logo         = trim($request->get_param('logo') ?? '');
    $description  = trim(strip_tags($request->get_param('description') ?? ''));
    $rss_url      = trim($request->get_param('rss_url') ?? '');
    $category_id  = $request->get_param('category_id');
    $category_id  = !empty($category_id) ? (int)$category_id : null;
    $contact_email = trim($request->get_param('contact_email') ?? '');
    $contact_name  = trim(strip_tags($request->get_param('contact_name') ?? ''));

    // ── 验证 ──
    if (empty($name)) {
        return [
            'code'    => 'rest_error',
            'message' => '请输入网站名称',
            'data'    => ['status' => 400],
        ];
    }

    if (empty($url)) {
        return [
            'code'    => 'rest_error',
            'message' => '请输入网站链接',
            'data'    => ['status' => 400],
        ];
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [
            'code'    => 'rest_error',
            'message' => '请输入有效的网站链接',
            'data'    => ['status' => 400],
        ];
    }

    if (empty($contact_email)) {
        return [
            'code'    => 'rest_error',
            'message' => '请输入联系邮箱',
            'data'    => ['status' => 400],
        ];
    }

    if (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        return [
            'code'    => 'rest_error',
            'message' => '请输入有效的邮箱地址',
            'data'    => ['status' => 400],
        ];
    }

    if (empty($contact_name)) {
        return [
            'code'    => 'rest_error',
            'message' => '请输入联系人姓名',
            'data'    => ['status' => 400],
        ];
    }

    // ── 检查链接是否已存在 ──
    $checkLink = $db->prepare("SELECT id FROM friend_links WHERE url = ?");
    $checkLink->execute([$url]);
    if ($checkLink->fetch()) {
        return [
            'code'    => 'rest_error',
            'message' => '该链接已经存在，请联系管理员',
            'data'    => ['status' => 409],
        ];
    }

    // ── 检查是否已有待审核申请 ──
    $checkApp = $db->prepare("SELECT id FROM friend_link_applications WHERE url = ? AND status = 0");
    $checkApp->execute([$url]);
    if ($checkApp->fetch()) {
        return [
            'code'    => 'rest_error',
            'message' => '您已经提交过该链接的申请，请耐心等待审核',
            'data'    => ['status' => 409],
        ];
    }

    // ── 插入申请记录 ──
    $userId = $_SESSION['user_id'] ?? null;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $stmt = $db->prepare(
        "INSERT INTO friend_link_applications
         (name, url, logo, description, rss_url, category_id, contact_email, contact_name, status, user_id, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)"
    );
    $stmt->execute([$name, $url, $logo, $description, $rss_url, $category_id, $contact_email, $contact_name, $userId, $ip]);
    $applicationId = $db->lastInsertId();

    // ── 获取分类名称 ──
    $categoryName = '';
    if ($category_id) {
        $catStmt = $db->prepare("SELECT name FROM friend_link_categories WHERE id = ?");
        $catStmt->execute([$category_id]);
        $catResult = $catStmt->fetch();
        $categoryName = $catResult ? $catResult['name'] : '';
    }

    // ── 发送邮件通知站长 ──
    if (!empty($config['contact_email'])) {
        sendNewApplicationNotice($config['contact_email'], $config['website_name'] ?? '', [
            'name'          => $name,
            'url'           => $url,
            'logo'          => $logo,
            'description'   => $description,
            'rss_url'       => $rss_url,
            'category_id'   => $category_id,
            'category_name' => $categoryName,
            'contact_email' => $contact_email,
            'contact_name'  => $contact_name,
        ]);
    }

    return [
        'code'    => 'rest_ok',
        'message' => '申请提交成功，我们会尽快审核您的申请！',
        'data'    => [
            'status' => 200,
            'id'     => (int)$applicationId,
        ],
    ];
}
