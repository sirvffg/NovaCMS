<?php
session_start();
require_once 'config/database.php';
require_once 'config/functions.php';
require_once 'config/music_player.php';
require_once 'config/comment_functions.php';
require_once 'config/privacy_functions.php';
require_once 'config/paid_functions.php';
require_once 'config/ai_functions.php';

// 静态资源版本号，更新后修改此处即可全局生效
$blog_version = '20260630';

// =============================================
// 图片映射表类 - 内嵌版本，不依赖外部文件
// =============================================
class ImageMapper {
    private static $currentDir = null;
    private static $map = [];

    private static function init($localPath = '') {
        if (empty($localPath)) {
            self::$currentDir = __DIR__ . '/uploads';
        } else {
            $dir = dirname($localPath);
            $uploadsDir = __DIR__ . '/uploads';
            if (strpos($dir, $uploadsDir) === 0) {
                self::$currentDir = $dir;
            } else {
                self::$currentDir = $uploadsDir;
            }
        }
        self::load();
    }

    private static function getMapFilePath() {
        return self::$currentDir . '/.image_map.json';
    }

    private static function load() {
        $mapFile = self::getMapFilePath();
        if (file_exists($mapFile)) {
            $content = file_get_contents($mapFile);
            self::$map = json_decode($content, true) ?: [];
        } else {
            self::$map = [];
        }
    }

    private static function save() {
        $mapFile = self::getMapFilePath();
        if (!is_dir(self::$currentDir)) {
            mkdir(self::$currentDir, 0755, true);
        }
        file_put_contents($mapFile, json_encode(self::$map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private static function getKey($localPath) {
        $normalizedPath = str_replace('\\', '/', $localPath);
        return md5($normalizedPath);
    }

    private static function getKeyByUrl($url) {
        $uploadsDir = str_replace('\\', '/', __DIR__) . '/uploads';
        $localPath = $uploadsDir . $url;
        return self::getKey($localPath);
    }

    public static function add($localPath, $localUrl, $imageBedUrl = '', $filename = '', $imageBedId = 0) {
        $localPath = str_replace('\\', '/', $localPath);
        self::init($localPath);
        $key = self::getKey($localPath);
        self::$map[$key] = [
            'local_path' => $localPath,
            'local_url' => $localUrl,
            'image_bed_url' => $imageBedUrl,
            'image_bed_id' => $imageBedId,
            'filename' => $filename,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        self::save();
        return $key;
    }

    public static function updateImageBedUrl($localPath, $imageBedUrl, $imageBedId = 0) {
        $localPath = str_replace('\\', '/', $localPath);
        self::init($localPath);
        $key = self::getKey($localPath);
        if (isset(self::$map[$key])) {
            self::$map[$key]['image_bed_url'] = $imageBedUrl;
            if ($imageBedId > 0) {
                self::$map[$key]['image_bed_id'] = $imageBedId;
            }
            self::$map[$key]['updated_at'] = date('Y-m-d H:i:s');
            self::save();
            return true;
        }
        return false;
    }

    public static function get($localPathOrUrl) {
        $info = self::getByUrl($localPathOrUrl);
        if ($info) return $info;

        $urlToMatch = $localPathOrUrl;
        if (strpos($urlToMatch, '/uploads/') === false) {
            $uploadsDir = str_replace('\\', '/', __DIR__) . '/uploads';
            $uploadsDir = rtrim($uploadsDir, '/');
            if (strpos($urlToMatch, $uploadsDir) === 0) {
                $urlToMatch = '/uploads' . str_replace($uploadsDir, '', $urlToMatch);
            }
        }
        $urlToMatch = str_replace('\\', '/', $urlToMatch);
        
        $allMap = self::getAll();
        foreach ($allMap as $item) {
            if (!empty($item['local_url']) && $item['local_url'] === $urlToMatch) {
                return $item;
            }
        }
        return null;
    }

    public static function getByUrl($url) {
        $url = str_replace('\\', '/', $url);
        
        // 规范化 URL：统一处理有无 / 前缀的情况
        $normalizedUrl = ltrim($url, '/');
        
        $uploadsDir = str_replace('\\', '/', __DIR__) . '/uploads';
        $relativePath = ltrim(str_replace('/uploads/', '', $normalizedUrl), '/');
        $parts = explode('/', $relativePath);
        array_pop($parts);
        $subDir = implode('/', $parts);
        $targetDir = $uploadsDir;
        if (!empty($subDir)) {
            $targetDir = $uploadsDir . '/' . $subDir;
        }
        $targetDir = str_replace('\\', '/', $targetDir);
        
        // 修复：key 计算使用规范化后的 URL，避免路径重复
        $key = self::getKey($uploadsDir . '/' . $normalizedUrl);
        
        self::init($targetDir);
        if (isset(self::$map[$key])) {
            return self::$map[$key];
        }
        
        self::init($uploadsDir);
        if (isset(self::$map[$key])) {
            return self::$map[$key];
        }
        
        // 遍历全部映射，规范化比较
        $allMap = self::getAll();
        foreach ($allMap as $itemKey => $item) {
            if (!empty($item['local_url'])) {
                $itemNormalized = ltrim($item['local_url'], '/');
                if ($itemNormalized === $normalizedUrl) {
                    return $item;
                }
            }
        }
        return null;
    }

    public static function getFinalUrl($localUrl, $useImageBed = false) {
        $info = self::getByUrl($localUrl);
        if ($info && $useImageBed && !empty($info['image_bed_url'])) {
            return $info['image_bed_url'];
        }
        return $localUrl;
    }

    public static function convertContent($content, $useImageBed = false) {
        if (!$useImageBed) return $content;

        $content = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/', function($matches) use ($useImageBed) {
            $alt = $matches[1];
            $url = $matches[2];
            if (strpos($url, '/uploads/') !== false) {
                $info = self::getByUrl($url);
                if ($info && !empty($info['image_bed_url'])) {
                    return '<img src="' . $info['image_bed_url'] . '" alt="' . htmlspecialchars($alt) . '" data-local-url="' . htmlspecialchars($url) . '" loading="lazy" onerror="if(this.dataset.localUrl)this.src=this.dataset.localUrl;this.onerror=null;">';
                }
                return $matches[0];
            }
            return $matches[0];
        }, $content);

        $content = preg_replace_callback('/<img([^>]*?)src=["\']([^"\']+)["\']([^>]*)>/i', function($matches) use ($useImageBed) {
            $before = $matches[1];
            $url = $matches[2];
            $after = $matches[3];
            if (strpos($url, '/uploads/') !== false) {
                $info = self::getByUrl($url);
                if ($info && !empty($info['image_bed_url'])) {
                    if (stripos($before . $after, 'data-local-url') !== false) {
                        $newTag = preg_replace('/src=["\']([^"\']*)["\']/', 'src="' . $info['image_bed_url'] . '"', $matches[0]);
                        return $newTag;
                    }
                    return '<img' . $before . 'src="' . $info['image_bed_url'] . '" data-local-url="' . htmlspecialchars($url) . '" loading="lazy" onerror="if(this.dataset.localUrl)this.src=this.dataset.localUrl;this.onerror=null;"' . $after . '>';
                }
            }
            return $matches[0];
        }, $content);

        return $content;
    }

    public static function getPendingUploads() {
        $uploadsDir = __DIR__ . '/uploads';
        $pending = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $dir) {
            if ($dir->isDir()) {
                $mapFile = $dir->getPathname() . '/.image_map.json';
                if (file_exists($mapFile)) {
                    $content = file_get_contents($mapFile);
                    $map = json_decode($content, true) ?: [];
                    foreach ($map as $key => $item) {
                        if (empty($item['image_bed_url'])) {
                            $pending[$key] = $item;
                        }
                    }
                }
            }
        }
        return $pending;
    }

    public static function delete($localPathOrUrl) {
        $info = self::get($localPathOrUrl);
        if (!$info) return false;
        self::init($info['local_path']);
        $key = self::getKey($info['local_path']);
        if (isset(self::$map[$key])) {
            unset(self::$map[$key]);
            self::save();
            return true;
        }
        return false;
    }

    private static $allCache = null;

    public static function getAll() {
        if (self::$allCache !== null) {
            return self::$allCache;
        }
        $uploadsDir = __DIR__ . '/uploads';
        $all = [];
        if (is_dir($uploadsDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $dir) {
                if ($dir->isDir()) {
                    $mapFile = $dir->getPathname() . '/.image_map.json';
                    if (file_exists($mapFile)) {
                        $content = file_get_contents($mapFile);
                        $map = json_decode($content, true) ?: [];
                        $all = array_merge($all, $map);
                    }
                }
            }
        }
        self::$allCache = $all;
        return $all;
    }

    public static function getStats() {
        $uploadsDir = __DIR__ . '/uploads';
        $total = $withImageBed = $withoutImageBed = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $dir) {
            if ($dir->isDir()) {
                $mapFile = $dir->getPathname() . '/.image_map.json';
                if (file_exists($mapFile)) {
                    $content = file_get_contents($mapFile);
                    $map = json_decode($content, true) ?: [];
                    foreach ($map as $item) {
                        $total++;
                        if (!empty($item['image_bed_url'])) {
                            $withImageBed++;
                        } else {
                            $withoutImageBed++;
                        }
                    }
                }
            }
        }
        return ['total' => $total, 'with_image_bed' => $withImageBed, 'without_image_bed' => $withoutImageBed];
    }

    public static function batchUploadToImageBed($apiUrl, $apiKey, $progressCallback = null) {
        $pending = self::getPendingUploads();
        $total = count($pending);
        $current = $success = $failed = 0;
        foreach ($pending as $key => $item) {
            $current++;
            if ($progressCallback) $progressCallback($current, $total, $item['filename']);
            if (!file_exists($item['local_path'])) {
                $failed++;
                continue;
            }
            $result = self::uploadFileToImageBed($apiUrl, $apiKey, $item['local_path']);
            if ($result['success']) {
                self::updateImageBedUrl($item['local_path'], $result['url']);
                $success++;
            } else {
                $failed++;
            }
        }
        return ['total' => $total, 'success' => $success, 'failed' => $failed];
    }

    private static function uploadFileToImageBed($apiUrl, $apiKey, $filePath) {
        if (!file_exists($filePath)) return ['success' => false, 'error' => '文件不存在'];
        
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
            $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            $mimeType = $mimeMap[$ext] ?? 'application/octet-stream';
        }
        
        // 生成缩略图
        $thumbnailBase64 = self::generateThumbnail($filePath, $mimeType);
        $filename = basename($filePath);
        
        // 使用新API: /api/external/upload
        $baseUrl = rtrim($apiUrl, '/');
        $uploadUrl = $baseUrl . '/api/external/upload';
        
        $postData = [
            'file' => new CURLFile($filePath, $mimeType, $filename),
            'thumbnail' => $thumbnailBase64,
            'title' => '来自博客系统的上传'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) return ['success' => false, 'error' => $error];
        $data = json_decode($response, true);
        
        // 新API返回格式
        if ($httpCode === 200 && isset($data['success']) && $data['success'] === true && !empty($data['url'])) {
            return ['success' => true, 'url' => $data['url'], 'id' => $data['id'] ?? 0];
        }
        // 兼容旧格式
        if ($httpCode === 200 && isset($data['code']) && $data['code'] === 200 && !empty($data['data']['url'])) {
            return ['success' => true, 'url' => $data['data']['url'], 'id' => $data['data']['id'] ?? 0];
        }
        return ['success' => false, 'error' => $data['msg'] ?? $data['error'] ?? '上传失败'];
    }
    
    private static function generateThumbnail($filePath, $mimeType) {
        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            $image = null;
            switch ($mimeType) {
                case 'image/jpeg': $image = @imagecreatefromjpeg($filePath); break;
                case 'image/png': $image = @imagecreatefrompng($filePath); break;
                case 'image/gif': $image = @imagecreatefromgif($filePath); break;
                case 'image/webp': $image = @imagecreatefromwebp($filePath); break;
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
                if ($mimeType === 'image/png') {
                    imagealphablending($thumbnail, false);
                    imagesavealpha($thumbnail, true);
                }
                imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
                ob_start();
                imagejpeg($thumbnail, null, 60);
                $imageData = ob_get_clean();
                imagedestroy($image);
                imagedestroy($thumbnail);
                return 'data:image/jpeg;base64,' . base64_encode($imageData);
            }
        }
        return 'data:image/jpeg;base64,' . base64_encode(file_get_contents($filePath));
    }

    public static function scanLocalImages($progressCallback = null) {
        $uploadsDir = str_replace('\\', '/', __DIR__) . '/uploads';
        $existingMap = self::getAll();
        $existingUrls = [];
        foreach ($existingMap as $item) {
            if (!empty($item['local_url'])) {
                $existingUrls[$item['local_url']] = true;
            }
        }
        $scanned = $added = 0;
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $imageExtensions)) {
                    if ($file->getFilename() === '.image_map.json') continue;
                    $scanned++;
                    $localPath = str_replace('\\', '/', $file->getPathname());
                    $relativePath = str_replace($uploadsDir, '', $localPath);
                    $localUrl = '/uploads' . $relativePath;
                    $filename = $file->getFilename();
                    if (!isset($existingUrls[$localUrl])) {
                        if ($progressCallback) $progressCallback($filename, $scanned);
                        self::add($localPath, $localUrl, '', $filename);
                        $added++;
                    }
                }
            }
        }
        return ['scanned' => $scanned, 'added' => $added, 'existing' => count($existingUrls)];
    }

    public static function scanPostsImages($db, $progressCallback = null) {
        $existingMap = self::getAll();
        $existingUrls = [];
        foreach ($existingMap as $item) {
            if (!empty($item['local_url'])) {
                $existingUrls[$item['local_url']] = true;
            }
        }
        $found = $added = 0;
        $posts = $db->query("SELECT id, title, content FROM blog_posts")->fetchAll();
        foreach ($posts as $post) {
            preg_match_all('/!\[([^\]]*)\]\(([^)]+\.(?:jpg|jpeg|png|gif|webp))\)/i', $post['content'], $matches);
            foreach ($matches[2] as $url) {
                if (strpos($url, '/uploads/') !== false) {
                    $found++;
                    if (!isset($existingUrls[$url])) {
                        if ($progressCallback) $progressCallback($post['title'], $url);
                        $uploadsDir = str_replace('\\', '/', __DIR__) . '/uploads';
                        $localPath = $uploadsDir . str_replace('/uploads/', '/', $url);
                        $filename = basename($url);
                        self::add($localPath, $url, '', $filename);
                        $existingUrls[$url] = true;
                        $added++;
                    }
                }
            }
            preg_match_all('/<img[^>]+src=["\']([^"\']+\.(?:jpg|jpeg|png|gif|webp))["\'][^>]*>/i', $post['content'], $imgMatches);
            foreach ($imgMatches[1] as $url) {
                if (strpos($url, '/uploads/') !== false && !isset($existingUrls[$url])) {
                    $found++;
                    if ($progressCallback) $progressCallback($post['title'], $url);
                    $uploadsDir = str_replace('\\', '/', __DIR__) . '/uploads';
                    $localPath = $uploadsDir . str_replace('/uploads/', '/', $url);
                    $filename = basename($url);
                    self::add($localPath, $url, '', $filename);
                    $existingUrls[$url] = true;
                    $added++;
                }
            }
        }
        return ['found' => $found, 'added' => $added, 'total_posts' => count($posts)];
    }

    public static function deleteFromImageBed($apiUrl, $apiKey, $imageId) {
        if (empty($apiUrl) || empty($apiKey) || empty($imageId)) {
            return ['success' => false, 'error' => '缺少必要参数'];
        }
        $baseUrl = rtrim($apiUrl, '/');
        $url = $baseUrl . '/api/delete?key=' . urlencode($apiKey) . '&id=' . intval($imageId);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) return ['success' => false, 'error' => $error];
        $data = json_decode($response, true);
        if ($httpCode === 200 && isset($data['code']) && $data['code'] === 200) {
            return ['success' => true, 'message' => $data['msg'] ?? '删除成功'];
        }
        return ['success' => false, 'error' => $data['msg'] ?? '删除失败'];
    }

    public static function deleteWithFiles($localUrl, $apiUrl = '', $apiKey = '') {
        $result = ['local_deleted' => false, 'local_file_deleted' => false, 'image_bed_deleted' => false, 'errors' => []];
        $info = self::getByUrl($localUrl);
        if (!$info) {
            $result['errors'][] = '映射表中未找到该图片';
        } else {
            if (!empty($info['image_bed_url']) && !empty($info['image_bed_id']) && !empty($apiUrl) && !empty($apiKey)) {
                $bedResult = self::deleteFromImageBed($apiUrl, $apiKey, $info['image_bed_id']);
                if ($bedResult['success']) {
                    $result['image_bed_deleted'] = true;
                } else {
                    $result['errors'][] = '图床删除失败: ' . ($bedResult['error'] ?? '');
                }
            }
            $localPath = $info['local_path'] ?? '';
            if (!empty($localPath) && file_exists($localPath)) {
                if (unlink($localPath)) {
                    $result['local_file_deleted'] = true;
                } else {
                    $result['errors'][] = '本地文件删除失败';
                }
            }
            if (self::delete($localUrl)) {
                $result['local_deleted'] = true;
            }
        }
        return $result;
    }

    public static function extractImagesFromContent($content) {
        $images = [];
        preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $content, $matches);
        foreach ($matches[2] as $url) {
            if (strpos($url, '/uploads/') !== false) {
                $images[] = $url;
            }
        }
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $imgMatches);
        foreach ($imgMatches[1] as $url) {
            if (strpos($url, '/uploads/') !== false && !in_array($url, $images)) {
                $images[] = $url;
            }
        }
        return array_unique($images);
    }

    public static function deleteImagesFromContent($content, $apiUrl = '', $apiKey = '') {
        $images = self::extractImagesFromContent($content);
        $results = ['total' => count($images), 'deleted' => 0, 'failed' => 0, 'details' => []];
        foreach ($images as $url) {
            $result = self::deleteWithFiles($url, $apiUrl, $apiKey);
            if ($result['local_deleted'] || $result['local_file_deleted']) {
                $results['deleted']++;
            } else {
                $results['failed']++;
            }
            $results['details'][$url] = $result;
        }
        return $results;
    }

    public static function checkImageUsage($localUrl, $db, $excludePostId = null) {
        $sql = "SELECT COUNT(*) as cnt FROM blog_posts WHERE content LIKE ?";
        $params = ['%' . $localUrl . '%'];
        if ($excludePostId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludePostId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['cnt'] ?? 0;
    }
}

$db = getDB();
aiEnsureSchema($db);

$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 初始化图床调试变量（默认空数组，避免列表页报错）
$imageBedDebug = [];
$imageBedEnabled = !empty($config['image_bed_display_enabled']);

// 列表页封面图床调试数据
$listPageCoversDebug = [];

// 从数据库查询当前用户是否为管理员
$isCurrentUserAdmin = false;
$currentUserId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
if ($currentUserId) {
    $stmt = $db->prepare("SELECT role FROM admins WHERE id = ?");
    $stmt->execute([$currentUserId]);
    $adminRow = $stmt->fetch();
    if ($adminRow && $adminRow['role'] === 'admin') {
        $isCurrentUserAdmin = true;
    }
}

// 处理评论提交和删除
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 处理退出登录
    if (isset($_POST['action']) && $_POST['action'] === 'logout') {
        if (isset($_SESSION['user_id'])) {
            logoutCurrentDevice($_SESSION['user_id']);
        }
        session_destroy();
        setcookie('device_token', '', time() - 3600, '/');
        setcookie('nova_token', '', time() - 3600, '/');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'delete') {
            // 删除评论 - 需要登录
            if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
                echo json_encode(['success' => false, 'message' => '请先登录']);
                exit;
            }
            if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
                echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面后重试']);
                exit;
            }
            $comment_id = $_POST['comment_id'] ?? 0;
            $result = deleteComment($comment_id);
            echo json_encode($result);
            exit;
        } elseif ($_POST['action'] === 'submitPrivacyAnswer') {
            // 提交隐私答案
            $post_id = $_POST['post_id'] ?? 0;
            $answer = $_POST['answer'] ?? '';
            
            if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
                echo json_encode(['success' => false, 'message' => '请先登录']);
                exit;
            }
            if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
                echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面后重试']);
                exit;
            }
            
            $userId = isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : $_SESSION['user_id'];
            $result = validatePrivacyAnswer($db, $userId, $post_id, $answer);
            echo json_encode($result);
            exit;
        }
    } else {
        // 添加评论
        if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
            echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面后重试']);
            exit;
        }
        $post_id = $_POST['post_id'] ?? 0;
        $content = trim($_POST['content'] ?? '');
        $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
        
        if (empty($content)) {
            echo json_encode(['success' => false, 'message' => '评论内容不能为空']);
            exit;
        }
        
        $result = addComment($post_id, $content, $parent_id);
        echo json_encode($result);
        exit;
    }
}

// 获取所有分类颜色
$categories = $db->query("SELECT name, color FROM blog_categories ORDER BY sort_order ASC, name ASC")->fetchAll();
$categoryColors = [];
foreach ($categories as $cat) {
    $categoryColors[$cat['name']] = $cat['color'] ?? '#007bff';
}

// 获取友情链接
$friendLinks = $db->query("SELECT name, url, logo, description FROM friend_links WHERE is_active=1 ORDER BY sort_order ASC, id DESC")->fetchAll();

// 辅助函数：调整颜色亮度
function adjustBrightness($hex, $percent) {
    $hex = str_replace('#', '', $hex);

    if (strlen($hex) == 3) {
        $hex = str_repeat(substr($hex,0,1), 2).str_repeat(substr($hex,1,1), 2).str_repeat(substr($hex,2,1), 2);
    }

    $r = hexdec(substr($hex,0,2));
    $g = hexdec(substr($hex,2,2));
    $b = hexdec(substr($hex,4,2));

    $r = max(0, min(255, $r + $percent));
    $g = max(0, min(255, $g + $percent));
    $b = max(0, min(255, $b + $percent));

    return '#'.str_pad(dechex($r), 2, '0', STR_PAD_LEFT)
               .str_pad(dechex($g), 2, '0', STR_PAD_LEFT)
               .str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
}

