<?php
// 数据库配置：生产环境优先使用 NOVACMS_DB_* 环境变量，默认值保持向后兼容。
// 优化点：
// 1. 环境变量读取兼容 getenv / $_ENV / $_SERVER，适配 1Panel/Docker 等环境
// 2. 连接前校验 pdo_mysql 扩展与配置格式（如用户名误带 @host），给出可操作的错误提示
// 3. 按 SQLSTATE/错误码分类异常，日志与用户提示分离，便于排查又避免泄露敏感信息

function novaDatabaseEnv($key, $default) {
    // 优先 getenv，再回退到 $_ENV / $_SERVER（部分容器环境只注入其中之一）
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
    }
    return $value === '' ? $default : $value;
}

/**
 * 规范化数据库用户名：兼容误把 'user'@'host' 整体填入的情况，
 * 自动取 @ 之前的部分（MySQL 用户名本身不含 @，@ 后由服务端按 host 匹配）。
 * 发生修正时写 error_log 提示，便于运维定位配置来源。
 */
function novaDbNormalizeUser(string $user): string {
    $user = trim($user);
    $atPos = strpos($user, '@');
    if ($atPos !== false) {
        $normalized = substr($user, 0, $atPos);
        error_log("数据库用户名已自动修正: '{$user}' -> '{$normalized}'（已去掉 @host 部分，建议修改配置）");
        return $normalized;
    }
    return $user;
}

define('DB_HOST', novaDatabaseEnv('NOVACMS_DB_HOST', 'localhost'));
define('DB_PORT', (int)novaDatabaseEnv('NOVACMS_DB_PORT', 3306));
define('DB_NAME', novaDatabaseEnv('NOVACMS_DB_NAME', 'blog'));
define('DB_USER', novaDbNormalizeUser(novaDatabaseEnv('NOVACMS_DB_USER', 'blog')));
define('DB_PASS', novaDatabaseEnv('NOVACMS_DB_PASS', 'admin'));
define('DB_CHARSET', novaDatabaseEnv('NOVACMS_DB_CHARSET', 'utf8mb4'));

/**
 * 输出数据库错误并终止：AJAX 返回 JSON，普通请求 die 文本。
 * 详细信息写 error_log，对用户只暴露通用提示。
 */
function novaDbAbort(string $reason, string $userHint = '数据库连接失败，请稍后重试或联系管理员') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    error_log("数据库连接失败: {$reason} | IP: {$ip} | Time: " . date('Y-m-d H:i:s'));

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || isset($_POST['ajax']) || isset($_GET['ajax'])
        || (!empty($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($isAjax) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $userHint]);
        exit;
    }
    die($userHint);
}

/**
 * 根据 PDOException 返回可读的中文原因（仅用于日志，不直接暴露给用户）。
 */
function novaDbExplainError(PDOException $e): string {
    $msg = $e->getMessage();
    $code = (string)$e->getCode();
    // SQLSTATE 或驱动错误码
    if (strpos($msg, 'could not find driver') !== false) {
        return 'PHP 未启用 pdo_mysql 扩展（请在 1Panel/PHP 环境安装 pdo_mysql 并重载 PHP-FPM）';
    }
    if (strpos($msg, 'No connection could be made') !== false || $code === '2002') {
        return "无法连接到数据库主机 " . DB_HOST . ":" . DB_PORT . "（请检查主机名/端口/容器网络）";
    }
    if (strpos($msg, 'Access denied') !== false || $code === '1045') {
        return '数据库认证失败（用户名或密码错误） user=' . DB_USER;
    }
    if (strpos($msg, 'Unknown database') !== false || $code === '1049') {
        return '数据库不存在 dbname=' . DB_NAME;
    }
    if ($code === '2006') {
        return 'MySQL 服务器已离线或连接超时';
    }
    return '[SQLSTATE ' . $code . '] ' . $msg;
}

// 创建数据库连接
function getDB() {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    // 前置校验 1：pdo_mysql 扩展必须存在，否则 PDO 会抛 "could not find driver" 误导排查
    if (!extension_loaded('pdo_mysql')) {
        novaDbAbort('PHP 未启用 pdo_mysql 扩展');
    }

    // 前置校验 2：必填项不能为空
    if (DB_HOST === '' || DB_NAME === '' || DB_USER === '') {
        novaDbAbort('数据库配置缺失（host/name/user 为空）');
    }

    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        // 设置时区为中国（UTC+8）
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (PDOException $e) {
        $pdo = null;
        novaDbAbort(novaDbExplainError($e));
    }

    return $pdo;
}
