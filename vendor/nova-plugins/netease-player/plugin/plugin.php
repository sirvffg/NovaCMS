<?php
/**
 * NeteaseMiniPlayer v3 NovaCMS 插件入口
 *
 * 通过前台注入钩子（nova_head / nova_footer）向页面注入网易云播放器。
 * 后台通过 config.json 提供配置界面（API 地址、播放内容、外观、注入范围）。
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Netease_Player_Plugin extends Nova_Plugin {

    protected $name    = 'netease-player';
    protected $version = '1.0.0';

    /** @var array|null 配置缓存 */
    private $cfgCache = null;

    public function init() {
        // 仅在需要时注入，避免无意义开销
        if (!$this->shouldInject()) {
            return;
        }

        Nova_Hooks::add_action('nova_head',   [$this, 'injectHead']);
        Nova_Hooks::add_action('nova_footer', [$this, 'injectPlayer']);
    }

    /**
     * 读取 config.json 配置（带缓存）
     */
    private function getConfig(): array {
        if ($this->cfgCache !== null) {
            return $this->cfgCache;
        }

        $file = dirname($this->plugin_path) . '/config.json';
        $cfg  = [];
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && !empty($data['tabs'])) {
                foreach ($data['tabs'] as $tab) {
                    if (empty($tab['fields'])) continue;
                    foreach ($tab['fields'] as $field) {
                        if (isset($field['name'])) {
                            $cfg[$field['name']] = $field['value'] ?? '';
                    }
                    }
                }
            }
        }
        $this->cfgCache = $cfg;
        return $cfg;
    }

    /**
     * 根据配置判断是否在当前页面注入
     */
    private function shouldInject(): bool {
        $cfg = $this->getConfig();
        $scope = $cfg['inject_scope'] ?? 'all';
        $path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        switch ($scope) {
            case 'all':
                return true;
            case 'home':
                return $path === '/' || $path === '/index.php';
            case 'blog_detail':
                // 文章详情页为 /blog?id=N（查询参数风格），与博客列表页 /blog 通过 ?id 区分
                return ($path === '/blog' || $path === '/blog.php') && (int)($_GET['id'] ?? 0) > 0;
            case 'list':
                $custom = (string)($cfg['custom_paths'] ?? '');
                if ($custom === '') return false;
                $paths = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $custom)));
                return in_array($path, $paths, true);
            default:
                return true;
        }
    }

    /**
     * 拼接 nmpv3.min.js 的 URL
     */
    private function buildScriptUrl(): string {
        $cfg = $this->getConfig();
        $source = $cfg['source'] ?? 'cdn';

        if ($source === 'custom') {
            $custom = trim((string)($cfg['custom_script_url'] ?? ''));
            if ($custom !== '' && filter_var($custom, FILTER_VALIDATE_URL)) {
                return $custom;
            }
        }

        // 默认走 jsDelivr CDN（固定版本，避免 latest 不可预期升级）
        return 'https://cdn.jsdelivr.net/npm/netease-mini-player-v3@3.0.1/dist/nmpv3.min.js';
    }

    /**
     * 向 <head> 注入脚本和全局配置
     */
    public function injectHead() {
        $cfg         = $this->getConfig();
        $apiBaseUrl  = trim((string)($cfg['api_base_url'] ?? ''));
        $scriptUrl   = $this->buildScriptUrl();

        // 注入全局配置（api-base-url 属性优先级更高，此处仅作为默认值）
        if ($apiBaseUrl !== '') {
            $cfgJson = json_encode(['apiBaseUrl' => $apiBaseUrl], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo '<script>window.NMPv3Config = Object.assign({}, window.NMPv3Config || {}, ' . $cfgJson . ');</script>' . "\n";
        }

        // 注入播放器脚本
        echo '<script defer src="' . e($scriptUrl) . '"></script>' . "\n";
    }

    /**
     * 向页脚注入播放器 DOM
     */
    public function injectPlayer() {
        $cfg = $this->getConfig();

        $playType   = $cfg['play_type']  ?? 'playlist';
        $songId     = trim((string)($cfg['song_id']     ?? ''));
        $playlistId = trim((string)($cfg['playlist_id'] ?? ''));
        $theme      = $cfg['theme']      ?? 'auto';
        $layout     = $cfg['layout']     ?? 'compact';
        $position   = $cfg['position']   ?? 'fixed-bottom-right';
        $lyric      = ($cfg['lyric'] ?? true) ? 'true' : 'false';
        $autoplay   = ($cfg['autoplay'] ?? false) ? 'true' : 'false';
        $apiBaseUrl = trim((string)($cfg['api_base_url'] ?? ''));

        // 校验 ID 必填
        if ($playType === 'song' && $songId === '') return;
        if ($playType === 'playlist' && $playlistId === '') return;

        // 拼接 <nmp-player> 属性
        $attrs = [];
        if ($playType === 'song' && $songId !== '') {
            $attrs[] = 'song-id="' . e($songId) . '"';
        }
        if ($playType === 'playlist' && $playlistId !== '') {
            $attrs[] = 'playlist-id="' . e($playlistId) . '"';
        }
        $attrs[] = 'theme="'    . e($theme)    . '"';
        $attrs[] = 'layout="'   . e($layout)   . '"';

        // 配置值映射到 NMPv3 原生 position 值
        // NMPv3 内置 bottom-right/bottom-left 自带 position:fixed;bottom:20px，
        // 且 data-position^="bottom" 时播放列表自动向上展开
        $nmpPositionMap = [
            'fixed-bottom-right' => 'bottom-right',
            'fixed-bottom-left'  => 'bottom-left',
            'static'             => 'static',
        ];
        $nmpPosition = $nmpPositionMap[$position] ?? 'bottom-right';

        $attrs[] = 'position="' . e($nmpPosition) . '"';
        $attrs[] = 'lyric="'    . e($lyric)    . '"';
        $attrs[] = 'autoplay="' . e($autoplay) . '"';
        if ($apiBaseUrl !== '') {
            $attrs[] = 'api-base-url="' . e($apiBaseUrl) . '"';
        }

        $playerHtml = '<div class="nova-netease-player">'
            . '<nmp-player ' . implode(' ', $attrs) . '></nmp-player>'
            . '</div>';

        // 静态位置：通过 nova_inject 过滤器注入到文章容器内
        // 固定底部位置：NMPv3 自带定位，直接通过 nova_footer 输出；播放列表自动向上展开
        if ($position === 'static') {
            Nova_Hooks::add_filter('nova_inject', function (array $items) use ($playerHtml) {
                $items[] = [
                    'selector' => 'article.article-shell',
                    'position' => 'append',
                    'html'     => $playerHtml,
                    'retry'    => 5,
                    'delay'    => 300,
                ];
                return $items;
            });
        } else {
            // 固定底部：NMPv3 自带 position:fixed;bottom:20px，直接输出
            echo $playerHtml;
            // 1) 缩放窗口时重置播放器到固定位置（NMPv3 拖动后用 inline left/top 定位，
            //    不会在 resize 时自动回到 bottom-right/bottom-left）
            // 2) 播放器与 #nova-back-to-top 同侧重叠时，把按钮上移避让
            echo '<script>'
               . '(function(){'
               // 清除播放器拖动后的 inline 定位 + 持久化位置，让 CSS 固定位置重新生效
               . 'function resetPlayerPosition(){'
               . 'var p=document.querySelector(".nmpv3-player.nmpv3-user-positioned");'
               . 'if(!p)return;'
               . 'if(p.classList.contains("nmpv3-dragging"))return;'  // 拖拽中不干预
               . 'p.style.left="";p.style.top="";p.style.right="";p.style.bottom="";p.style.transition="";'
               . 'p.classList.remove("nmpv3-user-positioned");'
               . 'delete p.dataset.side;'
               // 清除 localStorage 持久化的 position，避免刷新后又恢复拖动位置
               . 'try{'
               . 'for(var i=localStorage.length-1;i>=0;i--){'
               . 'var k=localStorage.key(i);'
               . 'if(!k||k.indexOf("nmpv3:")!==0||k.indexOf(":state")<0)continue;'
               . 'var raw=localStorage.getItem(k);'
               . 'if(!raw)continue;'
               . 'var obj=JSON.parse(raw);'
               . 'if(obj&&"position" in obj){delete obj.position;localStorage.setItem(k,JSON.stringify(obj));}'
               . '}'
               . '}catch(e){}'
               . '}'
               // 回到顶部按钮避让
               . 'function adjustBtt(){'
               . 'var p=document.querySelector(".nmpv3-player[data-position^=\\"bottom\\"]");'
               . 'if(!p)return;'
               . 'var btt=document.getElementById("nova-back-to-top");'
               . 'if(!btt)return;'
               . 'var pRect=p.getBoundingClientRect();'
               . 'var bRect=btt.getBoundingClientRect();'
               . 'if(bRect.width===0||bRect.height===0)return;'
               . 'var overlapX=!(bRect.right<pRect.left||bRect.left>pRect.right);'
               . 'if(!overlapX)return;'
               . 'var overlapY=!(bRect.bottom<pRect.top||bRect.top>pRect.bottom);'
               . 'if(!overlapY)return;'
               . 'var playerTopFromBottom=window.innerHeight-pRect.top;'
               . 'var gap=12;'
               . 'btt.style.bottom=(playerTopFromBottom+gap)+"px";'
               . '}'
               // resize：debounce，先重置播放器位置再调整按钮避让
               . 'var rt;'
               . 'function onResize(){clearTimeout(rt);rt=setTimeout(function(){resetPlayerPosition();adjustBtt();},200);}'
               . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",adjustBtt);}else{adjustBtt();}'
               . 'window.addEventListener("resize",onResize);'
               . 'window.addEventListener("scroll",adjustBtt,{passive:true});'
               . 'setTimeout(adjustBtt,500);setTimeout(adjustBtt,1500);'
               . '})();'
               . '</script>' . "\n";
        }
    }
}

new Netease_Player_Plugin();
