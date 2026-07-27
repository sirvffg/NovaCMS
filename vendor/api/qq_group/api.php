<?php
// api.php

// 允许跨域请求（如果前端和后端不在同一个域名下）
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// 获取参数
$url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($url)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '缺少 url 参数']);
    exit;
}

if (strpos($url, 'http') !== 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '链接格式不正确']);
    exit;
}

// 初始化 cURL 会话
$ch = curl_init();

// 设置 cURL 选项
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// 启用自动跳转，因为 qm.qq.com 会 302 跳转到 qun.qq.com
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
// 设置最大重定向次数
curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
// 模拟浏览器 User-Agent，避免被拦截
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
// 忽略 SSL 证书验证（在本地或没有配置好证书的环境下很有用）
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

// 执行请求并获取 HTML 源码
$html = curl_exec($ch);

// 检查是否有错误
if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '请求失败: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}

curl_close($ch);

// 使用正则表达式提取 Nuxt 数据
$pattern = '/<script type="application\/json" data-nuxt-data="nuxt-app"[^>]*>(.*?)<\/script>/s';
if (preg_match($pattern, $html, $matches)) {
    $jsonStr = $matches[1];
    $nuxtData = json_decode($jsonStr, true);

    if (is_array($nuxtData)) {
        foreach ($nuxtData as $item) {
            if (is_string($item) && strpos($item, '"groupinfo"') !== false) {
                $parsed = json_decode($item, true);
                
                if (isset($parsed['groupinfo']) || isset($parsed['base_info']['groupinfo'])) {
                    $groupinfo = isset($parsed['base_info']['groupinfo']) ? $parsed['base_info']['groupinfo'] : $parsed['groupinfo'];
                    
                    // 构造返回数据
                    $result = [
                        'name' => isset($groupinfo['name']) ? $groupinfo['name'] : '未知',
                        'groupCode' => isset($groupinfo['groupcode']) ? $groupinfo['groupcode'] : '未知',
                        'memberCount' => isset($groupinfo['memberCnt']) ? $groupinfo['memberCnt'] : 0,
                        'description' => isset($groupinfo['description']) ? $groupinfo['description'] : null,
                        'tags' => isset($groupinfo['tags']) ? $groupinfo['tags'] : null,
                        'avatar' => isset($groupinfo['avatar']) ? $groupinfo['avatar'] : null,
                        'createTime' => isset($groupinfo['createtime']) ? date('Y/n/j H:i:s', $groupinfo['createtime']) : '未知',
                        'activity' => isset($parsed['activity']) ? $parsed['activity'] : null,
                        'memberTags' => isset($parsed['member_info']['member_tags']) ? $parsed['member_info']['member_tags'] : null,
                        'assetInfo' => isset($parsed['asset_info']['resource_infos']) ? $parsed['asset_info']['resource_infos'] : null
                    ];
                    
                    echo json_encode(['success' => true, 'info' => $result], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        }
    }
    
    echo json_encode(['success' => false, 'error' => '成功提取页面数据，但未匹配到群信息字段。']);
} else {
    echo json_encode(['success' => false, 'error' => '无法找到群信息数据，可能页面结构已改变。']);
}
?>