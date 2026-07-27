<?php
/**
 * Auth 路由
 * 命名空间: v1
 *
 * /v1/auth/login     - 第一步：验证凭据，发送邮箱验证码 (POST)
 * /v1/auth/verify    - 第二步：验证邮箱验证码，完成登录 (POST)
 * /v1/auth/logout    - 登出 (POST)
 * /v1/auth/me        - 当前用户信息 (GET)
 *
 * 认证方式:
 *   1. 用户名/邮箱 + 密码 → 邮箱验证码 → Bearer Token
 *   2. Session Cookie（浏览器登录后自动携带）
 *   3. Bearer Token（Authorization 头，适合服务端/移动端）
 *      Authorization: Bearer <device_token>
 */

// =============================================
// 辅助：确保 email_verification 表存在
// =============================================
function v1_ensure_verification_table() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS `email_verification` (
      `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '验证码ID',
      `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '邮箱地址',
      `code` varchar(6) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '验证码',
      `purpose` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'register' COMMENT '用途',
      `is_used` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已使用',
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
      `expires_at` timestamp NOT NULL COMMENT '过期时间',
      `used_at` timestamp NULL DEFAULT NULL COMMENT '使用时间',
      PRIMARY KEY (`id`),
      UNIQUE KEY `email_purpose_unused` (`email`, `purpose`, `is_used`),
      KEY `idx_email_expires` (`email`, `expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮箱验证码表'");
}

/**
 * 邮箱脱敏显示: 26***@qq.com
 */
function v1_mask_email($email) {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    $parts = explode('@', $email);
    $name  = $parts[0];
    $domain = $parts[1];
    $len   = mb_strlen($name);
    if ($len <= 2) {
        $masked = $name[0] . '***';
    } else {
        $masked = mb_substr($name, 0, 2) . '***';
    }
    return $masked . '@' . $domain;
}

// =============================================
// POST /v1/auth/login - 第一步：验证凭据，发验证码
// =============================================
register_rest_route('v1', '/auth/login', [
    'methods'  => 'POST',
    'callback' => 'v1_auth_login',
]);

function v1_auth_login($request) {
    $username = trim($request->get_param('username') ?: '');
    $password = $request->get_param('password') ?: '';

    if (empty($username) || empty($password)) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_fields',
            'message' => '用户名/邮箱和密码不能为空',
            'data'    => ['status' => 400],
        ], 400);
    }

    // LY-005: 登录速率限制 — 每IP每5分钟最多10次登录尝试
    $rateLimit = checkRateLimit('api_login_attempt', 10, 300);
    if (!$rateLimit['allowed']) {
        return new Nova_REST_Response([
            'code'    => 'rest_rate_limited',
            'message' => '登录尝试过于频繁，请稍后再试',
            'data'    => ['status' => 429],
        ], 429);
    }

    // 用户名级速率限制 — 连续5次失败锁定15分钟
    $userRateLimit = checkRateLimit('api_login_user_' . md5(strtolower($username)), 5, 900);
    if (!$userRateLimit['allowed']) {
        return new Nova_REST_Response([
            'code'    => 'rest_rate_limited',
            'message' => '该账号登录尝试过于频繁，请稍后再试',
            'data'    => ['status' => 429],
        ], 429);
    }

    $db = getDB();

    // 支持用户名或邮箱登录
    $stmt = $db->prepare("SELECT * FROM admins WHERE (username = ? OR email = ?) LIMIT 1");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user) {
        return new Nova_REST_Response([
            'code'    => 'rest_invalid_login',
            'message' => '用户名/邮箱或密码错误',
            'data'    => ['status' => 401],
        ], 401);
    }

    // 检查封禁
    if (!empty($user['is_banned'])) {
        return new Nova_REST_Response([
            'code'    => 'rest_user_banned',
            'message' => '账号已被封禁',
            'data'    => ['status' => 403],
        ], 403);
    }

    // 验证密码 (bcrypt)
    if (!password_verify($password, $user['password'])) {
        recordLoginFailure($user['id'], '密码错误');

        return new Nova_REST_Response([
            'code'    => 'rest_invalid_login',
            'message' => '用户名/邮箱或密码错误',
            'data'    => ['status' => 401],
        ], 401);
    }

    // ── 密码正确，发送邮箱验证码 ──
    $email = $user['email'] ?? '';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return new Nova_REST_Response([
            'code'    => 'rest_no_email',
            'message' => '该账号未绑定邮箱，无法完成二次验证',
            'data'    => ['status' => 400],
        ], 400);
    }

    // 速率限制检查
    $rateLimit = checkRateLimit('email_code_login', 3, 300); // 5 分钟内最多 3 次
    if (!$rateLimit['allowed']) {
        return new Nova_REST_Response([
            'code'    => 'rest_rate_limited',
            'message' => '验证码发送过于频繁，请稍后再试',
            'data'    => ['status' => 429],
        ], 429);
    }

    v1_ensure_verification_table();

    // 先检查是否有未过期的验证码（避免频繁生成）
    $existing = $db->prepare(
        "SELECT id, code FROM email_verification
         WHERE email = ? AND purpose = 'login' AND is_used = 0
         AND expires_at > DATE_ADD(NOW(), INTERVAL 5 MINUTE)
         ORDER BY created_at DESC LIMIT 1"
    );
    $existing->execute([$email]);
    $existingRow = $existing->fetch();

    if ($existingRow) {
        $code = $existingRow['code'];
    } else {
        // 清理旧验证码
        $db->prepare("DELETE FROM email_verification WHERE email = ? AND purpose = 'login'")
           ->execute([$email]);

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 分钟过期

        $insert = $db->prepare(
            "INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'login', ?)"
        );
        $insert->execute([$email, $code, $expiresAt]);
    }

    // 发送验证码邮件
    $sendResult = sendVerificationEmail($email, $code);

    return [
        'code'    => 'rest_ok',
        'message' => '验证码已发送到您的邮箱',
        'data'    => [
            'status'               => 200,
            'requires_verification' => true,
            'email_hint'           => v1_mask_email($email),
        ],
    ];
}

// =============================================
// POST /v1/auth/verify - 第二步：验证邮箱验证码，完成登录
// =============================================
register_rest_route('v1', '/auth/verify', [
    'methods'  => 'POST',
    'callback' => 'v1_auth_verify',
]);

function v1_auth_verify($request) {
    $username = trim($request->get_param('username') ?: '');
    $code     = trim($request->get_param('code') ?: '');

    if (empty($username) || empty($code)) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_fields',
            'message' => '用户名/邮箱和验证码不能为空',
            'data'    => ['status' => 400],
        ], 400);
    }

    $db = getDB();

    // 查找用户
    $stmt = $db->prepare("SELECT id, username, email, role FROM admins WHERE (username = ? OR email = ?) AND is_banned = 0 LIMIT 1");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user) {
        return new Nova_REST_Response([
            'code'    => 'rest_invalid_login',
            'message' => '用户不存在',
            'data'    => ['status' => 401],
        ], 401);
    }

    $email = $user['email'] ?? '';

    // 验证验证码
    $stmt = $db->prepare(
        "SELECT id FROM email_verification
         WHERE email = ? AND code = ? AND purpose = 'login' AND is_used = 0
         AND expires_at > NOW()
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$email, $code]);
    $verRow = $stmt->fetch();

    if (!$verRow) {
        return new Nova_REST_Response([
            'code'    => 'rest_invalid_code',
            'message' => '验证码错误或已过期',
            'data'    => ['status' => 401],
        ], 401);
    }

    // 标记验证码已使用
    $db->prepare("UPDATE email_verification SET is_used = 1, used_at = NOW() WHERE id = ?")
       ->execute([$verRow['id']]);

    // ── 验证通过，完成登录 ──
    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_email']    = $email;
    $_SESSION['user_role']     = $user['role'];

    // 记住我功能：读取前端传参，默认 false（不记住）
    $rememberMe = filter_var($request->get_param('remember_me'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

    // 创建设备 Token（用于 API Bearer 认证）
    $deviceToken = createSession($user['id'], $rememberMe);

    // LY-006: 同时设置 HttpOnly Cookie（防止 JS 读取，降低 XSS 窃取风险）
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    $rememberDuration = (int)getSiteConfigValue('remember_duration', 30);
    $cookieExpire = $rememberMe ? time() + $rememberDuration * 86400 : 0;
    setcookie('nova_token', $deviceToken, [
        'expires'  => $cookieExpire,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    // 更新最后登录时间
    $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    return [
        'code'    => 'rest_ok',
        'message' => '登录成功',
        'data'    => [
            'status' => 200,
            'token'  => $deviceToken,
            'user'   => [
                'id'       => (int)$user['id'],
                'username' => $user['username'],
                'email'    => $email,
                'role'     => $user['role'],
            ],
        ],
    ];
}

// =============================================
// POST /v1/auth/register - 注册：验证凭据，发送邮箱验证码
// =============================================
register_rest_route('v1', '/auth/register', [
    'methods'  => 'POST',
    'callback' => 'v1_auth_register',
]);

function v1_auth_register($request) {
    $username = trim(strip_tags($request->get_param('username') ?: ''));
    $email    = trim(strip_tags($request->get_param('email') ?: ''));
    $password = $request->get_param('password') ?: '';

    // ── 校验 ──
    if (empty($username) || empty($email) || empty($password)) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_fields',
            'message' => '用户名、邮箱和密码不能为空',
            'data'    => ['status' => 400],
        ], 400);
    }

    if (strlen($username) < 2 || strlen($username) > 20) {
        return new Nova_REST_Response([
            'code'    => 'rest_invalid_username',
            'message' => '用户名长度需在 2-20 个字符之间',
            'data'    => ['status' => 400],
        ], 400);
    }

    if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
        return new Nova_REST_Response([
            'code'    => 'rest_invalid_username',
            'message' => '用户名只能包含字母、数字、下划线和中文',
            'data'    => ['status' => 400],
        ], 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return new Nova_REST_Response([
            'code'    => 'rest_invalid_email',
            'message' => '邮箱格式无效',
            'data'    => ['status' => 400],
        ], 400);
    }

    // 检查邮箱域名是否在允许注册的列表中
    $pos = strrpos($email, '@');
    if ($pos === false) {
        return new Nova_REST_Response([
            'code'    => 'rest_invalid_email',
            'message' => '邮箱格式无效',
            'data'    => ['status' => 400],
        ], 400);
    }
    $domain = strtolower(substr($email, $pos + 1));
    $db = getDB();
    $config = $db->query("SELECT allowed_email_domains FROM website_config LIMIT 1")->fetch();
    $allowedDomains = [];
    if ($config && !empty($config['allowed_email_domains'])) {
        $allowedDomains = array_map('trim', explode(',', strtolower($config['allowed_email_domains'])));
    } else {
        $allowedDomains = ['qq.com','vip.qq.com','foxmail.com','163.com','126.com','yeah.net','sina.com','sina.cn','sohu.com','139.com','aliyun.com','gmail.com','outlook.com','hotmail.com','live.com','yahoo.com','yahoo.co.jp','icloud.com','proton.me','protonmail.com','mail.com','gmx.com','gmx.de'];
    }
    if (!in_array($domain, $allowedDomains)) {
        return new Nova_REST_Response([
            'code'    => 'rest_email_not_allowed',
            'message' => '该邮箱后缀不被允许注册，支持的邮箱：' . implode('、', $allowedDomains),
            'data'    => ['status' => 400, 'allowed_domains' => $allowedDomains],
        ], 400);
    }

    if (strlen($password) < 6) {
        return new Nova_REST_Response([
            'code'    => 'rest_weak_password',
            'message' => '密码长度不能少于 6 位',
            'data'    => ['status' => 400],
        ], 400);
    }

    // 速率限制
    $rateLimit = checkRateLimit('api_register', 3, 300);
    if (!$rateLimit['allowed']) {
        return new Nova_REST_Response([
            'code'    => 'rest_rate_limited',
            'message' => '注册过于频繁，请稍后再试',
            'data'    => ['status' => 429],
        ], 429);
    }

    // 检查用户名是否已存在
    $stmt = $db->prepare("SELECT id FROM admins WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return new Nova_REST_Response([
            'code'    => 'rest_duplicate_username',
            'message' => '用户名已被使用',
            'data'    => ['status' => 409],
        ], 409);
    }

    // 检查邮箱是否已存在
    $stmt = $db->prepare("SELECT id FROM admins WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return new Nova_REST_Response([
            'code'    => 'rest_duplicate_email',
            'message' => '邮箱已被注册',
            'data'    => ['status' => 409],
        ], 409);
    }

    // 发送邮箱验证码
    $codeRateLimit = checkRateLimit('email_code_register', 3, 300);
    if (!$codeRateLimit['allowed']) {
        return new Nova_REST_Response([
            'code'    => 'rest_rate_limited',
            'message' => '验证码发送过于频繁，请稍后再试',
            'data'    => ['status' => 429],
        ], 429);
    }

    v1_ensure_verification_table();

    // 检查是否有未过期的验证码
    $existing = $db->prepare(
        "SELECT id, code FROM email_verification
         WHERE email = ? AND purpose = 'register' AND is_used = 0
         AND expires_at > DATE_ADD(NOW(), INTERVAL 5 MINUTE)
         ORDER BY created_at DESC LIMIT 1"
    );
    $existing->execute([$email]);
    $existingRow = $existing->fetch();

    if ($existingRow) {
        $code = $existingRow['code'];
    } else {
        $db->prepare("DELETE FROM email_verification WHERE email = ? AND purpose = 'register'")
           ->execute([$email]);

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + 600);

        $insert = $db->prepare(
            "INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'register', ?)"
        );
        $insert->execute([$email, $code, $expiresAt]);
    }

    // 发送验证码邮件
    $sendResult = sendVerificationEmail($email, $code);

    return [
        'code'    => 'rest_ok',
        'message' => '验证码已发送到您的邮箱',
        'data'    => [
            'status'  => 200,
            'email_hint' => v1_mask_email($email),
        ],
    ];
}

// =============================================
// POST /v1/auth/register-verify - 注册：验证邮箱验证码，创建用户
// =============================================
register_rest_route('v1', '/auth/register-verify', [
    'methods'  => 'POST',
    'callback' => 'v1_auth_register_verify',
]);

function v1_auth_register_verify($request) {
    $username = trim(strip_tags($request->get_param('username') ?: ''));
    $email    = trim(strip_tags($request->get_param('email') ?: ''));
    $code     = trim($request->get_param('code') ?: '');
    $password = $request->get_param('password') ?: '';

    if (empty($username) || empty($email) || empty($code) || empty($password)) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_fields',
            'message' => '参数不完整',
            'data'    => ['status' => 400],
        ], 400);
    }

    $db = getDB();

    // 再次检查用户名和邮箱是否仍可用（防止并发注册）
    $stmt = $db->prepare("SELECT id FROM admins WHERE username = ? OR email = ? LIMIT 1");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        return new Nova_REST_Response([
            'code'    => 'rest_duplicate',
            'message' => '用户名或邮箱已被使用',
            'data'    => ['status' => 409],
        ], 409);
    }

    // 验证验证码
    $stmt = $db->prepare(
        "SELECT id FROM email_verification
         WHERE email = ? AND code = ? AND purpose = 'register' AND is_used = 0
         AND expires_at > NOW()
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$email, $code]);
    $verRow = $stmt->fetch();

    if (!$verRow) {
        return new Nova_REST_Response([
            'code'    => 'rest_invalid_code',
            'message' => '验证码错误或已过期',
            'data'    => ['status' => 401],
        ], 401);
    }

    // 标记验证码已使用
    $db->prepare("UPDATE email_verification SET is_used = 1, used_at = NOW() WHERE id = ?")
       ->execute([$verRow['id']]);

    // 创建用户
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $registerIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (strpos($registerIp, ',') !== false) {
        $registerIp = trim(explode(',', $registerIp)[0]);
    }

    // 确保 register_ip 字段存在
    $columns = $db->query("SHOW COLUMNS FROM admins LIKE 'register_ip'")->fetch();
    if (!$columns) {
        $db->exec("ALTER TABLE admins ADD COLUMN register_ip VARCHAR(45) DEFAULT '' COMMENT '注册IP'");
    }

    $stmt = $db->prepare("INSERT INTO admins (username, password, email, role, register_ip) VALUES (?, ?, ?, 'user', ?)");
    $stmt->execute([$username, $hashedPassword, $email, $registerIp]);
    $newId = (int)$db->lastInsertId();

    if ($newId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_register_failed',
            'message' => '注册失败，请重试',
            'data'    => ['status' => 500],
        ], 500);
    }

    return [
        'code'    => 'rest_ok',
        'message' => '注册成功',
        'data'    => ['status' => 201, 'id' => $newId, 'username' => $username],
    ];
}

// =============================================
// POST /v1/auth/logout - 登出
// =============================================
register_rest_route('v1', '/auth/logout', [
    'methods'  => 'POST',
    'callback' => 'v1_auth_logout',
]);

function v1_auth_logout($request) {
    $userId = v1_get_current_user_id();

    if ($userId <= 0) {
        return [
            'code'    => 'rest_ok',
            'message' => '已登出',
            'data'    => ['status' => 200],
        ];
    }

    $db = getDB();

    // 如果是 Bearer Token 认证，失效该 Token
    $token = v1_get_bearer_token();
    if ($token) {
        $db->prepare("UPDATE user_sessions SET is_active = 0, deleted_by_user = 1 WHERE device_token = ? AND user_id = ?")
           ->execute([$token, $userId]);
    }

    // 清除 Session
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    session_destroy();

    // LY-006: 清除 nova_token HttpOnly Cookie
    setcookie('nova_token', '', time() - 3600, '/');

    // 清除 device_token Cookie
    setcookie('device_token', '', time() - 3600, '/');

    return [
        'code'    => 'rest_ok',
        'message' => '已登出',
        'data'    => ['status' => 200],
    ];
}

// =============================================
// GET /v1/auth/me - 当前用户信息
// =============================================
register_rest_route('v1', '/auth/me', [
    'methods'  => 'GET',
    'callback' => 'v1_auth_me',
]);

function v1_auth_me($request) {
    $userId = v1_get_current_user_id();
    if ($userId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_not_logged_in',
            'message' => '未登录',
            'data'    => ['status' => 401],
        ], 401);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, email, role, is_banned, register_ip, last_login, created_at FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return new Nova_REST_Response([
            'code'    => 'rest_user_not_found',
            'message' => '用户不存在',
            'data'    => ['status' => 404],
        ], 404);
    }

    // 查询最近登录 IP
    $ipStmt = $db->prepare("SELECT DISTINCT ip_address FROM user_sessions WHERE user_id = ? AND ip_address != 'unknown' ORDER BY login_at DESC LIMIT 5");
    $ipStmt->execute([$userId]);
    $recentIps = $ipStmt->fetchAll(PDO::FETCH_COLUMN);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => ['status' => 200, 'user' => [
            'id'           => (int)$user['id'],
            'username'     => $user['username'],
            'email'        => $user['email'],
            'role'         => $user['role'],
            'is_banned'    => (bool)$user['is_banned'],
            'register_ip'  => $user['register_ip'] ?: '',
            'last_login'   => $user['last_login'],
            'created_at'   => $user['created_at'],
            'recent_ips'   => $recentIps,
        ]],
    ];
}

// =============================================
// GET /v1/auth/devices - 当前用户的设备列表
// =============================================
register_rest_route('v1', '/auth/devices', [
    'methods'  => 'GET',
    'callback' => 'v1_auth_devices',
]);

function v1_auth_devices($request) {
    $userId = v1_get_current_user_id();
    if ($userId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_not_logged_in',
            'message' => '未登录',
            'data'    => ['status' => 401],
        ], 401);
    }

    $db = getDB();
    $currentToken = v1_get_bearer_token();

    $stmt = $db->prepare("SELECT id, device_token, device_name, ip_address, login_at, last_active_at, is_current
        FROM user_sessions
        WHERE user_id = ? AND is_active = 1 AND status = 'success'
        ORDER BY last_active_at DESC");
    $stmt->execute([$userId]);
    $devices = $stmt->fetchAll();

    $items = [];
    foreach ($devices as $d) {
        $isCurrent = $d['device_token'] === $currentToken || !empty($d['is_current']);
        $items[] = [
            'id'             => (int)$d['id'],
            'device_token'   => substr($d['device_token'], 0, 8) . '...',
            'device_name'    => $d['device_name'] ?: '未知设备',
            'ip_address'     => $d['ip_address'] ?: '',
            'login_at'       => $d['login_at'],
            'last_active_at' => $d['last_active_at'],
            'is_current'     => (bool)$isCurrent,
        ];
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => ['status' => 200, 'items' => $items],
    ];
}

// =============================================
// POST /v1/auth/devices/logout - 登出指定设备
// =============================================
register_rest_route('v1', '/auth/devices/logout', [
    'methods'  => 'POST, OPTIONS',
    'callback' => 'v1_auth_device_logout',
]);

function v1_auth_device_logout($request) {
    if ($request->get_method() === 'OPTIONS') {
        return new Nova_REST_Response(['code' => 'rest_ok', 'message' => 'OK', 'data' => ['status' => 204]], 204);
    }

    $userId = v1_get_current_user_id();
    if ($userId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_not_logged_in',
            'message' => '未登录',
            'data'    => ['status' => 401],
        ], 401);
    }

    $deviceToken = trim($request->get_param('device_token') ?: '');
    if (empty($deviceToken)) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_param',
            'message' => '缺少 device_token',
            'data'    => ['status' => 400],
        ], 400);
    }

    // 防止登出当前设备（应使用 /auth/logout）
    $currentToken = v1_get_bearer_token();
    if ($deviceToken === $currentToken) {
        return new Nova_REST_Response([
            'code'    => 'rest_cannot_logout_current',
            'message' => '不能通过此接口登出当前设备，请使用 /auth/logout',
            'data'    => ['status' => 400],
        ], 400);
    }

    $db = getDB();
    $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0, deleted_by_user = 1 WHERE device_token = ? AND user_id = ? AND is_active = 1");
    $stmt->execute([$deviceToken, $userId]);

    if ($stmt->rowCount() > 0) {
        return [
            'code'    => 'rest_ok',
            'message' => '设备已登出',
            'data'    => ['status' => 200],
        ];
    }

    return new Nova_REST_Response([
        'code'    => 'rest_device_not_found',
        'message' => '未找到该设备或已下线',
        'data'    => ['status' => 404],
    ], 404);
}

// =============================================
// POST /v1/auth/forgot-password - 忘记密码：发送验证码
// =============================================
register_rest_route('v1', '/auth/forgot-password', [
    'methods'  => 'POST, OPTIONS',
    'callback' => 'v1_auth_forgot_password',
]);

function v1_auth_forgot_password($request) {
    if ($request->get_method() === 'OPTIONS') {
        return new Nova_REST_Response(['code' => 'rest_ok', 'message' => 'OK', 'data' => ['status' => 204]], 204);
    }

    $username = trim($request->get_param('username') ?: '');
    if (empty($username)) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_fields',
            'message' => '请输入用户名或邮箱',
            'data'    => ['status' => 400],
        ], 400);
    }

    // 频率限制
    $rateLimit = checkRateLimit('api_forgot_password', 3, 300);
    if (!$rateLimit['allowed']) {
        return new Nova_REST_Response([
            'code'    => 'rest_rate_limited',
            'message' => '操作过于频繁，请稍后再试',
            'data'    => ['status' => 429],
        ], 429);
    }

    $db = getDB();

    // 查找用户
    $stmt = $db->prepare("SELECT id, username, email FROM admins WHERE (username = ? OR email = ?) AND is_banned = 0 LIMIT 1");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    // 无论是否存在，统一返回（防枚举）
    if (!$user || empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        return [
            'code'    => 'rest_ok',
            'message' => '如果该账号存在且已绑定邮箱，验证码已发送',
            'data'    => ['status' => 200],
        ];
    }

    $email = $user['email'];

    v1_ensure_verification_table();

    // 检查是否有未过期的验证码
    $existing = $db->prepare(
        "SELECT id, code FROM email_verification
         WHERE email = ? AND purpose = 'reset_password' AND is_used = 0
         AND expires_at > DATE_ADD(NOW(), INTERVAL 5 MINUTE)
         ORDER BY created_at DESC LIMIT 1"
    );
    $existing->execute([$email]);
    $existingRow = $existing->fetch();

    if ($existingRow) {
        $code = $existingRow['code'];
    } else {
        // 清理旧验证码
        $db->prepare("DELETE FROM email_verification WHERE email = ? AND purpose = 'reset_password'")
           ->execute([$email]);

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + 600);

        $insert = $db->prepare(
            "INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'reset_password', ?)"
        );
        $insert->execute([$email, $code, $expiresAt]);
    }

    // 发送验证码
    sendVerificationEmail($email, $code);

    return [
        'code'    => 'rest_ok',
        'message' => '如果该账号存在且已绑定邮箱，验证码已发送',
        'data'    => ['status' => 200],
    ];
}

// =============================================
// POST /v1/auth/reset-password - 忘记密码：验证码重置密码
// =============================================
register_rest_route('v1', '/auth/reset-password', [
    'methods'  => 'POST, OPTIONS',
    'callback' => 'v1_auth_reset_password',
]);

function v1_auth_reset_password($request) {
    if ($request->get_method() === 'OPTIONS') {
        return new Nova_REST_Response(['code' => 'rest_ok', 'message' => 'OK', 'data' => ['status' => 204]], 204);
    }

    $username = trim($request->get_param('username') ?: '');
    $code     = trim($request->get_param('code') ?: '');
    $password = $request->get_param('password') ?: '';

    if (empty($username) || empty($code) || empty($password)) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_fields',
            'message' => '用户名/邮箱、验证码和新密码不能为空',
            'data'    => ['status' => 400],
        ], 400);
    }

    if (strlen($password) < 6) {
        return new Nova_REST_Response([
            'code'    => 'rest_weak_password',
            'message' => '密码长度不能少于6位',
            'data'    => ['status' => 400],
        ], 400);
    }

    $db = getDB();

    // 查找用户
    $stmt = $db->prepare("SELECT id, email FROM admins WHERE (username = ? OR email = ?) AND is_banned = 0 LIMIT 1");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user || empty($user['email'])) {
        return new Nova_REST_Response([
            'code'    => 'rest_invalid_request',
            'message' => '验证失败',
            'data'    => ['status' => 400],
        ], 400);
    }

    $email = $user['email'];

    // 验证验证码
    $stmt = $db->prepare(
        "SELECT id FROM email_verification
         WHERE email = ? AND code = ? AND purpose = 'reset_password' AND is_used = 0
         AND expires_at > NOW()
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$email, $code]);
    $verRow = $stmt->fetch();

    if (!$verRow) {
        return new Nova_REST_Response([
            'code'    => 'rest_invalid_code',
            'message' => '验证码错误或已过期',
            'data'    => ['status' => 401],
        ], 401);
    }

    // 标记验证码已使用
    $db->prepare("UPDATE email_verification SET is_used = 1, used_at = NOW() WHERE id = ?")
       ->execute([$verRow['id']]);

    // 更新密码
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE admins SET password = ? WHERE id = ?")->execute([$hash, $user['id']]);

    // 使所有设备下线（密码已变，旧 Token 应失效）
    $db->prepare("UPDATE user_sessions SET is_active = 0, deleted_by_user = 1 WHERE user_id = ? AND is_active = 1")
       ->execute([$user['id']]);

    // 销毁当前 Session
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    session_destroy();

    return [
        'code'    => 'rest_ok',
        'message' => '密码已重置，请重新登录',
        'data'    => ['status' => 200],
    ];
}

// =============================================
// 辅助函数：从请求头提取 Bearer Token
// =============================================
function v1_get_bearer_token() {
    $headers = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $headers = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        if (isset($requestHeaders['Authorization'])) {
            $headers = $requestHeaders['Authorization'];
        }
    }

    if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
        return $matches[1];
    }

    return '';
}
