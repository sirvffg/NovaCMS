<?php
/**
 * 音乐播放器组件
 * 在需要显示音乐播放器的页面引入此文件
 */

// 确保已经加载了配置
if (!isset($config)) {
    require_once __DIR__ . '/database.php';
    $db = getDB();
    $config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
}

// 检查代理是否可用（每天检测一次，结果缓存到文件）
function checkProxyAvailable() {
    static $is_available_cache = null;
    if ($is_available_cache !== null) {
        return $is_available_cache;
    }

    $cache_file = __DIR__ . '/cache/proxy_check_status.json';
    $cache_ttl = 86400; // 24小时 = 每天检测一次

    // 1. 读取缓存
    if (file_exists($cache_file)) {
        $cache_data = json_decode(file_get_contents($cache_file), true);
        if ($cache_data && isset($cache_data['time']) && (time() - $cache_data['time']) < $cache_ttl) {
            $is_available_cache = $cache_data['available'];
            return $is_available_cache;
        }
    }

    $proxy_url = '/config/music_proxy.php?type=css';
    $is_available = false;
    
    // 2. 首先检查代理文件是否存在
    if (file_exists(__DIR__ . '/music_proxy.php')) {
        // 3. 尝试使用 curl 进行实际连接检查
        if (function_exists('curl_init')) {
            $ch = curl_init();
            
            // 动态判断协议
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $check_url = $protocol . $_SERVER['HTTP_HOST'] . $proxy_url;
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $check_url,
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            
            curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($http_code == 200) {
                $is_available = true;
            }
        } else {
            // 4. 如果没有 curl，尝试使用 get_headers
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'method' => 'HEAD',
                    'follow_location' => 1
                ]
            ]);
            
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $headers = @get_headers($protocol . $_SERVER['HTTP_HOST'] . $proxy_url, 1, $context);
            
            if ($headers && (strpos($headers[0], '200') !== false || (isset($headers['Status']) && strpos($headers['Status'], '200') !== false))) {
                $is_available = true;
            }
        }
    }
    
    // 5. 如果检查失败，但在本地开发环境，强制开启
    if (!$is_available && ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1')) {
        $is_available = true;
    }
    
    // 6. 写入缓存文件
    $cache_dir = dirname($cache_file);
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0755, true);
    }
    @file_put_contents($cache_file, json_encode([
        'available' => $is_available,
        'time' => time()
    ]));
    
    $is_available_cache = $is_available;
    return $is_available;
}

// 输出音乐播放器CSS（在head中调用）
function renderMusicPlayerCSS($config) {
    if (!empty($config['music_enabled'])):
        $use_local = !checkProxyAvailable(); ?>
    <!-- 网易云音乐播放器 CSS -->
    <?php if ($use_local): ?>
    <link rel="stylesheet" href="/config/music_local/netease-mini-player-v2.css">
    <?php else: ?>
    <link rel="stylesheet" href="/config/music_proxy.php?type=css">
    <?php endif; ?>
    <?php endif;
}

// 输出音乐播放器HTML和JS（在body结束前调用）
function renderMusicPlayer($config) {
    if (!empty($config['music_enabled'])):
        $use_local = !checkProxyAvailable(); ?>
    <!-- 网易云音乐播放器 -->
    <!-- Music Player Mode: <?= $use_local ? 'Local' : 'Proxy' ?> -->
    <div class="netease-mini-player" 
         data-source-mode="<?= $use_local ? 'local' : 'proxy' ?>"
         <?php if (!empty($config['music_playlist_id'])): ?>
         data-playlist-id="<?= htmlspecialchars($config['music_playlist_id'], ENT_QUOTES, 'UTF-8') ?>"
         <?php endif; ?>
         <?php if (!empty($config['music_song_id'])): ?>
         data-song-id="<?= htmlspecialchars($config['music_song_id'], ENT_QUOTES, 'UTF-8') ?>"
         <?php endif; ?>
         data-position="<?= htmlspecialchars($config['music_position'] ?? 'bottom-left', ENT_QUOTES, 'UTF-8') ?>"
         data-embed="<?= !empty($config['music_embed']) ? 'true' : 'false' ?>"
         data-theme="<?= htmlspecialchars($config['music_theme'] ?? 'auto', ENT_QUOTES, 'UTF-8') ?>"
         data-default-minimized="<?= !empty($config['music_default_minimized']) ? 'true' : 'false' ?>"
         data-lyric="<?= !empty($config['music_lyric']) ? 'true' : 'false' ?>"
         data-autoplay="<?= !empty($config['music_autoplay']) ? 'true' : 'false' ?>"
         data-auto-pause="<?= !empty($config['music_auto_pause']) ? 'true' : 'false' ?>">
    </div>
    
    <script>
    (function() {
        // 智能判断播放器状态：如果是电脑端且窗口最大化，则展开播放器；否则保持配置状态（或最小化）
        var player = document.querySelector('.netease-mini-player');
        if (player && player.getAttribute('data-default-minimized') === 'true') {
            var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            // 判断是否最大化 (允许一定的误差)
            var isMaximized = (window.outerWidth >= window.screen.availWidth - 12) || (window.innerWidth >= window.screen.availWidth - 12);
            
            if (!isMobile && isMaximized) {
                // 电脑端最大化时，取消最小化（即展开）
                player.setAttribute('data-default-minimized', 'false');
            }
        }
    })();
    </script>
    
    <!-- 网易云音乐播放器 JS -->
    <?php if ($use_local): ?>
    <script src="/config/music_local/netease-mini-player-v2.js"></script>
    <?php else: ?>
    <script src="/config/music_proxy.php?type=js"></script>
    <?php endif; ?>
    <?php endif;
}