// 辅助函数：获取许可协议说明
function getLicenseDescription($license) {
    $descriptions = [
        'CC BY 4.0' => '允许他人自由共享、修改作品，但必须注明原作者姓名及来源',
        'CC BY-NC 4.0' => '允许他人非商业性使用、修改作品，必须注明原作者及来源，不得用于商业目的',
        'CC BY-SA 4.0' => '允许他人自由共享、修改作品，必须注明原作者，且衍生作品须采用相同许可协议',
        'CC BY-NC-SA 4.0' => '允许他人非商业性使用、修改作品，必须注明原作者，且衍生作品须采用相同许可协议，不得用于商业目的',
        'CC BY-ND 4.0' => '允许他人自由共享作品，但必须注明原作者及来源，不得对作品进行任何修改或衍生',
        'CC BY-NC-ND 4.0' => '允许他人非商业性共享作品，必须注明原作者及来源，不得修改、衍生或用于商业目的',
        'MIT' => '最宽松的开源许可，允许任何人以任何目的使用、复制、修改、合并、出版发行、散布、再授权及贩售软件的副本',
        'Apache-2.0' => '允许自由使用、修改和分发，要求保留版权声明和许可声明，提供专利授权，适用于大型商业项目',
        'GPL-3.0' => '强传染性开源协议，要求衍生作品也必须采用GPL协议，修改后的源码必须公开',
        'LGPL-3.0' => '较宽松的GPL，允许链接到库而不使整个程序受GPL约束，适用于库和组件',
        'BSD-3-Clause' => '宽松开源协议，允许使用、修改和分发，只需保留版权声明和免责条款，没有传染性',
        'ODbL' => '开放数据库许可，要求共享-相同方式，适用于数据库内容，如OpenStreetMap',
        'CC0 1.0' => '放弃所有版权，将作品完全置于公有领域，允许任何人以任何方式使用，无需署名',
        'PLOS' => 'PLOS期刊的开放获取许可，基于CC BY，允许自由使用、分发和改编，必须注明来源',
        'ArXiv' => 'arXiv预印本平台的许可协议，通常基于CC协议，促进学术成果的快速传播',
        'OGL' => '开放游戏许可，允许使用、修改和分发游戏内容，适用于桌面角色扮演游戏规则',
        'GFDL' => 'GNU自由文档许可，要求复制和修改时保留许可声明，适用于维基百科等文档',
        '无协议' => '保留所有版权，未经授权不得使用、复制、修改或分发'
    ];

    return $descriptions[$license] ?? '请选择一个许可协议';
}

// AJAX请求处理
if (isset($_GET['ajax'])) {
    if (isset($_GET['search'])) {
        // 搜索功能
        $searchTerm = trim($_GET['search'] ?? '');
        $tagOnly = isset($_GET['tag_only']) && $_GET['tag_only'] === '1';
        
        if (!empty($searchTerm)) {
            $listFields = "id, title, is_pinned, is_featured, author, created_at, views, category, tags, cover_image, SUBSTRING(content, 1, 200) AS content";
            if ($tagOnly) {
                // 只搜索标签字段
                $stmt = $db->prepare("
                    SELECT {$listFields} FROM blog_posts 
                    WHERE tags LIKE ? AND is_published = 1
                    ORDER BY is_pinned DESC, created_at DESC
                ");
                $searchPattern = '%' . $searchTerm . '%';
                $stmt->execute([$searchPattern]);
                $posts = $stmt->fetchAll();
            } else {
                // 搜索标题、内容、作者、分类、标签
                $stmt = $db->prepare("
                    SELECT {$listFields} FROM blog_posts 
                    WHERE (title LIKE ? 
                       OR content LIKE ? 
                       OR author LIKE ? 
                       OR category LIKE ? 
                       OR tags LIKE ?)
                       AND is_published = 1
                    ORDER BY is_pinned DESC, created_at DESC
                ");
                $searchPattern = '%' . $searchTerm . '%';
                $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern]);
                $posts = $stmt->fetchAll();
            }
        } else {
            // 空搜索返回所有文章
            $posts = $db->query("SELECT id, title, is_pinned, is_featured, author, created_at, views, category, tags, cover_image, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE is_published = 1 ORDER BY is_pinned DESC, created_at DESC")->fetchAll();
        }
        
        // 返回HTML内容
        if (empty($posts)) {
            if (!empty($searchTerm)) {
                echo '<div class="text-center py-5">
                        <i class="bi bi-search" style="font-size: 4rem; color: #6c757d;"></i>
                        <h4 class="mt-3">未找到相关文章</h4>
                        <p class="text-muted">没有找到包含"' . htmlspecialchars($searchTerm) . '"的文章。</p>
                        <div class="mt-3">
                            <p class="small text-muted">搜索建议：</p>
                            <ul class="list-unstyled small text-muted">
                                <li>• 检查搜索词是否正确</li>
                                <li>• 尝试使用更简单的关键词</li>
                                <li>• 尝试搜索文章标题或作者名</li>
                            </ul>
                        </div>
                      </div>';
            } else {
                echo '<div class="text-center py-5">
                        <i class="bi bi-file-text" style="font-size: 4rem; color: #6c757d;"></i>
                        <h4 class="mt-3">暂无文章</h4>
                        <p class="text-muted">该分类下还没有文章。</p>
                      </div>';
            }
        } else {
            echo '<div class="row g-4">';
            foreach ($posts as $post):
                $categoryColor = isset($categoryColors[$post['category']]) ? $categoryColors[$post['category']] : '#007bff';

                // 检测搜索关键词出现的字段
                $matchSources = [];
                if (!empty($searchTerm)) {
                    if (stripos($post['title'], $searchTerm) !== false) {
                        $matchSources[] = '标题';
                    }
                    if (stripos($post['content'], $searchTerm) !== false) {
                        $matchSources[] = '文章';
                    }
                }
            ?>
            <div class="col-md-12">
                <div class="card blog-list-card" onclick="window.location.href='/blog.php?id=<?= $post['id'] ?>'">
                    <div class="row g-0">
                        <div class="col-md-8 col-12">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-1 title-container">
                                    <h2 class="h5 mb-0 fw-bold w-100">
                                        <?php if ($post['is_pinned']): ?><i class="bi bi-pin-angle-fill text-danger" title="置顶"></i><?php endif; ?>
                                        <?php if ($post['is_featured']): ?><i class="bi bi-star-fill text-warning" title="精选"></i><?php endif; ?>
                                        <a href="/blog.php?id=<?= $post['id'] ?>" class="text-decoration-none text-dark">
                                            <?= e($post['title']) ?>
                                        </a>
                                    </h2>
                                    <?php if (!empty($matchSources)): ?>
                                    <span class="badge bg-danger" style="font-size: 11px;">
                                        <i class="bi bi-search"></i> 来自<?= implode('与', $matchSources) ?>中
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-muted small mb-1">
                                    <i class="bi bi-person"></i> <?= e($post['author']) ?> |
                                    <?= date('Y-m-d H:i', strtotime($post['created_at'])) ?> |
                                    <i class="bi bi-eye"></i> <?= $post['views'] ?>
                                </p>
                                <?php if ($post['category'] || $post['tags']): ?>
                                <div class="mb-1 tags-container">
                                    <?php if ($post['category']): ?>
                                    <span class="badge category-badge" style="background-color: <?= $categoryColor ?>">
                                        <span class="color-dot" style="background-color: <?= adjustBrightness($categoryColor, 30) ?>"></span>
                                        <i class="bi bi-folder"></i> <?= e($post['category']) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($post['tags']): ?>
                                    <?php $tags = explode(',', $post['tags']); foreach ($tags as $tag): ?>
                                    <?php if (trim($tag)): 
                                        $tagColor = isset($categoryColors[trim($tag)]) ? $categoryColors[trim($tag)] : '#6c757d';
                                    ?>
                                    <span class="badge tag-badge" style="background-color: <?= $tagColor ?>; color: white;">
                                        <span class="tag-color-dot" style="background-color: <?= adjustBrightness($tagColor, 30) ?>"></span>
                                        <i class="bi bi-tag"></i> <?= e(trim($tag)) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php
                                $previewContent = $post['content'];
                                // 简单的 Markdown 去除逻辑
                                $previewContent = preg_replace('/!\[.*?\]\(.*?\)/', '', $previewContent); // 图片
                                $previewContent = preg_replace('/\[(.*?)\]\(.*?\)/', '$1', $previewContent); // 链接
                                $previewContent = preg_replace('/#+ /', '', $previewContent); // 标题
                                $previewContent = preg_replace('/(\*\*|__)(.*?)\1/', '$2', $previewContent); // 粗体
                                $previewContent = preg_replace('/(\*|_)(.*?)\1/', '$2', $previewContent); // 斜体
                                $previewContent = str_replace(['```', '`', '>'], '', $previewContent); // 代码和引用
                                $previewContent = strip_tags($previewContent); // HTML 标签
                                $previewContent = trim(preg_replace('/\s+/', ' ', $previewContent)); // 多余空白
                                $previewContent = mb_substr($previewContent, 0, 50, 'UTF-8') . '...';
                                ?>
                                <p class="content-preview mb-2"><?= htmlspecialchars($previewContent) ?></p>
                            </div>
                        </div>
                        <div class="col-md-4 d-none d-md-block">
                            <div class="d-flex h-100 align-items-center justify-content-center p-2">
                                <?php 
                                $coverUrl = !empty($post['cover_image']) ? ImageMapper::getFinalUrl($post['cover_image'], $imageBedEnabled) : '';
                                if (!empty($coverUrl)): ?>
                                    <img src="<?= e($coverUrl) ?>" class="img-fluid rounded shadow-sm" alt="<?= e($post['title']) ?>" width="300" height="120" style="max-height: 120px; object-fit: contain;" data-local-url="<?= e($post['cover_image']) ?>" loading="lazy">
                                <?php else: ?>
                                    <picture>
                                        <source srcset="https://t.alcy.cc/ai?<?= rand() ?>&format=webp" type="image/webp">
                                        <img src="https://t.alcy.cc/ai?<?= rand() ?>&format=jpg" class="img-fluid rounded shadow-sm" alt="<?= e($post['title']) ?> (随机封面)" width="300" height="120" style="max-height: 120px; object-fit: contain;">
                                    </picture>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; 
            echo '</div>';
        }
        exit;
    } elseif (isset($_GET['category'])) {
        $selectedCategory = $_GET['category'] ?? '';
        
        // 根据分类筛选文章
        if ($selectedCategory) {
            if ($selectedCategory === '无分类') {
                // 显示没有分类的文章
                $posts = $db->query("SELECT id, title, is_pinned, is_featured, author, created_at, views, category, tags, cover_image, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE (category IS NULL OR category = '') AND is_published = 1 ORDER BY is_pinned DESC, created_at DESC")->fetchAll();
            } else {
                // 显示特定分类的文章
                $stmt = $db->prepare("SELECT id, title, is_pinned, is_featured, author, created_at, views, category, tags, cover_image, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE category = ? AND is_published = 1 ORDER BY is_pinned DESC, created_at DESC");
                $stmt->execute([$selectedCategory]);
                $posts = $stmt->fetchAll();
            }
        } else {
            // 显示所有文章
            $posts = $db->query("SELECT id, title, is_pinned, is_featured, author, created_at, views, category, tags, cover_image, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE is_published = 1 ORDER BY is_pinned DESC, created_at DESC")->fetchAll();
        }
        
        // 返回HTML内容
        if (empty($posts)) {
            if (!empty($searchTerm)) {
                echo '<div class="text-center py-5">
                        <i class="bi bi-search" style="font-size: 4rem; color: #6c757d;"></i>
                        <h4 class="mt-3">未找到相关文章</h4>
                        <p class="text-muted">没有找到包含"' . htmlspecialchars($searchTerm) . '"的文章。</p>
                        <div class="mt-3">
                            <p class="small text-muted">搜索建议：</p>
                            <ul class="list-unstyled small text-muted">
                                <li>• 检查搜索词是否正确</li>
                                <li>• 尝试使用更简单的关键词</li>
                                <li>• 尝试搜索文章标题或作者名</li>
                            </ul>
                        </div>
                      </div>';
            } else {
                echo '<div class="text-center py-5">
                        <i class="bi bi-file-text" style="font-size: 4rem; color: #6c757d;"></i>
                        <h4 class="mt-3">暂无文章</h4>
                        <p class="text-muted">该分类下还没有文章。</p>
                      </div>';
            }
        } else {
            echo '<div class="row g-4">';
            foreach ($posts as $post):
                $categoryColor = isset($categoryColors[$post['category']]) ? $categoryColors[$post['category']] : '#007bff';

                // 检测搜索关键词出现的字段
                $matchSources = [];
                if (!empty($searchTerm)) {
                    if (stripos($post['title'], $searchTerm) !== false) {
                        $matchSources[] = '标题';
                    }
                    if (stripos($post['content'], $searchTerm) !== false) {
                        $matchSources[] = '文章';
                    }
                }
            ?>
            <div class="col-md-12">
                <div class="card blog-list-card" onclick="window.location.href='/blog.php?id=<?= $post['id'] ?>'">
                    <div class="row g-0">
                        <div class="col-md-8 col-12">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-1 title-container">
                                    <h2 class="h5 mb-0 fw-bold w-100">
                                        <?php if ($post['is_pinned']): ?><i class="bi bi-pin-angle-fill text-danger" title="置顶"></i><?php endif; ?>
                                        <?php if ($post['is_featured']): ?><i class="bi bi-star-fill text-warning" title="精选"></i><?php endif; ?>
                                        <a href="/blog.php?id=<?= $post['id'] ?>" class="text-decoration-none text-dark">
                                            <?= e($post['title']) ?>
                                        </a>
                                    </h2>
                                    <?php if (!empty($matchSources)): ?>
                                    <span class="badge bg-danger" style="font-size: 11px;">
                                        <i class="bi bi-search"></i> 来自<?= implode('与', $matchSources) ?>中
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-muted small mb-1">
                                    <i class="bi bi-person"></i> <?= e($post['author']) ?> |
                                    <?= date('Y-m-d H:i', strtotime($post['created_at'])) ?> |
                                    <i class="bi bi-eye"></i> <?= $post['views'] ?>
                                </p>
                                <?php if ($post['category'] || $post['tags']): ?>
                                <div class="mb-1 tags-container">
                                    <?php if ($post['category']): ?>
                                    <span class="badge category-badge" style="background-color: <?= $categoryColor ?>">
                                        <span class="color-dot" style="background-color: <?= adjustBrightness($categoryColor, 30) ?>"></span>
                                        <i class="bi bi-folder"></i> <?= e($post['category']) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($post['tags']): ?>
                                    <?php $tags = explode(',', $post['tags']); foreach ($tags as $tag): ?>
                                    <?php if (trim($tag)): 
                                        $tagColor = isset($categoryColors[trim($tag)]) ? $categoryColors[trim($tag)] : '#6c757d';
                                    ?>
                                    <span class="badge tag-badge" style="background-color: <?= $tagColor ?>; color: white;">
                                        <span class="tag-color-dot" style="background-color: <?= adjustBrightness($tagColor, 30) ?>"></span>
                                        <i class="bi bi-tag"></i> <?= e(trim($tag)) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php
                                $previewContent = $post['content'];
                                // 简单的 Markdown 去除逻辑
                                $previewContent = preg_replace('/!\[.*?\]\(.*?\)/', '', $previewContent); // 图片
                                $previewContent = preg_replace('/\[(.*?)\]\(.*?\)/', '$1', $previewContent); // 链接
                                $previewContent = preg_replace('/#+ /', '', $previewContent); // 标题
                                $previewContent = preg_replace('/(\*\*|__)(.*?)\1/', '$2', $previewContent); // 粗体
                                $previewContent = preg_replace('/(\*|_)(.*?)\1/', '$2', $previewContent); // 斜体
                                $previewContent = str_replace(['```', '`', '>'], '', $previewContent); // 代码和引用
                                $previewContent = strip_tags($previewContent); // HTML 标签
                                $previewContent = trim(preg_replace('/\s+/', ' ', $previewContent)); // 多余空白
                                $previewContent = mb_substr($previewContent, 0, 50, 'UTF-8') . '...';
                                ?>
                                <p class="content-preview mb-2"><?= htmlspecialchars($previewContent) ?></p>
                            </div>
                        </div>
                        <div class="col-md-4 d-none d-md-block">
                            <div class="d-flex h-100 align-items-center justify-content-center p-2">
                                <?php 
                                $coverUrl = !empty($post['cover_image']) ? ImageMapper::getFinalUrl($post['cover_image'], $imageBedEnabled) : '';
                                if (!empty($coverUrl)): ?>
                                    <img src="<?= e($coverUrl) ?>" class="img-fluid rounded shadow-sm" alt="<?= e($post['title']) ?>" width="300" height="120" style="max-height: 120px; object-fit: contain;" data-local-url="<?= e($post['cover_image']) ?>" loading="lazy">
                                <?php else: ?>
                                    <picture>
                                        <source srcset="https://t.alcy.cc/ai?<?= rand() ?>&format=webp" type="image/webp">
                                        <img src="https://t.alcy.cc/ai?<?= rand() ?>&format=jpg" class="img-fluid rounded shadow-sm" alt="<?= e($post['title']) ?> (随机封面)" width="300" height="120" style="max-height: 120px; object-fit: contain;">
                                    </picture>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; 
            echo '</div>';
        }
        exit;
    }
}

