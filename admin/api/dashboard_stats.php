<?php
/**
 * 仪表盘基础统计数据 API
 * 返回总访问量、独立访客、文章/评论数、表单数据等
 */
session_start();

// 如果未登录，拒绝请求
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../includes/dashboard_stats.php';

$db = getDB();
$stats = getDashboardStats($db);

$result = [
    'totalVisits'    => (int)$stats['totalVisits'],
    'uniqueVisitors' => (int)$stats['uniqueVisitors'],
    'todayVisits'    => (int)$stats['todayVisits'],
    'todayUnique'    => (int)$stats['todayUnique'],
    'totalPosts'     => (int)$stats['totalPosts'],
    'totalComments'  => (int)$stats['totalComments'],
    'totalForms'     => (int)$stats['totalForms'],
    'pendingForms'   => (int)$stats['pendingForms'],
];

echo json_encode($result, JSON_UNESCAPED_UNICODE);
