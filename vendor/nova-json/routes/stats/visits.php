<?php
/**
 * 访问统计路由
 * 命名空间: v1
 *
 * /v1/stats/visits/summary   - 按天聚合访问量（折线图用）
 * /v1/stats/visits/top-pages  - 热门页面 Top N（柱状图用）
 * /v1/stats/visits/overview   - 总览数据（卡片用）
 *
 * 通用参数:
 *   ?days=7       统计天数（1-90）
 */

register_rest_route('v1', '/stats/visits/summary', [
    'methods'  => 'GET',
    'callback' => 'v1_stats_visits_summary',
]);

register_rest_route('v1', '/stats/visits/top-pages', [
    'methods'  => 'GET',
    'callback' => 'v1_stats_visits_top_pages',
]);

register_rest_route('v1', '/stats/visits/overview', [
    'methods'  => 'GET',
    'callback' => 'v1_stats_visits_overview',
]);

/**
 * 按天聚合访问量（折线图数据）
 */
function v1_stats_visits_summary($request) {
    $days = max(1, min(90, (int)($request->get_param('days') ?? 7)));
    $db   = getDB();

    $sql = "SELECT DATE(visit_time) AS date, COUNT(*) AS count
            FROM visit_stats
            WHERE visit_time >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
            GROUP BY DATE(visit_time)
            ORDER BY date ASC";

    $rows = $db->query($sql)->fetchAll();

    // 补全缺失的日期（保证折线图连续）
    $result = [];
    $map = [];
    foreach ($rows as $row) {
        $map[$row['date']] = (int)$row['count'];
    }

    $start = new DateTime("-" . ($days - 1) . " days");
    $end   = new DateTime();
    $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
    foreach ($period as $dt) {
        $date = $dt->format('Y-m-d');
        $result[] = [
            'date'  => $date,
            'count' => $map[$date] ?? 0,
        ];
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status' => 200,
            'days'   => $days,
            'items'  => $result,
        ],
    ];
}

/**
 * 热门页面 Top N（柱状图数据）
 */
function v1_stats_visits_top_pages($request) {
    $days  = max(1, min(90, (int)($request->get_param('days') ?? 7)));
    $limit = max(1, min(50, (int)($request->get_param('limit') ?? 10)));
    $db    = getDB();

    $sql = "SELECT page_url, COUNT(*) AS count
            FROM visit_stats
            WHERE visit_time >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
              AND page_url IS NOT NULL
              AND page_url <> ''
            GROUP BY page_url
            ORDER BY count DESC
            LIMIT {$limit}";

    $rows = $db->query($sql)->fetchAll();

    $items = array_map(function($row) {
        return [
            'page_url' => $row['page_url'],
            'count'    => (int)$row['count'],
        ];
    }, $rows);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status' => 200,
            'days'   => $days,
            'total'  => count($items),
            'items'   => $items,
        ],
    ];
}

/**
 * 访问总览（卡片数据）
 */
function v1_stats_visits_overview($request) {
    $days = max(1, min(90, (int)($request->get_param('days') ?? 7)));
    $db   = getDB();

    // 指定时间范围内的总访问量、独立 IP 数
    $summarySql = "SELECT
                        COUNT(*)              AS total_visits,
                        COUNT(DISTINCT ip_address) AS unique_ips
                   FROM visit_stats
                   WHERE visit_time >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)";
    $summary = $db->query($summarySql)->fetch();

    // 今日访问量
    $todaySql = "SELECT COUNT(*) AS count FROM visit_stats WHERE DATE(visit_time) = CURDATE()";
    $todayCount = (int)$db->query($todaySql)->fetchColumn();

    // 昨日访问量（用于计算环比）
    $yesterdaySql = "SELECT COUNT(*) AS count FROM visit_stats WHERE DATE(visit_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    $yesterdayCount = (int)$db->query($yesterdaySql)->fetchColumn();

    // 计算环比变化率
    $growthRate = 0;
    if ($yesterdayCount > 0) {
        $growthRate = round(($todayCount - $yesterdayCount) / $yesterdayCount * 100, 1);
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status'        => 200,
            'days'          => $days,
            'total_visits'  => (int)$summary['total_visits'],
            'unique_ips'    => (int)$summary['unique_ips'],
            'today_visits'  => $todayCount,
            'yesterday_visits' => $yesterdayCount,
            'growth_rate'   => $growthRate,
        ],
    ];
}
