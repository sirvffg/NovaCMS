<?php
/**
 * 爱发电 Webhook 模拟测试工具
 * 用于测试 webhook.php 的逻辑
 */
session_start();

// 管理员权限检查
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    exit('无权访问，请以管理员身份登录');
}

// 默认配置
$defaultUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . str_replace('test_simulation.php', 'webhook.php', $_SERVER['SCRIPT_NAME']);
$result = null;

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = $_POST['url'] ?? $defaultUrl;
    
    // 构造模拟数据
    $data = [
        'ec' => 200,
        'em' => 'ok',
        'data' => [
            'type' => 'order',
            'order' => [
                'out_trade_no' => $_POST['out_trade_no'] ?? 'TEST_' . date('YmdHis'),
                'custom_order_id' => $_POST['custom_order_id'] ?? '',
                'user_id' => $_POST['user_id'] ?? '',
                'plan_id' => $_POST['plan_id'] ?? '',
                'month' => (int)($_POST['month'] ?? 1),
                'total_amount' => $_POST['amount'] ?? '0.00',
                'show_amount' => $_POST['amount'] ?? '0.00',
                'status' => (int)($_POST['status'] ?? 200),
                'remark' => $_POST['remark'] ?? '',
                'product_type' => (int)($_POST['product_type'] ?? 0),
                'address_person' => $_POST['address_person'] ?? '',
                'address_phone' => $_POST['address_phone'] ?? '',
                'address_address' => $_POST['address_address'] ?? ''
            ]
        ]
    ];

    // 转换为 JSON
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE);

    // 初始化 cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $result = [
        'payload' => $data,
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $curlError
    ];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>爱发电 Webhook 模拟测试</title>
    <link href="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.1.3/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding-top: 20px; padding-bottom: 40px; }
        .container { max-width: 900px; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); margin-bottom: 20px; }
        .result-box { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; font-family: monospace; white-space: pre-wrap; word-break: break-all; }
        .form-label { font-weight: 500; }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="mb-4 text-center"><i class="bi bi-lightning-charge-fill text-warning"></i> 爱发电 Webhook 模拟测试</h2>

        <?php if ($result): ?>
        <div class="card border-<?php echo $result['http_code'] == 200 ? 'success' : 'danger'; ?>">
            <div class="card-header bg-<?php echo $result['http_code'] == 200 ? 'success' : 'danger'; ?> text-white">
                测试结果 (HTTP <?php echo $result['http_code']; ?>)
            </div>
            <div class="card-body">
                <?php if ($result['error']): ?>
                    <div class="alert alert-danger">cURL 错误: <?php echo htmlspecialchars($result['error']); ?></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-6">
                        <h6>发送的数据 (Payload)</h6>
                        <div class="result-box mb-3"><?php echo json_encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></div>
                    </div>
                    <div class="col-md-6">
                        <h6>响应内容 (Response)</h6>
                        <div class="result-box"><?php echo htmlspecialchars($result['response']); ?></div>
                    </div>
                </div>
                <div class="mt-2 text-muted small">
                    <i class="bi bi-info-circle"></i> 请检查 <code>logs/ifdian</code> 目录下的日志文件以查看详细处理过程。
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Webhook URL</label>
                        <input type="text" class="form-control" name="url" value="<?php echo htmlspecialchars($_POST['url'] ?? $defaultUrl); ?>" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">订单号 (out_trade_no)</label>
                            <input type="text" class="form-control" name="out_trade_no" value="<?php echo htmlspecialchars($_POST['out_trade_no'] ?? 'TEST_' . date('YmdHis') . '_' . rand(100, 999)); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">用户 ID (user_id)</label>
                            <input type="text" class="form-control" name="user_id" value="<?php echo htmlspecialchars($_POST['user_id'] ?? 'test_user_001'); ?>" required placeholder="接收私信的用户ID">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">方案 ID (plan_id)</label>
                            <input type="text" class="form-control" name="plan_id" value="<?php echo htmlspecialchars($_POST['plan_id'] ?? 'test_plan_001'); ?>" required placeholder="触发自动回复的方案ID">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">金额 (amount)</label>
                            <div class="input-group">
                                <span class="input-group-text">¥</span>
                                <input type="number" class="form-control" name="amount" value="<?php echo htmlspecialchars($_POST['amount'] ?? '5.00'); ?>" step="0.01">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">状态 (status)</label>
                            <select class="form-select" name="status">
                                <option value="200" <?php echo ($_POST['status'] ?? 200) == 200 ? 'selected' : ''; ?>>200 (支付成功)</option>
                                <option value="0" <?php echo ($_POST['status'] ?? 200) == 0 ? 'selected' : ''; ?>>0 (待支付/其他)</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">赞助月数 (month)</label>
                            <input type="number" class="form-control" name="month" value="<?php echo htmlspecialchars($_POST['month'] ?? '1'); ?>" min="1">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">类型 (product_type)</label>
                            <select class="form-select" name="product_type">
                                <option value="0" <?php echo ($_POST['product_type'] ?? 0) == 0 ? 'selected' : ''; ?>>0 (常规订阅)</option>
                                <option value="1" <?php echo ($_POST['product_type'] ?? 0) == 1 ? 'selected' : ''; ?>>1 (商品)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">备注 (remark)</label>
                        <input type="text" class="form-control" name="remark" value="<?php echo htmlspecialchars($_POST['remark'] ?? '这是一条测试订单'); ?>">
                    </div>
                    
                    <div class="accordion mb-3" id="accordionExample">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingOne">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="false" aria-controls="collapseOne">
                                    更多选项 (收货信息等)
                                </button>
                            </h2>
                            <div id="collapseOne" class="accordion-collapse collapse" aria-labelledby="headingOne" data-bs-parent="#accordionExample">
                                <div class="accordion-body">
                                    <div class="mb-3">
                                        <label class="form-label">自定义订单号 (custom_order_id)</label>
                                        <input type="text" class="form-control" name="custom_order_id" value="<?php echo htmlspecialchars($_POST['custom_order_id'] ?? ''); ?>">
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">收货人</label>
                                            <input type="text" class="form-control" name="address_person" value="<?php echo htmlspecialchars($_POST['address_person'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">联系电话</label>
                                            <input type="text" class="form-control" name="address_phone" value="<?php echo htmlspecialchars($_POST['address_phone'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">收货地址</label>
                                        <input type="text" class="form-control" name="address_address" value="<?php echo htmlspecialchars($_POST['address_address'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">发送模拟请求</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
