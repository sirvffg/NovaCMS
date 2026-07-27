<?php
/**
 * 隐私内容访问路由
 * 命名空间: v1
 *
 * POST /v1/posts/privacy  - 提交隐私问题答案，申请访问权限
 *
 * 逻辑与 blog.php 中的 submitPrivacyAnswer 一致
 */

register_rest_route('v1', '/posts/privacy', [
    'methods'  => 'POST, OPTIONS',
    'callback' => 'v1_submit_privacy_answer',
]);

function v1_submit_privacy_answer($request) {
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
    $answer = trim($request->get_param('answer') ?: '');

    if ($postId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_post_id',
            'message' => '缺少文章 ID',
            'data'    => ['status' => 400],
        ], 400);
    }

    $db = getDB();

    // 检查文章是否存在且有隐私内容
    $stmt = $db->prepare("SELECT id, has_privacy_content, privacy_type FROM blog_posts WHERE id = ? AND is_published = 1 LIMIT 1");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();

    if (!$post) {
        return new Nova_REST_Response([
            'code'    => 'rest_post_not_found',
            'message' => '文章不存在',
            'data'    => ['status' => 404],
        ], 404);
    }

    if (empty($post['has_privacy_content'])) {
        return new Nova_REST_Response([
            'code'    => 'rest_no_privacy_content',
            'message' => '该文章没有隐私内容',
            'data'    => ['status' => 400],
        ], 400);
    }

    // 管理员直接拥有权限
    if (isAdmin($db, $userId)) {
        return [
            'code'    => 'rest_ok',
            'message' => '管理员无需申请，可直接查看',
            'data'    => ['status' => 200, 'access_granted' => true],
        ];
    }

    // 检查是否已有权限
    if (hasPrivacyAccess($db, $userId, $postId)) {
        return [
            'code'    => 'rest_ok',
            'message' => '您已有权限查看该文章的隐私内容',
            'data'    => ['status' => 200, 'access_granted' => true],
        ];
    }

    // login_only 类型：登录即可
    if ($post['privacy_type'] === 'login_only') {
        return [
            'code'    => 'rest_ok',
            'message' => '您已登录，可以查看隐私内容',
            'data'    => ['status' => 200, 'access_granted' => true],
        ];
    }

    // 需要答案 — 返回隐私问题供前端展示
    $question = '';
    $customText = '';
    $privacySettings = getPrivacySettings($db, $postId);
    if ($privacySettings) {
        $question = $privacySettings['question'] ?? '';
        $customText = $privacySettings['custom_text'] ?? '';
    }

    if (empty($answer)) {
        return new Nova_REST_Response([
            'code'    => 'rest_need_answer',
            'message' => '请回答隐私问题',
            'data'    => [
                'status'       => 400,
                'question'     => $question,
                'privacy_type' => $post['privacy_type'],
                'custom_text'  => $customText,
            ],
        ], 400);
    }

    // 调用已有的验证逻辑
    $result = validatePrivacyAnswer($db, $userId, $postId, $answer);

    return [
        'code'    => $result['success'] ? 'rest_ok' : 'rest_privacy_denied',
        'message' => $result['message'] ?? ($result['success'] ? '验证通过' : '答案错误'),
        'data'    => [
            'status'         => $result['success'] ? 200 : 403,
            'access_granted' => $result['success'] ?? false,
            'pending_approval' => !empty($result['pending_approval']),
        ],
    ];
}
