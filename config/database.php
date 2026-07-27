<?php
// 数据库配置：生产环境优先使用 NOVACMS_DB_* 环境变量，默认值保持向后兼容。
function novaDatabaseEnv($key, $default) {
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

define('DB_HOST', novaDatabaseEnv('NOVACMS_DB_HOST', 'localhost'));
define('DB_PORT', (int)novaDatabaseEnv('NOVACMS_DB_PORT', 3306));
define('DB_NAME', novaDatabaseEnv('NOVACMS_DB_NAME', 'blog'));
define('DB_USER', novaDatabaseEnv('NOVACMS_DB_USER', 'blog'));
define('DB_PASS', novaDatabaseEnv('NOVACMS_DB_PASS', 'admin'));
define('DB_CHARSET', novaDatabaseEnv('NOVACMS_DB_CHARSET', 'utf8mb4'));

// 创建数据库连接
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
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
            // 记录详细错误到日志
            error_log("数据库连接失败: " . $e->getMessage() . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . " | Time: " . date('Y-m-d H:i:s'));
            
            // 如果是Ajax请求，返回JSON错误
            if ((!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || isset($_POST['ajax']) || isset($_GET['ajax'])) {
                // 清除可能存在的缓冲
                if (ob_get_length()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => "数据库连接失败，请稍后重试"]);
                exit;
            }
            die("数据库连接失败，请稍后重试或联系管理员");
        }
    }
    
    return $pdo;
}
