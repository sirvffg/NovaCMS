<?php
/**
 * Proxy 路由（公网 + 内部）
 * 命名空间: v1
 *
 * 提供两种代理方式:
 *   1. 公网代理 — 服务器端请求外部 API，避免前端跨域
 *   2. 内部代理 — 直接调度本地 API 端点，零网络开销
 *
 * 安全特性:
 *   - 仅允许 http/https 协议 (SSRF 防护)
 *   - 禁止内网/环回地址
 *   - 超时限制
 *   - 响应体大小限制
 */

// ── 公网代理 ──

register_rest_route('v1', '/public/proxy', [
    'methods'  => 'GET, POST',
    'callback' => 'nova_handle_proxy',
]);

function nova_handle_proxy($request) {
    // 从 GET 或 POST JSON body 读取参数
    $input = [];
    if ($request->get_method() === 'POST') {
        $raw = $request->get_body();
        $input = json_decode($raw, true) ?: [];
    }

    $url     = $request->get_param('url') ?: $input['url'] ?? '';
    $method  = strtoupper($request->get_param('method') ?: $input['method'] ?? $request->get_method());
    $headers = $request->get_param('headers') ?: $input['headers'] ?? [];
    $body    = $input['body'] ?? null;
    $timeout = min(30, max(1, (int)($request->get_param('timeout') ?: $input['timeout'] ?? 10)));

    if (empty($url)) {
        return new Nova_REST_Response([
            'code'    => 'proxy_missing_url',
            'message' => '缺少参数: url',
            'data'    => ['status' => 400],
        ], 400);
    }

    // SSRF 防护（内置，不依赖外部函数）
    $validatedUrl = nova_validate_proxy_url($url);
    if ($validatedUrl === false) {
        return new Nova_REST_Response([
            'code'    => 'proxy_invalid_url',
            'message' => 'URL 不合法或被禁止（仅允许公网 http/https）',
            'data'    => ['status' => 400],
        ], 400);
    }
    $url = $validatedUrl;

    // DNS 固定解析，防止 DNS Rebinding 攻击
    $resolvedHost = parse_url($url, PHP_URL_HOST);
    $resolvedPort = parse_url($url, PHP_URL_PORT)
        ?: (strtolower(parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80);
    $resolvedIp   = gethostbyname($resolvedHost);
    $dnsResolve   = [];
    if ($resolvedIp !== $resolvedHost && filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
        // 二次验证，防御性检查（即使上面已经检查过）
        if (nova_is_private_ip($resolvedIp)) {
            return new Nova_REST_Response([
                'code'    => 'proxy_invalid_url',
                'message' => 'URL 不合法或被禁止（仅允许公网 http/https）',
                'data'    => ['status' => 400],
            ], 400);
        }
        // 将主机名绑定到已解析的 IP，防止 cURL 二次解析时被 DNS 重绑定攻击
        $dnsResolve = ["{$resolvedHost}:{$resolvedPort}:{$resolvedIp}"];
    }

    // 初始化 cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    if (!empty($dnsResolve)) {
        curl_setopt($ch, CURLOPT_RESOLVE, $dnsResolve);
    }
    curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']
        ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

    // 自定义请求头
    if (!empty($headers)) {
        $curlHeaders = [];
        if (is_array($headers)) {
            foreach ($headers as $key => $val) {
                if (is_string($key)) {
                    $curlHeaders[] = "$key: $val";
                } else {
                    $curlHeaders[] = $val;
                }
            }
        }
        if (!empty($curlHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }
    }

    // 自定义请求方法
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            $encodedBody = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedBody);
        }
    }

    $response     = curl_exec($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($response === false || $response === '') {
        $statusCode = $httpCode ?: 502;
        return new Nova_REST_Response([
            'code'    => 'proxy_fetch_failed',
            'message' => '请求目标地址失败',
            'data'    => [
                'status'    => $statusCode,
                'http_code' => $httpCode,
            ],
        ], $statusCode);
    }

    // 响应体大小限制（最大 2MB）
    $maxBodySize = 2 * 1024 * 1024;
    if (strlen($response) > $maxBodySize) {
        return new Nova_REST_Response([
            'code'    => 'proxy_response_too_large',
            'message' => '响应体超过 2MB 大小限制',
            'data'    => ['status' => 413],
        ], 413);
    }

    // 判断响应是否为 JSON
    $isJson = ($contentType && strpos($contentType, 'application/json') !== false)
        ? true
        : (json_decode($response, true) !== null);

    if ($isJson) {
        $data = json_decode($response, true);
        return [
            'code'    => 'rest_ok',
            'message' => '请求成功',
            'data'    => [
                'status'       => $httpCode,
                'target_url'   => nova_strip_url_credentials($effectiveUrl ?: $url),
                'content_type' => $contentType,
                'body'         => $data,
            ],
        ];
    }

    return [
        'code'    => 'rest_ok',
        'message' => '请求成功',
        'data'    => [
            'status'       => $httpCode,
            'target_url'   => nova_strip_url_credentials($effectiveUrl ?: $url),
            'content_type' => $contentType,
            'body'         => mb_substr($response, 0, $maxBodySize),
        ],
    ];
}

