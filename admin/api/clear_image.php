<?php
session_start();
require_once '../../config/database.php';

// 设置 JSON 响应头
header('Content-Type: application/json');

// 检查登录
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

// 获取要清除的类型
$type = $_GET['type'] ?? $_POST['type'] ?? '';

if (!in_array($type, ['favicon', 'logo'])) {
    http_response_code(400);
    echo json_encode(['error' => '无效的类型']);
    exit;
}

try {
    $db = getDB();
    
    // 获取当前文件路径
    $stmt = $db->query("SELECT {$type} FROM website_config LIMIT 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($config && !empty($config[$type])) {
        $filePath = dirname(__DIR__, 2) . $config[$type];
        
        // 删除文件
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        
        // 清空数据库字段
        $updateStmt = $db->prepare("UPDATE website_config SET {$type} = NULL WHERE id = 1");
        $updateStmt->execute();
    }
    
    echo json_encode([
        'success' => true,
        'message' => '清除成功'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '清除失败: ' . $e->getMessage()]);
}