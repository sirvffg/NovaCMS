<?php
// ============================================
// 邮件发送函数配置文件
// 位置：/config/email_config.php
// 依赖：vendor/phpmailer、logs目录
// ============================================

// -------------------------------------------------
// 第一步：定义根目录常量
// -------------------------------------------------
define('ROOT_DIR', dirname(dirname(__FILE__))); // 获取根目录路径

// -------------------------------------------------
// 第二步：加载 PHPMailer 库
// -------------------------------------------------

$phpmailer_dir = ROOT_DIR . '/vendor/public/phpmailer/';
$phpmailer_files = ['PHPMailer.php', 'SMTP.php', 'Exception.php'];
$phpmailer_ok = true;

foreach ($phpmailer_files as $file) {
    if (file_exists($phpmailer_dir . $file)) {
        require_once $phpmailer_dir . $file;
    } else {
        $phpmailer_ok = false;
        break;
    }
}

if (!$phpmailer_ok) {
    error_log("PHPMailer 文件缺失，请检查 vendor/public/phpmailer/ 目录。");
    
    if (php_sapi_name() !== 'cli') {
        echo "<div style='color: red; padding: 20px; background: #fdd; border: 2px solid red; border-radius: 5px;'>";
        echo "<h3>邮件系统错误</h3>";
        echo "<p>PHPMailer 文件缺失，请检查 <code>vendor/public/phpmailer/</code> 目录。</p>";
        echo "<p>错误时间: " . date('Y-m-d H:i:s') . "</p>";
        echo "</div>";
    }
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => '邮件系统初始化失败，请联系管理员',
            'error' => 'PHPMailer 文件缺失'
        ]);
        exit;
    }
    
    if (!defined('EMAIL_CONFIG_SILENT_FAILURE')) {
        die('邮件系统初始化失败');
    }
}

// -------------------------------------------------
// 第三步：邮件配置常量
// -------------------------------------------------

/**
 * 获取数据库中的邮件配置
 */
function getEmailConfig() {
    static $config_cache = null;
    if ($config_cache === null) {
        try {
            $db = getDB();
            $stmt = $db->query("SELECT email_mode, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, smtp_from_name FROM website_config LIMIT 1");
            if ($stmt) {
                $config_cache = $stmt->fetch();
            }
        } catch (Exception $e) {
            error_log("获取邮件配置失败: " . $e->getMessage());
        }
        
        // 如果获取失败或字段缺失，使用默认值
        if (!$config_cache) {
            $config_cache = [];
        }
    }
    return $config_cache;
}

// 获取配置
$dbEmailConfig = getEmailConfig();

// 定义模式常量
define('EMAIL_MODE', $dbEmailConfig['email_mode'] ?? 'test'); // 模式: test(测试) / production(生产)

// SMTP配置（优先使用数据库配置，否则使用默认值）
define('SMTP_HOST', $dbEmailConfig['smtp_host'] ?? 'smtp.qq.com');
define('SMTP_PORT', $dbEmailConfig['smtp_port'] ?? 465);
define('SMTP_USERNAME', $dbEmailConfig['smtp_username'] ?? '');
define('SMTP_PASSWORD', $dbEmailConfig['smtp_password'] ?? '');
define('SMTP_ENCRYPTION', $dbEmailConfig['smtp_encryption'] ?? 'ssl');
define('SMTP_FROM_NAME', $dbEmailConfig['smtp_from_name'] ?? 'LyGalaxy');

// QQ邮箱配置常量（指向主配置）
define('QQ_SMTP_HOST', SMTP_HOST);
define('QQ_SMTP_PORT', SMTP_PORT);
define('QQ_SMTP_USERNAME', SMTP_USERNAME);
define('QQ_SMTP_PASSWORD', SMTP_PASSWORD);
define('QQ_SMTP_ENCRYPTION', SMTP_ENCRYPTION);
define('QQ_SMTP_FROM_NAME', SMTP_FROM_NAME);

// -------------------------------------------------
// 第四步：核心功能函数
// -------------------------------------------------

// downloadPHPMailerToRoot() 已移除，改用 Composer 管理依赖

/**
 * 获取网站名称
 */
function getSiteName() {
    try {
        $db = getDB();
        $config = $db->query("SELECT website_name FROM website_config LIMIT 1")->fetch();
        return $config['website_name'] ?? '网站';
    } catch (Exception $e) {
        error_log("获取网站名称失败: " . $e->getMessage());
        return '网站';
    }
}

/**
 * 生成验证码
 */
function generateVerificationCode() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * 发送验证邮件（主函数）
 */
