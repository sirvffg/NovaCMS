<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

recordVisit($_SERVER['REQUEST_URI'] ?? '/admin/login.php');

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

if (isset($_SESSION['admin_id'])) {
    header('Location: /admin/index.php');
    exit;
}

if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['role'] === 'admin' && empty($user['is_banned'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];

        $updateStmt = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);

        header('Location: /admin/index.php');
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $error = '安全验证已失效，请刷新页面后重试';
    } else {
        $captchaToken = $_POST['captcha_token'] ?? '';
        require_once __DIR__ . '/../vendor/public/captcha/AuthApi.php';
        $captchaAuth = new BehaviorAuth();

        if ($captchaToken === '' || !$captchaAuth->verifyBizToken($captchaToken)) {
            $error = '人机验证失败，请重新完成验证';
        } else {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $stmt = $db->prepare("SELECT * FROM admins WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$username, $username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($admin && verifyPassword($password, $admin['password'], $db, $admin['id'])) {
                if (!empty($admin['is_banned'])) {
                    $error = '该账户当前无法登录，请联系站点负责人';
                } elseif ($admin['role'] === 'admin') {
                    $updateStmt = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
                    $updateStmt->execute([$admin['id']]);

                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];

                    header('Location: /admin/index.php');
                    exit;
                } else {
                    $error = '该账户没有后台管理权限';
                }
            } else {
                $error = '登录信息不正确，请检查后重试';
            }
        }
    }
}

