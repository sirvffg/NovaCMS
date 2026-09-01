<?php
/**
 * Comments 路由
 * 命名空间: v1
 *
 * /v1/comments              - 评论列表（GET，不带分页参数则返回全部）
 * /v1/comments              - 添加评论/回复（POST）
 * /v1/comments/{id}         - 评论详情（GET）
 * /v1/comments/{id}         - 删除评论（DELETE）
 *
 * 分页参数: ?page=1&per_page=10
 * 筛选:     ?post_id=1
 */

require_once dirname(__DIR__, 4) . '/config/comment_functions.php';

register_rest_route('v1', '/comments', [
    'methods'  => 'GET',
    'callback' => 'nova_get_comment_list',
]);

register_rest_route('v1', '/comments', [
    'methods'  => 'POST, OPTIONS',
    'callback' => 'nova_add_comment',
]);

register_rest_route('v1', '/comments/{id}', [
    'methods'  => 'GET',
    'callback' => 'nova_get_single_comment',
]);

register_rest_route('v1', '/comments/{id}', [
    'methods'  => 'DELETE, OPTIONS',
    'callback' => 'nova_delete_comment',
]);

function nova_get_comment_list($request) {
    $db = getDB();
    ensureCommentSchema();
    $currentUserId = v1_get_current_user_id();
    $isAdmin = v1_is_admin($currentUserId);

    // 分页：不传 per_page 则返回全部
    $raw_per_page = $request->get_param('per_page');
    $has_pagination = $raw_per_page !== null;
    if ($has_pagination) {
        $per_page = min(100, max(1, (int)$raw_per_page));
        $page     = max(1, (int)$request->get_param('page') ?: 1);
        $offset   = ($page - 1) * $per_page;
    } else {
        $per_page = 0;
        $page     = 1;
        $offset   = 0;
    }

    $post_id = max(0, (int)($request->get_param('post_id') ?? 0));

    $where  = "WHERE c.status = 'approved'";
    $params = [];

    if (!$isAdmin) {
        $where .= " AND p.is_published = 1";
    }

    if ($post_id > 0) {
        $postStmt = $db->prepare("SELECT is_published FROM blog_posts WHERE id = ? LIMIT 1");
        $postStmt->execute([$post_id]);
        $post = $postStmt->fetch();
        if (!$post || (!$isAdmin && empty($post['is_published']))) {
            return new Nova_REST_Response([
                'code' => 'post_not_found',
                'message' => '文章不存在',
                'data' => ['status' => 404],
            ], 404);
        }
        $where .= " AND c.post_id = ?";
        $params[] = $post_id;
    }

    $count_stmt = $db->prepare("SELECT COUNT(*) FROM blog_comments c INNER JOIN blog_posts p ON c.post_id = p.id {$where}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    $sql = "SELECT c.*, p.title as post_title
            FROM blog_comments c
            INNER JOIN blog_posts p ON c.post_id = p.id
            {$where}
            ORDER BY c.created_at DESC";

    if ($has_pagination) {
        $sql .= " LIMIT {$per_page} OFFSET {$offset}";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $comments = $stmt->fetchAll();

    $items = array_map(function($c) use ($isAdmin, $currentUserId) {
        $isPrivate = !empty($c['is_private']);
        // 私密评论可见性：管理员 或 评论作者本人（已登录）
        $canSeePrivate = $isAdmin || ($currentUserId > 0 && (int)$c['user_id'] === $currentUserId);
        if ($isPrivate && !$canSeePrivate) {
            // 非授权用户：仅显示占位，隐藏作者/邮箱/网址/设备信息
            return [
                'id'         => (int)$c['id'],
                'post_id'    => (int)$c['post_id'],
                'post_title' => $c['post_title'],
                'username'   => '私密评论',
                'content'    => '此评论为私密内容，仅作者和管理员可见',
                'parent_id'  => $c['parent_id'] ? (int)$c['parent_id'] : null,
                'created_at' => $c['created_at'],
                'is_private' => true,
                'masked'     => true,
                'avatar_url' => '',
                'device_info'=> '',
                'website'    => '',
            ];
        }
        $item = [
            'id'         => (int)$c['id'],
            'post_id'    => (int)$c['post_id'],
            'post_title' => $c['post_title'],
            'username'   => $c['username'],
            'content'    => $c['content'],
            'parent_id'  => $c['parent_id'] ? (int)$c['parent_id'] : null,
            'created_at' => $c['created_at'],
            'is_private' => $isPrivate,
            'device_info'=> $c['device_info'] ?? '',
            'website'    => $c['website'] ?? '',
            'avatar_url' => function_exists('getCommentAvatarUrl') ? getCommentAvatarUrl($c['email'] ?? '', 100) : '',
        ];
        // 仅管理员可查看评论者邮箱
        if ($isAdmin) {
            $item['email'] = $c['email'];
        }
        return $item;
    }, $comments);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status'      => 200,
            'total'       => $total,
            'page'        => $has_pagination ? $page : 1,
            'per_page'    => $has_pagination ? $per_page : $total,
            'total_pages' => $has_pagination ? (int)ceil($total / $per_page) : 1,
            'items'       => $items,
        ],
    ];
}