function sendVerificationEmail($email, $code) {
    $site_name = getSiteName();
    
    if (EMAIL_MODE === 'test') {
        // 测试模式：记录日志但不发送邮件
        logEmailSending($email, $code, 'test_mode', '测试模式，未实际发送');
        
        // 测试模式下，在开发环境显示验证码
        if (defined('APP_DEBUG') && APP_DEBUG) {
            error_log("测试模式验证码 [{$email}]: {$code}");
        }
        
        return true;
    }
    
    // 生产环境：实际发送邮件
    return sendRealEmail($email, $site_name, $code);
}

/**
 * 实际发送邮件函数
 * 支持自动重试机制，应对 SMTP 连接不稳定
 */
/**
 * 获取SMTP服务器的IP地址（带缓存），跳过DNS解析
 */
function getSMTPHostIP() {
    try {
        $db = getDB();
        $row = $db->query("SELECT smtp_ip_cache, smtp_ip_cache_time FROM website_config WHERE id=1")->fetch();
        $cached_ip = $row['smtp_ip_cache'] ?? '';
        $cached_time = intval($row['smtp_ip_cache_time'] ?? 0);
        
        // 缓存2小时内有效，超时重新解析
        if ($cached_ip && (time() - $cached_time) < 7200) {
            return $cached_ip;
        }
    } catch (Exception $e) {
        // 数据库读取失败，降级为直接DNS解析
    }
    
    $ip = gethostbyname(SMTP_HOST);
    $ip = ($ip !== SMTP_HOST) ? $ip : SMTP_HOST;
    $now = time();
    
    // 写入数据库缓存（跨请求持久化）
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE website_config SET smtp_ip_cache=?, smtp_ip_cache_time=? WHERE id=1");
        $stmt->execute([$ip, $now]);
    } catch (Exception $e) {
        // 写入失败忽略，不影响发邮件
    }
    
    return $ip;
}

function sendRealEmail($email, $siteName, $code, $maxRetries = 1) {
    $lastError = '';
    $startTime = microtime(true);
    
    for ($attempt = 1; $attempt <= $maxRetries + 1; $attempt++) {
        try {
            // 创建PHPMailer实例
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // 服务器设置：使用缓存的IP直连，跳过DNS解析
            $mail->SMTPDebug = 0;                     // 调试模式：0=关闭
            $mail->isSMTP();                          // 使用SMTP
            $mail->Host       = getSMTPHostIP();      // 直接用IP连接，跳过DNS
            $mail->Helo       = SMTP_HOST;            // HELO用域名
            $mail->SMTPAuth   = true;                // 启用SMTP认证
            $mail->Username   = SMTP_USERNAME;       // SMTP用户名
            $mail->Password   = SMTP_PASSWORD;       // SMTP密码/授权码
            $mail->SMTPSecure = SMTP_ENCRYPTION;     // 加密方式 ssl/tls
            $mail->Port       = SMTP_PORT;           // 端口
            $mail->CharSet    = 'UTF-8';             // 字符编码
            $mail->Timeout    = 10;                  // 超时5→10秒，正常SMTP连接应在2-3秒内完成
            $mail->SMTPKeepAlive = true;             // 保持连接，避免重复TCP/TLS握手开销
            $mail->SMTPAutoTLS = false;              // 已明确指定加密方式，跳过自动TLS探测
            
            // SMTP连接优化：TCP层禁止Nagle算法 + SSL层快速握手
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT  // 固定TLS 1.2，跳过协商
                ],
                'socket' => [
                    'tcp_nodelay' => true  // 禁用Nagle算法，减少小包延迟
                ]
            ];
            
            // 收发件人
            $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
            $mail->addAddress($email);               // 收件人
            
            // 邮件内容
            $mail->isHTML(true);                     // 使用HTML格式
            $mail->Subject = '邮箱验证码 - ' . $siteName;
            $mail->Body    = getEmailTemplate($code, $siteName);
            $mail->AltBody = "您的验证码是：{$code}，有效期10分钟。\n\n如果这不是您本人操作，请忽略此邮件。";
            
            // 发送邮件
            $sendStart = microtime(true);
            if ($mail->send()) {
                $elapsed = round((microtime(true) - $sendStart) * 1000);
                logEmailSending($email, $code, 'success', "发送成功(第{$attempt}次,耗时{$elapsed}ms)");
                return true;
            } else {
                $lastError = $mail->ErrorInfo;
                $elapsed = round((microtime(true) - $sendStart) * 1000);
                logEmailSending($email, $code, 'error', "第{$attempt}次发送失败(耗时{$elapsed}ms): " . $lastError);
            }
            
        } catch (Exception $e) {
            $lastError = $mail->ErrorInfo ?? $e->getMessage();
            logEmailSending($email, $code, 'error', "第{$attempt}次发送异常: " . $lastError);
        }
        
        // 如果不是最后一次尝试，等待后重试
        if ($attempt <= $maxRetries) {
            usleep(100000); // 固定100ms快速重试
        }
    }
    
    // 所有重试都失败
    $totalElapsed = round((microtime(true) - $startTime) * 1000);
    error_log("邮件发送最终失败 [{$email}]，重试{$maxRetries}次，总耗时{$totalElapsed}ms: {$lastError}");
    return false;
}

