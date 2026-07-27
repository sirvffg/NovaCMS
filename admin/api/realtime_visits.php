<?php
// 清除所有输出缓冲
if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

session_start();

// 如果未登录，返回错误
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once '../../config/database.php';
require_once '../../config/functions.php';

$db = getDB();

// 获取最近15分钟的访问记录
$stmt = $db->prepare("
    SELECT 
        ip_address,
        page_url,
        user_agent,
        visit_time,
        visitor_username,
        visitor_email,
        CASE 
            WHEN visit_time >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 'online'
            WHEN visit_time >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 'recent'
            ELSE 'offline'
        END as status,
        TIMESTAMPDIFF(MINUTE, visit_time, NOW()) as minutes_ago
    FROM visit_stats 
    WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ORDER BY visit_time DESC
    LIMIT 20
");

try {
    $stmt->execute();
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}
$visits = $stmt->fetchAll();

// 格式化数据
$formattedVisits = [];
foreach ($visits as $visit) {
    // 获取IP地理位置信息
    $location = getIPLocation($visit['ip_address']);
    
    $formattedVisits[] = [
        'ip_address' => $visit['ip_address'],
        'location' => $location,
        'page_url' => $visit['page_url'] ?: '/',
        'user_agent' => $visit['user_agent'] ?: 'Unknown',
        'status' => $visit['status'],
        'time_ago' => formatTimeAgo($visit['visit_time']),
        'visitor_username' => $visit['visitor_username'] ?? null,
        'visitor_email' => $visit['visitor_email'] ?? null
    ];
}

// 如果没有最近的访问数据，获取一些历史数据作为演示
if (empty($formattedVisits)) {
    $fallbackStmt = $db->prepare("
        SELECT 
            ip_address,
            page_url,
            user_agent,
            visit_time,
            visitor_username,
            visitor_email,
            'offline' as status
        FROM visit_stats 
        ORDER BY visit_time DESC
        LIMIT 5
    ");
    $fallbackStmt->execute();
    $fallbackVisits = $fallbackStmt->fetchAll();
    
    foreach ($fallbackVisits as $visit) {
        // 获取IP地理位置信息
        $location = getIPLocation($visit['ip_address']);
        
        $formattedVisits[] = [
            'ip_address' => $visit['ip_address'],
            'location' => $location,
            'page_url' => $visit['page_url'] ?: '/',
            'user_agent' => $visit['user_agent'] ?: 'Unknown',
            'status' => 'demo',
            'time_ago' => formatTimeAgo($visit['visit_time']) . ' (历史数据)',
            'visitor_username' => $visit['visitor_username'] ?? null,
            'visitor_email' => $visit['visitor_email'] ?? null
        ];
    }
}

// 清理过期的缓存文件（可选，仅在特定情况下执行）
if (isset($_GET['clear_cache']) && $_GET['clear_cache'] === 'true') {
    clearIPCache();
    echo json_encode(['success' => true, 'message' => 'IP地理位置缓存已清理']);
    exit;
}

// 获取当前在线人数（最近5分钟）
$onlineStmt = $db->prepare("
    SELECT COUNT(DISTINCT ip_address) as online_count
    FROM visit_stats 
    WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
");
$onlineStmt->execute();
$onlineCount = $onlineStmt->fetch()['online_count'];

// 获取今日页面访问统计
$todayStmt = $db->prepare("
    SELECT 
        page_url,
        COUNT(*) as visits,
        COUNT(DISTINCT ip_address) as unique_visitors
    FROM visit_stats 
    WHERE DATE(visit_time) = CURDATE()
    GROUP BY page_url
    ORDER BY visits DESC
    LIMIT 10
");
$todayStmt->execute();
$todayStats = $todayStmt->fetchAll();

echo json_encode([
    'visits' => $formattedVisits,
    'online_count' => $onlineCount,
    'today_stats' => $todayStats,
    'total_today' => getTotalVisitsToday()
]);

/**
 * 格式化时间差为可读格式
 */
function formatTimeAgo($datetime) {
    $now = new DateTime();
    $past = new DateTime($datetime);
    $interval = $now->diff($past);
    
    if ($interval->i < 1) {
        return '刚刚';
    } elseif ($interval->i < 60) {
        return $interval->i . '分钟前';
    } elseif ($interval->h < 24) {
        return $interval->h . '小时前';
    } else {
        return $interval->d . '天前';
    }
}

/**
 * 获取今日访问量
 */
function getTotalVisitsToday() {
    try {
        $db = getDB();
        $stmt = $db->query("SELECT COUNT(*) as total FROM visit_stats WHERE DATE(visit_time) = CURDATE()");
        return $stmt->fetch()['total'];
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 获取IP地址的地理位置信息
 * 使用 IpQuery 类（纯真 + GeoCN + 行政区划），失败后回退到远程API
 */
function getIPLocation($ip) {
    // 本地IP地址直接返回（RFC 1918私有地址：10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16）
    $isPrivate = in_array($ip, ['127.0.0.1', '::1', 'localhost'])
        || strpos($ip, '192.168.') === 0
        || strpos($ip, '10.') === 0
        || (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip));
    if ($isPrivate) {
        return [
            'country' => '中国',
            'region' => '局域网',
            'city' => '内网',
            'location' => '本地网络'
        ];
    }
    
    // 尝试从缓存获取
    $cacheKey = 'ip_location_' . md5($ip);
    $cacheFile = sys_get_temp_dir() . '/' . $cacheKey;
    
    // 检查缓存是否存在且未过期（24小时）
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $cachedData = file_get_contents($cacheFile);
        if ($cachedData) {
            $data = json_decode($cachedData, true);
            if ($data && !empty($data['location']) && $data['location'] !== '位置未知') {
                return $data;
            }
        }
    }
    
    // === 使用 IpQuery 类查询（纯真 + GeoCN + 行政区划） ===
    try {
        $autoloadFile = dirname(__DIR__, 2) . '/vendor/api/ipsearch/vendor/autoload.php';
        if (file_exists($autoloadFile)) {
            require_once $autoloadFile;
            $ipQuery = new IpQuery();
            $queryResult = $ipQuery->query($ip);

            if ($queryResult['success'] && !empty($queryResult['location'])) {
                $loc = $queryResult['location'];
                $country  = $loc['country'] ?? '';
                $region   = $loc['province'] ?? '';
                $city     = $loc['city'] ?? '';
                $isp      = $loc['isp'] ?? '';

                // 构建位置字符串
                $locationStr = trim(implode(' ', array_filter([$country, $region, $city, $isp])));

                $result = [
                    'country'  => $country,
                    'region'   => $region,
                    'city'     => $city,
                    'isp'      => $isp,
                    'location' => $locationStr ?: '位置未知'
                ];

                file_put_contents($cacheFile, json_encode($result));
                return $result;
            }
        }
    } catch (Exception $e) {
        error_log("IpQuery failed for {$ip}: " . $e->getMessage());
    }
    
    // === 回退到远程API ===
    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);
        
        // 使用 ip-api.com (支持中文，非商业用途免费，限制每分钟45次)
        $url = "http://ip-api.com/json/{$ip}?lang=zh-CN";
        $response = @file_get_contents($url, false, $context);
        
        if ($response !== false) {
            $json = json_decode($response, true);
            
            if ($json && $json['status'] === 'success') {
                $country = $json['country'] ?? '';
                $region = $json['regionName'] ?? '';
                $city = $json['city'] ?? '';
                $isp = $json['isp'] ?? '';
                
                // 构建位置字符串
                $location = $country;
                if ($region && $region !== $country) $location .= ' ' . $region;
                if ($city && $city !== $region) $location .= ' ' . $city;
                if ($isp) $location .= ' ' . $isp;
                
                $result = [
                    'country' => $country,
                    'region' => $region,
                    'city' => $city,
                    'isp' => $isp,
                    'location' => trim($location) ?: '位置未知'
                ];
                
                file_put_contents($cacheFile, json_encode($result));
                return $result;
            }
        }
    } catch (Exception $e) {
        error_log("IP-API failed for {$ip}: " . $e->getMessage());
    }

    // 备用API：太平洋电脑网 (国内IP准确)
    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
            ]
        ]);
        
        // 注意：whois.pconline.com.cn 返回的是GBK编码
        $url = "http://whois.pconline.com.cn/ipJson.jsp?ip={$ip}&json=true";
        $response = @file_get_contents($url, false, $context);
        
        if ($response !== false) {
            // 尝试转码 GBK -> UTF-8
            $response = mb_convert_encoding($response, 'UTF-8', 'GBK');
            
            // 提取 JSON 部分
            if (preg_match('/\{.*\}/s', $response, $matches)) {
                $json = json_decode($matches[0], true);
                
                if ($json && (!isset($json['err']) || empty($json['err']))) {
                    $addr = $json['addr'] ?? '';
                    $pro = $json['pro'] ?? '';
                    $city = $json['city'] ?? '';
                    $region = $json['region'] ?? '';
                    
                    // 组合地址
                    $location = $addr;
                    if (empty($location)) {
                        $location = $pro . ' ' . $city . ' ' . $region;
                    }
                    
                    $result = [
                        'country' => '中国',
                        'region' => $pro,
                        'city' => $city,
                        'location' => trim($location) ?: '位置未知'
                    ];
                    
                    file_put_contents($cacheFile, json_encode($result));
                    return $result;
                }
            }
        }
    } catch (Exception $e) {
        error_log("PConline API failed for {$ip}: " . $e->getMessage());
    }
    
    // 最后的备用：ipapi.co
    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);
        
        $url = "https://ipapi.co/{$ip}/json/";
        $response = @file_get_contents($url, false, $context);
        
        if ($response !== false) {
            $json = json_decode($response, true);
            
            if ($json && !isset($json['error'])) {
                $country_en = $json['country_name'] ?? '未知';
                $region_en = $json['region'] ?? '未知';
                $city_en = $json['city'] ?? '未知';
                
                // 英文转中文
                $country_cn = translateCountryToChinese($country_en);
                $region_cn = translateRegionToChinese($region_en);
                $city_cn = translateCityToChinese($city_en);
                
                $result = [
                    'country' => $country_cn,
                    'region' => $region_cn,
                    'city' => $city_cn,
                    'country_en' => $country_en,
                    'region_en' => $region_en,
                    'city_en' => $city_en,
                    'location' => trim($country_cn . ' ' . $region_cn . ' ' . $city_cn)
                ];
                
                // 缓存结果
                file_put_contents($cacheFile, json_encode($result));
                return $result;
            }
        }
    } catch (Exception $e) {
        // 继续尝试下一个备用API
        error_log("Backup IP API 1 failed for {$ip}: " . $e->getMessage());
    }
    
    // 第三个备用API：使用xxapi.cn的IP查询API
    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);
        
        $url = "https://v2.xxapi.cn/api/ip?ip={$ip}";
        $response = @file_get_contents($url, false, $context);
        
        if ($response !== false) {
            $json = json_decode($response, true);
            
            // 检查是否获取到有效数据
            if ($json && isset($json['code']) && $json['code'] === 200 && isset($json['data']['address'])) {
                $address = $json['data']['address'];
                $type = $json['data']['type'] ?? '';
                
                // 解析地址信息，例如："中国浙江温州 电信"
                if (preg_match('/^中国(.+?)(?:市|省|自治区|特别行政区)(.+?)(?:市|县|区)(?:\s+(.+))?$/', $address, $matches)) {
                    $country = '中国';
                    $province = $matches[1];
                    $city = $matches[2];
                    $operator = isset($matches[3]) ? $matches[3] : '';
                } elseif (preg_match('/^中国(.+?)\s+(.+)$/', $address, $matches)) {
                    $country = '中国';
                    $location_parts = explode(' ', $matches[1]);
                    $province = $location_parts[0] ?? '';
                    $city = $location_parts[1] ?? $province;
                    $operator = $matches[2] ?? '';
                } else {
                    $country = '中国';
                    $province = '未知省份';
                    $city = '未知城市';
                    $operator = $address;
                }
                
                $location = trim($province . ' ' . $city);
                
                $result = [
                    'country' => $country,
                    'region' => $province,
                    'city' => $city,
                    'region_detail' => '',
                    'addr' => trim($address . ($type ? " [{$type}]" : '')),
                    'location' => $location ?: '位置未知'
                ];
                
                // 缓存结果
                file_put_contents($cacheFile, json_encode($result));
                return $result;
            }
        }
    } catch (Exception $e) {
        // 所有API都失败
    }
    
    // 所有API都失败，返回默认值
    $defaultLocation = [
        'country' => '未知',
        'region' => '未知',
        'city' => '未知',
        'location' => '位置未知'
    ];
    
    // 缓存默认值避免重复请求
    file_put_contents($cacheFile, json_encode($defaultLocation));
    return $defaultLocation;
}