function nova_get_single_comment($request) {
    $db = getDB();
    ensureCommentSchema();
    $id = (int)$request->get_param('id');
    $currentUserId = v1_get_current_user_id();
    $isAdmin = v1_is_admin($currentUserId);

    $visibility = $isAdmin ? '' : " AND c.status = 'approved' AND p.is_published = 1";
    $stmt = $db->prepare("SELECT c.*, p.title as post_title FROM blog_comments c INNER JOIN blog_posts p ON c.post_id = p.id WHERE c.id = ?{$visibility} LIMIT 1");
    $stmt->execute([$id]);
    $comment = $stmt->fetch();

    if (!$comment) {
        return new Nova_REST_Response([
            'code'    => 'comment_not_found',
            'message' => '评论不存在',
            'data'    => ['status' => 404],
        ], 404);
    }

    $isPrivate = !empty($comment['is_private']);
    $canSeePrivate = $isAdmin || ($currentUserId > 0 && (int)$comment['user_id'] === $currentUserId);
    if ($isPrivate && !$canSeePrivate) {
        // 非授权用户：仅显示占位，隐藏作者/邮箱/网址/设备信息
        $item = [
            'id'         => (int)$comment['id'],
            'post_id'    => (int)$comment['post_id'],
            'post_title' => $comment['post_title'],
            'username'   => '私密评论',
            'content'    => '此评论为私密内容，仅作者和管理员可见',
            'parent_id'  => $comment['parent_id'] ? (int)$comment['parent_id'] : null,
            'status'     => $comment['status'],
            'created_at' => $comment['created_at'],
            'is_private' => true,
            'masked'     => true,
            'avatar_url' => '',
            'device_info'=> '',
            'website'    => '',
        ];
    } else {
        $item = [
            'id'         => (int)$comment['id'],
            'post_id'    => (int)$comment['post_id'],
            'post_title' => $comment['post_title'],
            'username'   => $comment['username'],
            'content'    => $comment['content'],
            'parent_id'  => $comment['parent_id'] ? (int)$comment['parent_id'] : null,
            'status'     => $comment['status'],
            'created_at' => $comment['created_at'],
            'is_private' => $isPrivate,
            'device_info'=> $comment['device_info'] ?? '',
            'website'    => $comment['website'] ?? '',
            'avatar_url' => function_exists('getCommentAvatarUrl') ? getCommentAvatarUrl($comment['email'] ?? '', 100) : '',
        ];
        // 仅管理员可查看评论者邮箱
        if ($isAdmin) {
            $item['email'] = $comment['email'];
        }
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => ['status' => 200, 'item' => $item],
    ];
}

/**
 * 添加评论 / 回复评论
 *
 * POST /v1/comments
 * Body: { "post_id": 1, "content": "评论内容", "parent_id": null }
 */
