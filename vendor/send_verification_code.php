<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/email_config.php';

header('Content-Type: application/json');

// 检查是否为 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '无效的请求方法']);
    exit;
}

// 检查权限（仅限管理员）
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => '无权操作']);
    exit;
}

// 使用登录用户的邮箱
$email = $_SESSION['user_email'] ?? '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => '当前登录用户未绑定有效邮箱']);
    exit;
}

try {
    $db = getDB();
    
    // 生成验证码
    $code = generateVerificationCode();
    $expires_at = date('Y-m-d H:i:s', time() + 600); // 10分钟有效期
    
    // 先删除该邮箱之前的下载验证码（避免重复）
    $stmt = $db->prepare("DELETE FROM email_verification WHERE email = ? AND purpose = 'download_article' AND is_used = 0");
    $stmt->execute([$email]);
    
    // 存储新验证码
    $stmt = $db->prepare("INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'download_article', ?)");
    $stmt->execute([$email, $code, $expires_at]);
    
    // 发送验证邮件
    if (sendVerificationEmail($email, $code)) {
        $msg = '验证码已发送';
        // 如果是测试模式，提示验证码
        if (defined('EMAIL_MODE') && EMAIL_MODE === 'test') {
            $msg .= " (测试模式验证码: {$code})";
        }
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        echo json_encode(['success' => false, 'message' => '邮件发送失败，请检查系统日志']);
    }

} catch (Exception $e) {
    error_log("发送验证码异常: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '系统错误，请稍后重试']);
}
