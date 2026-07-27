<?php
/**
 * 图床上传管理界面
 * 按照文章分类显示图片，让用户选择要上传到图床的文章图片
 */

ob_start();

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/includes/image_mapper.php';

requireLogin();

$db = getDB();

// 从数据库获取网站配置
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 图床配置
$imageBedEnabled = !empty($config['image_bed_enabled']);
$imageBedApiUrl = $config['image_bed_api_url'] ?? '';
$imageBedApiKey = $config['image_bed_api_key'] ?? '';

// 函数定义
function extractImagesFromContent($content) {
    $images = [];
    
    // 匹配 Markdown 图片格式 ![alt](url)
    preg_match_all('/!\[.*?\]\((.*?)\)/', $content, $matches);
    foreach ($matches[1] as $url) {
        if (strpos($url, '/uploads/') !== false) {
            $images[] = ['url' => $url, 'type' => 'markdown'];
        }
    }
    
    // 匹配 HTML img 标签
    preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches);
    foreach ($matches[1] as $url) {
        if (strpos($url, '/uploads/') !== false) {
            $exists = false;
            foreach ($images as $img) {
                if ($img['url'] === $url) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $images[] = ['url' => $url, 'type' => 'html'];
            }
        }
    }
    
    return $images;
}

function getPostsWithImages($db) {
    $sql = "SELECT id, title, content FROM blog_posts ORDER BY id DESC";
    $stmt = $db->query($sql);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    foreach ($posts as $post) {
        $images = extractImagesFromContent($post['content']);
        if (!empty($images)) {
            $result[] = [
                'id' => $post['id'],
                'title' => $post['title'],
                'images' => $images,
                'image_count' => count($images)
            ];
        }
    }
    return $result;
}

function uploadToImageBed($apiUrl, $apiKey, $filePath) {
    if (!file_exists($filePath)) {
        return ['success' => false, 'error' => '文件不存在: ' . $filePath];
    }
    
    // 获取文件内容
    $fileContent = file_get_contents($filePath);
    if ($fileContent === false) {
        return ['success' => false, 'error' => '无法读取文件: ' . $filePath];
    }
    
    // 获取文件MIME类型
    $mimeType = false;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
    }
    if (!$mimeType && function_exists('mime_content_type')) {
        $mimeType = mime_content_type($filePath);
    }
    if (!$mimeType) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml'
        ];
        $mimeType = $mimeMap[$ext] ?? 'application/octet-stream';
    }
    
    // 【深度优化】：优先使用前端传来的缩略图
    if (isset($_POST['thumbnail_base64']) && !empty($_POST['thumbnail_base64'])) {
        $thumbnailBase64 = $_POST['thumbnail_base64'];
    } else {
        // 如果前端没有传来（如之前的旧方法/批量后台处理兜底），走后端安全缩略图逻辑
        $thumbnailBase64 = generateThumbnail($filePath, $mimeType);
    }
    
    // 获取文件名
    $filename = basename($filePath);
    
    // 使用新API接口: /api/external/upload
    $baseUrl = rtrim($apiUrl, '/');
    $uploadUrl = $baseUrl . '/api/external/upload';
    
    // 构建 FormData 请求
    $postData = [
        'file' => new CURLFile($filePath, $mimeType, $filename),
        'title' => '来自博客系统的上传'
    ];
    
    // 只有在有缩略图时才添加
    if (!empty($thumbnailBase64)) {
        $postData['thumbnail'] = $thumbnailBase64;
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $uploadUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'CURL错误: ' . $error];
    }
    
    $data = json_decode($response, true);
    
    // 调试信息
    $debug = ['http_code' => $httpCode, 'response' => $response, 'upload_url' => $uploadUrl];
    
    // 新API返回格式: {success: true, url: "..."}
    if ($httpCode === 200 && isset($data['success']) && $data['success'] === true && !empty($data['url'])) {
        return [
            'success' => true,
            'url' => $data['url'],
            'id' => $data['id'] ?? 0
        ];
    }
    
    // 尝试兼容旧格式
    if ($httpCode === 200 && isset($data['code']) && $data['code'] === 200 && !empty($data['data']['url'])) {
        return [
            'success' => true,
            'url' => $data['data']['url'],
            'id' => $data['data']['id'] ?? 0
        ];
    }
    
    return [
        'success' => false, 
        'error' => $data['msg'] ?? $data['error'] ?? '上传失败',
        'debug' => [
            'http_code' => $httpCode, 
            'response_preview' => substr($response, 0, 500),
            'upload_url' => $uploadUrl
        ]
    ];
}

/**
 * 后端备用生成缩略图 (最大宽度250px)
 * 【核心改进】：增加 getimagesize 预先防御，防止大分辨率图片导致后端爆内存
 */
function generateThumbnail($filePath, $mimeType) {
    // 1. 检查文件大小，超过10MB直接跳过
    if (filesize($filePath) > 10 * 1024 * 1024) {
        return '';
    }
    
    // 根据MIME类型决定处理方式
    if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
        
        // 2. 预先读取尺寸，不将其载入内存开辟位图
        $imageInfo = @getimagesize($filePath);
        if (!$imageInfo) {
            return '';
        }
        
        // 分辨率超过 3000px 的图直接跳过不生成缩略图，防止后端内存崩溃
        if ($imageInfo[0] > 3000 || $imageInfo[1] > 3000) {
            return '';
        }

        $image = null;
        switch ($mimeType) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($filePath);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($filePath);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($filePath);
                break;
            case 'image/webp':
                $image = @imagecreatefromwebp($filePath);
                break;
        }
        
        if ($image) {
            $origWidth = imagesx($image);
            $origHeight = imagesy($image);
            
            $maxWidth = 250;
            if ($origWidth > $maxWidth) {
                $newWidth = $maxWidth;
                $newHeight = intval($origHeight * ($maxWidth / $origWidth));
            } else {
                $newWidth = $origWidth;
                $newHeight = $origHeight;
            }
            
            $thumbnail = imagecreatetruecolor($newWidth, $newHeight);
            
            // 保持PNG透明度
            if ($mimeType === 'image/png') {
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);
            }
            
            imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
            
            // 输出为JPEG格式的Base64
            ob_start();
            imagejpeg($thumbnail, null, 60);
            $imageData = ob_get_clean();
            imagedestroy($image);
            imagedestroy($thumbnail);
            
            return 'data:image/jpeg;base64,' . base64_encode($imageData);
        }
    }
    
    return '';
}

