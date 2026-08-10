<?php
/**
 * Nova JSON API: Nova_Plugin
 *
 * 插件基类。所有插件应继承此类。
 *
 * 用法：
 *   class MyPlugin extends Nova_Plugin {
 *       protected $name = 'my-plugin';
 *       public function init() {
 *           $this->register_route('v1', '/my-plugin/data', [
 *               'methods' => 'GET',
 *               'callback' => [$this, 'get_data'],
 *           ]);
 *       }
 *   }
 *   new MyPlugin();
 */

defined('NOVA_API') or exit('禁止直接访问');

class Nova_Plugin {

    protected $name        = '';
    protected $version     = '1.0.0';
    protected $plugin_path = '';
    protected $plugin_url  = '';
    protected $plugin_id   = '';
    protected $plugin_slug = '';

    public function __construct() {
        // 自动检测插件路径
        $ref = new ReflectionClass($this);
        $this->plugin_path = dirname($ref->getFileName());
        $this->plugin_url  = $this->detect_plugin_url();
        if (empty($this->name)) {
            $this->name = basename($this->plugin_path);
        }
        $this->plugin_slug = basename(dirname($this->plugin_path));

        // 通过路径反查 plugin_id
        $this->plugin_id = $this->detect_plugin_id();

        // 设置当前插件上下文，让 routes 目录中的 register_rest_route() 能归属到该插件
        Nova_REST_Server::set_current_plugin_context($this->plugin_id, $this->plugin_slug);

        // 自动加载 routes/ 目录
        $this->load_routes();

        // 清除插件上下文
        Nova_REST_Server::clear_current_plugin_context();

        // 调用子类初始化（仅在插件启用时注册 init 回调，避免禁用插件时产生副作用）
        if (method_exists($this, 'init')) {
            if (Nova_Plugin_Registry::is_plugin_active($this->plugin_id)) {
                Nova_Hooks::add_action('nova_init', [$this, 'init']);
            }
        }
    }

    // ── 路由注册 ──

    /**
     * 注册 REST 路由（快捷方式）
     * 自动附加 plugin_id，便于禁用插件时拦截访问
     */
    protected function register_route($namespace, $route, $args) {
        if (!isset($args['plugin_id'])) {
            $args['plugin_id'] = $this->plugin_id;
        }
        if (!isset($args['plugin_slug'])) {
            $args['plugin_slug'] = $this->plugin_slug;
        }
        Nova_Hooks::add_action('rest_api_init', function($server) use ($namespace, $route, $args) {
            $server->register_route($namespace, $route, $args);
        });
    }

    /**
     * 自动加载插件内 routes/ 目录下的 PHP 文件
     * 加载前设置插件上下文，让 register_rest_route() 能归属插件
     */
    protected function load_routes() {
        $routesDir = $this->plugin_path . '/routes';
        if (is_dir($routesDir)) {
            $files = glob($routesDir . '/*.php');
            if ($files) {
                Nova_REST_Server::set_current_plugin_context($this->plugin_id, $this->plugin_slug);
                foreach ($files as $file) {
                    require_once $file;
                }
                Nova_REST_Server::clear_current_plugin_context();
            }
        }
    }

    // ── 数据库 ──

    /**
     * 获取 Nova_DB 实例
     */
    protected function db() {
        return new Nova_DB();
    }

    // ── 日志 ──

    /**
     * 写入插件日志
     */
    protected function log($message, $level = 'INFO') {
        error_log("[{$this->name}][{$level}] {$message}");
    }

    // ── 工具 ──

    /**
     * 检测插件 URL（从项目根目录自动计算）
     */
    private function detect_plugin_url() {
        $projectRoot = str_replace('\\', '/', realpath(dirname(__DIR__, 4)));
        $pluginPath  = str_replace('\\', '/', $this->plugin_path);
        $relative    = ltrim(str_replace($projectRoot, '', $pluginPath), '/');
        $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "{$scheme}://{$host}/{$relative}";
    }

    /**
     * 通过插件路径反查 plugin_id
     */
    private function detect_plugin_id() {
        $plugins = Nova_Plugin_Registry::scan_all();
        $myDir = rtrim(str_replace('\\', '/', dirname($this->plugin_path)), '/');
        foreach ($plugins as $p) {
            $pDir = rtrim(str_replace('\\', '/', $p['plugin_dir']), '/');
            if ($pDir === $myDir) {
                return $p['id'];
            }
        }
        return '';
    }

    /**
     * 获取当前插件 ID
     */
    public function get_plugin_id() {
        return $this->plugin_id;
    }

    /**
     * 获取当前插件 slug
     */
    public function get_plugin_slug() {
        return $this->plugin_slug;
    }
}
