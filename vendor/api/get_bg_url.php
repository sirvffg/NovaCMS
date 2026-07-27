<?php
// api/get_bg_url.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // 允许跨域

require_once '../../config/functions.php';

$url = $_GET['url'] ?? '';

if (empty($url)) {
    echo json_encode(['success' => false, 'error' => 'No URL provided']);
    exit;
}

// 安全验证：防止SSRF攻击
$validatedUrl = validateUrl($url, ['http', 'https']);
if ($validatedUrl === false) {
    echo json_encode(['success' => false, 'error' => 'Invalid or unauthorized URL']);
    exit;
}

$url = $validatedUrl; // 使用验证后的URL

// 初始化curl
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 跟随重定向
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 忽略SSL证书错误
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

// 本站域名解析到127.0.0.1，避免服务器访问自己的公网IP时超时（DNS回环）
$parsed = parse_url($url);
$localHosts = ['wallpaper.lygalaxy.cn'];
if (isset($parsed['host']) && in_array(strtolower($parsed['host']), $localHosts, true)) {
    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    curl_setopt($ch, CURLOPT_RESOLVE, [
        strtolower($parsed['host']) . ':443:127.0.0.1',
        strtolower($parsed['host']) . ':80:127.0.0.1',
    ]);
}

// 执行
$response = curl_exec($ch);
$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$error = curl_error($ch);
curl_close($ch);

if ($response !== false) {
    // 尝试将最终URL转换为HTTPS
    if (strpos($effectiveUrl, 'bing.com') !== false || strpos($effectiveUrl, 'bing.net') !== false) {
        $effectiveUrl = str_replace('http://', 'https://', $effectiveUrl);
    }

    // 判断返回的是JSON还是图片
    $isJson = false;
    if (strpos($contentType, 'application/json') !== false) {
        $isJson = true;
    } else {
        // 尝试解析JSON
        $json = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $isJson = true;
        }
    }

    if ($isJson) {
        // 如果API直接返回JSON，直接透传，但需要确保里面的URL也是HTTPS
        // 这里简单透传，如果里面的URL是HTTP，前端可能还会报错，但通常API返回的JSON包含的URL是可用的
        // 最好解析一下JSON，把里面的http链接替换为https（如果是bing的话）
        $data = json_decode($response, true);
        if (isset($data['data']['url'])) {
             $data['data']['url'] = str_replace('http://', 'https://', $data['data']['url']);
        }
        if (isset($data['url'])) {
             $data['url'] = str_replace('http://', 'https://', $data['url']);
        }
        echo json_encode($data);
    } else {
        // 如果返回的是图片（通过ContentType或非JSON内容），则构造JSON响应
        // 这通常意味着发生了重定向，effectiveUrl就是图片地址
        echo json_encode([
            'success' => true,
            'data' => [
                'url' => $effectiveUrl,
                'url_mobile' => $effectiveUrl // 移动端也用同一个
            ]
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch URL: ' . $error
    ]);
}