function getUploadsDir() {
    return realpath(__DIR__ . '/../uploads');
}

function getLocalPath($url) {
    $uploadsDir = getUploadsDir();
    $localPath = str_replace('/uploads/', '/', $url);
    return $uploadsDir . $localPath;
}

// 获取封面目录中的所有图片（支持分页）
function getCoverImages($page = 1, $perPage = 20) {
    $coverDir = realpath(__DIR__ . '/../uploads/cover');
    if (!$coverDir || !is_dir($coverDir)) {
        return ['total' => 0, 'page' => $page, 'per_page' => $perPage, 'images' => []];
    }

    $images = [];
    $files = scandir($coverDir);
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $imageExtensions) && is_file($coverDir . '/' . $file)) {
            $localUrl = '/uploads/cover/' . $file;
            $info = ImageMapper::getByUrl($localUrl);
            $images[] = [
                'filename' => $file,
                'local_url' => $localUrl,
                'local_path' => $coverDir . '/' . $file,
                'uploaded' => $info && !empty($info['image_bed_url']),
                'image_bed_url' => $info['image_bed_url'] ?? '',
                'size' => filesize($coverDir . '/' . $file),
                'modified' => filemtime($coverDir . '/' . $file)
            ];
        }
    }

    // 按修改时间倒序
    usort($images, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });

    $total = count($images);
    $totalPages = ceil($total / $perPage);
    $page = max(1, min($page, $totalPages ?: 1));
    $offset = ($page - 1) * $perPage;

    return [
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'images' => array_slice($images, $offset, $perPage)
    ];
}

// 格式化文件大小
function formatSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

// 映射表管理函数 - 使用 ImageMapper 类
function isImageUploaded($localUrl) {
    $info = ImageMapper::getByUrl($localUrl);
    return $info && !empty($info['image_bed_url']);
}

