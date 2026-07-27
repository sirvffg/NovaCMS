<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';

// 封禁 IP 禁止访问
if (isBotBlacklisted()) { http_response_code(403); header('Location: /vendor/public/error/banned.html'); exit; }

// 记录访问
recordVisit($_SERVER['REQUEST_URI']);

// 退出登录仅接受带 CSRF 的 POST 请求，避免跨站请求触发登出。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('安全验证失败，请返回后刷新页面重试');
    }
    logoutAuthenticatedUser();
    $logoutRedirect = safeRedirectUrl($_POST['redirect_url'] ?? '/vendor/login.php');
    header('Location: ' . $logoutRedirect);
    exit;
}

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 已登录则跳转
if (isset($_SESSION['user_id'])) {
    $redirect_url = safeRedirectUrl($_GET['redirect_url'] ?? '/');
    header('Location: ' . $redirect_url);
    exit;
}

// 检测后台是否已经登录（如果后台已登录，自动登录前台）
if (isset($_SESSION['admin_id'])) {
    // 验证用户
    $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $user = $stmt->fetch();
    
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_username'] = $user['username'];
        $_SESSION['user_email'] = $user['email'] ?? '';
        $_SESSION['user_role'] = $user['role'];
        
        $redirect_url = safeRedirectUrl($_GET['redirect_url'] ?? '/');
        header('Location: ' . $redirect_url);
        exit;
    }
}

$login_error = '';
$remaining_lock_time = 0;

// 账户锁定配置
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15分钟

// 获取重定向 URL
$redirect_url = safeRedirectUrl($_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '/');

// 邮箱验证相关变量
$need_email_verify = false;
$verify_email = '';
$verify_user_id = 0;
$verify_username = '';

