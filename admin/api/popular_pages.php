<?php
// 清除所有输出缓冲
if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

session_start();

// 如果未登录，返回错误
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once '../../config/database.php';
require_once '../../config/functions.php';

try {
    $pages = getPageVisitStats(7, 6);

    $formatted = [];
    $rank = 1;
    foreach ($pages as $page) {
        // 截断过长 URL 用于显示
        $displayUrl = $page['page_url'];
        if (mb_strlen($displayUrl) > 20) {
            $displayUrl = mb_substr($displayUrl, 0, 18) . '…';
        }

        $formatted[] = [
            'rank'           => $rank++,
            'page_url'       => $page['page_url'],
            'display_url'    => $displayUrl,
            'visits'         => (int)$page['visits'],
            'unique_visitors' => (int)$page['unique_visitors'],
            'last_visit'     => $page['last_visit']
        ];
    }

    echo json_encode(['success' => true, 'pages' => $formatted]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
