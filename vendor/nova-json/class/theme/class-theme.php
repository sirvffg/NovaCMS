<?php
/**
 * Nova JSON API: Nova_Theme
 *
 * 主题基类。所有主题应继承此类。
 *
 * 用法：
 *   class MyTheme extends Nova_Theme {
 *       public function init() {
 *           $this->set_layout('default');
 *           $this->register_route('v1', '/my-theme/data', [
 *               'methods' => 'GET',
 *               'callback' => [$this, 'get_data'],
 *           ]);
 *       }
 *   }
 *   new MyTheme();
 */

defined('NOVA_API') or exit('禁止直接访问');

class Nova_Theme {

    protected $name       = '';
    protected $version    = '1.0.0';
    protected $theme_path = '';
    protected $theme_url  = '';
    protected $layout     = 'default';

    public function __construct() {
        $ref = new ReflectionClass($this);
        $this->theme_path = dirname($ref->getFileName());
        $this->theme_url  = $this->detect_theme_url();
        if (empty($this->name)) {
            $this->name = basename($this->theme_path);
        }

        // 自动加载 routes/ 目录
        $this->load_routes();

        // 调用子类初始化
        if (method_exists($this, 'init')) {
            Nova_Hooks::add_action('nova_init', [$this, 'init']);
        }
    }

    // ── 布局 ──

    /**
     * 设置布局模板
     */
    protected function set_layout($layout) {
        $this->layout = $layout;
    }

    /**
     * 渲染模板
     */
    protected function render($template, $data = []) {
        extract($data);
        $file = $this->theme_path . '/views/' . $template . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }

    // ── 路由 ──

    protected function register_route($namespace, $route, $args) {
        Nova_Hooks::add_action('rest_api_init', function($server) use ($namespace, $route, $args) {
            $server->register_route($namespace, $route, $args);
        });
    }

    protected function load_routes() {
        $routesDir = $this->theme_path . '/routes';
        if (is_dir($routesDir)) {
            foreach (glob($routesDir . '/*.php') as $file) {
                require_once $file;
            }
        }
    }

    // ── 数据库 ──

    protected function db() {
        return new Nova_DB();
    }

    // ── 资源 ──

    /**
     * 获取主题资源 URL
     */
    protected function asset($path) {
        return rtrim($this->theme_url, '/') . '/assets/' . ltrim($path, '/');
    }

    // ── 工具 ──

    private function detect_theme_url() {
        $projectRoot = str_replace('\\', '/', realpath(dirname(__DIR__, 4)));
        $themePath   = str_replace('\\', '/', $this->theme_path);
        $relative    = ltrim(str_replace($projectRoot, '', $themePath), '/');
        $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "{$scheme}://{$host}/{$relative}";
    }
}
