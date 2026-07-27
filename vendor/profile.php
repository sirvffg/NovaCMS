<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';

// 记录访问
recordVisit($_SERVER['REQUEST_URI']);

// 检查是否登录
if (!isset($_SESSION['user_id'])) {
    header('Location: /vendor/login.php?redirect_url=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 确保设备管理表存在
ensureSessionTables();
$maxDevices = (int)($config['max_devices'] ?? 2);

// 获取用户信息
$stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    logoutAuthenticatedUser();
    header('Location: /vendor/login.php');
    exit;
}

$success_msg = '';
$error_msg = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 发送验证码
    if (isset($_POST['action']) && $_POST['action'] === 'send_code') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
            accountJsonResponse(['success' => false, 'message' => '安全验证失败，请刷新页面后重试'], 403);
        }
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            accountJsonResponse(['success' => false, 'message' => '请输入有效的邮箱地址'], 422);
        }

        if (!isAllowedEmailDomain($email)) {
            accountJsonResponse(['success' => false, 'message' => '该邮箱域名不在站点允许范围内'], 422);
        }

        $rateKey = 'user:' . (int)$user['id'] . ':email:' . hash('sha256', accountLowercase($email));
        $rateLimit = checkRateLimit('change_email_code', 3, 3600, $rateKey);
        if (!$rateLimit['allowed']) {
            accountJsonResponse(['success' => false, 'message' => $rateLimit['message']], 429);
        }

        // 检查邮箱是否被其他用户占用
        $check_stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
        $check_stmt->execute([$email]);
        if ($check_stmt->fetch()) {
            accountJsonResponse(['success' => false, 'message' => '该邮箱已被其他账号使用'], 409);
        }

        try {
            // 删除旧验证码
            $stmt = $db->prepare("DELETE FROM email_verification WHERE email = ? AND purpose = 'change_email'");
            $stmt->execute([$email]);
            
            // 生成验证码
            $code = generateVerificationCode();
            $expires_at = date('Y-m-d H:i:s', time() + 600);
            
            // 存储验证码
            $stmt = $db->prepare("INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'change_email', ?)");
            if ($stmt->execute([$email, $code, $expires_at])) {
                if (sendVerificationEmail($email, $code)) {
                    accountJsonResponse(['success' => true, 'message' => "验证码已发送到 {$email}"]);
                } else {
                    $db->prepare("DELETE FROM email_verification WHERE email = ? AND code = ? AND purpose = 'change_email'")
                        ->execute([$email, $code]);
                    accountJsonResponse(['success' => false, 'message' => '邮件发送失败，请稍后重试'], 502);
                }
            } else {
                accountJsonResponse(['success' => false, 'message' => '验证码生成失败'], 500);
            }
        } catch (Exception $e) {
            error_log('Send change-email code error: ' . $e->getMessage());
            accountJsonResponse(['success' => false, 'message' => '系统暂时不可用，请稍后重试'], 500);
        }
    }

    // 修改密码
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
            $error_msg = '安全验证失败，请刷新页面后重试';
        } else {
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($old_password) || empty($new_password) || empty($confirm_password)) {
            $error_msg = '请填写所有密码字段';
        } elseif (!verifyPassword($old_password, $user['password'], $db, $user['id'])) {
            $error_msg = '当前密码错误';
        } elseif ($new_password !== $confirm_password) {
            $error_msg = '两次输入的新密码不一致';
        } elseif (accountTextLength($new_password) < 6) {
            $error_msg = '新密码长度至少6位';
        } else {
            // 更新密码
            $new_password_hash = hashPassword($new_password);
            $update_stmt = $db->prepare("UPDATE admins SET password = ? WHERE id = ?");
            if ($update_stmt->execute([$new_password_hash, $user['id']])) {
                $success_msg = '密码修改成功，所有其他设备已下线，下次登录请使用新密码';
                invalidateOtherUserDevices($user['id']);
            } else {
                $error_msg = '密码修改失败，请重试';
            }
        }
        }
    }
    
    // 修改用户名
    if (isset($_POST['action']) && $_POST['action'] === 'change_username') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
            $error_msg = '安全验证失败，请刷新页面后重试';
        } else {
        $new_username = trim($_POST['username'] ?? '');
        
        // 使用 isValidUsername 函数检查用户名
        $validation = isValidUsername($new_username);
        
        if (!$validation['valid']) {
            $error_msg = $validation['message'];
        } elseif ($new_username === $user['username']) {
            $error_msg = '新用户名不能与当前用户名相同';
        } else {
            // 检查用户名是否重复
            $check_stmt = $db->prepare("SELECT id FROM admins WHERE username = ?");
            $check_stmt->execute([$new_username]);
            if ($check_stmt->fetch()) {
                $error_msg = '该用户名已被使用，请换一个';
            } else {
                // 更新用户名
                $update_stmt = $db->prepare("UPDATE admins SET username = ? WHERE id = ?");
                if ($update_stmt->execute([$new_username, $user['id']])) {
                    $success_msg = '用户名修改成功';
                    $user['username'] = $new_username; // 更新当前变量
                    $_SESSION['user_username'] = $new_username; // 更新 Session
                } else {
                    $error_msg = '用户名修改失败，请重试';
                }
            }
        }
        }
    }
    
    // 修改邮箱
    if (isset($_POST['action']) && $_POST['action'] === 'change_email') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
            $error_msg = '安全验证失败，请刷新页面后重试';
        } else {
        $email = trim($_POST['email'] ?? '');
        $verification_code = trim($_POST['verification_code'] ?? '');
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = '请输入有效的邮箱地址';
        } elseif (!isAllowedEmailDomain($email)) {
            $error_msg = '请使用常用邮箱地址';
        } elseif ($email === $user['email']) {
            $error_msg = '新邮箱不能与当前邮箱相同';
        } elseif (empty($verification_code)) {
            $error_msg = '请输入验证码';
        } else {
            // 检查邮箱是否被其他用户占用
            $check_stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
            $check_stmt->execute([$email]);
            if ($check_stmt->fetch()) {
                $error_msg = '该邮箱已被其他账号使用';
            } else {
                try {
                    $db->beginTransaction();
                    $stmt = $db->prepare("SELECT id FROM email_verification
                        WHERE email = ? AND code = ? AND purpose = 'change_email'
                        AND is_used = 0 AND expires_at > NOW() FOR UPDATE");
                    $stmt->execute([$email, $verification_code]);
                    $verificationId = $stmt->fetchColumn();
                    if (!$verificationId) {
                        $db->rollBack();
                        $error_msg = '验证码无效或已过期';
                    } else {
                        $updateCode = $db->prepare("UPDATE email_verification SET is_used = 1, used_at = NOW()
                            WHERE id = ? AND is_used = 0");
                        $updateCode->execute([$verificationId]);
                        $updateEmail = $db->prepare("UPDATE admins SET email = ? WHERE id = ?");
                        $updateEmail->execute([$email, $user['id']]);
                        $db->commit();

                        $success_msg = '邮箱修改成功';
                        $user['email'] = $email;
                        $_SESSION['user_email'] = $email;
                    }
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('Change email transaction error: ' . $e->getMessage());
                    $error_msg = '邮箱更新失败，请稍后重试';
                }
            }
        }
    }
    }

    // 下线设备 AJAX
    if (isset($_POST['action']) && $_POST['action'] === 'remove_device') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
            accountJsonResponse(['success' => false, 'message' => '安全验证失败'], 403);
        }
        $sessionId = intval($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) {
            accountJsonResponse(['success' => false, 'message' => '无效的设备ID'], 422);
        }
        if (removeUserDevice($sessionId, $user['id'])) {
            accountJsonResponse(['success' => true, 'message' => '设备已下线']);
        } else {
            accountJsonResponse(['success' => false, 'message' => '操作失败或无法下线当前设备'], 409);
        }
    }
}

