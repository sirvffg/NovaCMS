<?php
/**
 * 图片映射表管理
 * 分散存储在 uploads 各子目录中
 */

class ImageMapper {
    private static $currentDir = null;
    private static $map = [];

    /**
     * 初始化当前目录的映射表
     * @param string $localPath 本地文件路径
     */
    private static function init($localPath = '') {
        if (empty($localPath)) {
            // 调试：输出 __DIR__ 和计算出的路径
            error_log('ImageMapper init - __DIR__: ' . __DIR__);
            error_log('ImageMapper init - dirname(__DIR__, 2): ' . dirname(__DIR__, 2));
            self::$currentDir = dirname(__DIR__, 2) . '/uploads';
            error_log('ImageMapper init - uploadsDir: ' . self::$currentDir);
        } else {
            // 获取文件所在目录
            $dir = dirname($localPath);
            // 确保目录在 uploads 下
            $uploadsDir = dirname(__DIR__, 2) . '/uploads';
            if (strpos($dir, $uploadsDir) === 0) {
                self::$currentDir = $dir;
            } else {
                self::$currentDir = $uploadsDir;
            }
        }

        self::load();
    }

    /**
     * 获取映射文件路径
     */
    private static function getMapFilePath() {
        return self::$currentDir . '/.image_map.json';
    }

    /**
     * 加载映射表
     */
    private static function load() {
        $mapFile = self::getMapFilePath();
        if (file_exists($mapFile)) {
            $content = file_get_contents($mapFile);
            self::$map = json_decode($content, true) ?: [];
        } else {
            self::$map = [];
        }
    }

