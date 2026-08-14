<?php
/**
 * Nova REST API: Nova_REST_Request
 *
 * 模仿 WP_REST_Request，封装请求数据。
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_REST_Request {

    protected $method   = '';
    protected $route    = '';
    protected $params   = [];
    protected $headers  = [];
    protected $body     = null;

    public function __construct($method, $route) {
        $this->method = strtoupper($method);
        $this->route  = $route;
    }

    public function get_method() {
        return $this->method;
    }

    public function get_route() {
        return $this->route;
    }

    public function set_query_params($params) {
        $this->params['query'] = $params;
    }

    public function set_body_params($params) {
        $this->params['body'] = $params;
    }

    public function set_file_params($params) {
        $this->params['file'] = $params;
    }

    public function set_url_params($params) {
        $this->params['url'] = $params;
    }

    public function set_headers($headers) {
        $this->headers = $headers;
    }

    public function set_body($body) {
        $this->body = $body;
    }

    public function get_param($key) {
        foreach (['url', 'query', 'body', 'file'] as $source) {
            if (isset($this->params[$source][$key])) {
                return $this->params[$source][$key];
            }
        }
        return null;
    }

    public function get_params() {
        $merged = [];
        foreach (['url', 'query', 'body', 'file'] as $source) {
            if (!empty($this->params[$source])) {
                $merged = array_merge($merged, $this->params[$source]);
            }
        }
        return $merged;
    }

    public function get_header($key) {
        $key = strtolower($key);
        return $this->headers[$key] ?? null;
    }

    public function get_headers() {
        return $this->headers;
    }

    public function get_body() {
        return $this->body;
    }
}
