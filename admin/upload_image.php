<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/includes/image_mapper.php';

// 设置 JSON 响应头
header('Content-Type: application/json');

// 检查登录
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

// 检查是否有文件上传
if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => '没有上传文件']);
    exit;
}

$file = $_FILES['image'];

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

// 根据来源参数确定子目录
$subDir = '';
$dateDir = '';
if (isset($_POST['source']) || isset($_GET['source'])) {
    $source = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['source'] ?? $_GET['source'] ?? '');
    if ($source) {
        $subDir = $source . '/';
        // posts 类型按日期建子目录
        if ($source === 'posts') {
            $dateDir = date('Y-m') . '/';
        }
    }
}

// 根据来源确定允许的文件类型
$isVideo = false;
$imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$videoTypes = ['video/mp4', 'video/webm', 'video/ogg'];
$videoExtensions = ['mp4', 'webm', 'ogg'];

if ($subDir === 'posts/') {
    // posts 支持图片和视频
    $allowedTypes = array_merge($imageTypes, $videoTypes);
    $allowedExtensions = array_merge($imageExtensions, $videoExtensions);
    $maxSize = 50 * 1024 * 1024; // 视频 50MB
} else {
    $allowedTypes = $imageTypes;
    $allowedExtensions = $imageExtensions;
    $maxSize = 5 * 1024 * 1024;
}

// 获取文件扩展名
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// 检查扩展名
if (!in_array($extension, $allowedExtensions)) {
    if ($subDir === 'posts/') {
        http_response_code(400);
        echo json_encode(['error' => '只支持 JPG, PNG, GIF, WEBP, MP4, WEBM, OGG 格式']);
    } else {
        http_response_code(400);
        echo json_encode(['error' => '只支持 JPG, PNG, GIF, WEBP 格式']);
    }
    exit;
}

// 判断是否视频
$isVideo = in_array($extension, $videoExtensions);

// 检查 MIME 类型
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        http_response_code(400);
        echo json_encode(['error' => '文件类型不正确']);
        exit;
    }
}

// 验证文件大小
if ($file['size'] > $maxSize) {
    $maxMB = $maxSize / (1024 * 1024);
    http_response_code(400);
    echo json_encode(['error' => '文件大小不能超过 ' . $maxMB . 'MB']);
    exit;
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
    // 不进行压缩，保持原图质量

    $localUrl = '/uploads/' . $subDir . $dateDir . $filename;
    $localPath = str_replace('\\', '/', $uploadPath);
    
    // 添加到映射表（不上传到图床，访问时按需转换）
    ImageMapper::add($localPath, $localUrl, '', $filename);
    
    $response = [
        'success' => true,
        'url' => $localUrl,
        'filename' => $filename,
        'is_video' => $isVideo,
        'local_url' => $localUrl,
        'local_path' => $localPath
    ];
    
    echo json_encode($response);
} else {
    http_response_code(500);
    echo json_encode(['error' => '保存文件失败，请检查目录权限']);
}

/**
 * 压缩图片函数
 * @param string $source 源文件路径
 * @param string $destination 目标文件路径
 * @param int $quality 质量 (0-100)
 * @return bool
 */
function compressImage($source, $destination, $quality) {
    // 检查文件大小，超过50MB跳过压缩
    if (filesize($source) > 50 * 1024 * 1024) {
        return false;
    }
    
    $info = getimagesize($source);
    if (!$info) return false;
    
    $mime = $info['mime'];
    $image = null;
    
    // 增加内存限制
    ini_set('memory_limit', '256M');
    
    try {
        switch ($mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($source);
                // 自动旋转（处理手机拍摄照片的方向问题）
                if (function_exists('exif_read_data')) {
                    $exif = @exif_read_data($source);
                    if (!empty($exif['Orientation']) && $image) {
                        switch ($exif['Orientation']) {
                            case 3:
                                $image = imagerotate($image, 180, 0);
                                break;
                            case 6:
                                $image = imagerotate($image, -90, 0);
                                break;
                            case 8:
                                $image = imagerotate($image, 90, 0);
                                break;
                        }
                    }
                }
                if ($image) {
                    imagejpeg($image, $destination, $quality);
                }
                break;
                
            case 'image/png':
                $image = imagecreatefrompng($source);
                if ($image) {
                    // 保留透明度
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                    imagepng($image, $destination, 9);
                }
                break;

            case 'image/webp':
                $image = imagecreatefromwebp($source);
                if ($image) {
                    imagewebp($image, $destination, $quality);
                }
                break;
                
            default:
                return false;
        }
        
        if ($image) {
            imagedestroy($image);
            return true;
        }
    } catch (Exception $e) {
        if ($image) {
            imagedestroy($image);
        }
        return false;
    }
    
    return false;
}

