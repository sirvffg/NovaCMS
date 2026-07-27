<?php
session_start();

// 如果未登录，返回错误
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once '../../config/database.php';
require_once '../../config/functions.php';

$db = getDB();

// 创建一个测试访问记录（仅用于调试）
if (isset($_GET['create_test'])) {
    $testVisit = [
        'ip_address' => '127.0.0.1',
        'page_url' => '/admin/index.php',
        'user_agent' => 'Mozilla/5.0 (Test Browser)',
        'referer' => null
    ];
    
    $stmt = $db->prepare("
        INSERT INTO visit_stats (ip_address, user_agent, page_url, referer, visit_time)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $testVisit['ip_address'],
        $testVisit['user_agent'],
        $testVisit['page_url'],
        $testVisit['referer']
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Test visit created']);
    exit;
}

// 检查表结构和数据
$tableInfo = $db->query("DESCRIBE visit_stats")->fetchAll();
$recentCount = $db->query("SELECT COUNT(*) as count FROM visit_stats WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetch()['count'];
$totalCount = $db->query("SELECT COUNT(*) as count FROM visit_stats")->fetch()['count'];

header('Content-Type: application/json');
echo json_encode([
    'table_info' => $tableInfo,
    'recent_count' => $recentCount,
    'total_count' => $totalCount,
    'current_time' => date('Y-m-d H:i:s')
]);