// 获取所有已上传的图片URL列表
function getUploadedImagesMap() {
    $all = ImageMapper::getAll();
    $uploaded = [];
    foreach ($all as $item) {
        if (!empty($item['local_url']) && !empty($item['image_bed_url'])) {
            $uploaded[$item['local_url']] = $item['image_bed_url'];
        }
    }
    return $uploaded;
}

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'delete_mapping') {
        $localUrl = $_POST['local_url'] ?? '';
        
        if (empty($localUrl)) {
            echo json_encode(['success' => false, 'message' => '缺少图片URL参数']);
            exit;
        }
        
        require_once __DIR__ . '/includes/image_mapper.php';
        
        // 获取图片信息
        $info = ImageMapper::getByUrl($localUrl);
        if (!$info) {
            echo json_encode(['success' => false, 'message' => '映射记录不存在']);
            exit;
        }
        
        // 删除映射
        if (ImageMapper::delete($localUrl)) {
            echo json_encode([
                'success' => true,
                'message' => '删除成功'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '删除失败']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'delete_all_mappings') {
        require_once __DIR__ . '/includes/image_mapper.php';
        
        $deleted = 0;
        $all = ImageMapper::getAll();
        foreach ($all as $item) {
            if (!empty($item['local_url'])) {
                if (ImageMapper::delete($item['local_url'])) {
                    $deleted++;
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => "已删除 {$deleted} 条映射记录",
            'deleted' => $deleted
        ]);
        exit;
    }
    
    if ($_POST['action'] === 'delete_selected_mappings') {
        require_once __DIR__ . '/includes/image_mapper.php';
        
        $urls = $_POST['urls'] ?? [];
        if (empty($urls)) {
            echo json_encode(['success' => false, 'message' => '没有选择图片']);
            exit;
        }
        
        $deleted = 0;
        $failed = 0;
        foreach ($urls as $url) {
            $info = ImageMapper::getByUrl($url);
            if ($info) {
                if (ImageMapper::delete($url)) {
                    $deleted++;
                } else {
                    $failed++;
                }
            } else {
                $deleted++; // 不存在的也当成功处理
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => "已删除 {$deleted} 张图片的映射",
            'deleted' => $deleted,
            'failed' => $failed
        ]);
        exit;
    }
    
    if ($_POST['action'] === 'upload_post') {
        if (!$imageBedEnabled || empty($imageBedApiUrl) || empty($imageBedApiKey)) {
            echo json_encode(['success' => false, 'message' => '图床未配置']);
            exit;
        }
        
        $postId = intval($_POST['post_id']);
        $stmt = $db->prepare("SELECT content FROM blog_posts WHERE id = ?");
        $stmt->execute([$postId]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$post) {
            echo json_encode(['success' => false, 'message' => '文章不存在']);
            exit;
        }
        
        $images = extractImagesFromContent($post['content']);
        if (empty($images)) {
            echo json_encode(['success' => true, 'message' => '没有图片', 'uploaded' => 0]);
            exit;
        }
        
        $uploaded = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];
        
        foreach ($images as $image) {
            $localPath = getLocalPath($image['url']);
            
            // 如果已经上传过，跳过
            if (isImageUploaded($image['url'])) {
                $skipped++;
                $results[] = ['url' => $image['url'], 'success' => true, 'skipped' => true, 'message' => '已上传过'];
                continue;
            }
            
            if (!file_exists($localPath)) {
                $results[] = ['url' => $image['url'], 'success' => false, 'error' => '文件不存在: ' . $localPath];
                $failed++;
                continue;
            }
            
            $result = uploadToImageBed($imageBedApiUrl, $imageBedApiKey, $localPath);
            
            if ($result['success']) {
                $filename = basename($image['url']);
                ImageMapper::add($localPath, $image['url'], $result['url'], $filename, $result['id'] ?? 0);
                $uploaded++;
                $results[] = ['url' => $image['url'], 'success' => true, 'new_url' => $result['url']];
            } else {
                $failed++;
                $results[] = ['url' => $image['url'], 'success' => false, 'error' => $result['error']];
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => "上传完成: 成功 {$uploaded} 张, 跳过 {$skipped} 张, 失败 {$failed} 张",
            'uploaded' => $uploaded,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results
        ]);
        exit;
    }
    
    // 【深度拓展】：全新单张图片异步上传（含前端传回的 Base64 缩略图）
    if ($_POST['action'] === 'upload_single_image_with_thumb') {
        if (!$imageBedEnabled || empty($imageBedApiUrl) || empty($imageBedApiKey)) {
            echo json_encode(['success' => false, 'message' => '图床未配置']);
            exit;
        }
        
        $localUrl = $_POST['local_url'] ?? '';
        if (empty($localUrl)) {
            echo json_encode(['success' => false, 'message' => '缺少图片URL']);
            exit;
        }
        
        $localPath = getLocalPath($localUrl);
        if (!file_exists($localPath)) {
            echo json_encode(['success' => false, 'message' => '文件不存在']);
            exit;
        }
        
        if (isImageUploaded($localUrl)) {
            echo json_encode(['success' => true, 'message' => '已上传过', 'skipped' => true]);
            exit;
        }
        
        $result = uploadToImageBed($imageBedApiUrl, $imageBedApiKey, $localPath);
        
        if ($result['success']) {
            $filename = basename($localUrl);
            ImageMapper::add($localPath, $localUrl, $result['url'], $filename, $result['id'] ?? 0);
            echo json_encode([
                'success' => true,
                'message' => '上传成功',
                'url' => $result['url']
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => $result['error'] ?? '上传失败',
                'debug' => $result['debug'] ?? null
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'upload_all') {
        if (!$imageBedEnabled || empty($imageBedApiUrl) || empty($imageBedApiKey)) {
            echo json_encode(['success' => false, 'message' => '图床未配置']);
            exit;
        }
        
        $postIds = $_POST['post_ids'] ?? [];
        $results = [];
        $totalUploaded = 0;
        $totalFailed = 0;
        $totalSkipped = 0;
        
        foreach ($postIds as $postId) {
            $postId = intval($postId);
            $stmt = $db->prepare("SELECT content FROM blog_posts WHERE id = ?");
            $stmt->execute([$postId]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$post) continue;
            
            $images = extractImagesFromContent($post['content']);
            $uploaded = 0;
            $failed = 0;
            $skipped = 0;
            
            foreach ($images as $image) {
                if (isImageUploaded($image['url'])) {
                    $skipped++;
                    continue;
                }
                
                $localPath = getLocalPath($image['url']);
                if (!file_exists($localPath)) {
                    $failed++;
                    continue;
                }
                
                $result = uploadToImageBed($imageBedApiUrl, $imageBedApiKey, $localPath);
                
                if ($result['success']) {
                    $filename = basename($image['url']);
                    ImageMapper::add($localPath, $image['url'], $result['url'], $filename, $result['id'] ?? 0);
                    $uploaded++;
                } else {
                    $failed++;
                }
            }
            
            $totalUploaded += $uploaded;
            $totalFailed += $failed;
            $totalSkipped += $skipped;
            $results[$postId] = ['uploaded' => $uploaded, 'skipped' => $skipped, 'failed' => $failed];
        }
        
        echo json_encode([
            'success' => true,
            'message' => "批量上传完成: 成功 {$totalUploaded} 张, 跳过 {$totalSkipped} 张, 失败 {$totalFailed} 张",
            'results' => $results
        ]);
        exit;
    }
    
    // 获取封面列表（分页）
    if ($_POST['action'] === 'get_covers') {
        $page = intval($_POST['page'] ?? 1);
        $perPage = intval($_POST['per_page'] ?? 20);
        $covers = getCoverImages($page, $perPage);
        echo json_encode(['success' => true, 'data' => $covers]);
        exit;
    }
    
    // 上传单个封面到图床
    if ($_POST['action'] === 'upload_cover') {
        if (!$imageBedEnabled || empty($imageBedApiUrl) || empty($imageBedApiKey)) {
            echo json_encode(['success' => false, 'message' => '图床未配置']);
            exit;
        }
        
        $localUrl = $_POST['local_url'] ?? '';
        if (empty($localUrl)) {
            echo json_encode(['success' => false, 'message' => '缺少图片URL']);
            exit;
        }
        
        $localPath = getLocalPath($localUrl);
        if (!file_exists($localPath)) {
            echo json_encode(['success' => false, 'message' => '文件不存在']);
            exit;
        }
        
        // 检查是否已上传
        if (isImageUploaded($localUrl)) {
            echo json_encode(['success' => true, 'message' => '已上传过', 'skipped' => true]);
            exit;
        }
        
        $result = uploadToImageBed($imageBedApiUrl, $imageBedApiKey, $localPath);
        
        if ($result['success']) {
            $filename = basename($localUrl);
            ImageMapper::add($localPath, $localUrl, $result['url'], $filename, $result['id'] ?? 0);
            echo json_encode([
                'success' => true,
                'message' => '上传成功',
                'url' => $result['url'],
                'image_bed_url' => $result['url']
            ]);
        } else {
            $response = [
                'success' => false, 
                'message' => $result['error'] ?? '上传失败'
            ];
            // 添加调试信息
            if (!empty($result['debug'])) {
                $response['debug'] = $result['debug'];
            }
            echo json_encode($response);
        }
        exit;
    }
    
    // 批量上传封面
    if ($_POST['action'] === 'upload_all_covers') {
        if (!$imageBedEnabled || empty($imageBedApiUrl) || empty($imageBedApiKey)) {
            echo json_encode(['success' => false, 'message' => '图床未配置']);
            exit;
        }
        
        $covers = getCoverImages(1, 1000); // 获取所有封面
        $uploaded = 0;
        $skipped = 0;
        $failed = 0;
        $results = [];
        
        foreach ($covers['images'] as $cover) {
            if ($cover['uploaded']) {
                $skipped++;
                continue;
            }
            
            $result = uploadToImageBed($imageBedApiUrl, $imageBedApiKey, $cover['local_path']);
            
            if ($result['success']) {
                ImageMapper::add($cover['local_path'], $cover['local_url'], $result['url'], $cover['filename'], $result['id'] ?? 0);
                $uploaded++;
                $results[] = ['url' => $cover['local_url'], 'success' => true, 'image_bed_url' => $result['url']];
            } else {
                $failed++;
                $resultItem = ['url' => $cover['local_url'], 'success' => false, 'error' => $result['error'] ?? '上传失败'];
                if (!empty($result['debug'])) {
                    $resultItem['debug'] = $result['debug'];
                }
                $results[] = $resultItem;
            }
        }
        
        $response = [
            'success' => true,
            'message' => "封面上传完成: 成功 {$uploaded} 张, 跳过 {$skipped} 张, 失败 {$failed} 张",
            'uploaded' => $uploaded,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results
        ];
        if ($failed > 0 && !empty($results)) {
            $failedResults = array_filter($results, function($r) { return !$r['success']; });
            if (!empty($failedResults)) {
                $response['failed_details'] = $failedResults;
            }
        }
        echo json_encode($response);
        exit;
    }
    
    // 删除封面映射
    if ($_POST['action'] === 'delete_cover_mapping') {
        $localUrl = $_POST['local_url'] ?? '';
        if (empty($localUrl)) {
            echo json_encode(['success' => false, 'message' => '缺少图片URL']);
            exit;
        }
        
        if (ImageMapper::delete($localUrl)) {
            echo json_encode(['success' => true, 'message' => '删除成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '删除失败']);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => '未知操作']);
    exit;
}

// 获取文章数据
try {
    $posts = getPostsWithImages($db);
    $uploadedImages = getUploadedImagesMap();
    $coversPage = intval($_GET['covers_page'] ?? 1);
    $coversData = getCoverImages($coversPage, 20);
} catch (Exception $e) {
    $posts = [];
    $uploadedImages = [];
    $coversData = ['total' => 0, 'page' => 1, 'per_page' => 20, 'total_pages' => 0, 'images' => []];
    $error_msg = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>图床上传管理</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { margin-bottom: 20px; color: #333; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .alert-warning { background: #fffbe6; border: 1px solid #ffe58f; color: #d48806; }
        .alert-info { background: #e6f7ff; border: 1px solid #91d5ff; color: #1890ff; }
        
        .header-bar { 
            background: #fff; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .summary { color: #666; font-size: 14px; }
        .btn {
            padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer;
            font-size: 14px; transition: all 0.2s;
        }
        .btn-primary { background: #1890ff; color: #fff; }
        .btn-primary:hover { background: #40a9ff; }
        .btn-success { background: #52c41a; color: #fff; }
        .btn-success:hover { background: #73d13d; }
        .btn-orange { background: #fa8c16; color: #fff; }
        .btn-orange:hover { background: #ffa940; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .post-item {
            background: #fff; border-radius: 8px; margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden;
        }
        .post-header {
            padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;
            cursor: pointer; border-bottom: 1px solid #f0f0f0;
        }
        .post-header:hover { background: #fafafa; }
        .post-title { font-size: 16px; font-weight: 500; color: #333; }
        .post-meta { font-size: 12px; color: #999; margin-top: 4px; }
        .post-checkbox { margin-right: 15px; transform: scale(1.2); cursor: pointer; }
        .post-body { display: none; padding: 20px; background: #fafafa; }
        .post-body.show { display: block; }
        .image-grid-empty { text-align: center; color: #999; padding: 20px; }
        
        .image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; }
        .image-item {
            background: #fff; border-radius: 6px; overflow: hidden;
            border: 2px solid transparent; transition: all 0.2s; position: relative;
        }
        .image-item img { width: 100%; height: 120px; object-fit: cover; }
        .image-item .filename { padding: 8px; font-size: 11px; color: #666; word-break: break-all; }
        .image-item.uploaded { border-color: #52c41a; }
        .image-item.uploaded::after {
            content: '已上传'; position: absolute; top: 5px; right: 5px;
            background: #52c41a; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 10px;
        }
        .image-item .uploaded-badge {
            position: absolute; top: 5px; left: 5px;
            background: #52c41a; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 10px;
        }
        
        .arrow { transition: transform 0.2s; margin-left: 10px; }
        .post-header.open .arrow { transform: rotate(90deg); }
        
        .result-message { padding: 10px; border-radius: 4px; margin-top: 10px; font-size: 14px; }
        .result-message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: #52c41a; }
        .result-message.error { background: #fff2f0; border: 1px solid #ffccc7; color: #ff4d4f; }
        
        .batch-bar { 
            position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
            background: #fff; padding: 15px 25px; border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: none;
            z-index: 100;
        }
        .batch-bar.show { display: flex; gap: 15px; align-items: center; }
        
        .error-box {
            background: #fff2f0; border: 1px solid #ffccc7; color: #ff4d4f;
            padding: 15px; border-radius: 8px; margin-bottom: 20px;
        }
        
        .btn-danger { background: #ff4d4f; color: #fff; }
        .btn-danger:hover { background: #ff7875; }
        
        .image-item .delete-btn {
            position: absolute; bottom: 5px; right: 5px;
            background: rgba(255,77,79,0.9); color: #fff; border: none;
            padding: 3px 8px; border-radius: 3px; font-size: 10px;
            cursor: pointer; display: none;
        }
        .image-item:hover .delete-btn { display: block; }
        .image-item.uploaded .delete-btn { display: none; }
        
        .header-actions { display: flex; gap: 10px; align-items: center; }
        
        .image-item input[type="checkbox"] {
            position: absolute; top: 5px; left: 5px; transform: scale(1.2); z-index: 1;
        }
        .image-item.uploaded input[type="checkbox"] { display: none; }

        /* Tab 切换样式 */
        .tab-bar { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e8e8e8; padding-bottom: 0; }
        .tab-btn { padding: 12px 24px; background: none; border: none; cursor: pointer; font-size: 15px; color: #666; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s; }
        .tab-btn:hover { color: #1890ff; }
        .tab-btn.active { color: #1890ff; border-bottom-color: #1890ff; font-weight: 500; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .cover-section { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .cover-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .cover-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; }
        .cover-item { background: #fafafa; border-radius: 8px; overflow: hidden; border: 2px solid transparent; transition: all 0.2s; position: relative; }
        .cover-item:hover { border-color: #d9d9d9; }
        .cover-item.uploaded { border-color: #52c41a; }
        .cover-item img { width: 100%; height: 120px; object-fit: cover; background: #f0f0f0; }
        .cover-item .info { padding: 10px; }
        .cover-item .filename { font-size: 12px; color: #333; word-break: break-all; margin-bottom: 5px; }
        .cover-item .size { font-size: 11px; color: #999; }
        .cover-item .actions { display: flex; gap: 5px; margin-top: 8px; }
        .cover-item .actions button { flex: 1; padding: 5px; font-size: 12px; border: none; border-radius: 4px; cursor: pointer; }
        .cover-item .upload-btn { background: #1890ff; color: #fff; }
        .cover-item .upload-btn:hover { background: #40a9ff; }
        .cover-item .delete-btn { background: #ff4d4f; color: #fff; }
        .cover-item .delete-btn:hover { background: #ff7875; }
        .cover-item .uploaded-badge { position: absolute; top: 8px; right: 8px; background: #52c41a; color: #fff; padding: 3px 8px; border-radius: 4px; font-size: 11px; }
        .cover-item .loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.6); color: #fff; padding: 5px 10px; border-radius: 4px; font-size: 12px; }

        .pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 20px; }
        .pagination button { padding: 6px 12px; border: 1px solid #d9d9d9; background: #fff; border-radius: 4px; cursor: pointer; font-size: 13px; }
        .pagination button:hover:not(:disabled) { border-color: #1890ff; color: #1890ff; }
        .pagination button:disabled { opacity: 0.5; cursor: not-allowed; }
        .pagination .info { color: #666; font-size: 13px; padding: 0 10px; }

        .cover-stats { display: flex; gap: 20px; padding: 15px; background: #fafafa; border-radius: 8px; margin-bottom: 15px; }
        .cover-stats .stat { text-align: center; }
        .cover-stats .stat-value { font-size: 24px; font-weight: 600; color: #333; }
        .cover-stats .stat-label { font-size: 12px; color: #999; }
        .cover-stats .stat-value.uploaded { color: #52c41a; }
        .cover-stats .stat-value.pending { color: #fa8c16; }
    </style>
</head>
<body>
    <div class="container">
        <h1>图床上传 management</h1>

        <?php if (!empty($error_msg)): ?>
        <div class="error-box">
            错误: <?= htmlspecialchars($error_msg) ?>
        </div>
        <?php endif; ?>

        <?php if (!$imageBedEnabled): ?>
        <div class="alert alert-warning">
            <span>⚠️ 图床未启用，请在设置中启用并配置图床API</span>
        </div>
        <?php elseif (empty($imageBedApiKey)): ?>
        <div class="alert alert-warning">
            <span>⚠️ 图床API密钥未配置，请在设置中填写API密钥</span>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            <span>📤 图床配置正常，当前API地址: <?= htmlspecialchars($imageBedApiUrl) ?></span>
        </div>
        <?php endif; ?>

        <div class="tab-bar">
            <button class="tab-btn active" onclick="switchTab('posts')">📄 文章图片</button>
            <button class="tab-btn" onclick="switchTab('covers')">🖼️ 封面图片</button>
        </div>

        <div id="tab-posts" class="tab-content active">
            <div class="header-bar">
                <div class="summary">
                    共找到 <strong><?= count($posts) ?></strong> 篇文章包含图片，共 <strong><?= array_sum(array_column($posts, 'image_count')) ?></strong> 张图片
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="selectAllImages()">全选图片</button>
                    <button class="btn btn-primary" onclick="selectNoneImages()">取消选择</button>
                    <button class="btn btn-danger" onclick="deleteSelectedMappings()">删除选中</button>
                    <button class="btn btn-danger" onclick="deleteAllMappings()">清空所有映射</button>
                </div>
            </div>
            
            <?php if (empty($posts)): ?>
                <div class="post-item">
                    <div class="post-header" style="cursor:default;">
                        <span>没有找到包含图片的文章</span>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <?php 
                    $uploadedCount = 0;
                    foreach ($post['images'] as $img) {
                        if (isset($uploadedImages[$img['url']])) {
                            $uploadedCount++;
                        }
                    }
                    $totalCount = count($post['images']);
                    ?>
                    <div class="post-item" data-post-id="<?= $post['id'] ?>">
                        <div class="post-header" onclick="togglePost(this)">
                            <div style="display:flex;align-items:center;">
                                <input type="checkbox" class="post-checkbox" onchange="updateBatchBar()" onclick="event.stopPropagation()" <?= ($uploadedCount >= $totalCount) ? 'disabled' : '' ?>>
                                <div>
                                    <div class="post-title"><?= htmlspecialchars($post['title']) ?></div>
                                    <div class="post-meta">
                                        文章ID: <?= $post['id'] ?> | 图片数量: <?= $totalCount ?>
                                        <?php if ($uploadedCount > 0): ?>
                                            | <span style="color:#52c41a;">已上传: <?= $uploadedCount ?>/<?= $totalCount ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <span class="arrow">▶</span>
                        </div>
                        <div class="post-body" data-loaded="false">
                            <div class="image-grid"><div class="image-grid-empty">点击加载图片...</div></div>
                            <div style="margin-top:15px;">
                                <?php if ($uploadedCount >= $totalCount): ?>
                                    <span class="result-message success" style="display:inline-block;">✓ 所有图片已上传到图床</span>
                                <?php else: ?>
                                    <button class="btn btn-orange" onclick="uploadPost(<?= $post['id'] ?>, this)" <?= (!$imageBedEnabled || empty($imageBedApiKey)) ? 'disabled' : '' ?>>上传这篇文章的图片</button>
                                    <span class="result-message" style="display:inline-block;"></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <script type="application/json" class="post-images-data"><?= json_encode($post['images']) ?></script>
                        <script type="application/json" class="post-uploaded-map"><?= json_encode($uploadedImages) ?></script>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div id="tab-covers" class="tab-content">
            <div class="cover-section">
                <div class="cover-header">
                    <div class="cover-stats" id="coversStats">
                        <div class="stat">
                            <div class="stat-value"><?= $coversData['total'] ?></div>
                            <div class="stat-label">全部封面</div>
                        </div>
                        <div class="stat">
                            <div class="stat-value uploaded"><?php
                                $uploadedCount = 0;
                                foreach ($coversData['images'] as $c) { if ($c['uploaded']) $uploadedCount++; }
                                echo $uploadedCount;
                            ?></div>
                            <div class="stat-label">已上传</div>
                        </div>
                        <div class="stat">
                            <div class="stat-value pending"><?= count($coversData['images']) - $uploadedCount ?></div>
                            <div class="stat-label">待上传</div>
                        </div>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-orange" id="uploadAllCoversBtn" onclick="uploadAllCovers()" <?= (!$imageBedEnabled || empty($imageBedApiKey)) ? 'disabled' : '' ?>>批量上传封面</button>
                    </div>
                </div>

                <div class="cover-grid" id="coversGrid">
                    <?php if (empty($coversData['images'])): ?>
                        <div class="image-grid-empty">没有封面图片</div>
                    <?php else: ?>
                        <?php foreach ($coversData['images'] as $cover): ?>
                            <div class="cover-item <?= $cover['uploaded'] ? 'uploaded' : '' ?>" data-url="<?= $cover['local_url'] ?>">
                                <?php if ($cover['uploaded']): ?>
                                    <div class="uploaded-badge">已上传</div>
                                <?php endif; ?>
                                <img src="<?= htmlspecialchars($cover['local_url']) ?>" alt="<?= htmlspecialchars($cover['filename']) ?>" loading="lazy" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22100%22 height=%22100%22><rect fill=%22%23f0f0f0%22 width=%22100%22 height=%22100%22/><text x=%2250%22 y=%2255%22 text-anchor=%22middle%22 fill=%22%23999%22 font-size=%2212%22>加载失败</text></svg>'">
                                <div class="info">
                                    <div class="filename"><?= htmlspecialchars($cover['filename']) ?></div>
                                    <div class="size"><?= formatSize($cover['size']) ?></div>
                                    <div class="actions">
                                        <?php if ($cover['uploaded']): ?>
                                            <button class="delete-btn" onclick="deleteCoverMapping('<?= urlencode($cover['local_url']) ?>')">删除映射</button>
                                        <?php else: ?>
                                            <button class="upload-btn" onclick="uploadSingleCover('<?= urlencode($cover['local_url']) ?>', this)">上传</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="pagination" id="coversPagination">
                    <?php if ($coversData['total_pages'] > 1): ?>
                        <button onclick="goToCoversPage(<?= $coversData['page'] - 1 ?>)" <?= $coversData['page'] <= 1 ? 'disabled' : '' ?>>上一页</button>
                        <span class="info">第 <?= $coversData['page'] ?> / <?= $coversData['total_pages'] ?> 页</span>
                        <button onclick="goToCoversPage(<?= $coversData['page'] + 1 ?>)" <?= $coversData['page'] >= $coversData['total_pages'] ? 'disabled' : '' ?>>下一页</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="batch-bar" id="batchBar">
            <span>已选择 <strong id="selectedCount">0</strong> 篇文章</span>
            <button class="btn btn-orange" onclick="uploadSelected()" <?= (!$imageBedEnabled || empty($imageBedApiKey)) ? 'disabled' : '' ?>>批量上传到图床</button>
        </div>
    </div>
    
    <script>
        // 【核心优化】：在前端使用画布（Canvas）等比例异步生成缩略图，不消耗任何服务器内存！
        function makeFrontendThumbnail(url, maxWidth = 250) {
            return new Promise((resolve) => {
                const img = new Image();
                img.crossOrigin = "Anonymous"; 
                img.onload = function() {
                    let width = img.width;
                    let height = img.height;
                    
                    if (width > maxWidth) {
                        height = Math.round(height * (maxWidth / width));
                        width = maxWidth;
                    } else {
                        // 如果图片本身没有超过 250 宽度，则不用压缩
                        resolve('');
                        return;
                    }

                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    
                    // 硬件加速重采样渲染小图
                    ctx.drawImage(img, 0, 0, width, height);
                    
                    // 压缩为 60% 画质的 Base64 字符串
                    const base64 = canvas.toDataURL('image/jpeg', 0.6);
                    resolve(base64);
                };
                img.onerror = function() {
                    resolve(''); // 遇到死链或格式不支持直接返回空
                };
                img.src = url;
            });
        }

        function togglePost(el) {
            const postItem = el.closest('.post-item');
            const postBody = postItem.querySelector('.post-body');
            const isOpening = !el.classList.contains('open');
            
            el.classList.toggle('open');
            postBody.classList.toggle('show');
            
            if (isOpening && postBody.dataset.loaded === 'false') {
                loadPostImages(postItem);
            }
        }
        
        function loadPostImages(postItem) {
            const postBody = postItem.querySelector('.post-body');
            const dataEl = postItem.querySelector('.post-images-data');
            const uploadedMapEl = postItem.querySelector('.post-uploaded-map');
            const grid = postBody.querySelector('.image-grid');
            
            if (!dataEl) return;
            
            const images = JSON.parse(dataEl.textContent);
            const uploadedMap = uploadedMapEl ? JSON.parse(uploadedMapEl.textContent) : {};
            
            if (images.length === 0) {
                grid.innerHTML = '<div class="image-grid-empty">没有图片</div>';
            } else {
                grid.innerHTML = images.map(img => {
                    const isUploaded = uploadedMap[img.url];
                    const uploadedClass = isUploaded ? 'uploaded' : '';
                    const uploadedBadge = isUploaded ? '<div class="uploaded-badge">已上传</div>' : '';
                    const checkbox = isUploaded ? '' : `<input type="checkbox" class="image-checkbox" onchange="updateDeleteBar()" data-url="${encodeURIComponent(img.url)}">`;
                    const deleteBtn = isUploaded ? '' : `<button class="delete-btn" onclick="event.stopPropagation(); deleteMapping('${encodeURIComponent(img.url)}')">删除</button>`;
                    return `
                    <div class="image-item ${uploadedClass}" data-url="${encodeURIComponent(img.url)}">
                        ${checkbox}
                        <img src="${img.url}" alt="" loading="lazy" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22100%22 height=%22100%22><rect fill=%22%23f0f0f0%22 width=%22100%22 height=%22100%22/><text x=%2250%22 y=%2255%22 text-anchor=%22middle%22 fill=%22%23999%22 font-size=%2212%22>图片加载失败</text></svg>'">
                        ${uploadedBadge}
                        ${deleteBtn}
                        <div class="filename">${img.url.split('/').pop()}</div>
                    </div>
                `}).join('');
            }
            
            postBody.dataset.loaded = 'true';
        }
        
        function selectAllImages() {
            document.querySelectorAll('.image-checkbox').forEach(cb => cb.checked = true);
            updateDeleteBar();
        }
        
        function selectNoneImages() {
            document.querySelectorAll('.image-checkbox').forEach(cb => cb.checked = false);
            updateDeleteBar();
        }
        
        function updateDeleteBar() {
            const checked = document.querySelectorAll('.image-checkbox:checked');
            console.log('选中的图片数量:', checked.length);
        }
        
        function deleteSelectedMappings() {
            const checked = document.querySelectorAll('.image-checkbox:checked');
            if (checked.length === 0) {
                alert('请先选择要删除的图片');
                return;
            }
            
            if (!confirm('确定要删除选中的 ' + checked.length + ' 张图片的映射记录吗？')) return;
            
            const urls = [];
            checked.forEach(cb => {
                urls.push(decodeURIComponent(cb.dataset.url));
            });
            
            fetch('upload_to_imagebed.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete_selected_mappings&urls=' + encodeURIComponent(JSON.stringify(urls))
            })
            .then(r => r.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            })
            .catch(err => {
                alert('请求失败: ' + err);
            });
        }
        
        function updateBatchBar() {
            const checked = document.querySelectorAll('.post-checkbox:checked');
            const bar = document.getElementById('batchBar');
            const count = document.getElementById('selectedCount');
            count.textContent = checked.length;
            bar.classList.toggle('show', checked.length > 0);
        }
        
        // 【核心修改】：单篇图片改为一条条单张图片处理，并赋予前端压缩的缩略图参数
        async function uploadPost(postId, btn) {
            const postItem = document.querySelector(`.post-item[data-post-id="${postId}"]`);
            if (!postItem) return;
            
            const dataEl = postItem.querySelector('.post-images-data');
            const uploadedMapEl = postItem.querySelector('.post-uploaded-map');
            if (!dataEl) return;
            
            const images = JSON.parse(dataEl.textContent);
            const uploadedMap = uploadedMapEl ? JSON.parse(uploadedMapEl.textContent) : {};
            const msg = btn.nextElementSibling;
            
            btn.disabled = true;
            btn.textContent = '准备处理图片...';
            msg.style.display = 'none';

            let uploaded = 0, failed = 0, skipped = 0;
            
            for (let img of images) {
                if (uploadedMap[img.url]) {
                    skipped++;
                    continue;
                }
                
                btn.textContent = `生成缩略图: ${img.url.split('/').pop()}...`;
                // 1. 调用前端函数在用户本地画小缩略图，避免后端爆内存
                const thumbBase64 = await makeFrontendThumbnail(img.url, 250);
                
                btn.textContent = `正在同步: ${img.url.split('/').pop()}...`;
                
                // 2. 发起合并单图请求，后端直接使用该数据
                try {
                    let response = await fetch('upload_to_imagebed.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=upload_single_image_with_thumb&local_url=${encodeURIComponent(img.url)}&thumbnail_base64=${encodeURIComponent(thumbBase64)}`
                    });
                    let data = await response.json();
                    if (data.success) {
                        uploaded++;
                        uploadedMap[img.url] = data.url; // 写入已上传缓存
                    } else {
                        failed++;
                        console.error('上传失败详情：', data);
                    }
                } catch(e) {
                    failed++;
                    console.error('请求网络错误：', e);
                }
            }
            
            // 刷新页面状态展现
            btn.disabled = false;
            btn.textContent = '上传这篇文章的图片';
            
            msg.className = failed === 0 ? 'result-message success' : 'result-message error';
            msg.textContent = `同步完成：成功 ${uploaded} 张，跳过 ${skipped} 张，失败 ${failed} 张。`;
            msg.style.display = 'inline-block';
            
            // 更新当前DOM视图状态
            if (uploaded > 0) {
                if (uploadedMapEl) uploadedMapEl.textContent = JSON.stringify(uploadedMap);
                loadPostImages(postItem); // 重新加载视图样式
            }
        }
        
        function deleteMapping(url) {
            if (!confirm('确定要删除这张图片的映射记录吗？')) return;
            url = decodeURIComponent(url);
            
            fetch('upload_to_imagebed.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete_mapping&local_url=' + encodeURIComponent(url)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.image-item').forEach(item => {
                        if (decodeURIComponent(item.dataset.url) === url) {
                            item.remove();
                        }
                    });
                    alert(data.message);
                } else {
                    alert(data.message || '删除失败');
                }
            })
            .catch(err => {
                alert('请求失败: ' + err);
            });
        }
        
        function deleteAllMappings() {
            if (!confirm('确定要清空所有映射记录吗？此操作不可恢复！')) return;
            if (!confirm('再次确认：删除后图片将无法通过图床访问！')) return;
            
            fetch('upload_to_imagebed.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete_all_mappings'
            })
            .then(r => r.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            })
            .catch(err => {
                alert('请求失败: ' + err);
            });
        }
        
        // 【优化】：批量文章多图上传逻辑，也全面改为安全的“前端串行分流处理”
        async function uploadSelected() {
            const checked = document.querySelectorAll('.post-checkbox:checked');
            if (checked.length === 0) return;
            
            if (!confirm(`确定要处理选中的 ${checked.length} 篇文章的图片同步吗？`)) return;
            
            const btn = document.querySelector('.batch-bar .btn-orange');
            btn.disabled = true;
            
            for (let cb of checked) {
                const postItem = cb.closest('.post-item');
                const actionBtn = postItem.querySelector('.btn-orange');
                if (actionBtn) {
                    cb.checked = false; // 上传中解除选择状态
                    await uploadPost(postItem.dataset.postId, actionBtn);
                }
            }
            
            btn.disabled = false;
            updateBatchBar();
            alert('批量选中文章处理结束');
        }

        // ==================== 封面图片管理 ====================
        let coversCurrentPage = <?= $coversData['page'] ?>;
        let coversTotalPages = <?= $coversData['total_pages'] ?>;
        let coversData = <?= json_encode($coversData) ?>;

        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.querySelector(`[onclick="switchTab('${tab}')"]`).classList.add('active');
            document.getElementById('tab-' + tab).classList.add('active');

            if (tab === 'covers' && !coversData.images.length) {
                loadCovers(coversCurrentPage);
            }
        }

        function loadCovers(page) {
            const grid = document.getElementById('coversGrid');
            if (!grid) return;
            grid.innerHTML = '<div class="image-grid-empty">加载中...</div>';

            fetch('upload_to_imagebed.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=get_covers&page=${page}&per_page=20`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    coversData = data.data;
                    coversCurrentPage = data.data.page;
                    coversTotalPages = data.data.total_pages;
                    renderCovers(data.data);
                    renderCoversPagination();
                    updateCoversStats(data.data);
                }
            })
            .catch(err => {
                grid.innerHTML = '<div class="image-grid-empty">加载失败: ' + err + '</div>';
            });
        }

        function updateCoversStats(data) {
            const stats = document.getElementById('coversStats');
            const uploaded = data.images.filter(c => c.uploaded).length;
            const pending = data.images.length - uploaded;
            stats.innerHTML = `
                <div class="stat">
                    <div class="stat-value">${data.total}</div>
                    <div class="stat-label">全部封面</div>
                </div>
                <div class="stat">
                    <div class="stat-value uploaded">${uploaded}</div>
                    <div class="stat-label">已上传</div>
                </div>
                <div class="stat">
                    <div class="stat-value pending">${pending}</div>
                    <div class="stat-label">待上传</div>
                </div>
            `;
        }

        function renderCovers(data) {
            const grid = document.getElementById('coversGrid');
            if (!data.images.length) {
                grid.innerHTML = '<div class="image-grid-empty">没有封面图片</div>';
                return;
            }

            grid.innerHTML = data.images.map(cover => `
                <div class="cover-item ${cover.uploaded ? 'uploaded' : ''}" data-url="${cover.local_url}">
                    ${cover.uploaded ? '<div class="uploaded-badge">已上传</div>' : ''}
                    <img src="${cover.local_url}" alt="${cover.filename}" loading="lazy" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22100%22 height=%22100%22><rect fill=%22%23f0f0f0%22 width=%22100%22 height=%22100%22/><text x=%2250%22 y=%2255%22 text-anchor=%22middle%22 fill=%22%23999%22 font-size=%2212%22>加载失败</text></svg>'">
                    <div class="info">
                        <div class="filename">${cover.filename}</div>
                        <div class="size">${formatFileSize(cover.size)}</div>
                        <div class="actions">
                            ${cover.uploaded ? `
                                <button class="delete-btn" onclick="deleteCoverMapping('${encodeURIComponent(cover.local_url)}')">删除映射</button>
                            ` : `
                                <button class="upload-btn" onclick="uploadSingleCover('${encodeURIComponent(cover.local_url)}', this)">上传</button>
                            `}
                        </div>
                    </div>
                </div>
            `).join('');
        }

        function renderCoversPagination() {
            const pagination = document.getElementById('coversPagination');
            if (!pagination) return;
            if (coversTotalPages <= 1) {
                pagination.innerHTML = '';
                return;
            }

            pagination.innerHTML = `
                <button onclick="goToCoversPage(${coversCurrentPage - 1})" ${coversCurrentPage <= 1 ? 'disabled' : ''}>上一页</button>
                <span class="info">第 ${coversCurrentPage} / ${coversTotalPages} 页</span>
                <button onclick="goToCoversPage(${coversCurrentPage + 1})" ${coversCurrentPage >= coversTotalPages ? 'disabled' : ''}>下一页</button>
            `;
        }

        function goToCoversPage(page) {
            if (page < 1 || page > coversTotalPages) return;
            loadCovers(page);
        }

        // 【优化】：单张封面上传也采用前端画布预先压缩，避免 8192 超高像素爆内存
        async function uploadSingleCover(url, btn) {
            url = decodeURIComponent(url);
            const item = document.querySelector(`.cover-item[data-url="${url}"]`);
            if (!item) return;

            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'loading';
            loadingDiv.textContent = '本地压缩中...';
            item.appendChild(loadingDiv);

            btn.disabled = true;
            btn.textContent = '处理中...';

            // 前端快速缩放
            const thumbBase64 = await makeFrontendThumbnail(url, 250);
            loadingDiv.textContent = '上传中...';

            fetch('upload_to_imagebed.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=upload_single_image_with_thumb&local_url=${encodeURIComponent(url)}&thumbnail_base64=${encodeURIComponent(thumbBase64)}`
            })
            .then(r => r.json())
            .then(data => {
                loadingDiv.remove();
                btn.disabled = false;

                if (data.success) {
                    item.classList.add('uploaded');
                    if (!item.querySelector('.uploaded-badge')) {
                        const badge = document.createElement('div');
                        badge.className = 'uploaded-badge';
                        badge.textContent = '已上传';
                        item.appendChild(badge);
                    }
                    item.querySelector('.actions').innerHTML = `
                        <button class="delete-btn" onclick="deleteCoverMapping('${encodeURIComponent(url)}')">删除映射</button>
                    `;
                    updateCoversStatsAfterChange();
                } else {
                    btn.textContent = '上传';
                    alert(data.message || '上传失败');
                }
            })
            .catch(err => {
                loadingDiv.remove();
                btn.disabled = false;
                btn.textContent = '上传';
                alert('请求失败: ' + err);
            });
        }

        function deleteCoverMapping(url) {
            url = decodeURIComponent(url);
            if (!confirm('确定要删除这张封面图的映射记录吗？')) return;

            fetch('upload_to_imagebed.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete_cover_mapping&local_url=' + encodeURIComponent(url)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const item = document.querySelector(`.cover-item[data-url="${url}"]`);
                    if (item) {
                        item.classList.remove('uploaded');
                        const badge = item.querySelector('.uploaded-badge');
                        if (badge) badge.remove();
                        item.querySelector('.actions').innerHTML = `
                            <button class="upload-btn" onclick="uploadSingleCover('${encodeURIComponent(url)}', this)">上传</button>
                        `;
                    }
                    updateCoversStatsAfterChange();
                } else {
                    alert(data.message);
                }
            })
            .catch(err => {
                alert('请求失败: ' + err);
            });
        }

        // 【优化】：批量封面图上传改造为安全的串行前端上传
        async function uploadAllCovers() {
            if (!confirm('确定要按序异步同步所有未上传封面吗？')) return;

            const btn = document.getElementById('uploadAllCoversBtn');
            btn.disabled = true;
            btn.textContent = '队列执行中...';

            const unuploadedCovers = document.querySelectorAll('.cover-item:not(.uploaded) .upload-btn');
            
            for (let coverBtn of unuploadedCovers) {
                const onclickStr = coverBtn.getAttribute('onclick');
                const match = onclickStr.match(/'([^']+)'/);
                if (match && match[1]) {
                    await uploadSingleCover(match[1], coverBtn);
                }
            }

            btn.disabled = false;
            btn.textContent = '批量上传封面';
            alert('全量封面队列同步完毕');
        }

        function updateCoversStatsAfterChange() {
            const items = document.querySelectorAll('.cover-item');
            const total = coversData.total || items.length;
            const uploaded = document.querySelectorAll('.cover-item.uploaded').length;
            const pending = items.length - uploaded;
            
            const stats = document.getElementById('coversStats');
            if (stats) {
                stats.innerHTML = `
                    <div class="stat">
                        <div class="stat-value">${total}</div>
                        <div class="stat-label">全部封面</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value uploaded">${uploaded}</div>
                        <div class="stat-label">已上传</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value pending">${pending}</div>
                        <div class="stat-label">待上传</div>
                    </div>
                `;
            }
        }

        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }
    </script>
</body>
</html>