/**
 * 英文国家名转中文
 */
function translateCountryToChinese($country_en) {
    $translations = [
        'United States' => '美国',
        'China' => '中国',
        'Hong Kong' => '香港',
        'Singapore' => '新加坡',
        'Japan' => '日本',
        'South Korea' => '韩国',
        'Taiwan' => '台湾',
        'Indonesia' => '印度尼西亚',
        'Malaysia' => '马来西亚',
        'Thailand' => '泰国',
        'Vietnam' => '越南',
        'Philippines' => '菲律宾',
        'India' => '印度',
        'Australia' => '澳大利亚',
        'Canada' => '加拿大',
        'United Kingdom' => '英国',
        'Germany' => '德国',
        'France' => '法国',
        'Russia' => '俄罗斯',
        'Brazil' => '巴西',
        'Netherlands' => '荷兰',
        'Switzerland' => '瑞士',
        'Sweden' => '瑞典',
        'Norway' => '挪威',
        'Denmark' => '丹麦',
        'Finland' => '芬兰',
        'Italy' => '意大利',
        'Spain' => '西班牙',
        'Poland' => '波兰',
        'Turkey' => '土耳其',
        'Mexico' => '墨西哥',
        'Argentina' => '阿根廷',
        'Chile' => '智利',
        'Colombia' => '哥伦比亚',
        'Peru' => '秘鲁',
        'Egypt' => '埃及',
        'South Africa' => '南非',
        'Kenya' => '肯尼亚',
        'Nigeria' => '尼日利亚',
        'Ghana' => '加纳',
        'Morocco' => '摩洛哥',
        'New Zealand' => '新西兰',
        'Ireland' => '爱尔兰',
        'Belgium' => '比利时',
        'Austria' => '奥地利',
        'Portugal' => '葡萄牙',
        'Greece' => '希腊',
        'Czech Republic' => '捷克',
        'Hungary' => '匈牙利',
        'Romania' => '罗马尼亚',
        'Bulgaria' => '保加利亚',
        'Croatia' => '克罗地亚',
        'Slovakia' => '斯洛伐克',
        'Slovenia' => '斯洛文尼亚',
        'Estonia' => '爱沙尼亚',
        'Latvia' => '拉脱维亚',
        'Lithuania' => '立陶宛',
        'Luxembourg' => '卢森堡',
        'Monaco' => '摩纳哥',
        'Cyprus' => '塞浦路斯',
        'Malta' => '马耳他',
        'Iceland' => '冰岛'
    ];
    
    return $translations[$country_en] ?? $country_en;
}