// 处理AJAX请求：发送登录验证码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_login_code') {
    header('Content-Type: application/json');

    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面']);
        exit;
    }

    $userId = (int)($_POST['user_id'] ?? 0);
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => '参数错误']);
        exit;
    }

    // 速率限制：每个用户每小时最多发5次验证码
    $rateLimit = checkRateLimit('login_verify_' . $userId, 5, 3600);
    if (!$rateLimit['allowed']) {
        echo json_encode(['success' => false, 'message' => $rateLimit['message']]);
        exit;
    }

    // 每个IP每小时最多发10次（防止批量注册/攻击）
    $ipRateLimit = checkRateLimit('login_verify_ip', 10, 3600);
    if (!$ipRateLimit['allowed']) {
        echo json_encode(['success' => false, 'message' => '操作过于频繁，请稍后再试']);
        exit;
    }

    // 获取用户邮箱
    $stmt = $db->prepare("SELECT id, email, username FROM admins WHERE id = ? AND is_banned = 0");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['email'])) {
        echo json_encode(['success' => false, 'message' => '该账号未绑定邮箱，无法进行验证']);
        exit;
    }

    // 检查是否已有未过期的验证码（10分钟内不重复发送）
    $stmt = $db->prepare("SELECT id FROM email_verification WHERE email = ? AND purpose = 'login_verify' AND is_used = 0 AND expires_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
    $stmt->execute([$user['email']]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => '验证码已发送，请稍等1分钟后再试']);
        exit;
    }

    // 删除该用户旧的登录验证码（避免唯一索引冲突）
    $stmt = $db->prepare("DELETE FROM email_verification WHERE email = ? AND purpose = 'login_verify' AND is_used = 0");
    $stmt->execute([$user['email']]);

    // 生成并发送验证码
    $code = generateVerificationCode();
    $expires_at = date('Y-m-d H:i:s', time() + 600); // 10分钟过期

    $stmt = $db->prepare("INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'login_verify', ?)");
    $stmt->execute([$user['email'], $code, $expires_at]);

    if (sendVerificationEmail($user['email'], $code)) {
        // 对邮箱做脱敏处理
        $maskedEmail = preg_replace('/(.{2})(.*)(@.*)/', '$1***$3', $user['email']);
        echo json_encode(['success' => true, 'message' => "验证码已发送到 {$maskedEmail}"]);
    } else {
        echo json_encode(['success' => false, 'message' => '验证码发送失败，请稍后再试']);
    }
    exit;
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        // CSRF验证
        if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
            $login_error = '安全验证失败，请刷新页面后重试';
        } elseif (checkHoneypot()) {
            // 蜜罐触发：假装登录失败，不暴露蜜罐
            sleep(2);
            $login_error = '用户名或密码错误';
        } else {
        // 人机验证
        $captcha_token = $_POST['captcha_token'] ?? '';
        require_once __DIR__ . '/public/captcha/AuthApi.php';
        $captchaAuth = new BehaviorAuth();
        if (empty($captcha_token) || !$captchaAuth->verifyBizToken($captcha_token)) {
            $login_error = '人机验证失败，请重试';
        } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $login_error = '用户名/邮箱和密码不能为空';
        } else {
            // 速率限制检查：防止暴力破解
            $rateLimit = checkRateLimit('login', 5, 300); // 5分钟内最多5次尝试
            if (!$rateLimit['allowed']) {
                $login_error = $rateLimit['message'];
            } else {
                $stmt = $db->prepare("SELECT * FROM admins WHERE username = ? OR email = ?");
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch();

                // 检查账户锁定状态（如果字段存在）
                if ($user && isset($user['login_attempts']) && isset($user['last_login_attempt'])) {
                    $lockout_end = $user['last_login_attempt'] + LOCKOUT_DURATION;
                    if ($user['login_attempts'] >= MAX_LOGIN_ATTEMPTS && time() < $lockout_end) {
                        $remaining_lock_time = ceil(($lockout_end - time()) / 60);
                        $login_error = "账户已被锁定，请在 {$remaining_lock_time} 分钟后重试";
                    }
                }

                if ($user && verifyPassword($password, $user['password'], $db, $user['id'])) {
                    // 检查是否被封禁
                    if (!empty($user['is_banned'])) {
                        $login_error = '您的账号已被封禁，无法登录';
                    } elseif (empty($user['email'])) {
                        // 没有邮箱，无法进行非常用IP验证，直接放行
                        completeLogin($user, !empty($_POST['remember']));
                    } else {
                        // 密码正确，检查是否为常用IP
                        $currentIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        $forceVerify = isset($_GET['force_verify']);

                        if (!$forceVerify && isUserFrequentIP($user['id'], $currentIP)) {
                            // 常用IP，直接登录
                            completeLogin($user, !empty($_POST['remember']));
                        } else {
                            // 非常用IP（或强制测试），进入邮箱验证流程
                            $need_email_verify = true;
                            $verify_email = $user['email'];
                            $verify_user_id = $user['id'];
                            $verify_username = $user['username'];

                            // 重置登录失败计数（密码已验证通过）
                            if (isset($user['login_attempts'])) {
                                $resetStmt = $db->prepare("UPDATE admins SET login_attempts = 0 WHERE id = ?");
                                $resetStmt->execute([$user['id']]);
                            }
                            resetRateLimit('login');
                        }
                    }
                } elseif ($user && empty($login_error)) {
                    // 登录失败，增加失败计数（如果字段存在）
                    ensureSessionTables();
                    recordLoginFailure($user['id'], '密码错误');

                    // 渐进延迟：每次失败增加延迟，防止暴力破解
                    $delaySeconds = 1;
                    if (isset($user['login_attempts'])) {
                        $delaySeconds = min(pow(2, $user['login_attempts']), 8); // 最多延迟8秒
                    }
                    sleep($delaySeconds);

                    if (isset($user['login_attempts'])) {
                        $updateStmt = $db->prepare("UPDATE admins SET login_attempts = login_attempts + 1, last_login_attempt = ? WHERE id = ?");
                        $updateStmt->execute([time(), $user['id']]);

                        // 重新获取更新后的数据检查锁定状态
                        $checkStmt = $db->prepare("SELECT login_attempts, last_login_attempt FROM admins WHERE id = ?");
                        $checkStmt->execute([$user['id']]);
                        $updatedUser = $checkStmt->fetch();

                        if ($updatedUser['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
                            $remaining_lock_time = ceil(LOCKOUT_DURATION / 60);
                            $login_error = "密码错误，账户已被锁定，请在 {$remaining_lock_time} 分钟后重试";
                        } else {
                            $remaining_attempts = MAX_LOGIN_ATTEMPTS - $updatedUser['login_attempts'];
                            $login_error = "用户名/邮箱或密码错误，剩余 {$remaining_attempts} 次尝试机会";
                        }
                    } else {
                        $login_error = '用户名/邮箱或密码错误';
                    }
                } elseif (!$user) {
                    $login_error = '用户名/邮箱或密码错误';
                }
            }
            } // 关闭人机验证的 else
            } // 关闭 CSRF 验证的 else
        }
    }

    // 处理验证码验证
    if (isset($_POST['action']) && $_POST['action'] === 'verify_code') {
        $verify_code = trim($_POST['verify_code'] ?? '');
        $v_user_id = (int)($_POST['verify_user_id'] ?? 0);

        if (empty($verify_code) || !$v_user_id) {
            $login_error = '请输入验证码';
        } elseif (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
            $login_error = '安全验证失败，请刷新页面后重试';
        } else {
            // 获取用户信息
            $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
            $stmt->execute([$v_user_id]);
            $user = $stmt->fetch();

            if (!$user) {
                $login_error = '用户不存在，请重新登录';
                $need_email_verify = false;
            } elseif (!empty($user['is_banned'])) {
                $login_error = '您的账号已被封禁，无法登录';
                $need_email_verify = false;
            } else {
                // 查找未使用的验证码
                $stmt = $db->prepare("SELECT id FROM email_verification WHERE email = ? AND code = ? AND purpose = 'login_verify' AND is_used = 0 AND expires_at > NOW()");
                $stmt->execute([$user['email'], $verify_code]);
                $verifyRecord = $stmt->fetch();

                if ($verifyRecord) {
                    // 验证通过，删除该验证码记录（避免唯一索引冲突）
                    $updateStmt = $db->prepare("DELETE FROM email_verification WHERE id = ?");
                    $updateStmt->execute([$verifyRecord['id']]);

                    // 完成登录
                    completeLogin($user, !empty($_POST['remember']));
                } else {
                    $login_error = '验证码错误或已过期，请重新输入';
                    $need_email_verify = true;
                    $verify_email = $user['email'];
                    $verify_user_id = $user['id'];
                    $verify_username = $user['username'];
                }
            }
        }
    }
}

