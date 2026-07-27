<?php
// 申诉页面不使用 PHP session，完全用签名 cookie + 数据库 token 实现登录态

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 记录访问统计（申诉页面也需要记录，但跳过黑名单检查）
$current_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$current_page_url = '/vendor/appeal.php';
$current_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$visitor_username = null;
$visitor_email = null;

// 获取登录用户信息（如果已登录）
$appeal_secret = $config['site_key'] ?? 'appeal_secret_key_' . md5(__FILE__);
$current_user_temp = null;
if (isset($_COOKIE['appeal_auth'])) {
    $parts = explode('|', $_COOKIE['appeal_auth'], 2);
    if (count($parts) === 2 && strlen($parts[0]) === 64) {
        $token = $parts[0];
        $sig = hash_hmac('sha256', $token, $appeal_secret);
        if (hash_equals($sig, $parts[1])) {
            $stmt = $db->prepare("SELECT a.id, a.username, a.email FROM admins a INNER JOIN appeal_tokens t ON a.id = t.user_id WHERE t.token = ? AND t.expires_at > NOW()");
            $stmt->execute([$token]);
            $current_user_temp = $stmt->fetch();
            if ($current_user_temp) {
                $visitor_username = $current_user_temp['username'];
                $visitor_email = $current_user_temp['email'];
            }
        }
    }
}