// 单篇文章
if (isset($_GET['id'])) {
    recordVisit('/blog.php?id=' . $_GET['id']);
    
    $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $post = $stmt->fetch();
    
    if (!$post) {
        die('文章不存在');
    }

    // 检查草稿状态
    if (!$post['is_published'] && !isset($_SESSION['admin_id'])) {
        die('文章不存在');
    }
    
    // 增加浏览量
    $db->prepare("UPDATE blog_posts SET views = views + 1 WHERE id = ?")->execute([$_GET['id']]);
    
    // 获取文章分类颜色
    $categoryColor = isset($categoryColors[$post['category']]) ? $categoryColors[$post['category']] : '#007bff';
    
    // 处理隐私内容
    $userId = isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0);
    $processedContent = processBlogContent($db, $userId, $post['id'], $post['content']);

    // 处理付费内容
    $processedContent = processPaidContent($db, $userId, $post['id'], $processedContent);

    // 如果启用了图床显示，转换图片URL
    $imageBedDebug = [];
    $imageBedEnabled = !empty($config['image_bed_display_enabled']);
    if ($imageBedEnabled) {
        // 提取所有图片URL用于调试
        $allImages = ImageMapper::extractImagesFromContent($processedContent);
        
        foreach ($allImages as $imgUrl) {
            $imgInfo = ImageMapper::getByUrl($imgUrl);
            $hasImageBed = $imgInfo && !empty($imgInfo['image_bed_url']);
            
            $imageBedDebug[] = [
                'local_url' => $imgUrl,
                'image_bed_url' => $imgInfo['image_bed_url'] ?? null,
                'has_image_bed' => $hasImageBed,
                'filename' => $imgInfo['filename'] ?? basename($imgUrl)
            ];
        }
        $processedContent = ImageMapper::convertContent($processedContent, true);
    }
    
    // 获取隐私设置（如果有）
    $privacySettings = null;
    if ($post['has_privacy_content'] == 1) {
        $privacySettings = getPrivacySettings($db, $post['id']);
    }
    
    // 检查用户是否已经提交过答案等待审核
    $isPendingApproval = false;
    if ($privacySettings && $userId > 0) {
        // 查询用户是否已经提交过答案
        $stmt = $db->prepare("SELECT id, access_granted FROM blog_privacy_access 
                             WHERE user_id = ? AND post_id = ? 
                             ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$userId, $post['id']]);
        $existingAccess = $stmt->fetch();
        
        // 如果用户已经提交过答案，且未获得访问权限
        if ($existingAccess && $existingAccess['access_granted'] == 0) {
            // 判断是否等待审核
            $isManualApproval = $privacySettings['type'] == 'manual_approval';
            $isOpenAnswerWithApproval = $privacySettings['type'] == 'open_answer' && $privacySettings['approval_required'] == 1;
            
            if ($isManualApproval || $isOpenAnswerWithApproval) {
                $isPendingApproval = true;
            }
        }
    }

    $showAiSummary = false;
    $aiSummaryStale = false;
    if (!empty($post['ai_summary'])) {
        $aiSettingsRow = aiGetSiteAiSettings($db);
        if (!empty($aiSettingsRow['ai_feature_enabled'])) {
            $showAiSummary = true;
            $aiSummaryStale = aiSummaryHashStale($db, $post);
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <?php if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'): ?>
        <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        <?php endif; ?>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= e($post['title']) ?> - <?= e($config['website_name']) ?></title>
    
    <meta name="description" content="<?= e(mb_substr(strip_tags($post['content']), 0, 160)) ?>">
    <meta name="keywords" content="<?= e($post['title']) ?>,<?= e($post['author']) ?>,<?= e($config['website_name']) ?>,博客">
    <meta name="author" content="<?= e($post['author']) ?>">
    
    <!-- Open Graph / Facebook -->
    <?php 
    $postCoverUrl = !empty($post['cover_image']) ? ImageMapper::getFinalUrl($post['cover_image'], $imageBedEnabled) : '';
    $postCoverFullUrl = !empty($postCoverUrl) ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $postCoverUrl : '';
    ?>
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= e($post['title']) ?>">
    <meta property="og:description" content="<?= e(mb_substr(strip_tags($post['content']), 0, 160)) ?>">
    <meta property="og:url" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/blog.php?id=' . $post['id'] ?>">
    <meta property="og:site_name" content="<?= e($config['website_name']) ?>">
    <?php if (!empty($postCoverFullUrl)): ?>
    <meta property="og:image" content="<?= e($postCoverFullUrl) ?>">
    <?php endif; ?>
    <meta property="article:published_time" content="<?= date('c', strtotime($post['created_at'])) ?>">
    <meta property="article:author" content="<?= e($post['author']) ?>">
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($post['title']) ?>">
    <meta name="twitter:description" content="<?= e(mb_substr(strip_tags($post['content']), 0, 160)) ?>">
    <?php if (!empty($postCoverFullUrl)): ?>
    <meta name="twitter:image" content="<?= e($postCoverFullUrl) ?>">
    <?php endif; ?>
    
    <link rel="canonical" href="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/blog.php?id=' . $post['id'] ?>">
    
    <!-- 图标设置 (覆盖全平台) -->
    <?php if (!empty($config['favicon'])): ?>
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <link rel="icon" href="<?= e($config['favicon']) ?>" type="image/x-icon">
    <link rel="apple-touch-icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>

    <!-- JSON-LD Structured Data -->
    <script type="application/ld+json">
    [
    {
      "@context": "https://schema.org",
      "@type": "BlogPosting",
      "headline": "<?= e($post['title']) ?>",
      <?php if (!empty($postCoverFullUrl)): ?>
      "image": "<?= e($postCoverFullUrl) ?>",
      <?php endif; ?>
      "datePublished": "<?= date('c', strtotime($post['created_at'])) ?>",
      "dateModified": "<?= date('c', strtotime($post['created_at'])) ?>",
      "author": {
        "@type": "Person",
        "name": "<?= e($post['author']) ?>"
      },
      "publisher": {
        "@type": "Organization",
        "name": "<?= e($config['website_name']) ?>",
        "logo": {
          "@type": "ImageObject",
          "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['logo'] ?? '') ?>"
        }
      },
      "description": "<?= e(mb_substr(strip_tags($post['content']), 0, 160)) ?>"
    },
    {
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      "itemListElement": [{
        "@type": "ListItem",
        "position": 1,
        "name": "首页",
        "item": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' ?>"
      },{
        "@type": "ListItem",
        "position": 2,
        "name": "博客",
        "item": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/blog.php' ?>"
      }
      <?php if (!empty($post['category'])): ?>
      ,{
        "@type": "ListItem",
        "position": 3,
        "name": "<?= e($post['category']) ?>",
        "item": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/blog.php?category=' . urlencode($post['category']) ?>"
      }
      <?php endif; ?>
      ]
    }
    ]
    </script>
        
        <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
        <link href="<?= getResourceUrl('/assets/css/bootstrap-icons.css', 'https://cdn.staticfile.net/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
        <link href="/assets/css/blog/blog-common.css?v=<?= $blog_version ?>" rel="stylesheet">
        <link href="/assets/css/blog/blog-article.css?v=<?= $blog_version ?>" rel="stylesheet">
        
        <?php if (!empty($config['favicon'])): ?>
        <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
        <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
        <?php endif; ?>
        
        <?php if (!empty($post['data_music_enabled']) && $post['data_music_enabled'] === 'true'): ?>
        <!-- 音乐播放器 CSS -->
        <link rel="stylesheet" href="<?= getMusicPlayerUrl('css') ?>">
        <?php endif; ?>

    </head>
    <body>
        <!-- 加载动画 -->
        <div id="page-loader">
            <div class="loader-overlay"></div>
            <div class="loader-content">
                <div class="loader-spinner"></div>
                <div class="loader-text">正在加载文章...</div>
                <div class="loader-subtext">请稍候</div>
            </div>
        </div>

        <div class="page-wrapper">
        <nav class="navbar navbar-expand-lg navbar-light fixed-top">
            <div class="container">
                <!-- 使用响应式文本，不同断点显示不同内容 -->
                <a class="navbar-brand" href="/blog.php">
                    <span class="d-none d-lg-inline">博客文章 | <?= e($config['website_name']) ?></span>
                    <span class="d-lg-none">博客文章</span>
                </a>

                <div class="ms-auto d-flex align-items-center">
                    <a class="btn btn-outline-secondary btn-sm me-2" href="/blog.php">返回列表</a>
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="dropdown">
                        <a class="btn btn-link text-decoration-none dropdown-toggle text-dark btn-sm d-flex align-items-center" href="#" id="userDropdownPost" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle me-1"></i> <?= htmlspecialchars($_SESSION['user_username'] ?? 'User') ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdownPost">
                            <li><span class="dropdown-item-text">
                                <?php if (($_SESSION['user_role'] ?? 'user') === 'admin'): ?>
                                <span class="badge bg-danger">管理员</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">普通用户</span>
                                <?php endif; ?>
                            </span></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if (($_SESSION['user_role'] ?? 'user') === 'admin'): ?>
                            <li><a class="dropdown-item" href="/admin"><i class="bi bi-gear"></i> 管理后台</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="/vendor/profile.php"><i class="bi bi-person"></i> 个人中心</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="logout">
                                    <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right"></i> 退出登录</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                    <?php else: ?>
                        <a href="/vendor/login.php?redirect_url=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-outline-secondary btn-sm me-2">登录</a>
                        <a href="/vendor/register.php?redirect_url=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-primary btn-sm" style="background: #6c757d !important;">注册</a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
        
        <div class="container my-5 main-content">

            <article class="blog-post" itemscope itemtype="http://schema.org/BlogPosting">
                <meta itemprop="mainEntityOfPage" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/blog.php?id=' . $post['id'] ?>">
                <h1 class="mb-3" itemprop="headline"><?= e($post['title']) ?></h1>
                <div class="text-muted mb-3">
                    <span itemprop="author" itemscope itemtype="http://schema.org/Person">
                        <i class="bi bi-person"></i> <span itemprop="name"><?= e($post['author']) ?></span>
                    </span> | 
                    <span itemprop="datePublished" content="<?= date('c', strtotime($post['created_at'])) ?>">
                        <i class="bi bi-calendar"></i> <?= date('Y-m-d H:i', strtotime($post['created_at'])) ?>
                    </span>
                    <meta itemprop="dateModified" content="<?= date('c', strtotime($post['updated_at'] ?? $post['created_at'])) ?>"> | 
                    <span><i class="bi bi-eye"></i> <?= $post['views'] + 1 ?> 次浏览</span> | 
                    <link href="/assets/css/blog/share-buttons.css?v=<?= $blog_version ?>" rel="stylesheet">
                    <div class="d-inline-block dropdown share-dropdown">
                        <a href="#" class="text-muted text-decoration-none share-btn" id="shareDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="分享文章">
                            <i class="bi bi-share"></i> 分享
                        </a>
                        <ul class="dropdown-menu shadow border-0" aria-labelledby="shareDropdown">
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="shareToWechat()"><i class="bi bi-wechat text-success me-2"></i>微信</a></li>
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="shareToWeibo()"><i class="bi bi-sina-weibo text-danger me-2"></i>微博</a></li>
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="shareToQQ()"><i class="bi bi-tencent-qq text-primary me-2"></i>QQ</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="copyPageLink(this)"><i class="bi bi-link-45deg me-2"></i>复制链接</a></li>
                        </ul>
                    </div>
                </div>
                
                <?php if ($post['category'] || $post['tags']): ?>
                <div class="mb-4">
                    <?php if ($post['category']): ?>
                    <span class="badge category-badge" style="background-color: <?= $categoryColor ?>">
                        <span class="color-dot" style="background-color: <?= adjustBrightness($categoryColor, 30) ?>"></span>
                        <i class="bi bi-folder"></i> <?= e($post['category']) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($post['tags']): ?>
                    <?php $tags = explode(',', $post['tags']); foreach ($tags as $tag): ?>
                    <?php if (trim($tag)): 
                        $tagColor = isset($categoryColors[trim($tag)]) ? $categoryColors[trim($tag)] : '#6c757d';
                    ?>
                    <span class="badge bg-secondary tag-badge" style="background-color: <?= $tagColor ?>; color: white;">
                        <span class="tag-color-dot" style="background-color: <?= adjustBrightness($tagColor, 30) ?>"></span>
                        <i class="bi bi-tag"></i> <?= e(trim($tag)) ?>
                    </span>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($showAiSummary): ?>
                <div class="ai-article-summary card border-0 shadow-sm mb-2" style="border-left: 4px solid #6366f1 !important; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);">
                    <div class="card-body py-1 px-3">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <i class="bi bi-stars text-primary"></i>
                            <strong class="text-dark"><?= e($config['ai_summary_section_title'] ?? '文章摘要') ?></strong>
                            <?php if ($aiSummaryStale): ?>
                            <span class="badge bg-warning text-dark">正文已变更，摘要可能未同步</span>
                            <?php endif; ?>
                        </div>
                        <p id="ai-typing-text" class="mb-0 text-secondary typing-text" style="line-height: 1.4;" data-text="<?= htmlspecialchars($post['ai_summary'], ENT_QUOTES, 'UTF-8') ?>"></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="post-content" id="post-content" data-markdown="<?= htmlspecialchars($processedContent) ?>">
                    <?= $processedContent ?>
                </div>

                <!-- 分割线 -->
                <hr class="mt-5 mb-4" style="border-top: 2px dashed #dee2e6; opacity: 0.5;">

                <!-- 文章更新提示 -->
                <div class="alert alert-info mt-4" style="
                    border: 2px dashed #17a2b8;
                    border-radius: 8px;
                    background: linear-gradient(135deg, rgba(231, 245, 255, 0.7) 0%, rgba(255, 243, 230, 0.7) 33%, rgba(232, 245, 233, 0.7) 66%, rgba(252, 228, 236, 0.7) 100%);
                    color: #333;
                ">
                    <i class="bi bi-info-circle"></i>
                    <strong>提示：</strong>本文最后更新时间为 <?= date('Y-m-d H:i', strtotime($post['updated_at'] ?? $post['created_at'])) ?>，如文中内容素材有错误或者已经失效，请留言告知。

                    <!-- 版权声明 -->
                    <?php if (!empty($post['license']) && $post['license'] !== '无协议'): ?>
                    <hr class="my-3">
                    <div class="mb-0">
                        <strong><i class="bi bi-shield-check text-primary"></i> 版权声明</strong>
                        <div class="small text-muted" style="line-height: 2;">
                            <p class="mb-1">
                                <strong>本文作者：</strong><?= e($post['author']) ?>
                            </p>
                            <p class="mb-1">
                                <strong>本文链接：</strong>
                                <a href="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] ?>/blog.php?id=<?= $post['id'] ?>" class="text-decoration-none"><?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] ?>/blog.php?id=<?= $post['id'] ?></a>
                            </p>
                            <p class="mb-2">
                                <strong>许可协议：</strong>
                                <span class="badge bg-primary"><?= e($post['license']) ?></span>
                            </p>
                            <div class="alert alert-info" style="font-size: 13px; padding: 10px; background: rgba(233, 236, 239, 0.7);">
                                <i class="bi bi-info-circle"></i>
                                <strong>协议说明：</strong>
                                <?= getLicenseDescription($post['license']) ?>
                            </div>
                            <p class="mb-0">
                                本站所有文章除特别声明外，均采用上述许可协议。转载请注明文章出处！
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($post['data_music_enabled']) && $post['data_music_enabled'] === 'true'): ?>
                <!-- 音乐播放器 -->
                <div class="mt-5 pt-4 border-top">
                    <h5 class="mb-3"><i class="bi bi-music-note-beamed"></i> 文章音乐</h5>
                    <div class="netease-mini-player"
                        data-music-file="<?= htmlspecialchars($post['data_music_file'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        data-lyric-file="<?= htmlspecialchars($post['data_lyric_file'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        data-autoplay="<?= htmlspecialchars($post['data_autoplay'] ?? 'false', ENT_QUOTES, 'UTF-8') ?>"
                        data-position="<?= htmlspecialchars($post['data_position'] ?? 'static', ENT_QUOTES, 'UTF-8') ?>"
                        data-theme="<?= htmlspecialchars($post['data_theme'] ?? 'auto', ENT_QUOTES, 'UTF-8') ?>"
                        data-size="<?= htmlspecialchars($post['data_size'] ?? 'normal', ENT_QUOTES, 'UTF-8') ?>"
                        data-embed="<?= htmlspecialchars($post['data_embed'] ?? 'false', ENT_QUOTES, 'UTF-8') ?>"
                        data-cover-mode="<?= htmlspecialchars($post['data_cover_mode'] ?? 'false', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <?php endif; ?>


            </article>
            
            <!-- 隐私内容访问模态框 -->
            <?php if ($post['has_privacy_content'] == 1 && $privacySettings): ?>
            <?php $privacyCustomHtml = !empty($privacySettings['custom_text']) ? processColorTags($privacySettings['custom_text']) : ''; ?>
            <div class="modal fade" id="privacyAccessModal" tabindex="-1" aria-labelledby="privacyAccessModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="privacyAccessModalLabel">
                                <i class="bi bi-shield-lock"></i> 
                                <?= $privacySettings['type'] == 'login_only' ? '登录查看' : '访问隐私内容' ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <?php if (!isset($_SESSION['user_id'])): ?>
                            <?php if ($privacySettings['type'] == 'login_only'): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-info-circle"></i> 此内容只需要登录即可查看，无需回答问题。
                            </div>
                            <?php if ($privacyCustomHtml): ?>
                            <div style="margin-bottom: 12px; padding: 10px; background: rgba(40,167,69,0.08); border-radius: 4px; font-size: 14px;"><?= $privacyCustomHtml ?></div>
                            <?php endif; ?>
                            <div class="d-grid">
                                <a href="/vendor/login.php?redirect_url=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-success">
                                    <i class="bi bi-box-arrow-in-right"></i> 立即登录
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> 您需要先登录才能申请访问隐私内容。
                            </div>
                            <?php if ($privacyCustomHtml): ?>
                            <div style="margin-bottom: 12px; padding: 10px; background: rgba(255,193,7,0.08); border-radius: 4px; font-size: 14px;"><?= $privacyCustomHtml ?></div>
                            <?php endif; ?>
                            <div class="d-grid">
                                <a href="/vendor/login.php?redirect_url=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-primary">
                                    <i class="bi bi-box-arrow-in-right"></i> 立即登录
                                </a>
                            </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <?php if ($privacySettings['type'] == 'login_only'): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle"></i> 您已登录，现在可以查看隐私内容。
                            </div>
                            <?php if ($privacyCustomHtml): ?>
                            <div style="margin-bottom: 12px; padding: 10px; background: rgba(40,167,69,0.08); border-radius: 4px; font-size: 14px;"><?= $privacyCustomHtml ?></div>
                            <?php endif; ?>
                            <div class="d-grid">
                                <button type="button" class="btn btn-success" data-bs-dismiss="modal">
                                    <i class="bi bi-check"></i> 确定
                                </button>
                            </div>
                            <?php elseif ($isPendingApproval): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-hourglass-split"></i> <strong>您已经提交答案请等待</strong>
                            </div>
                            <p class="text-muted">您的申请已提交，正在等待管理员审核。审核通过后您即可访问隐私内容。</p>
                            <?php if ($privacyCustomHtml): ?>
                            <div style="margin-bottom: 12px; padding: 10px; background: rgba(13,110,253,0.08); border-radius: 4px; font-size: 14px;"><?= $privacyCustomHtml ?></div>
                            <?php endif; ?>
                            <div class="d-grid">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="bi bi-check"></i> 确定
                                </button>
                            </div>
                            <?php else: ?>
                            <p>请回答以下问题以获取访问权限：</p>
                            <div class="mb-3">
                                <label for="privacyQuestion" class="form-label">问题：</label>
                                <div class="alert alert-info"><?= htmlspecialchars($privacySettings['question']) ?></div>
                                
                                <?php if ($privacySettings['type'] == 'open_answer'): ?>
                                <small class="text-muted">这是一个开放性问题，没有固定答案，请认真回答。</small>
                                <?php elseif ($privacySettings['type'] == 'manual_approval'): ?>
                                <small class="text-muted">提交申请后，需要管理员审核才能获得访问权限。</small>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($privacySettings['type'] == 'manual_approval'): ?>
                            <div class="mb-3">
                                <label for="privacyAnswer" class="form-label">申请理由：</label>
                                <textarea class="form-control" id="privacyAnswer" rows="3" placeholder="请说明您申请访问的理由"></textarea>
                            </div>
                            <?php else: ?>
                            <div class="mb-3">
                                <label for="privacyAnswer" class="form-label">您的答案：</label>
                                <input type="text" class="form-control" id="privacyAnswer" placeholder="请输入答案">
                            </div>
                            <?php endif; ?>
                            <?php if ($privacyCustomHtml): ?>
                            <div style="margin-bottom: 12px; padding: 10px; background: rgba(13,110,253,0.08); border-radius: 4px; font-size: 14px;"><?= $privacyCustomHtml ?></div>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($_SESSION['user_id']) && $privacySettings['type'] != 'login_only' && !$isPendingApproval): ?>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="button" class="btn btn-primary" id="submitPrivacyAnswer">
                                <i class="bi bi-check-circle"></i> 
                                <?= $privacySettings['type'] == 'manual_approval' ? '提交申请' : '提交答案' ?>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <script>
                // 提交隐私答案
                document.getElementById('submitPrivacyAnswer')?.addEventListener('click', function() {
                    const button = this;
                    const answer = document.getElementById('privacyAnswer').value.trim();
                    const modal = document.getElementById('privacyAccessModal');
                    const modalInstance = bootstrap.Modal.getInstance(modal);

                    if (!answer) {
                        alert('请输入答案');
                        return;
                    }

                    // 禁用按钮防止重复提交
                    button.disabled = true;
                    button.innerHTML = '<i class="bi bi-hourglass-split"></i> 提交中...';

                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=submitPrivacyAnswer&post_id=<?= $post['id'] ?>&answer=${encodeURIComponent(answer)}&csrf_token=<?= generateCSRFToken() ?>`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);

                            // 关闭模态框
                            if (modalInstance) {
                                modalInstance.hide();
                            }

                            // 重新加载文章内容
                            fetch(window.location.href + '&refresh_content=1', {
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            })
                            .then(response => response.text())
                            .then(html => {
                                const parser = new DOMParser();
                                const doc = parser.parseFromString(html, 'text/html');
                                const newContent = doc.getElementById('post-content');
                                const currentContent = document.getElementById('post-content');

                                if (newContent && currentContent) {
                                    // 获取新的Markdown内容
                                    const newMarkdown = newContent.getAttribute('data-markdown');
                                    if (newMarkdown) {
                                        // 更新data-markdown属性
                                        currentContent.setAttribute('data-markdown', newMarkdown);
                                        
                                        // 在 marked 解析前，将 <color:xxx>...</color> 替换为占位符，避免被 marked 转义或吞掉
                                        const colorNames = {red:'#e74c3c',blue:'#3498db',green:'#2ecc71',orange:'#e67e22',purple:'#9b59b6',pink:'#e91e63',yellow:'#f1c40f',cyan:'#00bcd4',white:'#ffffff',black:'#333333',gray:'#95a5a6',brown:'#8b4513',gold:'#ffd700',indigo:'#3f51b5',teal:'#009688',lime:'#8bc34a',coral:'#ff7f50',salmon:'#fa8072',crimson:'#dc143c',navy:'#000080'};
                                        const colorPlaceholders = [];
                                        let preProcessed = newMarkdown;
                                        preProcessed = preProcessed.replace(/<color:([^>]+)>([\s\S]*?)<\/color>/gi, function(match, color, text) {
                                            const resolvedColor = colorNames[color.toLowerCase()] || color;
                                            const placeholder = '%%COLOR_' + colorPlaceholders.length + '%%';
                                            colorPlaceholders.push('<span style="color:' + resolvedColor + ';font-weight:inherit">' + text + '</span>');
                                            return placeholder;
                                        });

                                        // 重新渲染Markdown
                                        let html = marked.parse(preProcessed);

                                        // 将占位符还原为彩色 span
                                        colorPlaceholders.forEach(function(span, i) {
                                            html = html.replace('<p>' + '%%COLOR_' + i + '%%' + '</p>', span);
                                            html = html.replace('%%COLOR_' + i + '%%', span);
                                        });
                                        
                                        // 处理锚点跳转
                                        html = html.replace(/<a\s+(?:[^>]*?\s+)?href="(#[^"]*)"(?:[^>]*?)>/gi, function(match, href) {
                                            const cleanHref = href.replace(/^#/, '');
                                            const rawAnchor = cleanHref;
                                            return `<a href="${href}" onclick="handleAnchorClick('${rawAnchor}'); return false;" data-anchor="${rawAnchor}">`;
                                        });
                                        
                                        currentContent.innerHTML = html;

                                        // 禁止视频下载
                                        disableVideoDownload();

                                        // 处理外部链接
                                        const currentDomain = window.location.hostname;
                                        const links = currentContent.querySelectorAll('a[href^="http"]:not([href*="' + currentDomain + '"])');
                                        links.forEach(link => {
                                            const href = link.getAttribute('href');
                                            const linkText = link.textContent || link.innerText || '外部链接';
                                            if (!href.includes('/redirect.php')) {
                                                link.setAttribute('href', '/vendor/redirect.php?url=' + encodeURIComponent(href) + '&title=' + encodeURIComponent(linkText));
                                                link.setAttribute('target', '_blank');
                                                link.setAttribute('rel', 'noopener noreferrer');
                                            }
                                        });
                                        
                                        // 重新生成标题ID
                                        generateHeaderIds();
                                    } else {
                                        // 如果没有data-markdown，直接替换HTML
                                        currentContent.innerHTML = newContent.innerHTML;
                                    }
                                }
                            })
                            .catch(error => {
                                console.error('Error reloading content:', error);
                                // 如果加载失败，则刷新页面
                                location.reload();
                            });
                        } else {
                            alert(data.message);
                            // 恢复按钮状态
                            button.disabled = false;
                            button.innerHTML = '<i class="bi bi-check-circle"></i> 提交答案';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('提交失败，请重试');
                        // 恢复按钮状态
                        button.disabled = false;
                        button.innerHTML = '<i class="bi bi-check-circle"></i> 提交答案';
                    });
                });
            </script>
            <?php endif; ?>
            
            <!-- 评论区 -->
            <div class="comments-section mt-5 pt-5 border-top">
                <h3 class="mb-4">
                    <i class="bi bi-chat-dots"></i> 评论
                    <span class="badge bg-secondary ms-2" id="comment-count"><?= getCommentCount($post['id']) ?></span>
                </h3>
                
                <?php if (isset($_SESSION['admin_id']) || isset($_SESSION['user_id'])): ?>
                <!-- 评论表单 -->
                <div class="comment-form mb-5">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">发表评论</h5>
                        </div>
                        <div class="card-body">
                            <form id="comment-form">
                                <?= csrfField() ?>
                                <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                                <input type="hidden" name="parent_id" id="parent-id" value="">
                                <div class="mb-3">
                                    <label for="comment-content" class="form-label">评论内容</label>
                                    <textarea class="form-control" id="comment-content" name="content" rows="4" required placeholder="写下你的想法..."></textarea>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <button type="submit" class="btn btn-primary" style="background: #6c757d !important;">
                                        <i class="bi bi-send"></i> 发表评论
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary d-none" id="cancel-reply">
                                        <i class="bi bi-x-circle"></i> 取消回复
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- 未登录提示 -->
                <div class="alert alert-info mb-5">
                    <i class="bi bi-info-circle"></i>
                    请 <a href="/vendor/login.php?redirect_url=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="alert-link">登录</a> 后发表评论
                </div>
                <?php endif; ?>
                
                <!-- 评论列表 -->
                <div id="comments-list">
                    <?php
                    // 递归渲染回复的函数
                    function renderReplies($replies) {
                        global $isCurrentUserAdmin;
                        if (empty($replies)) return;
                        foreach ($replies as $reply):
                    ?>
                    <div class="reply-item mb-2 ps-4 border-start border-2 border-light" id="comment-<?= $reply['id'] ?>" data-comment-id="<?= $reply['id'] ?>">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <div>
                                <h6 class="mb-1 small">
                                    <i class="bi bi-person-circle"></i>
                                    <?= htmlspecialchars($reply['username']) ?>
                                    <?php if ($reply['user_role'] === 'admin'): ?>
                                    <span class="badge bg-success ms-1">管理员</span>
                                    <?php elseif ($reply['user_role'] === 'user'): ?>
                                    <span class="badge bg-secondary ms-1">普通用户</span>
                                    <?php endif; ?>
                                </h6>
                                <small class="text-muted">
                                    <i class="bi bi-clock"></i>
                                    <?= formatCommentTime($reply['created_at']) ?>
                                </small>
                            </div>
                            <div class="comment-actions">
                                <button class="btn btn-sm btn-outline-secondary reply-btn" data-comment-id="<?= $reply['id'] ?>" data-username="<?= htmlspecialchars($reply['username']) ?>">
                                    <i class="bi bi-reply"></i> 回复
                                </button>
                                <?php if ($isCurrentUserAdmin || (isset($_SESSION['user_id']) && $reply['user_id'] == $_SESSION['user_id'])): ?>
                                <button class="btn btn-sm btn-outline-danger delete-comment" data-comment-id="<?= $reply['id'] ?>">
                                    <i class="bi bi-trash"></i> 删除
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="reply-content small">
                            <?= nl2br(htmlspecialchars($reply['content'])) ?>
                        </div>
                        <?php if (!empty($reply['replies'])): ?>
                        <div class="replies mt-2">
                            <?php renderReplies($reply['replies']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php 
                        endforeach;
                    }
                    
                    $comments = getPostComments($post['id']);
                    if (empty($comments)):
                    ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-chat-dots fs-1"></i>
                        <p class="mt-2">暂无评论，快来发表第一条评论吧！</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($comments as $comment): ?>
                            <div class="comment-item mb-4" id="comment-<?= $comment['id'] ?>" data-comment-id="<?= $comment['id'] ?>">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h6 class="mb-1">
                                                    <i class="bi bi-person-circle"></i>
                                                    <?= htmlspecialchars($comment['username']) ?>
                                                    <?php if ($comment['user_role'] === 'admin'): ?>
                                                    <span class="badge bg-success ms-1">管理员</span>
                                                    <?php elseif ($comment['user_role'] === 'user'): ?>
                                                    <span class="badge bg-secondary ms-1">普通用户</span>
                                                    <?php endif; ?>
                                                </h6>
                                                <small class="text-muted">
                                                    <i class="bi bi-clock"></i>
                                                    <?= formatCommentTime($comment['created_at']) ?>
                                                </small>
                                            </div>
                                            <div class="comment-actions">
                                                <button class="btn btn-sm btn-outline-secondary reply-btn" data-comment-id="<?= $comment['id'] ?>" data-username="<?= htmlspecialchars($comment['username']) ?>">
                                                    <i class="bi bi-reply"></i> 回复
                                                </button>
                                                <?php if ($isCurrentUserAdmin || (isset($_SESSION['user_id']) && $comment['user_id'] == $_SESSION['user_id'])): ?>
                                                <button class="btn btn-sm btn-outline-danger delete-comment" data-comment-id="<?= $comment['id'] ?>">
                                                    <i class="bi bi-trash"></i> 删除
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="comment-content">
                                            <?= nl2br(htmlspecialchars($comment['content'])) ?>
                                        </div>
                                        
                                        <!-- 回复列表 -->
                                        <?php if (!empty($comment['replies'])): ?>
                                        <div class="replies mt-3">
                                            <?php renderReplies($comment['replies']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 推荐文章与友情链接 -->
            <div class="recommended-posts mt-2 pt-2 border-top">
                <h3 class="mb-5">
                    <i class="bi bi-bookmark-star"></i> 推荐文章
                </h3>
                <?php
                // 获取推荐文章 - 同分类的其他文章
                $recommendedPosts = [];
                
                if (!empty($post['category'])) {
                    // 优先显示同分类的其他文章（排除当前文章）
                    $stmt = $db->prepare("SELECT id, title, is_pinned, is_featured, author, views, created_at, cover_image, category, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE category = ? AND id != ? ORDER BY views DESC LIMIT 3");
                    $stmt->execute([$post['category'], $post['id']]);
                    $recommendedPosts = $stmt->fetchAll();
                }
                
                // 如果同分类文章不足3篇，补充最新文章
                if (count($recommendedPosts) < 3) {
                    $needed = 3 - count($recommendedPosts);
                    $excludedIds = array_merge([$post['id']], array_column($recommendedPosts, 'id'));
                    $placeholders = str_repeat('?,', count($excludedIds) - 1) . '?';
                    $stmt = $db->prepare("SELECT id, title, is_pinned, is_featured, author, views, created_at, cover_image, category, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE id NOT IN ($placeholders) AND is_published = 1 ORDER BY created_at DESC LIMIT $needed");
                    $stmt->execute($excludedIds);
                    $additionalPosts = $stmt->fetchAll();
                    $recommendedPosts = array_merge($recommendedPosts, $additionalPosts);
                }
                
                if (!empty($recommendedPosts)):
                ?>
                <div class="row g-4">
                    <?php foreach ($recommendedPosts as $recPost): 
                        $recCategoryColor = isset($categoryColors[$recPost['category']]) ? $categoryColors[$recPost['category']] : '#007bff';
                    ?>
                    <div class="col-md-4">
                        <div class="card h-100 post-recommendation-card">
                            <?php 
                            $recCoverUrl = !empty($recPost['cover_image']) ? ImageMapper::getFinalUrl($recPost['cover_image'], $imageBedEnabled) : '';
                            if (!empty($recCoverUrl)): ?>
                            <img src="<?= e($recCoverUrl) ?>" class="card-img-top" alt="<?= e($recPost['title']) ?>" style="height: 180px; object-fit: cover;" data-local-url="<?= e($recPost['cover_image']) ?>" loading="lazy">
                            <?php else: ?>
                            <div class="card-img-top d-flex align-items-center justify-content-center" style="height: 180px; background-color: #f8f9fa;">
                                <picture>
                                    <source srcset="https://t.alcy.cc/ai?<?= rand() ?>&format=webp" type="image/webp">
                                    <img src="https://t.alcy.cc/ai?<?= rand() ?>&format=jpg" class="img-fluid" alt="<?= e($recPost['title']) ?> (随机封面)" style="max-height: 160px; object-fit: cover;">
                                </picture>
                            </div>
                            <?php endif; ?>
                            <div class="card-body d-flex flex-column">
                                <h6 class="card-title">
                                    <?php if ($recPost['is_pinned']): ?><i class="bi bi-pin-angle-fill text-danger" title="置顶"></i><?php endif; ?>
                                    <?php if ($recPost['is_featured']): ?><i class="bi bi-star-fill text-warning" title="精选"></i><?php endif; ?>
                                    <a href="/blog.php?id=<?= $recPost['id'] ?>" class="text-decoration-none text-dark">
                                        <?= e($recPost['title']) ?>
                                    </a>
                                </h6>
                                <p class="card-text small text-muted mb-2">
                                    <i class="bi bi-person"></i> <?= e($recPost['author']) ?> | 
                                    <i class="bi bi-eye"></i> <?= $recPost['views'] ?> | 
                                    <?= date('Y-m-d', strtotime($recPost['created_at'])) ?>
                                </p>
                                <?php if ($recPost['category']): ?>
                                <div class="mb-2">
                                    <span class="badge" style="background-color: <?= $recCategoryColor ?>">
                                        <i class="bi bi-folder"></i> <?= e($recPost['category']) ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <p class="card-text small flex-grow-1">
                                    <?= mb_substr(strip_tags($recPost['content']), 0, 50) ?>...
                                </p>
                                <a href="/blog.php?id=<?= $recPost['id'] ?>" class="btn btn-outline-secondary btn-sm mt-auto">
                                    阅读全文 <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-bookmark fs-1"></i>
                    <p class="mt-2">暂无推荐文章</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- 友情链接 -->
            <div class="recommended-posts mt-1">
                <div class="d-flex justify-content-between align-items-center mb-5">
                    <h3 class="mb-0">
                        <i class="bi bi-link-45deg"></i> 友情链接
                    </h3>
                    <a href="/vendor/friend-links.php" class="btn btn-outline-secondary btn-sm rounded-pill">
                        查看更多 <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <?php 
                // 随机获取4个友情链接
                if (!empty($friendLinks)) {
                    shuffle($friendLinks);
                    $displayLinks = array_slice($friendLinks, 0, 4);
                } else {
                    $displayLinks = [];
                }
                ?>
                <?php if (!empty($displayLinks)): ?>
                    <div class="friend-links-grid">
                        <?php foreach ($displayLinks as $link): ?>
                        <a href="/vendor/redirect.php?url=<?= urlencode($link['url']) ?>&title=<?= urlencode($link['name']) ?>" target="_blank" class="friend-link-item">
                            <?php if ($link['logo']): ?>
                            <img src="<?= e($link['logo']) ?>" alt="<?= e($link['name']) ?>" class="friend-link-logo">
                            <?php else: ?>
                            <div class="friend-link-logo-placeholder">
                                <i class="bi bi-link-45deg"></i>
                            </div>
                            <?php endif; ?>
                            <div class="friend-link-info">
                                <h5><?= e($link['name']) ?></h5>
                                <?php if ($link['description']): ?>
                                <p><?= e($link['description']) ?></p>
                                <?php endif; ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-link-45deg fs-1"></i>
                        <p class="mt-2">暂无友情链接</p>
                    </div>
                <?php endif; ?>
            </div>

                <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                <!-- 管理员工具箱 -->
                <link href="/assets/css/blog/admin-toolbox.css?v=<?= $blog_version ?>" rel="stylesheet">
                <div class="admin-toolbox">
                    <div class="admin-toolbox-header">
                        <h6 class="admin-toolbox-title">
                            <span class="icon-wrapper"><i class="bi bi-shield-lock-fill"></i></span>
                            管理员控制台
                        </h6>
                        <span class="badge admin-badge">Admin</span>
                    </div>
                    <div class="admin-toolbox-body">
                        <button class="admin-btn admin-btn-primary" onclick="downloadArticleMarkdown()">
                            <i class="bi bi-cloud-download-fill"></i>
                            <span>下载本篇文章</span>
                        </button>
                        <a href="/admin/posts.php?edit=<?= $post['id'] ?>" class="admin-btn admin-btn-info">
                            <i class="bi bi-pencil-square"></i>
                            <span>编辑文章</span>
                        </a>
                        <a href="/admin/comments.php" class="admin-btn admin-btn-warning">
                            <i class="bi bi-chat-dots"></i>
                            <span>评论管理</span>
                        </a>
                        <a href="/admin/privacy_access.php" class="admin-btn admin-btn-success">
                            <i class="bi bi-shield-check"></i>
                            <span>表单记录</span>
                        </a>
                    </div>
                </div>

                <script>
                function downloadArticleMarkdown() {
                    const modalEl = document.getElementById('downloadVerifyModal');
                    const modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }

                // 显示Toast通知函数
                function showToast(message, type = 'success') {
                    // 创建Toast容器（如果不存在）
                    let toastContainer = document.querySelector('.toast-container');
                    if (!toastContainer) {
                        toastContainer = document.createElement('div');
                        toastContainer.className = 'toast-container position-fixed bottom-0 end-0 p-3';
                        document.body.appendChild(toastContainer);
                    }
                    
                    // 生成唯一ID
                    const toastId = 'toast-' + Date.now();
                    
                    // 设置图标和颜色
                    let icon = 'bi-check-circle-fill';
                    let headerClass = 'text-success';
                    
                    if (type === 'error' || type === 'danger') {
                        icon = 'bi-x-circle-fill';
                        headerClass = 'text-danger';
                    } else if (type === 'warning') {
                        icon = 'bi-exclamation-triangle-fill';
                        headerClass = 'text-warning';
                    } else if (type === 'info') {
                        icon = 'bi-info-circle-fill';
                        headerClass = 'text-info';
                    }
                    
                    // 创建Toast元素
                    const toastHtml = `
                        <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                            <div class="toast-header">
                                <i class="bi ${icon} ${headerClass} me-2"></i>
                                <strong class="me-auto">系统通知</strong>
                                <small>刚刚</small>
                                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                            </div>
                            <div class="toast-body">
                                ${message}
                            </div>
                        </div>
                    `;
                    
                    // 添加到容器
                    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
                    
                    // 初始化并显示Toast
                    const toastElement = document.getElementById(toastId);
                    const toast = new bootstrap.Toast(toastElement, {
                        delay: 3000
                    });
                    toast.show();
                    
                    // 隐藏后移除DOM元素
                    toastElement.addEventListener('hidden.bs.toast', function () {
                        toastElement.remove();
                    });
                }

                // 页面加载动画控制
        window.addEventListener('load', function() {
            // 确保所有资源（包括图片、CSS、JS）都已加载完成
            setTimeout(function() {
                const loader = document.getElementById('page-loader');
                const pageWrapper = document.querySelector('.page-wrapper');
                
                if (loader && pageWrapper) {
                    // 隐藏加载动画
                    loader.classList.add('hidden');
                    
                    // 显示页面内容
                    pageWrapper.classList.add('loaded');
                    
                    // 动画完成后从DOM中移除加载器
                    setTimeout(function() {
                        loader.remove();
                    }, 500);
                }
            }, 500); // 添加500ms延迟，确保动画平滑
        });

        document.addEventListener('DOMContentLoaded', function() {
                    const sendCodeBtn = document.getElementById('sendCodeBtn');
                    if (sendCodeBtn) {
                        sendCodeBtn.addEventListener('click', async function() {
                            const btn = this;
                            btn.disabled = true;
                            
                            try {
                                const response = await fetch('/vendor/send_verification_code.php', {
                                    method: 'POST'
                                });
                                
                                const data = await response.json();
                                if (data.success) {
                                    showToast(data.message, 'success');
                                    let seconds = 60;
                                    const originalText = btn.innerText;
                                    btn.innerText = `${seconds}s后重试`;
                                    const timer = setInterval(() => {
                                        seconds--;
                                        btn.innerText = `${seconds}s后重试`;
                                        if (seconds <= 0) {
                                            clearInterval(timer);
                                            btn.disabled = false;
                                            btn.innerText = originalText;
                                        }
                                    }, 1000);
                                } else {
                                    showToast(data.message || '发送失败', 'error');
                                    btn.disabled = false;
                                }
                            } catch (e) {
                                console.error(e);
                                showToast('发送出错，请检查网络', 'error');
                                btn.disabled = false;
                            }
                        });
                    }

                    const confirmBtn = document.getElementById('confirmDownloadBtn');
                    if (confirmBtn) {
                        confirmBtn.addEventListener('click', function() {
                            const code = document.getElementById('verifyCode').value;
                            const password = document.getElementById('downloadPassword').value;
                            
                            if (!code || !password) {
                                showToast('请填写完整信息', 'warning');
                                return;
                            }
                            
                            const modalEl = document.getElementById('downloadVerifyModal');
                            const modal = bootstrap.Modal.getInstance(modalEl);
                            modal.hide();
                            
                            window.location.href = `/vendor/download_article_zip.php?id=<?= $post['id'] ?>&password=${encodeURIComponent(password)}&code=${encodeURIComponent(code)}`;
                        });
                    }
                });
                </script>
                <?php endif; ?>
        </div>

        <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
        <script src="<?= getResourceUrl('/assets/js/marked.min.js', 'https://cdn.staticfile.net/marked/11.1.1/marked.min.js') ?>"></script>

        <!-- 语法高亮库 highlight.js (auto版本，自动检测常用语言) -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js" defer></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
        hljs.configure({ignoreUnescapedHTML: true});

        // 配置 marked.js 的语法高亮
        marked.setOptions({
            highlight: function(code, lang) {
                // 调试：输出语言信息
                if (lang) {
                    console.log('检测到语言:', lang);
                }

                if (lang && hljs.getLanguage(lang)) {
                    try {
                        const result = hljs.highlight(code, { language: lang });
                        console.log('语法高亮成功:', lang);
                        return result.value;
                    } catch (err) {
                        console.error('语法高亮失败:', lang, err);
                    }
                }
                // 自动检测语言
                const autoResult = hljs.highlightAuto(code);
                console.log('自动检测语言:', autoResult.language);
                return autoResult.value;
            },
            breaks: true,
            gfm: true
        });
        });
        </script>

        <!-- 导航栏滚动效果 -->
        <script>
        // 检测页面滚动，为导航栏添加阴影效果
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar.fixed-top');
            if (navbar) {
                if (window.scrollY > 10) {
                    navbar.classList.add('scrolled');
                } else {
                    navbar.classList.remove('scrolled');
                }
            }
        });
        </script>
        
        <!-- 锚点跳转功能JavaScript -->
        <script>
        // 图片右键禁用脚本
        document.addEventListener('contextmenu', function(e) {
            // 禁止文章内容中的图片和模态框中的图片右键
            if (e.target.tagName === 'IMG' && (e.target.closest('.post-content') || e.target.closest('.image-viewer-modal'))) {
                e.preventDefault();
                return false;
            }
        });

        // 全局标题映射表
        let headingMapping = {};
        
        // 生成标题ID以支持锚点跳转
        function generateHeaderIds() {
            const postContent = document.getElementById('post-content');
            if (!postContent) return;
            
            const headings = postContent.querySelectorAll('h1, h2, h3, h4, h5, h6');
            const usedIds = new Set();
            headingMapping = {}; // 重置映射表
            
            headings.forEach((heading, index) => {
                const originalText = heading.textContent.trim();
                let id = heading.getAttribute('id');
                
                // 如果没有ID，生成一个
                if (!id) {
                    // 生成基础ID - 改进中文字符处理
                    let baseId = originalText
                        .trim()
                        .toLowerCase()
                        // 保留中文字符、字母、数字、空格、连字符
                        .replace(/[^\w\u4e00-\u9fa5\s-]/g, '') 
                        .replace(/\s+/g, '-') // 空格替换为连字符
                        .replace(/-+/g, '-') // 多个连字符合并为一个
                        .trim();
                    
                    // 如果基础ID为空，使用通用ID
                    if (!baseId) {
                        baseId = `heading-${index + 1}`;
                    }
                    
                    // 确保ID唯一
                    let finalId = baseId;
                    let counter = 1;
                    while (usedIds.has(finalId)) {
                        finalId = `${baseId}-${counter}`;
                        counter++;
                    }
                    
                    usedIds.add(finalId);
                    heading.setAttribute('id', finalId);
                    id = finalId;
                }
                
                // 建立映射关系
                headingMapping[originalText] = id;
                headingMapping[originalText.toLowerCase()] = id;
                headingMapping[originalText.replace(/\s+/g, '-')] = id;
                headingMapping[originalText.toLowerCase().replace(/\s+/g, '-')] = id;
                headingMapping[encodeURIComponent(originalText)] = id;
                headingMapping[encodeURIComponent(originalText.toLowerCase())] = id;
                
                // 添加调试信息
                console.log('标题映射:', originalText, '->', id);
            });
            
            console.log('完整标题映射表:', headingMapping);
        }
        
        // 通过标题文本查找元素
        function findHeadingByTitle(title) {
            const postContent = document.getElementById('post-content');
            if (!postContent) return null;
            
            const decodedTitle = decodeURIComponent(title);
            
            // 1. 使用映射表
            if (headingMapping[decodedTitle]) {
                const element = postContent.querySelector('#' + CSS.escape(headingMapping[decodedTitle]));
                if (element) {
                    console.log('通过映射表找到:', decodedTitle, '->', headingMapping[decodedTitle]);
                    return element;
                }
            }
            
            // 2. 直接查找所有标题
            const headings = postContent.querySelectorAll('h1, h2, h3, h4, h5, h6');
            for (let heading of headings) {
                const headingText = heading.textContent.trim();
                if (headingText === decodedTitle || headingText === title) {
                    console.log('直接文本匹配找到:', headingText);
                    return heading;
                }
            }
            
            // 3. 模糊匹配
            for (let heading of headings) {
                const headingText = heading.textContent.trim().toLowerCase();
                const searchTitle = decodedTitle.toLowerCase();
                
                if (headingText.includes(searchTitle) || searchTitle.includes(headingText)) {
                    console.log('模糊匹配找到:', headingText);
                    return heading;
                }
            }
            
            return null;
        }

        // 处理锚点点击
        function handleAnchorClick(anchorId) {
            console.log('处理锚点点击:', anchorId);
            
            // 在文章内容区域中查找目标元素
            const postContent = document.getElementById('post-content');
            if (!postContent) {
                console.error('文章内容区域未找到');
                return;
            }
            
            // 移除现有的高亮
            const existingHighlights = postContent.querySelectorAll('.anchor-highlight');
            existingHighlights.forEach(el => el.classList.remove('anchor-highlight'));
            
            // URL解码
            const decodedAnchor = decodeURIComponent(anchorId);
            console.log('解码后的锚点:', decodedAnchor);
            
            let targetElement = null;
            
            // 1. 使用映射表查找
            if (headingMapping[decodedAnchor] || headingMapping[anchorId]) {
                const mappedId = headingMapping[decodedAnchor] || headingMapping[anchorId];
                targetElement = postContent.querySelector('#' + CSS.escape(mappedId));
                if (targetElement) {
                    console.log('✅ 通过映射表找到:', decodedAnchor, '->', mappedId);
                }
            }
            
            // 2. 通过标题文本直接查找
            if (!targetElement) {
                targetElement = findHeadingByTitle(decodedAnchor);
                if (targetElement) {
                    console.log('✅ 通过标题文本找到:', decodedAnchor);
                }
            }
            
            // 3. 通过ID直接查找
            if (!targetElement) {
                targetElement = postContent.querySelector(`#${CSS.escape(anchorId)}`);
                if (targetElement) {
                    console.log('✅ 通过ID找到:', anchorId);
                }
            }
            
            // 4. 通过解码后的ID查找
            if (!targetElement) {
                targetElement = postContent.querySelector(`#${CSS.escape(decodedAnchor)}`);
                if (targetElement) {
                    console.log('✅ 通过解码ID找到:', decodedAnchor);
                }
            }
            
            if (targetElement) {
                // 添加高亮效果
                targetElement.classList.add('anchor-highlight');
                
                // 计算滚动位置，考虑导航栏高度
                const navHeight = 70;
                const elementPosition = targetElement.getBoundingClientRect().top + window.pageYOffset;
                const offsetPosition = elementPosition - navHeight;
                
                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });
                
                // 3秒后移除高亮
                setTimeout(() => {
                    targetElement.classList.remove('anchor-highlight');
                }, 3000);
                
                console.log('🎯 成功定位到锚点:', decodedAnchor, '->', targetElement.textContent.trim());
            } else {
                console.warn('❌ 未找到锚点目标:', decodedAnchor);
                
                // 显示用户友好的错误信息
                const errorMsg = `未找到标题：${decodedAnchor}\n\n请检查：\n1. 标题是否存在\n2. 链接是否正确\n3. 页面是否完全加载`;
                
                // 尝试滚动到最相似的标题作为备用方案
                const headings = postContent.querySelectorAll('h1, h2, h3, h4, h5, h6');
                let bestMatch = null;
                let bestScore = 0;
                
                for (let heading of headings) {
                    const headingText = heading.textContent.toLowerCase();
                    const anchorText = decodedAnchor.toLowerCase();
                    
                    // 计算相似度得分
                    if (headingText === anchorText) {
                        bestScore = 100;
                        bestMatch = heading;
                        break;
                    } else if (headingText.includes(anchorText) || anchorText.includes(headingText)) {
                        if (bestScore < 80) {
                            bestScore = 80;
                            bestMatch = heading;
                        }
                    } else {
                        const words = anchorText.split(/\s+/).filter(w => w.length > 1);
                        const matchedWords = words.filter(word => headingText.includes(word));
                        const score = (matchedWords.length / words.length) * 50;
                        
                        if (score > bestScore) {
                            bestScore = score;
                            bestMatch = heading;
                        }
                    }
                }
                
                if (bestMatch && bestScore > 30) {
                    if (confirm(`未找到精确匹配的标题"${decodedAnchor}"。\n\n是否跳转到最相似的标题：\n"${bestMatch.textContent.trim()}"？`)) {
                        bestMatch.classList.add('anchor-highlight');
                        
                        // 计算滚动位置，考虑导航栏高度
                        const navHeight = 70;
                        const elementPosition = bestMatch.getBoundingClientRect().top + window.pageYOffset;
                        const offsetPosition = elementPosition - navHeight;
                        
                        window.scrollTo({
                            top: offsetPosition,
                            behavior: 'smooth'
                        });
                        
                        setTimeout(() => {
                            bestMatch.classList.remove('anchor-highlight');
                        }, 3000);
                        
                        console.log('🎯 使用相似度匹配找到目标:', bestMatch.textContent.trim(), '得分:', bestScore);
                        return;
                    }
                }
                
                alert(errorMsg);
            }
        }
        </script>

        <script>
        // 禁止视频下载功能（全局函数）
        function disableVideoDownload() {
            const postContent = document.getElementById('post-content');
            if (!postContent) return;

            const videos = postContent.querySelectorAll('video');

            videos.forEach(video => {
                // 移除 controlsList 中的 download 选项（如果有）
                const currentControlsList = video.getAttribute('controlsList') || '';
                if (!currentControlsList.includes('nodownload')) {
                    video.setAttribute('controlsList', currentControlsList + (currentControlsList ? ' ' : '') + 'nodownload');
                }

                // 禁用画中画功能
                video.setAttribute('disablePictureInPicture', 'true');

                // 禁用下载按钮
                video.removeAttribute('download');

                // 包装视频添加覆盖层（如果还没有包装）
                if (!video.parentElement.classList.contains('video-overlay')) {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'video-overlay';
                    video.parentNode.insertBefore(wrapper, video);
                    wrapper.appendChild(video);
                }

                // 禁用右键菜单
                video.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                });

                // 禁用拖拽
                video.addEventListener('dragstart', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                });

                // 防止键盘快捷键下载（只在视频有焦点时）
                video.addEventListener('keydown', function(e) {
                    // 禁用 Ctrl+S, Ctrl+D, Ctrl+Shift+S 等下载快捷键
                    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'd' || e.key === 'S' || e.key === 'D')) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                });

                // 阻止复制事件
                video.addEventListener('copy', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                });

                // 监听视频控制栏右键点击
                video.addEventListener('mousedown', function(e) {
                    // 如果是右键点击（button === 2），阻止默认行为
                    if (e.button === 2) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                });

                // 隐藏浏览器默认的下载按钮（通过 shadow DOM）
                const shadowObserver = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'childList') {
                            const shadowRoot = video.shadowRoot;
                            if (shadowRoot) {
                                const downloadButton = shadowRoot.querySelector('[download], [aria-label*="下载"], [title*="下载"]');
                                if (downloadButton) {
                                    downloadButton.style.display = 'none';
                                    downloadButton.disabled = true;
                                }
                            }
                        }
                    });
                });
                shadowObserver.observe(video, { childList: true, subtree: true });
            });
        }
        </script>

        <script>
        // 复制时附加来源、作者与许可说明（支持右键复制与快捷键复制）
        document.addEventListener('copy', function(e) {
            try {
                const sel = document.getSelection();
                if (!sel || sel.isCollapsed) return;

                const anchorNode = sel.anchorNode || sel.focusNode;
                if (!anchorNode) return;

                // 检测选中的内容是否在代码块内
                const codeElement = (anchorNode.nodeType === Node.TEXT_NODE ? anchorNode.parentElement : anchorNode).closest('pre, code');

                // 只在代码块或文章内容内附加来源信息
                const postContentEl = document.getElementById('post-content');
                const isInCodeBlock = codeElement !== null;
                const isInPostContent = postContentEl && postContentEl.contains(anchorNode);

                if (!isInCodeBlock && !isInPostContent) return;

                const selectedText = sel.toString();

                const siteUrl = <?php echo json_encode((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>;
                const author = <?php echo json_encode($post['author'] ?? '未知作者'); ?>;
                const license = <?php echo json_encode($post['license'] ?? '无协议'); ?>;
                const licenseDesc = <?php echo json_encode(getLicenseDescription($post['license'] ?? '无协议')); ?>;

                const appendix = "\n\n——\n来源：" + siteUrl + "\n作者：" + author + "\n许可：" + license + (license && license !== '无协议' ? ("\n说明：" + licenseDesc) : "");

                e.preventDefault();
                const clipboard = e.clipboardData || window.clipboardData;
                clipboard.setData('text/plain', selectedText + appendix);

                // 同时设置 HTML 格式（将换行替换为 <br>）
                const htmlSelected = selectedText.replace(/\n/g, '<br>');
                const htmlAppend = '<br><br><small>——<br>来源：' + siteUrl + '<br>作者：' + author + '<br>许可：' + license + (license && license !== '无协议' ? ('<br>说明：' + licenseDesc) : '') + '</small>';
                clipboard.setData('text/html', htmlSelected + htmlAppend);
            } catch (err) {
                console.error('copy handler error', err);
            }
        });

        // 复制代码块功能
        function copyCode(button) {
            const codeBlock = button.closest('.code-block-wrapper').querySelector('code');
            const codeText = codeBlock.textContent;

            // 附加来源、作者与许可说明
            const siteUrl = <?php echo json_encode((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>;
            const author = <?php echo json_encode($post['author'] ?? '未知作者'); ?>;
            const license = <?php echo json_encode($post['license'] ?? '无协议'); ?>;
            const licenseDesc = <?php echo json_encode(getLicenseDescription($post['license'] ?? '无协议')); ?>;

            const appendix = "\n\n——\n来源：" + siteUrl + "\n作者：" + author + "\n许可：" + license + (license && license !== '无协议' ? ("\n说明：" + licenseDesc) : "");

            // 使用 navigator.clipboard API（如果可用）
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(codeText + appendix).then(function() {
                    showCopySuccess(button);
                }).catch(function(err) {
                    console.error('复制失败:', err);
                    fallbackCopy(codeText + appendix, button);
                });
            } else {
                // 回退方案
                fallbackCopy(codeText + appendix, button);
            }
        }

        // 回退复制方案
        function fallbackCopy(text, button) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '0';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    showCopySuccess(button);
                } else {
                    console.error('复制失败');
                }
            } catch (err) {
                console.error('复制失败:', err);
            }

            document.body.removeChild(textArea);
        }

        // 显示复制成功状态
        function showCopySuccess(button) {
            const originalContent = button.innerHTML;
            button.innerHTML = '<i class="bi bi-check"></i> 已复制';
            button.classList.add('copied');

            setTimeout(function() {
                button.innerHTML = originalContent;
                button.classList.remove('copied');
            }, 2000);
        }
        </script>

        <!-- 评论功能JavaScript -->
        <script>
        // 用户信息
        const currentUser = {
            id: <?= $currentUserId ?? 'null' ?>,
            role: '<?= $isCurrentUserAdmin ? 'admin' : (isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'guest') ?>'
        };
        
        // 格式化时间函数
        function formatCommentTime(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diff = now - date;
            const minutes = Math.floor(diff / 60000);
            const hours = Math.floor(diff / 3600000);
            const days = Math.floor(diff / 8640000);
            
            if (minutes < 1) return '刚刚';
            if (minutes < 60) return minutes + '分钟前';
            if (hours < 24) return hours + '小时前';
            if (days < 30) return days + '天前';
            
            return date.toLocaleDateString('zh-CN');
        }
        
        // 动态添加评论到列表
        function addCommentToList(comment) {
            const commentsList = document.getElementById('comments-list');
            
            // 如果是第一条评论，清空"暂无评论"提示
            const emptyMessage = commentsList.querySelector('.text-muted');
            if (emptyMessage && emptyMessage.textContent.includes('暂无评论')) {
                commentsList.innerHTML = '';
            }
            
            const isAdmin = comment.user_role === 'admin';
            const isReply = comment.parent_id !== null && comment.parent_id !== '' && comment.parent_id !== undefined;
            
            if (isReply) {
                // 如果是回复，需要添加到对应父评论的回复列表中
                const parentEl = document.querySelector(`[data-comment-id="${comment.parent_id}"]`);
                if (parentEl) {
                    // 找到父评论所属的一级评论（.comment-item）
                    let commentItem = parentEl.closest('.comment-item');
                    if (commentItem) {
                        // 确保回复容器存在
                        let repliesContainer = commentItem.querySelector('.replies');
                        if (!repliesContainer) {
                            repliesContainer = document.createElement('div');
                            repliesContainer.className = 'replies mt-3';
                            const cardBody = commentItem.querySelector('.card-body');
                            if (cardBody) {
                                cardBody.appendChild(repliesContainer);
                            }
                        }
                        
                        const replyHtml = createReplyHtml(comment);
                        repliesContainer.appendChild(replyHtml);
                    }
                } else {
                    // 如果没找到父元素，作为新评论添加
                    const commentHtml = createCommentHtml(comment);
                    commentsList.insertBefore(commentHtml, commentsList.firstChild);
                }
            } else {
                // 如果是新评论，添加到列表顶部
                const commentHtml = createCommentHtml(comment);
                commentsList.insertBefore(commentHtml, commentsList.firstChild);
            }
            
            // 重新绑定事件
            bindCommentEvents();
        }
        
        // 创建评论HTML - 使用安全的DOM API
        function createCommentHtml(comment) {
            const isAdmin = comment.user_role === 'admin';
            const time = formatCommentTime(comment.created_at);
            const canDelete = currentUser.role === 'admin' || (currentUser.id == comment.user_id && currentUser.id !== null);

            // 使用DOM API创建元素，防止XSS
            const div = document.createElement('div');
            div.className = 'comment-item mb-4';
            div.id = 'comment-' + comment.id;
            div.dataset.commentId = comment.id;

            div.innerHTML = `
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h6 class="mb-1">
                                    <i class="bi bi-person-circle"></i>
                                    <span class="comment-username"></span>
                                    <span class="comment-role-badge ms-1"></span>
                                </h6>
                                <small class="text-muted">
                                    <i class="bi bi-clock"></i>
                                    <span class="comment-time"></span>
                                </small>
                            </div>
                            <div class="comment-actions">
                                <button class="btn btn-sm btn-outline-secondary reply-btn" data-comment-id="">
                                    <i class="bi bi-reply"></i> 回复
                                </button>
                                <span class="delete-btn-container"></span>
                            </div>
                        </div>
                        <div class="comment-content"></div>
                    </div>
                </div>
            `;

            // 安全地设置用户名（textContent自动转义）
            div.querySelector('.comment-username').textContent = comment.username;

            // 设置角色徽章
            const roleBadge = div.querySelector('.comment-role-badge');
            if (isAdmin) {
                roleBadge.className = 'badge bg-success ms-1';
                roleBadge.textContent = '管理员';
            } else {
                roleBadge.className = 'badge bg-secondary ms-1';
                roleBadge.textContent = '普通用户';
            }

            // 设置时间
            div.querySelector('.comment-time').textContent = time;

            // 设置按钮属性
            const replyBtn = div.querySelector('.reply-btn');
            replyBtn.dataset.commentId = comment.id;

            const deleteBtnContainer = div.querySelector('.delete-btn-container');
            if (canDelete) {
                deleteBtnContainer.innerHTML = `
                    <button class="btn btn-sm btn-outline-danger delete-comment" data-comment-id="">
                        <i class="bi bi-trash"></i> 删除
                    </button>
                `;
                deleteBtnContainer.querySelector('.delete-comment').dataset.commentId = comment.id;
            }

            // 安全地设置评论内容
            const contentDiv = div.querySelector('.comment-content');
            contentDiv.innerHTML = comment.content.replace(/\n/g, '<br>');
            
            // 递归渲染子回复
            if (comment.replies && comment.replies.length > 0) {
                const repliesContainer = document.createElement('div');
                repliesContainer.className = 'replies mt-3';
                comment.replies.forEach(reply => {
                    const replyHtml = createReplyHtml(reply);
                    repliesContainer.appendChild(replyHtml);
                });
                div.querySelector('.card-body').appendChild(repliesContainer);
            }

            return div;
        }
        
        // 创建回复HTML - 使用安全的DOM API
        function createReplyHtml(comment) {
            const isAdmin = comment.user_role === 'admin';
            const time = formatCommentTime(comment.created_at);
            const canDelete = currentUser.role === 'admin' || (currentUser.id == comment.user_id && currentUser.id !== null);

            // 使用DOM API创建元素，防止XSS
            const div = document.createElement('div');
            div.className = 'reply-item mb-2 ps-4 border-start border-2 border-light';
            div.id = 'comment-' + comment.id;
            div.dataset.commentId = comment.id;

            div.innerHTML = `
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div>
                        <h6 class="mb-1 small">
                            <i class="bi bi-person-circle"></i>
                            <span class="reply-username"></span>
                            <span class="reply-role-badge ms-1"></span>
                        </h6>
                        <small class="text-muted">
                            <i class="bi bi-clock"></i> <span class="reply-time"></span>
                        </small>
                    </div>
                    <div class="comment-actions">
                        <button class="btn btn-sm btn-outline-secondary reply-btn" data-comment-id="" data-username="匿名用户">
                            <i class="bi bi-reply"></i> 回复
                        </button>
                        <span class="delete-reply-btn-container"></span>
                    </div>
                </div>
                <div class="comment-content small"></div>
            `;

            // 安全地设置用户名（防止undefined）
            const safeUsername = comment.username || '匿名用户';
            div.querySelector('.reply-username').textContent = safeUsername;

            // 设置角色徽章
            const roleBadge = div.querySelector('.reply-role-badge');
            if (isAdmin) {
                roleBadge.className = 'badge bg-success ms-1';
                roleBadge.textContent = '管理员';
            } else {
                roleBadge.className = 'badge bg-secondary ms-1';
                roleBadge.textContent = '普通用户';
            }

            // 设置时间
            div.querySelector('.reply-time').textContent = time;

            // 设置回复按钮
            const replyBtn = div.querySelector('.reply-btn');
            replyBtn.dataset.commentId = comment.id;
            replyBtn.dataset.username = safeUsername;

            // 设置删除按钮
            const deleteBtnContainer = div.querySelector('.delete-reply-btn-container');
            if (canDelete) {
                deleteBtnContainer.innerHTML = `
                    <button class="btn btn-sm btn-outline-danger delete-comment" data-comment-id="">
                        <i class="bi bi-trash"></i> 删除
                    </button>
                `;
                deleteBtnContainer.querySelector('.delete-comment').dataset.commentId = comment.id;
            }

            // 安全地设置回复内容
            const contentDiv = div.querySelector('.comment-content');
            contentDiv.innerHTML = comment.content.replace(/\n/g, '<br>');
            
            // 递归渲染子回复
            if (comment.replies && comment.replies.length > 0) {
                const repliesContainer = document.createElement('div');
                repliesContainer.className = 'replies mt-2';
                comment.replies.forEach(subReply => {
                    const subReplyHtml = createReplyHtml(subReply);
                    repliesContainer.appendChild(subReplyHtml);
                });
                div.appendChild(repliesContainer);
            }

            return div;
        }
        
        // 绑定评论事件
        function bindCommentEvents() {
            // 回复按钮事件
            document.querySelectorAll('.reply-btn:not([data-bound])').forEach(btn => {
                btn.setAttribute('data-bound', 'true');
                btn.addEventListener('click', function() {
                    const commentId = this.dataset.commentId;
                    const username = this.dataset.username || '匿名用户';

                    const parentIdInput = document.getElementById('parent-id');
                    const contentTextarea = document.getElementById('comment-content');
                    const cancelReplyBtn = document.getElementById('cancel-reply');

                    if (parentIdInput) {
                        parentIdInput.value = commentId;
                    }
                    if (contentTextarea) {
                        contentTextarea.value = '@' + username + ' ';
                        contentTextarea.focus();
                    }
                    if (cancelReplyBtn) {
                        cancelReplyBtn.classList.remove('d-none');
                    }
                });
            });
            
            // 删除按钮事件
            document.querySelectorAll('.delete-comment:not([data-bound])').forEach(btn => {
                btn.setAttribute('data-bound', 'true');
                btn.addEventListener('click', function() {
                    if (!confirm('确定要删除这条评论吗？')) {
                        return;
                    }

                    const commentId = this.dataset.commentId;

                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=delete&comment_id=' + commentId + '&csrf_token=' + encodeURIComponent(document.querySelector('#comment-form input[name="csrf_token"]').value)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // 移除评论元素
                            const commentElement = document.getElementById('comment-' + commentId);
                            if (commentElement) {
                                commentElement.remove();
                            }

                            // 更新评论计数
                            const commentCountEl = document.getElementById('comment-count');
                            if (commentCountEl) {
                                const currentCount = parseInt(commentCountEl.textContent) || 0;
                                commentCountEl.textContent = Math.max(0, currentCount - 1);
                            }
                        } else {
                            alert(data.message || '删除失败，请重试');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('删除失败，请重试');
                    });
                });
            });
        }
        
        // 页面加载动画控制
        window.addEventListener('load', function() {
            // 确保所有资源（包括图片、CSS、JS）都已加载完成
            setTimeout(function() {
                const loader = document.getElementById('page-loader');
                const pageWrapper = document.querySelector('.page-wrapper');
                
                if (loader && pageWrapper) {
                    // 隐藏加载动画
                    loader.classList.add('hidden');
                    
                    // 显示页面内容
                    pageWrapper.classList.add('loaded');
                    
                    // 动画完成后从DOM中移除加载器
                    setTimeout(function() {
                        loader.remove();
                    }, 500);
                }
            }, 500); // 添加500ms延迟，确保动画平滑
        });

        document.addEventListener('DOMContentLoaded', function() {
            const commentForm = document.getElementById('comment-form');
            const commentsList = document.getElementById('comments-list');
            const commentCount = document.getElementById('comment-count');
            const parentIdInput = document.getElementById('parent-id');
            const cancelReplyBtn = document.getElementById('cancel-reply');
            const contentTextarea = document.getElementById('comment-content');
            
            // 使用 marked.js 渲染 Markdown 内容
            const postContent = document.getElementById('post-content');
            if (postContent) {
                const markdownText = postContent.getAttribute('data-markdown');
                if (markdownText) {
                    // 在 marked 解析前，将 <color:xxx>...</color> 替换为占位符，避免被 marked 转义或吞掉
                    const colorNames = {red:'#e74c3c',blue:'#3498db',green:'#2ecc71',orange:'#e67e22',purple:'#9b59b6',pink:'#e91e63',yellow:'#f1c40f',cyan:'#00bcd4',white:'#ffffff',black:'#333333',gray:'#95a5a6',brown:'#8b4513',gold:'#ffd700',indigo:'#3f51b5',teal:'#009688',lime:'#8bc34a',coral:'#ff7f50',salmon:'#fa8072',crimson:'#dc143c',navy:'#000080'};
                    const colorPlaceholders = [];
                    let preProcessed = markdownText;
                    // 匹配 <color:xxx>内容</color>，支持换行
                    preProcessed = preProcessed.replace(/<color:([^>]+)>([\s\S]*?)<\/color>/gi, function(match, color, text) {
                        const resolvedColor = colorNames[color.toLowerCase()] || color;
                        const placeholder = '%%COLOR_' + colorPlaceholders.length + '%%';
                        colorPlaceholders.push('<span style="color:' + resolvedColor + ';font-weight:inherit">' + text + '</span>');
                        return placeholder;
                    });

                    let html = marked.parse(preProcessed);

                    // 将占位符还原为彩色 span
                    colorPlaceholders.forEach(function(span, i) {
                        // marked 可能在占位符周围添加 <p> 标签，需要处理
                        html = html.replace('<p>' + '%%COLOR_' + i + '%%' + '</p>', span);
                        html = html.replace('%%COLOR_' + i + '%%', span);
                    });

                    // 为表格添加 Bootstrap 样式和响应式包装
                    html = html.replace(/<table\b[^>]*>/g, '<div class="table-responsive"><table class="table table-bordered table-striped table-hover">');
                    html = html.replace(/<\/table>/g, '</table></div>');

                    // 为所有图片添加 loading="lazy"
                    html = html.replace(/<img /g, '<img loading="lazy" ');

                    // 为代码块添加复制按钮和语言标识 - 保留所有属性包括语言标识
                    html = html.replace(/<pre>(<code([^>]*)>)/gi, function(match, codeTag, attrs) {
                        // 提取语言名称
                        let language = '';
                        const langMatch = attrs.match(/class="language-([^"]+)"/);
                        if (langMatch) {
                            language = langMatch[1];
                        }

                        const header = language ?
                            '<div class="code-block-header">' +
                            '<span class="code-language">' + language + '</span>' +
                            '<button class="code-copy-btn" onclick="copyCode(this)">' +
                            '<i class="bi bi-clipboard"></i> 复制' +
                            '</button>' +
                            '</div>' :
                            '<div class="code-block-header">' +
                            '<button class="code-copy-btn" onclick="copyCode(this)">' +
                            '<i class="bi bi-clipboard"></i> 复制' +
                            '</button>' +
                            '</div>';

                        return '<div class="code-block-wrapper">' + header + '<pre>' + codeTag;
                    });
                    html = html.replace(/(<\/code>)<\/pre>/gi, '$1</pre></div>');
                    
                    // 处理锚点跳转 - 增强链接点击处理
                    html = html.replace(/<a\s+(?:[^>]*?\s+)?href="(#[^"]*)"(?:[^>]*?)>/gi, function(match, href) {
                        const cleanHref = href.replace(/^#/, '');
                        // 保留原始锚点文本，用于更好的匹配
                        const rawAnchor = cleanHref;
                        return `<a href="${href}" onclick="handleAnchorClick('${rawAnchor}'); return false;" data-anchor="${rawAnchor}">`;
                    });
                    
                    postContent.innerHTML = html;

                    // 禁止视频下载
                    disableVideoDownload();

                    // 处理外部链接跳转
                    setupExternalLinks();

                    // 生成标题ID以支持锚点跳转
                    generateHeaderIds();
                }
            }

            // 自动处理外部链接，使用跳转页面
            function setupExternalLinks() {
                const currentDomain = window.location.hostname;
                const links = document.querySelectorAll('a[href^="http"]:not([href*="' + currentDomain + '"])');
                
                links.forEach(link => {
                    const href = link.getAttribute('href');
                    const linkText = link.textContent || link.innerText || '外部链接';
                    
                    // 跳过已经处理过的链接
                    if (href.includes('/redirect.php')) {
                        return;
                    }
                    
                    // 为外部链接添加跳转页面
                    link.setAttribute('href', '/vendor/redirect.php?url=' + encodeURIComponent(href) + '&title=' + encodeURIComponent(linkText));
                    link.setAttribute('target', '_blank');
                    link.setAttribute('rel', 'noopener noreferrer');
                });
            }
            
            // 提交评论
            let isSubmitting = false;
            commentForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (isSubmitting) return;
                isSubmitting = true;
                
                const submitBtn = commentForm.querySelector('button[type="submit"]');
                const originalBtnText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>提交中...';
                
                const formData = new FormData(commentForm);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 显示成功动画
                        submitBtn.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>发布成功！';
                        submitBtn.classList.remove('btn-primary');
                        submitBtn.classList.add('btn-success');
                        
                        // 动画效果
                        submitBtn.style.transform = 'scale(1.1)';
                        setTimeout(() => {
                            submitBtn.style.transform = 'scale(1)';
                        }, 200);
                        
                        // 清空表单
                        setTimeout(() => {
                            commentForm.reset();
                            parentIdInput.value = '';
                            cancelReplyBtn.classList.add('d-none');
                            
                            // 动态添加新评论到列表
                            addCommentToList(data.comment);
                            
                            // 更新评论计数
                            if (commentCount) {
                                const currentCount = parseInt(commentCount.textContent) || 0;
                                commentCount.textContent = currentCount + 1;
                            }
                            
                            // 恢复按钮状态 - 重新获取按钮引用
                            const btn = document.querySelector('#comment-form button[type="submit"]');
                            if (btn) {
                                btn.disabled = false;
                                btn.innerHTML = '<i class="bi bi-send"></i> 发表评论';
                                btn.style.background = '#6c757d !important';
                                btn.classList.remove('btn-success');
                                btn.classList.add('btn-primary');
                            }
                            isSubmitting = false;
                        }, 1000);
                        
                    } else {
                        alert(data.message || '评论失败，请重试');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnText;
                        isSubmitting = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('评论失败，请重试');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                    isSubmitting = false;
                });
            });
            
            // 绑定现有评论的事件
            bindCommentEvents();
            
            // 回复评论
            document.querySelectorAll('.reply-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const commentId = this.dataset.commentId;
                    const username = this.dataset.username || '匿名用户';
                    
                    parentIdInput.value = commentId;
                    contentTextarea.value = '@' + username + ' ';
                    contentTextarea.focus();
                    
                    cancelReplyBtn.classList.remove('d-none');
                    
                    // 滚动到评论表单
                    commentForm.scrollIntoView({ behavior: 'smooth' });
                });
            });
            
            // 取消回复
            cancelReplyBtn.addEventListener('click', function() {
                parentIdInput.value = '';
                contentTextarea.value = '';
                this.classList.add('d-none');
            });
            
            // 设置外部链接跳转
            setupExternalLinks();
        });
        </script>
        
        <?php if (!empty($post['data_music_enabled']) && $post['data_music_enabled'] === 'true'): ?>
        <!-- 音乐播放器 JS -->
        <script src="<?= getMusicPlayerUrl('js') ?>"></script>
        <?php endif; ?>
        
        <!-- 页脚 -->
        <?php require_once __DIR__ . '/vendor/footer.php'; ?>
        </div> <!-- 结束 page-wrapper -->
        
    <!-- 页面加载动画控制脚本 -->
    <script>
    // 页面加载完成后隐藏加载动画
    window.addEventListener('load', function() {
        // 确保所有资源（包括图片、CSS、JS）都已加载完成
        setTimeout(function() {
            const loader = document.getElementById('page-loader');
            const pageWrapper = document.querySelector('.page-wrapper');

            if (loader && pageWrapper) {
                // 隐藏加载动画
                loader.classList.add('hidden');

                // 显示页面内容
                pageWrapper.classList.add('loaded');

                // 动画完成后从DOM中移除加载器
                setTimeout(function() {
                    loader.remove();
                }, 500);
            }
        }, 500); // 添加500ms延迟，确保动画平滑
    });
    </script>

    <!-- 图片查看器模态框 -->
    <link href="/assets/css/blog/image-viewer.css?v=<?= $blog_version ?>" rel="stylesheet">
    <div id="imageViewerModal" class="image-viewer-modal">
        <span class="close-btn" data-tooltip="关闭">&times;</span>

        <!-- 工具栏 -->
        <div class="viewer-toolbar">
            <button class="tool-btn" id="zoomInBtn" data-tooltip="放大"><i class="bi bi-zoom-in"></i></button>
            <button class="tool-btn" id="zoomOutBtn" data-tooltip="缩小"><i class="bi bi-zoom-out"></i></button>
            <button class="tool-btn" id="rotateBtn" data-tooltip="旋转"><i class="bi bi-arrow-clockwise"></i></button>
            <button class="tool-btn" id="resetBtn" data-tooltip="重置"><i class="bi bi-arrow-counterclockwise"></i></button>
        </div>

        <div class="modal-content-wrapper">
            <img class="modal-content" id="img01">
        </div>
        <div id="caption"></div>
    </div>


    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // 元素引用
        const modal = document.getElementById("imageViewerModal");
        const modalImg = document.getElementById("img01");
        const captionText = document.getElementById("caption");
        const closeBtn = document.querySelector(".image-viewer-modal .close-btn");
        const toolbar = document.querySelector(".viewer-toolbar");
        
        // 状态变量
        let scale = 1;
        let pannedX = 0;
        let pannedY = 0;
        let rotation = 0;
        let isDragging = false;
        let startX = 0;
        let startY = 0;
        
        // 更新变换
        function updateTransform() {
            modalImg.style.transform = `translate(${pannedX}px, ${pannedY}px) scale(${scale}) rotate(${rotation}deg)`;
        }
        
        // 重置状态
        function resetImage() {
            scale = 1;
            pannedX = 0;
            pannedY = 0;
            rotation = 0;
            updateTransform();
        }

        // 监听文章内容中的图片点击
        const postContent = document.getElementById('post-content');
        if (postContent) {
            postContent.addEventListener('click', function(e) {
                if (e.target.tagName === 'IMG') {
                    modal.style.display = "block";
                    modalImg.src = e.target.src;
                    captionText.innerHTML = e.target.alt || '';
                    resetImage();
                    // 禁止背景滚动
                    document.body.style.overflow = 'hidden';
                }
            });
        }

        // 关闭模态框
        function closeModal() {
            modal.style.display = "none";
            document.body.style.overflow = ''; // 恢复背景滚动
        }

        if (closeBtn) closeBtn.onclick = closeModal;

        // 工具栏功能
        document.getElementById('zoomInBtn').onclick = (e) => {
            e.stopPropagation();
            scale += 0.2;
            updateTransform();
        };
        
        document.getElementById('zoomOutBtn').onclick = (e) => {
            e.stopPropagation();
            if (scale > 0.2) {
                scale -= 0.2;
                updateTransform();
            }
        };
        
        document.getElementById('rotateBtn').onclick = (e) => {
            e.stopPropagation();
            rotation += 90;
            updateTransform();
        };
        
        document.getElementById('resetBtn').onclick = (e) => {
            e.stopPropagation();
            resetImage();
        };
        
        // 鼠标滚轮缩放
        modal.addEventListener('wheel', function(e) {
            e.preventDefault();
            const delta = e.deltaY * -0.001;
            const newScale = Math.min(Math.max(0.1, scale + delta), 10); // 限制缩放范围 0.1 - 10
            scale = newScale;
            updateTransform();
        });

        // 拖拽功能
        modalImg.addEventListener('mousedown', function(e) {
            isDragging = true;
            startX = e.clientX - pannedX;
            startY = e.clientY - pannedY;
            modalImg.classList.add('grabbing');
            e.preventDefault(); // 防止默认拖拽行为
        });

        window.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            e.preventDefault();
            pannedX = e.clientX - startX;
            pannedY = e.clientY - startY;
            updateTransform();
        });

        window.addEventListener('mouseup', function() {
            isDragging = false;
            modalImg.classList.remove('grabbing');
        });

        // 点击背景关闭 (但不能点击图片或工具栏)
        modal.onclick = function(e) {
            if (e.target === modal || e.target.classList.contains('modal-content-wrapper')) {
                closeModal();
            }
        }
        
        // 键盘事件
        document.addEventListener('keydown', function(e) {
            if (modal.style.display !== "block") return;
            
            if (e.key === "Escape") closeModal();
            if (e.key === "+" || e.key === "=") { // + 键
                scale += 0.2;
                updateTransform();
            }
            if (e.key === "-" || e.key === "_") { // - 键
                if (scale > 0.2) {
                    scale -= 0.2;
                    updateTransform();
                }
            }
            if (e.key === "r" || e.key === "R") { // R 键旋转
                rotation += 90;
                updateTransform();
            }
            if (e.key === "0") { // 0 键重置
                resetImage();
            }
        });
    });
    </script>
    
    <!-- 下载验证模态框 -->
