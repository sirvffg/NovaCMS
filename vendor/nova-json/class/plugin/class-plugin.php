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

    public function __construct() {
        // 自动检测插件路径
        $ref = new ReflectionClass($this);
        $this->plugin_path = dirname($ref->getFileName());
        $this->plugin_url  = $this->detect_plugin_url();
        if (empty($this->name)) {
            $this->name = basename($this->plugin_path);
        }

        // 自动加载 routes/ 目录
        $this->load_routes();

        // 调用子类初始化
        if (method_exists($this, 'init')) {
            Nova_Hooks::add_action('nova_init', [$this, 'init']);
        }
    }

    // ── 路由注册 ──

    /**
     * 注册 REST 路由（快捷方式）
     */
    protected function register_route($namespace, $route, $args) {
        Nova_Hooks::add_action('rest_api_init', function($server) use ($namespace, $route, $args) {
            $server->register_route($namespace, $route, $args);
        });
    }

    /**
     * 自动加载插件内 routes/ 目录下的 PHP 文件
     */
    protected function load_routes() {
        $routesDir = $this->plugin_path . '/routes';
        if (is_dir($routesDir)) {
            $files = glob($routesDir . '/*.php');
            if ($files) {
                foreach ($files as $file) {
                    require_once $file;
                }
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
}