/**
 * 内置 URL 验证函数（SSRF 防护）
 * 仅允许公网 http/https，禁止内网/环回地址
 */
function nova_validate_proxy_url($url) {
    // 仅允许 http/https
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (empty($host)) {
        return false;
    }

    // 禁止环回地址
    $lowerHost = strtolower($host);
    $loopbacks = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'];
    if (in_array($lowerHost, $loopbacks, true)) {
        return false;
    }

    // 禁止内网域名（常见通配）
    if (preg_match('/\.(local|internal|lan|intranet)$/i', $lowerHost)) {
        return false;
    }

    // 解析 IP 并检查是否为内网地址
    $ip = gethostbyname($host);
    if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
        if (nova_is_private_ip($ip)) {
            return false;
        }
    }

    return $url;
}

/**
 * 从 URL 中剥离认证凭据（user:pass@），防止泄露
 */
function nova_strip_url_credentials($url) {
    $parts = parse_url($url);
    if (isset($parts['user']) || isset($parts['pass'])) {
        $scheme   = $parts['scheme'] ?? 'http';
        $host     = $parts['host'] ?? '';
        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path     = $parts['path'] ?? '';
        $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $scheme . '://' . $host . $port . $path . $query . $fragment;
    }
    return $url;
}

/**
 * 检查 IP 是否为内网/保留地址
 * 同时支持 IPv4 和 IPv6
 */
function nova_is_private_ip($ip) {
    // ── IPv4 检查 ──
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        $ranges = [
            [0x0A000000, 0x0AFFFFFF],   // 10.0.0.0/8
            [0x7F000000, 0x7FFFFFFF],   // 127.0.0.0/8
            [0xA9FE0000, 0xA9FEFFFF],   // 169.254.0.0/16
            [0xAC100000, 0xAC1FFFFF],   // 172.16.0.0/12
            [0xC0A80000, 0xC0A8FFFF],   // 192.168.0.0/16
        ];

        foreach ($ranges as $range) {
            if ($ipLong >= $range[0] && $ipLong <= $range[1]) {
                return true;
            }
        }

        return false;
    }

    // ── IPv6 检查 ──
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $binary = inet_pton($ip);
        if ($binary === false || strlen($binary) !== 16) {
            return false;
        }

        $firstByte = ord($binary[0]);

        // ::1/128 — IPv6 环回地址
        if ($binary === inet_pton('::1')) {
            return true;
        }

        // fc00::/7 — Unique Local Addresses (ULA)，含 fd00::/8
        if (($firstByte & 0xfe) === 0xfc) {
            return true;
        }

        // fe80::/10 — Link-Local 地址
        if ($firstByte === 0xfe && (ord($binary[1]) & 0xc0) === 0x80) {
            return true;
        }
    }

    return false;
}

// =============================================
// ── 内部代理：直接调度本地 API 端点 ──
// =============================================
//
// 无需经过 HTTP 栈，适合同一服务器上服务间调用。

register_rest_route('v1', '/public/proxy/internal', [
    'methods'  => 'POST',
    'callback' => 'nova_handle_internal_proxy',
]);

function nova_handle_internal_proxy($request) {
    // 解析输入
    $raw    = $request->get_body();
    $input  = json_decode($raw, true) ?: [];

    $targetRoute  = $input['route'] ?? '';
    $targetURL    = $input['url'] ?? '';
    $targetMethod = strtoupper($input['method'] ?? 'GET');
    $params       = $input['params'] ?? [];

    // ── 情况 1：传了 route → 直接通过 Nova_API 调度 ──
    if (!empty($targetRoute)) {
        return nova_dispatch_local_route($targetRoute, $targetMethod, $params);
    }

    // ── 情况 2：传了 url ──
    if (!empty($targetURL)) {
        return nova_handle_internal_url($targetURL, $targetMethod, $params);
    }

    return new Nova_REST_Response([
        'code'    => 'proxy_missing_route',
        'message' => '缺少参数: route 或 url',
        'data'    => ['status' => 400],
    ], 400);
}