<div class="modal fade" id="downloadVerifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">安全验证</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="downloadVerifyForm">
                    <div class="mb-3">
                        <div class="d-grid gap-2">
                            <button class="btn btn-outline-primary" type="button" id="sendCodeBtn">
                                <i class="bi bi-envelope"></i> 发送邮箱验证码
                            </button>
                        </div>
                        <div class="form-text text-center mt-2">点击发送后，验证码将发送到您的账户邮箱</div>
                        <div class="form-text text-center mt-2">首次下载可能需要重复操作两次（用于身份验证）</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">邮箱验证码</label>
                        <input type="text" class="form-control" id="verifyCode" required placeholder="6位数字验证码">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">操作密码</label>
                        <input type="password" class="form-control" id="downloadPassword" required placeholder="请输入密码">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="confirmDownloadBtn">确认下载</button>
            </div>
        </div>
    </div>
</div>

<!-- 微信分享模态框 -->
<div class="modal fade" id="wechatShareModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">微信分享</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <p class="mb-2">打开微信"扫一扫"</p>
                <div id="wechat-qrcode" class="d-flex justify-content-center mb-2"></div>
                <p class="text-muted small">扫描二维码分享至朋友圈</p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
function shareToWeibo() {
    const url = encodeURIComponent(window.location.href);
    const title = encodeURIComponent(document.title);
    window.open(`http://service.weibo.com/share/share.php?url=${url}&title=${title}`, '_blank');
}

