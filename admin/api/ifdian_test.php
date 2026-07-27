<?php
/**
 * 爱发电 API 测试接口
 */
session_start();
header('Content-Type: application/json');

// 关闭错误显示，只记录到日志
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 检查管理员登录
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }

    require_once '../../config/database.php';
    require_once '../../config/functions.php';
    require_once '../../vendor/api/ifdian/AfdianAPI.php';

    $db = getDB();
    $config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $page = (int)($_POST['page'] ?? 1);
    $perPage = (int)($_POST['per_page'] ?? 10);
    $param = $_POST['param'] ?? '';

    try {
        $userId = $config['ifdian_user_id'] ?? '';
        $apiToken = $config['ifdian_api_token'] ?? '';

        if (empty($userId) || empty($apiToken)) {
            echo json_encode(['success' => false, 'message' => '请先配置爱发电 User ID 和 API Token', 'debug' => ['userId' => $userId, 'hasToken' => !empty($apiToken)]]);
            exit;
        }

        $afdian = new AfdianAPI($userId, $apiToken);

        switch ($action) {
            case 'ping':
                $result = $afdian->ping();
                echo json_encode(['success' => true, 'message' => '连接成功', 'data' => $result]);
                break;
            
            case 'save_auto_reply':
                $planId = $_POST['plan_id'] ?? '';
                $replyContent = $_POST['reply_content'] ?? '';
                
                if (empty($planId)) {
                    echo json_encode(['success' => false, 'message' => '方案ID不能为空']);
                    exit;
                }
                
                // 确保表存在
                $db->exec("CREATE TABLE IF NOT EXISTS `ifdian_auto_replies` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `plan_id` varchar(64) NOT NULL COMMENT '方案ID',
                    `reply_content` text COMMENT '自动回复内容',
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_plan_id` (`plan_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='爱发电自动回复配置'");
                
                // 检查是否存在
                $check = $db->prepare("SELECT id FROM ifdian_auto_replies WHERE plan_id = ?");
                $check->execute([$planId]);
                
                if ($check->fetch()) {
                    $stmt = $db->prepare("UPDATE ifdian_auto_replies SET reply_content = ? WHERE plan_id = ?");
                    $stmt->execute([$replyContent, $planId]);
                } else {
                    $stmt = $db->prepare("INSERT INTO ifdian_auto_replies (plan_id, reply_content) VALUES (?, ?)");
                    $stmt->execute([$planId, $replyContent]);
                }
                
                echo json_encode(['success' => true, 'message' => '自动回复配置已保存']);
                break;

            case 'save_plan_config':
                $planId = $_POST['plan_id'] ?? '';
                $isShow = isset($_POST['is_show_in_thanks']) ? (int)$_POST['is_show_in_thanks'] : 1;
                $durationType = isset($_POST['show_duration_type']) ? (int)$_POST['show_duration_type'] : 0;
                $durationValue = isset($_POST['show_duration_value']) ? (int)$_POST['show_duration_value'] : 0;

                if (empty($planId)) {
                    echo json_encode(['success' => false, 'message' => '方案ID不能为空']);
                    exit;
                }

                // 确保表存在
                $db->exec("CREATE TABLE IF NOT EXISTS `ifdian_plan_configs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `plan_id` varchar(64) NOT NULL COMMENT '方案ID',
                    `is_show_in_thanks` tinyint(1) DEFAULT 1 COMMENT '是否在鸣谢页展示 (0:否, 1:是)',
                    `show_duration_type` tinyint(1) DEFAULT 0 COMMENT '展示时长类型 (0:永久, 1:按月, 2:按年, 3:直到过期, 4:按天)',
                    `show_duration_value` int(11) DEFAULT 0 COMMENT '时长数值',
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_plan_id` (`plan_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='爱发电方案配置'");

                // 检查是否存在
                $check = $db->prepare("SELECT id FROM ifdian_plan_configs WHERE plan_id = ?");
                $check->execute([$planId]);

                if ($check->fetch()) {
                    $stmt = $db->prepare("UPDATE ifdian_plan_configs SET is_show_in_thanks = ?, show_duration_type = ?, show_duration_value = ? WHERE plan_id = ?");
                    $stmt->execute([$isShow, $durationType, $durationValue, $planId]);
                } else {
                    $stmt = $db->prepare("INSERT INTO ifdian_plan_configs (plan_id, is_show_in_thanks, show_duration_type, show_duration_value) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$planId, $isShow, $durationType, $durationValue]);
                }

                // 清除前台缓存，以便立即生效
                $cacheFile = __DIR__ . '/../../config/cache/ifdian_sponsors.json';
                if (file_exists($cacheFile)) {
                    unlink($cacheFile);
                }

                echo json_encode(['success' => true, 'message' => '方案高级设置已保存']);
                break;
                
            case 'get_plan_config':
                $planId = $_POST['plan_id'] ?? '';
                
                if (empty($planId)) {
                    echo json_encode(['success' => false, 'message' => '方案ID不能为空']);
                    exit;
                }

                // 确保表存在(防止报错)
                $db->exec("CREATE TABLE IF NOT EXISTS `ifdian_plan_configs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `plan_id` varchar(64) NOT NULL COMMENT '方案ID',
                    `is_show_in_thanks` tinyint(1) DEFAULT 1 COMMENT '是否在鸣谢页展示 (0:否, 1:是)',
                    `show_duration_type` tinyint(1) DEFAULT 0 COMMENT '展示时长类型 (0:永久, 1:按月, 2:按年, 3:直到过期, 4:按天)',
                    `show_duration_value` int(11) DEFAULT 0 COMMENT '时长数值',
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_plan_id` (`plan_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='爱发电方案配置'");
                
                $stmt = $db->prepare("SELECT * FROM ifdian_plan_configs WHERE plan_id = ?");
                $stmt->execute([$planId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$result) {
                    // 默认值
                    $result = [
                        'plan_id' => $planId,
                        'is_show_in_thanks' => 1,
                        'show_duration_type' => 0,
                        'show_duration_value' => 0
                    ];
                }
                
                echo json_encode(['success' => true, 'message' => '获取成功', 'data' => $result]);
                break;
                
            case 'get_auto_reply':
                $planId = $_POST['plan_id'] ?? '';
                
                if (empty($planId)) {
                    echo json_encode(['success' => false, 'message' => '方案ID不能为空']);
                    exit;
                }
                
                // 确保表存在
                $db->exec("CREATE TABLE IF NOT EXISTS `ifdian_auto_replies` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `plan_id` varchar(64) NOT NULL COMMENT '方案ID',
                    `reply_content` text COMMENT '自动回复内容',
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_plan_id` (`plan_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='爱发电自动回复配置'");

                $stmt = $db->prepare("SELECT reply_content FROM ifdian_auto_replies WHERE plan_id = ?");
                $stmt->execute([$planId]);
                $result = $stmt->fetch();
                
                echo json_encode(['success' => true, 'message' => '获取成功', 'data' => $result]);
                break;

            case 'query_order':
                // param 作为 out_trade_no
                $result = $afdian->queryOrder($page, $param, $perPage);

                // 订单接口只有 user_id，没有头像昵称
                // 为了显示用户信息，我们需要收集所有 user_id，然后调用 querySponsor 批量查询用户信息
                // 这样虽然会多一次请求，但能极大提升体验
                if (isset($result['list']) && !empty($result['list'])) {
                    $userIds = [];
                    foreach ($result['list'] as $order) {
                        if (!empty($order['user_id'])) {
                            $userIds[] = $order['user_id'];
                        }
                    }
                    
                    if (!empty($userIds)) {
                        $userIds = array_unique($userIds);
                        // querySponsor 支持传入多个 user_id，用逗号分隔
                        $userIdStr = implode(',', $userIds);
                        
                        try {
                            // 注意：querySponsor 分页参数传1即可，per_page 设置大一点以容纳所有 ID
                            // 但官方接口对 user_id 查询可能有限制，如果 ID 太多可能需要分批，这里暂且假设一页能查完
                            $sponsorResult = $afdian->querySponsor(1, $userIdStr, count($userIds));
                            
                            // 建立 user_id => user_info 的映射
                            $userMap = [];
                            if (isset($sponsorResult['list'])) {
                                foreach ($sponsorResult['list'] as $sponsor) {
                                    if (isset($sponsor['user']['user_id'])) {
                                        $userMap[$sponsor['user']['user_id']] = $sponsor['user'];
                                    }
                                }
                            }
                            
                            // 将用户信息合并回订单列表
                            foreach ($result['list'] as &$order) {
                                if (isset($userMap[$order['user_id']])) {
                                    $order['user'] = $userMap[$order['user_id']];
                                }
                            }
                        } catch (Exception $e) {
                            // 忽略用户信息查询失败，前端会显示默认值
                        }
                    }
                }

                echo json_encode(['success' => true, 'message' => '查询成功', 'data' => $result]);
                break;

            case 'query_sponsor':
                // param 作为 user_id
                $result = $afdian->querySponsor($page, $param, $perPage);
                echo json_encode(['success' => true, 'message' => '查询成功', 'data' => $result]);
                break;

            case 'query_plan':
                // param 作为 plan_id
                if (empty($param)) {
                    // 如果未提供 plan_id，尝试不传参调用（虽然 API 可能不支持，但我们试试）
                    // 但 AfdianAPI::queryPlan 强制要求参数
                    // 这里我们为了测试，如果 param 为空，传空字符串
                }
                $result = $afdian->queryPlan($param);
                echo json_encode(['success' => true, 'message' => '查询成功', 'data' => $result]);
                break;

            case 'get_plan_list':
                // 尝试通过官方未公开的接口获取完整方案列表
                // https://ifdian.net/api/creator/get-plans?user_id=xxx
                // 注意：这个接口不需要签名，只需要 user_id
                
                $plans = [];
                $error = null;
                
                try {
                    // 使用传入的 userId，如果没配置则为空
                    if (empty($userId)) {
                        throw new Exception("User ID not configured");
                    }
                    
                    $url = "https://ifdian.net/api/creator/get-plans?user_id=" . $userId . "&album_id=&unlock_plan_ids=&diy=&affiliate_code=";
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    // 必须模拟真实的 User-Agent，否则会被爱发电拦截
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
                    
                    $response = curl_exec($ch);
                    
                    if (curl_errno($ch)) {
                        throw new Exception(curl_error($ch));
                    }
                    
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($httpCode === 200) {
                        $json = json_decode($response, true);
                        if (isset($json['ec']) && $json['ec'] === 200 && isset($json['data']['list'])) {
                            foreach ($json['data']['list'] as $p) {
                                // 过滤掉 status=0 (已下架) 的方案，如果需要可以不过滤
                                if (isset($p['status']) && $p['status'] == 0) continue;

                                $planData = [
                                    'plan_id' => $p['plan_id'],
                                    'name' => '加载中...', 
                                    'price' => $p['price'], // 这里有价格
                                    'show_price' => $p['show_price'],
                                    'desc' => '',
                                    'product_type' => $p['product_type'],
                                ];

                                // 强制调用官方 API 获取详细信息
                                try {
                                    $detail = $afdian->queryPlan($p['plan_id']);
                                    if (isset($detail['plan'])) {
                                        $d = $detail['plan'];
                                        
                                        $planData['name'] = $d['name'] ?: '未命名方案';
                                        $planData['price'] = $d['price'] ?: $p['price'];
                                        $planData['show_price'] = $d['show_price'] ?: $p['show_price'];
                                        $planData['desc'] = $d['desc'] ?: '';
                                        $planData['product_type'] = isset($d['product_type']) ? $d['product_type'] : $p['product_type'];

                                        // 如果没有描述，尝试显示自动回复内容
                                        if (empty($planData['desc']) && !empty($d['reply_content'])) {
                                            $planData['desc'] = '[自动回复] ' . mb_substr($d['reply_content'], 0, 20) . '...';
                                        }

                                        // 商品类型的 SKU 处理
                                        if ($planData['product_type'] == 1 && !empty($d['skus'])) {
                                            $skuNames = [];
                                            foreach ($d['skus'] as $sku) {
                                                if (!empty($sku['name'])) {
                                                    $skuNames[] = $sku['name'];
                                                }
                                            }
                                            if (!empty($skuNames)) {
                                                $planData['name'] .= ' [' . implode('/', $skuNames) . ']';
                                            }
                                        }
                                    }
                                } catch (Exception $e) {
                                    $planData['name'] = '查询失败 (ID:' . $p['plan_id'] . ')';
                                    $planData['desc'] = $e->getMessage();
                                }
                                
                                $plans[] = $planData;
                            }
                        }
                    }
                } catch (Exception $e) {
                    $error = $e->getMessage();
                    // 记录错误但不中断，继续尝试降级方案
                }

                // 如果通过 API 获取到了数据，直接返回
                if (!empty($plans)) {
                    echo json_encode(['success' => true, 'message' => '获取成功', 'data' => $plans]);
                    exit;
                }

                // 如果上面的接口没获取到（比如 user_id 填错了或者接口变了），回退到旧逻辑
                // 从赞助者列表中提取方案
                // 获取最近的赞助者 (尝试获取前 2 页以覆盖更多方案)
                $plans = [];
                $seenPlanIds = [];

                // 辅助函数：处理方案
                $processPlan = function($plan) use (&$plans, &$seenPlanIds) {
                    if (empty($plan['plan_id'])) return;
                    if (in_array($plan['plan_id'], $seenPlanIds)) return;
                    
                    $seenPlanIds[] = $plan['plan_id'];
                    $plans[] = [
                        'plan_id' => $plan['plan_id'],
                        'name' => $plan['name'] ?? '',
                        'price' => $plan['price'] ?? '0.00',
                        'show_price' => $plan['show_price'] ?? ($plan['price'] ?? '0.00'),
                        'desc' => $plan['desc'] ?? '',
                        'product_type' => $plan['product_type'] ?? 0,
                    ];
                };

                // 获取第一页赞助者
                $res1 = $afdian->querySponsor(1, '', 50);
                if (!empty($res1['list'])) {
                    foreach ($res1['list'] as $sponsor) {
                        // 检查 current_plan
                        if (!empty($sponsor['current_plan']['plan_id'])) {
                            $processPlan($sponsor['current_plan']);
                        }
                        // 检查 sponsor_plans
                        if (!empty($sponsor['sponsor_plans'])) {
                            foreach ($sponsor['sponsor_plans'] as $p) {
                                $processPlan($p);
                            }
                        }
                    }
                }

                // 尝试从订单列表中获取 (补充商品类方案)
                $resOrder = $afdian->queryOrder(1, '', 50);
                if (!empty($resOrder['list'])) {
                    foreach ($resOrder['list'] as $order) {
                        // 订单中的方案信息可能不如赞助者接口全，但至少有 plan_id
                        // 注意：订单接口返回的 sku_detail 可能包含更详细信息，但这里我们主要关注 plan_id
                        // 如果是商品，product_type = 1
                        if (!empty($order['plan_id'])) {
                             // 构造一个基本的 plan 对象，因为订单接口不一定返回 name/price 等详细信息(取决于 API 版本)
                             // 根据文档，订单对象有 plan_id, product_type, discount, show_amount 等
                             // 但没有 plan_name。不过 sku_detail 里有 name
                             
                             // 只有当这个 plan_id 还没见过时，我们才尝试去获取详情或者先占位
                             if (!in_array($order['plan_id'], $seenPlanIds)) {
                                 // 由于订单接口缺乏方案名称(除非是 SKU)，我们可以选择：
                                 // 1. 忽略 (还是依赖赞助者接口)
                                 // 2. 单独调用 queryPlan (太慢)
                                 // 3. 仅添加 ID，标记为"未知名称"
                                 
                                 // 这里尝试从 sku_detail 获取名称
                                 $name = '未知方案';
                                 if (!empty($order['sku_detail'])) {
                                     $name = $order['sku_detail'][0]['name'] ?? '未知商品';
                                 }
                                 
                                 $seenPlanIds[] = $order['plan_id'];
                                 $plans[] = [
                                     'plan_id' => $order['plan_id'],
                                     'name' => $name, // 订单接口可能不包含方案名，需注意
                                     'price' => $order['show_amount'] ?? '0.00',
                                     'show_price' => $order['show_amount'] ?? '0.00',
                                     'desc' => $order['remark'] ?? '',
                                     'product_type' => $order['product_type'] ?? 0,
                                 ];
                             }
                        }
                    }
                }

                // 如果数据较少，尝试获取第二页赞助者
                if (count($plans) < 5 && (!empty($res1['total_page']) && $res1['total_page'] > 1)) {
                     $res2 = $afdian->querySponsor(2, '', 50);
                     if (!empty($res2['list'])) {
                        foreach ($res2['list'] as $sponsor) {
                            if (!empty($sponsor['current_plan']['plan_id'])) {
                                $processPlan($sponsor['current_plan']);
                            }
                            if (!empty($sponsor['sponsor_plans'])) {
                                foreach ($sponsor['sponsor_plans'] as $p) {
                                    $processPlan($p);
                                }
                            }
                        }
                     }
                }

                echo json_encode(['success' => true, 'message' => '获取成功', 'data' => $plans]);
                break;

            case 'send_msg':
                $userId = $_POST['user_id'] ?? '';
                $content = $_POST['content'] ?? '';
                
                if (empty($userId) || empty($content)) {
                    echo json_encode(['success' => false, 'message' => '参数不完整']);
                    exit;
                }
                
                try {
                    $result = $afdian->sendMsg($userId, $content);
                    echo json_encode(['success' => true, 'message' => '发送成功', 'data' => $result]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => '发送失败: ' . $e->getMessage()]);
                }
                break;

            case 'get_post_list':
                $page = (int)($_POST['page'] ?? 1);
                $cookie = $config['ifdian_cookie'] ?? '';
                
                if (empty($cookie)) {
                    echo json_encode(['success' => false, 'message' => '请先在配置中填写 Cookie']);
                    exit;
                }
                
                try {
                    $result = $afdian->getUserPostList($page, $cookie);
                    
                    // 如果是第一页，提取置顶信息（如果有）
                    $userData = null;
                    if ($page === 1 && isset($result['list']) && !empty($result['list'])) {
                        // 尝试从第一条动态中提取用户信息（虽然不一定准确，但通常第一条包含user字段）
                        if (isset($result['list'][0]['user'])) {
                            $userData = $result['list'][0]['user'];
                        }
                    }
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => '获取成功', 
                        'data' => $result,
                        'user_data' => $userData
                    ]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => '获取失败: ' . $e->getMessage()]);
                }
                break;

            case 'get_manual_list':
                // 确保表存在
                $db->exec("CREATE TABLE IF NOT EXISTS `ifdian_manual_sponsors` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `name` varchar(100) NOT NULL COMMENT '名称',
                    `qq` varchar(20) DEFAULT '' COMMENT 'QQ号',
                    `avatar` varchar(255) DEFAULT '' COMMENT '头像URL',
                    `description` varchar(255) DEFAULT '' COMMENT '描述',
                    `link` varchar(255) DEFAULT '' COMMENT '链接',
                    `sort_order` int(11) DEFAULT 0 COMMENT '排序(小到大)',
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='手动鸣谢列表'");
                
                // 检查并添加 qq 字段
                $checkStmt = $db->query("SHOW COLUMNS FROM ifdian_manual_sponsors LIKE 'qq'");
                if (!$checkStmt->fetch()) {
                    $db->exec("ALTER TABLE ifdian_manual_sponsors ADD COLUMN qq VARCHAR(20) DEFAULT '' COMMENT 'QQ号' AFTER name");
                }

                $stmt = $db->query("SELECT * FROM ifdian_manual_sponsors ORDER BY sort_order ASC, created_at DESC");
                $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => '未知操作', 'action' => $action]);
        }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '错误: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