/**
 * 英文地区/州名转中文
 */
function translateRegionToChinese($region_en) {
    $translations = [
        // 美国州份
        'California' => '加利福尼亚州',
        'New York' => '纽约州',
        'Texas' => '德克萨斯州',
        'Florida' => '佛罗里达州',
        'Illinois' => '伊利诺伊州',
        'Pennsylvania' => '宾夕法尼亚州',
        'Ohio' => '俄亥俄州',
        'Georgia' => '乔治亚州',
        'Michigan' => '密歇根州',
        'North Carolina' => '北卡罗来纳州',
        'New Jersey' => '新泽西州',
        'Virginia' => '弗吉尼亚州',
        'Washington' => '华盛顿州',
        'Arizona' => '亚利桑那州',
        'Massachusetts' => '马萨诸塞州',
        'Tennessee' => '田纳西州',
        'Indiana' => '印第安纳州',
        'Missouri' => '密苏里州',
        'Maryland' => '马里兰州',
        'Wisconsin' => '威斯康星州',
        'Colorado' => '科罗拉多州',
        'Minnesota' => '明尼苏达州',
        'South Carolina' => '南卡罗来纳州',
        'Alabama' => '阿拉巴马州',
        'Louisiana' => '路易斯安那州',
        'Kentucky' => '肯塔基州',
        'Oregon' => '俄勒冈州',
        'Oklahoma' => '俄克拉何马州',
        'Connecticut' => '康涅狄格州',
        'Utah' => '犹他州',
        'Iowa' => '爱荷华州',
        'Nevada' => '内华达州',
        'Arkansas' => '阿肯色州',
        'Mississippi' => '密西西比州',
        'Kansas' => '堪萨斯州',
        'New Mexico' => '新墨西哥州',
        'Nebraska' => '内布拉斯加州',
        'West Virginia' => '西弗吉尼亚州',
        'Idaho' => '爱达荷州',
        'Hawaii' => '夏威夷州',
        'New Hampshire' => '新罕布什尔州',
        'Maine' => '缅因州',
        'Montana' => '蒙大拿州',
        'Rhode Island' => '罗得岛州',
        'Delaware' => '特拉华州',
        'South Dakota' => '南达科他州',
        'North Dakota' => '北达科他州',
        'Alaska' => '阿拉斯加州',
        'Vermont' => '佛蒙特州',
        'Wyoming' => '怀俄明州',
        'District of Columbia' => '哥伦比亚特区',
        
        // 其他国家地区
        'Ontario' => '安大略省',
        'Quebec' => '魁北克省',
        'British Columbia' => '不列颠哥伦比亚省',
        'Alberta' => '阿尔伯塔省',
        'Saskatchewan' => '萨斯喀彻温省',
        'Manitoba' => '曼尼托巴省',
        'New Brunswick' => '新不伦瑞克省',
        'Nova Scotia' => '新斯科舍省',
        'Prince Edward Island' => '爱德华王子岛省',
        'Newfoundland and Labrador' => '纽芬兰和拉布拉多省',
        'Yukon' => '育空地区',
        'Northwest Territories' => '西北地区',
        'Nunavut' => '努纳武特地区',
        
        'England' => '英格兰',
        'Scotland' => '苏格兰',
        'Wales' => '威尔士',
        'Northern Ireland' => '北爱尔兰',
        
        'Bali' => '巴厘岛',
        'Bangli' => '邦利',
        'Tsuen Wan District' => '荃湾区',
        'Tsuen Wan' => '荃湾'
    ];
    
    return $translations[$region_en] ?? $region_en;
}

