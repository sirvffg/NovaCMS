<?php
/**
 * 账户模块共享函数。
 *
 * 这里只放无页面输出的账户规则和会话辅助逻辑，供注册、登录和用户中心复用。
 */

function getDefaultAllowedEmailDomains() {
    return [
        'qq.com', 'vip.qq.com', 'foxmail.com', '163.com', '126.com', 'yeah.net',
        'sina.com', 'sina.cn', 'sohu.com', '139.com', 'aliyun.com', 'gmail.com',
        'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com', 'yahoo.co.jp',
        'icloud.com', 'proton.me', 'protonmail.com', 'mail.com', 'gmx.com', 'gmx.de',
    ];
}

function getAllowedEmailDomains() {
    static $domains = null;
    if ($domains !== null) {
        return $domains;
    }

    $configured = '';
    try {
        $configured = (string)getSiteConfigValue('allowed_email_domains', '');
    } catch (Exception $e) {
        error_log('Unable to read allowed email domains: ' . $e->getMessage());
    }

    $candidates = $configured !== '' ? explode(',', $configured) : getDefaultAllowedEmailDomains();
    $domains = [];
    foreach ($candidates as $candidate) {
        $domain = strtolower(trim((string)$candidate));
        $domain = ltrim($domain, '.');
        if ($domain !== '' && preg_match('/^[a-z0-9.-]+$/', $domain)) {
            $domains[] = $domain;
        }
    }

    $domains = array_values(array_unique($domains));
    if (empty($domains)) {
        $domains = getDefaultAllowedEmailDomains();
    }
    return $domains;
}

function isAllowedEmailDomain($email) {
    $email = trim((string)$email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $separator = strrpos($email, '@');
    if ($separator === false) {
        return false;
    }
    $domain = strtolower(substr($email, $separator + 1));

    foreach (getAllowedEmailDomains() as $allowedDomain) {
        if ($domain === $allowedDomain || substr($domain, -strlen('.' . $allowedDomain)) === '.' . $allowedDomain) {
            return true;
        }
    }
    return false;
}

function accountTextLength($value) {
    $value = (string)$value;
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    return preg_match_all('/./us', $value, $matches) ?: 0;
}

function accountLowercase($value) {
    $value = (string)$value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function isValidUsername($username) {
    $username = trim((string)$username);
    $length = accountTextLength($username);
    if ($length < 3 || $length > 20) {
        return ['valid' => false, 'message' => '用户名长度应在3-20个字符之间'];
    }

    if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_]+$/u', $username)) {
        return ['valid' => false, 'message' => '用户名只能包含中文、字母、数字和下划线'];
    }
    if (preg_match('/^\d/', $username)) {
        return ['valid' => false, 'message' => '用户名不能以数字开头'];
    }
    if ($username[0] === '_' || substr($username, -1) === '_') {
        return ['valid' => false, 'message' => '用户名不能以下划线开头或结尾'];
    }
    if (strpos($username, '__') !== false) {
        return ['valid' => false, 'message' => '用户名不能包含连续的下划线'];
    }

    $reserved = ['admin', '管理员', 'root', 'system', '系统', 'test', '测试', 'guest', '游客', 'null', 'undefined'];
    $reserved = array_map('accountLowercase', $reserved);
    if (in_array(accountLowercase($username), $reserved, true)) {
        return ['valid' => false, 'message' => '该用户名已被保留，请使用其他名称'];
    }
    return ['valid' => true, 'message' => ''];
}

function accountJsonResponse(array $payload, $statusCode = 200) {
    http_response_code((int)$statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function expireAuthCookie($name) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    setcookie($name, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[$name]);
}

function logoutAuthenticatedUser() {
    if (isset($_SESSION['user_id'])) {
        logoutCurrentDevice((int)$_SESSION['user_id']);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => !empty($params['secure']),
            'httponly' => !empty($params['httponly']),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    expireAuthCookie('device_token');
    expireAuthCookie('nova_token');
}