function shareToQQ() {
    const url = encodeURIComponent(window.location.href);
    const title = encodeURIComponent(document.title);
    window.open(`http://connect.qq.com/widget/shareqq/index.html?url=${url}&title=${title}`, '_blank');
}

function shareToWechat() {
    const url = window.location.href;
    const container = document.getElementById('wechat-qrcode');
    container.innerHTML = ''; 
    new QRCode(container, {
        text: url,
        width: 180,
        height: 180
    });
    var myModal = new bootstrap.Modal(document.getElementById('wechatShareModal'));
    myModal.show();
}

function copyPageLink(element) {
    const url = window.location.href;
    
    // 尝试使用 Clipboard API
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(() => {
            showCopyFeedback(element);
        }).catch(err => {
            fallbackCopyLink(url, element);
        });
    } else {
        fallbackCopyLink(url, element);
    }
}

function fallbackCopyLink(text, element) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-9999px";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        const successful = document.execCommand('copy');
        if (successful) {
            showCopyFeedback(element);
        }
    } catch (err) {
        console.error('Copy failed', err);
    }
    
    document.body.removeChild(textArea);
}

function showCopyFeedback(element) {
    const originalHtml = element.innerHTML;
    element.innerHTML = '<i class="bi bi-check me-2"></i>已复制';
    setTimeout(() => {
        element.innerHTML = originalHtml;
    }, 2000);
}
</script>



