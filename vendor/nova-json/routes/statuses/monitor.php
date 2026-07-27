<?php
/**
 * Statuses Monitor 路由
 * 命名空间: v1
 *
 * GET    /v1/statuses/monitor           - 获取所有监控状态
 * POST   /v1/statuses/monitor          - 添加监控项（管理员）
 * GET    /v1/statuses/monitor/{id}     - 获取单个监控状态
 * DELETE /v1/statuses/monitor/{id}     - 删除监控项（管理员）
 */

register_rest_route('v1', '/statuses/monitor', [
    'methods'  => 'GET',
    'callback' => 'nova_get_monitors',
]);

register_rest_route('v1', '/statuses/monitor', [
    'methods'  => 'POST',
    'callback' => 'nova_create_monitor',
]);

register_rest_route('v1', '/statuses/monitor/{id}', [
    'methods'  => 'GET',
    'callback' => 'nova_get_monitor_detail',
]);

register_rest_route('v1', '/statuses/monitor/{id}', [
    'methods'  => 'DELETE',
    'callback' => 'nova_delete_monitor',
]);

/**
 * 获取所有监控服务状态
 */
function nova_get_monitors($request) {
    $db = getDB();
    $isAdmin = v1_is_admin(v1_get_current_user_id());

    try {
        $monitors = $db->query(
            "SELECT * FROM server_monitors ORDER BY sort_order ASC, id ASC"
        )->fetchAll();
    } catch (PDOException $e) {
        $monitors = [];
    }

    if (empty($monitors)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $monitors = [
            [
                'id'         => 0,
                'name'       => '本站',
                'url'        => "$protocol://$host",
                'location'   => 'CN',
                'type'       => 'Web',
                'sort_order' => 0,
            ]
        ];
    }

    $serverStart = microtime(true);
    $results = [];
    $onlineCount = 0;
    $offlineCount = 0;

    foreach ($monitors as $m) {
        $result = nova_check_monitor($m['url']);
        $result['id']         = (int)$m['id'];
        $result['name']       = $m['name'];
        $result['location']   = $m['location'] ?? 'CN';
        $result['type']       = $m['type'] ?? 'Web';

        // 仅管理员可见 URL
        if ($isAdmin) {
            $result['url'] = $m['url'];
        }

        if ($result['online']) {
            $onlineCount++;
        } else {
            $offlineCount++;
        }

        $results[] = $result;
    }

    $responseTime = round((microtime(true) - $serverStart) * 1000);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status'        => 200,
            'total'         => count($results),
            'online_count'  => $onlineCount,
            'offline_count' => $offlineCount,
            'response_time' => $responseTime,
            'items'         => $results,
        ],
    ];
}

/**
 * 获取单个监控服务详情
 */
function nova_get_monitor_detail($request) {
    $db = getDB();
    $id = (int)($request->get_param('id') ?? 0);
    $isAdmin = v1_is_admin(v1_get_current_user_id());

    $stmt = $db->prepare("SELECT * FROM server_monitors WHERE id = ?");
    $stmt->execute([$id]);
    $monitor = $stmt->fetch();

    if (!$monitor) {
        return [
            'code'    => 'rest_error',
            'message' => '监控项不存在',
            'data'    => ['status' => 404],
        ];
    }

    $result = nova_check_monitor($monitor['url']);
    $result['id']         = (int)$monitor['id'];
    $result['name']       = $monitor['name'];
    $result['location']   = $monitor['location'] ?? 'CN';
    $result['type']       = $monitor['type'] ?? 'Web';
    $result['sort_order'] = (int)$monitor['sort_order'];
    $result['created_at'] = $monitor['created_at'];

    // 仅管理员可见 URL
    if ($isAdmin) {
        $result['url'] = $monitor['url'];
    }

    $result['http_code'] = nova_get_http_code($monitor['url']);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status' => 200,
            'item'   => $result,
        ],
    ];
}

/**
 * 对单个 URL 执行连通性检查
 */
function nova_check_monitor($url) {
    $start   = microtime(true);
    $online  = false;
    $latency = 0;
    $error   = '';

    $parsed = parse_url($url);
    if ($parsed === false || !isset($parsed['host'])) {
        return ['online' => false, 'latency' => 0, 'error' => '无效的 URL'];
    }

    $host = $parsed['host'];
    $port = $parsed['port'] ?? (($parsed['scheme'] ?? 'http') === 'https' ? 443 : 80);

    try {
        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($fp) {
            $online  = true;
            $latency = round((microtime(true) - $start) * 1000);
            fclose($fp);
        } else {
            $error = $errstr ?: "连接超时或拒绝";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    return [
        'online'  => $online,
        'latency' => $latency,
        'error'   => $error,
    ];
}

/**
 * 获取 HTTP 状态码
 */
function nova_get_http_code($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY        => true,
        CURLOPT_TIMEOUT       => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode ?: 0;
}

/**
 * 添加监控项（管理员）
 */
function nova_create_monitor($request) {
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可添加监控项',
            'data'    => ['status' => 403],
        ];
    }

    $db   = getDB();
    $name = trim(strip_tags($request->get_param('name') ?? ''));
    $url  = trim($request->get_param('url') ?? '');

    if (empty($name)) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '监控名称不能为空',
            'data'    => ['status' => 400],
        ];
    }

    if (empty($url)) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '监控 URL 不能为空',
            'data'    => ['status' => 400],
        ];
    }

    $parsed = parse_url($url);
    if ($parsed === false || !isset($parsed['host'])) {
        return [
            'code'    => 'rest_error',
            'message' => '无效的 URL 格式',
            'data'    => ['status' => 400],
        ];
    }

    $location  = trim($request->get_param('location') ?? 'CN');
    $type      = trim($request->get_param('type') ?? 'Web');
    $sortOrder = (int)($request->get_param('sort_order') ?? 0);

    $stmt = $db->prepare(
        "INSERT INTO server_monitors (name, url, location, type, sort_order) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$name, $url, $location, $type, $sortOrder]);
    $newId = (int)$db->lastInsertId();

    return [
        'code'    => 'rest_ok',
        'message' => '监控项已添加',
        'data'    => [
            'status'     => 201,
            'id'         => $newId,
            'name'       => $name,
            'url'        => $url,
            'location'   => $location,
            'type'       => $type,
            'sort_order' => $sortOrder,
        ],
    ];
}

/**
 * 删除监控项（管理员）
 */
function nova_delete_monitor($request) {
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可删除监控项',
            'data'    => ['status' => 403],
        ];
    }

    $db = getDB();
    $id = (int)($request->get_param('id') ?? 0);

    if (!$id) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '未指定监控项 ID',
            'data'    => ['status' => 400],
        ];
    }

    $stmt = $db->prepare("SELECT id FROM server_monitors WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        return [
            'code'    => 'rest_error',
            'message' => '监控项不存在',
            'data'    => ['status' => 404],
        ];
    }

    $db->prepare("DELETE FROM server_monitors WHERE id = ?")->execute([$id]);

    $remaining = (int)$db->query("SELECT COUNT(*) FROM server_monitors")->fetchColumn();

    return [
        'code'    => 'rest_ok',
        'message' => '监控项已删除，ID 已重排',
        'data'    => [
            'status'     => 200,
            'deleted_id' => $id,
            'remaining'  => $remaining,
        ],
    ];
}
