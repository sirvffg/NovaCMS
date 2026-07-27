<?php
/**
 * Posts Download 路由
 * 命名空间: v1
 *
 * POST /v1/posts/{id}/download/send-code - 发送邮箱验证码
 * GET  /v1/posts/{id}/download            - 下载文章（跳转至 download_article_zip.php）
 */

register_rest_route('v1', '/posts/{id}/download/send-code', [
    'methods'  => 'POST',
    'callback' => 'nova_download_send_code',
]);

register_rest_route('v1', '/posts/{id}/download', [
    'methods'  => 'GET',
    'callback' => 'nova_download_post',
]);

/**
 * 发送下载文章所需的邮箱验证码
 * 逻辑与 vendor/send_verification_code.php 一致
 */
function nova_download_send_code($request) {
    // ── 权限检查：仅管理员 ──
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可下载文章',
            'data'    => ['status' => 403],
        ];
    }

    $email = $_SESSION['user_email'] ?? '';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'code'    => 'rest_error',
            'message' => '当前登录用户未绑定有效邮箱',
            'data'    => ['status' => 400],
        ];
    }

    try {
        $db = getDB();

        // 生成验证码
        $code       = generateVerificationCode();
        $expires_at = date('Y-m-d H:i:s', time() + 600); // 10 分钟有效期

        // 先删除该邮箱之前的下载验证码（避免重复）
        $stmt = $db->prepare(
            "DELETE FROM email_verification WHERE email = ? AND purpose = 'download_article' AND is_used = 0"
        );
        $stmt->execute([$email]);

        // 存储新验证码
        $stmt = $db->prepare(
            "INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'download_article', ?)"
        );
        $stmt->execute([$email, $code, $expires_at]);

        // 发送验证邮件
        if (sendVerificationEmail($email, $code)) {
            $message = '验证码已发送到您的邮箱';
            if (defined('EMAIL_MODE') && EMAIL_MODE === 'test') {
                $message .= " (测试模式验证码: {$code})";
            }
            return [
                'code'    => 'rest_ok',
                'message' => $message,
                'data'    => ['status' => 200],
            ];
        } else {
            return [
                'code'    => 'rest_error',
                'message' => '邮件发送失败，请检查系统日志',
                'data'    => ['status' => 500],
            ];
        }
    } catch (Exception $e) {
        error_log("发送验证码异常: " . $e->getMessage());
        return [
            'code'    => 'rest_error',
            'message' => '系统错误，请稍后重试',
            'data'    => ['status' => 500],
        ];
    }
}

/**
 * 下载文章
 * 参数验证后跳转至 vendor/download_article_zip.php 处理实际 ZIP 生成
 */
function nova_download_post($request) {
    $postId   = (int)($request->get_param('id') ?? 0);
    $code     = $request->get_param('code') ?? '';
    $password = $request->get_param('password') ?? '';

    if (!$postId) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '未指定文章 ID',
            'data'    => ['status' => 400],
        ];
    }

    // 重定向到 vendor/download_article_zip.php 处理实际下载
    $protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host        = $_SERVER['HTTP_HOST'] ?? '';
    $redirectUrl = $protocol . '://' . $host . '/vendor/download_article_zip.php'
        . '?id=' . $postId
        . '&password=' . urlencode($password)
        . '&code=' . urlencode($code);

    header('Location: ' . $redirectUrl);
    exit;
}