<script>
document.addEventListener("DOMContentLoaded", function () {
    const el = document.getElementById("ai-typing-text");
    if (!el) return;

    let text = el.getAttribute("data-text") || "";
    text = text.replace(/\n/g, "<br>");

    let index = 0;
    let speed = 25;

    function type() {
        if (index < text.length) {
            if (text[index] === "<") {
                let end = text.indexOf(">", index);
                if (end !== -1) {
                    el.innerHTML += text.substring(index, end + 1);
                    index = end + 1;
                }
            } else {
                el.innerHTML += text[index];
                index++;
            }
            setTimeout(type, speed);
        } else {
            el.classList.add("typing-done");
        }
    }

    type();
});
</script>

<script>
// 图床使用情况调试信息
const imageBedDebug = <?php echo json_encode($imageBedDebug); ?>;
const imageBedEnabled = <?php echo $imageBedEnabled ? 'true' : 'false'; ?>;
const allImageMap = <?php echo json_encode(ImageMapper::getAll()); ?>;
console.log('%c=== 图床使用情况 ===', 'color: #4CAF50; font-weight: bold; font-size: 14px;');
console.log('图床功能状态:', imageBedEnabled ? '✅ 已启用' : '❌ 未启用');
console.log('文章图片数量:', imageBedDebug.length);
console.log('完整调试数据:', imageBedDebug);
const withImageBed = imageBedDebug.filter(img => img.has_image_bed).length;
const withoutImageBed = imageBedDebug.filter(img => !img.has_image_bed).length;
console.log('使用图床:', withImageBed, '| 本地加载:', withoutImageBed);
if (imageBedDebug.length > 0) {
    console.table(imageBedDebug.map(img => ({
        '文件名': img.filename,
        '图床URL': img.has_image_bed ? '✅ ' + img.image_bed_url : '❌ 无',
        '本地URL': img.local_url
    })));
}

</script>

</body>
    </html>
    <?php exit;
}

// 博客列表页 - 初始加载
recordVisit('/blog.php');

// 初始加载文章（支持服务端筛选）
$pageTitle = '博客文章';

if (isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
    $tagOnly = isset($_GET['tag_only']) && $_GET['tag_only'] === '1';
    $pageTitle = '搜索结果: ' . htmlspecialchars($searchTerm);
    
    if (!empty($searchTerm)) {
        if ($tagOnly) {
            $stmt = $db->prepare("SELECT id, title, is_pinned, is_featured, author, created_at, views, category, tags, cover_image, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE tags LIKE ? AND is_published = 1 ORDER BY is_pinned DESC, created_at DESC");
            $stmt->execute(['%' . $searchTerm . '%']);
        } else {
            $stmt = $db->prepare("SELECT id, title, is_pinned, is_featured, author, created_at, views, category, tags, cover_image, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE (title LIKE ? OR content LIKE ? OR author LIKE ? OR category LIKE ? OR tags LIKE ?) AND is_published = 1 ORDER BY is_pinned DESC, created_at DESC");
            $searchPattern = '%' . $searchTerm . '%';
            $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern]);
        }
        $posts = $stmt->fetchAll();
    } else {
         $posts = $db->query("SELECT id, title, is_pinned, is_featured, author, created_at, views, category, tags, cover_image, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE is_published = 1 ORDER BY is_pinned DESC, created_at DESC")->fetchAll();
    }
} elseif (isset($_GET['category'])) {
    $selectedCategory = $_GET['category'];
    if ($selectedCategory) {
        if ($selectedCategory === '无分类') {
            $pageTitle = '未分类文章';
            $posts = $db->query("SELECT id, title, is_pinned, is_featured, author, created_at, views, category, tags, cover_image, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE (category IS NULL OR category = '') AND is_published = 1 ORDER BY is_pinned DESC, created_at DESC")->fetchAll();
        } else {
            $pageTitle = '分类: ' . htmlspecialchars($selectedCategory);
            $stmt = $db->prepare("SELECT id, title, is_pinned, is_featured, author, created_at, views, category, tags, cover_image, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE category = ? AND is_published = 1 ORDER BY is_pinned DESC, created_at DESC");
            $stmt->execute([$selectedCategory]);
            $posts = $stmt->fetchAll();
        }
    } else {
         $posts = $db->query("SELECT id, title, is_pinned, is_featured, author, created_at, views, category, tags, cover_image, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE is_published = 1 ORDER BY is_pinned DESC, created_at DESC")->fetchAll();
    }
} else {
    // 默认列表
    $posts = $db->query("SELECT id, title, is_pinned, is_featured, author, created_at, views, category, tags, cover_image, SUBSTRING(content, 1, 200) AS content FROM blog_posts WHERE is_published = 1 ORDER BY is_pinned DESC, created_at DESC")->fetchAll();
}

