<?php
/**
 * QQ头像API
 * 获取指定QQ号的头像URL
 */

header('Content-Type: application/json; charset=utf-8');

// 获取QQ号参数
$qq = isset($_GET['qq']) ? trim($_GET['qq']) : '';

// 验证QQ号
if (empty($qq)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => '缺少QQ号参数',
        'message' => '请提供qq参数'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 验证QQ号格式（5-11位数字）
if (!preg_match('/^\d{5,11}$/', $qq)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'QQ号格式错误',
        'message' => 'QQ号必须是5-11位数字'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 获取头像尺寸参数，默认为100
$size = isset($_GET['size']) ? intval($_GET['size']) : 100;

// 支持的头像尺寸
$validSizes = [40, 100, 140, 640];
if (!in_array($size, $validSizes)) {
    $size = 100; // 默认尺寸
}

try {
    // QQ头像URL模板
    $avatarUrl = "https://q1.qlogo.cn/g?b=qq&nk={$qq}&s={$size}";
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'qq' => $qq,
        'avatar_url' => $avatarUrl,
        'size' => $size,
        'message' => '获取QQ头像成功'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    error_log('QQ avatar API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '服务器内部错误',
        'message' => '头像服务暂时不可用，请稍后重试'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
?>
