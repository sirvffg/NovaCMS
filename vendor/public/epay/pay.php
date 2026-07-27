<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once 'epay.php';

$db = getDB();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS `blog_paid_access` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `post_id` int(11) NOT NULL,
        `trade_no` varchar(64) DEFAULT NULL,
        `amount` decimal(10,2) NOT NULL,
        `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0: pending, 1: paid',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_post` (`user_id`, `post_id`),
        UNIQUE KEY `idx_trade_no` (`trade_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // 尝试修复已存在表的索引（忽略可能存在的错误）
    $db->exec("ALTER TABLE `blog_paid_access` DROP INDEX `idx_trade_no`, ADD UNIQUE INDEX `idx_trade_no` (`trade_no`)");
} catch (Exception $e) {
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS `blog_paid_access_temporary` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `post_id` int(11) NOT NULL,
        `trade_no` varchar(64) DEFAULT NULL,
        `amount` decimal(10,2) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_trade_no` (`trade_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
}

$stmt = $db->query("SELECT * FROM website_config LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
if ($userId <= 0) {
    die("<script>alert('请先登录！'); location.href='/vendor/login.php?redirect_url=' + encodeURIComponent('/vendor/public/epay/pay.php?post_id=' + " . intval($_GET['post_id'] ?? 0) . ");</script>");
}

// 处理同步回调
$isReturn = false;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['sign']) && isset($_GET['out_trade_no'])) {
    $isReturn = true;
}

if ($isReturn) {
    $epayUrl = trim($config['epay_url'] ?? '');
    $epayPid = trim($config['epay_pid'] ?? '');
    $epayKey = trim($config['epay_key'] ?? '');
    
    if ($epayUrl && $epayPid && $epayKey) {
        $epay = new EpayCore([
            'pid' => $epayPid,
            'key' => $epayKey,
            'url' => $epayUrl
        ]);
        
        if ($epay->verifyReturn($_GET)) {
            $out_trade_no = $_GET['out_trade_no'] ?? '';
            if (($_GET['trade_status'] ?? '') == 'TRADE_SUCCESS') {
                // 检查是否已经在正式表了
                $stmt = $db->prepare("SELECT id, post_id FROM blog_paid_access WHERE trade_no = ?");
                $stmt->execute([$out_trade_no]);
                $paidOrder = $stmt->fetch();
                
                if (!$paidOrder) {
                    // 从临时表中查出订单信息
                    $stmt = $db->prepare("SELECT * FROM blog_paid_access_temporary WHERE trade_no = ?");
                    $stmt->execute([$out_trade_no]);
                    $tempOrder = $stmt->fetch();
                    
                    if ($tempOrder) {
                        // 校验回调金额是否与订单金额一致，防止金额篡改漏洞
                        if (!isset($_GET['money']) || (float)$_GET['money'] < (float)$tempOrder['amount']) {
                            die("<script>alert('支付金额异常！');location.href='/';</script>");
                        }
                        
                        // 将订单直接插入正式表并标记为已支付
                        $stmt = $db->prepare("INSERT IGNORE INTO blog_paid_access (user_id, post_id, trade_no, amount, status, created_at) VALUES (?, ?, ?, ?, 1, ?)");
                        $stmt->execute([$tempOrder['user_id'], $tempOrder['post_id'], $tempOrder['trade_no'], $tempOrder['amount'], $tempOrder['created_at']]);
                        
                        die("<script>alert('支付成功！');location.href='/blog.php?id={$tempOrder['post_id']}';</script>");
                    }
                } else {
                    // 已经支付过
                    die("<script>alert('支付成功！');location.href='/blog.php?id={$paidOrder['post_id']}';</script>");
                }
            }
        }
    }
    die("<script>alert('支付结果验证失败！');location.href='/';</script>");
}

$postId = intval($_GET['post_id'] ?? ($_POST['post_id'] ?? 0));
if ($postId <= 0) {
    die("<script>alert('参数错误！');history.back();</script>");
}

$stmt = $db->prepare("SELECT title, has_paid_content, post_price FROM blog_posts WHERE id = ?");
$stmt->execute([$postId]);
$post = $stmt->fetch();

if (!$post || $post['has_paid_content'] == 0 || $post['post_price'] <= 0) {
    die("<script>alert('该文章无需付费！');location.href='/blog.php?id={$postId}';</script>");
}

// 检查是否已经支付
$stmt = $db->prepare("SELECT id FROM blog_paid_access WHERE user_id = ? AND post_id = ?");
$stmt->execute([$userId, $postId]);
if ($stmt->fetch()) {
    die("<script>alert('您已经支付过该文章了！');location.href='/blog.php?id={$postId}';</script>");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = trim($_POST['type'] ?? 'alipay');
    if (!in_array($type, ['alipay', 'wxpay', 'qqpay'])) {
        die("<script>alert('无效的支付方式！');history.back();</script>");
    }

    $epayUrl = trim($config['epay_url'] ?? '');
    $epayPid = trim($config['epay_pid'] ?? '');
    $epayKey = trim($config['epay_key'] ?? '');
    
    if (empty($epayUrl) || empty($epayPid) || empty($epayKey)) {
        die("<script>alert('网站支付暂未配置，请联系管理员。');history.back();</script>");
    }

    // 生成订单号
    $tradeNo = date('YmdHis') . mt_rand(10000, 99999) . uniqid() . $userId;
    
    // 写入临时数据库
    $stmt = $db->prepare("INSERT INTO blog_paid_access_temporary (user_id, post_id, trade_no, amount) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $postId, $tradeNo, $post['post_price']]);
    
    // 发起支付
    $epay = new EpayCore([
        'pid' => $epayPid,
        'key' => $epayKey,
        'url' => $epayUrl
    ]);
    
    $siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $notifyUrl = $siteUrl . '/vendor/public/epay/notify_url.php';
    $returnUrl = $siteUrl . '/vendor/public/epay/pay.php';
    
    $parameter = [
        "pid" => $epayPid,
        "type" => $type,
        "notify_url" => $notifyUrl,
        "return_url" => $returnUrl,
        "out_trade_no" => $tradeNo,
        "name" => "Article_" . $postId,
        "money" => $post['post_price']
    ];
    
    $html_text = $epay->buildRequestForm($parameter);
    echo $html_text;
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>支付内容 - <?= htmlspecialchars($post['title']) ?></title>
    <link href="https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding-top: 50px; }
        .pay-card { max-width: 500px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .price { font-size: 36px; color: #ff5722; font-weight: bold; margin: 20px 0; }
        .pay-methods { display: flex; gap: 15px; margin-bottom: 20px; }
        .pay-method { flex: 1; border: 1px solid #ddd; border-radius: 5px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.3s; }
        .pay-method:hover { border-color: #007bff; background-color: #f0f7ff; }
        .pay-method.active { border-color: #007bff; background-color: #e6f2ff; color: #007bff; font-weight: bold; }
        .pay-method input { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="pay-card text-center">
            <h4>解锁付费内容</h4>
            <p class="text-muted mt-3">文章：<?= htmlspecialchars($post['title']) ?></p>
            <div class="price">￥<?= number_format($post['post_price'], 2) ?></div>
            
            <form method="POST">
                <input type="hidden" name="post_id" value="<?= $postId ?>">
                
                <div class="pay-methods">
                    <label class="pay-method active">
                        <input type="radio" name="type" value="alipay" checked>
                        支付宝
                    </label>
                    <label class="pay-method">
                        <input type="radio" name="type" value="wxpay">
                        微信支付
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary w-100 btn-lg">立即支付</button>
            </form>
            
            <div class="mt-3">
                <a href="/blog.php?id=<?= $postId ?>" class="text-muted text-decoration-none">返回文章</a>
            </div>
        </div>
    </div>
    
    <script>
        document.querySelectorAll('.pay-method').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.pay-method').forEach(el => el.classList.remove('active'));
                this.classList.add('active');
                this.querySelector('input').checked = true;
            });
        });
    </script>
</body>
</html>