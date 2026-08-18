<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';

// 封禁 IP 禁止访问
if (isBotBlacklisted()) { http_response_code(403); header('Location: /vendor/public/error/banned.html'); exit; }

// 记录访问
recordVisit($_SERVER['REQUEST_URI']);

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 注册页必须独立于前台主题始终可访问；这里只清理失效会话，不在 GET 阶段跳转。
if (isset($_SESSION['user_id'])) {
    $sessionUserStmt = $db->prepare("SELECT id FROM admins WHERE id = ? LIMIT 1");
    $sessionUserStmt->execute([(int)$_SESSION['user_id']]);
    if (!$sessionUserStmt->fetchColumn()) {
        unset(
            $_SESSION['user_id'],
            $_SESSION['user_username'],
            $_SESSION['user_email'],
            $_SESSION['user_role']
        );
    }
}

$register_error = '';
$register_success = '';
$email_sent = '';

// 获取重定向 URL
$redirect_url = safeRedirectUrl($_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '/');

// 处理用户名检测请求（AJAX）
if (isset($_POST['action']) && $_POST['action'] === 'check_username') {
    $username = trim($_POST['username'] ?? '');
    
    header('Content-Type: application/json');
    
    if (empty($username)) {
        echo json_encode(['valid' => false, 'message' => '请输入用户名']);
        exit;
    }
    
    // 使用用户名验证函数
    $username_check = isValidUsername($username);
    if (!$username_check['valid']) {
        echo json_encode(['valid' => false, 'message' => $username_check['message']]);
        exit;
    }
    
    try {
        // 检查用户名是否已存在
        $stmt = $db->prepare("SELECT id FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            echo json_encode(['valid' => false, 'message' => '用户名已存在']);
        } else {
            echo json_encode(['valid' => true, 'message' => '用户名可用']);
        }
    } catch (Exception $e) {
        echo json_encode(['valid' => false, 'message' => '检测失败，请重试']);
    }
    exit;
}

// 处理邮箱检测请求（AJAX）
if (isset($_POST['action']) && $_POST['action'] === 'check_email') {
    $email = trim($_POST['email'] ?? '');
    
    header('Content-Type: application/json');
    
    if (empty($email)) {
        echo json_encode(['valid' => false, 'message' => '请输入邮箱地址']);
        exit;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['valid' => false, 'message' => '请输入有效的邮箱地址']);
        exit;
    }
    
    if (!isAllowedEmailDomain($email)) {
        echo json_encode(['valid' => false, 'message' => '请使用常用邮箱地址']);
        exit;
    }
    
    try {
        // 检查邮箱是否已存在
        $stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['valid' => false, 'message' => '该邮箱已被注册']);
        } else {
            echo json_encode(['valid' => true, 'message' => '邮箱可用']);
        }
    } catch (Exception $e) {
        echo json_encode(['valid' => false, 'message' => '检测失败，请重试']);
    }
    exit;
}

