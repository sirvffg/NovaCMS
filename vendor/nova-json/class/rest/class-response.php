<?php
/**
 * Nova REST API: Nova_REST_Response
 *
 * 模仿 WP_REST_Response，封装响应数据。
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_REST_Response {

    protected $status  = 200;
    protected $headers = [];
    protected $data    = null;

    public function __construct($data = null, $status = 200) {
        $this->data   = $data;
        $this->status = $status;
    }

    public function set_status($status) {
        $this->status = $status;
    }

    public function get_status() {
        return $this->status;
    }

    public function set_header($key, $value) {
        $this->headers[$key] = $value;
    }

    public function get_headers() {
        return $this->headers;
    }

    public function get_data() {
        return $this->data;
    }

    public function to_json() {
        return json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
