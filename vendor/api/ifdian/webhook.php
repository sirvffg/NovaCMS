<?php
/**
 * 爱发电 Webhook 接收接口
 * 用于接收爱发电订单回调
 */

// 设置响应头
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, PATCH, OPTIONS, HEAD');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// 处理 OPTIONS 预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['ec' => 200, 'em' => 'ok']);
    exit;
}

// 引入数据库配置
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/functions.php';
// 引入邮件配置
require_once __DIR__ . '/../../../config/email_config.php';

// 获取网站配置
$db = getDB();
$siteConfig = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$ifdianConfig = [
    'public_key' => $siteConfig['ifdian_public_key'] ?? ''
];

// 如果数据库中没有配置，则尝试从文件读取
if (empty($ifdianConfig['public_key'])) {
    $configFile = __DIR__ . '/config.php';
    if (file_exists($configFile)) {
        $fileConfig = require $configFile;
        $ifdianConfig['public_key'] = $fileConfig['public_key'] ?? '';
    }
}

// 获取 POST 数据
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// 记录日志
$webhookLog = [
    'time' => date('Y-m-d H:i:s'),
    'input' => $input,
    'data' => $data,
    'server' => $_SERVER,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
];

// 保存日志到文件（根目录的 logs/ifdian 文件夹）
$logDir = __DIR__ . '/../../../logs/ifdian';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/webhook_' . date('Ymd') . '.log';
file_put_contents($logFile, json_encode($webhookLog, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

// 验证数据格式
if (!isset($data['ec']) || $data['ec'] != 200) {
    echo json_encode(['ec' => 400, 'em' => '数据格式错误']);
    exit;
}

if (!isset($data['data']['type']) || $data['data']['type'] !== 'order') {
    echo json_encode(['ec' => 400, 'em' => '不支持的回调类型']);
    exit;
}

if (!isset($data['data']['order'])) {
    echo json_encode(['ec' => 400, 'em' => '缺少订单数据']);
    exit;
}

$order = $data['data']['order'];

// 提取订单信息
$out_trade_no = $order['out_trade_no'] ?? '';
$custom_order_id = $order['custom_order_id'] ?? '';
$user_id = $order['user_id'] ?? '';
$plan_id = $order['plan_id'] ?? '';
$month = $order['month'] ?? 0;
$total_amount = $order['total_amount'] ?? '0.00';
$show_amount = $order['show_amount'] ?? '0.00';
$status = $order['status'] ?? 0;
$remark = $order['remark'] ?? '';
$product_type = $order['product_type'] ?? 0;
$address_person = $order['address_person'] ?? '';
$address_phone = $order['address_phone'] ?? '';
$address_address = $order['address_address'] ?? '';

// 签名验证（可选）
$sign = $data['sign'] ?? '';
if (!empty($sign)) {
    // 构建签名字符串
    $sign_str = $out_trade_no . $user_id . $plan_id . $total_amount;

    // 从配置文件读取公钥
    $publicKey = $ifdianConfig['public_key'];

    if ($publicKey) {
        $key = openssl_get_publickey($publicKey);
        if ($key) {
            $verifyResult = openssl_verify($sign_str, base64_decode($sign), $key, 'SHA256');

            if (!$verifyResult) {
                echo json_encode(['ec' => 400, 'em' => '签名验证失败']);
                exit;
            }
        }
    }
}

// ----------------------------------------------------------------
// 关键修改：先返回数据，断开连接，然后再发送邮件
// ----------------------------------------------------------------

// 准备响应数据
$response = json_encode(['ec' => 200, 'em' => 'success']);

// 清除缓冲区
if (ob_get_level() > 0) {
    ob_end_clean();
}

// 开启输出缓冲
ob_start();
echo $response;
$size = ob_get_length();

// 发送响应头
header("Content-Encoding: none");
header("Content-Length: {$size}");
header("Connection: close");

// 输出并关闭连接
ob_end_flush();
if (function_exists('flush')) {
    flush();
}

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ----------------------------------------------------------------
// 以下代码将在连接断开后执行（客户端已收到响应）
// ----------------------------------------------------------------

// 1. 自动回复逻辑 (发送私信)
// ----------------------------------------------------------------
// 调试日志：记录自动回复逻辑开始
$debugLogFile = $logDir . '/debug_api_payload_' . date('Ymd') . '.log';
$startLog = [
    'time' => date('Y-m-d H:i:s'),
    'step' => 'start_auto_reply',
    'conditions' => [
        'plan_id' => $plan_id,
        'user_id' => $user_id,
        'status' => $status,
        'is_status_2' => ($status == 2)
    ]
];
file_put_contents($debugLogFile, json_encode($startLog, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

if (!empty($plan_id) && !empty($user_id) && $status == 2) {
    // ----------------------------------------------------------------
    // 新增：同步赞助者信息到数据库
    // ----------------------------------------------------------------
    try {
        require_once __DIR__ . '/sync.php';
        require_once __DIR__ . '/AfdianAPI.php';
        
        $userId = $siteConfig['ifdian_user_id'] ?? '';
        $apiToken = $siteConfig['ifdian_api_token'] ?? '';
        
        if (!empty($userId) && !empty($apiToken)) {
            $afdian = new AfdianAPI($userId, $apiToken);
            // 同步该用户的最新赞助信息
            syncSponsors($db, $afdian, $user_id);
            
            // 记录日志
            $logFile = $logDir . '/sync_' . date('Ymd') . '.log';
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - 同步用户数据: $user_id\n", FILE_APPEND);
        }
    } catch (Exception $e) {
        $logFile = $logDir . '/sync_error_' . date('Ymd') . '.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - 同步失败: " . $e->getMessage() . "\n", FILE_APPEND);
    }

    try {
        // 检查是否有配置自动回复
        $stmt = $db->prepare("SELECT reply_content FROM ifdian_auto_replies WHERE plan_id = ?");
        $stmt->execute([$plan_id]);
        $autoReply = $stmt->fetch();
        
        // 调试日志：记录配置查询结果
        $configLog = [
            'time' => date('Y-m-d H:i:s'),
            'step' => 'query_config',
            'plan_id' => $plan_id,
            'has_config' => !empty($autoReply),
            'has_content' => !empty($autoReply['reply_content'])
        ];
        file_put_contents($debugLogFile, json_encode($configLog, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        
        if ($autoReply && !empty($autoReply['reply_content'])) {
            // 加载爱发电 API
            require_once __DIR__ . '/AfdianAPI.php';
            
            $userId = $siteConfig['ifdian_user_id'] ?? '';
            $apiToken = $siteConfig['ifdian_api_token'] ?? '';
            
            if (!empty($userId) && !empty($apiToken)) {
                $afdian = new AfdianAPI($userId, $apiToken);
                
                // 发送私信
                // 替换变量 (可选)
                $replyContent = $autoReply['reply_content'];
                $replyContent = str_replace('{out_trade_no}', $out_trade_no, $replyContent);
                $replyContent = str_replace('{user_id}', $user_id, $replyContent);
                
                // ----------------------------------------------------------------
                // 新增：详细记录 API 调用参数到独立日志
                // ----------------------------------------------------------------
                $debugLogFile = $logDir . '/debug_api_payload_' . date('Ymd') . '.log';
                $debugData = [
                    'time' => date('Y-m-d H:i:s'),
                    'action' => 'send_msg',
                    'params' => [
                        'user_id' => $user_id,
                        'content' => $replyContent
                    ],
                    'config' => [
                        'user_id' => $userId,
                        'token_preview' => substr($apiToken, 0, 4) . '****' . substr($apiToken, -4)
                    ]
                ];
                file_put_contents($debugLogFile, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                // ----------------------------------------------------------------

                $result = $afdian->sendMsg($user_id, $replyContent);
                
                // 记录结果到同一个调试日志
                file_put_contents($debugLogFile, date('Y-m-d H:i:s') . " - Result: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);
                
                // 记录日志
                $logFile = $logDir . '/auto_reply_' . date('Ymd') . '.log';
                $logMsg = date('Y-m-d H:i:s') . " - 自动回复发送成功: User:$user_id, Plan:$plan_id\n";
                $logMsg .= "结果: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
                file_put_contents($logFile, $logMsg, FILE_APPEND);
            } else {
                // 记录配置缺失日志
                $logFile = $logDir . '/auto_reply_' . date('Ymd') . '.log';
                $logMsg = date('Y-m-d H:i:s') . " - 自动回复未发送: 未配置爱发电 UserID 或 API Token\n";
                file_put_contents($logFile, $logMsg, FILE_APPEND);
            }
        } else {
             // 记录未配置回复日志
             $logFile = $logDir . '/auto_reply_' . date('Ymd') . '.log';
             $logMsg = date('Y-m-d H:i:s') . " - 自动回复未发送: 未找到方案 $plan_id 的回复配置或内容为空\n";
             file_put_contents($logFile, $logMsg, FILE_APPEND);
        }
    } catch (Exception $e) {
        $logFile = $logDir . '/auto_reply_' . date('Ymd') . '.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - 自动回复失败: " . $e->getMessage() . "\n", FILE_APPEND);
        
        // 同时记录到错误日志
        $errLogFile = $logDir . '/auto_reply_error_' . date('Ymd') . '.log';
        file_put_contents($errLogFile, date('Y-m-d H:i:s') . " - 自动回复失败: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// 2. 发送邮件通知
// ----------------------------------------------------------------

// 检查是否配置了联系邮箱
$contactEmail = $siteConfig['contact_email'] ?? '';

if (empty($contactEmail)) {
    // 如果没有配置联系邮箱，尝试获取管理员邮箱
    $adminInfo = $db->query("SELECT email FROM admins ORDER BY id ASC LIMIT 1")->fetch();
    $contactEmail = $adminInfo['email'] ?? '';
}

if (!empty($contactEmail)) {
    try {
        // 加载 PHPMailer
        if (loadPHPMailerLibrary()) {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // 服务器设置
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            
            // 收发件人
            $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
            $mail->addAddress($contactEmail);
            
            // 邮件内容
            $mail->isHTML(true);
            $mail->Subject = '【爱发电】收到新的赞助订单 - ' . $out_trade_no;
            
            // 构建邮件内容
            $mailContent = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                    <h3 style='color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px;'>🎉 收到新的爱发电赞助</h3>
                    <div style='background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                        <p><strong>订单号：</strong> {$out_trade_no}</p>
                        <p><strong>赞助金额：</strong> <span style='color: #f44336; font-weight: bold;'>￥{$show_amount}</span></p>
                        <p><strong>赞助者ID：</strong> {$user_id}</p>
                        <p><strong>方案ID：</strong> {$plan_id}</p>
                        <p><strong>赞助月份：</strong> {$month} 个月</p>
                        <p><strong>状态：</strong> " . ($status == 2 ? '支付成功' : '待支付/其他') . "</p>
                        <p><strong>留言/备注：</strong> " . htmlspecialchars($remark) . "</p>
                        <p><strong>下单时间：</strong> " . date('Y-m-d H:i:s') . "</p>
                    </div>
            ";
            
            // 如果有收货信息
            if (!empty($address_person) || !empty($address_phone) || !empty($address_address)) {
                $mailContent .= "
                    <div style='background: #e3f2fd; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                        <h4 style='margin-top: 0; color: #1976d2;'>📦 收货信息</h4>
                        <p><strong>收货人：</strong> " . htmlspecialchars($address_person) . "</p>
                        <p><strong>联系电话：</strong> " . htmlspecialchars($address_phone) . "</p>
                        <p><strong>收货地址：</strong> " . htmlspecialchars($address_address) . "</p>
                    </div>
                ";
            }
            
            $mailContent .= "
                    <div style='margin-top: 30px; font-size: 12px; color: #999; text-align: center; border-top: 1px solid #eee; padding-top: 10px;'>
                        此邮件由系统自动发送，请勿直接回复。
                    </div>
                </div>
            ";
            
            $mail->Body = $mailContent;
            $mail->AltBody = "收到新的爱发电赞助\n\n订单号: $out_trade_no\n金额: $show_amount\n用户ID: $user_id\n备注: $remark";
            
            $mail->send();
            
            // 记录发送日志
            $logFile = $logDir . '/email_' . date('Ymd') . '.log';
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - 邮件发送成功: $contactEmail\n", FILE_APPEND);
        } else {
            // 记录错误日志
            $logFile = $logDir . '/email_' . date('Ymd') . '.log';
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - PHPMailer 加载失败\n", FILE_APPEND);
        }
    } catch (Exception $e) {
        // 记录错误日志
        $logFile = $logDir . '/email_' . date('Ymd') . '.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - 邮件发送失败: " . $e->getMessage() . "\n", FILE_APPEND);
        
        // 同时记录到错误日志
        $errLogFile = $logDir . '/email_error_' . date('Ymd') . '.log';
        file_put_contents($errLogFile, date('Y-m-d H:i:s') . " - 邮件发送失败: " . $e->getMessage() . "\n", FILE_APPEND);
    }
} else {
    // 记录警告日志
    $logFile = $logDir . '/email_error_' . date('Ymd') . '.log';
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - 未配置联系邮箱，无法发送通知\n", FILE_APPEND);
}