/**
 * 处理内部代理的 URL 请求
 * 统一解析到本地（127.0.0.1），不依赖 host 匹配
 */
function nova_handle_internal_url($url, $method, $params) {
    $path  = parse_url($url, PHP_URL_PATH) ?: '';
    $query = parse_url($url, PHP_URL_QUERY) ?: '';
    $host  = parse_url($url, PHP_URL_HOST) ?: '';

    // 如果是 Nova API 路由（含 /nova-json/），解析为本地调度（零网络开销）
    $pos = strpos($path, '/nova-json/');
    if ($pos !== false) {
        $route = '/' . trim(substr($path, $pos + strlen('/nova-json')), '/');

        // 禁止循环调用自身
        if ($route === '/v1/public/proxy/internal') {
            return new Nova_REST_Response([
                'code'    => 'proxy_self_call',
                'message' => '禁止递归调用内部代理',
                'data'    => ['status' => 400],
            ], 400);
        }

        // 合并 query string 参数
        if (!empty($query)) {
            parse_str($query, $queryParams);
            $params = array_merge($queryParams, $params);
        }

        return nova_dispatch_local_route($route, $method, $params);
    }

    // 非 Nova API 路径 → 走本地 cURL（127.0.0.1），带原始 Host 头让 nginx 正确路由
    $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'http';
    $localUrl = $scheme . '://127.0.0.1';
    if (!empty($path)) $localUrl .= $path;
    if (!empty($query)) $localUrl .= '?' . $query;

    $extraHeaders = [];
    if (!empty($host)) {
        $extraHeaders[] = 'Host: ' . $host;
    }

    return nova_fetch_local_curl($localUrl, $method, $params, $extraHeaders);
}

/**
 * 通过 Nova_API 直接调度本地路由（零网络开销）
 */
function nova_dispatch_local_route($route, $method, $params) {
    // 禁止循环调用自身
    if ($route === '/v1/public/proxy/internal') {
        return new Nova_REST_Response([
            'code'    => 'proxy_self_call',
            'message' => '禁止递归调用内部代理',
            'data'    => ['status' => 400],
        ], 400);
    }

    try {
        switch ($method) {
            case 'GET':
                $result = Nova_API::get($route, $params);
                break;
            case 'POST':
                $result = Nova_API::post($route, $params);
                break;
            case 'PUT':
                $result = Nova_API::put($route, $params);
                break;
            case 'DELETE':
                $result = Nova_API::delete($route);
                break;
            default:
                return new Nova_REST_Response([
                    'code'    => 'proxy_invalid_method',
                    'message' => "不支持的请求方法: {$method}",
                    'data'    => ['status' => 400],
                ], 400);
        }

        return $result;

    } catch (Exception $e) {
        return new Nova_REST_Response([
            'code'    => 'proxy_internal_error',
            'message' => '内部代理执行失败: ' . $e->getMessage(),
            'data'    => ['status' => 500],
        ], 500);
    }
}

/**
 * 通过本地 cURL 请求（同一服务器，非 Nova API 路径）
 * 成功时直接返回原始数据，失败时返回错误
 */
function nova_fetch_local_curl($localUrl, $method, $params, $extraHeaders = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $localUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    // 设置 Host 头使 nginx 正确路由到对应虚拟主机
    if (!empty($extraHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $extraHeaders);
    }

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($params)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($params) ? $params : json_encode($params, JSON_UNESCAPED_UNICODE));
        }
    }

    $response    = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error       = curl_error($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        $statusCode = $httpCode ?: 502;
        return new Nova_REST_Response([
            'code'    => 'proxy_local_fetch_failed',
            'message' => '本地请求失败: ' . ($error ?: '空响应'),
            'data'    => ['status' => $statusCode, 'http_code' => $httpCode],
        ], $statusCode);
    }

    // 直接返回原始数据 — JSON 解码为数组，其余保留原样
    $isJson = ($contentType && strpos($contentType, 'application/json') !== false)
        ? true
        : (json_decode($response, true) !== null);

    return $isJson ? json_decode($response, true) : $response;
}
