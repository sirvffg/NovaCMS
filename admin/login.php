<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';

// 记录访问
recordVisit($_SERVER['REQUEST_URI']);

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 已登录则跳转
if (isset($_SESSION['admin_id'])) {
    header('Location: /admin/index.php');
    exit;
}

// 检测前台是否已经登录（如果前台已登录且是管理员，自动登录后台）
if (isset($_SESSION['user_id'])) {
    // 验证用户权限
    $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user && $user['role'] === 'admin') {
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];

        // 更新最后登录时间
        $updateStmt = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);

        header('Location: /admin/index.php');
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $error = '安全验证失败，请刷新页面后重试';
    } else {
        // 人机验证
        $captcha_token = $_POST['captcha_token'] ?? '';
        require_once __DIR__ . '/../vendor/public/captcha/AuthApi.php';
        $captchaAuth = new BehaviorAuth();
        if (empty($captcha_token) || !$captchaAuth->verifyBizToken($captcha_token)) {
            $error = '人机验证失败，请重试';
        } else {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';

            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM admins WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $admin = $stmt->fetch();

            if ($admin && verifyPassword($password, $admin['password'], $db, $admin['id'])) {
                // 检查是否被封禁
                if (!empty($admin['is_banned'])) {
                    $error = '您的账号已被封禁，无法登录';
                } elseif ($admin['role'] === 'admin') {
                    // 更新最后登录时间
                    $updateStmt = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
                    $updateStmt->execute([$admin['id']]);

                    // 防止会话固定攻击：重新生成会话ID
                    session_regenerate_id(true);

                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    header('Location: /admin/index.php');
                    exit;
                } else {
                    $error = '您没有管理员权限，无法登录后台';
                }
            } else {
                $error = '用户名或密码错误';
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
    <title><?= e($config['website_name']) ?> - 后台登录</title>
    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
    <style>
        body {
            background: url('https://api.dujin.org/bing/1920.php') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
        }
        .login-container { max-width: 400px; margin: 100px auto; }
        .login-card {
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            background-color: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="card login-card">
            <div class="card-body p-5">
                <h3 class="text-center mb-4">后台管理登录</h3>
                <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="POST" id="loginForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="captcha_token" id="captchaToken" value="">
                    <div class="mb-3">
                        <label class="form-label">用户名</label>
                        <input type="text" name="username" id="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">密码</label>
                        <input type="password" name="password" id="password" class="form-control" required>
                    </div>
                    <button type="button" class="btn btn-primary w-100" id="btnLogin">登录</button>
                </form>
                <div class="text-center mt-3">
                    <a href="/" class="text-muted">返回首页</a>
                </div>
            </div>
        </div>
    </div>

    <!-- 人机验证弹窗 -->
    <div class="modal fade" id="captchaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content" style="border:none;box-shadow:none;background:transparent;">
                <div class="modal-body p-0 d-flex justify-content-center">
                    <div id="captcha-container"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="/vendor/public/captcha/captcha.js"></script>
    <script>
    let loginCaptcha = null;

    document.getElementById('btnLogin').addEventListener('click', function() {
        const form = document.getElementById('loginForm');
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;

        if (!username || !password) {
            alert('请输入用户名和密码');
            return;
        }

        // 弹出验证码
        const captchaModal = new bootstrap.Modal(document.getElementById('captchaModal'));
        captchaModal.show();

        if (!loginCaptcha) {
            loginCaptcha = new BehaviorAuth('captcha-container', '/vendor/public/captcha/AuthApi.php');
            loginCaptcha.onSuccess = function(bizToken) {
                document.getElementById('captchaToken').value = bizToken;
                setTimeout(function() {
                    const captchaModalEl = document.getElementById('captchaModal');
                    const captchaModalInstance = bootstrap.Modal.getInstance(captchaModalEl);
                    if (captchaModalInstance) captchaModalInstance.hide();
                    form.submit();
                }, 500);
            };
            loginCaptcha.onFail = function() {
                document.getElementById('captchaToken').value = '';
            };
        } else {
            loginCaptcha.reset();
        }
    });
    </script>
</body>
</html>
