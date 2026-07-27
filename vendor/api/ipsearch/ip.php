<?php
/**
 * IP 查询接口 (兼容旧版)
 * 
 * 已关联至 index.php 的统一查询入口
 * 返回格式保持与旧版兼容: { code, ip, country, isp }
 * 
 * 使用方式: GET /ip.php?ip=8.8.8.8
 */

require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // 获取查询 IP
    $ip = $_GET['ip'] ?? null;
    if (!$ip) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? null;
        if ($ip && strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
    }

    if (!$ip) {
        echo json_encode([
            'code'    => 1,
            'ip'      => '',
            'country' => '',
            'isp'     => '',
            'error'   => '无法获取 IP 地址',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 调用统一的 IpQuery 类
    $query = new IpQuery();
    $result = $query->query($ip);

    if (!$result['success']) {
        echo json_encode([
            'code'    => 1,
            'ip'      => $ip,
            'country' => '',
            'isp'     => '',
            'error'   => $result['error'] ?? '查询失败',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 兼容旧版格式输出
    $location = $result['location'] ?? [];
    $fullLocation = $result['full_location'] ?? '';

    echo json_encode([
        'code'    => 0,
        'ip'      => $ip,
        'country' => $fullLocation ?: ($location['country'] ?? ''),
        'isp'     => $location['isp'] ?? '',
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'code'    => 1,
        'ip'      => $ip ?? '',
        'country' => '',
        'isp'     => '',
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