/**
 * 邮件模板
 */
function getEmailTemplate($code, $siteName) {
    // 使用普通字符串拼接，避免HEREDOC语法问题
    $template = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>邮箱验证码 - ' . htmlspecialchars($siteName) . '</title>
    <style>
        body { font-family: \'Microsoft YaHei\', Arial, sans-serif; line-height: 1.6; color: #333; background: #f5f5f5; margin: 0; padding: 20px; }
        .email-container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .email-header { background: linear-gradient(135deg, #56bff8ff 0%, #aaf19cff 100%); padding: 30px 20px; text-align: center; }
        .email-header h1 { color: white; margin: 0; font-size: 24px; }
        .email-header p { color: rgba(255,255,255,0.9); margin: 10px 0 0 0; }
        .email-content { padding: 30px; }
        .code-box { background: #f8f9fa; border: 2px dashed #dee2e6; border-radius: 8px; padding: 25px; text-align: center; margin: 20px 0; }
        .code { font-size: 32px; font-weight: bold; color: #667eea; letter-spacing: 5px; font-family: monospace; }
        .info-box { background: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .email-footer { text-align: center; padding: 20px; background: #f8f9fa; border-top: 1px solid #eee; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>📧 邮箱验证码</h1>
            <p>' . htmlspecialchars($siteName) . ' - 账户安全验证</p>
        </div>
        
        <div class="email-content">
            <p>您正在尝试进行敏感操作，请使用以下验证码完成验证：</p>
            
            <div class="code-box">
                <div class="code">' . $code . '</div>
                <div style="color: #666; margin-top: 10px; font-size: 14px;">验证码</div>
            </div>
            
            <div style="text-align: center; color: #ff9800; margin: 15px 0;">
                <strong>⏰ 有效期：10分钟</strong>
            </div>
            
            <div class="info-box">
                <strong>使用说明：</strong>
                <ol style="margin: 10px 0; padding-left: 20px;">
                    <li>在验证页面输入上述6位数字验证码</li>
                    <li>验证通过后即可继续您的操作</li>
                    <li>验证码仅用于本次验证</li>
                </ol>
            </div>
            
            <div class="warning-box">
                <strong>🔒 安全提醒：</strong>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>请勿将验证码转发或告知任何人</li>
                    <li>网站工作人员不会向您索要验证码</li>
                    <li>如果这不是您本人操作，请忽略此邮件</li>
                </ul>
            </div>
        </div>
        
        <div class="email-footer">
            <p>此邮件由 <strong>' . htmlspecialchars($siteName) . '</strong> 系统自动发送，请勿直接回复</p>
            <p>如需帮助，请联系网站管理员</p>
        </div>
    </div>
</body>
</html>';
    
    return $template;
}

/**
 * 记录邮件发送日志（保存到根目录logs文件夹）
 */
function logEmailSending($email, $code, $status, $message = '') {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'email' => $email,
        'code' => $code,
        'status' => $status,
        'message' => $message,
        'mode' => EMAIL_MODE,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 200)
    ];
    
    // 创建日志目录（根目录下的logs/email）
    $log_dir = ROOT_DIR . '/logs/email/';
    if (!is_dir($log_dir)) {
        if (!mkdir($log_dir, 0755, true)) {
            // 如果无法创建目录，使用临时目录
            $log_dir = sys_get_temp_dir() . '/email_logs/';
            @mkdir($log_dir, 0755, true);
        }
    }
    
    // 写入日志文件
    $log_file = $log_dir . 'email_' . date('Y-m-d') . '.log';
    $log_line = json_encode($log_entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    
    @file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
    
    return $log_entry;
}

/**
 * 测试邮件配置
 */
function testEmailConfig($test_email = '') {
    $result = [
        'status' => 'unknown',
        'message' => '',
        'details' => []
    ];
    
    if (EMAIL_MODE === 'test') {
        $result['status'] = 'test';
        $result['message'] = '当前为测试模式，不会实际发送邮件';
        $result['details'] = [
            'mode' => 'test',
            'provider' => 'QQ邮箱',
            'config' => [
                'host' => SMTP_HOST,
                'port' => SMTP_PORT,
                'username' => SMTP_USERNAME,
                'password' => substr(SMTP_PASSWORD, 0, 3) . '***',
                'encryption' => SMTP_ENCRYPTION
            ]
        ];
        return $result;
    }
    
    // 检查PHPMailer类是否存在
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $result['status'] = 'error';
        $result['message'] = 'PHPMailer库未加载';
        return $result;
    }
    
    try {
        // 基本配置检查
        $checks = [
            'PHPMailer类' => class_exists('PHPMailer\PHPMailer\PHPMailer'),
            'SMTP类' => class_exists('PHPMailer\PHPMailer\SMTP'),
            'Exception类' => class_exists('PHPMailer\PHPMailer\Exception'),
            'SMTP服务器' => !empty(SMTP_HOST),
            'SMTP端口' => !empty(SMTP_PORT),
            'SMTP用户名' => !empty(SMTP_USERNAME),
            'SMTP密码' => !empty(SMTP_PASSWORD),
            '加密方式' => in_array(SMTP_ENCRYPTION, ['ssl', 'tls', ''])
        ];
        
        $result['status'] = 'success';
        $result['message'] = '邮件配置正常';
        $result['details'] = [
            'mode' => 'production',
            'config' => [
                'host' => SMTP_HOST,
                'port' => SMTP_PORT,
                'username' => SMTP_USERNAME,
                'password' => substr(SMTP_PASSWORD, 0, 3) . '***',
                'encryption' => SMTP_ENCRYPTION
            ],
            'directory' => [
                'config_dir' => __DIR__,
                'root_dir' => ROOT_DIR,
                'logs_dir' => ROOT_DIR . '/logs/email/',
                'vendor_dir' => ROOT_DIR . '/vendor/'
            ],
            'checks' => $checks
        ];
        
        // 如果提供了测试邮箱，尝试发送测试邮件
        if (!empty($test_email) && filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
            $test_code = generateVerificationCode();
            $test_result = sendVerificationEmail($test_email, $test_code);
            
            $result['test_send'] = $test_result;
            $result['test_email'] = $test_email;
            $result['test_code'] = $test_code;
        }
        
    } catch (Exception $e) {
        $result['status'] = 'error';
        $result['message'] = '配置测试失败: ' . $e->getMessage();
    }
    
    return $result;
}

/**
 * 获取邮件发送统计
 */
function getEmailStats($days = 7) {
    $stats = [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'test' => 0,
        'days' => $days
    ];
    
    // 从根目录logs文件夹读取日志
    $log_dir = ROOT_DIR . '/logs/email/';
    if (!is_dir($log_dir)) {
        return $stats;
    }
    
    $cutoff_date = date('Y-m-d', strtotime("-$days days"));
    $files = glob($log_dir . 'email_*.log');
    
    foreach ($files as $file) {
        $file_date = substr(basename($file), 6, 10); // email_YYYY-MM-DD.log
        
        if ($file_date >= $cutoff_date) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines) continue;
            
            foreach ($lines as $line) {
                $log = json_decode($line, true);
                if ($log) {
                    $stats['total']++;
                    switch ($log['status']) {
                        case 'success':
                            $stats['success']++;
                            break;
                        case 'error':
                            $stats['failed']++;
                            break;
                        case 'test_mode':
                            $stats['test']++;
                            break;
                    }
                }
            }
        }
    }
    
    return $stats;
}

/**
 * 清理过期日志
 */
function cleanEmailLogs($days_to_keep = 30) {
    $log_dir = ROOT_DIR . '/logs/email/';
    if (!is_dir($log_dir)) {
        return 0;
    }
    
    $files = glob($log_dir . 'email_*.log');
    $cutoff_time = time() - ($days_to_keep * 24 * 60 * 60);
    $deleted = 0;
    
    foreach ($files as $file) {
        if (filemtime($file) < $cutoff_time) {
            if (@unlink($file)) {
                $deleted++;
            }
        }
    }
    
    return $deleted;
}

/**
 * 初始化邮件系统
 */
function initEmailSystem() {
    // 检查并创建必要的目录
    $directories = [
        ROOT_DIR . '/logs/email/'
    ];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
    
    return true;
}

// -------------------------------------------------
// 初始化邮件系统
// -------------------------------------------------
initEmailSystem();

// -------------------------------------------------
// 使用示例
// -------------------------------------------------
/*
// 在其他PHP文件中使用：
require_once ROOT_DIR . '/config/email_config.php';

// 发送验证码
$email = "user@example.com";
$code = generateVerificationCode();
$result = sendVerificationEmail($email, $code);

if ($result) {
    echo "验证码已发送！";
} else {
    echo "发送失败！";
}

// 测试配置
$test = testEmailConfig();
print_r($test);
*/

// -------------------------------------------------
// 结束
// -------------------------------------------------

// 可选：添加一个简单的验证函数供外部调用
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
?>