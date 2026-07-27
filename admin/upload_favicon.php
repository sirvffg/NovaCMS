<?php
session_start();
require_once '../config/database.php';

// 设置 JSON 响应头
header('Content-Type: application/json');

// 检查登录
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

// 检查是否有文件上传
if (!isset($_FILES['favicon'])) {
    http_response_code(400);
    echo json_encode(['error' => '没有上传文件']);
    exit;
}

$file = $_FILES['favicon'];

// 检查上传错误
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => '文件超过 php.ini 中 upload_max_filesize 限制',
        UPLOAD_ERR_FORM_SIZE => '文件超过表单中 MAX_FILE_SIZE 限制',
        UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
        UPLOAD_ERR_NO_FILE => '没有文件被上传',
        UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
        UPLOAD_ERR_CANT_WRITE => '文件写入失败',
        UPLOAD_ERR_EXTENSION => 'PHP 扩展停止了文件上传'
    ];
    $errorMsg = $errorMessages[$file['error']] ?? '上传失败，错误代码: ' . $file['error'];
    http_response_code(400);
    echo json_encode(['error' => $errorMsg]);
    exit;
}

// 验证文件类型
$allowedTypes = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/jpeg', 'image/gif'];
$allowedExtensions = ['ico', 'png', 'jpg', 'jpeg', 'gif'];

// 获取文件扩展名
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// 检查扩展名
if (!in_array($extension, $allowedExtensions)) {
    http_response_code(400);
    echo json_encode(['error' => '只支持 ICO, PNG, JPG, GIF 格式']);
    exit;
}

// 验证文件大小 (最大 1MB)
if ($file['size'] > 1 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => '文件大小不能超过 1MB']);
    exit;
}

// 固定文件名
$filename = 'favicon.' . $extension;
$uploadDir = dirname(__DIR__) . '/assets/images/';
$uploadPath = $uploadDir . $filename;

// 确保上传目录存在
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => '无法创建上传目录: ' . $uploadDir]);
        exit;
    }
}

// 检查目录是否可写
if (!is_writable($uploadDir)) {
    http_response_code(500);
    echo json_encode(['error' => '上传目录不可写: ' . $uploadDir]);
    exit;
}

// 删除旧的 favicon 文件
foreach (['favicon.ico', 'favicon.png', 'favicon.jpg', 'favicon.jpeg', 'favicon.gif'] as $oldFile) {
    $oldPath = $uploadDir . $oldFile;
    if (file_exists($oldPath) && $oldFile !== $filename) {
        @unlink($oldPath);
    }
}

// 移动文件
if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
    $url = '/assets/images/' . $filename;
    
    // 更新数据库（如果有 favicon 字段）
    try {
        $db = getDB();
        // 检查字段是否存在
        $columns = $db->query("SHOW COLUMNS FROM website_config LIKE 'favicon'")->fetchAll();
        if (count($columns) > 0) {
            $stmt = $db->prepare("UPDATE website_config SET favicon=? WHERE id=1");
            $stmt->execute([$url]);
        }
    } catch (Exception $e) {
        // 忽略数据库错误，文件已经上传成功
        error_log("Favicon database update error: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'url' => $url,
        'filename' => $filename
    ]);
} else {
    http_response_code(500);
    $errorInfo = error_get_last();
    echo json_encode([
        'error' => '保存文件失败，请检查目录权限',
        'debug' => [
            'uploadDir' => $uploadDir,
            'uploadPath' => $uploadPath,
            'is_dir' => is_dir($uploadDir),
            'is_writable' => is_writable($uploadDir),
            'tmp_name' => $file['tmp_name'],
            'tmp_exists' => file_exists($file['tmp_name']),
            'error_info' => $errorInfo
        ]
    ]);
}