// 插入访问记录（跳过黑名单检查，允许被封IP记录）
try {
    $stmt = $db->prepare("INSERT INTO visit_stats (ip_address, user_agent, page_url, visitor_username, visitor_email) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$current_ip, $current_user_agent, $current_page_url, $visitor_username, $visitor_email]);
} catch (Exception $e) {
    error_log('Appeal visit record error: ' . $e->getMessage());
}

// appeal 签名密钥（从网站配置取，确保稳定）
// 注意：此时无 session active，functions.php 中的 checkRememberMe() 不会执行

// 创建 appeal 登录 token 表
$db->exec("CREATE TABLE IF NOT EXISTS `appeal_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='申诉页面登录token'");

// 清理过期 token
$db->prepare("DELETE FROM appeal_tokens WHERE expires_at < NOW()")->execute();

// ===================== 独立 CSRF 保护（不依赖 session）=====================
// 使用 HMAC 签名的 token，验证时无需 session 存储
function appealCsrfField() {
    $token = bin2hex(random_bytes(16));
    $sig = hash_hmac('sha256', $token, 'appeal_csrf_' . date('YmdH')); // 按小时轮换密钥
    return '<input type="hidden" name="csrf_token" value="' . $token . '|' . $sig . '">';
}

function appealValidateCsrf($input) {
    if (empty($input)) return false;
    $parts = explode('|', $input, 2);
    if (count($parts) !== 2 || strlen($parts[0]) !== 32) return false;
    $sig = hash_hmac('sha256', $parts[0], 'appeal_csrf_' . date('YmdH'));
    return hash_equals($sig, $parts[1]);
}

// 独立 honeypot（不依赖 session_id）
function appealHoneypotField($name = 'website_hp') {
    $id = 'hp_' . md5($name . 'appeal_static_salt');
    return '<div style="position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;overflow:hidden;" aria-hidden="true" tabindex="-1" autocomplete="off">' .
        '<label for="' . $id . '">请勿填写此字段</label>' .
        '<input type="text" id="' . $id . '" name="' . htmlspecialchars($name) . '" value="" tabindex="-1" autocomplete="off">' .
        '</div>';
}

function appealCheckHoneypot($fields = ['website_hp']) {
    foreach ($fields as $field) {
        $value = trim($_POST[$field] ?? '');
        if ($value !== '') return true;
    }
    return false;
}
function appealLogin($user_id, $db, $secret) {
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + 7200); // 2小时
    $db->prepare("INSERT INTO appeal_tokens (user_id, token, expires_at) VALUES (?, ?, ?)")
        ->execute([$user_id, $token, $expires_at]);
    $sig = hash_hmac('sha256', $token, $secret);
    setcookie('appeal_auth', $token . '|' . $sig, [
        'expires' => time() + 7200,
        'path' => '/vendor/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    return true;
}

function appealLogout($db) {
    $token = appealGetToken();
    if ($token) {
        $db->prepare("DELETE FROM appeal_tokens WHERE token = ?")->execute([$token]);
    }
    setcookie('appeal_auth', '', [
        'expires' => time() - 3600,
        'path' => '/vendor/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function appealGetToken() {
    if (isset($_COOKIE['appeal_auth'])) {
        $parts = explode('|', $_COOKIE['appeal_auth'], 2);
        if (count($parts) === 2 && strlen($parts[0]) === 64) {
            return $parts[0];
        }
    }
    return null;
}

function appealVerifyLogin($db, $secret) {
    $token = appealGetToken();
    if (!$token) return null;
    $parts = explode('|', $_COOKIE['appeal_auth'], 2);
    $sig = hash_hmac('sha256', $token, $secret);
    if (!hash_equals($sig, $parts[1])) return null;
    $stmt = $db->prepare("SELECT a.id, a.username, a.email FROM admins a INNER JOIN appeal_tokens t ON a.id = t.user_id WHERE t.token = ? AND t.expires_at > NOW()");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

// 确保申诉表存在
ensureAppealTables();

// 确保邮箱验证码表存在
$db->exec("CREATE TABLE IF NOT EXISTS `email_verification` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `code` varchar(6) NOT NULL,
  `purpose` varchar(20) NOT NULL DEFAULT 'register',
  `is_used` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_purpose_unused` (`email`, `purpose`, `is_used`),
  KEY `idx_email_expires` (`email`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮箱验证码表'");

// 当前用户信息（通过 cookie + 数据库验证，不依赖 session）
$current_user = appealVerifyLogin($db, $appeal_secret);
$is_logged_in = !empty($current_user);
$appeal_user_id = $is_logged_in ? $current_user['id'] : null;

// 检查当前IP是否被封禁
$ip_banned = false;
try {
    $stmt = $db->prepare("SELECT 1 FROM bot_blacklist WHERE ip_address = ? AND (reason LIKE '%用户封禁%' OR expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
    $stmt->execute([$current_ip]);
    $ip_banned = (bool)$stmt->fetch();
} catch (Exception $e) {}

// 检查当前用户是否被封禁
$user_banned = false;
if ($is_logged_in && $current_user) {
    try {
        $stmt = $db->prepare("SELECT is_banned FROM admins WHERE id = ?");
        $stmt->execute([$appeal_user_id]);
        $row = $stmt->fetch();
        $user_banned = $row && (bool)$row['is_banned'];
    } catch (Exception $e) {}
}

// 申诉类型定义
$appeal_types = [
    'ip' => ['name' => 'IP申诉', 'icon' => 'bi-globe', 'desc' => '您的IP地址被封禁，申请解封'],
    'user' => ['name' => '账号申诉', 'icon' => 'bi-person-lock', 'desc' => '您的账号被封禁，申请解封', 'require_login' => true],
    'ip_user' => ['name' => 'IP + 账号申诉', 'icon' => 'bi-shield-exclamation', 'desc' => '您的IP和账号同时被封禁，申请解封', 'require_login' => true],
];

$form_error = '';
$form_success = '';
$selected_type = $_GET['type'] ?? '';

// 验证申诉类型
if (!array_key_exists($selected_type, $appeal_types)) {
    $selected_type = '';
}

// ===================== 内置认证系统（登录/注册/找回密码）=====================

// 账户锁定配置
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900);

// 内置登录处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'builtin_login') {
    header('Content-Type: application/json');

    if (!appealValidateCsrf($_POST['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面']);
        exit;
    }
    if (appealCheckHoneypot()) {
        sleep(2);
        echo json_encode(['success' => false, 'message' => '用户名或密码错误']);
        exit;
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => '用户名/邮箱和密码不能为空']);
        exit;
    }

    $rateLimit = checkRateLimit('login', 5, 300);
    if (!$rateLimit['allowed']) {
        echo json_encode(['success' => false, 'message' => $rateLimit['message']]);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM admins WHERE username = ? OR email = ?");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if ($user && isset($user['login_attempts']) && isset($user['last_login_attempt'])) {
        $lockout_end = $user['last_login_attempt'] + LOCKOUT_DURATION;
        if ($user['login_attempts'] >= MAX_LOGIN_ATTEMPTS && time() < $lockout_end) {
            $remaining = ceil(($lockout_end - time()) / 60);
            echo json_encode(['success' => false, 'message' => "账户已被锁定，请在 {$remaining} 分钟后重试"]);
            exit;
        }
    }

    if ($user && verifyPassword($password, $user['password'], $db, $user['id'])) {
        // 登录成功（不检查封禁，因为申诉页面允许被封用户登录）
        appealLogin($user['id'], $db, $appeal_secret);
        resetRateLimit('login');

        if (isset($user['login_attempts'])) {
            $db->prepare("UPDATE admins SET login_attempts = 0 WHERE id = ?")->execute([$user['id']]);
        }

        echo json_encode(['success' => true, 'message' => '登录成功']);
    } else {
        if ($user) {
            ensureSessionTables();
            recordLoginFailure($user['id'], '密码错误');
            $delaySeconds = 1;
            if (isset($user['login_attempts'])) {
                $delaySeconds = min(pow(2, $user['login_attempts']), 8);
            }
            sleep($delaySeconds);

            if (isset($user['login_attempts'])) {
                $db->prepare("UPDATE admins SET login_attempts = login_attempts + 1, last_login_attempt = ? WHERE id = ?")
                    ->execute([time(), $user['id']]);
                $checkStmt = $db->prepare("SELECT login_attempts FROM admins WHERE id = ?");
                $checkStmt->execute([$user['id']]);
                $updated = $checkStmt->fetch();
                if ($updated['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
                    echo json_encode(['success' => false, 'message' => "密码错误，账户已被锁定，请15分钟后再试"]);
                    exit;
                }
                $remaining = MAX_LOGIN_ATTEMPTS - $updated['login_attempts'];
                echo json_encode(['success' => false, 'message' => "用户名/邮箱或密码错误，剩余 {$remaining} 次尝试机会"]);
            } else {
                echo json_encode(['success' => false, 'message' => '用户名/邮箱或密码错误']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '用户名/邮箱或密码错误']);
        }
    }
    exit;
}

// 内置注册处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'builtin_register') {
    header('Content-Type: application/json');

    if (!appealValidateCsrf($_POST['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面']);
        exit;
    }
    if (appealCheckHoneypot()) {
        echo json_encode(['success' => true, 'message' => '注册成功，请登录']);
        exit;
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $verification_code = trim($_POST['verification_code'] ?? '');

    if (empty($username) || empty($password) || empty($email) || empty($verification_code)) {
        echo json_encode(['success' => false, 'message' => '请填写所有必填字段']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => '请输入有效的邮箱地址']);
        exit;
    }
    if ($password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => '两次输入的密码不一致']);
        exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => '密码长度至少6位']);
        exit;
    }

    $rateLimit = checkRateLimit('register', 10, 3600);
    if (!$rateLimit['allowed']) {
        echo json_encode(['success' => false, 'message' => $rateLimit['message']]);
        exit;
    }

    // 用户名验证
    if (strlen($username) < 3 || strlen($username) > 20) {
        echo json_encode(['success' => false, 'message' => '用户名长度应在3-20个字符之间']);
        exit;
    }
    if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_]+$/u', $username)) {
        echo json_encode(['success' => false, 'message' => '用户名只能包含中文、字母、数字和下划线']);
        exit;
    }
    if (preg_match('/^\d/', $username)) {
        echo json_encode(['success' => false, 'message' => '用户名不能以数字开头']);
        exit;
    }

    try {
        // 验证邮箱验证码
        $stmt = $db->prepare("SELECT id FROM email_verification WHERE email = ? AND code = ? AND purpose = 'register' AND is_used = 0 AND expires_at > NOW()");
        $stmt->execute([$email, $verification_code]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => '验证码无效或已过期']);
            exit;
        }

        $stmt = $db->prepare("SELECT id FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => '用户名已存在']);
            exit;
        }

        $stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => '该邮箱已被注册']);
            exit;
        }

        $db->prepare("UPDATE email_verification SET is_used = 1, used_at = NOW() WHERE email = ? AND code = ? AND purpose = 'register'")
            ->execute([$email, $verification_code]);

        $hashedPassword = hashPassword($password);
        $registerIp = $current_ip;
        $columns = $db->query("SHOW COLUMNS FROM admins LIKE 'register_ip'")->fetch();
        if (!$columns) {
            $db->exec("ALTER TABLE admins ADD COLUMN register_ip VARCHAR(45) DEFAULT '' COMMENT '注册IP'");
        }
        $stmt = $db->prepare("INSERT INTO admins (username, password, email, role, register_ip) VALUES (?, ?, ?, 'user', ?)");
        $stmt->execute([$username, $hashedPassword, $email, $registerIp]);

        echo json_encode(['success' => true, 'message' => '注册成功，请登录']);
    } catch (Exception $e) {
        error_log('Register error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '注册出错，请重试']);
    }
    exit;
}

// 内置用户名检测
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'builtin_check_username') {
    header('Content-Type: application/json');
    $username = trim($_POST['username'] ?? '');
    if (empty($username) || strlen($username) < 3 || strlen($username) > 20) {
        echo json_encode(['valid' => false, 'message' => '用户名长度应在3-20个字符之间']);
        exit;
    }
    if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_]+$/u', $username)) {
        echo json_encode(['valid' => false, 'message' => '用户名只能包含中文、字母、数字和下划线']);
        exit;
    }
    if (preg_match('/^\d/', $username)) {
        echo json_encode(['valid' => false, 'message' => '用户名不能以数字开头']);
        exit;
    }
    $stmt = $db->prepare("SELECT id FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    echo $stmt->fetch()
        ? json_encode(['valid' => false, 'message' => '用户名已存在'])
        : json_encode(['valid' => true, 'message' => '用户名可用']);
    exit;
}

// 内置邮箱检测
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'builtin_check_email') {
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['valid' => false, 'message' => '请输入有效的邮箱地址']);
        exit;
    }
    $stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    echo $stmt->fetch()
        ? json_encode(['valid' => false, 'message' => '该邮箱已被注册'])
        : json_encode(['valid' => true, 'message' => '邮箱可用']);
    exit;
}

// 内置发送注册验证码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'builtin_send_reg_code') {
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => '请输入有效的邮箱地址']);
        exit;
    }
    $rateLimit = checkRateLimit('email_code', 3, 3600);
    if (!$rateLimit['allowed']) {
        echo json_encode(['success' => false, 'message' => $rateLimit['message']]);
        exit;
    }
    try {
        $stmt = $db->prepare("SELECT id, code FROM email_verification WHERE email = ? AND purpose = 'register' AND is_used = 0 AND expires_at > DATE_ADD(NOW(), INTERVAL 5 MINUTE) ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email]);
        $existingCode = $stmt->fetch();
        if ($existingCode) {
            $code = $existingCode['code'];
        } else {
            $db->prepare("DELETE FROM email_verification WHERE email = ? AND purpose = 'register' AND is_used = 0")->execute([$email]);
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', time() + 600);
            $db->prepare("INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'register', ?)")
                ->execute([$email, $code, $expires_at]);
        }
        if (sendVerificationEmail($email, $code)) {
            echo json_encode(['success' => true, 'message' => "验证码已发送到 {$email}"]);
        } else {
            echo json_encode(['success' => false, 'message' => '邮件发送失败，请稍后重试']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '发送验证码出错，请稍后重试']);
    }
    exit;
}

// 内置发送找回密码验证码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'builtin_send_forgot_code') {
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => '请输入有效的邮箱地址']);
        exit;
    }
    $stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => '该邮箱未注册，请先注册账户']);
        exit;
    }
    $rateLimit = checkRateLimit('forgot_code', 3, 3600);
    if (!$rateLimit['allowed']) {
        echo json_encode(['success' => false, 'message' => $rateLimit['message']]);
        exit;
    }
    try {
        $db->prepare("DELETE FROM email_verification WHERE email = ? AND purpose = 'reset'")->execute([$email]);
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires_at = date('Y-m-d H:i:s', time() + 600);
        $db->prepare("INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'reset', ?)")
            ->execute([$email, $code, $expires_at]);
        if (sendVerificationEmail($email, $code)) {
            echo json_encode(['success' => true, 'message' => "验证码已发送到 {$email}"]);
        } else {
            echo json_encode(['success' => false, 'message' => '邮件发送失败，请重试']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '发送验证码出错，请稍后重试']);
    }
    exit;
}

// 内置重置密码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'builtin_reset_password') {
    header('Content-Type: application/json');
    if (!appealValidateCsrf($_POST['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面']);
        exit;
    }
    $email = trim($_POST['email'] ?? '');
    $verification_code = trim($_POST['verification_code'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    if (empty($email) || empty($verification_code) || empty($new_password) || empty($confirm_password)) {
        echo json_encode(['success' => false, 'message' => '请填写所有必填字段']);
        exit;
    }
    if (strlen($new_password) < 6) {
        echo json_encode(['success' => false, 'message' => '密码长度至少6位']);
        exit;
    }
    if ($new_password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => '两次输入的密码不一致']);
        exit;
    }
    try {
        $stmt = $db->prepare("SELECT id FROM email_verification WHERE email = ? AND code = ? AND purpose = 'reset' AND is_used = 0 AND expires_at > NOW()");
        $stmt->execute([$email, $verification_code]);
        $verification = $stmt->fetch();
        if (!$verification) {
            echo json_encode(['success' => false, 'message' => '验证码无效或已过期，请重新获取']);
            exit;
        }
        $db->prepare("UPDATE email_verification SET is_used = 1, used_at = NOW() WHERE id = ?")->execute([$verification['id']]);
        $hashedPassword = hashPassword($new_password);
        $db->prepare("UPDATE admins SET password = ? WHERE email = ?")->execute([$hashedPassword, $email]);
        echo json_encode(['success' => true, 'message' => '密码重置成功，请使用新密码登录']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '密码重置出错，请重试']);
    }
    exit;
}

// 内置登出处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'builtin_logout') {
    header('Content-Type: application/json');
    appealLogout($db);
    echo json_encode(['success' => true]);
    exit;
}

// ===================== 申诉表单提交 =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_appeal') {
    header('Content-Type: application/json');

    if (appealCheckHoneypot()) {
        echo json_encode(['success' => true, 'message' => '申诉已提交，请耐心等待审核']);
        exit;
    }

    if (!appealValidateCsrf($_POST['csrf_token'] ?? null)) {
        echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面']);
        exit;
    }

    $type = trim($_POST['appeal_type'] ?? '');
    if (!array_key_exists($type, $appeal_types)) {
        echo json_encode(['success' => false, 'message' => '无效的申诉类型']);
        exit;
    }

    if (isset($appeal_types[$type]['require_login']) && $appeal_types[$type]['require_login'] && !$is_logged_in) {
        echo json_encode(['success' => false, 'message' => '该申诉类型需要登录，请先登录']);
        exit;
    }

    if ($type === 'ip' && !$ip_banned) {
        echo json_encode(['success' => false, 'message' => '当前IP未被封禁，无需申诉']);
        exit;
    }
    if ($type === 'user' && !$user_banned) {
        echo json_encode(['success' => false, 'message' => '当前账号未被封禁，无需申诉']);
        exit;
    }
    if ($type === 'ip_user' && !$ip_banned && !$user_banned) {
        echo json_encode(['success' => false, 'message' => '当前IP和账号均未被封禁，无需申诉']);
        exit;
    }

    $contact_name = trim($_POST['contact_name'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if (empty($contact_name)) { echo json_encode(['success' => false, 'message' => '请输入联系人姓名']); exit; }
    if (strlen($contact_name) > 50) { echo json_encode(['success' => false, 'message' => '联系人姓名不能超过50个字符']); exit; }
    if (empty($contact_email)) { echo json_encode(['success' => false, 'message' => '请输入联系邮箱']); exit; }
    if (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'message' => '请输入有效的邮箱地址']); exit; }
    if (empty($reason)) { echo json_encode(['success' => false, 'message' => '请填写申诉理由']); exit; }
    if (strlen($reason) < 10) { echo json_encode(['success' => false, 'message' => '申诉理由至少10个字符']); exit; }
    if (strlen($reason) > 2000) { echo json_encode(['success' => false, 'message' => '申诉理由不能超过2000个字符']); exit; }

    $rateLimit = checkRateLimit('appeal_' . $current_ip, 3, 86400);
    if (!$rateLimit['allowed']) { echo json_encode(['success' => false, 'message' => $rateLimit['message']]); exit; }

    $emailRateLimit = checkRateLimit('appeal_email_' . $contact_email, 3, 86400);
    if (!$emailRateLimit['allowed']) { echo json_encode(['success' => false, 'message' => '该邮箱今日申诉次数已达上限']); exit; }

    try {
        $user_id = $is_logged_in ? $appeal_user_id : null;
        $stmt = $db->prepare("INSERT INTO appeals (appeal_type, ip_address, user_id, contact_name, contact_email, reason, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([$type, $current_ip, $user_id, $contact_name, $contact_email, $reason]);
        
        // 发送申诉通知邮件给站长
        if (!empty($config['contact_email'])) {
            $appealData = [
                'type' => $type,
                'contact_name' => $contact_name,
                'contact_email' => $contact_email,
                'reason' => $reason,
                'ip_address' => $current_ip
            ];
            sendAppealNotice($config['contact_email'], $config['website_name'], $appealData);
        }
        
        echo json_encode(['success' => true, 'message' => '申诉已成功提交，管理员将在24小时内审核处理，结果将发送至您的邮箱。']);
    } catch (Exception $e) {
        error_log('Appeal submit error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '提交失败，请稍后再试']);
    }
    exit;
}

// 查询申诉记录
$my_appeals = [];
if ($is_logged_in) {
    $stmt = $db->prepare("SELECT * FROM appeals WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$appeal_user_id]);
    $my_appeals = $stmt->fetchAll();
}

/**
 * 确保申诉表存在
 */
function ensureAppealTables() {
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS appeals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            appeal_type ENUM('ip', 'user', 'ip_user') NOT NULL COMMENT '申诉类型',
            ip_address VARCHAR(45) NOT NULL COMMENT '申诉IP地址',
            user_id INT DEFAULT NULL COMMENT '关联用户ID',
            contact_name VARCHAR(50) NOT NULL COMMENT '联系人姓名',
            contact_email VARCHAR(100) NOT NULL COMMENT '联系邮箱',
            reason TEXT NOT NULL COMMENT '申诉理由',
            status ENUM('pending', 'approved', 'rejected', 'processing') DEFAULT 'pending' COMMENT '状态',
            admin_reply TEXT DEFAULT NULL COMMENT '管理员回复',
            reviewed_by INT DEFAULT NULL COMMENT '审核管理员ID',
            reviewed_at DATETIME DEFAULT NULL COMMENT '审核时间',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '提交时间',
            INDEX idx_type (appeal_type),
            INDEX idx_status (status),
            INDEX idx_user (user_id),
            INDEX idx_ip (ip_address),
            INDEX idx_email (contact_email),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='申诉记录表';");
    } catch (Exception $e) {
        error_log('Appeal table creation error: ' . $e->getMessage());
    }
}

// 状态中文映射
$status_map = [
    'pending' => ['text' => '待审核', 'class' => 'warning'],
    'processing' => ['text' => '处理中', 'class' => 'info'],
    'approved' => ['text' => '已通过', 'class' => 'success'],
    'rejected' => ['text' => '已拒绝', 'class' => 'danger'],
];

$type_map = [
    'ip' => 'IP申诉',
    'user' => '账号申诉',
    'ip_user' => 'IP+账号申诉',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>封禁申诉 - <?= e($config['website_name']) ?></title>
    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= getResourceUrl('/assets/css/bootstrap-icons.css', 'https://cdn.staticfile.net/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="/assets/css/harmonyos-sans.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --bg-gradient: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        body {
            background: var(--bg-gradient);
            min-height: 100vh;
            font-family: 'HarmonyOS Sans', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            padding: 20px;
            padding-top: 30px;
        }
        .appeal-container { max-width: 800px; margin: 0 auto; }
        .page-header { text-align: center; margin-bottom: 32px; }
        .page-header h1 { font-size: 2rem; font-weight: 700; color: #2b2d42; margin-bottom: 8px; }
        .page-header p { color: #6c757d; font-size: 1rem; }
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: #6c757d; text-decoration: none; font-size: 0.9rem; margin-bottom: 20px; transition: color 0.2s; }
        .back-link:hover { color: var(--primary-color); }

        .type-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 32px; }
        .type-card { background: white; border: 2px solid #e9ecef; border-radius: 16px; padding: 24px 16px; text-align: center; cursor: pointer; transition: all 0.3s ease; text-decoration: none; color: inherit; }
        .type-card:hover { border-color: var(--primary-color); transform: translateY(-3px); box-shadow: 0 8px 24px rgba(67,97,238,0.15); }
        .type-card.active { border-color: var(--primary-color); background: linear-gradient(135deg, rgba(67,97,238,0.05) 0%, rgba(63,55,201,0.05) 100%); box-shadow: 0 4px 16px rgba(67,97,238,0.12); }
        .type-card .type-icon { width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, #4361ee 0%, #3f37c9 100%); display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; font-size: 24px; color: white; }
        .type-card.active .type-icon { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .type-card .type-name { font-weight: 600; font-size: 1rem; margin-bottom: 4px; color: #2b2d42; }
        .type-card .type-desc { font-size: 0.8rem; color: #6c757d; line-height: 1.4; }
        .type-card .type-badge { display: inline-block; margin-top: 8px; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; background: #fff3cd; color: #856404; }

        .form-card { background: white; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.08); padding: 36px; display: none; }
        .form-card.active { display: block; }
        .form-card h2 { font-size: 1.4rem; font-weight: 700; color: #2b2d42; margin-bottom: 6px; }
        .form-card .form-subtitle { color: #6c757d; font-size: 0.9rem; margin-bottom: 24px; }
        .form-floating > .form-control, .form-floating > .form-select { border-radius: 12px; border: 2px solid #e9ecef; padding-left: 15px; }
        .form-floating > .form-control:focus, .form-floating > textarea:focus { border-color: var(--primary-color); box-shadow: 0 0 0 4px rgba(67,97,238,0.1); }
        .form-floating > label { padding-left: 15px; }
        .form-text.hint { font-size: 0.8rem; color: #6c757d; }
        .btn-primary { background: var(--primary-color); border: none; border-radius: 12px; padding: 12px; font-weight: 600; font-size: 16px; transition: all 0.3s ease; }
        .btn-primary:hover:not(:disabled) { background: var(--secondary-color); transform: translateY(-2px); box-shadow: 0 6px 16px rgba(67,97,238,0.3); }
        .btn-primary:disabled { opacity: 0.65; cursor: not-allowed; }

        .history-section { margin-top: 32px; }
        .history-section h3 { font-size: 1.2rem; font-weight: 700; color: #2b2d42; margin-bottom: 16px; }
        .appeal-record { background: white; border-radius: 14px; padding: 18px 20px; margin-bottom: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border-left: 4px solid #dee2e6; }
        .appeal-record[data-status="pending"] { border-left-color: #ffc107; }
        .appeal-record[data-status="processing"] { border-left-color: #0dcaf0; }
        .appeal-record[data-status="approved"] { border-left-color: #22c55e; }
        .appeal-record[data-status="rejected"] { border-left-color: #dc3545; }
        .appeal-record .record-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .appeal-record .record-type { font-weight: 600; font-size: 0.95rem; color: #2b2d42; }
        .appeal-record .record-time { font-size: 0.8rem; color: #adb5bd; }
        .appeal-record .record-reason { font-size: 0.9rem; color: #495057; margin-bottom: 8px; line-height: 1.5; }
        .appeal-record .record-reply { background: #f8f9fa; border-radius: 8px; padding: 10px 12px; font-size: 0.85rem; color: #495057; }
        .appeal-record .record-reply strong { color: #2b2d42; }

        .ip-info-card { background: white; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 0.85rem; color: #6c757d; border: 1px solid #e9ecef; }
        .ip-info-card i { color: var(--primary-color); font-size: 1.1rem; }
        .ip-info-card strong { color: #2b2d42; }
        .empty-state { text-align: center; padding: 32px; color: #adb5bd; }
        .empty-state i { font-size: 2.5rem; margin-bottom: 12px; }

        /* 用户状态栏 */
        .user-status-bar {
            background: white;
            border-radius: 16px;
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
            border: 1px solid #e9ecef;
        }
        .user-status-bar .user-info { display: flex; align-items: center; gap: 12px; }
        .user-status-bar .user-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #4361ee 0%, #3f37c9 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; font-weight: 600; }
        .user-status-bar .user-name { font-weight: 600; color: #2b2d42; font-size: 0.95rem; }
        .user-status-bar .user-email { font-size: 0.8rem; color: #6c757d; }
        .user-status-bar .auth-buttons { display: flex; gap: 8px; }
        .user-status-bar .btn-sm { border-radius: 10px; font-size: 0.85rem; padding: 6px 16px; }

        /* 模态框样式 */
        .auth-modal .modal-content { border-radius: 20px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.15); }
        .auth-modal .modal-header { border-bottom: 1px solid #f0f0f0; padding: 24px 28px 16px; }
        .auth-modal .modal-title { font-weight: 700; font-size: 1.3rem; color: #2b2d42; }
        .auth-modal .modal-body { padding: 20px 28px 28px; }
        .auth-modal .btn-close { opacity: 0.5; }
        .auth-modal .form-floating > .form-control { border-radius: 12px; border: 2px solid #e9ecef; padding-left: 15px; }
        .auth-modal .form-floating > .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 4px rgba(67,97,238,0.1); }
        .auth-modal .form-floating > label { padding-left: 15px; }
        .auth-modal .modal-footer { border-top: 1px solid #f0f0f0; padding: 16px 28px; }
        .auth-modal .modal-footer-text { text-align: center; width: 100%; color: #6c757d; font-size: 0.9rem; }
        .auth-modal .modal-footer-text a { color: var(--primary-color); font-weight: 600; cursor: pointer; text-decoration: none; }
        .auth-modal .modal-footer-text a:hover { text-decoration: underline; }
        .auth-modal .alert { border-radius: 12px; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; }
        .auth-modal .form-text { font-size: 0.8rem; }

        .code-btn-group { display: flex; gap: 8px; }
        .code-btn-group .form-floating { flex: 1; }
        .code-btn-group .btn { border-radius: 12px; white-space: nowrap; min-width: 120px; border: 2px solid #e9ecef; }

        @media (max-width: 768px) {
            body { padding: 15px; padding-top: 20px; }
            .type-grid { grid-template-columns: 1fr; }
            .form-card { padding: 24px 20px; }
            .page-header h1 { font-size: 1.6rem; }
            .user-status-bar { flex-direction: column; gap: 12px; text-align: center; }
            .user-status-bar .user-info { flex-direction: column; }
            .auth-modal .modal-content { margin: 10px; }
            .auth-modal .modal-body { padding: 16px 20px 20px; }
        }
    </style>
</head>
<body>
    <div class="appeal-container">
        <a href="/" class="back-link">
            <i class="bi bi-arrow-left"></i>
            <span>返回首页</span>
        </a>

        <div class="page-header">
            <h1><i class="bi bi-shield-lock me-2"></i>封禁申诉</h1>
            <p>如果您认为封禁是误操作，请通过以下方式提交申诉</p>
        </div>

        <!-- 用户状态栏 -->
        <div class="user-status-bar">
            <?php if ($is_logged_in && $current_user): ?>
            <div class="user-info">
                <div class="user-avatar"><?= mb_substr($current_user['username'], 0, 1) ?></div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($current_user['username']) ?></div>
                    <div class="user-email"><?= htmlspecialchars($current_user['email'] ?? '') ?></div>
                </div>
            </div>
            <div class="auth-buttons">
                <button class="btn btn-outline-secondary btn-sm" onclick="doLogout()">
                    <i class="bi bi-box-arrow-right me-1"></i>退出登录
                </button>
            </div>
            <?php else: ?>
            <div class="user-info">
                <div class="user-avatar"><i class="bi bi-person"></i></div>
                <div>
                    <div class="user-name">未登录</div>
                    <div class="user-email">登录后可提交账号相关申诉</div>
                </div>
            </div>
            <div class="auth-buttons">
                <button class="btn btn-outline-primary btn-sm" onclick="openAuthModal('login')">
                    <i class="bi bi-box-arrow-in-right me-1"></i>登录
                </button>
                <button class="btn btn-primary btn-sm" onclick="openAuthModal('register')">
                    <i class="bi bi-person-plus me-1"></i>注册
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- 封禁状态提示 -->
        <?php if (!$ip_banned && !$user_banned): ?>
        <div class="alert alert-success" role="alert" style="border-radius: 14px; padding: 16px 20px;">
            <i class="bi bi-check-circle-fill me-2"></i>
            <strong>当前状态正常</strong> — 您的IP和账号均未被封禁，无需提交申诉。
        </div>
        <?php endif; ?>

        <!-- 类型选择 -->
        <div class="type-grid">
            <?php foreach ($appeal_types as $type_key => $type_info):
                $type_disabled = false;
                $need_login = !empty($type_info['require_login']);
                if ($type_key === 'ip' && !$ip_banned) $type_disabled = true;
                if ($type_key === 'user' && (!$is_logged_in || !$user_banned)) $type_disabled = true;
                if ($type_key === 'ip_user' && (!$is_logged_in || !$ip_banned)) $type_disabled = true;
                // 计算卡片状态文字
                $badge_html = '';
                if ($type_disabled) {
                    if ($need_login && !$is_logged_in) {
                        $badge_html = '<span class="type-badge" style="background:#fff3cd;color:#856404;"><i class="bi bi-question-circle me-1"></i>未登录</span>';
                    } elseif ($need_login && $is_logged_in && !$user_banned && $type_key !== 'ip_user') {
                        $badge_html = '<span class="type-badge" style="background:#d1e7dd;color:#0f5132;"><i class="bi bi-check-fill me-1"></i>账号正常</span>';
                    } elseif ($need_login && $is_logged_in && !$ip_banned && !$user_banned && $type_key === 'ip_user') {
                        $badge_html = '<span class="type-badge" style="background:#d1e7dd;color:#0f5132;"><i class="bi bi-check-fill me-1"></i>正常</span>';
                    } else {
                        $badge_html = '<span class="type-badge" style="background:#d1e7dd;color:#0f5132;"><i class="bi bi-check-fill me-1"></i>正常</span>';
                    }
                } else {
                    // 可点击状态
                    if ($type_key === 'ip') {
                        $badge_html = '<span class="type-badge" style="background:#f8d7da;color:#842029;"><i class="bi bi-x-circle-fill me-1"></i>IP已封禁</span>';
                    } elseif ($type_key === 'user') {
                        $badge_html = '<span class="type-badge" style="background:#f8d7da;color:#842029;"><i class="bi bi-x-circle-fill me-1"></i>账号已封禁</span>';
                    } elseif ($type_key === 'ip_user') {
                        $badge_html = '<span class="type-badge" style="background:#f8d7da;color:#842029;"><i class="bi bi-x-circle-fill me-1"></i>IP+账号已封禁</span>';
                    }
                }
            ?>
            <?php if ($type_disabled): ?>
            <div class="type-card" style="opacity: 0.5; cursor: not-allowed; position: relative;" title="<?= $need_login && !$is_logged_in ? '请先登录查看账号状态' : '未被封禁，无需申诉' ?>">
                <div class="type-icon" style="background: linear-gradient(135deg, #adb5bd 0%, #6c757d 100%);">
                    <i class="bi <?= $type_info['icon'] ?>"></i>
                </div>
                <div class="type-name"><?= $type_info['name'] ?></div>
                <div class="type-desc"><?= $type_info['desc'] ?></div>
                <?= $badge_html ?>
            </div>
            <?php else: ?>
            <a href="/vendor/appeal.php?type=<?= $type_key ?>" class="type-card <?= $selected_type === $type_key ? 'active' : '' ?>">
                <div class="type-icon" style="background: linear-gradient(135deg, #dc3545 0%, #a71d2a 100%);">
                    <i class="bi <?= $type_info['icon'] ?>"></i>
                </div>
                <div class="type-name"><?= $type_info['name'] ?></div>
                <div class="type-desc"><?= $type_info['desc'] ?></div>
                <?= $badge_html ?>
            </a>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- IP信息展示 -->
        <?php if ($selected_type === 'ip' || $selected_type === 'ip_user'): ?>
        <div class="ip-info-card">
            <i class="bi bi-geo-alt-fill"></i>
            <span>当前IP：<strong><?= htmlspecialchars($current_ip) ?></strong></span>
            <span class="ms-auto" style="font-size: 0.8rem;">申诉将自动关联此IP</span>
        </div>
        <?php endif; ?>

        <!-- 表单区域 -->
        <?php foreach ($appeal_types as $type_key => $type_info): ?>
        <div class="form-card <?= $selected_type === $type_key ? 'active' : '' ?>" id="form-<?= $type_key ?>">
            <h2><i class="bi <?= $type_info['icon'] ?> me-2"></i><?= $type_info['name'] ?></h2>
            <p class="form-subtitle"><?= $type_info['desc'] ?>，请认真填写以下信息</p>
            <?php if ($selected_type === $type_key && !$is_logged_in && !empty($type_info['require_login'])): ?>
            <div class="alert alert-info" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i>此申诉类型需要登录账号，<a href="javascript:void(0)" onclick="openAuthModal('login')" class="alert-link">请先登录</a>。
            </div>
            <?php elseif ($selected_type === $type_key): ?>
            <form method="POST" id="appealForm" onsubmit="submitAppeal(event)">
                <?= appealCsrfField() ?>
                <?= appealHoneypotField('website_hp') ?>
                <input type="hidden" name="action" value="submit_appeal">
                <input type="hidden" name="appeal_type" value="<?= $type_key ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="contact_name" name="contact_name" placeholder="联系人" maxlength="50" required
                                   value="<?= $is_logged_in ? htmlspecialchars($current_user['username']) : htmlspecialchars($_POST['contact_name'] ?? '') ?>">
                            <label for="contact_name">联系人姓名 *</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="email" class="form-control" id="contact_email" name="contact_email" placeholder="联系邮箱" required
                                   value="<?= $is_logged_in ? htmlspecialchars($current_user['email'] ?? '') : htmlspecialchars($_POST['contact_email'] ?? '') ?>">
                            <label for="contact_email">联系邮箱 *</label>
                            <div class="form-text hint" style="padding: 0 15px;">审核结果将通过此邮箱通知</div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-floating">
                            <textarea class="form-control" id="reason" name="reason" placeholder="申诉理由" style="height: 160px" required minlength="10" maxlength="2000"><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
                            <label for="reason">申诉理由 *（10~2000字）</label>
                        </div>
                        <div class="form-text hint" style="padding: 0 15px; margin-top: 4px;">
                            请详细说明申诉原因，例如：您认为封禁是误操作、您已了解并遵守站规、您希望解除封禁继续使用等。
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100" id="submitBtn">
                            <i class="bi bi-send-fill me-2"></i>提交申诉
                        </button>
                    </div>
                    <div class="col-12 text-center">
                        <small class="text-muted">提交后管理员将在24小时内审核，请耐心等待</small>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- 已登录用户的申诉记录 -->
        <?php if ($is_logged_in && !empty($my_appeals)): ?>
        <div class="history-section">
            <h3><i class="bi bi-clock-history me-2"></i>我的申诉记录</h3>
            <?php foreach ($my_appeals as $appeal): ?>
            <div class="appeal-record" data-status="<?= $appeal['status'] ?>">
                <div class="record-header">
                    <span class="record-type">
                        <span class="badge bg-<?= $status_map[$appeal['status']]['class'] ?> me-1"><?= $status_map[$appeal['status']]['text'] ?></span>
                        <?= $type_map[$appeal['appeal_type']] ?>
                        <?php if ($appeal['appeal_type'] !== 'user'): ?>
                        <small class="text-muted ms-1">(IP: <?= htmlspecialchars($appeal['ip_address']) ?>)</small>
                        <?php endif; ?>
                    </span>
                    <span class="record-time"><?= date('Y-m-d H:i', strtotime($appeal['created_at'])) ?></span>
                </div>
                <div class="record-reason"><?= nl2br(htmlspecialchars($appeal['reason'])) ?></div>
                <?php if ($appeal['admin_reply']): ?>
                <div class="record-reply">
                    <strong><i class="bi bi-reply-fill me-1"></i>管理员回复：</strong><br>
                    <?= nl2br(htmlspecialchars($appeal['admin_reply'])) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php elseif ($is_logged_in && empty($my_appeals) && !$selected_type): ?>
        <div class="history-section">
            <h3><i class="bi bi-clock-history me-2"></i>我的申诉记录</h3>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <p>暂无申诉记录</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===================== 登录模态框 ===================== -->
    <div class="modal fade auth-modal" id="loginModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-in-right me-2"></i>账号登录</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="loginAlert"></div>
                    <form id="loginForm" onsubmit="doLogin(event)">
                        <?= appealCsrfField() ?>
                        <?= appealHoneypotField('login_hp') ?>
                        <input type="hidden" name="action" value="builtin_login">
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="loginUsername" name="username" placeholder="用户名" required>
                            <label for="loginUsername">用户名 / 邮箱</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="password" class="form-control" id="loginPassword" name="password" placeholder="密码" required>
                            <label for="loginPassword">密码</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" id="loginBtn">
                            <i class="bi bi-box-arrow-in-right me-2"></i>登录
                        </button>
                    </form>
                </div>
                <div class="modal-footer justify-content-center">
                    <div class="modal-footer-text">
                        还没有账号？<a onclick="switchAuthModal('register')">立即注册</a>
                        &nbsp;|&nbsp;
                        <a onclick="switchAuthModal('forgot')">忘记密码</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== 注册模态框 ===================== -->
    <div class="modal fade auth-modal" id="registerModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>创建账户</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="registerAlert"></div>
                    <form id="registerForm" onsubmit="doRegister(event)">
                        <?= appealCsrfField() ?>
                        <?= appealHoneypotField('reg_hp') ?>
                        <input type="hidden" name="action" value="builtin_register">
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="regUsername" name="username" placeholder="用户名" required minlength="3" maxlength="20">
                            <label for="regUsername">用户名 (3-20字符)</label>
                            <div id="regUsernameFeedback" class="form-text mt-1"></div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <div class="form-floating">
                                    <input type="password" class="form-control" id="regPassword" name="password" placeholder="密码" required minlength="6">
                                    <label for="regPassword">密码 (至少6位)</label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-floating">
                                    <input type="password" class="form-control" id="regConfirmPassword" name="confirm_password" placeholder="确认密码" required minlength="6">
                                    <label for="regConfirmPassword">确认密码</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="code-btn-group">
                                <div class="form-floating">
                                    <input type="email" class="form-control" id="regEmail" name="email" placeholder="邮箱" required>
                                    <label for="regEmail">邮箱地址</label>
                                </div>
                                <button type="button" class="btn btn-outline-primary" id="regCodeBtn" onclick="sendRegCode()" disabled>
                                    <span id="regCodeText">发送验证码</span>
                                </button>
                            </div>
                            <div id="regEmailFeedback" class="form-text mt-1"></div>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="regCode" name="verification_code" placeholder="验证码" required maxlength="6">
                            <label for="regCode">邮箱验证码</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" id="registerBtn">
                            <i class="bi bi-person-plus me-2"></i>注册
                        </button>
                    </form>
                </div>
                <div class="modal-footer justify-content-center">
                    <div class="modal-footer-text">
                        已有账号？<a onclick="switchAuthModal('login')">立即登录</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== 找回密码模态框 ===================== -->
    <div class="modal fade auth-modal" id="forgotModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-key me-2"></i>找回密码</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="forgotAlert"></div>
                    <div id="forgotStep1">
                        <p class="text-muted mb-3" style="font-size: 0.9rem;">请输入您的注册邮箱，我们将发送验证码以重置密码。</p>
                        <div class="form-floating mb-3">
                            <input type="email" class="form-control" id="forgotEmail" placeholder="邮箱" required>
                            <label for="forgotEmail">邮箱地址</label>
                        </div>
                        <button type="button" class="btn btn-primary w-100" id="forgotSendBtn" onclick="sendForgotCode()">
                            <i class="bi bi-envelope me-2"></i>发送验证码
                        </button>
                    </div>
                    <div id="forgotStep2" style="display:none;">
                        <div class="alert alert-info mb-3" style="font-size: 0.85rem;">
                            <i class="bi bi-info-circle-fill me-1"></i>
                            验证码已发送至 <strong id="forgotEmailDisplay"></strong>
                        </div>
                        <form onsubmit="doResetPassword(event)">
                            <?= appealCsrfField() ?>
                            <input type="hidden" name="action" value="builtin_reset_password">
                            <input type="hidden" id="resetEmailInput" name="email">
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="resetCode" name="verification_code" placeholder="验证码" required maxlength="6" pattern="[0-9]{6}">
                                <label for="resetCode">6位验证码</label>
                            </div>
                            <div class="form-floating mb-3">
                                <input type="password" class="form-control" id="resetNewPwd" name="new_password" placeholder="新密码" required minlength="6">
                                <label for="resetNewPwd">新密码 (至少6位)</label>
                            </div>
                            <div class="form-floating mb-3">
                                <input type="password" class="form-control" id="resetConfirmPwd" name="confirm_password" placeholder="确认新密码" required minlength="6">
                                <label for="resetConfirmPwd">确认新密码</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100" id="resetBtn">
                                <i class="bi bi-check-lg me-2"></i>重置密码
                            </button>
                        </form>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <div class="modal-footer-text">
                        <a onclick="switchAuthModal('login')"><i class="bi bi-arrow-left me-1"></i>返回登录</a>
                        &nbsp;|&nbsp;
                        <a onclick="switchAuthModal('register')">注册新账号</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
    // ===================== 申诉提交 =====================
    function submitAppeal(e) {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('submitBtn');
        const formData = new FormData(form);
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>提交中...';
        fetch('/vendor/appeal.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                btn.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>已提交';
                btn.classList.remove('btn-primary'); btn.classList.add('btn-success');
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 2000);
            } else {
                showToast(data.message, 'danger');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send-fill me-2"></i>提交申诉';
            }
        })
        .catch(() => {
            showToast('网络错误，请重试', 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send-fill me-2"></i>提交申诉';
        });
    }

    // ===================== Toast =====================
    function showToast(msg, type) {
        document.querySelectorAll('.toast-msg').forEach(el => el.remove());
        const toast = document.createElement('div');
        toast.className = 'toast-msg';
        toast.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99999;padding:12px 24px;border-radius:12px;font-size:14px;font-weight:500;opacity:0;transition:opacity 0.3s;max-width:90%;text-align:center;';
        if (type === 'success') { toast.style.background = '#dcfce7'; toast.style.color = '#166534'; toast.style.border = '1px solid #bbf7d0'; }
        else { toast.style.background = '#fef2f2'; toast.style.color = '#dc2626'; toast.style.border = '1px solid #fecaca'; }
        toast.textContent = msg;
        document.body.appendChild(toast);
        requestAnimationFrame(() => toast.style.opacity = '1');
        setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 4000);
    }

    // ===================== 模态框管理 =====================
    let loginModalEl, registerModalEl, forgotModalEl;
    let loginModal, registerModal, forgotModal;

    document.addEventListener('DOMContentLoaded', function() {
        loginModalEl = document.getElementById('loginModal');
        registerModalEl = document.getElementById('registerModal');
        forgotModalEl = document.getElementById('forgotModal');
        loginModal = new bootstrap.Modal(loginModalEl);
        registerModal = new bootstrap.Modal(registerModalEl);
        forgotModal = new bootstrap.Modal(forgotModalEl);

        // 注册表单实时检测
        const regUsername = document.getElementById('regUsername');
        const regEmail = document.getElementById('regEmail');
        let regUsernameTimer, regEmailTimer;

        if (regUsername) {
            regUsername.addEventListener('input', function() {
                clearTimeout(regUsernameTimer);
                const v = this.value.trim();
                const fb = document.getElementById('regUsernameFeedback');
                fb.textContent = ''; fb.className = 'form-text mt-1';
                if (v.length === 0) { updateRegCodeBtn(); return; }
                if (v.length < 3) { fb.textContent = '用户名长度至少3个字符'; fb.className = 'form-text mt-1 text-danger'; updateRegCodeBtn(); return; }
                fb.textContent = '检测中...'; fb.className = 'form-text mt-1 text-muted';
                regUsernameTimer = setTimeout(() => {
                    fetch('/vendor/appeal.php', { method: 'POST', body: new URLSearchParams({ action: 'builtin_check_username', username: v }) })
                    .then(r => r.json()).then(d => {
                        fb.textContent = (d.valid ? '\u2713 ' : '\u2717 ') + d.message;
                        fb.className = 'form-text mt-1 ' + (d.valid ? 'text-success' : 'text-danger');
                        updateRegCodeBtn();
                    }).catch(() => { fb.textContent = '检测失败'; fb.className = 'form-text mt-1 text-warning'; updateRegCodeBtn(); });
                }, 500);
            });
        }
        if (regEmail) {
            regEmail.addEventListener('input', function() {
                clearTimeout(regEmailTimer);
                const v = this.value.trim();
                const fb = document.getElementById('regEmailFeedback');
                fb.textContent = ''; fb.className = 'form-text mt-1';
                if (v.length === 0) { updateRegCodeBtn(); return; }
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { fb.textContent = '请输入有效的邮箱地址'; fb.className = 'form-text mt-1 text-danger'; updateRegCodeBtn(); return; }
                fb.textContent = '检测中...'; fb.className = 'form-text mt-1 text-muted';
                regEmailTimer = setTimeout(() => {
                    fetch('/vendor/appeal.php', { method: 'POST', body: new URLSearchParams({ action: 'builtin_check_email', email: v }) })
                    .then(r => r.json()).then(d => {
                        fb.textContent = (d.valid ? '\u2713 ' : '\u2717 ') + d.message;
                        fb.className = 'form-text mt-1 ' + (d.valid ? 'text-success' : 'text-danger');
                        updateRegCodeBtn();
                    }).catch(() => { fb.textContent = '检测失败'; fb.className = 'form-text mt-1 text-warning'; updateRegCodeBtn(); });
                }, 500);
            });
        }
    });

    function openAuthModal(type) {
        closeAllModals();
        if (type === 'login') loginModal.show();
        else if (type === 'register') registerModal.show();
        else if (type === 'forgot') forgotModal.show();
    }

    function switchAuthModal(type) {
        closeAllModals();
        setTimeout(() => openAuthModal(type), 150);
    }

    function closeAllModals() {
        if (loginModal) loginModal.hide();
        if (registerModal) registerModal.hide();
        if (forgotModal) forgotModal.hide();
    }

    function updateRegCodeBtn() {
        const btn = document.getElementById('regCodeBtn');
        if (!btn) return;
        const uFb = document.getElementById('regUsernameFeedback');
        const eFb = document.getElementById('regEmailFeedback');
        const uOk = uFb && uFb.classList.contains('text-success');
        const eOk = eFb && eFb.classList.contains('text-success');
        btn.disabled = !(uOk && eOk);
    }

    // ===================== 登录 =====================
    function doLogin(e) {
        e.preventDefault();
        const btn = document.getElementById('loginBtn');
        const alertDiv = document.getElementById('loginAlert');
        const form = document.getElementById('loginForm');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>登录中...';
        alertDiv.innerHTML = '';
        fetch('/vendor/appeal.php', { method: 'POST', body: new FormData(form) })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alertDiv.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i>登录成功，正在刷新...</div>';
                showToast(data.message, 'success');
                setTimeout(() => window.location.href = window.location.href, 1000);
            } else {
                alertDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-1"></i>' + data.message + '</div>';
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-box-arrow-in-right me-2"></i>登录';
            }
        })
        .catch(() => {
            alertDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-1"></i>网络错误，请重试</div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-box-arrow-in-right me-2"></i>登录';
        });
    }

    function doLogout() {
        fetch('/vendor/appeal.php', { method: 'POST', body: new URLSearchParams({ action: 'builtin_logout' }) })
        .then(() => window.location.href = window.location.href);
    }

    // ===================== 注册 =====================
    let regCodeCountdown = 0, regCodeInterval;

    function sendRegCode() {
        const btn = document.getElementById('regCodeBtn');
        const email = document.getElementById('regEmail').value.trim();
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showToast('请输入有效的邮箱地址', 'danger'); return; }
        if (regCodeCountdown > 0) return;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        fetch('/vendor/appeal.php', { method: 'POST', body: new URLSearchParams({ action: 'builtin_send_reg_code', email: email }) })
        .then(r => r.json())
        .then(data => {
            if (data.success) { showToast(data.message, 'success'); startRegCodeCountdown(); }
            else { showToast(data.message, 'danger'); btn.disabled = false; btn.innerHTML = '<span id="regCodeText">发送验证码</span>'; }
        })
        .catch(() => { showToast('发送失败，请重试', 'danger'); btn.disabled = false; btn.innerHTML = '<span id="regCodeText">发送验证码</span>'; });
    }

    function startRegCodeCountdown() {
        regCodeCountdown = 60;
        const btn = document.getElementById('regCodeBtn');
        if (!document.getElementById('regCodeText')) btn.innerHTML = '<span id="regCodeText"></span>';
        const txt = document.getElementById('regCodeText');
        txt.textContent = regCodeCountdown + '秒后重发';
        btn.disabled = true;
        regCodeInterval = setInterval(() => {
            regCodeCountdown--;
            if (regCodeCountdown <= 0) { clearInterval(regCodeInterval); txt.textContent = '重新发送'; btn.disabled = false; }
            else txt.textContent = regCodeCountdown + '秒后重发';
        }, 1000);
    }

    function doRegister(e) {
        e.preventDefault();
        const btn = document.getElementById('registerBtn');
        const alertDiv = document.getElementById('registerAlert');
        const form = document.getElementById('registerForm');
        const pwd = document.getElementById('regPassword').value;
        const cpwd = document.getElementById('regConfirmPassword').value;
        const code = document.getElementById('regCode').value;
        alertDiv.innerHTML = '';
        if (pwd !== cpwd) { alertDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-1"></i>两次输入的密码不一致</div>'; return; }
        if (code.length !== 6) { alertDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-1"></i>请输入6位验证码</div>'; return; }
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>注册中...';
        fetch('/vendor/appeal.php', { method: 'POST', body: new FormData(form) })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alertDiv.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i>' + data.message + '</div>';
                showToast(data.message, 'success');
                setTimeout(() => switchAuthModal('login'), 1500);
            } else {
                alertDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-1"></i>' + data.message + '</div>';
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-person-plus me-2"></i>注册';
            }
        })
        .catch(() => {
            alertDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-1"></i>注册失败，请重试</div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-person-plus me-2"></i>注册';
        });
    }

    // ===================== 找回密码 =====================
    let forgotCodeCountdown = 0, forgotCodeInterval;

    function sendForgotCode() {
        const email = document.getElementById('forgotEmail').value.trim();
        const alertDiv = document.getElementById('forgotAlert');
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showToast('请输入有效的邮箱地址', 'danger'); return; }
        if (forgotCodeCountdown > 0) return;
        const btn = document.getElementById('forgotSendBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>发送中...';
        alertDiv.innerHTML = '';
        fetch('/vendor/appeal.php', { method: 'POST', body: new URLSearchParams({ action: 'builtin_send_forgot_code', email: email }) })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('forgotEmailDisplay').textContent = email;
                document.getElementById('resetEmailInput').value = email;
                document.getElementById('forgotStep1').style.display = 'none';
                document.getElementById('forgotStep2').style.display = 'block';
                startForgotCodeCountdown();
            } else {
                alertDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-1"></i>' + data.message + '</div>';
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-envelope me-2"></i>发送验证码';
            }
        })
        .catch(() => {
            alertDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-1"></i>发送失败，请重试</div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-envelope me-2"></i>发送验证码';
        });
    }

    function startForgotCodeCountdown() {
        forgotCodeCountdown = 60;
        const btn = document.getElementById('forgotSendBtn');
        btn.disabled = true;
        btn.innerHTML = forgotCodeCountdown + '秒后重发';
        forgotCodeInterval = setInterval(() => {
            forgotCodeCountdown--;
            if (forgotCodeCountdown <= 0) { clearInterval(forgotCodeInterval); btn.disabled = false; btn.innerHTML = '<i class="bi bi-envelope me-2"></i>重新发送'; }
            else btn.innerHTML = forgotCodeCountdown + '秒后重发';
        }, 1000);
    }

    function doResetPassword(e) {
        e.preventDefault();
        const btn = document.getElementById('resetBtn');
        const alertDiv = document.getElementById('forgotAlert');
        const form = e.target;
        const pwd = document.getElementById('resetNewPwd').value;
        const cpwd = document.getElementById('resetConfirmPwd').value;
        const code = document.getElementById('resetCode').value;
        alertDiv.innerHTML = '';
        if (code.length !== 6) { alertDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-1"></i>请输入6位验证码</div>'; return; }
        if (pwd !== cpwd) { alertDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-1"></i>两次输入的密码不一致</div>'; return; }
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>重置中...';
        fetch('/vendor/appeal.php', { method: 'POST', body: new FormData(form) })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alertDiv.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i>' + data.message + '</div>';
                showToast(data.message, 'success');
                setTimeout(() => switchAuthModal('login'), 1500);
            } else {
                alertDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-1"></i>' + data.message + '</div>';
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-2"></i>重置密码';
            }
        })
        .catch(() => {
            alertDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill me-1"></i>重置失败，请重试</div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-2"></i>重置密码';
        });
    }

    // 验证码输入框只允许数字
    document.getElementById('regCode')?.addEventListener('input', function() { this.value = this.value.replace(/\D/g, '').slice(0, 6); });
    document.getElementById('resetCode')?.addEventListener('input', function() { this.value = this.value.replace(/\D/g, '').slice(0, 6); });
    </script>
</body>
<?php
/**
 * 发送申诉通知邮件给站长
 */
function sendAppealNotice($adminEmail, $studioName, $appealData) {
    if (defined('EMAIL_MODE') && EMAIL_MODE === 'test') {
        error_log("【测试模式】申诉通知邮件 - 收件人: {$adminEmail}, 类型: {$appealData['type']}");
        return true;
    }

    // 类型映射
    $typeMap = [
        'ip' => 'IP封禁申诉',
        'user' => '用户封禁申诉',
        'ip_user' => 'IP+用户封禁申诉'
    ];
    $appealTypeName = $typeMap[$appealData['type']] ?? '未知申诉';

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
        $mail->addAddress($adminEmail);

        $mail->isHTML(true);
        $mail->Subject = "【封禁申诉】{$appealData['contact_name']} - {$studioName}";
        
        $mail->Body = "
        <div style='font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 12px 12px 0 0;'>
                <h1 style='color: white; margin: 0; font-size: 24px;'>收到新的封禁申诉</h1>
            </div>
            <div style='background: white; padding: 30px; border-radius: 0 0 12px 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);'>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; font-weight: 600; width: 100px; color: #666;'>申诉类型</td>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee;'><strong style='color: #e74c3c;'>{$appealTypeName}</strong></td>
                    </tr>
                    <tr>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; font-weight: 600; color: #666;'>联系人</td>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee;'>{$appealData['contact_name']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; font-weight: 600; color: #666;'>联系邮箱</td>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee;'><a href='mailto:{$appealData['contact_email']}'>{$appealData['contact_email']}</a></td>
                    </tr>
                    <tr>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; font-weight: 600; color: #666;'>申诉IP</td>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee;'>{$appealData['ip_address']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee; font-weight: 600; color: #666;'>申诉理由</td>
                        <td style='padding: 12px 0; border-bottom: 1px solid #eee;'>" . nl2br(htmlspecialchars($appealData['reason'])) . "</td>
                    </tr>
                    <tr>
                        <td style='padding: 12px 0; font-weight: 600; color: #666;'>提交时间</td>
                        <td style='padding: 12px 0;'>" . date('Y-m-d H:i:s') . "</td>
                    </tr>
                </table>
                <div style='margin-top: 24px; text-align: center;'>
                    <a href='" . (defined('SITE_URL') ? SITE_URL : '') . "/vendor/appeal.php' style='display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-weight: 600;'>前往管理后台处理</a>
                </div>
            </div>
            <div style='text-align: center; padding: 15px; color: #999; font-size: 12px;'>
                此邮件由系统自动发送，请勿直接回复
            </div>
        </div>";

        $mail->AltBody = "收到新的封禁申诉！\n\n" .
            "申诉类型: {$appealTypeName}\n" .
            "联系人: {$appealData['contact_name']}\n" .
            "联系邮箱: {$appealData['contact_email']}\n" .
            "申诉IP: {$appealData['ip_address']}\n" .
            "申诉理由: {$appealData['reason']}\n" .
            "提交时间: " . date('Y-m-d H:i:s') . "\n\n" .
            "请登录管理后台处理此申诉。";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Appeal email send failed: ' . $e->getMessage());
        return false;
    }
}
?>
</html>
