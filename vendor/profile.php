<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';

// 记录访问
recordVisit($_SERVER['REQUEST_URI']);

// 引入注册时使用的邮箱域名验证函数
if (!function_exists('isAllowedEmailDomain')) {
    function isAllowedEmailDomain($email) {
        $pos = strrpos($email, '@');
        if ($pos === false) return false;
        $domain = strtolower(substr($email, $pos + 1));
        $allowed = [
            'qq.com','vip.qq.com','foxmail.com','163.com','126.com','yeah.net','sina.com','sina.cn',
            'sohu.com','139.com','aliyun.com','gmail.com','outlook.com','hotmail.com','live.com',
            'yahoo.com','yahoo.co.jp','icloud.com','proton.me','protonmail.com','mail.com','gmx.com','gmx.de'
        ];
        if (in_array($domain, $allowed, true)) return true;
        foreach ($allowed as $d) {
            $suffix = '.' . $d;
            if (strlen($domain) > strlen($suffix) && substr($domain, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }
}

// 引入注册时使用的用户名验证函数
if (!function_exists('isValidUsername')) {
    function isValidUsername($username) {
        // 用户名长度检查
        if (strlen($username) < 3 || strlen($username) > 20) {
            return ['valid' => false, 'message' => '用户名长度应在3-20个字符之间'];
        }
        
        // 用户名格式检查：只允许字母、数字、下划线、中文
        if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_]+$/u', $username)) {
            return ['valid' => false, 'message' => '用户名只能包含中文、字母、数字和下划线'];
        }
        
        // 用户名不能以数字开头
        if (preg_match('/^\d/', $username)) {
            return ['valid' => false, 'message' => '用户名不能以数字开头'];
        }
        
        // 用户名不能以特殊字符开头或结尾
        if (preg_match('/^_/', $username) || preg_match('/_$/', $username)) {
            return ['valid' => false, 'message' => '用户名不能以下划线开头或结尾'];
        }
        
        // 检查用户名是否包含连续的下划线
        if (strpos($username, '__') !== false) {
            return ['valid' => false, 'message' => '用户名不能包含连续的下划线'];
        }
        
        // 禁止使用的敏感词
        $forbidden_words = ['admin', '管理员', 'root', 'system', '系统', 'test', '测试', 'guest', '游客', 'null', 'undefined'];
        if (in_array(strtolower($username), array_map('strtolower', $forbidden_words))) {
            return ['valid' => false, 'message' => '该用户名已被保留，请使用其他名称'];
        }
        
        return ['valid' => true, 'message' => ''];
    }
}

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
$activeDevices = getUserActiveDevices($_SESSION['user_id']);

// 获取用户信息
$stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    // 用户不存在，可能是被删除了
    session_destroy();
    setcookie('device_token', '', time() - 3600, '/');
    setcookie('nova_token', '', time() - 3600, '/');
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
            echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面后重试']);
            exit;
        }
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => '请输入有效的邮箱地址']);
            exit;
        }

        if (!isAllowedEmailDomain($email)) {
            echo json_encode(['success' => false, 'message' => '请使用常用邮箱地址']);
            exit;
        }

        // 检查邮箱是否被其他用户占用
        $check_stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
        $check_stmt->execute([$email]);
        if ($check_stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => '该邮箱已被其他账号使用']);
            exit;
        }

        try {
            // 删除旧验证码
            $stmt = $db->prepare("DELETE FROM email_verification WHERE email = ? AND purpose = 'change_email'");
            $stmt->execute([$email]);
            
            // 生成验证码
            $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', time() + 600);
            
            // 存储验证码
            $stmt = $db->prepare("INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'change_email', ?)");
            if ($stmt->execute([$email, $code, $expires_at])) {
                if (sendVerificationEmail($email, $code)) {
                    // 生产环境应去除验证码显示
                    echo json_encode(['success' => true, 'message' => "验证码已发送到 {$email}"]);
                } else {
                    echo json_encode(['success' => false, 'message' => '邮件发送失败']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => '验证码生成失败']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => '系统错误: ' . $e->getMessage()]);
        }
        exit;
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
        } elseif (strlen($new_password) < 6) {
            $error_msg = '新密码长度至少6位';
        } else {
            // 更新密码
            $new_password_hash = hashPassword($new_password);
            $update_stmt = $db->prepare("UPDATE admins SET password = ? WHERE id = ?");
            if ($update_stmt->execute([$new_password_hash, $user['id']])) {
                $success_msg = '密码修改成功，所有其他设备已下线，下次登录请使用新密码';
                invalidateAllUserDevices($user['id']);
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
                // 验证验证码
                $stmt = $db->prepare("SELECT id FROM email_verification WHERE email = ? AND code = ? AND purpose = 'change_email' AND is_used = 0 AND expires_at > NOW()");
                $stmt->execute([$email, $verification_code]);
                if (!$stmt->fetch()) {
                    $error_msg = '验证码无效或已过期';
                } else {
                    // 标记验证码已使用
                    $stmt = $db->prepare("UPDATE email_verification SET is_used = 1, used_at = NOW() WHERE email = ? AND code = ? AND purpose = 'change_email'");
                    $stmt->execute([$email, $verification_code]);
                    
                    // 更新邮箱
                    $update_stmt = $db->prepare("UPDATE admins SET email = ? WHERE id = ?");
                    if ($update_stmt->execute([$email, $user['id']])) {
                        $success_msg = '邮箱修改成功';
                        $user['email'] = $email; // 更新当前变量
                        $_SESSION['user_email'] = $email; // 更新 Session
                    } else {
                        $error_msg = '更新失败，请重试';
                    }
                }
            }
        }
    }
    }

    // 下线设备 AJAX
    if (isset($_POST['action']) && $_POST['action'] === 'remove_device') {
        header('Content-Type: application/json');
        if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
            echo json_encode(['success' => false, 'message' => '安全验证失败']);
            exit;
        }
        $sessionId = intval($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) {
            echo json_encode(['success' => false, 'message' => '无效的设备ID']);
            exit;
        }
        if (removeUserDevice($sessionId, $user['id'])) {
            echo json_encode(['success' => true, 'message' => '设备已下线']);
        } else {
            echo json_encode(['success' => false, 'message' => '操作失败或无法下线当前设备']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($config['website_name']) ?> - 个人中心</title>
    
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
</head>
<body>
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="/">
                <i class="bi bi-box-seam text-primary"></i>
                <span class="fw-bold"><?= e($config['website_name']) ?></span>
            </a>
            <div class="ms-auto">
                <button onclick="goBackOrHome()" class="btn btn-outline-primary rounded-pill px-4">
                    <i class="bi bi-arrow-left me-1"></i> 返回
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

        <div class="row g-4">
            <!-- 左侧侧边栏 -->
            <div class="col-lg-4 col-xl-3 profile-sidebar">
                <div class="card shadow-sm mb-4">
                    <div class="profile-cover"></div>
                    <div class="card-body pt-0">
                        <div class="profile-avatar-wrapper">
                            <div class="profile-avatar">
                                <i class="bi bi-person-fill"></i>
                            </div>
                        </div>
                        <div class="text-center mt-3 mb-4">
                            <h4 class="fw-bold mb-1"><?= e($user['username']) ?></h4>
                            <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2">
                                <?= $user['role'] === 'admin' ? '管理员' : '普通用户' ?>
                            </span>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <div class="info-item">
                                <div class="info-label">注册时间</div>
                                <div class="info-value">
                                    <i class="bi bi-calendar3 me-2 text-primary"></i>
                                    <?= isset($user['created_at']) ? date('Y-m-d', strtotime($user['created_at'])) : '未知' ?>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4 text-muted opacity-25">
                        
                        <div class="nav flex-column nav-pills">
                            <a href="#security" class="nav-link active">
                                <i class="bi bi-shield-check"></i> 安全设置
                            </a>
                            <a href="#devices" class="nav-link">
                                <i class="bi bi-laptop"></i> 设备管理
                            </a>
                            <a href="/vendor/login.php?logout=1" class="nav-link text-danger mt-2">
                                <i class="bi bi-box-arrow-right"></i> 退出登录
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 设备管理卡片 -->
                <div class="card settings-card bg-white mt-4" id="devices">
                    <div class="settings-header">
                        <div class="settings-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                            <i class="bi bi-laptop"></i>
                        </div>
                        <h5 class="fw-bold mb-0">设备管理</h5>
                        <span class="badge bg-secondary bg-opacity-10 text-secondary ms-auto">最多 <?= $maxDevices ?> 台</span>
                    </div>
                    <div class="card-body p-4">
                        <?php if (empty($activeDevices)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-laptop fs-1 d-block mb-3 opacity-25"></i>
                            <p>暂无在线设备</p>
                        </div>
                        <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($activeDevices as $i => $device): ?>
                            <div class="list-group-item px-0 border-0 <?= $i > 0 ? 'border-top pt-3 mt-3' : '' ?>">
                                <div class="d-flex align-items-start justify-content-between">
                                    <div class="d-flex align-items-start">
                                        <div class="bg-primary bg-opacity-10 p-2 rounded-circle me-3 text-primary">
                                            <i class="bi bi-<?= strpos($device['device_name'], 'Windows') !== false ? 'display' : (strpos($device['device_name'], 'iPhone') !== false || strpos($device['device_name'], 'Android') !== false || strpos($device['device_name'], 'iPad') !== false ? 'phone' : 'laptop') ?> fs-5"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark mb-1">
                                                <?= htmlspecialchars($device['device_name']) ?>
                                                <?php if (!empty($device['is_current'])): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success ms-1" style="font-size:0.7rem;">当前设备</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small">
                                                <div><i class="bi bi-globe me-1"></i>IP: <?= htmlspecialchars($device['ip_address']) ?></div>
                                                <div><i class="bi bi-clock me-1"></i>最后活跃: <?= getTimeAgo($device['last_active_at']) ?></div>
                                                <div><i class="bi bi-box-arrow-in-right me-1"></i>登录方式: <?= $device['login_method'] === 'auto' ? '记住我' : '密码登录' ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (empty($device['is_current'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger ms-3 mt-1" onclick="removeDevice(<?= $device['id'] ?>, this)">
                                        <i class="bi bi-box-arrow-right me-1"></i>下线
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="alert alert-light border-0 mt-3 mb-0 rounded-3">
                            <i class="bi bi-info-circle me-2 text-primary"></i>
                            <small class="text-muted">登录新设备时，超出限制将自动下线最久未活跃的设备。修改密码会使所有设备下线。</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 右侧内容区 -->
            <div class="col-lg-8 col-xl-9">
                <div class="card settings-card bg-white" id="security">
                    <div class="settings-header">
                        <div class="settings-icon">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                        <h5 class="fw-bold mb-0">账户安全设置</h5>
                    </div>
                    <div class="card-body p-4">
                        <!-- 修改用户名 -->
                        <div class="mb-5">
                            <div class="d-flex align-items-center mb-4">
                                <div class="bg-primary bg-opacity-10 p-2 rounded-circle me-3 text-primary">
                                    <i class="bi bi-person fs-5"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1">基本信息</h6>
                                    <p class="text-muted small mb-0">设置一个独特的昵称</p>
                                </div>
                            </div>

                            <form method="POST" class="row g-3">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_username">
                                <div class="col-md-8">
                                    <div class="form-floating">
                                        <input type="text" name="username" id="username" class="form-control" placeholder="新用户名" value="<?= e($user['username']) ?>" required minlength="2" maxlength="20">
                                        <label for="username">用户名</label>
                                    </div>
                                </div>
                                <div class="col-md-4 d-flex align-items-center">
                                    <button type="submit" class="btn btn-action w-100 h-100" style="min-height: 58px;">
                                        <i class="bi bi-check2-circle me-2"></i>保存修改
                                    </button>
                                </div>
                            </form>
                        </div>

                        <hr class="text-muted opacity-10 my-4">

                        <!-- 修改邮箱 -->
                        <div class="mb-5">
                            <div class="d-flex align-items-center mb-4">
                                <div class="bg-primary bg-opacity-10 p-2 rounded-circle me-3 text-primary">
                                    <i class="bi bi-envelope fs-5"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1">绑定邮箱</h6>
                                    <p class="text-muted small mb-0">当前绑定：<?= e($user['email']) ?></p>
                                </div>
                            </div>

                            <form method="POST" id="emailForm" class="row g-3">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_email">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="email" name="email" id="email" class="form-control" placeholder="新邮箱地址" required>
                                        <label for="email">新邮箱地址</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="input-group h-100" id="verificationCodeGroup" style="display:none;">
                                        <div class="form-floating flex-grow-1">
                                            <input type="text" name="verification_code" id="verification_code" class="form-control" placeholder="验证码" maxlength="6">
                                            <label for="verification_code">验证码</label>
                                        </div>
                                        <button class="btn btn-outline-primary px-4" type="button" id="sendCodeBtn" onclick="sendVerificationCode()" style="display:none;">
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
                        </div>

                        <hr class="text-muted opacity-10 my-4">

                        <!-- 修改密码 -->
                        <div>
                            <div class="d-flex align-items-center mb-4">
                                <div class="bg-primary bg-opacity-10 p-2 rounded-circle me-3 text-primary">
                                    <i class="bi bi-key fs-5"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1">登录密码</h6>
                                    <p class="text-muted small mb-0">建议定期更换密码以保障账户安全</p>
                                </div>
                            </div>

                            <form method="POST" class="row g-3">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_password">
                                <div class="col-md-4">
                                    <div class="form-floating">
                                        <input type="password" name="old_password" id="old_password" class="form-control" placeholder="当前密码" required>
                                        <label for="old_password">当前密码</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-floating">
                                        <input type="password" name="new_password" id="new_password" class="form-control" placeholder="新密码" required minlength="6">
                                        <label for="new_password">新密码 (至少6位)</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-floating">
                                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="确认新密码" required minlength="6">
                                        <label for="confirm_password">确认新密码</label>
                                    </div>
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-action">
                                        <i class="bi bi-check2-circle me-2"></i>修改密码
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- 设备管理卡片 -->
                <div class="card settings-card bg-white mt-4" id="devices">
                    <div class="settings-header">
                        <div class="settings-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                            <i class="bi bi-laptop"></i>
                        </div>
                        <h5 class="fw-bold mb-0">设备管理</h5>
                        <span class="badge bg-secondary bg-opacity-10 text-secondary ms-auto">最多 <?= $maxDevices ?> 台</span>
                    </div>
                    <div class="card-body p-4">
                        <?php if (empty($activeDevices)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-laptop fs-1 d-block mb-3 opacity-25"></i>
                            <p>暂无在线设备</p>
                        </div>
                        <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($activeDevices as $i => $device): ?>
                            <div class="list-group-item px-0 border-0 <?= $i > 0 ? 'border-top pt-3 mt-3' : '' ?>">
                                <div class="d-flex align-items-start justify-content-between">
                                    <div class="d-flex align-items-start">
                                        <div class="bg-primary bg-opacity-10 p-2 rounded-circle me-3 text-primary">
                                            <i class="bi bi-<?= strpos($device['device_name'], 'Windows') !== false ? 'display' : (strpos($device['device_name'], 'iPhone') !== false || strpos($device['device_name'], 'Android') !== false || strpos($device['device_name'], 'iPad') !== false ? 'phone' : 'laptop') ?> fs-5"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark mb-1">
                                                <?= htmlspecialchars($device['device_name']) ?>
                                                <?php if (!empty($device['is_current'])): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success ms-1" style="font-size:0.7rem;">当前设备</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small">
                                                <div><i class="bi bi-globe me-1"></i>IP: <?= htmlspecialchars($device['ip_address']) ?></div>
                                                <div><i class="bi bi-clock me-1"></i>最后活跃: <?= getTimeAgo($device['last_active_at']) ?></div>
                                                <div><i class="bi bi-box-arrow-in-right me-1"></i>登录方式: <?= $device['login_method'] === 'auto' ? '记住我' : '密码登录' ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (empty($device['is_current'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger ms-3 mt-1" onclick="removeDevice(<?= $device['id'] ?>, this)">
                                        <i class="bi bi-box-arrow-right me-1"></i>下线
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="alert alert-light border-0 mt-3 mb-0 rounded-3">
                            <i class="bi bi-info-circle me-2 text-primary"></i>
                            <small class="text-muted">登录新设备时，超出限制将自动下线最久未活跃的设备。修改密码会使所有设备下线。</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/bootstrap.bundle.min.js"></script>
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
                sendCodeBtn.style.display = 'block';
                verificationCodeGroup.style.display = 'flex';
                verificationInput.setAttribute('required', 'required');
            } else {
                sendCodeBtn.style.display = 'none';
                verificationCodeGroup.style.display = 'none';
                verificationInput.removeAttribute('required');
                verificationInput.value = '';
            }
        });

        // 发送验证码
        function sendVerificationCode() {
            const email = emailInput.value;
            
            if (!email) {
                alert('请输入邮箱地址');
                return;
            }
            
            if (countdown > 0) return;

            // 禁用按钮
            sendCodeBtn.disabled = true;
            
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
                    alert(data.message);
                    startCountdown();
                } else {
                    alert(data.message);
                    sendCodeBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('发送失败，请重试');
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
                    const card = btn.closest('.list-group-item');
                    card.style.transition = 'all 0.3s';
                    card.style.opacity = '0';
                    card.style.transform = 'translateX(20px)';
                    setTimeout(() => {
                        const nextBorder = card.nextElementSibling;
                        if (nextBorder) nextBorder.remove();
                        card.remove();
                    }, 300);
                } else {
                    alert(data.message);
                }
            })
            .catch(() => alert('操作失败，请重试'));
        }
    </script>
</body>
</html>