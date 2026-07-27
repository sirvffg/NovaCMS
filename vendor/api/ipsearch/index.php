<?php
/**
 * IP 查询 API 入口 (对应 index.js 的 fetch handler)
 * 
 * 使用方式:
 *   Web:    GET /?ip=8.8.8.8
 *   Web:    GET /health
 *   CLI:    php index.php --ip=8.8.8.8
 *   CLI:    php index.php --health
 */

// 自动加载
require_once __DIR__ . '/vendor/autoload.php';

// CORS 头
function corsHeaders(): array
{
    return [
        'Access-Control-Allow-Origin'  => '*',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type',
        'Access-Control-Max-Age'       => '86400',
    ];
}

function jsonResponse(array $data, int $status = 200, array $extraHeaders = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach (corsHeaders() as $key => $val) {
        header("{$key}: {$val}");
    }
    foreach ($extraHeaders as $key => $val) {
        header("{$key}: {$val}");
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// ========== CLI 模式 ==========
if (php_sapi_name() === 'cli') {
    $opts = getopt('', ['ip:', 'health::']);
    $ip = $opts['ip'] ?? null;
    $health = array_key_exists('health', $opts);

    $query = new IpQuery();

    if ($health) {
        echo json_encode($query->healthCheck(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    if (!$ip) {
        echo "用法: php index.php --ip=8.8.8.8" . PHP_EOL;
        echo "      php index.php --health" . PHP_EOL;
        exit(1);
    }

    $result = $query->query($ip);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

// ========== Web 模式 ==========
// 处理 CORS 预检
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    foreach (corsHeaders() as $key => $val) {
        header("{$key}: {$val}");
    }
    exit;
}

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$queryParams = [];
if (isset($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $queryParams);
}

// 获取查询 IP
$targetIp = $queryParams['ip'] ?? null;
if (!$targetIp) {
    // 从请求头获取真实 IP
    $targetIp = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? null;
    // x-forwarded-for 可能包含多个 IP，取第一个
    if ($targetIp && strpos($targetIp, ',') !== false) {
        $targetIp = trim(explode(',', $targetIp)[0]);
    }
}

// 健康检查
if ($requestUri === '/health' || $requestUri === '/health/') {
    $query = new IpQuery();
    jsonResponse($query->healthCheck());
    exit;
}

// IP 参数校验
if (!$targetIp) {
    jsonResponse([
        'success' => false,
        'error'   => '无法获取 IP 地址，请使用 ?ip=xxx 参数',
    ], 400);
    exit;
}

// 查询
try {
    $query = new IpQuery();
    $result = $query->query($targetIp);
    $statusCode = $result['success'] ? 200 : 500;
    jsonResponse($result, $statusCode, [
        'Cache-Control' => 'public, max-age=3600',
    ]);
} catch (\Exception $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage(),
        'ip'      => $targetIp,
    ], 500);
}
