<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once 'epay.php';

$db = getDB();
$stmt = $db->query("SELECT * FROM website_config LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

$epayUrl = trim($config['epay_url'] ?? '');
$epayPid = trim($config['epay_pid'] ?? '');
$epayKey = trim($config['epay_key'] ?? '');

if (empty($epayUrl) || empty($epayPid) || empty($epayKey)) {
    die("fail");
}

$epay = new EpayCore([
    'pid' => $epayPid,
    'key' => $epayKey,
    'url' => $epayUrl
]);

$verify_result = $epay->verifyNotify($_GET);

if($verify_result) {
    $out_trade_no = $_GET['out_trade_no'];
    $trade_no = $_GET['trade_no'];
    $trade_status = $_GET['trade_status'];
    
    if ($_GET['trade_status'] == 'TRADE_SUCCESS') {
        // 检查是否已经在正式表了
        $stmt = $db->prepare("SELECT id FROM blog_paid_access WHERE trade_no = ?");
        $stmt->execute([$out_trade_no]);
        if (!$stmt->fetch()) {
            // 从临时表中查出订单信息
            $stmt = $db->prepare("SELECT * FROM blog_paid_access_temporary WHERE trade_no = ?");
            $stmt->execute([$out_trade_no]);
            $tempOrder = $stmt->fetch();
            
            if ($tempOrder) {
                // 校验回调金额是否与订单金额一致，防止金额篡改漏洞
                if (!isset($_GET['money']) || (float)$_GET['money'] < (float)$tempOrder['amount']) {
                    die("fail: amount mismatch");
                }
                
                // 将订单直接插入正式表并标记为已支付
                $stmt = $db->prepare("INSERT IGNORE INTO blog_paid_access (user_id, post_id, trade_no, amount, status, created_at) VALUES (?, ?, ?, ?, 1, ?)");
                $stmt->execute([$tempOrder['user_id'], $tempOrder['post_id'], $tempOrder['trade_no'], $tempOrder['amount'], $tempOrder['created_at']]);
            }
        }
    }
    echo "success";
}
else {
    echo "fail";
}
?>