/**
 * 完成登录流程（设置Session + 重定向）
 */
function completeLogin($user, $rememberMe = false) {
    global $redirect_url;
    $db = getDB();

    // 防止会话固定攻击：重新生成会话ID
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_email'] = $user['email'] ?? '';
    $_SESSION['user_role'] = $user['role'];

    // 登录成功，重置速率限制
    resetRateLimit('login');

    // 重置账户登录失败次数（如果字段存在）
    if (isset($user['login_attempts'])) {
        $resetStmt = $db->prepare("UPDATE admins SET login_attempts = 0 WHERE id = ?");
        $resetStmt->execute([$user['id']]);
    }

    // 处理记住我 / 设备管理
    ensureSessionTables();
    createSession($user['id'], $rememberMe);

    // 安全重定向到指定页面
    $redirect_url = safeRedirectUrl($redirect_url);
    header('Location: ' . $redirect_url);
    exit;
}

// 如果因为需要邮箱验证，需要重新获取CSRF Token
if ($need_email_verify) {
    // CSRF token 已在函数中生成，这里保持
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($config['website_name']) ?> - 用户登录</title>
    <script>
        (function () {
            try {
                document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('theme') === 'dark' ? 'dark' : 'light');
            } catch (error) {
                document.documentElement.setAttribute('data-bs-theme', 'light');
            }
        })();
    </script>
    
    <?php 
    if (!empty($config['favicon'])): 
        $faviconUrl = $config['favicon'];
        if (!preg_match('/^https?:\/\//', $faviconUrl) && strpos($faviconUrl, '/') !== 0) {
            $faviconUrl = '/' . $faviconUrl;
        }
    ?>
    <link rel="icon" type="image/x-icon" href="<?= e($faviconUrl) ?>">
    <link rel="shortcut icon" href="<?= e($faviconUrl) ?>">
    <link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
    <?php endif; ?>
    
    <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= getResourceUrl('/assets/css/bootstrap-icons.css', 'https://cdn.staticfile.net/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="/assets/css/harmonyos-sans.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --accent-color: #4895ef;
            --text-color: #2b2d42;
            --bg-gradient: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        
        body {
            background: var(--bg-gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'HarmonyOS Sans', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            padding: 20px;
        }

        .auth-wrapper {
            display: flex;
            width: 100%;
            max-width: 900px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            min-height: 600px;
        }

        .auth-sidebar {
            flex: 1;
            background: linear-gradient(135deg, #4361ee 0%, #3f37c9 100%);
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .auth-sidebar::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .auth-sidebar-content {
            position: relative;
            z-index: 1;
        }

        .auth-main {
            flex: 1;
            padding: 40px 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: white;
        }

        .brand-logo {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .welcome-text h2 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 15px;
            line-height: 1.2;
        }

        .welcome-text p {
            opacity: 0.9;
            font-size: 16px;
            line-height: 1.6;
        }

        .auth-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 10px;
        }

        .auth-subtitle {
            color: #6c757d;
            margin-bottom: 30px;
        }

        .form-floating > .form-control {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding-left: 15px;
        }

        .form-floating > .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
        }

        .form-floating > label {
            padding-left: 15px;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            border-radius: 12px;
            padding: 14px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .btn-primary:hover:not(:disabled) {
            background: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
        }

        .btn-primary:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        .social-login {
            margin-top: 30px;
            text-align: center;
            position: relative;
        }

        .social-login::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #e9ecef;
            z-index: 0;
        }

        .social-login span {
            background: white;
            padding: 0 15px;
            color: #6c757d;
            position: relative;
            z-index: 1;
            font-size: 14px;
        }

        .back-link {
            position: absolute;
            top: 30px;
            left: 30px;
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 10;
            transition: color 0.2s;
        }
        
        .back-link:hover {
            color: var(--primary-color);
        }

        .alert-danger {
            background-color: #fff2f2;
            border-color: #ffe6e6;
            color: #dc3545;
            border-radius: 12px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-info {
            background-color: #eff6ff;
            border-color: #dbeafe;
            color: #1d4ed8;
            border-radius: 12px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .verify-step {
            display: none;
        }
        .verify-step.active {
            display: block;
        }

        .code-input-group {
            display: flex;
            gap: 8px;
        }
        .code-input-group .form-control {
            flex: 1;
        }
        .code-input-group .btn {
            white-space: nowrap;
            min-width: 120px;
        }

        .verify-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4361ee 0%, #3f37c9 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 28px;
            color: white;
        }

        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 24px;
        }
        .step-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #dee2e6;
            transition: all 0.3s;
        }
        .step-dot.active {
            width: 24px;
            border-radius: 4px;
            background: var(--primary-color);
        }
        .step-dot.done {
            background: #22c55e;
        }

        @media (max-width: 768px) {
            body {
                padding: 15px;
                align-items: flex-start;
                padding-top: 55px;
            }
            .auth-wrapper {
                flex-direction: column;
                min-height: auto;
                border-radius: 20px;
            }
            .auth-sidebar {
                padding: 25px 20px;
                min-height: auto;
            }
            .auth-sidebar .brand-logo {
                font-size: 18px;
                justify-content: center;
            }
            .welcome-text {
                text-align: center;
            }
            .welcome-text h2 {
                font-size: 24px;
                margin-bottom: 8px;
            }
            .welcome-text p {
                font-size: 14px;
                margin-bottom: 5px;
            }
            .auth-sidebar .text-center.mt-4 {
                margin-top: 20px !important;
            }
            .auth-main {
                padding: 25px 20px;
            }
            .auth-title {
                font-size: 22px;
            }
            .auth-subtitle {
                font-size: 14px;
                margin-bottom: 20px;
            }
            .form-floating.mb-3 {
                margin-bottom: 15px;
            }
            .d-flex.justify-content-between {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start !important;
            }
            .btn-primary {
                padding: 12px;
                font-size: 15px;
            }
            .back-link {
                top: 15px;
                left: 15px;
                color: white;
                font-size: 14px;
            }
            .alert-danger, .alert-info {
                padding: 12px;
                font-size: 14px;
            }
        }
        
        @media (max-width: 380px) {
            .auth-sidebar {
                padding: 20px 15px;
            }
            .auth-main {
                padding: 20px 15px;
            }
            .welcome-text h2 {
                font-size: 20px;
            }
            .auth-title {
                font-size: 20px;
            }
            .form-floating > .form-control {
                font-size: 15px;
            }
        }
    </style>
    <link href="/assets/css/account.css?v=20260728-1" rel="stylesheet">
</head>
<body class="account-auth-page">
    <div class="account-auth-toolbar" aria-label="页面工具">
        <a href="<?= htmlspecialchars($redirect_url) ?>" class="back-link">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>返回</span>
        </a>
        <button type="button" class="account-icon-button" data-account-theme-toggle aria-label="切换显示模式">
            <i class="bi bi-moon-stars" aria-hidden="true"></i>
        </button>
    </div>

    <div class="auth-wrapper">
        <div class="auth-sidebar">
            <div class="auth-sidebar-content">
                <div class="brand-logo">
                    <span class="brand-mark"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                    <span><?= e($config['website_name']) ?></span>
                </div>
                <div class="welcome-text">
                    <span class="account-eyebrow">Welcome back</span>
                    <h2>回到你的内容空间</h2>
                    <p>安全登录后继续阅读、评论，并在个人中心管理账户与在线设备。</p>
                </div>
                <ul class="auth-feature-list" aria-label="账户能力">
                    <li class="auth-feature-item">
                        <span class="auth-feature-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
                        <span>新环境邮箱验证</span>
                    </li>
                    <li class="auth-feature-item">
                        <span class="auth-feature-icon"><i class="bi bi-laptop" aria-hidden="true"></i></span>
                        <span>多设备会话管理</span>
                    </li>
                    <li class="auth-feature-item">
                        <span class="auth-feature-icon"><i class="bi bi-lock" aria-hidden="true"></i></span>
                        <span>安全会话保护</span>
                    </li>
                </ul>
            </div>
            <div class="auth-sidebar-content auth-sidebar-footer">
                <span>&copy; <?= date('Y') ?> <?= e($config['website_name']) ?></span>
                <span class="auth-security-pill"><i class="bi bi-shield-lock" aria-hidden="true"></i> 安全连接</span>
            </div>
        </div>

        <div class="auth-main">
            <div class="auth-main-inner">
            <!-- 步骤指示器 -->
            <div class="auth-progress" aria-label="登录进度">
                <div class="auth-progress-item <?= $need_email_verify ? 'done' : 'active' ?>" id="stepDot1">
                    <span class="auth-progress-number"><span>1</span></span>
                    <div><small>Step 01</small><strong>账号验证</strong></div>
                </div>
                <div class="auth-progress-line" aria-hidden="true"></div>
                <div class="auth-progress-item <?= $need_email_verify ? 'active' : '' ?>" id="stepDot2">
                    <span class="auth-progress-number"><span>2</span></span>
                    <div><small>Step 02</small><strong>安全验证</strong></div>
                </div>
            </div>

            <!-- 第一步：账号密码登录 -->
            <div class="verify-step <?= $need_email_verify ? '' : 'active' ?>" id="step1">
                <div class="mb-4">
                    <span class="account-eyebrow">Account sign in</span>
                    <h1 class="auth-title">欢迎回来</h1>
                    <p class="auth-subtitle">使用用户名或邮箱登录，继续访问你的账户。</p>
                </div>

                <?php if ($login_error && !$need_email_verify): ?>
                <div class="alert alert-danger mb-4" role="alert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <?= htmlspecialchars($login_error) ?>
                    <?php if (strpos($login_error, '已被封禁') !== false): ?>
                    <a href="/vendor/appeal.php" class="text-decoration-none fw-bold" style="color: #ffc0c0;">申请解封</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form method="POST" id="formTraditional">
                    <?= csrfField() ?>
                    <?= honeypotField('website_hp') ?>
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($redirect_url) ?>">
                    <input type="hidden" name="captcha_token" id="loginCaptchaToken" value="">
                    
                    <div class="account-form-group">
                        <label class="account-form-label" for="username">用户名或邮箱</label>
                        <div class="account-input-wrap">
                            <i class="bi bi-person account-input-icon" aria-hidden="true"></i>
                            <input type="text" class="form-control account-input" id="username" name="username"
                                   placeholder="请输入用户名或邮箱"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                   autocomplete="username" autocapitalize="none" spellcheck="false" required
                                   <?= !$need_email_verify ? 'autofocus' : '' ?>>
                        </div>
                    </div>

                    <div class="account-form-group">
                        <label class="account-form-label" for="password">密码</label>
                        <div class="account-input-wrap">
                            <i class="bi bi-lock account-input-icon" aria-hidden="true"></i>
                            <input type="password" class="form-control account-input" id="password" name="password"
                                   placeholder="请输入登录密码" autocomplete="current-password"
                                   data-caps-lock-hint="loginCapsLockHint" required>
                            <button type="button" class="account-password-toggle" data-password-toggle="password"
                                    aria-label="显示密码" aria-pressed="false">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="account-field-hint warning" id="loginCapsLockHint" hidden>
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> 大写锁定已开启
                        </div>
                    </div>

                    <div class="account-form-options">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1">
                            <label class="form-check-label" for="remember">记住此设备</label>
                        </div>
                        <a href="/vendor/forgot_password.php<?= ($redirect_url && $redirect_url !== '/') ? '?redirect_url=' . urlencode($redirect_url) : '' ?>" class="account-link">
                            忘记密码？
                        </a>
                    </div>

                    <button type="submit" class="btn account-btn-primary w-100 mb-4" id="btnLogin">
                        <span>登录账户</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>

                    <div class="text-center">
                        <span style="color: var(--account-muted);">还没有账号？</span>
                        <a href="/vendor/register.php<?= ($redirect_url && $redirect_url !== '/') ? '?redirect_url=' . urlencode($redirect_url) : '' ?>" class="account-link">
                            立即注册
                        </a>
                    </div>
                </form>
            </div>

            <!-- 第二步：邮箱验证 -->
            <div class="verify-step <?= $need_email_verify ? 'active' : '' ?>" id="step2">
                <div class="mb-4 text-center">
                    <div class="verify-icon">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <h1 class="auth-title">安全验证</h1>
                    <p class="auth-subtitle">检测到新的设备或网络环境，请验证邮箱以保护账户。</p>
                </div>

                <div class="alert alert-info mb-4" role="alert">
                    <i class="bi bi-info-circle-fill"></i>
                    <span>验证码将发送至 <strong><?= htmlspecialchars(preg_replace('/(.{2})(.*)(@.*)/', '$1***$3', $verify_email)) ?></strong>，10分钟内有效</span>
                </div>

                <?php if ($login_error && $need_email_verify): ?>
                <div class="alert alert-danger mb-3" role="alert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <?= htmlspecialchars($login_error) ?>
                    <?php if (strpos($login_error, '已被封禁') !== false): ?>
                    <a href="/vendor/appeal.php" class="text-decoration-none fw-bold" style="color: #dc3545;">申请解封</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form method="POST" id="formVerify">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="verify_code">
                    <input type="hidden" name="verify_user_id" value="<?= htmlspecialchars($verify_user_id) ?>">
                    <input type="hidden" name="remember" value="<?= !empty($_POST['remember']) ? '1' : '0' ?>">
                    <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($redirect_url) ?>">

                    <div class="code-input-group mb-3">
                        <div class="account-form-group mb-0">
                            <label class="account-form-label" for="verify_code">邮箱验证码</label>
                            <div class="account-input-wrap">
                                <i class="bi bi-123 account-input-icon" aria-hidden="true"></i>
                                <input type="text" class="form-control account-input" id="verify_code" name="verify_code"
                                       placeholder="6 位数字验证码" maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                                       autocomplete="one-time-code" required
                                       value="<?= htmlspecialchars($_POST['verify_code'] ?? '') ?>"
                                       <?= $need_email_verify ? 'autofocus' : '' ?>>
                            </div>
                        </div>
                        <button type="button" class="account-code-button" id="btnSendCode" onclick="sendLoginCode()">
                            发送验证码
                        </button>
                    </div>

                    <button type="submit" class="btn account-btn-primary w-100 mb-3">
                        <i class="bi bi-check-lg me-1"></i>验证并登录
                    </button>

                    <div class="text-center">
                        <a href="/vendor/login.php" class="account-link">
                            <i class="bi bi-arrow-left me-1"></i>返回重新登录
                        </a>
                    </div>
                </form>
            </div>
            <div class="auth-footnote">
                <i class="bi bi-lock" aria-hidden="true"></i>
                登录信息仅用于账户身份验证
            </div>
            </div>
        </div>
    </div>

    <!-- 人机验证弹窗 -->
    <div class="modal fade" id="captchaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content" style="border:none;box-shadow:none;background:transparent;">
                <div class="modal-body p-0 d-flex justify-content-center">
                    <div id="login-captcha-container"></div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="/assets/js/account-ui.js?v=20260727-1"></script>
    <script src="/vendor/public/captcha/BehaviorAuth.js?v=20260727-2"></script>
    <script>
    // 登录人机验证
    let loginCaptcha = null;
    const traditionalForm = document.getElementById('formTraditional');

    traditionalForm?.addEventListener('submit', function(event) {
        event.preventDefault();
        const form = traditionalForm;
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;

        if (!username || !password) {
            showToast('请输入用户名和密码', 'danger');
            return;
        }

        // 弹出验证码
        const captchaModal = new bootstrap.Modal(document.getElementById('captchaModal'));
        captchaModal.show();

        if (!loginCaptcha) {
            loginCaptcha = new BehaviorAuth('login-captcha-container', '/vendor/public/captcha/AuthApi.php');
            loginCaptcha.onSuccess = function(bizToken) {
                document.getElementById('loginCaptchaToken').value = bizToken;
                setTimeout(() => {
                    const captchaModalEl = document.getElementById('captchaModal');
                    const captchaModalInstance = bootstrap.Modal.getInstance(captchaModalEl);
                    if (captchaModalInstance) captchaModalInstance.hide();
                    form.submit();
                }, 500);
            };
            loginCaptcha.onFail = function() {
                document.getElementById('loginCaptchaToken').value = '';
            };
        } else {
            loginCaptcha.reset();
        }
    });

    <?php if ($need_email_verify): ?>
    // 页面加载后自动发送验证码
    document.addEventListener('DOMContentLoaded', function() {
        sendLoginCode();
    });
    <?php endif; ?>

    function sendLoginCode() {
        const btn = document.getElementById('btnSendCode');
        if (btn.disabled) return;
        btn.disabled = true;

        const userId = document.querySelector('input[name="verify_user_id"]').value;
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;

        fetch('/vendor/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=send_login_code&user_id=' + encodeURIComponent(userId) + '&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                startCountdown(btn, 60);
            } else {
                showToast(data.message, 'danger');
                btn.disabled = false;
            }
        })
        .catch(() => {
            showToast('网络错误，请重试', 'danger');
            btn.disabled = false;
        });
    }

    function startCountdown(btn, seconds) {
        let remaining = seconds;
        btn.textContent = remaining + 's 后重发';
        const timer = setInterval(() => {
            remaining--;
            if (remaining <= 0) {
                clearInterval(timer);
                btn.textContent = '重新发送';
                btn.disabled = false;
            } else {
                btn.textContent = remaining + 's 后重发';
            }
        }, 1000);
    }

    function showToast(msg, type) {
        if (window.showAccountToast) {
            window.showAccountToast(msg, type);
        } else {
            alert(msg);
        }
    }

    // 验证码输入框只允许数字
    document.getElementById('verify_code')?.addEventListener('input', function(e) {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });
    </script>
</body>
</html>
