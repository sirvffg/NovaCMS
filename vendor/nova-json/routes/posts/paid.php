<?php
/**
 * 付费内容访问路由
 * 命名空间: v1
 *
 * POST /v1/posts/paid   - 检查/获取付费内容访问状态
 *
 * 逻辑与 blog.php 中的 paid_functions 一致
 */

register_rest_route('v1', '/posts/paid', [
    'methods'  => 'POST, OPTIONS',
    'callback' => 'v1_check_paid_access',
]);

function v1_check_paid_access($request) {
    // OPTIONS 预检请求直接返回成功
    if ($request->get_method() === 'OPTIONS') {
        return new Nova_REST_Response(['code' => 'rest_ok', 'message' => 'OK', 'data' => ['status' => 204]], 204);
    }

    $userId = v1_get_current_user_id();
    if ($userId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_not_logged_in',
            'message' => '请先登录',
            'data'    => ['status' => 401],
        ], 401);
    }

    $postId = (int)$request->get_param('post_id');
    if ($postId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_post_id',
            'message' => '缺少文章 ID',
            'data'    => ['status' => 400],
        ], 400);
    }

    $db = getDB();

    // 检查文章是否存在且有付费内容
    $stmt = $db->prepare("SELECT id, title, has_paid_content, post_price FROM blog_posts WHERE id = ? AND is_published = 1 LIMIT 1");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();

    if (!$post) {
        return new Nova_REST_Response([
            'code'    => 'rest_post_not_found',
            'message' => '文章不存在',
            'data'    => ['status' => 404],
        ], 404);
    }

    if (empty($post['has_paid_content'])) {
        return new Nova_REST_Response([
            'code'    => 'rest_no_paid_content',
            'message' => '该文章没有付费内容',
            'data'    => ['status' => 400],
        ], 400);
    }

    // 检查是否已支付（管理员自动拥有权限）
    $hasAccess = hasPaidAccess($db, $userId, $postId);

    return [
        'code'    => 'rest_ok',
        'message' => $hasAccess ? '您已获得该文章的付费内容访问权限' : '该文章包含付费内容，需要支付后才能查看',
        'data'    => [
            'status'         => 200,
            'has_access'     => $hasAccess,
            'post_id'        => $postId,
            'post_title'     => $post['title'],
            'price'          => $hasAccess ? null : (float)$post['post_price'],
            'pay_url'        => $hasAccess ? null : '/vendor/public/epay/pay.php?post_id=' . $postId,
        ],
    ];
}
