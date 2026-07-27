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
if (!isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => '没有上传文件']);
    exit;
}

// 获取目标目录
$directory = $_POST['directory'] ?? 'files';
$allowedDirectories = ['files', 'images', 'videos', 'audio', 'logo', 'posts'];

// 安全验证：防止目录遍历攻击
// 1. 禁止路径遍历字符
if (strpos($directory, '..') !== false ||
    strpos($directory, '\\0') !== false ||
    strpos($directory, "\0") !== false ||
    strpos($directory, '\\') !== false) {
    $directory = 'files';
}

// 2. 检查是否为自定义路径（包含/）
if (strpos($directory, '/') !== false) {
    // 验证自定义路径的安全性
    $pathParts = explode('/', $directory);
    // 过滤空路径部分和包含遍历字符的部分
    $pathParts = array_filter($pathParts, function($part) {
        return !empty($part) &&
               strpos($part, '..') === false &&
               strpos($part, '\\0') === false &&
               strpos($part, "\0") === false;
    });

    if (count($pathParts) > 0 && in_array($pathParts[0], $allowedDirectories)) {
        // 路径有效，重建目录路径
        $directory = implode('/', $pathParts);
    } else {
        // 路径无效，使用默认目录
        $directory = 'files';
    }
} else {
    // 检查是否为预定义目录
    if (!in_array($directory, $allowedDirectories, true)) {
        $directory = 'files';
    }
}

$file = $_FILES['file'];

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

// 获取根目录名称（处理自定义路径）
$rootDirectory = $directory;
if (strpos($directory, '/') !== false) {
    $pathParts = explode('/', $directory);
    $rootDirectory = $pathParts[0];
}

// 根据根目录设置允许的文件类型
$allowedTypes = [];

switch ($rootDirectory) {
    case 'images':
        $allowedTypes = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'image/bmp' => ['bmp'],
            'image/tiff' => ['tiff', 'tif']
        ];
        break;
        
    case 'videos':
        $allowedTypes = [
            'video/mp4' => ['mp4'],
            'video/avi' => ['avi'],
            'video/mov' => ['mov'],
            'video/wmv' => ['wmv'],
            'video/flv' => ['flv'],
            'video/webm' => ['webm'],
            'video/mkv' => ['mkv']
        ];
        break;
        
    case 'audio':
        $allowedTypes = [
            'audio/mpeg' => ['mp3'],
            'audio/wav' => ['wav'],
            'audio/ogg' => ['ogg'],
            'audio/aac' => ['aac'],
            'audio/flac' => ['flac'],
            'audio/m4a' => ['m4a']
        ];
        break;
        
    case 'logo':
        $allowedTypes = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/x-icon' => ['ico'],
            'image/vnd.microsoft.icon' => ['ico']
        ];
        break;
        
    case 'posts':
        // posts 目录允许所有类型
        $allowedTypes = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'video/mp4' => ['mp4'],
            'video/webm' => ['webm'],
            'video/ogg' => ['ogg'],
            'audio/mpeg' => ['mp3'],
            'audio/wav' => ['wav'],
            'application/pdf' => ['pdf'],
            'application/zip' => ['zip'],
            'text/plain' => ['txt'],
            'application/json' => ['json'],
            'text/csv' => ['csv'],
        ];
        break;
        
    case 'files':
    default:
        $allowedTypes = [
            // 文档
            'application/pdf' => ['pdf'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            'application/vnd.ms-powerpoint' => ['ppt'],
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
            'text/plain' => ['txt'],
            
            // 压缩文件
            'application/zip' => ['zip'],
            'application/x-rar-compressed' => ['rar'],
            'application/x-7z-compressed' => ['7z'],
            
            // 其他
            'application/json' => ['json'],
            'text/csv' => ['csv'],
        ];
        break;
}

// 获取文件扩展名
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// 验证扩展名
$validExtension = false;
foreach ($allowedTypes as $mime => $exts) {
    if (in_array($extension, $exts)) {
        $validExtension = true;
        break;
    }
}

if (!$validExtension) {
    http_response_code(400);
    echo json_encode(['error' => '不支持的文件类型']);
    exit;
}

// 验证文件大小 (最大 50MB)
if ($file['size'] > 50 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => '文件大小不能超过 50MB']);
    exit;
}

// 生成唯一文件名
$filename = date('Ymd_His') . '_' . uniqid() . '.' . $extension;
$uploadDir = dirname(__DIR__) . '/uploads/' . $directory . '/';
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
    $url = '/uploads/' . $directory . '/' . $filename;
    echo json_encode([
        'success' => true,
        'url' => $url,
        'filename' => $filename,
        'originalName' => $file['name'],
        'size' => $file['size'],
        'directory' => $directory
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => '保存文件失败，请检查目录权限']);
}