    /**
     * 保存映射表
     */
    private static function save() {
        $mapFile = self::getMapFilePath();

        // 确保目录存在
        if (!is_dir(self::$currentDir)) {
            mkdir(self::$currentDir, 0755, true);
        }

        file_put_contents($mapFile, json_encode(self::$map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 获取文件在映射表中的唯一键
     * 统一使用正斜杠，避免 Windows/Linux 路径格式不一致导致 MD5 不匹配
     */
    private static function getKey($localPath) {
        // 统一转换为正斜杠
        $normalizedPath = str_replace('\\', '/', $localPath);
        return md5($normalizedPath);
    }
    
    /**
     * 通过 local_url 获取映射表的 key
     */
    private static function getKeyByUrl($url) {
        // local_url 格式：/uploads/posts/xxx.png
        // 需要转换为 local_path 格式来计算 key
        $uploadsDir = str_replace('\\', '/', dirname(__DIR__, 2)) . '/uploads';
        $localPath = $uploadsDir . $url;
        return self::getKey($localPath);
    }

    /**
     * 添加映射
     * @param string $localPath 本地文件路径
     * @param string $localUrl 本地URL
     * @param string $imageBedUrl 图床URL
     * @param string $filename 文件名
     * @param int $imageBedId 图床图片ID
     */
    public static function add($localPath, $localUrl, $imageBedUrl = '', $filename = '', $imageBedId = 0) {
        // 统一路径格式为正斜杠
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

    /**
     * 更新图床URL和ID
     * @param string $localPath 本地文件路径
     * @param string $imageBedUrl 图床URL
     * @param int $imageBedId 图床图片ID
     */
    public static function updateImageBedUrl($localPath, $imageBedUrl, $imageBedId = 0) {
        // 统一路径格式为正斜杠
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

    /**
     * 获取图片信息
     * @param string $localPathOrUrl 本地路径或URL
     */
    public static function get($localPathOrUrl) {
        // 先尝试通过URL查找
        $info = self::getByUrl($localPathOrUrl);
        if ($info) {
            return $info;
        }

        // 如果 URL 查找失败，尝试遍历所有映射表用 local_url 匹配
        $urlToMatch = $localPathOrUrl;
        if (strpos($urlToMatch, '/uploads/') === false) {
            // 如果不是 URL 格式，尝试构建 URL
            $uploadsDir = str_replace('\\', '/', dirname(__DIR__, 2)) . '/uploads';
            $uploadsDir = rtrim($uploadsDir, '/');
            if (strpos($urlToMatch, $uploadsDir) === 0) {
                $urlToMatch = '/uploads' . str_replace($uploadsDir, '', $urlToMatch);
            }
        }
        $urlToMatch = str_replace('\\', '/', $urlToMatch);
        
        // 遍历所有映射文件查找
        $allMap = self::getAll();
        foreach ($allMap as $item) {
            if (!empty($item['local_url']) && $item['local_url'] === $urlToMatch) {
                return $item;
            }
        }

        return null;
    }

    /**
     * 通过URL查找映射信息
     * @param string $url 图片URL（如 /uploads/posts/xxx.png）
     */
    public static function getByUrl($url) {
        $url = str_replace('\\', '/', $url);
        
        // 获取 uploads 目录
        $uploadsDir = str_replace('\\', '/', dirname(__DIR__, 2)) . '/uploads';
        
        // 提取相对路径和子目录
        $relativePath = ltrim(str_replace('/uploads/', '', $url), '/');
        $parts = explode('/', $relativePath);
        array_pop($parts); // 移除文件名，得到子目录
        $subDir = implode('/', $parts);
        
        // 构建目标目录
        $targetDir = $uploadsDir;
        if (!empty($subDir)) {
            $targetDir = $uploadsDir . '/' . $subDir;
        }
        $targetDir = str_replace('\\', '/', $targetDir);
        
        // 用 local_url 计算 key（在 uploads 目录下）
        $key = self::getKey($uploadsDir . $url);
        
        // 初始化并查找
        self::init($targetDir);
        if (isset(self::$map[$key])) {
            return self::$map[$key];
        }
        
        // 尝试在 uploads 根目录查找
        self::init($uploadsDir);
        if (isset(self::$map[$key])) {
            return self::$map[$key];
        }
        
        // 如果本地查找失败，遍历所有映射文件用 local_url 精确匹配
        // 这解决了服务器路径与本地路径不一致的问题
        $allMap = self::getAll();
        foreach ($allMap as $item) {
            if (!empty($item['local_url']) && $item['local_url'] === $url) {
                return $item;
            }
        }

        return null;
    }

    /**
     * 获取最终URL（根据配置返回本地或图床URL）
     * @param string $localUrl 本地URL
     * @param bool $useImageBed 是否使用图床
     */
    public static function getFinalUrl($localUrl, $useImageBed = false) {
        $info = self::getByUrl($localUrl);

        if ($info && $useImageBed && !empty($info['image_bed_url'])) {
            return $info['image_bed_url'];
        }

        return $localUrl;
    }

    /**
     * 转换内容中的图片URL（添加fallback支持）
     * @param string $content 内容
     * @param bool $useImageBed 是否使用图床
     */
    public static function convertContent($content, $useImageBed = false) {
        if (!$useImageBed) {
            return $content;
        }

        // 匹配 Markdown 图片语法 ![alt](url)
        $content = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/', function($matches) use ($useImageBed) {
            $alt = $matches[1];
            $url = $matches[2];

            // 只处理本地图片
            if (strpos($url, '/uploads/') !== false) {
                $info = self::getByUrl($url);
                // 只有存在图床URL时才替换，否则保持原样
                if ($info && !empty($info['image_bed_url'])) {
                    // 转换为HTML img标签以便添加onerror（避免使用||，防止Markdown表格解析错误）
                    return '<img src="' . $info['image_bed_url'] . '" alt="' . htmlspecialchars($alt) . '" data-local-url="' . htmlspecialchars($url) . '" onerror="if(this.dataset.localUrl)this.src=this.dataset.localUrl;this.onerror=null;">';
                }
                return $matches[0];
            }
            return $matches[0];
        }, $content);

        // 匹配 HTML img 标签（处理已存在的标签）
        $content = preg_replace_callback('/<img([^>]*?)src=["\']([^"\']+)["\']([^>]*)>/i', function($matches) use ($useImageBed) {
            $before = $matches[1];
            $url = $matches[2];
            $after = $matches[3];

            // 只处理本地图片
            if (strpos($url, '/uploads/') !== false) {
                $info = self::getByUrl($url);
                // 只有存在图床URL时才替换
                if ($info && !empty($info['image_bed_url'])) {
                    // 检查是否已有data-local-url属性
                    if (stripos($before . $after, 'data-local-url') !== false) {
                        // 已有属性，只更新src
                        $newTag = preg_replace('/src=["\']([^"\']*)["\']/', 'src="' . $info['image_bed_url'] . '"', $matches[0]);
                        return $newTag;
                    }
                    // 添加data-local-url和onerror（避免使用||，防止Markdown表格解析错误）
                    return '<img' . $before . 'src="' . $info['image_bed_url'] . '" data-local-url="' . htmlspecialchars($url) . '" onerror="if(this.dataset.localUrl)this.src=this.dataset.localUrl;this.onerror=null;"' . $after . '>';
                }
            }
            return $matches[0];
        }, $content);

        return $content;
    }

    /**
     * 获取需要上传到图床的图片列表
     */
    public static function getPendingUploads() {
        $uploadsDir = dirname(__DIR__, 2) . '/uploads';
        $pending = [];

        // 递归遍历所有子目录
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

    /**
     * 删除映射
     * @param string $localPathOrUrl 本地路径或URL
     */
    public static function delete($localPathOrUrl) {
        $info = self::get($localPathOrUrl);
        if (!$info) {
            return false;
        }

        self::init($info['local_path']);
        $key = self::getKey($info['local_path']);
        if (isset(self::$map[$key])) {
            unset(self::$map[$key]);
            self::save();
            return true;
        }

        return false;
    }

    /**
     * 获取所有映射
     */
    public static function getAll() {
        $uploadsDir = dirname(__DIR__, 2) . '/uploads';
        $all = [];

        // 递归遍历所有子目录
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

        return $all;
    }

    /**
     * 获取映射表统计
     */
    public static function getStats() {
        $uploadsDir = dirname(__DIR__, 2) . '/uploads';
        $total = 0;
        $withImageBed = 0;
        $withoutImageBed = 0;

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

        return [
            'total' => $total,
            'with_image_bed' => $withImageBed,
            'without_image_bed' => $withoutImageBed
        ];
    }

    /**
     * 批量上传本地图片到图床
     * @param string $apiUrl 图床API地址
     * @param string $apiKey 图床API密钥
     * @param callable $progressCallback 进度回调
     */
    public static function batchUploadToImageBed($apiUrl, $apiKey, $progressCallback = null) {
        $pending = self::getPendingUploads();
        $total = count($pending);
        $current = 0;
        $success = 0;
        $failed = 0;

        foreach ($pending as $key => $item) {
            $current++;

            if ($progressCallback) {
                $progressCallback($current, $total, $item['filename']);
            }

            if (!file_exists($item['local_path'])) {
                $failed++;
                continue;
            }

            $result = self::uploadFileToImageBed($apiUrl, $apiKey, $item['local_path']);

            if ($result['success']) {
                // 更新对应目录的映射文件
                self::updateImageBedUrl($item['local_path'], $result['url']);
                $success++;
            } else {
                $failed++;
            }
        }

        return [
            'total' => $total,
            'success' => $success,
            'failed' => $failed
        ];
    }

    /**
     * 上传文件到图床
     */
    private static function uploadFileToImageBed($apiUrl, $apiKey, $filePath) {
        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => '文件不存在'];
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
            $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            $mimeType = $mimeMap[$ext] ?? 'application/octet-stream';
        }

        // 生成缩略图
        $thumbnailBase64 = self::generateThumbnail($filePath, $mimeType);
        $filename = basename($filePath);

        // 使用新API接口: /api/external/upload
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

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        $data = json_decode($response, true);

        // 新API返回格式: {success: true, url: "..."}
        if ($httpCode === 200 && isset($data['success']) && $data['success'] === true && !empty($data['url'])) {
            return ['success' => true, 'url' => $data['url'], 'id' => $data['id'] ?? 0];
        }

        // 兼容旧格式
        if ($httpCode === 200 && isset($data['code']) && $data['code'] === 200 && !empty($data['data']['url'])) {
            return ['success' => true, 'url' => $data['data']['url'], 'id' => $data['data']['id'] ?? 0];
        }

        return ['success' => false, 'error' => $data['msg'] ?? $data['error'] ?? '上传失败'];
    }

    /**
     * 生成缩略图 (最大宽度250px)
     */
    private static function generateThumbnail($filePath, $mimeType) {
        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
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
        
        $content = file_get_contents($filePath);
        return 'data:image/jpeg;base64,' . base64_encode($content);
    }

    /**
     * 扫描本地uploads目录，识别历史图片并添加到映射表
     * @param callable $progressCallback 进度回调
     * @return array 扫描结果
     */
    public static function scanLocalImages($progressCallback = null) {
        // 统一使用正斜杠
        $uploadsDir = str_replace('\\', '/', dirname(__DIR__, 2)) . '/uploads';
        $existingMap = self::getAll();

        // 构建已存在的URL索引
        $existingUrls = [];
        foreach ($existingMap as $item) {
            if (!empty($item['local_url'])) {
                $existingUrls[$item['local_url']] = true;
            }
        }

        $scanned = 0;
        $added = 0;
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        // 递归扫描目录
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $imageExtensions)) {
                    // 跳过映射文件本身
                    if ($file->getFilename() === '.image_map.json') {
                        continue;
                    }

                    $scanned++;
                    $localPath = str_replace('\\', '/', $file->getPathname());
                    $relativePath = str_replace($uploadsDir, '', $localPath);
                    $localUrl = '/uploads' . $relativePath;
                    $filename = $file->getFilename();

                    // 检查是否已存在
                    if (!isset($existingUrls[$localUrl])) {
                        if ($progressCallback) {
                            $progressCallback($filename, $scanned);
                        }
                        self::add($localPath, $localUrl, '', $filename);
                        $added++;
                    }
                }
            }
        }

        return [
            'scanned' => $scanned,
            'added' => $added,
            'existing' => count($existingUrls)
        ];
    }

    /**
     * 扫描文章内容中的图片URL，识别已存在但未在映射表中的图片
     * @param PDO $db 数据库连接
     * @param callable $progressCallback 进度回调
     * @return array 扫描结果
     */
    public static function scanPostsImages($db, $progressCallback = null) {
        $existingMap = self::getAll();

        // 构建已存在的URL索引
        $existingUrls = [];
        foreach ($existingMap as $item) {
            if (!empty($item['local_url'])) {
                $existingUrls[$item['local_url']] = true;
            }
        }

        $found = 0;
        $added = 0;

        // 获取所有文章
        $posts = $db->query("SELECT id, title, content FROM blog_posts")->fetchAll();

        foreach ($posts as $post) {
            // 匹配 Markdown 图片语法
            preg_match_all('/!\[([^\]]*)\]\(([^)]+\.(?:jpg|jpeg|png|gif|webp))\)/i', $post['content'], $matches);

            foreach ($matches[2] as $url) {
                // 只处理本地图片
                if (strpos($url, '/uploads/') !== false) {
                    $found++;
                    if (!isset($existingUrls[$url])) {
                        if ($progressCallback) {
                            $progressCallback($post['title'], $url);
                        }

                        // 构建本地路径（统一使用正斜杠）
                        $uploadsDir = str_replace('\\', '/', dirname(__DIR__, 2)) . '/uploads';
                        $localPath = $uploadsDir . str_replace('/uploads/', '/', $url);
                        $filename = basename($url);

                        self::add($localPath, $url, '', $filename);
                        $existingUrls[$url] = true;
                        $added++;
                    }
                }
            }

            // 匹配 HTML img 标签
            preg_match_all('/<img[^>]+src=["\']([^"\']+\.(?:jpg|jpeg|png|gif|webp))["\'][^>]*>/i', $post['content'], $imgMatches);

            foreach ($imgMatches[1] as $url) {
                if (strpos($url, '/uploads/') !== false && !isset($existingUrls[$url])) {
                    $found++;
                    if ($progressCallback) {
                        $progressCallback($post['title'], $url);
                    }

                    $uploadsDir = str_replace('\\', '/', dirname(__DIR__, 2)) . '/uploads';
                    $localPath = $uploadsDir . str_replace('/uploads/', '/', $url);
                    $filename = basename($url);

                    self::add($localPath, $url, '', $filename);
                    $existingUrls[$url] = true;
                    $added++;
                }
            }
        }

        return [
            'found' => $found,
            'added' => $added,
            'total_posts' => count($posts)
        ];
    }

    /**
     * 从图床删除图片
     * @param string $apiUrl 图床API地址
     * @param string $apiKey 图床API密钥
     * @param string $imageUrl 图床图片URL
     * @return array 删除结果
     */
    public static function deleteFromImageBed($apiUrl, $apiKey, $imageUrl) {
        if (empty($apiUrl) || empty($apiKey) || empty($imageUrl)) {
            return ['success' => false, 'error' => '缺少必要参数'];
        }

        // 新API接口: /api/external/delete
        $baseUrl = rtrim($apiUrl, '/');
        $deleteUrl = $baseUrl . '/api/external/delete';

        // JSON请求体
        $jsonBody = json_encode(['url' => $imageUrl]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $deleteUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        $data = json_decode($response, true);

        if ($httpCode === 200 && isset($data['success']) && $data['success'] === true) {
            return ['success' => true, 'message' => $data['message'] ?? '删除成功'];
        }

        return ['success' => false, 'error' => $data['message'] ?? $data['error'] ?? '删除失败'];
    }

    /**
     * 删除图片（本地文件 + 映射表 + 图床）
     * @param string $localUrl 本地URL
     * @param string $apiUrl 图床API地址（可选）
     * @param string $apiKey 图床API密钥（可选）
     * @return array 删除结果
     */
    public static function deleteWithFiles($localUrl, $apiUrl = '', $apiKey = '') {
        $result = [
            'local_deleted' => false,
            'local_file_deleted' => false,
            'image_bed_deleted' => false,
            'errors' => []
        ];

        // 获取图片信息
        $info = self::getByUrl($localUrl);
        if (!$info) {
            $result['errors'][] = '映射表中未找到该图片';
        } else {
            // 删除图床图片 (新API使用URL)
            if (!empty($info['image_bed_url']) && !empty($apiUrl) && !empty($apiKey)) {
                $bedResult = self::deleteFromImageBed($apiUrl, $apiKey, $info['image_bed_url']);
                if ($bedResult['success']) {
                    $result['image_bed_deleted'] = true;
                } else {
                    $result['errors'][] = '图床删除失败: ' . ($bedResult['error'] ?? '');
                }
            }

            // 删除本地文件
            $localPath = $info['local_path'] ?? '';
            if (!empty($localPath) && file_exists($localPath)) {
                if (unlink($localPath)) {
                    $result['local_file_deleted'] = true;
                } else {
                    $result['errors'][] = '本地文件删除失败';
                }
            }

            // 删除映射表记录
            if (self::delete($localUrl)) {
                $result['local_deleted'] = true;
            }
        }

        return $result;
    }

    /**
     * 从文章内容中提取所有图片URL
     * @param string $content 文章内容
     * @return array 图片URL列表
     */
    public static function extractImagesFromContent($content) {
        $images = [];

        // 匹配 Markdown 图片语法
        preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $content, $matches);
        foreach ($matches[2] as $url) {
            if (strpos($url, '/uploads/') !== false) {
                $images[] = $url;
            }
        }

        // 匹配 HTML img 标签
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $imgMatches);
        foreach ($imgMatches[1] as $url) {
            if (strpos($url, '/uploads/') !== false && !in_array($url, $images)) {
                $images[] = $url;
            }
        }

        return array_unique($images);
    }

    /**
     * 删除文章中的所有图片
     * @param string $content 文章内容
     * @param string $apiUrl 图床API地址（可选）
     * @param string $apiKey 图床API密钥（可选）
     * @return array 删除结果
     */
    public static function deleteImagesFromContent($content, $apiUrl = '', $apiKey = '') {
        $images = self::extractImagesFromContent($content);
        $results = [
            'total' => count($images),
            'deleted' => 0,
            'failed' => 0,
            'details' => []
        ];

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

    /**
     * 检查图片是否被多篇文章使用
     * @param string $localUrl 本地URL
     * @param PDO $db 数据库连接
     * @param int|null $excludePostId 排除的文章ID（删除某篇文章时使用）
     * @return int 使用次数
     */
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