$activeDevices = getUserActiveDevices($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($config['website_name']) ?> - 个人中心</title>
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
    <link href="/assets/css/style.css" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #36d1dc 0%, #5b86e5 100%);
            --glass-bg: rgba(255, 255, 255, 0.95);
        }
        
        body {
            background-color: #f3f4f6;
            font-family: 'HarmonyOS Sans', system-ui, -apple-system, sans-serif;
        }

        .navbar {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }

        .main-container {
            padding-top: 100px;
            padding-bottom: 40px;
        }

        /* 侧边栏样式 */
        .profile-sidebar .card {
            border: none;
            border-radius: 16px;
            overflow: hidden;
        }

        .profile-cover {
            height: 120px;
            background: var(--primary-gradient);
            position: relative;
        }

        .profile-avatar-wrapper {
            position: relative;
            margin-top: -60px;
            text-align: center;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: #fff;
            padding: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: #5b86e5;
        }

        .nav-pills .nav-link {
            color: #4b5563;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-pills .nav-link:hover {
            background-color: #f3f4f6;
            color: #2563eb;
            transform: translateX(5px);
        }

        .nav-pills .nav-link.active {
            background: var(--primary-gradient);
            color: #fff;
            box-shadow: 0 4px 12px rgba(91, 134, 229, 0.3);
        }

        .nav-pills .nav-link.text-danger:hover {
            background-color: #fef2f2;
            color: #dc2626;
        }

        /* 内容区样式 */
        .settings-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .settings-header {
            padding: 24px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .settings-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(91, 134, 229, 0.1);
            color: #5b86e5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .form-floating > .form-control:focus ~ label,
        .form-floating > .form-control:not(:placeholder-shown) ~ label {
            color: #5b86e5;
            transform: scale(0.85) translateY(-0.5rem) translateX(0.15rem);
        }

        .form-control:focus {
            border-color: #5b86e5;
            box-shadow: 0 0 0 4px rgba(91, 134, 229, 0.1);
        }

        .btn-action {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            background: var(--primary-gradient);
            border: none;
            color: white;
            transition: all 0.3s ease;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(91, 134, 229, 0.3);
            color: white;
        }

        .info-item {
            padding: 16px;
            background: #f8f9fa;
            border-radius: 12px;
            margin-bottom: 16px;
        }

        .info-label {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .info-value {
            font-weight: 500;
            color: #111827;
        }

        @media (max-width: 768px) {
            .profile-sidebar {
                margin-bottom: 24px;
            }
        }
    </style>
    <link href="/assets/css/account.css?v=20260728-1" rel="stylesheet">
</head>
<body class="account-profile-page">
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="/">
                <span class="account-brand-mark"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                <span class="fw-bold"><?= e($config['website_name']) ?></span>
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <button type="button" class="account-icon-button" data-account-theme-toggle aria-label="切换显示模式">
                    <i class="bi bi-moon-stars" aria-hidden="true"></i>
                </button>
                <button type="button" onclick="goBackOrHome()" class="btn btn-outline-primary rounded-pill px-3 px-md-4">
                    <i class="bi bi-arrow-left me-md-1" aria-hidden="true"></i><span class="d-none d-md-inline">返回</span>
                </button>
            </div>
        </div>
    </nav>

    <div class="container main-container">
        <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm border-0 bg-success bg-opacity-10 text-success mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-check-circle-fill me-2 fs-5"></i> 
                <div><?= htmlspecialchars($success_msg) ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm border-0 bg-danger bg-opacity-10 text-danger mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>
                <div><?= htmlspecialchars($error_msg) ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <section class="account-profile-hero" id="account-overview" aria-labelledby="profileGreeting">
            <div class="account-profile-hero-main">
                <div class="account-profile-identity">
                    <div class="account-avatar" aria-hidden="true"><?= e(mb_substr($user['username'], 0, 1)) ?></div>
                    <div class="min-w-0">
                        <span class="account-role-badge">
                            <i class="bi bi-<?= $user['role'] === 'admin' ? 'shield-check' : 'person-check' ?>" aria-hidden="true"></i>
                            <?= $user['role'] === 'admin' ? '管理员' : '注册用户' ?>
                        </span>
                        <h1 id="profileGreeting">你好，<?= e($user['username']) ?></h1>
                        <div class="account-profile-meta">
                            <span><i class="bi bi-envelope me-1" aria-hidden="true"></i><?= e($user['email']) ?></span>
                            <span><i class="bi bi-shield-check me-1" aria-hidden="true"></i>账户保护已启用</span>
                        </div>
                    </div>
                </div>
                <a href="/" class="btn btn-light rounded-pill px-3">
                    <i class="bi bi-house me-1" aria-hidden="true"></i>返回首页
                </a>
            </div>
            <div class="account-profile-stats" aria-label="账户概览">
                <div class="account-stat">
                    <span class="account-stat-label">账户状态</span>
                    <span class="account-stat-value"><?= !empty($user['is_banned']) ? '访问受限' : '状态正常' ?></span>
                </div>
                <div class="account-stat">
                    <span class="account-stat-label">在线设备</span>
                    <span class="account-stat-value"><?= count($activeDevices) ?> / <?= $maxDevices ?> 台</span>
                </div>
                <div class="account-stat">
                    <span class="account-stat-label">加入时间</span>
                    <span class="account-stat-value"><?= isset($user['created_at']) ? date('Y-m-d', strtotime($user['created_at'])) : '未知' ?></span>
                </div>
            </div>
        </section>

        <div class="account-profile-grid">
            <!-- 左侧侧边栏 -->
            <aside class="account-nav-card" aria-label="账户设置导航">
                <div class="account-nav-heading">账户设置</div>
                <nav class="account-section-nav">
                    <a href="#profile-info" class="account-nav-link active">
                        <i class="bi bi-person" aria-hidden="true"></i><span>账户资料</span>
                    </a>
                    <a href="#email-settings" class="account-nav-link">
                        <i class="bi bi-envelope" aria-hidden="true"></i><span>邮箱绑定</span>
                    </a>
                    <a href="#password-settings" class="account-nav-link">
                        <i class="bi bi-key" aria-hidden="true"></i><span>密码安全</span>
                    </a>
                    <a href="#devices" class="account-nav-link">
                        <i class="bi bi-laptop" aria-hidden="true"></i><span>登录设备</span>
                    </a>
                    <form method="POST" action="/vendor/login.php" class="account-nav-form">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="redirect_url" value="/vendor/login.php">
                        <button type="submit" class="account-nav-link text-danger">
                            <i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>退出登录</span>
                        </button>
                    </form>
                </nav>
            </aside>

            <!-- 右侧内容区 -->
            <div class="account-content-stack">
                <div class="card settings-card" id="security">
                    <div class="settings-header">
                        <div class="settings-icon">
                            <i class="bi bi-sliders" aria-hidden="true"></i>
                        </div>
                        <div class="settings-header-copy">
                            <h2>账户设置</h2>
                            <p>管理登录名、绑定邮箱与账户密码</p>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <!-- 修改用户名 -->
                        <section class="account-section" id="profile-info">
                            <div class="account-section-heading">
                                <div class="account-section-icon">
                                    <i class="bi bi-person" aria-hidden="true"></i>
                                </div>
                                <div>
                                    <h3>账户资料</h3>
                                    <p>用户名同时用于登录和公开显示，修改后请使用新用户名登录。</p>
                                </div>
                            </div>

                            <form method="POST" class="row g-3">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_username">
                                <div class="col-md-8">
                                    <div class="form-floating">
                                        <input type="text" name="username" id="username" class="form-control" placeholder="新用户名"
                                               value="<?= e($user['username']) ?>" autocomplete="username"
                                               required minlength="3" maxlength="20">
                                        <label for="username">用户名 / 登录名</label>
                                    </div>
                                </div>
                                <div class="col-md-4 d-flex align-items-center">
                                    <button type="submit" class="btn btn-action w-100 h-100">
                                        <i class="bi bi-check2-circle me-2"></i>保存修改
                                    </button>
                                </div>
                            </form>
                        </section>

                        <!-- 修改邮箱 -->
                        <section class="account-section" id="email-settings">
                            <div class="account-section-heading">
                                <div class="account-section-icon">
                                    <i class="bi bi-envelope" aria-hidden="true"></i>
                                </div>
                                <div>
                                    <h3>绑定邮箱</h3>
                                    <p>当前绑定：<?= e($user['email']) ?>。更换邮箱需要验证码确认。</p>
                                </div>
                            </div>

                            <form method="POST" id="emailForm" class="row g-3">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_email">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="email" name="email" id="email" class="form-control" placeholder="新邮箱地址"
                                               value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email" inputmode="email" required>
                                        <label for="email">新邮箱地址</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="input-group h-100 d-none" id="verificationCodeGroup">
                                        <div class="form-floating flex-grow-1">
                                            <input type="text" name="verification_code" id="verification_code" class="form-control"
                                                   placeholder="验证码" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
                                            <label for="verification_code">验证码</label>
                                        </div>
                                        <button class="btn btn-outline-primary px-3 d-none" type="button" id="sendCodeBtn" onclick="sendVerificationCode()">
                                            发送验证码
                                        </button>
                                    </div>
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-action">
                                        <i class="bi bi-check2-circle me-2"></i>确认更换
                                    </button>
                                </div>
                            </form>
                        </section>

                        <!-- 修改密码 -->
                        <section class="account-section" id="password-settings">
                            <div class="account-section-heading">
                                <div class="account-section-icon">
                                    <i class="bi bi-key" aria-hidden="true"></i>
                                </div>
                                <div>
                                    <h3>登录密码</h3>
                                    <p>建议使用字母、数字和符号组合；修改后其他设备会被下线。</p>
                                </div>
                            </div>

                            <form method="POST" class="row g-3" id="passwordForm">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_password">
                                <div class="col-md-4">
                                    <div class="account-input-wrap">
                                        <input type="password" name="old_password" id="old_password" class="form-control pe-5"
                                               placeholder="当前密码" autocomplete="current-password" required>
                                        <button type="button" class="account-password-toggle" data-password-toggle="old_password" aria-label="显示当前密码">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="account-input-wrap">
                                        <input type="password" name="new_password" id="new_password" class="form-control pe-5"
                                               placeholder="新密码（至少 6 位）" autocomplete="new-password"
                                               data-password-strength="newPasswordStrength" required minlength="6">
                                        <button type="button" class="account-password-toggle" data-password-toggle="new_password" aria-label="显示新密码">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div class="account-strength" id="newPasswordStrength" data-level="0" aria-label="密码强度">
                                        <span></span><span></span><span></span><span></span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="account-input-wrap">
                                        <input type="password" name="confirm_password" id="confirm_password" class="form-control pe-5"
                                               placeholder="再次输入新密码" autocomplete="new-password" required minlength="6">
                                        <button type="button" class="account-password-toggle" data-password-toggle="confirm_password" aria-label="显示确认密码">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-action">
                                        <i class="bi bi-check2-circle me-2"></i>修改密码
                                    </button>
                                </div>
                            </form>
                        </section>
                    </div>
                </div>

                <!-- 设备管理卡片 -->
                <section class="card settings-card" id="devices" aria-labelledby="devicesTitle">
                    <div class="settings-header">
                        <div class="settings-icon">
                            <i class="bi bi-laptop" aria-hidden="true"></i>
                        </div>
                        <div class="settings-header-copy">
                            <h2 id="devicesTitle">登录设备</h2>
                            <p>查看当前在线会话并下线不再使用的设备</p>
                        </div>
                        <span class="badge rounded-pill ms-auto" id="deviceCountBadge" style="color: var(--account-primary); background: var(--account-primary-soft);">
                            <?= count($activeDevices) ?> / <?= $maxDevices ?> 台
                        </span>
                    </div>
                    <div class="account-section">
                        <?php if (empty($activeDevices)): ?>
                        <div class="account-empty-state" id="deviceEmptyState">
                            <i class="bi bi-laptop" aria-hidden="true"></i>
                            <p class="mb-0">暂无在线设备</p>
                        </div>
                        <?php else: ?>
                        <div class="account-device-list" id="deviceList">
                            <?php foreach ($activeDevices as $device): ?>
                            <article class="account-device-item" data-device-id="<?= (int)$device['id'] ?>">
                                <div class="account-device-main">
                                    <div class="account-device-icon">
                                        <i class="bi bi-<?= strpos($device['device_name'], 'Windows') !== false ? 'display' : (strpos($device['device_name'], 'iPhone') !== false || strpos($device['device_name'], 'Android') !== false || strpos($device['device_name'], 'iPad') !== false ? 'phone' : 'laptop') ?>" aria-hidden="true"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="account-device-name">
                                            <span><?= htmlspecialchars($device['device_name']) ?></span>
                                            <?php if (!empty($device['is_current'])): ?>
                                            <span class="account-current-badge"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>当前设备</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="account-device-meta">
                                            <span><i class="bi bi-globe" aria-hidden="true"></i><?= htmlspecialchars($device['ip_address']) ?></span>
                                            <span><i class="bi bi-clock" aria-hidden="true"></i><?= getTimeAgo($device['last_active_at']) ?></span>
                                            <span><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i><?= $device['login_method'] === 'auto' ? '记住我' : '密码登录' ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php if (empty($device['is_current'])): ?>
                                <button type="button" class="btn btn-outline-danger account-device-remove" onclick="removeDevice(<?= (int)$device['id'] ?>, this)">
                                    <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>下线设备
                                </button>
                                <?php endif; ?>
                            </article>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="account-tip">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            <span>登录新设备时，超出限制将自动下线最久未活跃的设备。修改密码会使现有设备会话失效。</span>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/account-ui.js?v=20260727-1"></script>
    <?php
    // 时间格式化辅助函数
    function getTimeAgo($datetime) {
        if (empty($datetime)) return '未知';
        $now = time();
        $time = strtotime($datetime);
        $diff = $now - $time;
        if ($diff < 60) return '刚刚';
        if ($diff < 3600) return floor($diff / 60) . ' 分钟前';
        if ($diff < 86400) return floor($diff / 3600) . ' 小时前';
        if ($diff < 2592000) return floor($diff / 86400) . ' 天前';
        return date('Y-m-d', $time);
    }
    ?>
    <script>
        const originalEmail = "<?= e($user['email']) ?>";
        const emailInput = document.getElementById('email');
        const sendCodeBtn = document.getElementById('sendCodeBtn');
        const verificationCodeGroup = document.getElementById('verificationCodeGroup');
        const verificationInput = document.getElementById('verification_code');
        
        let countdown = 0;
        let countdownInterval;

        // 监听邮箱输入框变化
        emailInput.addEventListener('input', function() {
            if (this.value !== originalEmail && this.value !== '') {
                sendCodeBtn.classList.remove('d-none');
                verificationCodeGroup.classList.remove('d-none');
                verificationInput.setAttribute('required', 'required');
            } else {
                sendCodeBtn.classList.add('d-none');
                verificationCodeGroup.classList.add('d-none');
                verificationInput.removeAttribute('required');
                verificationInput.value = '';
            }
        });

        // 发送验证码
        function sendVerificationCode() {
            const email = emailInput.value;
            
            if (!email) {
                window.showAccountToast?.('请输入邮箱地址', 'danger');
                emailInput.focus();
                return;
            }
            
            if (countdown > 0) return;

            // 禁用按钮
            sendCodeBtn.disabled = true;
            const originalButtonText = sendCodeBtn.textContent;
            sendCodeBtn.textContent = '发送中...';
            
            // 发送请求
            const formData = new FormData();
            formData.append('action', 'send_code');
            formData.append('email', email);
            formData.append('csrf_token', '<?= generateCSRFToken() ?>');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.showAccountToast?.(data.message, 'success');
                    startCountdown();
                } else {
                    window.showAccountToast?.(data.message, 'danger');
                    sendCodeBtn.textContent = originalButtonText;
                    sendCodeBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                window.showAccountToast?.('发送失败，请重试', 'danger');
                sendCodeBtn.textContent = originalButtonText;
                sendCodeBtn.disabled = false;
            });
        }

        function startCountdown() {
            countdown = 60;
            sendCodeBtn.textContent = `${countdown}秒后重发`;
            
            countdownInterval = setInterval(() => {
                countdown--;
                if (countdown > 0) {
                    sendCodeBtn.textContent = `${countdown}秒后重发`;
                } else {
                    clearInterval(countdownInterval);
                    sendCodeBtn.textContent = '发送验证码';
                    sendCodeBtn.disabled = false;
                }
            }, 1000);
        }

        // 智能返回逻辑
        function goBackOrHome() {
            if (document.referrer && document.referrer.indexOf(window.location.hostname) !== -1) {
                history.back();
            } else {
                window.location.href = '/';
            }
        }

        // 下线设备
        function removeDevice(sessionId, btn) {
            if (!confirm('确定要下线此设备吗？')) return;
            const originalButtonHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>处理中';
            const formData = new FormData();
            formData.append('action', 'remove_device');
            formData.append('session_id', sessionId);
            formData.append('csrf_token', '<?= generateCSRFToken() ?>');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const card = btn.closest('.account-device-item');
                    const list = document.getElementById('deviceList');
                    card.style.opacity = '0';
                    card.style.transform = 'translateX(20px)';
                    setTimeout(() => {
                        card.remove();
                        const remaining = list ? list.querySelectorAll('.account-device-item').length : 0;
                        const countBadge = document.getElementById('deviceCountBadge');
                        if (countBadge) countBadge.textContent = `${remaining} / <?= $maxDevices ?> 台`;
                        if (list && remaining === 0) {
                            list.outerHTML = '<div class="account-empty-state" id="deviceEmptyState"><i class="bi bi-laptop" aria-hidden="true"></i><p class="mb-0">暂无在线设备</p></div>';
                        }
                    }, 260);
                    window.showAccountToast?.(data.message, 'success');
                } else {
                    btn.disabled = false;
                    btn.innerHTML = originalButtonHtml;
                    window.showAccountToast?.(data.message, 'danger');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = originalButtonHtml;
                window.showAccountToast?.('操作失败，请重试', 'danger');
            });
        }

        // 恢复服务端校验失败后保留的新邮箱状态
        emailInput.dispatchEvent(new Event('input'));

        // 客户端先提示两次密码不一致，服务端仍会进行最终校验
        document.getElementById('passwordForm')?.addEventListener('submit', function(event) {
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            if (newPassword.value !== confirmPassword.value) {
                event.preventDefault();
                window.showAccountToast?.('两次输入的新密码不一致', 'danger');
                confirmPassword.focus();
            }
        });

        verificationInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
        });
    </script>
</body>
</html>
