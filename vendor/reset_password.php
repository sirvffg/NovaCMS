<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';

// 记录访问
recordVisit($_SERVER['REQUEST_URI']);

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 已登录则跳转到首页
if (isset($_SESSION['user_id'])) {
    $redirect_url = safeRedirectUrl($_GET['redirect_url'] ?? '/');
    header('Location: ' . $redirect_url);
    exit;
}

$error = '';
$success = '';

// 获取邮箱参数
$email = trim($_GET['email'] ?? '');
if (empty($email)) {
    $error = '无效的请求，请重新开始';
}

// 获取重定向 URL
$redirect_url = safeRedirectUrl($_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '/');

// 处理密码重置
if (isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $error = '安全验证失败，请刷新页面后重试';
    } else {
    $email = trim($_POST['email'] ?? '');
    $verification_code = trim($_POST['verification_code'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($email) || empty($verification_code) || empty($new_password) || empty($confirm_password)) {
        $error = '请填写所有必填字段';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '请输入有效的邮箱地址';
    } elseif (strlen($new_password) < 6) {
        $error = '密码长度至少6位';
    } elseif ($new_password !== $confirm_password) {
        $error = '两次输入的密码不一致';
    } else {
        try {
            // 验证邮箱验证码
            $stmt = $db->prepare("SELECT id FROM email_verification WHERE email = ? AND code = ? AND purpose = 'reset' AND is_used = 0 AND expires_at > NOW()");
            $stmt->execute([$email, $verification_code]);
            $verification = $stmt->fetch();
            
            if (!$verification) {
                $error = '验证码无效或已过期，请重新获取';
            } else {
                // 标记验证码已使用
                $stmt = $db->prepare("UPDATE email_verification SET is_used = 1, used_at = NOW() WHERE id = ?");
                $stmt->execute([$verification['id']]);
                
                // 更新密码
                $hashedPassword = hashPassword($new_password);
                $stmt = $db->prepare("UPDATE admins SET password = ? WHERE email = ?");
                if ($stmt->execute([$hashedPassword, $email])) {
                    $success = '密码重置成功！即将跳转到登录页面...';
                    
                    // 构建跳转 URL
                    $login_url = '/vendor/login.php';
                    if ($redirect_url && $redirect_url !== '/') {
                        $login_url .= '?redirect_url=' . urlencode($redirect_url);
                    }
                    
                    // 3秒后跳转到登录页面
                    header('refresh:3;url=' . $login_url);
                } else {
                    $error = '密码重置失败，请重试';
                }
            }
        } catch (Exception $e) {
            $error = '密码重置出错: ' . $e->getMessage();
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($config['website_name']) ?> - 重置密码</title>
    
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

        .email-display {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 12px;
            border: 1px solid #e9ecef;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-color);
        }

        .email-display i {
            color: var(--primary-color);
            font-size: 1.2rem;
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
                font-size: 24px;
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
            .email-display {
                padding: 12px;
                margin-bottom: 20px;
            }
            .email-display .fw-medium {
                font-size: 14px;
            }
            .form-floating.mb-3 {
                margin-bottom: 12px !important;
            }
            .form-floating.mb-4 {
                margin-bottom: 15px !important;
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
            .alert {
                padding: 12px;
                font-size: 14px;
            }
            .text-center a {
                font-size: 14px;
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
                font-size: 20px;
            }
            .auth-main {
                padding: 20px 15px;
            }
            .auth-title {
                font-size: 20px;
            }
            .form-floating > .form-control {
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <a href="/vendor/forgot_password.php?email=<?= urlencode($email) ?><?= ($redirect_url && $redirect_url !== '/') ? '&redirect_url=' . urlencode($redirect_url) : '' ?>" class="back-link">
        <i class="bi bi-arrow-left"></i>
        <span>返回</span>
    </a>

    <div class="auth-wrapper">
        <div class="auth-sidebar">
            <div class="auth-sidebar-content">
                <div class="brand-logo">
                    <i class="bi bi-box-seam"></i>
                    <?= e($config['website_name']) ?>
                </div>
                <div class="welcome-text">
                    <h2>重置密码</h2>
                    <p>为了您的账户安全，请设置一个新的强密码。建议使用字母、数字和符号的组合。</p>
                </div>
            </div>
            <div class="auth-sidebar-content text-center mt-4">
                <small style="opacity: 0.7;">&copy; <?= date('Y') ?> <?= e($config['website_name']) ?>. All rights reserved.</small>
            </div>
        </div>

        <div class="auth-main">
            <div class="mb-4">
                <h1 class="auth-title">设置新密码</h1>
                <p class="auth-subtitle">请输入验证码并设置您的新密码</p>
            </div>

            <?php if ($success): ?>
            <div class="alert alert-success mb-4" role="alert">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($success) ?>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger mb-4" role="alert">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <div class="email-display">
                <i class="bi bi-person-circle"></i>
                <div>
                    <small class="text-muted d-block" style="font-size: 0.8rem;">重置账户</small>
                    <span class="fw-medium"><?= htmlspecialchars($email) ?></span>
                </div>
            </div>

            <form method="POST" id="resetForm">
                <?= csrfField() ?>
                <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($redirect_url) ?>">
                
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="verification_code" name="verification_code" 
                           placeholder="验证码" required maxlength="6"
                           pattern="[0-9]{6}">
                    <label for="verification_code">6位验证码</label>
                </div>

                <div class="form-floating mb-3">
                    <input type="password" class="form-control" id="newPassword" name="new_password" 
                           placeholder="新密码" required minlength="6">
                    <label for="newPassword">新密码 (至少6位)</label>
                </div>

                <div class="form-floating mb-4">
                    <input type="password" class="form-control" id="confirmPassword" name="confirm_password" 
                           placeholder="确认新密码" required minlength="6">
                    <label for="confirmPassword">确认新密码</label>
                </div>

                <button type="button" class="btn btn-primary w-100 mb-4" onclick="submitReset()">
                    重置密码
                </button>

                <div class="text-center">
                    <a href="/vendor/forgot_password.php<?= ($redirect_url && $redirect_url !== '/') ? '?redirect_url=' . urlencode($redirect_url) : '' ?>" class="text-decoration-none" style="color: var(--primary-color);">
                        <i class="bi bi-arrow-clockwise"></i> 重新发送验证码
                    </a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if (!$success): ?>
        // 提交重置
        function submitReset() {
            const form = document.getElementById('resetForm');
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const verificationCode = document.querySelector('input[name="verification_code"]').value;
            
            // 表单验证
            if (!verificationCode) {
                showMessage('请输入验证码', 'danger');
                return;
            }
            
            if (!newPassword || !confirmPassword) {
                showMessage('请输入新密码', 'danger');
                return;
            }
            
            if (verificationCode.length !== 6) {
                showMessage('请输入6位验证码', 'danger');
                return;
            }
            
            if (newPassword.length < 6) {
                showMessage('密码长度至少6位', 'danger');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                showMessage('两次输入的密码不一致', 'danger');
                return;
            }
            
            // 创建FormData提交表单
            const formData = new FormData(form);
            formData.append('action', 'reset_password');
            
            // 禁用提交按钮
            const submitBtn = form.querySelector('button[type="button"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> 重置中...';
            
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
                const alerts = doc.querySelectorAll('.alert');
                
                // 清除现有alert
                document.querySelectorAll('.alert-dynamic').forEach(a => a.remove());
                
                let foundSuccess = false;
                alerts.forEach(alert => {
                    const alertDiv = document.createElement('div');
                    alertDiv.className = alert.className + ' alert-dynamic mb-3';
                    alertDiv.innerHTML = alert.innerHTML;
                    alertDiv.querySelector('.btn-close')?.remove();
                    
                    // 插入到表单上方
                    const formDiv = document.querySelector('.email-display').parentNode;
                    formDiv.insertBefore(alertDiv, document.querySelector('.email-display'));
                    
                    if (alert.classList.contains('alert-success') || alert.textContent.includes('密码重置成功')) {
                        foundSuccess = true;
                        // 3秒后跳转
                        setTimeout(() => {
                            window.location.href = loginUrl;
                        }, 3000);
                    } else {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }
                });
                
                if (alerts.length === 0) {
                    // 如果后端没有返回alert但也没有报错，尝试检测文本内容
                    if (html.includes('密码重置成功')) {
                        showMessage('密码重置成功', 'success');
                        setTimeout(() => {
                            window.location.href = loginUrl;
                        }, 3000);
                    } else {
                        // 可能是未知错误
                        console.log("Unknown response state");
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('重置失败，请重试', 'danger');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        }
        
        // 显示消息
        function showMessage(message, type) {
            // 移除现有的动态alert
            const existingAlerts = document.querySelectorAll('.alert-dynamic');
            existingAlerts.forEach(alert => alert.remove());
            
            // 隐藏静态的 alert (如果有)
             const staticAlerts = document.querySelectorAll('.alert:not(.alert-dynamic)');
             staticAlerts.forEach(alert => alert.style.display = 'none');
            
            // 创建新的alert
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show alert-dynamic mb-3`;
            alertDiv.innerHTML = `
                <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill'}"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            // 插入到email-display前面
            const emailDisplay = document.querySelector('.email-display');
            emailDisplay.parentNode.insertBefore(alertDiv, emailDisplay);
            
            // 5秒后自动消失
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    const bsAlert = new bootstrap.Alert(alertDiv);
                    bsAlert.close();
                }
            }, 5000);
        }
        <?php endif; ?>
    </script>
</body>
</html>