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
if (!isset($_FILES['video'])) {
    http_response_code(400);
    echo json_encode(['error' => '没有上传文件']);
    exit;
}

$file = $_FILES['video'];

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
$allowedTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-msvideo'];
$allowedExtensions = ['mp4', 'webm', 'ogg', 'mov', 'avi'];

// 获取文件扩展名
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// 检查扩展名
if (!in_array($extension, $allowedExtensions)) {
    http_response_code(400);
    echo json_encode(['error' => '只支持 MP4, WEBM, OGG, MOV, AVI 格式']);
    exit;
}

// 检查 MIME 类型
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        http_response_code(400);
        echo json_encode(['error' => '不支持的视频格式，仅允许 MP4, WEBM, OGG, MOV, AVI']);
        exit;
    }
}

// 验证文件大小 (最大 100MB)
if ($file['size'] > 100 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => '视频大小不能超过 100MB']);
    exit;
}

// 根据来源参数确定子目录
$subDir = 'videos/';
$dateDir = '';
if (isset($_POST['source']) || isset($_GET['source'])) {
    $source = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['source'] ?? $_GET['source'] ?? '');
    if ($source === 'posts') {
        $subDir = 'posts/';
        $dateDir = date('Y-m') . '/';
    }
}

// 生成唯一文件名
$filename = date('Ymd_His') . '_' . uniqid() . '.' . $extension;
$uploadDir = dirname(__DIR__) . '/uploads/' . $subDir . $dateDir;
$uploadPath = $uploadDir . $filename;

// 确保上传目录存在
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => '无法创建上传目录']);
        exit;
    }
}

// 检查目录是否可写
if (!is_writable($uploadDir)) {
    http_response_code(500);
    echo json_encode(['error' => '上传目录不可写']);
    exit;
}

// 移动文件
if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
    $url = '/uploads/' . $subDir . $dateDir . $filename;
    echo json_encode([
        'success' => true,
        'url' => $url,
        'filename' => $filename,
        'originalName' => $file['name'],
        'size' => $file['size']
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => '保存文件失败，请检查目录权限']);
}