// 获取所有标签（GROUP_CONCAT 聚合为单行，减少数据传输量）
$allTags = [];
$tagRow = $db->query("SELECT GROUP_CONCAT(tags SEPARATOR ',') AS all_tags
    FROM blog_posts WHERE tags IS NOT NULL AND tags != '' AND is_published = 1")->fetch();
if (!empty($tagRow['all_tags'])) {
    $allTags = array_unique(array_filter(array_map('trim', explode(',', $tagRow['all_tags']))));
}

// 为每个标签生成稳定颜色（基于标签名 hash，同标签不再随机变色）
$tagColors = [];
foreach ($allTags as $tag) {
    $tagColors[$tag] = sprintf('#%06X', crc32($tag) & 0xFFFFFF);
}

// 获取随机推荐的三篇文章（避开 ORDER BY RAND()，改用 id 随机跳跃）
$maxIdResult = $db->query("SELECT MIN(id) AS min_id, MAX(id) AS max_id FROM blog_posts WHERE is_published = 1")->fetch();
$randomRecommendedPosts = [];
if ($maxIdResult && $maxIdResult['max_id']) {
    $randStart = $maxIdResult['min_id'] + mt_rand(0, $maxIdResult['max_id'] - $maxIdResult['min_id']);
    $stmt = $db->prepare("SELECT id, title FROM blog_posts
        WHERE is_published = 1 AND id >= ? ORDER BY id ASC LIMIT 3");
    $stmt->execute([$randStart]);
    $randomRecommendedPosts = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<?php if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'): ?>
<meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
<?php endif; ?>
<title><?= e($pageTitle) ?> - <?= e($config['website_name']) ?></title>
<meta name="description" content="<?= e(!empty($config['robot_description']) ? strip_tags($config['robot_description']) : strip_tags(mb_substr($config['website_description'], 0, 160))) ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= getResourceUrl('/assets/css/bootstrap-icons.css', 'https://cdn.staticfile.net/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
<link href="/assets/css/blog/blog-common.css?v=<?= $blog_version ?>" rel="stylesheet">
<link href="/assets/css/blog/blog-list.css?v=<?= $blog_version ?>" rel="stylesheet">
<?php if (!empty($config['favicon'])): ?>
<link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
<link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
<?php endif; ?>
<?php renderMusicPlayerCSS($config); ?>
</head>
<body>
<div class="page-wrapper">

<nav class="navbar navbar-expand-lg navbar-light fixed-top" id="blog-navbar">
    <div class="container">
        <!-- 使用响应式文本，不同断点显示不同内容 -->
        <a class="navbar-brand" href="/blog.php">
            <span class="d-none d-lg-inline">博客文章 | <?= e($config['website_name']) ?></span>
            <span class="d-lg-none">博客文章</span>
        </a>

        <!-- 电脑端搜索框 -->
        <div class="d-none d-md-flex flex-grow-1 mx-4 align-items-center">
            <div class="input-group" style="max-width: 400px;">
                <span class="input-group-text">
                    <i class="bi bi-search"></i>
                </span>
                <div class="form-control-wrapper">
                    <input type="text" class="form-control" id="searchInputDesktop" placeholder="搜索文章... (Ctrl+F)" autocomplete="off">
                    <button class="btn-clear" type="button" id="clearSearchDesktop" title="清空搜索" style="display: none;">
                        <i class="bi bi-x-circle-fill"></i>
                    </button>
                </div>
                <button class="btn btn-success btn-sm" id="searchBtnDesktop">搜索</button>
            </div>
            
            <!-- 分类筛选按钮 -->
            <div class="dropdown ms-2">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" id="categoryFilterDesktop" data-bs-toggle="dropdown" aria-expanded="false" title="按分类筛选">
                    <i class="bi bi-funnel"></i> <span class="d-none d-lg-inline">分类</span>
                </button>
                <ul class="dropdown-menu" aria-labelledby="categoryFilterDesktop" style="max-height: 300px; overflow-y: auto;">
                    <li><a class="dropdown-item category-filter-item" href="#" data-category="">
                        <i class="bi bi-list-ul"></i> 全部分类
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <?php foreach ($categories as $cat): ?>
                    <li><a class="dropdown-item category-filter-item" href="#" data-category="<?= e($cat['name']) ?>">
                        <span class="category-dot me-2" style="background-color: <?= e($cat['color']) ?>"></span>
                        <?= e($cat['name']) ?>
                    </a></li>
                    <?php endforeach; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item category-filter-item" href="#" data-category="无分类">
                        <i class="bi bi-question-circle text-muted me-2"></i> 无分类
                    </a></li>
                </ul>
            </div>
        </div>

        <div class="ms-auto d-flex align-items-center">
            <a class="btn btn-outline-secondary btn-sm me-2" href="/">返回首页</a>
            <?php if (isset($_SESSION['user_id'])): ?>
            <div class="dropdown">
                <a class="btn btn-link text-decoration-none dropdown-toggle text-dark btn-sm d-flex align-items-center" href="#" id="userDropdownList" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle me-1"></i> <?= htmlspecialchars($_SESSION['user_username'] ?? 'User') ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdownList">
                    <li><span class="dropdown-item-text">
                        <?php if (($_SESSION['user_role'] ?? 'user') === 'admin'): ?>
                        <span class="badge bg-danger">管理员</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">普通用户</span>
                        <?php endif; ?>
                    </span></li>
                    <li><hr class="dropdown-divider"></li>
                    <?php if (($_SESSION['user_role'] ?? 'user') === 'admin'): ?>
                    <li><a class="dropdown-item" href="/admin"><i class="bi bi-gear"></i> 管理后台</a></li>
                    <?php endif; ?>
                    <li><a class="dropdown-item" href="/vendor/profile.php"><i class="bi bi-person"></i> 个人中心</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right"></i> 退出登录</button>
                        </form>
                    </li>
                </ul>
            </div>
            <?php else: ?>
                <a href="/vendor/login.php?redirect_url=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-outline-secondary btn-sm me-2">登录</a>
                <a href="/vendor/register.php?redirect_url=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-primary btn-sm" style="background: #6c757d !important;">注册</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container my-5 main-content">
    <h1 class="visually-hidden"><?= $pageTitle ?></h1>
    <!-- 手机端搜索框 -->
    <div class="row mb-4 d-md-none">
        <div class="col-12">
            <div class="input-group mb-2">
                <span class="input-group-text">
                    <i class="bi bi-search"></i>
                </span>
                <div class="form-control-wrapper">
                    <input type="text" class="form-control" id="searchInputMobile" placeholder="搜索文章..." autocomplete="off">
                    <button class="btn-clear" type="button" id="clearSearchMobile" title="清空搜索" style="display: none;">
                        <i class="bi bi-x-circle-fill"></i>
                    </button>
                </div>
                <button class="btn btn-success" id="searchBtnMobile">搜索</button>
            </div>
            
            <!-- 手机端分类筛选 -->
            <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle w-100" type="button" id="categoryFilterMobile" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-funnel"></i> 按分类筛选
                </button>
                <ul class="dropdown-menu w-100" aria-labelledby="categoryFilterMobile" style="max-height: 300px; overflow-y: auto;">
                    <li><a class="dropdown-item category-filter-item" href="#" data-category="">
                        <i class="bi bi-list-ul"></i> 全部分类
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <?php foreach ($categories as $cat): ?>
                    <li><a class="dropdown-item category-filter-item" href="#" data-category="<?= e($cat['name']) ?>">
                        <span class="category-dot me-2" style="background-color: <?= e($cat['color']) ?>"></span>
                        <?= e($cat['name']) ?>
                    </a></li>
                    <?php endforeach; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item category-filter-item" href="#" data-category="无分类">
                        <i class="bi bi-question-circle text-muted me-2"></i> 无分类
                    </a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- 主文章列表区域 -->
        <div class="col-lg-8 col-12">
            <!-- 视图切换工具栏 -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 text-white" id="listTitle" style="text-shadow: 1px 1px 3px rgba(0,0,0,0.8);"><i class="bi bi-list-ul"></i> 文章列表</h5>
                <div class="btn-group btn-group-sm" role="group" aria-label="视图切换">
                    <button type="button" class="btn btn-secondary" id="btn-view-list" title="列表视图" onclick="toggleBlogView('list')">
                        <i class="bi bi-list-task"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="btn-view-grid" title="网格视图" onclick="toggleBlogView('grid')">
                        <i class="bi bi-grid"></i>
                    </button>
                </div>
            </div>

            <!-- 文章列表容器 -->
            <div id="postsContainer">
                <div class="row g-4" id="postsRow">
                    <?php foreach ($posts as $post): 
                    $categoryColor = isset($categoryColors[$post['category']]) ? $categoryColors[$post['category']] : '#007bff';
                    ?>
                    <div class="col-md-12">
                        <div class="card blog-list-card" onclick="window.location.href='/blog.php?id=<?= $post['id'] ?>'">
                            <div class="row g-0">
                                <div class="col-md-8 col-12">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-1 title-container">
                                            <h2 class="h5 mb-0 fw-bold w-100">
                                                <?php if ($post['is_pinned']): ?><i class="bi bi-pin-angle-fill text-danger" title="置顶"></i><?php endif; ?>
                                                <?php if ($post['is_featured']): ?><i class="bi bi-star-fill text-warning" title="精选"></i><?php endif; ?>
                                                <a href="/blog.php?id=<?= $post['id'] ?>" class="text-decoration-none text-dark">
                                                    <?= e($post['title']) ?>
                                                </a>
                                            </h2>
                                        </div>
                                        <p class="text-muted small mb-1">
                                            <i class="bi bi-person"></i> <?= e($post['author']) ?> |
                                            <?= date('Y-m-d H:i', strtotime($post['created_at'])) ?> |
                                            <i class="bi bi-eye"></i> <?= $post['views'] ?>
                                        </p>
                                        <?php if ($post['category'] || $post['tags']): ?>
                                        <div class="mb-1 tags-container">
                                            <?php if ($post['category']): ?>
                                            <span class="badge category-badge" style="background-color: <?= $categoryColor ?>">
                                                <span class="color-dot" style="background-color: <?= adjustBrightness($categoryColor, 30) ?>"></span>
                                                <i class="bi bi-folder"></i> <?= e($post['category']) ?>
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($post['tags']): ?>
                                            <?php $tags = explode(',', $post['tags']); foreach ($tags as $tag): ?>
                                            <?php if (trim($tag)): 
                                                $tagColor = isset($categoryColors[trim($tag)]) ? $categoryColors[trim($tag)] : '#6c757d';
                                            ?>
                                            <span class="badge tag-badge" style="background-color: <?= $tagColor ?>; color: white;">
                                                <span class="tag-color-dot" style="background-color: <?= adjustBrightness($tagColor, 30) ?>"></span>
                                                <i class="bi bi-tag"></i> <?= e(trim($tag)) ?>
                                            </span>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php
                                        $previewContent = $post['content'];
                                        // 简单的 Markdown 去除逻辑
                                        $previewContent = preg_replace('/!\[.*?\]\(.*?\)/', '', $previewContent); // 图片
                                        $previewContent = preg_replace('/\[(.*?)\]\(.*?\)/', '$1', $previewContent); // 链接
                                        $previewContent = preg_replace('/#+ /', '', $previewContent); // 标题
                                        $previewContent = preg_replace('/(\*\*|__)(.*?)\1/', '$2', $previewContent); // 粗体
                                        $previewContent = preg_replace('/(\*|_)(.*?)\1/', '$2', $previewContent); // 斜体
                                        $previewContent = str_replace(['```', '`', '>'], '', $previewContent); // 代码和引用
                                        $previewContent = strip_tags($previewContent); // HTML 标签
                                        $previewContent = trim(preg_replace('/\s+/', ' ', $previewContent)); // 多余空白
                                         $previewContent = mb_substr($previewContent, 0, 50, 'UTF-8') . '...';
                                         ?>
                                         <p class="content-preview mb-2"><?= htmlspecialchars($previewContent) ?></p>
                                    </div>
                                </div>
                                <div class="col-md-4 d-none d-md-block">
                                    <div class="d-flex h-100 align-items-center justify-content-center p-2">
                                        <?php 
                                        $listCoverUrl = !empty($post['cover_image']) ? ImageMapper::getFinalUrl($post['cover_image'], $imageBedEnabled) : '';
                                        if (!empty($listCoverUrl)): ?>
                                            <img src="<?= e($listCoverUrl) ?>" class="img-fluid rounded shadow-sm" alt="<?= e($post['title']) ?>" width="300" height="120" style="max-height: 120px; object-fit: contain;" data-local-url="<?= e($post['cover_image']) ?>" loading="lazy">
                                        <?php else: ?>
                                            <picture>
                                                <source srcset="https://t.alcy.cc/ai?<?= rand() ?>&format=webp" type="image/webp">
                                                <img src="https://t.alcy.cc/ai?<?= rand() ?>&format=jpg" class="img-fluid rounded shadow-sm" alt="<?= e($post['title']) ?> (随机封面)" width="300" height="120" style="max-height: 120px; object-fit: contain;">
                                            </picture>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php 
                    // 准备列表页封面调试数据
                    // 获取所有映射表并创建快速查找表
                    $allMap = ImageMapper::getAll();
                    $urlLookup = [];
                    foreach ($allMap as $item) {
                        if (!empty($item['local_url'])) {
                            $urlLookup[$item['local_url']] = $item;
                            // 同时存储两个版本（带/和不带/）
                            $normalizedUrl = ltrim($item['local_url'], '/');
                            $urlLookup['/' . $normalizedUrl] = $item;
                            $urlLookup[$normalizedUrl] = $item;
                        }
                    }
                    
                    foreach ($posts as $post) {
                        if (!empty($post['cover_image'])) {
                            $coverUrl = $post['cover_image'];
                            // 尝试多种 URL 格式匹配
                            $info = $urlLookup[$coverUrl] ?? $urlLookup[ltrim($coverUrl, '/')] ?? $urlLookup['/' . ltrim($coverUrl, '/')] ?? null;
                            
                            $listPageCoversDebug[] = [
                                'post_id' => $post['id'],
                                'post_title' => $post['title'],
                                'local_url' => $coverUrl,
                                'has_image_bed' => $info && !empty($info['image_bed_url']),
                                'image_bed_url' => $info['image_bed_url'] ?? null,
                                'final_url' => ImageMapper::getFinalUrl($coverUrl, $imageBedEnabled)
                            ];
                        }
                    }
                    ?>
                    
                    <?php if (empty($posts)): ?>
                    <div class="col-12">
                        <div class="text-center py-5">
                            <i class="bi bi-file-text" style="font-size: 4rem; color: #6c757d;"></i>
                            <h4 class="mt-3">暂无文章</h4>
                            <p class="text-muted">当前还没有文章发布。</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>


        </div>

        <!-- 侧边栏 -->
        <div class="col-lg-4 d-none d-lg-block">
            <div class="sidebar">
                <!-- 标签云区域 -->
                <div class="card mb-3 sidebar-card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bi bi-tags me-2"></i>标签云
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="tag-cloud-container">
                            <div class="tag-carousel" id="tagCarousel">
                                <?php if (!empty($allTags)): ?>
                                    <?php foreach ($allTags as $tag): ?>
                                        <span class="tag-pill" data-tag="<?= e($tag) ?>" style="background-color: <?= $tagColors[$tag] ?>">
                                            <?= e($tag) ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted mb-0">暂无标签</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 推荐文章和友情链接区域 - 分栏显示 -->
                <div class="card mb-3 sidebar-card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center p-0 pr-2">
                        <ul class="nav nav-tabs card-header-tabs border-bottom-0 m-0" id="sidebarTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active border-top-0 border-left-0 border-right-0" id="recommended-tab" data-bs-toggle="tab" data-bs-target="#recommended" type="button" role="tab" style="border-radius: 0; padding: 12px 16px;">
                                    <i class="bi bi-bookmark-star me-1"></i>推荐文章
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link border-top-0 border-left-0 border-right-0" id="friends-tab" data-bs-toggle="tab" data-bs-target="#friends" type="button" role="tab" style="border-radius: 0; padding: 12px 16px;">
                                    <i class="bi bi-link-45deg me-1"></i>友情链接
                                </button>
                            </li>
                        </ul>
                        <?php if (!empty($friendLinks) && count($friendLinks) > 3): ?>
                        <a href="/vendor/friend-links.php" id="viewMoreBtn" class="btn btn-sm btn-outline-secondary" style="font-size: 12px; padding: 2px 8px; height: 26px; display: none; align-items: center; margin-right: 10px;">
                            查看更多友链
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="tab-content" id="sidebarTabContent">
                            <!-- 推荐文章 -->
                            <div class="tab-pane fade show active" id="recommended" role="tabpanel">
                                <?php if (!empty($randomRecommendedPosts)): ?>
                                    <ul class="list-unstyled recommended-list">
                                        <?php foreach ($randomRecommendedPosts as $recPost): ?>
                                            <li class="recommended-item">
                                                <a href="/blog.php?id=<?= $recPost['id'] ?>" class="recommended-link">
                                                    <i class="bi bi-file-text me-2"></i>
                                                    <?= e($recPost['title']) ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted mb-0">暂无推荐文章</p>
                                <?php endif; ?>
                            </div>
                            <!-- 友情链接 -->
                            <div class="tab-pane fade" id="friends" role="tabpanel">
                                <?php
                                // 随机获取3个友情链接
                                if (!empty($friendLinks)) {
                                    shuffle($friendLinks);
                                    $displayLinks = array_slice($friendLinks, 0, 3);
                                } else {
                                    $displayLinks = [];
                                }
                                ?>
                                <?php if (!empty($displayLinks)): ?>
                                    <ul class="list-unstyled recommended-list">
                                        <?php foreach ($displayLinks as $link): ?>
                                            <li class="recommended-item">
                                                <a href="/vendor/redirect.php?url=<?= urlencode($link['url']) ?>&title=<?= urlencode($link['name']) ?>" target="_blank" class="recommended-link" title="<?= htmlspecialchars($link['description'] ?? '') ?>">
                                                    <i class="bi bi-link-45deg me-2"></i>
                                                    <?= e($link['name']) ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted mb-0">暂无友情链接</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 网站运行时间区域 -->
                <div class="card sidebar-card mt-3">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history me-2"></i>Survival Time 
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($config['website_start_time'])): ?>
                            <link href="/assets/css/blog/runtime-display.css?v=<?= $blog_version ?>" rel="stylesheet">
                            <div class="website-runtime-display">
                                <div class="runtime-stats">
                                    <div class="runtime-item">
                                        <span class="runtime-number" id="runtime-days">0</span>
                                        <span class="runtime-label">天</span>
                                    </div>
                                    <div class="runtime-item">
                                        <span class="runtime-number" id="runtime-hours">0</span>
                                        <span class="runtime-label">时</span>
                                    </div>
                                    <div class="runtime-item">
                                        <span class="runtime-number" id="runtime-minutes">0</span>
                                        <span class="runtime-label">分</span>
                                    </div>
                                    <div class="runtime-item">
                                        <span class="runtime-number" id="runtime-seconds">0</span>
                                        <span class="runtime-label">秒</span>
                                    </div>
                                </div>
                                <div class="runtime-info mt-2">
                                    <small class="text-muted">
                                        <i class="bi bi-calendar-event me-1"></i>
                                        开办于 <?= date('Y年m月d日', strtotime($config['website_start_time'])) ?>
                                    </small>
                                </div>
                            </div>
                            
                            <script>
                                // 网站运行时间计算
                                function updateRuntime() {
                                    const startTime = new Date('<?= $config['website_start_time'] ?>').getTime();
                                    const now = new Date().getTime();
                                    const diff = now - startTime;
                                    
                                    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                                    const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                                    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                                    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                                    
                                    document.getElementById('runtime-days').textContent = days;
                                    document.getElementById('runtime-hours').textContent = hours;
                                    document.getElementById('runtime-minutes').textContent = minutes;
                                    document.getElementById('runtime-seconds').textContent = seconds;
                                }
                                
                                // 初始化并每秒更新
                                updateRuntime();
                                setInterval(updateRuntime, 1000);
                            </script>
                            

                        <?php else: ?>
                            <p class="text-muted mb-0">
                                <i class="bi bi-exclamation-circle me-1"></i>
                                未设置网站开办时间
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php renderMusicPlayer($config); ?>
<script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= getResourceUrl('/assets/js/marked.min.js', 'https://cdn.staticfile.net/marked/11.1.1/marked.min.js') ?>"></script>

<!-- 语法高亮库 highlight.js (auto版本，自动检测常用语言) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
hljs.configure({ignoreUnescapedHTML: true});

// 配置 marked.js 的语法高亮
marked.setOptions({
    highlight: function(code, lang) {
        // 调试：输出语言信息
        if (lang) {
            console.log('检测到语言:', lang);
        }

        if (lang && hljs.getLanguage(lang)) {
            try {
                const result = hljs.highlight(code, { language: lang });
                console.log('语法高亮成功:', lang);
                return result.value;
            } catch (err) {
                console.error('语法高亮失败:', lang, err);
            }
        }
        // 自动检测语言
        const autoResult = hljs.highlightAuto(code);
        console.log('自动检测语言:', autoResult.language);
        return autoResult.value;
    },
    breaks: true,
    gfm: true
});
});
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // 搜索功能 - 获取所有搜索相关元素
        const searchInputDesktop = document.getElementById('searchInputDesktop');
        const searchBtnDesktop = document.getElementById('searchBtnDesktop');
        const clearSearchBtnDesktop = document.getElementById('clearSearchDesktop');
        
        const searchInputMobile = document.getElementById('searchInputMobile');
        const searchBtnMobile = document.getElementById('searchBtnMobile');
        const clearSearchBtnMobile = document.getElementById('clearSearchMobile');
        
        const postsContainer = document.getElementById('postsContainer');
        
        // 搜索函数
        function performSearch(searchTerm = '', tagOnly = false) {
            // 构建URL参数
            let url = `/blog.php?ajax=1&search=${encodeURIComponent(searchTerm)}`;
            if (tagOnly) {
                url += '&tag_only=1';
            }
            
            fetch(url)
                .then(response => response.text())
                .then(html => {
                    // 更新文章列表
                    postsContainer.innerHTML = html;
                    
                    // 重新渲染内容预览
                    renderContentPreviews();
                    
                    // 高亮搜索关键词
                    if (searchTerm.trim()) {
                        highlightSearchTerm(searchTerm);
                    }
                    
                    // 重新设置外部链接
                    setupExternalLinks();
                })
                .catch(error => {
                    console.error('搜索失败:', error);
                    postsContainer.innerHTML = `
                        <div class="col-12">
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i>
                                搜索失败，请稍后重试。
                            </div>
                        </div>
                    `;
                });
        }
        
        // 分类筛选函数
        function performCategoryFilter(category = '') {
            // 发送AJAX请求
            const url = `/blog.php?ajax=1&category=${encodeURIComponent(category)}`;
            
            fetch(url)
                .then(response => response.text())
                .then(html => {
                    // 更新文章列表
                    postsContainer.innerHTML = html;
                    
                    // 重新渲染内容预览
                    renderContentPreviews();
                    
                    // 重新设置外部链接
                    setupExternalLinks();
                    
                    // 更新按钮状态
                    updateCategoryFilterButtons(category);
                })
                .catch(error => {
                    console.error('分类筛选失败:', error);
                    postsContainer.innerHTML = `
                        <div class="col-12">
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i>
                                分类筛选失败，请稍后重试。
                            </div>
                        </div>
                    `;
                });
        }
        
        // 更新分类筛选按钮状态
        function updateCategoryFilterButtons(selectedCategory) {
            // 更新桌面端按钮文本
            const desktopBtn = document.getElementById('categoryFilterDesktop');
            if (desktopBtn) {
                const btnText = desktopBtn.querySelector('.d-none.d-lg-inline');
                if (selectedCategory) {
                    desktopBtn.classList.add('category-filter-active');
                    if (btnText) btnText.textContent = selectedCategory;
                } else {
                    desktopBtn.classList.remove('category-filter-active');
                    if (btnText) btnText.textContent = '分类';
                }
            }
            
            // 更新手机端按钮文本
            const mobileBtn = document.getElementById('categoryFilterMobile');
            if (mobileBtn) {
                if (selectedCategory) {
                    mobileBtn.classList.add('category-filter-active');
                    mobileBtn.innerHTML = `<i class="bi bi-funnel"></i> ${selectedCategory}`;
                } else {
                    mobileBtn.classList.remove('category-filter-active');
                    mobileBtn.innerHTML = '<i class="bi bi-funnel"></i> 按分类筛选';
                }
            }
            
            // 更新下拉菜单项的激活状态
            document.querySelectorAll('.category-filter-item').forEach(item => {
                const itemCategory = item.getAttribute('data-category');
                if (itemCategory === selectedCategory) {
                    item.classList.add('active');
                } else {
                    item.classList.remove('active');
                }
            });
        }
        
        // 同步两个搜索框的内容
        function syncSearchInputs(sourceInput, targetInput) {
            targetInput.value = sourceInput.value;
            updateClearButtonVisibility(targetInput);
        }
        
        // 渲染内容预览
        function renderContentPreviews() {
            const contentPreviews = document.querySelectorAll('.content-preview');
            contentPreviews.forEach(element => {
                const markdownText = element.textContent.trim();
                if (markdownText) {
                    // 只渲染预览部分，限制长度
                    let html = marked.parse(markdownText);
                    // 移除 HTML 标签来获取纯文本，然后截取
                    let tempDiv = document.createElement('div');
                    tempDiv.innerHTML = html;
                    let textContent = tempDiv.textContent || tempDiv.innerText || '';
                    let preview = textContent.substring(0, 50);
                    element.innerHTML = preview + (textContent.length > 50 ? '...' : '');
                }
            });
        }
        
        // 高亮搜索关键词
        function highlightSearchTerm(searchTerm) {
            if (!searchTerm.trim()) return;
            
            const articles = document.querySelectorAll('.blog-list-card');
            articles.forEach(article => {
                // 高亮标题
                const title = article.querySelector('h5');
                if (title) {
                    highlightText(title, searchTerm);
                }
                
                // 高亮内容预览
                const preview = article.querySelector('.content-preview');
                if (preview) {
                    highlightText(preview, searchTerm);
                }
                
                // 高亮作者
                const author = article.querySelector('.text-muted.small');
                if (author) {
                    highlightText(author, searchTerm);
                }
                
                // 高亮分类和标签
                const badges = article.querySelectorAll('.badge');
                badges.forEach(badge => {
                    highlightText(badge, searchTerm);
                });
            });
        }
        
        // 高亮文本中的关键词
        function highlightText(element, searchTerm) {
            const text = element.textContent;
            const regex = new RegExp(`(${escapeRegExp(searchTerm)})`, 'gi');
            
            if (regex.test(text)) {
                const highlightedText = text.replace(regex, '<span class="search-highlight">$1</span>');
                element.innerHTML = highlightedText;
            }
        }
        
        // 转义正则表达式特殊字符
        function escapeRegExp(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
        
        // 监听输入框内容变化，控制清除按钮显示
        function updateClearButtonVisibility(input) {
            const clearBtn = input.id === 'searchInputDesktop' ? clearSearchBtnDesktop : clearSearchBtnMobile;
            if (input.value.trim()) {
                clearBtn.style.display = 'block';
            } else {
                clearBtn.style.display = 'none';
            }
        }
        
        // 绑定桌面端搜索事件
        if (searchInputDesktop && searchBtnDesktop && clearSearchBtnDesktop) {
            searchBtnDesktop.addEventListener('click', function() {
                const searchTerm = searchInputDesktop.value.trim();
                performSearch(searchTerm);
                syncSearchInputs(searchInputDesktop, searchInputMobile);
            });
            
            searchInputDesktop.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    const searchTerm = searchInputDesktop.value.trim();
                    performSearch(searchTerm);
                    syncSearchInputs(searchInputDesktop, searchInputMobile);
                }
            });
            
            clearSearchBtnDesktop.addEventListener('click', function() {
                searchInputDesktop.value = '';
                searchInputMobile.value = '';
                performSearch('');
                searchInputDesktop.focus();
                updateClearButtonVisibility(searchInputDesktop);
                updateClearButtonVisibility(searchInputMobile);
            });
            
            searchInputDesktop.addEventListener('input', function() {
                updateClearButtonVisibility(searchInputDesktop);
                syncSearchInputs(searchInputDesktop, searchInputMobile);
            });
        }
        
        // 绑定移动端搜索事件
        if (searchInputMobile && searchBtnMobile && clearSearchBtnMobile) {
            searchBtnMobile.addEventListener('click', function() {
                const searchTerm = searchInputMobile.value.trim();
                performSearch(searchTerm);
                syncSearchInputs(searchInputMobile, searchInputDesktop);
            });
            
            searchInputMobile.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    const searchTerm = searchInputMobile.value.trim();
                    performSearch(searchTerm);
                    syncSearchInputs(searchInputMobile, searchInputDesktop);
                }
            });
            
            clearSearchBtnMobile.addEventListener('click', function() {
                searchInputMobile.value = '';
                searchInputDesktop.value = '';
                performSearch('');
                searchInputMobile.focus();
                updateClearButtonVisibility(searchInputMobile);
                updateClearButtonVisibility(searchInputDesktop);
            });
            
            searchInputMobile.addEventListener('input', function() {
                updateClearButtonVisibility(searchInputMobile);
                syncSearchInputs(searchInputMobile, searchInputDesktop);
            });
        }
        
        // 搜索快捷键
        document.addEventListener('keydown', function(e) {
            // Ctrl+F 或 Ctrl+K 聚焦搜索框
            if ((e.ctrlKey || e.metaKey) && (e.key === 'f' || e.key === 'k')) {
                e.preventDefault();
                // 优先聚焦桌面端搜索框，如果不存在则聚焦移动端
                const targetInput = window.innerWidth >= 768 ? searchInputDesktop : searchInputMobile;
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
                }
            }
            
            // ESC 键清空搜索
            const activeElement = document.activeElement;
            if (e.key === 'Escape' && (activeElement === searchInputDesktop || activeElement === searchInputMobile)) {
                if (searchInputDesktop) searchInputDesktop.value = '';
                if (searchInputMobile) searchInputMobile.value = '';
                performSearch('');
                activeElement.blur();
                if (searchInputDesktop) updateClearButtonVisibility(searchInputDesktop);
                if (searchInputMobile) updateClearButtonVisibility(searchInputMobile);
            }
        });
        
        // 初始化：渲染博客列表中的内容预览
        renderContentPreviews();
        
        // 初始化清除按钮可见性
        if (searchInputDesktop) updateClearButtonVisibility(searchInputDesktop);
        if (searchInputMobile) updateClearButtonVisibility(searchInputMobile);
        
        // 绑定分类筛选事件
        document.querySelectorAll('.category-filter-item').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const category = this.getAttribute('data-category');
                performCategoryFilter(category);
                
                // 清空搜索框
                if (searchInputDesktop) {
                    searchInputDesktop.value = '';
                    updateClearButtonVisibility(searchInputDesktop);
                }
                if (searchInputMobile) {
                    searchInputMobile.value = '';
                    updateClearButtonVisibility(searchInputMobile);
                }
            });
        });
        
        // 处理外部链接跳转
        setupExternalLinks();
        
        // 生成标题ID以支持锚点跳转
        function generateHeaderIds() {
            const postContent = document.getElementById('post-content');
            if (!postContent) return;

            const headings = postContent.querySelectorAll('h1, h2, h3, h4, h5, h6');
            const usedIds = new Set();

            headings.forEach((heading, index) => {
                const originalText = heading.textContent.trim();
                let id = heading.getAttribute('id');

                // 如果没有ID，生成一个
                if (!id) {
                    // 生成基础ID - 改进中文字符处理
                    let baseId = originalText
                        .trim()
                        .toLowerCase()
                        // 保留中文字符、字母、数字、空格、连字符
                        .replace(/[^\w\u4e00-\u9fa5\s-]/g, '')
                        .replace(/\s+/g, '-') // 空格替换为连字符
                        .replace(/-+/g, '-') // 多个连字符合并为一个
                        .trim();

                    // 确保ID不为空
                    if (!baseId) {
                        baseId = 'heading-' + index;
                    }

                    // 如果ID已被使用，添加数字后缀
                    let finalId = baseId;
                    let suffix = 1;
                    while (usedIds.has(finalId)) {
                        finalId = baseId + '-' + suffix;
                        suffix++;
                    }

                    usedIds.add(finalId);
                    heading.id = finalId;
                }
            });
        }

        generateHeaderIds();

        // 标签轮播初始化和功能
        function initTagCarousel() {
            const tagCarousel = document.getElementById('tagCarousel');
            if (!tagCarousel) return;

            const tags = tagCarousel.querySelectorAll('.tag-pill');
            if (tags.length === 0) return;

            // 将标签分成两行
            const tagRows = [[], []];
            tags.forEach((tag, index) => {
                tagRows[index % 2].push(tag);
            });

            // 重新组织标签为两行结构
            tagCarousel.innerHTML = '';
            tagRows.forEach((row, rowIndex) => {
                const rowDiv = document.createElement('div');
                rowDiv.className = 'tag-row';

                // 复制标签以实现无缝滚动效果
                const duplicatedRow = [...row, ...row];
                duplicatedRow.forEach(tag => {
                    const clonedTag = tag.cloneNode(true);
                    rowDiv.appendChild(clonedTag);
                });

                tagCarousel.appendChild(rowDiv);
            });

            // 添加标签点击事件（搜索功能）
            const allTagsInCarousel = tagCarousel.querySelectorAll('.tag-pill');
            allTagsInCarousel.forEach(tag => {
                tag.addEventListener('click', function() {
                    const tagName = this.getAttribute('data-tag');
                    if (searchInputDesktop) {
                        searchInputDesktop.value = tagName;
                        performSearch(tagName, true); // 第二个参数为true表示只搜索标签
                        updateClearButtonVisibility(searchInputDesktop);
                    }
                    if (searchInputMobile) {
                        searchInputMobile.value = tagName;
                        updateClearButtonVisibility(searchInputMobile);
                    }
                    
                    // 滚动到页面顶部
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                });
            });
        }

        // 初始化标签轮播
        initTagCarousel();
    });
    
    // 全局函数：处理页面内锚点跳转（适用于页面加载时的URL锚点）
    function handlePageAnchor() {
        if (window.location.hash) {
            const anchorId = window.location.hash.substring(1);
            setTimeout(() => {
                handleAnchorClick(anchorId);
            }, 500); // 延迟执行以确保内容已渲染
        }
    }
    
    // 页面加载完成后处理URL中的锚点
    window.addEventListener('load', function() {
        handlePageAnchor();
    });
    
        // 监听hash变化
        window.addEventListener('hashchange', function() {
            handlePageAnchor();
        });
        
        // 移动端推荐文章卡片点击事件
        // 页面加载动画控制
        window.addEventListener('load', function() {
            // 确保所有资源（包括图片、CSS、JS）都已加载完成
            setTimeout(function() {
                const loader = document.getElementById('page-loader');
                const pageWrapper = document.querySelector('.page-wrapper');
                
                if (loader && pageWrapper) {
                    // 隐藏加载动画
                    loader.classList.add('hidden');
                    
                    // 显示页面内容
                    pageWrapper.classList.add('loaded');
                    
                    // 动画完成后从DOM中移除加载器
                    setTimeout(function() {
                        loader.remove();
                    }, 500);
                }
            }, 500); // 添加500ms延迟，确保动画平滑
        });

        document.addEventListener('DOMContentLoaded', function() {
            function setupMobileCardClick() {
                const recommendationCards = document.querySelectorAll('.recommended-posts .post-recommendation-card');
                recommendationCards.forEach(card => {
                    // 移除旧的事件监听器
                    card.removeEventListener('click', handleCardClick);
                    // 添加新的事件监听器
                    card.addEventListener('click', handleCardClick);
                    
                    // 确保标题链接可点击
                    const titleLink = card.querySelector('.card-title a');
                    if (titleLink) {
                        titleLink.addEventListener('click', function(e) {
                            if (window.innerWidth <= 768) {
                                e.stopPropagation();
                                window.location.href = this.href;
                            }
                        });
                    }
                });
            }
            
            function handleCardClick(e) {
                if (window.innerWidth <= 768) {
                    e.preventDefault();
                    e.stopPropagation();
                    const link = this.querySelector('.card-title a');
                    if (link) {
                        window.location.href = link.href;
                    }
                }
            }
            
            // 初始设置
            if (window.innerWidth <= 768) {
                setupMobileCardClick();
            }
            
            // 监听窗口大小变化
            window.addEventListener('resize', function() {
                if (window.innerWidth <= 768) {
                    setupMobileCardClick();
                }
            });
        });
        
        // 自动处理外部链接，使用跳转页面
    function setupExternalLinks() {
        const currentDomain = window.location.hostname;
        const links = document.querySelectorAll('a[href^="http"]:not([href*="' + currentDomain + '"])');
        
        links.forEach(link => {
            const href = link.getAttribute('href');
            const linkText = link.textContent || link.innerText || '外部链接';
            
            // 跳过已经处理过的链接
            if (href.includes('/redirect.php')) {
                return;
            }
            
            // 为外部链接添加跳转页面
            link.setAttribute('href', '/vendor/redirect.php?url=' + encodeURIComponent(href) + '&title=' + encodeURIComponent(linkText));
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener noreferrer');
        });
    }
    
    // 页面加载完成后设置外部链接
    document.addEventListener('DOMContentLoaded', function() {
        setupExternalLinks();
        
        // 监听标签切换,控制"查看更多"按钮的显示
        const viewMoreBtn = document.getElementById('viewMoreBtn');
        const friendsTab = document.getElementById('friends-tab');
        const recommendedTab = document.getElementById('recommended-tab');
        const sidebarTab = document.getElementById('sidebarTab');
        
        if (viewMoreBtn && friendsTab && recommendedTab && sidebarTab) {
            function updateBtnVisibility() {
                // 只有在友情链接标签激活时才进行空间判断
                if (friendsTab.classList.contains('active')) {
                    const parentWidth = sidebarTab.parentElement.clientWidth;
                    const tabsWidth = sidebarTab.offsetWidth;
                    // 预留 110px 给按钮 (按钮约 90-100px)
                    if (parentWidth - tabsWidth < 110) {
                        viewMoreBtn.style.display = 'none';
                    } else {
                        viewMoreBtn.style.display = 'inline-flex';
                    }
                } else {
                    viewMoreBtn.style.display = 'none';
                }
            }

            // 监听标签显示事件
            friendsTab.addEventListener('shown.bs.tab', updateBtnVisibility);
            recommendedTab.addEventListener('shown.bs.tab', updateBtnVisibility);
            
            // 监听窗口调整
            window.addEventListener('resize', updateBtnVisibility);
            
            // 初始运行一次
            updateBtnVisibility();
        }
    });
    
    // 导航栏滚动效果 - 固定导航栏并添加阴影
    window.addEventListener('scroll', function() {
        const navbar = document.querySelector('.navbar.fixed-top');
        if (navbar) {
            if (window.scrollY > 10) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        }
    });

    // 复制时附加来源说明（支持右键复制与快捷键复制）
    document.addEventListener('copy', function(e) {
        try {
            const sel = document.getSelection();
            if (!sel || sel.isCollapsed) return;

            const anchorNode = sel.anchorNode || sel.focusNode;
            if (!anchorNode) return;

            const selectedText = sel.toString();

            const siteUrl = <?php echo json_encode((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>;
            const siteName = <?php echo json_encode($config['website_name'] ?? '未命名网站'); ?>;

            const appendix = "\n\n——\n来源：" + siteUrl + "\n网站：" + siteName;

            e.preventDefault();
            const clipboard = e.clipboardData || window.clipboardData;
            clipboard.setData('text/plain', selectedText + appendix);

            // 同时设置 HTML 格式（将换行替换为 <br>）
            const htmlSelected = selectedText.replace(/\n/g, '<br>');
            const htmlAppend = '<br><br><small>——<br>来源：' + siteUrl + '<br>网站：' + siteName + '</small>';
            clipboard.setData('text/html', htmlSelected + htmlAppend);
        } catch (err) {
            console.error('copy handler error', err);
        }
    });

    // 复制代码块功能
    function copyCode(button) {
        const codeBlock = button.closest('.code-block-wrapper').querySelector('code');
        const codeText = codeBlock.textContent;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(codeText).then(function() {
                showCopySuccess(button);
            }).catch(function(err) {
                console.error('复制失败:', err);
                fallbackCopy(codeText, button);
            });
        } else {
            fallbackCopy(codeText, button);
        }
    }

    function fallbackCopy(text, button) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '0';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showCopySuccess(button);
            } else {
                console.error('复制失败');
            }
        } catch (err) {
            console.error('复制失败:', err);
        }

        document.body.removeChild(textArea);
    }

    function showCopySuccess(button) {
        const originalContent = button.innerHTML;
        button.innerHTML = '<i class="bi bi-check"></i> 已复制';
        button.classList.add('copied');

        setTimeout(function() {
            button.innerHTML = originalContent;
            button.classList.remove('copied');
        }, 2000);
    }

    // 视图切换功能
    function toggleBlogView(mode) {
        const container = document.getElementById('postsContainer');
        const btnList = document.getElementById('btn-view-list');
        const btnGrid = document.getElementById('btn-view-grid');
        
        if (mode === 'grid') {
            container.classList.add('view-grid');
            btnList.className = 'btn btn-outline-secondary';
            btnGrid.className = 'btn btn-secondary';
            localStorage.setItem('blog_view_mode', 'grid');
        } else {
            container.classList.remove('view-grid');
            btnGrid.className = 'btn btn-outline-secondary';
            btnList.className = 'btn btn-secondary';
            localStorage.setItem('blog_view_mode', 'list');
        }
    }

    // 初始化视图模式
    document.addEventListener('DOMContentLoaded', function() {
        const savedMode = localStorage.getItem('blog_view_mode') || 'list';
        toggleBlogView(savedMode);
    });
