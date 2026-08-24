<?php
/**
 * Nova JSON API: Nova_API
 *
 * 内部 API 调用类。插件和主题可通过此类直接访问已注册的 R EST 端点，
 * 无需构造 HTTP 请求，也不会产生网络开销。
 *
 * 用法：
 *   $posts = Nova_API::get('/v1/posts', ['per_page' => 5]);
 *   $result = Nova_API::post('/v1/statuses/guestbook', [
 *       'nickname' => 'Test',
 *       'content'  => 'Hello',
 *   ]);
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_API {

    protected static $server = null;

    /**
     * GET 请求
     * @param string $route  路由路径，如 /v1/posts
     * @param array  $params 查询参数
     * @return array|Nova_REST_Response
     */
    public static function get($route, $params = []) {
        $request = new Nova_REST_Request('GET', $route);
        $request->set_query_params($params);
        return self::dispatch($request);
    }

    /**
     * POST 请求
     * @param string $route 路由路径
     * @param array  $data  请求体数据
     * @return array|Nova_REST_Response
     */
    public static function post($route, $data = []) {
        $request = new Nova_REST_Request('POST', $route);
        $request->set_body_params($data);
        return self::dispatch($request);
    }

    /**
     * PUT 请求
     * @param string $route 路由路径（可含占位符，如 /v1/statuses/guestbook/{id}/reply）
     * @param array  $data  请求体数据
     * @return array|Nova_REST_Response
     */
    public static function put($route, $data = []) {
        $request = new Nova_REST_Request('PUT', $route);
        $request->set_body_params($data);
        return self::dispatch($request);
    }

    /**
     * DELETE 请求
     * @param string $route 路由路径（如 /v1/statuses/instant/5）
     * @return array|Nova_REST_Response
     */
    public static function delete($route) {
        $request = new Nova_REST_Request('DELETE', $route);
        return self::dispatch($request);
    }

    /**
     * 内部分发请求
     */
    protected static function dispatch($request) {
        $response = self::server()->dispatch($request);
        if ($response instanceof Nova_REST_Response) {
            return $response->get_data();
        }
        return $response;
    }

    /**
     * 获取/初始化 Server 实例
     */
    protected static function server() {
        if (self::$server === null) {
            self::$server = new Nova_REST_Server();
            self::$server->do_rest_api_init();
        }
        return self::$server;
    }
}
