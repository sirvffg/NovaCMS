<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';

// 记录访问
recordVisit($_SERVER['REQUEST_URI']);

// 获取工作室配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 已登录则跳转到首页
if (isset($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

$error = '';
$success = '';
$email_sent = '';

// 获取重定向 URL
$redirect_url = safeRedirectUrl($_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '/');

// 发送验证码
if (isset($_POST['action']) && $_POST['action'] === 'send_code') {
    $email = trim($_POST['email'] ?? '');
    
    // 蜜罐检查
    if (checkHoneypot()) {
        $success = '如果该邮箱已注册，验证码已发送';
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '请输入有效的邮箱地址';
    } else {
        try {
            // LY-011: 防止邮箱枚举 — 无论邮箱是否注册，统一返回成功提示
            // 先检查邮箱是否已注册
            $stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
            $stmt->execute([$email]);
            $emailExists = $stmt->fetch();

            if ($emailExists) {
                // 邮箱存在 — 真正发送验证码
                $stmt = $db->prepare("DELETE FROM email_verification WHERE email = ? AND purpose = 'reset'");
                $stmt->execute([$email]);

                $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expires_at = date('Y-m-d H:i:s', time() + 600);

                $stmt = $db->prepare("INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'reset', ?)");
                if ($stmt->execute([$email, $code, $expires_at])) {
                    sendVerificationEmail($email, $code);
                    $_SESSION['reset_email'] = $email;
                }
            }

            // 无论邮箱是否存在，统一返回相同提示
            $email_sent = '如果该邮箱已注册，验证码已发送';
        } catch (Exception $e) {
            // LY-011: 统一提示，不泄露具体错误信息
            $email_sent = '如果该邮箱已注册，验证码已发送';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($config['website_name']) ?> - 忘记密码</title>
    
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
            .mb-4 {
                margin-bottom: 15px !important;
            }
            .btn-primary, .btn-success {
                padding: 12px;
                font-size: 15px;
            }
            .btn-primary i, .btn-success i {
                margin-right: 5px;
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
                font-size: 13px;
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
        
        /* 滑动验证样式 */
        .slider-verification-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        .slider-verification-modal.show {
            opacity: 1;
            visibility: visible;
        }
        .slider-verification-box {
            background: white;
            border-radius: 16px;
            padding: 30px;
            width: 340px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }
        .slider-verification-modal.show .slider-verification-box {
            transform: scale(1);
        }
        .slider-verification-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            text-align: center;
            color: #333;
        }
        .slider-track {
            width: 100%;
            height: 44px;
            background: #f0f0f0;
            border-radius: 22px;
            position: relative;
            overflow: hidden;
        }
        .slider-bg {
            position: absolute;
            height: 100%;
            background: linear-gradient(90deg, 
                #ff6b6b 0%, 
                #feca57 17%, 
                #48dbfb 33%, 
                #ff9ff3 50%, 
                #54a0ff 67%, 
                #5f27cd 84%, 
                #00d2d3 100%);
            background-size: 300% 100%;
            border-radius: 22px;
            transition: width 0.05s ease;
            animation: rainbow 1.5s linear infinite;
        }
        @keyframes rainbow {
            0% { background-position: 0% 50%; }
            100% { background-position: 300% 50%; }
        }
        .slider-thumb {
            position: absolute;
            width: 44px;
            height: 44px;
            background: white;
            border-radius: 50%;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            cursor: grab;
            display: flex;
            align-items: center;
            justify-content: center;
            top: 0;
            transition: left 0.1s ease;
            user-select: none;
        }
        .slider-thumb:active {
            cursor: grabbing;
        }
        .slider-thumb i {
            font-size: 20px;
            color: #4361ee;
        }
        .slider-text {
            position: absolute;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: #666;
            pointer-events: none;
        }
        .slider-success {
            position: absolute;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 22px;
            color: white;
            font-size: 14px;
            font-weight: 500;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .slider-success.show {
            opacity: 1;
        }
        .slider-tips {
            text-align: center;
            margin-top: 15px;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>
<body>
    <a href="<?= htmlspecialchars($redirect_url) ?>" class="back-link">
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
                    <h2>忘记密码？</h2>
                    <p>不用担心，我们都遇到过。请输入您的注册邮箱，将向您发送验证码以重置密码。</p>
                </div>
            </div>
            <div class="auth-sidebar-content text-center mt-4">
                <small style="opacity: 0.7;">&copy; <?= date('Y') ?> <?= e($config['website_name']) ?>. All rights reserved.</small>
            </div>
        </div>

        <div class="auth-main">
            <div class="mb-4">
                <h1 class="auth-title">找回密码</h1>
                <p class="auth-subtitle">验证您的身份以继续</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger mb-4" role="alert">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <?php if ($email_sent): ?>
            <div class="alert alert-success mb-4" role="alert">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($email_sent) ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="forgotForm">
                <?= honeypotField('website_hp') ?>
                <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($redirect_url) ?>">
                
                <div class="mb-4">
                    <div class="form-floating">
                        <input type="email" class="form-control" id="email" name="email" 
                               placeholder="邮箱" 
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                        <label for="email">邮箱地址</label>
                    </div>
                    <div class="form-text mt-2 ms-1">
                        <i class="bi bi-info-circle"></i> 请输入您注册时的邮箱，接收验证码。
                    </div>
                </div>

                <button type="button" class="btn btn-primary w-100 mb-4" id="sendCodeMainBtn" onclick="sendVerificationCode()">
                    <i class="bi bi-envelope"></i> <span id="sendCodeMainText">发送验证码</span>
                </button>

                <button type="button" class="btn btn-success w-100 mb-4" id="nextStepBtn" onclick="submitForm()" style="display: none;">
                    <i class="bi bi-arrow-right"></i> 下一步
                </button>

                <div class="text-center">
                    <span class="text-secondary">记起密码了？</span>
                    <a href="/vendor/login.php<?= ($redirect_url && $redirect_url !== '/') ? '?redirect_url=' . urlencode($redirect_url) : '' ?>" class="text-decoration-none fw-bold" style="color: var(--primary-color);">
                        立即登录
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 滑动验证弹窗 -->
    <div class="slider-verification-modal" id="sliderModal">
        <div class="slider-verification-box">
            <div class="slider-verification-title">
                <i class="bi bi-shield-check me-2"></i>安全验证
            </div>
            <div class="slider-track" id="sliderTrack">
                <div class="slider-bg" id="sliderBg"></div>
                <div class="slider-text" id="sliderText">按住滑块，拖动完成拼图</div>
                <div class="slider-success" id="sliderSuccess">
                    <i class="bi bi-check-circle-fill me-2"></i>验证通过
                </div>
                <div class="slider-thumb" id="sliderThumb">
                    <i class="bi bi-chevron-double-right"></i>
                </div>
            </div>
            <div class="slider-tips">请滑动完成验证</div>
        </div>
    </div>
    
    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script>
        let countdown = 0;
        let countdownInterval;
        let isSliding = false;
        let slideStartX = 0;
        let slideVerified = false;
        
        // 发送验证码
        function sendVerificationCode() {
            const email = document.getElementById('email').value;
            const sendCodeBtn = document.getElementById('sendCodeMainBtn');
            const sendCodeText = document.getElementById('sendCodeMainText');
            
            if (!email) {
                showMessage('请先输入邮箱地址', 'danger');
                return;
            }
            
            if (!validateEmail(email)) {
                showMessage('请输入有效的邮箱地址', 'danger');
                return;
            }
            
            if (countdown > 0) {
                return;
            }
            
            // 重置滑动验证状态
            resetSlider();
            // 显示滑动验证弹窗
            showSliderModal();
        }
        
        // 显示滑动验证弹窗
        function showSliderModal() {
            const modal = document.getElementById('sliderModal');
            modal.classList.add('show');
            initSlider();
        }
        
        // 隐藏滑动验证弹窗
        function hideSliderModal() {
            const modal = document.getElementById('sliderModal');
            modal.classList.remove('show');
        }
        
        // 重置滑动验证
        function resetSlider() {
            slideVerified = false;
            const bg = document.getElementById('sliderBg');
            const thumb = document.getElementById('sliderThumb');
            const text = document.getElementById('sliderText');
            const success = document.getElementById('sliderSuccess');
            bg.style.width = '0';
            thumb.style.left = '0';
            thumb.innerHTML = '<i class="bi bi-chevron-double-right"></i>';
            thumb.style.background = 'white';
            thumb.style.color = '#4361ee';
            text.style.opacity = '1';
            success.classList.remove('show');
        }
        
        // 初始化滑动验证
        function initSlider() {
            const track = document.getElementById('sliderTrack');
            const thumb = document.getElementById('sliderThumb');
            
            const startSlide = (e) => {
                if (slideVerified) return;
                isSliding = true;
                slideStartX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
                thumb.innerHTML = '<i class="bi bi-arrow-right"></i>';
            };
            
            const moveSlide = (e) => {
                if (!isSliding || slideVerified) return;
                e.preventDefault();
                const currentX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
                const deltaX = currentX - slideStartX;
                const trackWidth = track.offsetWidth;
                const thumbWidth = thumb.offsetWidth;
                const maxX = trackWidth - thumbWidth - 4;
                
                let newX = Math.max(0, Math.min(deltaX, maxX));
                const percentage = (newX / maxX) * 100;
                
                document.getElementById('sliderBg').style.width = percentage + '%';
                thumb.style.left = newX + 'px';
                
                // 验证成功
                if (percentage >= 98) {
                    isSliding = false;
                    slideVerified = true;
                    thumb.style.left = maxX + 'px';
                    document.getElementById('sliderBg').style.width = '100%';
                    thumb.innerHTML = '<i class="bi bi-check-lg"></i>';
                    thumb.style.background = '#10b981';
                    thumb.style.color = 'white';
                    
                    setTimeout(() => {
                        hideSliderModal();
                        // 验证通过后发送验证码
                        doSendCode();
                    }, 500);
                }
            };
            
            const endSlide = () => {
                if (slideVerified) return;
                isSliding = false;
                if (!slideVerified) {
                    resetSlider();
                }
            };
            
            // 鼠标事件
            thumb.addEventListener('mousedown', startSlide);
            document.addEventListener('mousemove', moveSlide);
            document.addEventListener('mouseup', endSlide);
            
            // 触摸事件
            thumb.addEventListener('touchstart', startSlide);
            document.addEventListener('touchmove', moveSlide, { passive: false });
            document.addEventListener('touchend', endSlide);
        }
        
        // 执行发送验证码
        function doSendCode() {
            const email = document.getElementById('email').value;
            const sendCodeBtn = document.getElementById('sendCodeMainBtn');
            const sendCodeText = document.getElementById('sendCodeMainText');
            
            // 禁用按钮
            sendCodeBtn.disabled = true;
            sendCodeText.textContent = '发送中...';
            
            // 创建FormData发送请求
            const formData = new FormData();
            formData.append('action', 'send_code');
            formData.append('email', email);
            
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
                
                let foundAlert = false;
                
                const successAlert = doc.querySelector('.alert-success');
                const errorAlert = doc.querySelector('.alert-danger');
                
                if (successAlert) {
                     showMessage(successAlert.textContent, 'success');
                     startCountdown();
                     document.getElementById('nextStepBtn').style.display = 'block';
                     foundAlert = true;
                } else if (errorAlert) {
                     showMessage(errorAlert.textContent, 'danger');
                     sendCodeBtn.disabled = false;
                     sendCodeText.textContent = '重新发送验证码';
                     foundAlert = true;
                }
                
                if (!foundAlert) {
                    // 兜底逻辑
                    if (html.includes('验证码已发送')) {
                        showMessage('验证码发送成功', 'success');
                        startCountdown();
                        document.getElementById('nextStepBtn').style.display = 'block';
                    } else {
                         console.log("No alert found in response");
                         sendCodeBtn.disabled = false;
                         sendCodeText.textContent = '发送验证码';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('发送失败，请重试', 'danger');
                sendCodeBtn.disabled = false;
                sendCodeText.textContent = '发送验证码';
            });
        }
        
        // 开始倒计时
        function startCountdown() {
            countdown = 60;
            const sendCodeBtn = document.getElementById('sendCodeMainBtn');
            const sendCodeText = document.getElementById('sendCodeMainText');
            
            countdownInterval = setInterval(() => {
                if (countdown > 0) {
                    sendCodeText.textContent = `${countdown}秒后重发`;
                    countdown--;
                } else {
                    clearInterval(countdownInterval);
                    sendCodeText.textContent = '重新发送验证码';
                    sendCodeBtn.disabled = false;
                }
            }, 1000);
        }
        
        // 提交表单
        function submitForm() {
            const email = document.getElementById('email').value;
            const redirectUrl = document.querySelector('input[name="redirect_url"]').value;
            
            if (!email) {
                showMessage('请输入邮箱地址', 'danger');
                return;
            }
            
            if (!validateEmail(email)) {
                showMessage('请输入有效的邮箱地址', 'danger');
                return;
            }
            
            // 跳转到重置密码页面，携带 redirect_url
            let targetUrl = `/vendor/reset_password.php?email=${encodeURIComponent(email)}`;
            if (redirectUrl && redirectUrl !== '/') {
                targetUrl += `&redirect_url=${encodeURIComponent(redirectUrl)}`;
            }
            
            window.location.href = targetUrl;
        }
        
        // 验证邮箱格式
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
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
            
            // 插入到表单前面
            const form = document.getElementById('forgotForm');
            form.parentNode.insertBefore(alertDiv, form);
            
            // 5秒后自动消失
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    const bsAlert = new bootstrap.Alert(alertDiv);
                    bsAlert.close();
                }
            }, 5000);
        }
    </script>
</body>
</html>