</script>

<script>
// =============================================
// 列表页 - 图床封面调试信息
// =============================================
(function() {
    const listPageCovers = <?php echo json_encode($listPageCoversDebug); ?>;
    const listPageImageBedEnabled = <?php echo $imageBedEnabled ? 'true' : 'false'; ?>;
    const listPageAllMapObj = <?php echo json_encode(ImageMapper::getAll()); ?>;
    
    // 将对象转换为数组
    const listPageAllMap = Object.values(listPageAllMapObj);
    
    // 创建 URL 到映射信息的快速查找表
    const urlMap = {};
    listPageAllMap.forEach(item => {
        if (item.local_url) {
            urlMap[item.local_url] = item;
            // 也存储不带开头的版本
            if (item.local_url.startsWith('/')) {
                urlMap[item.local_url.substring(1)] = item;
            } else {
                urlMap['/' + item.local_url] = item;
            }
        }
    });
    
    console.log('%c=== 文章列表页 - 封面图床使用情况 ===', 'color: #2196F3; font-weight: bold; font-size: 14px;');
    console.log('图床功能状态:', listPageImageBedEnabled ? '✅ 已启用' : '❌ 未启用');
    console.log('图床映射表总数:', listPageAllMap.length);
    console.log('文章封面数量:', listPageCovers.length);
    
    if (listPageCovers.length > 0) {
        // 使用正确的映射信息重新计算状态
        const correctedCovers = listPageCovers.map(item => {
            const normalizedUrl = item.local_url.startsWith('/') ? item.local_url : '/' + item.local_url;
            const info = urlMap[item.local_url] || urlMap[normalizedUrl] || urlMap[item.local_url.replace(/^\//, '')];
            return {
                ...item,
                corrected_has_image_bed: info && info.image_bed_url ? true : false,
                corrected_image_bed_url: info ? info.image_bed_url : null
            };
        });
        
        console.table(correctedCovers.map(item => ({
            '文章ID': item.post_id,
            '文章标题': item.post_title.substring(0, 20) + (item.post_title.length > 20 ? '...' : ''),
            '封面状态': item.corrected_has_image_bed ? '✅ 图床' : '❌ 本地',
            '本地URL': item.local_url,
            '图床URL': item.corrected_image_bed_url || '无'
        })));
        
        const usingImageBed = correctedCovers.filter(c => c.corrected_has_image_bed).length;
        const usingLocal = correctedCovers.filter(c => !c.corrected_has_image_bed).length;
        console.log('封面统计: 图床=' + usingImageBed + ' | 本地=' + usingLocal);
        
        // 如果有未匹配的，显示警告
        const unmatched = correctedCovers.filter(c => !c.corrected_has_image_bed && !urlMap[c.local_url] && !urlMap['/' + c.local_url]);
        if (unmatched.length > 0) {
            console.warn('⚠️ 以下封面在映射表中未找到匹配记录:');
            unmatched.forEach(c => {
                console.warn('  - [' + c.post_id + '] ' + c.post_title + ': ' + c.local_url);
            });
        }
    }
    
    // 检查封面图片加载情况
    document.querySelectorAll('.blog-list-card img[data-local-url]').forEach(img => {
        const localUrl = img.getAttribute('data-local-url');
        const normalizedUrl = localUrl.startsWith('/') ? localUrl.substring(1) : '/' + localUrl;
        const info = urlMap[localUrl] || urlMap[normalizedUrl];
        
        img.addEventListener('error', function() {
            console.error('❌ 封面加载失败:', {
                '文章封面': localUrl,
                '映射信息': info ? '有' : '无',
                '图床URL': info ? info.image_bed_url : '无',
                '实际加载': this.src
            });
        });
        
        img.addEventListener('load', function() {
            const isImageBed = info && info.image_bed_url && this.src.startsWith('http');
            console.log(isImageBed ? '✅ 封面(图床)' : '✅ 封面(本地)', localUrl, '->', this.src.substring(0, 60) + '...');
        });
    });
})();
</script>

<!-- 页脚 -->
<?php require_once __DIR__ . '/vendor/footer.php'; ?>
</div> <!-- 结束 page-wrapper -->


<script>
document.addEventListener("DOMContentLoaded", function () {
    const el = document.getElementById("ai-typing-text");
    if (!el) return;

    let text = el.getAttribute("data-text") || "";
    text = text.replace(/\n/g, "<br>");

    let index = 0;
    let speed = 25;

    function type() {
        if (index < text.length) {
            if (text[index] === "<") {
                let end = text.indexOf(">", index);
                if (end !== -1) {
                    el.innerHTML += text.substring(index, end + 1);
                    index = end + 1;
                }
            } else {
                el.innerHTML += text[index];
                index++;
            }
            setTimeout(type, speed);
        } else {
            el.classList.add("typing-done");
        }
    }

    type();
});
</script>

</script>

</body>
</html>