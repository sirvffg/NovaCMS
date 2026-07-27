<?php
/**
 * Statuses Logs 路由
 * 命名空间: v1
 *
 * GET    /v1/statuses/logs              - 获取建站日志列表
 * POST   /v1/statuses/logs              - 添加日志（管理员）
 * DELETE /v1/statuses/logs/{id}         - 删除日志并重排 ID（管理员）
 *
 * 参数（GET）:
 *   page     - 页码（默认 1）
 *   per_page - 每页条数（不传则返回全部）
 *
 * 参数（POST）:
 *   version - 版本号（必填）
 *   date    - 日期，格式 Y-m-d（必填）
 *   title   - 标题（必填）
 *   content - 内容（必填）
 *   type    - 类型：release / update / fix（默认 update）
 */

register_rest_route('v1', '/statuses/logs', [
    'methods'  => 'GET',
    'callback' => 'nova_get_logs',
]);

register_rest_route('v1', '/statuses/logs', [
    'methods'  => 'POST',
    'callback' => 'nova_create_log',
]);

register_rest_route('v1', '/statuses/logs/{id}', [
    'methods'  => 'DELETE',
    'callback' => 'nova_delete_log',
]);

/**
 * 获取建站日志列表
 */
function nova_get_logs($request) {
    $db    = getDB();
    $total = (int)$db->query("SELECT COUNT(*) FROM website_logs")->fetchColumn();

    // 条件分页：不传 per_page 则返回全部
    $raw_per_page = $request->get_param('per_page');
    $has_pagination = $raw_per_page !== null;

    if ($has_pagination) {
        $page     = max(1, (int)($request->get_param('page') ?? 1));
        $per_page = min(50, max(1, (int)$raw_per_page));
        $offset   = ($page - 1) * $per_page;

        $stmt = $db->prepare("SELECT * FROM website_logs ORDER BY date DESC, id DESC LIMIT ?, ?");
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $per_page, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();
    } else {
        $page     = 1;
        $per_page = $total;
        $logs = $db->query("SELECT * FROM website_logs ORDER BY date DESC, id DESC")->fetchAll();
    }

    $items = array_map(function($item) {
        return [
            'id'         => (int)$item['id'],
            'version'    => $item['version'],
            'date'       => $item['date'],
            'title'      => $item['title'],
            'content'    => $item['content'],
            'type'       => $item['type'],
            'created_at' => $item['created_at'],
        ];
    }, $logs);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status'   => 200,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'items'    => $items,
        ],
    ];
}

/**
 * 添加日志（管理员）
 */
function nova_create_log($request) {
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可添加日志',
            'data'    => ['status' => 403],
        ];
    }

    $db      = getDB();
    $version = trim(strip_tags($request->get_param('version') ?? ''));
    $date    = trim(strip_tags($request->get_param('date') ?? ''));
    $title   = trim(strip_tags($request->get_param('title') ?? ''));
    $content = trim(strip_tags($request->get_param('content') ?? ''));
    $type    = trim(strip_tags($request->get_param('type') ?? 'update'));

    if (empty($version)) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '版本号不能为空',
            'data'    => ['status' => 400],
        ];
    }

    if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '日期不能为空，格式为 YYYY-MM-DD',
            'data'    => ['status' => 400],
        ];
    }

    if (empty($title)) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '标题不能为空',
            'data'    => ['status' => 400],
        ];
    }

    if (empty($content)) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '内容不能为空',
            'data'    => ['status' => 400],
        ];
    }

    // 校验类型
    $allowedTypes = ['release', 'update', 'fix'];
    if (!in_array($type, $allowedTypes)) {
        $type = 'update';
    }

    $stmt = $db->prepare(
        "INSERT INTO website_logs (version, date, title, content, type) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$version, $date, $title, $content, $type]);
    $newId = (int)$db->lastInsertId();

    return [
        'code'    => 'rest_ok',
        'message' => '日志已添加',
        'data'    => [
            'status'     => 201,
            'id'         => $newId,
            'version'    => $version,
            'date'       => $date,
            'title'      => $title,
            'content'    => $content,
            'type'       => $type,
        ],
    ];
}

/**
 * 删除日志并重排 ID（管理员）
 */
function nova_delete_log($request) {
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可删除日志',
            'data'    => ['status' => 403],
        ];
    }

    $db = getDB();
    $id = (int)($request->get_param('id') ?? 0);

    if (!$id) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '未指定日志 ID',
            'data'    => ['status' => 400],
        ];
    }

    // 检查是否存在
    $stmt = $db->prepare("SELECT id FROM website_logs WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        return [
            'code'    => 'rest_error',
            'message' => '日志不存在',
            'data'    => ['status' => 404],
        ];
    }

    $db->prepare("DELETE FROM website_logs WHERE id = ?")->execute([$id]);

    $remaining = (int)$db->query("SELECT COUNT(*) FROM website_logs")->fetchColumn();

    return [
        'code'    => 'rest_ok',
        'message' => '日志已删除，ID 已重排',
        'data'    => [
            'status'     => 200,
            'deleted_id' => $id,
            'remaining'  => $remaining,
        ],
    ];
}