/**
 * 英文城市名转中文
 */
function translateCityToChinese($city_en) {
    $translations = [
        // 美国城市
        'New York' => '纽约',
        'Los Angeles' => '洛杉矶',
        'Chicago' => '芝加哥',
        'Houston' => '休斯顿',
        'Phoenix' => '凤凰城',
        'Philadelphia' => '费城',
        'San Antonio' => '圣安东尼奥',
        'San Diego' => '圣地亚哥',
        'Dallas' => '达拉斯',
        'San Jose' => '圣何塞',
        'Austin' => '奥斯汀',
        'Jacksonville' => '杰克逊维尔',
        'Fort Worth' => '沃斯堡',
        'Columbus' => '哥伦布',
        'Charlotte' => '夏洛特',
        'San Francisco' => '旧金山',
        'Indianapolis' => '印第安纳波利斯',
        'Seattle' => '西雅图',
        'Denver' => '丹佛',
        'Washington' => '华盛顿',
        'Boston' => '波士顿',
        'El Paso' => '埃尔帕索',
        'Nashville' => '纳什维尔',
        'Detroit' => '底特律',
        'Oklahoma City' => '俄克拉何马城',
        'Portland' => '波特兰',
        'Las Vegas' => '拉斯维加斯',
        'Memphis' => '孟菲斯',
        'Louisville' => '路易斯维尔',
        'Milwaukee' => '密尔沃基',
        'Baltimore' => '巴尔的摩',
        'Albuquerque' => '阿尔伯克基',
        'Tucson' => '图森',
        'Fresno' => '弗雷斯诺',
        'Mesa' => '梅萨',
        'Sacramento' => '萨克拉门托',
        'Atlanta' => '亚特兰大',
        'Kansas City' => '堪萨斯城',
        'Colorado Springs' => '科罗拉多斯普林斯',
        'Miami' => '迈阿密',
        'Raleigh' => '罗利',
        'Omaha' => '奥马哈',
        'Long Beach' => '长滩',
        'Virginia Beach' => '弗吉尼亚海滩',
        'Oakland' => '奥克兰',
        'Minneapolis' => '明尼阿波利斯',
        'Tampa' => '坦帕',
        'Tulsa' => '塔尔萨',
        'Arlington' => '阿灵顿',
        'New Orleans' => '新奥尔良',
        'Wichita' => '威奇托',
        'Cleveland' => '克利夫兰',
        'Bakersfield' => '贝克斯菲尔德',
        'Aurora' => '奥罗拉',
        'Anaheim' => '阿纳海姆',
        'Honolulu' => '檀香山',
        'Santa Ana' => '圣安娜',
        'Riverside' => '河滨',
        'Corpus Christi' => '科珀斯克里斯蒂',
        'Lexington' => '列克星敦',
        'Henderson' => '亨德森',
        'Stockton' => '斯托克顿',
        'St. Paul' => '圣保罗',
        'Cincinnati' => '辛辛那提',
        'Irvine' => '尔湾',
        'Greensboro' => '格林斯伯勒',
        'Plano' => '普莱诺',
        'Newark' => '纽瓦克',
        'Toledo' => '托莱多',
        'St. Louis' => '圣路易斯',
        
        // 其他国家城市
        'London' => '伦敦',
        'Toronto' => '多伦多',
        'Sydney' => '悉尼',
        'Melbourne' => '墨尔本',
        'Berlin' => '柏林',
        'Paris' => '巴黎',
        'Moscow' => '莫斯科',
        'Tokyo' => '东京',
        'Seoul' => '首尔',
        'Singapore' => '新加坡',
        'Bangkok' => '曼谷',
        'Jakarta' => '雅加达',
        'Manila' => '马尼拉',
        'Kuala Lumpur' => '吉隆坡',
        'Ho Chi Minh City' => '胡志明市',
        'Mumbai' => '孟买',
        'Delhi' => '德里',
        'São Paulo' => '圣保罗',
        'Mexico City' => '墨西哥城',
        'Buenos Aires' => '布宜诺斯艾利斯',
        'Cairo' => '开罗',
        'Lagos' => '拉各斯',
        'Cape Town' => '开普敦',
        'Bangalore' => '班加罗尔',
        'Istanbul' => '伊斯坦布尔',
        'Amsterdam' => '阿姆斯特丹',
        'Brussels' => '布鲁塞尔',
        'Vienna' => '维也纳',
        'Zurich' => '苏黎世',
        'Stockholm' => '斯德哥尔摩',
        'Oslo' => '奥斯陆',
        'Copenhagen' => '哥本哈根',
        'Helsinki' => '赫尔辛基',
        'Rome' => '罗马',
        'Madrid' => '马德里',
        'Warsaw' => '华沙',
        'Athens' => '雅典',
        'Prague' => '布拉格',
        'Budapest' => '布达佩斯',
        'Dublin' => '都柏林',
        'Lisbon' => '里斯本',
        'Auckland' => '奥克兰',
        'Wellington' => '惠灵顿',
        
        'Santa Clara' => '圣克拉拉',
        'Boydton' => '博伊顿',
        'Tsuen Wan' => '荃湾'
    ];
    
    return $translations[$city_en] ?? $city_en;
}

/**
 * 清理IP地理位置缓存
 */
function clearIPCache() {
    $cacheDir = sys_get_temp_dir();
    $files = glob($cacheDir . '/ip_location_*');
    
    if ($files) {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
