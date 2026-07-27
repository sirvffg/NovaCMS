<?php
/**
 * IP归属地更新 - SSE 流式端点
 * 逐一查询并更新 IP 归属地，实时推送日志到前端
 */
session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

require_once '../../config/database.php';
require_once dirname(__DIR__, 2) . '/vendor/api/ipsearch/vendor/autoload.php';

// === SSE 头 ===
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');  // 禁用 nginx 缓冲

function sseEvent($event, $data) {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

$db = getDB();

// === 统计待更新 IP 总数 ===
$total = (int)$db->query("
    SELECT COUNT(DISTINCT ip_address) 
    FROM visit_stats 
    WHERE (country IS NULL OR country = '' 
        OR (country = '中国' AND (province IS NULL OR province = '')))
    AND ip_address != 'unknown'
")->fetchColumn();

if ($total === 0) {
    sseEvent('done', ['message' => '没有待更新的IP', 'updated' => 0, 'total' => 0]);
    exit;
}

sseEvent('init', ['total' => $total]);

// === 分批逐一查询并更新，直到全部处理完 ===
$updated   = 0;
$processed = 0;
$batchSize = 50;

while ($processed < $total) {
    $ips = $db->query("
        SELECT DISTINCT ip_address 
        FROM visit_stats 
        WHERE (country IS NULL OR country = '' 
            OR (country = '中国' AND (province IS NULL OR province = '')))
        AND ip_address != 'unknown'
        ORDER BY ip_address
        LIMIT {$batchSize}
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ips as $ip) {
        $processed++;
        try {
            $ipQuery = new IpQuery();
            $result = $ipQuery->query($ip);

            if ($result['success'] && !empty($result['location'])) {
                $loc = $result['location'];
                $geoData = [
                    'country'  => $loc['country'] ?? '',
                    'province' => $loc['province'] ?? '',
                    'city'     => $loc['city'] ?? '',
                    'isp'      => $loc['isp'] ?? ''
                ];

                // 跳过无效结果
                if (empty($geoData['country'])) {
                    sseEvent('log', [
                        'index'   => $processed,
                        'total'   => $total,
                        'ip'      => $ip,
                        'status'  => 'error',
                        'message' => '未能识别归属地',
                        'country' => '',
                        'source'  => ''
                    ]);
                    continue;
                }

                // 写入数据库
                $stmt = $db->prepare("
                    UPDATE visit_stats 
                    SET country = ?, province = ?, city = ?, isp = ?
                    WHERE ip_address = ?
                ");
                $stmt->execute([
                    $geoData['country'],
                    $geoData['province'],
                    $geoData['city'],
                    $geoData['isp'],
                    $ip
                ]);

                $locStr = implode(' ', array_filter([
                    $geoData['country'],
                    $geoData['province'],
                    $geoData['city']
                ]));

                sseEvent('log', [
                    'index'   => $processed,
                    'total'   => $total,
                    'ip'      => $ip,
                    'status'  => 'success',
                    'message' => $locStr,
                    'country' => $geoData['country'],
                    'source'  => $result['meta']['data_source'] ?? '本地'
                ]);
                $updated++;
            } else {
                sseEvent('log', [
                    'index'   => $processed,
                    'total'   => $total,
                    'ip'      => $ip,
                    'status'  => 'error',
                    'message' => $result['error'] ?? '查询失败',
                    'country' => '',
                    'source'  => ''
                ]);
            }
        } catch (Exception $e) {
            sseEvent('log', [
                'index'   => $processed,
                'total'   => $total,
                'ip'      => $ip,
                'status'  => 'error',
                'message' => $e->getMessage(),
                'country' => '',
                'source'  => ''
            ]);
        }
    }
}

sseEvent('done', [
    'message' => '全部更新完成',
    'updated' => $updated,
    'total'   => $total
]);
