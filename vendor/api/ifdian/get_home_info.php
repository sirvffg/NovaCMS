<?php
/**
 * 获取爱发电主页完整信息 API
 * 返回用户详情 + 完整方案列表
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// 引入核心文件
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/AfdianAPI.php';

try {
    // 1. 获取配置
    $db = getDB();
    $config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
    
    $userId = $config['ifdian_user_id'] ?? '';
    $apiToken = $config['ifdian_api_token'] ?? '';
    $cookie = $config['ifdian_cookie'] ?? '';

    if (empty($userId) || empty($apiToken)) {
        throw new Exception("请先在后台配置爱发电 User ID 和 API Token");
    }

    $afdian = new AfdianAPI($userId, $apiToken);

    // 2. 获取用户主页信息（复用 getUserPostList 接口的第一条数据）
    // 如果没有 Cookie，这步可能拿不到完整信息，但我们尽量尝试
    $userInfo = [
        'name' => '未命名',
        'avatar' => '',
        'cover' => '',
        'url_slug' => '',
        'doing' => '',
        'detail' => ''
    ];

    if (!empty($cookie)) {
        try {
            $postList = $afdian->getUserPostList(1, $cookie);
            if (isset($postList['list'][0]['user'])) {
                $u = $postList['list'][0]['user'];
                $c = $u['creator'] ?? [];
                
                $userInfo = [
                    'name' => $u['name'],
                    'avatar' => $u['avatar'],
                    'cover' => $u['cover'],
                    'url_slug' => $u['url_slug'],
                    'doing' => $c['doing'] ?? '',
                    'detail' => $c['detail'] ?? ''
                ];
            }
        } catch (Exception $e) {
            // 忽略错误，使用默认值
        }
    }

    // 3. 获取方案列表
    // 先尝试用未公开接口获取 ID 列表
    $planIds = [];
    try {
        $url = "https://ifdian.net/api/creator/get-plans?user_id=" . $userId . "&album_id=&unlock_plan_ids=&diy=&affiliate_code=";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $json = json_decode($response, true);
            if (isset($json['data']['list'])) {
                foreach ($json['data']['list'] as $p) {
                    if (isset($p['status']) && $p['status'] == 0) continue; // 过滤下架
                    $planIds[] = [
                        'plan_id' => $p['plan_id'],
                        'price' => $p['price'],
                        'show_price' => $p['show_price'],
                        'product_type' => $p['product_type']
                    ];
                }
            }
        }
    } catch (Exception $e) {
        // 忽略
    }

    // 4. 逐个查询方案详情
    $plans = [];
    foreach ($planIds as $pInfo) {
        try {
            $detail = $afdian->queryPlan($pInfo['plan_id']);
            if (isset($detail['plan'])) {
                $d = $detail['plan'];
                
                $name = $d['name'] ?: '未命名方案';
                $productType = isset($d['product_type']) ? $d['product_type'] : $pInfo['product_type'];
                
                // 处理 SKU 名称拼接
                if ($productType == 1 && !empty($d['skus'])) {
                    $skuNames = array_column($d['skus'], 'name');
                    if (!empty($skuNames)) {
                        $name .= ' [' . implode('/', $skuNames) . ']';
                    }
                }

                // 生成购买链接
                $payUrl = "https://ifdian.net/order/create?plan_id={$pInfo['plan_id']}&product_type={$productType}";

                $plans[] = [
                    'plan_id' => $pInfo['plan_id'],
                    'name' => $name,
                    'price' => isset($d['show_price']) ? $d['show_price'] : (isset($pInfo['show_price']) ? $pInfo['show_price'] : '0.00'),
                    'desc' => $d['desc'] ?: ($d['reply_content'] ? '[自动回复] ' . mb_substr($d['reply_content'], 0, 20) . '...' : ''),
                    'pic' => $d['pic'] ?? '',
                    'pay_url' => $payUrl,
                    'type' => $productType == 0 ? '订阅' : '商品'
                ];
            }
        } catch (Exception $e) {
            // 单个失败不影响整体
        }
    }

    // 5. 返回最终结果
    echo json_encode([
        'success' => true,
        'user' => $userInfo,
        'plans' => $plans
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
