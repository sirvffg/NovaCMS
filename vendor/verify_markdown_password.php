<?php
session_start();

// 检查是否为管理员
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '无权访问']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $password = $_POST['password'] ?? '';
    
    $configPath = __DIR__ . '/../config/markdown_copy_password.config';
    if (!file_exists($configPath)) {
        echo json_encode(['success' => false, 'message' => '配置文件不存在']);
        exit;
    }
    
    $config = parse_ini_file($configPath);
    $correctPasswordHash = $config['password'] ?? '';
    
    // 使用 MD5 验证
    if (md5($password) === $correctPasswordHash) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => '密码错误']);
    }
    exit;
}
