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
    $trends = getVisitTrends(7);

    // 提取数据
    $labels = array_map(function ($item) {
        return date('m/d', strtotime($item['date']));
    }, $trends);

    $totalVisits    = array_column($trends, 'total_visits');
    $uniqueVisitors = array_column($trends, 'unique_visitors');
    $homepageVisits = array_column($trends, 'homepage_visits');
    $blogVisits     = array_column($trends, 'blog_visits');

    echo json_encode([
        'success'  => true,
        'labels'   => $labels,
        'datasets' => [
            'total_visits'    => $totalVisits,
            'unique_visitors' => $uniqueVisitors,
            'homepage_visits' => $homepageVisits,
            'blog_visits'     => $blogVisits
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