function nova_add_comment($request) {
    // OPTIONS 预检请求
    if ($request->get_method() === 'OPTIONS') {
        return new Nova_REST_Response(['code' => 'rest_ok', 'message' => 'OK', 'data' => ['status' => 204]], 204);
    }

    // 评论插件已禁用 → 拒绝新增评论
    if (!isCommentsEnabled()) {
        return new Nova_REST_Response([
            'code'    => 'rest_forbidden',
            'message' => '评论功能已关闭',
            'data'    => ['status' => 403],
        ], 403);
    }

    ensureCommentSchema();
    $loginRequired = (bool)getSiteConfigValue('comment_login_required', 0);
    $userId = v1_get_current_user_id();

    // 需要登录但未登录 → 401；允许匿名评论时未登录也可继续
    if ($userId <= 0 && $loginRequired) {
        return new Nova_REST_Response([
            'code'    => 'rest_not_logged_in',
            'message' => '请先登录',
            'data'    => ['status' => 401],
        ], 401);
    }

    $post_id   = (int)$request->get_param('post_id');
    $content   = trim(strip_tags($request->get_param('content') ?: ''));
    $parent_id = $request->get_param('parent_id') !== null ? (int)$request->get_param('parent_id') : null;

    if ($post_id <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_param',
            'message' => '缺少文章 ID',
            'data'    => ['status' => 400],
        ], 400);
    }

    if ($content === '') {
        return new Nova_REST_Response([
            'code'    => 'rest_empty_content',
            'message' => '评论内容不能为空',
            'data'    => ['status' => 400],
        ], 400);
    }

    // 匿名评论者信息（仅未登录时使用）
    $anon_name    = null;
    $anon_email   = null;
    $anon_website = null;
    if ($userId <= 0) {
        $anon_name    = trim((string)$request->get_param('username'));
        $anon_email   = trim((string)$request->get_param('email'));
        $anon_website = trim((string)$request->get_param('website'));
    }
    $is_private = (bool)$request->get_param('is_private');

    $result = addComment($post_id, $content, $parent_id, $anon_name, $anon_email, $anon_website, $is_private);

    if ($result['success']) {
        return [
            'code'    => 'rest_ok',
            'message' => $result['message'] ?? '评论成功',
            'data'    => [
                'status'          => 201,
                'comment_id'      => $result['comment_id'],
                'comment'         => $result['comment'],
                'pending_approval' => !empty($result['pending_approval']),
            ],
        ];
    }

    $failureStatus = max(400, min(599, (int)($result['status'] ?? 400)));
    return new Nova_REST_Response([
        'code'    => 'rest_comment_failed',
        'message' => $result['message'],
        'data'    => ['status' => $failureStatus],
    ], $failureStatus);
}

/**
 * 删除评论
 *
 * DELETE /v1/comments/{id}
 */
function nova_delete_comment($request) {
    // OPTIONS 预检请求
    if ($request->get_method() === 'OPTIONS') {
        return new Nova_REST_Response(['code' => 'rest_ok', 'message' => 'OK', 'data' => ['status' => 204]], 204);
    }

    // 评论插件已禁用 → 拒绝删除评论
    if (!isCommentsEnabled()) {
        return new Nova_REST_Response([
            'code'    => 'rest_forbidden',
            'message' => '评论功能已关闭',
            'data'    => ['status' => 403],
        ], 403);
    }

    $userId = v1_get_current_user_id();
    if ($userId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_not_logged_in',
            'message' => '请先登录',
            'data'    => ['status' => 401],
        ], 401);
    }

    $comment_id = (int)$request->get_param('id');
    if ($comment_id <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_param',
            'message' => '缺少评论 ID',
            'data'    => ['status' => 400],
        ], 400);
    }

    $result = deleteComment($comment_id);

    if ($result['success']) {
        return [
            'code'    => 'rest_ok',
            'message' => '评论已删除',
            'data'    => ['status' => 200],
        ];
    }

    return new Nova_REST_Response([
        'code'    => 'rest_delete_failed',
        'message' => $result['message'],
        'data'    => ['status' => 400],
    ], 400);
}
