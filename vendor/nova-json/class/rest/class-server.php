<?php
/**
 * Nova REST API: Nova_REST_Server
 *
 * 模仿 WP_REST_Server，核心路由引擎。
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_REST_Server {

    const READABLE   = 'GET';
    const CREATABLE  = 'POST';
    const EDITABLE   = 'POST, PUT, PATCH';
    const DELETABLE  = 'DELETE';
    const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';

    protected $namespaces  = [];
    protected $endpoints   = [];

    /** @var callable[] rest_api_init 钩子队列 */
    protected static $init_hooks = [];

    /** @var array 当前插件上下文，供 routes 目录中 register_rest_route() 使用 */
    protected static $current_plugin_context = null;

    // ==================== 插件上下文管理 ====================

    /**
     * 设置当前插件上下文（routes 文件加载时使用）
     *
     * @param string $pluginId
     * @param string $pluginSlug
     */
    public static function set_current_plugin_context($pluginId, $pluginSlug = '') {
        self::$current_plugin_context = [
            'plugin_id'   => $pluginId,
            'plugin_slug' => $pluginSlug,
        ];
    }

    /**
     * 清除当前插件上下文
     */
    public static function clear_current_plugin_context() {
        self::$current_plugin_context = null;
    }

    /**
     * 获取当前插件上下文
     *
     * @return array|null
     */
    public static function get_current_plugin_context() {
        return self::$current_plugin_context;
    }

    // ==================== 路由注册 ====================

    /**
     * 注册 REST 路由。
     * 自动从当前上下文附加 plugin_id / plugin_slug（若 args 中未指定）
     */
    public function register_route($route_namespace, $route, $route_args, $override = false) {
        // 从上下文注入插件归属信息（如果 args 未明确指定）
        if (self::$current_plugin_context !== null) {
            if (!isset($route_args['plugin_id']) && !empty(self::$current_plugin_context['plugin_id'])) {
                $route_args['plugin_id'] = self::$current_plugin_context['plugin_id'];
            }
            if (!isset($route_args['plugin_slug']) && !empty(self::$current_plugin_context['plugin_slug'])) {
                $route_args['plugin_slug'] = self::$current_plugin_context['plugin_slug'];
            }
        }

        if (!isset($this->namespaces[$route_namespace])) {
            $this->namespaces[$route_namespace] = [];
            $this->register_route(
                $route_namespace,
                '/' . $route_namespace,
                [
                    'methods'  => self::READABLE,
                    'callback' => [$this, 'get_namespace_index'],
                    'args'     => ['namespace' => ['default' => $route_namespace]],
                ]
            );
        }

        $this->namespaces[$route_namespace][$route] = true;
        $route_args['namespace'] = $route_namespace;

        $full_route = '/' . trim(preg_replace('#/+#', '/', '/' . trim($route_namespace, '/') . '/' . trim($route, '/')), '/');

        if ($override || empty($this->endpoints[$full_route])) {
            $this->endpoints[$full_route] = $route_args;
        } else {
            $existing = $this->endpoints[$full_route];
            if (isset($existing['methods']) && $route_args !== $existing) {
                $this->endpoints[$full_route] = [$existing, $route_args];
            } elseif (is_array($existing) && !isset($existing['callback'])) {
                $this->endpoints[$full_route][] = $route_args;
            }
        }
    }

    public function get_namespaces() {
        return array_keys($this->namespaces);
    }

    public function get_routes($route_namespace = '') {
        if ($route_namespace) {
            $filtered = [];
            foreach ($this->endpoints as $route => $handler) {
                $handlerNamespace = is_array($handler) && isset($handler['namespace'])
                    ? $handler['namespace']
                    : (is_array($handler) && isset($handler[0]['namespace'])
                        ? $handler[0]['namespace']
                        : '');
                if ($handlerNamespace === $route_namespace) {
                    $filtered[$route] = $handler;
                }
            }
            $endpoints = $filtered;
        } else {
            $endpoints = $this->endpoints;
        }

        $normalized = [];
        foreach ($endpoints as $route => $handlers) {
            if (strpos($route, '(?P<') !== false) {
                $normalized[$route] = $handlers;
            } else {
                $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $route);
                $normalized[$regex] = $handlers;
            }
        }
        return $normalized;
    }

    // ==================== 请求分发 ====================

    public function serve_request($path = null) {
        $request = new Nova_REST_Request($_SERVER['REQUEST_METHOD'], $path ?: '/');

        $request->set_query_params($_GET);

        // 判断 Content-Type，决定如何解析 body
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $jsonBody = json_decode(file_get_contents('php://input'), true);
            $request->set_body_params($jsonBody ?: []);
        } else {
            $request->set_body_params($_POST);
        }

        $request->set_file_params($_FILES);

        $headers = [];
        foreach ($_SERVER as $key => $val) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $val;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        $request->set_headers($headers);
        $request->set_body(file_get_contents('php://input'));

        if (isset($_GET['_method'])) {
            $request = new Nova_REST_Request($_GET['_method'], $path ?: '/');
        }

        $this->do_rest_api_init();
        return $this->dispatch($request);
    }

    public function dispatch($request) {
        $matched = $this->match_request_to_handler($request);

        if ($matched instanceof Nova_REST_Response) {
            return $matched;
        }

        $route   = $matched['route'];
        $handler = $matched['handler'];
        $matches = $matched['matches'];

        // ── 插件禁用拦截 ──
        if (!empty($handler['plugin_id'])) {
            if (!Nova_Plugin_Registry::is_plugin_active($handler['plugin_id'])) {
                $pluginName = '';
                $pluginInfo = Nova_Plugin_Registry::find_plugin($handler['plugin_id']);
                if ($pluginInfo) {
                    $pluginName = $pluginInfo['name'];
                }
                $msg = $pluginName ? "插件「{$pluginName}」已禁用" : '此插件已禁用';
                return $this->error_to_response([
                    'code'    => 'plugin_disabled',
                    'message' => $msg,
                    'status'  => 403,
                ]);
            }
        }

        $url_params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $url_params[$key] = $value;
            }
        }
        $request->set_url_params($url_params);

        if (!empty($handler['permission_callback'])) {
            $allowed = call_user_func($handler['permission_callback'], $request);
            if ($allowed instanceof Nova_REST_Response) {
                return $allowed;
            }
            if ($allowed !== true) {
                return $this->error_to_response([
                    'code'    => 'rest_forbidden',
                    'message' => '无权访问此路由',
                    'status'  => 403,
                ]);
            }
        }

        if (!empty($handler['callback'])) {
            return $this->ensure_response(call_user_func($handler['callback'], $request));
        }

        return $this->error_to_response([
            'code'    => 'rest_invalid_handler',
            'message' => '路由处理器无效',
            'status'  => 500,
        ]);
    }

    protected function match_request_to_handler($request) {
        $method = $request->get_method();
        $path   = '/' . trim($request->get_route(), '/');

        $with_namespace = [];
        foreach ($this->get_namespaces() as $namespace) {
            if (strpos(ltrim($path, '/'), $namespace) === 0) {
                $routes = $this->get_routes($namespace);
                if (!empty($routes)) {
                    $with_namespace[] = $routes;
                }
            }
        }

        $routes = !empty($with_namespace) ? array_merge(...$with_namespace) : $this->get_routes();

        foreach ($routes as $route_pattern => $handlers) {
            $regex = '#^' . $route_pattern . '$#i';
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }

            $handler_list = [];
            if (isset($handlers['callback'])) {
                $handler_list = [$handlers];
            } elseif (is_array($handlers)) {
                foreach ($handlers as $h) {
                    if (isset($h['methods']) || isset($h['callback'])) {
                        $handler_list[] = $h;
                    }
                }
            }

            if (empty($handler_list)) {
                continue;
            }

            foreach ($handler_list as $handler) {
                $handler_methods = $handler['methods'] ?? self::READABLE;
                $methods = is_array($handler_methods)
                    ? array_keys($handler_methods)
                    : array_map('trim', explode(',', strtoupper($handler_methods)));

                if ($method === 'HEAD' && !in_array('HEAD', $methods, true)) {
                    if (in_array('GET', $methods, true)) {
                        $method = 'GET';
                    }
                }

                if (!in_array($method, $methods, true)) {
                    continue;
                }

                return ['route' => $route_pattern, 'handler' => $handler, 'matches' => $matches];
            }
        }

        return $this->error_to_response([
            'code' => 'rest_no_route',
            'message' => '未找到匹配的路由',
            'status' => 404,
        ]);
    }

    protected function ensure_response($result) {
        if ($result instanceof Nova_REST_Response) {
            return $result;
        }
        return new Nova_REST_Response(is_array($result) ? $result : ['data' => $result], 200);
    }

    protected function error_to_response($error) {
        if (is_array($error)) {
            $code    = $error['code']    ?? 'unknown_error';
            $message = $error['message'] ?? '未知错误';
            $status  = $error['status']  ?? 500;
        } else {
            $code    = 'unknown_error';
            $message = (string)$error;
            $status  = 500;
        }
        return new Nova_REST_Response(['code' => $code, 'message' => $message, 'data' => ['status' => $status]], $status);
    }

    public function get_namespace_index($request) {
        $namespace = $request->get_param('namespace');
        $routes    = $this->get_routes($namespace);
        $items     = [];

        foreach ($routes as $route => $handlers) {
            $handler_list = isset($handlers['callback']) ? [$handlers] : (array)$handlers;
            $methods = [];
            foreach ($handler_list as $h) {
                $m = $h['methods'] ?? 'GET';
                $methods = array_merge($methods, is_array($m) ? array_keys($m) : explode(',', $m));
            }
            $items[] = ['route' => $route, 'methods' => array_values(array_unique(array_map('trim', $methods)))];
        }

        usort($items, fn($a, $b) => strcmp($a['route'], $b['route']));

        return new Nova_REST_Response([
            'code'    => 'rest_ok',
            'message' => '命名空间: ' . $namespace,
            'data'    => ['status' => 200, 'namespace' => $namespace, 'routes' => $items],
        ]);
    }

    // ==================== 钩子系统 ====================

    public static function add_init_hook($callback) {
        self::$init_hooks[] = $callback;
    }

    public function do_rest_api_init() {
        foreach (self::$init_hooks as $hook) {
            call_user_func($hook, $this);
        }
        // 同时触发 Nova_Hooks 的 rest_api_init，让插件注册的路由生效
        Nova_Hooks::do_action('rest_api_init', $this);
    }
}

function register_rest_route($namespace, $route, $args, $override = false) {
    Nova_REST_Server::add_init_hook(function($server) use ($namespace, $route, $args, $override) {
        // 如果 args 中没有 plugin_id，尝试从当前上下文补充
        $ctx = Nova_REST_Server::get_current_plugin_context();
        if ($ctx !== null) {
            if (!isset($args['plugin_id']) && !empty($ctx['plugin_id'])) {
                $args['plugin_id'] = $ctx['plugin_id'];
            }
            if (!isset($args['plugin_slug']) && !empty($ctx['plugin_slug'])) {
                $args['plugin_slug'] = $ctx['plugin_slug'];
            }
        }
        $server->register_route($namespace, $route, $args, $override);
    });
}