// 创建邮箱验证码表（如果不存在）
$db->exec("CREATE TABLE IF NOT EXISTS `email_verification` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '验证码ID',
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '邮箱地址',
  `code` varchar(6) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '验证码',
  `purpose` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'register' COMMENT '用途: register=注册, reset=重置密码',
  `is_used` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已使用(0:未使用 1:已使用)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `expires_at` timestamp NOT NULL COMMENT '过期时间',
  `used_at` timestamp NULL DEFAULT NULL COMMENT '使用时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_purpose_unused` (`email`, `purpose`, `is_used`),
  KEY `idx_email_expires` (`email`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮箱验证码表'");

// 发送验证码
if (isset($_POST['action']) && $_POST['action'] === 'send_code') {
    $email = trim($_POST['email'] ?? '');

    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $register_error = '安全验证失败，请刷新页面后重试';
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = '请输入有效的邮箱地址';
    } elseif (!isAllowedEmailDomain($email)) {
        $register_error = '请使用常用邮箱地址';
    } else {
        // 速率限制检查：防止邮箱验证码滥用
        $ipRateLimit = checkRateLimit('register_email_code', 5, 3600);
        $emailRateKey = 'email:' . hash('sha256', accountLowercase($email));
        $emailRateLimit = checkRateLimit('register_email_code', 3, 3600, $emailRateKey);
        if (!$ipRateLimit['allowed'] || !$emailRateLimit['allowed']) {
            $register_error = !$ipRateLimit['allowed'] ? $ipRateLimit['message'] : $emailRateLimit['message'];
        } else {
            try {
                // 先检查是否已有未过期的验证码（避免频繁生成）
                $stmt = $db->prepare("SELECT id, code FROM email_verification WHERE email = ? AND purpose = 'register' AND is_used = 0 AND expires_at > DATE_ADD(NOW(), INTERVAL 5 MINUTE) ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$email]);
                $existingCode = $stmt->fetch();
                
                if ($existingCode) {
                    // 已有有效验证码，直接重发
                    $code = $existingCode['code'];
                    $sendResult = sendVerificationEmail($email, $code);
                    if ($sendResult) {
                        $email_sent = "验证码已发送到 {$email}（验证码未过期，已重新发送）";
                    } else {
                        $register_error = '邮件发送失败，请稍后重试';
                    }
                } else {
                    // 删除该邮箱同用途的所有旧验证码（包括已使用的，避免唯一索引冲突）
                    $stmt = $db->prepare("DELETE FROM email_verification WHERE email = ? AND purpose = 'register'");
                    $stmt->execute([$email]);

                    // 生成6位验证码（使用更安全的随机数）
                    $code = generateVerificationCode();
                    $expires_at = date('Y-m-d H:i:s', time() + 600); // 10分钟后过期

                    // 先存储验证码到数据库
                    $stmt = $db->prepare("INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'register', ?)");
                    if ($stmt->execute([$email, $code, $expires_at])) {
                        // 验证码已存储，再发送邮件（即使发送失败，验证码仍在数据库中）
                        $sendResult = sendVerificationEmail($email, $code);
                        if ($sendResult) {
                            $email_sent = "验证码已发送到 {$email}，10分钟内有效";
                        } else {
                            // 邮件发送失败，但验证码已入库，用户可以稍后重试发送
                            $register_error = '邮件发送失败，请点击重发按钮重试（验证码已生成）';
                        }
                    } else {
                        $register_error = '生成验证码失败，请重试';
                    }
                }
            } catch (Exception $e) {
                $register_error = '发送验证码出错，请稍后重试';
                error_log("send_code异常: " . $e->getMessage());
            }
        }
    }
}

// 处理注册
if (isset($_POST['action']) && $_POST['action'] === 'register') {
    // CSRF验证
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $register_error = '安全验证失败，请刷新页面后重试';
    } elseif (checkHoneypot()) {
        // 蜜罐触发：假装注册成功，不暴露蜜罐
        $register_success = '注册成功，请登录';
    } else {
        // 人机验证
        $captcha_token = $_POST['captcha_token'] ?? '';
        require_once __DIR__ . '/public/captcha/AuthApi.php';
        $captchaAuth = new BehaviorAuth();
        if (empty($captcha_token) || !$captchaAuth->verifyBizToken($captcha_token)) {
            $register_error = '人机验证失败，请重试';
        } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $verification_code = trim($_POST['verification_code'] ?? '');

    if (empty($username) || empty($password) || empty($email) || empty($verification_code)) {
        $register_error = '请填写所有必填字段';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = '请输入有效的邮箱地址';
    } elseif (!isAllowedEmailDomain($email)) {
        $register_error = '请使用常用邮箱地址';
    } elseif ($password !== $confirm_password) {
        $register_error = '两次输入的密码不一致';
    } elseif (accountTextLength($password) < 6) {
        $register_error = '密码长度至少6位';
    } else {
        // 速率限制检查：防止恶意注册
        $rateLimit = checkRateLimit('register', 10, 3600); // 1小时内最多10次注册尝试
        if (!$rateLimit['allowed']) {
            $register_error = $rateLimit['message'];
        } else {
            // 使用新的用户名验证函数
            $username_check = isValidUsername($username);
            if (!$username_check['valid']) {
                $register_error = $username_check['message'];
            } else {
                try {
                    // 验证邮箱验证码
                    $stmt = $db->prepare("SELECT id FROM email_verification WHERE email = ? AND code = ? AND purpose = 'register' AND is_used = 0 AND expires_at > NOW()");
                    $stmt->execute([$email, $verification_code]);
                    if (!$stmt->fetch()) {
                        $register_error = '验证码无效或已过期';
                    } else {
                        // 检查用户名是否已存在
                        $stmt = $db->prepare("SELECT id FROM admins WHERE username = ?");
                        $stmt->execute([$username]);
                        if ($stmt->fetch()) {
                            $register_error = '用户名已存在';
                        } else {
                            // 检查邮箱是否已存在
                            $stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
                            $stmt->execute([$email]);
                            if ($stmt->fetch()) {
                                $register_error = '该邮箱已被注册';
                            } else {
                                // 标记验证码已使用
                                $stmt = $db->prepare("UPDATE email_verification SET is_used = 1, used_at = NOW() WHERE email = ? AND code = ? AND purpose = 'register'");
                                $stmt->execute([$email, $verification_code]);

                                // 创建用户
                                $hashedPassword = hashPassword($password);
                                // 获取真实IP（支持代理）
                                $registerIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                                // 防止伪造：如果获取到多个IP，只取第一个
                                if (strpos($registerIp, ',') !== false) {
                                    $registerIp = trim(explode(',', $registerIp)[0]);
                                }
                                // 确保 register_ip 字段存在
                                $columns = $db->query("SHOW COLUMNS FROM admins LIKE 'register_ip'")->fetch();
                                if (!$columns) {
                                    $db->exec("ALTER TABLE admins ADD COLUMN register_ip VARCHAR(45) DEFAULT '' COMMENT '注册IP'");
                                }
                                $stmt = $db->prepare("INSERT INTO admins (username, password, email, role, register_ip) VALUES (?, ?, ?, 'user', ?)");
                                if ($stmt->execute([$username, $hashedPassword, $email, $registerIp])) {
                                    $register_success = '注册成功！正在跳转到登录页面...';

                                    // 构建跳转 URL
                                    $login_url = '/vendor/login.php';
                                    if ($redirect_url && $redirect_url !== '/') {
                                        $login_url .= '?redirect_url=' . urlencode($redirect_url);
                                    }

                                    // 3秒后跳转到登录页面
                                    header('refresh:3;url=' . $login_url);
                                } else {
                                    $register_error = '注册失败，请重试';
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log('Register account error: ' . $e->getMessage());
                    $register_error = '注册暂时失败，请稍后重试';
                }
            }
        }
        }
        } // 关闭人机验证的 else
    } // 关闭 CSRF 验证的 else
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($config['website_name']) ?> - 用户注册</title>
    <script>
        (function () {
            try {
                document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('theme') === 'dark' ? 'dark' : 'light');
            } catch (error) {
                document.documentElement.setAttribute('data-bs-theme', 'light');
            }
        })();
    </script>
    
    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
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

        .btn-primary:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
        }

        .btn-outline-secondary {
            border-radius: 0 12px 12px 0;
            border: 2px solid #e9ecef;
            border-left: none;
            padding: 0 20px;
        }

        .btn-outline-secondary:hover {
            background-color: #f8f9fa;
            color: var(--text-color);
            border-color: #e9ecef;
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

        .alert {
            border-radius: 12px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-danger {
            background-color: #fff2f2;
            border-color: #ffe6e6;
            color: #dc3545;
        }

        .alert-success {
            background-color: #f0fdf4;
            border-color: #dcfce7;
            color: #166534;
        }

        /* 修复 input-group 和 floating-label 组合样式 */
        .input-group > .form-floating {
            flex: 1;
            width: 1%;
        }
        .input-group > .form-floating > .form-control {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
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
                font-size: 20px;
                margin-bottom: 8px;
            }
            .welcome-text p {
                font-size: 14px;
                margin-bottom: 0;
            }
            .auth-sidebar .text-center.mt-4 {
                margin-top: 15px !important;
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
            .row .col-md-6 {
                margin-bottom: 0 !important;
            }
            .mb-3 {
                margin-bottom: 12px !important;
            }
            .form-floating {
                margin-bottom: 0;
            }
            .input-group {
                flex-direction: column;
            }
            .input-group .form-floating {
                width: 100%;
            }
            .input-group .btn-outline-secondary {
                border: 2px solid #e9ecef;
                border-top: none;
                border-radius: 0 0 12px 12px;
                width: 100%;
                padding: 12px;
                margin-top: -1px;
            }
            .input-group .form-floating > .form-control {
                border-radius: 12px 12px 0 0;
            }
            .form-floating.mb-4 {
                margin-bottom: 20px !important;
            }
            .btn-primary.w-100 {
                padding: 12px;
                font-size: 15px;
            }
            .back-link {
                top: 15px;
                left: 15px;
                color: white;
                font-size: 14px;
            }
            .alert {
                padding: 12px;
                font-size: 14px;
            }
            .form-text {
                font-size: 12px;
            }
        }
        
        @media (max-width: 380px) {
            .auth-sidebar {
                padding: 20px 15px;
            }
            .auth-sidebar .brand-logo {
                font-size: 16px;
            }
            .welcome-text h2 {
                font-size: 18px;
            }
            .auth-main {
                padding: 20px 15px;
            }
            .auth-title {
                font-size: 20px;
            }
            .form-floating > .form-control {
                font-size: 15px;
                height: calc(2.5rem + 2px);
            }
        }
    </style>
    <?php
    $useMonochromeAccount = trim((string)($config['active_theme'] ?? 'default')) === 'monochrome';
    $accountBaseVersion = (int)@filemtime(__DIR__ . '/../assets/css/account.css');
    $accountThemeVersion = (int)@filemtime(__DIR__ . '/nova-themes/monochrome/assets/css/account.css');
    ?>
    <link href="/assets/css/account.css<?= $accountBaseVersion ? '?v=' . $accountBaseVersion : '' ?>" rel="stylesheet">
    <?php if ($useMonochromeAccount): ?>
    <link href="/vendor/nova-themes/monochrome/assets/css/account.css<?= $accountThemeVersion ? '?v=' . $accountThemeVersion : '' ?>" rel="stylesheet">
    <?php endif; ?>
</head>
<body class="account-auth-page account-register-page">
    <div class="account-auth-toolbar" aria-label="页面工具">
        <a href="<?= htmlspecialchars($redirect_url) ?>" class="back-link">
            <i class="bi bi-arrow-left" aria-hidden="true"></i><span>返回</span>
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
                    <span class="account-eyebrow">Join the community</span>
                    <h2>创建你的内容身份</h2>
                    <p>一个账户即可参与评论、管理在线设备，并获得完整的内容体验。</p>
                    <?php $allowedDomains = getAllowedEmailDomains(); ?>
                    <div class="account-domain-cloud">
                        <span class="account-domain-cloud-label">支持邮箱域名</span>
                            <?php foreach (array_slice($allowedDomains, 0, 12) as $domain): ?>
                            <span class="account-domain-chip"><?= htmlspecialchars($domain) ?></span>
                            <?php endforeach; ?>
                            <?php if (count($allowedDomains) > 12): ?>
                            <span class="account-domain-chip">+<?= count($allowedDomains) - 12 ?></span>
                            <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="auth-sidebar-content auth-sidebar-footer">
                <span>&copy; <?= date('Y') ?> <?= e($config['website_name']) ?></span>
                <span class="auth-security-pill"><i class="bi bi-shield-lock" aria-hidden="true"></i> 安全注册</span>
            </div>
        </div>

        <div class="auth-main">
            <div class="auth-main-inner">
            <div class="mb-4">
                <span class="account-eyebrow">Create account</span>
                <h1 class="auth-title">创建账户</h1>
                <p class="auth-subtitle">完成邮箱验证后即可创建账户。</p>
            </div>

            <?php if ($register_success): ?>
            <div class="alert alert-success mb-4" role="alert">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($register_success) ?>
            </div>
            <?php endif; ?>

            <?php if ($register_error): ?>
            <div class="alert alert-danger mb-4" role="alert">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($register_error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="registerForm">
                <?= csrfField() ?>
                <?= honeypotField('website_hp') ?>
                <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($redirect_url) ?>">
                <input type="hidden" name="captcha_token" id="registerCaptchaToken" value="">
                
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="username" name="username" 
                           placeholder="用户名" 
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                           required minlength="3" maxlength="20" autocomplete="username" autofocus>
                    <label for="username">用户名 / 登录名（3-20字符）</label>
                    <div id="username-feedback" class="form-text mt-2"></div>
                </div>

                <div class="account-auth-grid">
                    <div class="account-form-group">
                        <label class="account-form-label" for="password">登录密码</label>
                        <div class="account-input-wrap">
                            <i class="bi bi-lock account-input-icon" aria-hidden="true"></i>
                            <input type="password" class="form-control account-input" id="password" name="password"
                                   placeholder="至少 6 位" autocomplete="new-password" required minlength="6"
                                   data-password-strength="registerPasswordStrength">
                            <button type="button" class="account-password-toggle" data-password-toggle="password" aria-label="显示密码" aria-pressed="false">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="account-strength" id="registerPasswordStrength" data-level="0" aria-label="密码强度"><span></span><span></span><span></span><span></span></div>
                    </div>
                    <div class="account-form-group">
                        <label class="account-form-label" for="confirm_password">确认密码</label>
                        <div class="account-input-wrap">
                            <i class="bi bi-shield-lock account-input-icon" aria-hidden="true"></i>
                            <input type="password" class="form-control account-input" id="confirm_password" name="confirm_password"
                                   placeholder="再次输入密码" autocomplete="new-password" required minlength="6">
                            <button type="button" class="account-password-toggle" data-password-toggle="confirm_password" aria-label="显示确认密码" aria-pressed="false">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="input-group">
                        <div class="form-floating">
                            <input type="email" class="form-control" id="email" name="email" 
                                   placeholder="邮箱" 
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autocomplete="email" inputmode="email">
                            <label for="email">邮箱地址</label>
                        </div>
                        <button class="btn btn-outline-secondary" type="button" id="sendCodeBtn" onclick="sendVerificationCode()" disabled>
                            <span id="sendCodeText">发送验证码</span>
                        </button>
                    </div>
                    <div id="email-feedback" class="form-text mt-2"></div>
                </div>

                <div class="form-floating mb-4">
                    <input type="text" class="form-control" id="verification_code" name="verification_code" 
                           placeholder="邮箱验证码" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code">
                    <label for="verification_code">邮箱验证码</label>
                </div>

                <button type="button" class="btn account-btn-primary w-100 mb-4" onclick="submitRegister()">
                    <span>创建账户</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
                </button>

                <div class="text-center">
                    <span style="color:var(--account-muted)">已有账号？</span>
                    <a href="/vendor/login.php<?= ($redirect_url && $redirect_url !== '/') ? '?redirect_url=' . urlencode($redirect_url) : '' ?>" class="account-link">
                        立即登录
                    </a>
                </div>
            </form>
            <div class="auth-footnote"><i class="bi bi-lock" aria-hidden="true"></i>账户信息仅用于身份验证与安全通知</div>
            </div>
        </div>
    </div>
    
    <!-- 人机验证弹窗 -->
    <div class="modal fade" id="captchaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content" style="border:none;box-shadow:none;background:transparent;">
                <div class="modal-body p-0 d-flex justify-content-center">
                    <div id="register-captcha-container"></div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/account-ui.js?v=20260727-1"></script>
    <script src="/vendor/public/captcha/BehaviorAuth.js?v=20260728-1"></script>
    <script>
        let countdown = 0;
        let countdownInterval;
        let sendCodeCaptcha = null;
        let registerCaptcha = null;
        let captchaPurpose = ''; // 'send_code' or 'register'
        
        // 用户名实时检测
        document.addEventListener('DOMContentLoaded', function() {
            const usernameInput = document.getElementById('username');
            const usernameFeedback = document.getElementById('username-feedback');
            let usernameCheckTimer;
            let isUsernameValid = false;
            
            if (usernameInput && usernameFeedback) {
                usernameInput.addEventListener('input', function() {
                    clearTimeout(usernameCheckTimer);
                    const usernameValue = this.value.trim();
                    
                    usernameFeedback.textContent = '';
                    usernameFeedback.className = 'form-text mt-2';
                    isUsernameValid = false;
                    
                    if (usernameValue.length === 0) {
                        updateSendCodeButton();
                        return;
                    }
                    
                    if (usernameValue.length < 3) {
                        usernameFeedback.textContent = '用户名长度至少3个字符';
                        usernameFeedback.className = 'form-text mt-2 text-danger';
                        updateSendCodeButton();
                        return;
                    }
                    
                    if (usernameValue.length > 20) {
                        usernameFeedback.textContent = '用户名长度最多20个字符';
                        usernameFeedback.className = 'form-text mt-2 text-danger';
                        updateSendCodeButton();
                        return;
                    }
                    
                    usernameFeedback.textContent = '检测中...';
                    usernameFeedback.className = 'form-text mt-2 text-muted';
                    
                    usernameCheckTimer = setTimeout(() => {
                        const formData = new FormData();
                        formData.append('action', 'check_username');
                        formData.append('username', usernameValue);
                        
                        fetch('register.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.valid) {
                                usernameFeedback.textContent = '✓ ' + data.message;
                                usernameFeedback.className = 'form-text mt-2 text-success';
                                isUsernameValid = true;
                            } else {
                                usernameFeedback.textContent = '✗ ' + data.message;
                                usernameFeedback.className = 'form-text mt-2 text-danger';
                                isUsernameValid = false;
                            }
                            updateSendCodeButton();
                        })
                        .catch(error => {
                            usernameFeedback.textContent = '检测失败，请稍后再试';
                            usernameFeedback.className = 'form-text mt-2 text-warning';
                            isUsernameValid = false;
                            updateSendCodeButton();
                        });
                    }, 500);
                });
            }
            
            // 邮箱实时检测
            const emailInput = document.getElementById('email');
            const emailFeedback = document.getElementById('email-feedback');
            let emailCheckTimer;
            let isEmailValid = false;
            
            if (emailInput && emailFeedback) {
                emailInput.addEventListener('input', function() {
                    clearTimeout(emailCheckTimer);
                    const emailValue = this.value.trim();
                    
                    emailFeedback.textContent = '';
                    emailFeedback.className = 'form-text mt-2';
                    isEmailValid = false;
                    
                    if (emailValue.length === 0) {
                        updateSendCodeButton();
                        return;
                    }
                    
                    // 基本邮箱格式验证
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(emailValue)) {
                        emailFeedback.textContent = '请输入有效的邮箱地址';
                        emailFeedback.className = 'form-text mt-2 text-danger';
                        updateSendCodeButton();
                        return;
                    }
                    
                    // 域名验证
                    if (!allowedEmailDomain(emailValue)) {
                        emailFeedback.textContent = '请使用常用邮箱地址';
                        emailFeedback.className = 'form-text mt-2 text-danger';
                        updateSendCodeButton();
                        return;
                    }
                    
                    emailFeedback.textContent = '检测中...';
                    emailFeedback.className = 'form-text mt-2 text-muted';
                    
                    emailCheckTimer = setTimeout(() => {
                        const formData = new FormData();
                        formData.append('action', 'check_email');
                        formData.append('email', emailValue);
                        
                        fetch('register.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.valid) {
                                emailFeedback.textContent = '✓ ' + data.message;
                                emailFeedback.className = 'form-text mt-2 text-success';
                                isEmailValid = true;
                            } else {
                                emailFeedback.textContent = '✗ ' + data.message;
                                emailFeedback.className = 'form-text mt-2 text-danger';
                                isEmailValid = false;
                            }
                            updateSendCodeButton();
                        })
                        .catch(error => {
                            emailFeedback.textContent = '检测失败，请稍后再试';
                            emailFeedback.className = 'form-text mt-2 text-warning';
                            isEmailValid = false;
                            updateSendCodeButton();
                        });
                    }, 500);
                });
            }
            
            // 更新发送验证码按钮状态
            function updateSendCodeButton() {
                const sendCodeBtn = document.getElementById('sendCodeBtn');
                if (sendCodeBtn) {
                    if (isUsernameValid && isEmailValid) {
                        sendCodeBtn.disabled = false;
                    } else {
                        sendCodeBtn.disabled = true;
                    }
                }
            }
            
            // 初始化时禁用发送验证码按钮
            updateSendCodeButton();
        });
        
        // 发送验证码
        function sendVerificationCode() {
            const email = document.getElementById('email').value;

            if (!email) {
                showMessage('请先输入邮箱地址', 'danger');
                return;
            }

            if (!validateEmail(email)) {
                showMessage('请输入有效的邮箱地址', 'danger');
                return;
            }
            if (!allowedEmailDomain(email)) {
                showMessage('请使用常用邮箱地址', 'danger');
                return;
            }

            if (countdown > 0) {
                return;
            }

            // 弹出人机验证
            captchaPurpose = 'send_code';
            showCaptchaModal();
        }

        // 显示人机验证弹窗
        function showCaptchaModal() {
            const captchaModal = new bootstrap.Modal(document.getElementById('captchaModal'));
            captchaModal.show();

            if (!sendCodeCaptcha) {
                sendCodeCaptcha = new BehaviorAuth('register-captcha-container', '/vendor/public/captcha/AuthApi.php');
                sendCodeCaptcha.onSuccess = function(bizToken) {
                    setTimeout(() => {
                        const captchaModalEl = document.getElementById('captchaModal');
                        const captchaModalInstance = bootstrap.Modal.getInstance(captchaModalEl);
                        if (captchaModalInstance) captchaModalInstance.hide();

                        if (captchaPurpose === 'send_code') {
                            doSendCode();
                        } else if (captchaPurpose === 'register') {
                            document.getElementById('registerCaptchaToken').value = bizToken;
                            doSubmitRegister();
                        }
                    }, 500);
                };
                sendCodeCaptcha.onFail = function() {
                    document.getElementById('registerCaptchaToken').value = '';
                };
            } else {
                sendCodeCaptcha.reset();
            }
        }

        // 执行发送验证码
        function doSendCode() {
            const email = document.getElementById('email').value;
            const sendCodeBtn = document.getElementById('sendCodeBtn');

            // 禁用按钮
            sendCodeBtn.disabled = true;
            sendCodeBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 发送中...';

            const formData = new FormData();
            formData.append('action', 'send_code');
            formData.append('email', email);
            formData.append('csrf_token', document.querySelector('#registerForm input[name="csrf_token"]').value);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const alert = doc.querySelector('.alert');

                if (alert) {
                    if (alert.classList.contains('alert-success') || alert.textContent.includes('验证码已发送')) {
                        showMessage(alert.textContent, 'success');
                        startCountdown();
                    } else {
                        showMessage(alert.textContent, 'danger');
                        sendCodeBtn.disabled = false;
                        sendCodeBtn.innerHTML = '<span id="sendCodeText">发送验证码</span>';
                    }
                } else {
                    showMessage('验证码发送成功', 'success');
                    startCountdown();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('发送失败，请重试', 'danger');
                sendCodeBtn.disabled = false;
                sendCodeBtn.innerHTML = '<span id="sendCodeText">发送验证码</span>';
            });
        }
        
        // 开始倒计时
        function startCountdown() {
            countdown = 60;
            const sendCodeBtn = document.getElementById('sendCodeBtn');
            
            // 先恢复按钮结构（含sendCodeText span），防止doSendCode已覆盖innerHTML导致元素丢失
            if (!document.getElementById('sendCodeText')) {
                sendCodeBtn.innerHTML = '<span id="sendCodeText"></span>';
            }
            const sendCodeText = document.getElementById('sendCodeText');
            sendCodeText.textContent = `${countdown}秒后重发`;
            
            countdownInterval = setInterval(() => {
                if (countdown > 0) {
                    countdown--;
                    sendCodeText.textContent = `${countdown}秒后重发`;
                } else {
                    clearInterval(countdownInterval);
                    sendCodeText.textContent = '重新发送';
                    sendCodeBtn.disabled = false;
                }
            }, 1000);
        }
        
        // 提交注册（先弹出人机验证）
        function submitRegister() {
            const form = document.getElementById('registerForm');
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const email = document.getElementById('email').value;
            const verificationCode = document.getElementById('verification_code').value;
            const username = document.getElementById('username').value;
            
            // 表单验证
            if (!username || !password || !email || !verificationCode) {
                showMessage('请填写所有必填字段', 'danger');
                return;
            }
            
            if (!validateEmail(email)) {
                showMessage('请输入有效的邮箱地址', 'danger');
                return;
            }
            
            if (!allowedEmailDomain(email)) {
                showMessage('请使用常用邮箱地址', 'danger');
                return;
            }
            
            if (password !== confirmPassword) {
                showMessage('两次输入的密码不一致', 'danger');
                return;
            }
            
            if (password.length < 6) {
                showMessage('密码长度至少6位', 'danger');
                return;
            }
            
            if (username.length < 3 || username.length > 20) {
                showMessage('用户名长度应在3-20个字符之间', 'danger');
                return;
            }
            
            if (verificationCode.length !== 6) {
                showMessage('请输入6位验证码', 'danger');
                return;
            }

            // 弹出人机验证
            captchaPurpose = 'register';
            showCaptchaModal();
        }

        // 执行提交注册
        function doSubmitRegister() {
            const form = document.getElementById('registerForm');
            const formData = new FormData(form);
            formData.append('action', 'register');
            
            // 禁用提交按钮
            const submitBtn = form.querySelector('button[type="button"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> 注册中...';
            
            // 获取重定向 URL
            const redirectUrlInput = form.querySelector('input[name="redirect_url"]');
            let loginUrl = '/vendor/login.php';
            if (redirectUrlInput && redirectUrlInput.value && redirectUrlInput.value !== '/') {
                loginUrl += '?redirect_url=' + encodeURIComponent(redirectUrlInput.value);
            }
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                // 解析响应HTML
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const alert = doc.querySelector('.alert');
                
                if (alert) {
                    if (alert.classList.contains('alert-success')) {
                        // 显示成功信息
                        showMessage(alert.textContent, 'success');
                        // 3秒后跳转
                        setTimeout(() => {
                            window.location.href = loginUrl;
                        }, 3000);
                    } else {
                        // 显示错误信息
                        showMessage(alert.textContent, 'danger');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }
                } else {
                     if (html.includes('注册成功')) {
                         showMessage('注册成功，正在跳转...', 'success');
                        setTimeout(() => {
                            window.location.href = loginUrl;
                        }, 3000);
                     } else {
                        showMessage('注册成功', 'success');
                        setTimeout(() => {
                            window.location.href = loginUrl;
                        }, 3000);
                     }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('注册失败，请重试', 'danger');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        }
        
        // 验证邮箱格式
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
        function allowedEmailDomain(email) {
            const parts = email.toLowerCase().split('@');
            if (parts.length !== 2) return false;
            const domain = parts[1];
            // 从数据库读取的允许域名列表（与PHP端isAllowedEmailDomain保持一致）
            const allowed = <?= json_encode($allowedDomains) ?>;
            if (allowed.indexOf(domain) >= 0) return true;
            for (let i = 0; i < allowed.length; i++) {
                const d = allowed[i];
                if (domain.endsWith('.' + d)) return true;
            }
            return false;
        }
        
        // 显示消息
        function showMessage(message, type) {
            // 移除现有的alert
             const dynamicAlerts = document.querySelectorAll('.alert-dynamic');
             dynamicAlerts.forEach(el => el.remove());
            
            // 创建新的alert
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show alert-dynamic mb-3`;
            alertDiv.innerHTML = `
                <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill'}"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            // 插入到表单前面
            const form = document.getElementById('registerForm');
            form.parentNode.insertBefore(alertDiv, form);
            
            // 5秒后自动消失
            setTimeout(() => {
                if (alertDiv.parentNode) {
                     const bsAlert = new bootstrap.Alert(alertDiv);
                     bsAlert.close();
                }
            }, 5000);
        }
        
        // 按Enter键触发相应操作
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const activeElement = document.activeElement;
                if (activeElement && activeElement.name === 'verification_code') {
                    submitRegister();
                }
            }
        });
    </script>
</body>
</html>
