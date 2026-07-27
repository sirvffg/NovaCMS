<?php
/**
 * Netease音乐播放器资源代理
 * 文件位置：/config/music_proxy.php
 */

// ============================================
// 配置部分
// ============================================
class ProxyConfig {
    // 允许访问的域名
    const ALLOWED_DOMAINS = [
        'lygalaxy.cn',
        'www.lygalaxy.cn',
        'd95ca531.hostidc.net',
        'z4gum9f8.cdnwaf.top',
        'ceshi2.w21.net',
        't.alcy.cc',
        'localhost',
        '127.0.0.1',
        '192.168.1.5'
    ];
    
    // 缓存目录（相对于当前文件）
    const CACHE_DIR = __DIR__ . '/cache/music_proxy/';
    
    // 缓存时间（秒）
    const CACHE_TIME = 604800; // 7天
    
    // 远程资源URL
    const REMOTE_RESOURCES = [
        'css' => 'https://api.hypcvgm.top/NeteaseMiniPlayer/netease-mini-player-v2.css',
        'js'  => 'https://api.hypcvgm.top/NeteaseMiniPlayer/netease-mini-player-v2.js'
    ];
}

// ============================================
// 工具类
// ============================================
class MusicProxy {
    /**
     * 检查来源是否允许
     */
    public static function checkOrigin() {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        
        // 允许直接访问（无来源信息）
        if (empty($origin) && empty($referer)) {
            return true;
        }
        
        // 允许无域名来源（比如本地文件、Postman等）
        if ($referer && parse_url($referer, PHP_URL_HOST) === null) {
            return true;
        }
        
        if ($origin && parse_url($origin, PHP_URL_HOST) === null) {
            return true;
        }
        
        // 提取域名并验证白名单
        $checkDomain = function($url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (!$host) return false;
            
            // 移除端口号
            $host = explode(':', $host)[0];
            
            foreach (ProxyConfig::ALLOWED_DOMAINS as $domain) {
                if ($host === $domain || strpos($host, '.' . $domain) !== false) {
                    return true;
                }
            }
            return false;
        };
        
        if ($origin && $checkDomain($origin)) return true;
        if ($referer && $checkDomain($referer)) return true;
        
        return false;
    }
    
    /**
     * 设置响应头
     */
    public static function setHeaders($type) {
        // CORS头 - 允许所有来源
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin && self::checkOrigin()) {
            header('Access-Control-Allow-Origin: ' . $origin);
        } else {
            header('Access-Control-Allow-Origin: *');
        }
        
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: *');
        
        // 处理预检请求
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        
        // 缓存头 - 7天浏览器缓存
        header('Cache-Control: public, max-age=' . ProxyConfig::CACHE_TIME . ', immutable');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + ProxyConfig::CACHE_TIME) . ' GMT');
        header('Vary: Accept-Encoding');
        
        // 内容类型
        if ($type === 'css') {
            header('Content-Type: text/css; charset=utf-8');
        } else {
            header('Content-Type: application/javascript; charset=utf-8');
        }
    }
    
    /**
     * 获取缓存
     */
    public static function getFromCache($key) {
        $cacheFile = ProxyConfig::CACHE_DIR . md5($key) . '.cache';
        
        if (file_exists($cacheFile)) {
            $data = @json_decode(file_get_contents($cacheFile), true);
            if ($data && isset($data['expire']) && $data['expire'] > time()) {
                return $data['content'];
            }
        }
        return null;
    }
    
    /**
     * 保存缓存
     */
    public static function saveToCache($key, $content) {
        if (!file_exists(ProxyConfig::CACHE_DIR)) {
            mkdir(ProxyConfig::CACHE_DIR, 0755, true);
        }
        
        $cacheFile = ProxyConfig::CACHE_DIR . md5($key) . '.cache';
        $data = [
            'content' => $content,
            'expire' => time() + ProxyConfig::CACHE_TIME,
            'created' => date('Y-m-d H:i:s')
        ];
        
        file_put_contents($cacheFile, json_encode($data));
    }
    
    /**
     * 获取远程内容
     */
    public static function fetchContent($url) {
        $content = false;
        
        // 尝试 file_get_contents
        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n",
                    'timeout' => 15
                ]
            ]);
            $content = @file_get_contents($url, false, $context);
        }
        
        // 如果失败，尝试 curl
        if ($content === false && function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]);
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                $content = false;
            }
        }
        
        return $content;
    }
}

// ============================================
// 主程序
// ============================================
try {
    // 处理 OPTIONS 预检请求
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: *');
        http_response_code(200);
        exit;
    }
    
    // 检查来源（白名单验证）
    if (!MusicProxy::checkOrigin()) {
        http_response_code(403);
        echo 'Access Denied - Invalid domain';
        exit;
    }
    
    // 获取资源类型
    $type = $_GET['type'] ?? '';
    if (!in_array($type, ['css', 'js'])) {
        http_response_code(400);
        echo 'Invalid type parameter. Use ?type=css or ?type=js';
        exit;
    }
    
    // 设置响应头
    MusicProxy::setHeaders($type);
    
    // 检查缓存
    $remoteUrl = ProxyConfig::REMOTE_RESOURCES[$type];
    $cachedContent = MusicProxy::getFromCache($remoteUrl);
    
    if ($cachedContent !== null) {
        echo $cachedContent;
        exit;
    }
    
    // 获取远程内容
    $content = MusicProxy::fetchContent($remoteUrl);
    
    if ($content === false || empty($content)) {
        // 返回备用内容而不是错误
        if ($type === 'css') {
            $content = '/* Netease Music Player CSS - Load failed, using fallback */';
        } else {
            $content = '// Netease Music Player JS - Load failed, using fallback';
        }
    } else {
        // 保存到缓存
        MusicProxy::saveToCache($remoteUrl, $content);
    }
    
    echo $content;
    
} catch (Exception $e) {
    http_response_code(500);
    error_log('MusicProxy Error: ' . $e->getMessage());
    header('Content-Type: text/plain');
    echo '/* Server Error: ' . $e->getMessage() . ' */';
}