$siteName = (string)($config['website_name'] ?? 'NovaCMS');
if (function_exists('mb_substr')) {
    $siteInitial = mb_substr($siteName, 0, 1, 'UTF-8');
} elseif (preg_match('/^./us', $siteName, $initialMatch)) {
    $siteInitial = $initialMatch[0];
} else {
    $siteInitial = substr($siteName, 0, 1);
}
$loginCssVersion = (string)(@filemtime(__DIR__ . '/../assets/css/admin-login.css') ?: 1);
$adminCssVersion = (string)(@filemtime(__DIR__ . '/../assets/css/admin.css') ?: 1);
$adminShellJsVersion = (string)(@filemtime(__DIR__ . '/../assets/js/admin-shell.js') ?: 1);
$loginJsVersion = (string)(@filemtime(__DIR__ . '/../assets/js/admin-login.js') ?: 1);
?>
<!DOCTYPE html>
<html lang="zh-CN" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#11182c">
    <title><?= e($siteName) ?> · 后台登录</title>
    <script>
        (function () {
            try {
                var savedTheme = localStorage.getItem('theme');
                var preferredTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                document.documentElement.setAttribute('data-bs-theme', savedTheme === 'dark' || savedTheme === 'light' ? savedTheme : preferredTheme);
            } catch (error) {
                document.documentElement.setAttribute('data-bs-theme', 'light');
            }
        }());
    </script>
    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= getResourceUrl('/assets/css/bootstrap-icons.css', 'https://cdn.staticfile.net/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="<?= getResourceUrl('/assets/css/harmonyos-sans.css') ?>" rel="stylesheet">
    <link href="/assets/css/admin.css?v=<?= e($adminCssVersion) ?>" rel="stylesheet">
    <link href="/assets/css/admin-login.css?v=<?= e($loginCssVersion) ?>" rel="stylesheet">
</head>
<body class="admin-login-page">
    <main class="admin-login-layout">
        <section class="admin-login-brand" aria-labelledby="admin-login-brand-title">
            <a class="admin-login-logo" href="/" aria-label="返回 <?= e($siteName) ?> 首页">
                <span class="admin-login-logo-mark" aria-hidden="true"><?= e(strtoupper($siteInitial ?: 'N')) ?></span>
                <span class="admin-login-logo-copy">
                    <strong><?= e($siteName) ?></strong>
                    <small>Administration</small>
                </span>
            </a>

            <div class="admin-login-brand-copy">
                <span class="admin-login-eyebrow"><i class="bi bi-stars" aria-hidden="true"></i> Nova workspace</span>
                <h1 id="admin-login-brand-title">内容、用户与数据，<br>在一处掌控。</h1>
                <p>专注于真正重要的管理任务，以清晰、安全且高效的方式维护你的站点。</p>

                <div class="admin-login-features" aria-label="后台能力">
                    <div class="admin-login-feature"><i class="bi bi-command" aria-hidden="true"></i><span>统一管理内容发布与站点配置</span></div>
                    <div class="admin-login-feature"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i><span>实时查看访问趋势与站内动态</span></div>
                    <div class="admin-login-feature"><i class="bi bi-shield-check" aria-hidden="true"></i><span>人机验证与安全会话保护</span></div>
                </div>
            </div>

            <div class="admin-login-brand-footer">© <?= date('Y') ?> <?= e($siteName) ?> · NovaCMS</div>
        </section>

        <section class="admin-login-form-side" aria-labelledby="admin-login-title">
            <button class="admin-login-theme" type="button" data-theme-toggle aria-label="切换深浅主题">
                <i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i>
            </button>

            <div class="admin-login-card">
                <header class="admin-login-card-head">
                    <h2 id="admin-login-title">欢迎回来</h2>
                    <p>使用管理员账户继续进入控制台</p>
                </header>

                <?php if ($error): ?>
                <div class="admin-login-alert" role="alert">
                    <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                    <span><?= e($error) ?></span>
                </div>
                <?php endif; ?>

                <div class="admin-login-alert is-info" data-login-notice role="status" aria-live="polite" hidden>
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <span data-login-notice-text></span>
                </div>

                <form method="post" id="loginForm" data-admin-login-form novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="captcha_token" id="captchaToken" value="">

                    <div class="admin-login-field">
                        <label for="username">账户</label>
                        <div class="admin-login-control">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <input
                                type="text"
                                name="username"
                                id="username"
                                value="<?= e($_POST['username'] ?? '') ?>"
                                placeholder="用户名或邮箱"
                                autocomplete="username"
                                autocapitalize="none"
                                spellcheck="false"
                                required
                                autofocus
                            >
                        </div>
                    </div>

                    <div class="admin-login-field">
                        <label for="password">
                            <span>密码</span>
                            <small data-caps-lock hidden>大写锁定已开启</small>
                        </label>
                        <div class="admin-login-control">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input type="password" name="password" id="password" placeholder="输入账户密码" autocomplete="current-password" required>
                            <button class="admin-password-toggle" type="button" data-password-toggle aria-label="显示密码" aria-pressed="false">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <button type="button" class="admin-login-submit" id="btnLogin" data-login-submit>
                        <span data-login-button-text>验证并登录</span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>
                </form>

                <a class="admin-login-back" href="/"><i class="bi bi-arrow-left" aria-hidden="true"></i>返回网站首页</a>
                <p class="admin-login-help">后台仅供授权管理员使用。连续登录失败时，请确认账户信息或联系站点负责人。</p>
            </div>
        </section>
    </main>

    <div class="admin-toast-region" data-toast-region aria-live="polite" aria-atomic="true"></div>

    <div class="modal fade" id="captchaModal" tabindex="-1" aria-labelledby="captchaModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content admin-captcha-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="captchaModalTitle">完成安全验证</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
                </div>
                <div class="modal-body d-flex justify-content-center">
                    <div id="captcha-container"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/hash-wasm@4/dist/sha256.umd.min.js"></script>
    <script src="/vendor/public/captcha/crypto-js.min.js"></script>
    <script src="/vendor/public/captcha/BehaviorAuth.js?v=20260731-1"></script>
    <script src="/assets/js/admin-shell.js?v=<?= e($adminShellJsVersion) ?>"></script>
    <script src="/assets/js/admin-login.js?v=<?= e($loginJsVersion) ?>"></script>
</body>
</html>
