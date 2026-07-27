<?php
/**
 * Nova JSON API: Nova_Backend_Ajax
 *
 * 后台 AJAX 处理器。插件通过此类注册 AJAX 回调，
 * 前后端通过统一的入口 /admin/ajax.php?action=xxx 调用。
 *
 * 用法：
 *   // 注册 AJAX 处理
 *   Nova_Backend_Ajax::add('my_plugin_save', function($input) {
 *       $id = (int)$input['id'];
 *       // ... 处理逻辑
 *       return ['success' => true, 'id' => $id];
 *   });
 *
 *   // 前端调用（JavaScript）
 *   fetch('/admin/ajax.php?action=my_plugin_save', {
 *       method: 'POST',
 *       body: new URLSearchParams({ id: 1 })
 *   }).then(r => r.json()).then(console.log);
 */

defined('NOVA_API') or exit('禁止直接访问');

class Nova_Backend_Ajax {

    /** @var array 已注册的 AJAX 处理器 */
    protected static $handlers = [];

    /** @var bool 是否已初始化 */
    protected static $inited = false;

    /**
     * 注册 AJAX 处理器
     *
     * @param string   $action   操作名（对应 URL 中的 action 参数）
     * @param callable $callback 回调函数，接收输入数据，返回响应数组
     * @param bool     $needAuth 是否需要管理员权限
     * @param string   $method   允许的请求方法：GET, POST, ANY
     */
    public static function add($action, callable $callback, $needAuth = true, $method = 'POST') {
        self::$handlers[$action] = [
            'callback' => $callback,
            'needAuth' => $needAuth,
            'method'   => strtoupper($method),
        ];
    }

    /**
     * 注册无需认证的 AJAX 处理器（前台可用）
     *
     * @param string   $action
     * @param callable $callback
     * @param string   $method
     */
    public static function addPublic($action, callable $callback, $method = 'POST') {
        self::add($action, $callback, false, $method);
    }

    /**
     * 移除 AJAX 处理器
     * @param string $action
     */
    public static function remove($action) {
        unset(self::$handlers[$action]);
    }

    /**
     * 获取所有已注册的 AJAX 操作列表
     * @return array
     */
    public static function getActions() {
        return array_keys(self::$handlers);
    }

    /**
     * 处理 AJAX 请求（在 ajax.php 入口中调用）
     *
     * @return never
     */
    public static function handle() {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex');

        $action = $_REQUEST['action'] ?? '';

        if (empty($action) || !isset(self::$handlers[$action])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => '未知操作: ' . $action], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $handler = self::$handlers[$action];

        // 验证请求方法
        $reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($handler['method'] !== 'ANY' && strtoupper($reqMethod) !== $handler['method']) {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => '不允许的请求方法'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 验证权限
        if ($handler['needAuth']) {
            $userId = $_SESSION['admin_id'] ?? 0;
            if (empty($userId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => '需要管理员权限'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 可选验证 CSRF Token
            $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
            if (empty($csrfToken) || $csrfToken !== ($_SESSION['_csrf'] ?? '')) {
                // 非严格模式，仅警告
            }
        }

        try {
            // 合并输入数据
            $input = array_merge($_GET, $_POST);

            // 如果请求体是 JSON，解析 JSON 数据
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = file_get_contents('php://input');
                $jsonData = json_decode($rawBody, true);
                if (is_array($jsonData)) {
                    $input = array_merge($input, $jsonData);
                }
            }

            $result = call_user_func($handler['callback'], $input);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }

        exit;
    }

    /**
     * 初始化：在 ajax.php 入口文件中调用此方法
     */
    public static function init() {
        if (self::$inited) return;
        self::$inited = true;

        // 确保会话已启动
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        self::handle();
    }

    /**
     * 生成前端 AJAX URL
     * @param string $action
     * @param array  $params 额外参数
     * @return string
     */
    public static function url($action, $params = []) {
        $params['action'] = $action;
        return '/admin/ajax.php?' . http_build_query($params);
    }

    /**
     * 生成前端 AJAX 脚本（可直接输出到页面）
     * @return string
     */
    public static function script() {
        $ajaxUrl = json_encode('/admin/ajax.php');
        $csrf    = json_encode($_SESSION['_csrf'] ?? '');
        return <<<JS
<script>
window.NovaAjax = {
    url: {$ajaxUrl},
    csrf: {$csrf},
    post: function(action, data, callback) {
        var params = new URLSearchParams(data || {});
        params.append('action', action);
        fetch(this.url, { method: 'POST', body: params, headers: {'X-CSRF-Token': this.csrf} })
            .then(function(r) { return r.json(); })
            .then(function(r) { if (callback) callback(r); });
    },
    get: function(action, data, callback) {
        var params = new URLSearchParams(data || {});
        params.append('action', action);
        fetch(this.url + '?' + params.toString(), { headers: {'X-CSRF-Token': this.csrf} })
            .then(function(r) { return r.json(); })
            .then(function(r) { if (callback) callback(r); });
    }
};
</script>
JS;
    }
}
