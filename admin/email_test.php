<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';

// 检查管理员权限
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

$message = '';
$message_type = '';

// 处理邮件发送测试
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_email = trim($_POST['test_email'] ?? '');
    $test_type = $_POST['test_type'] ?? 'simple';
    
    if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        $message = '请输入有效的邮箱地址';
        $message_type = 'danger';
    } else {
        try {
            if ($test_type === 'verification') {
                // 生成验证码并存储
                $code = generateVerificationCode();
                $expires_at = date('Y-m-d H:i:s', time() + 600);
                
                // 先删除旧的未使用验证码，避免重复键冲突
                $stmt = $db->prepare("DELETE FROM email_verification WHERE email = ? AND purpose = 'register' AND is_used = 0");
                $stmt->execute([$test_email]);
                
                // 存储新验证码
                $stmt = $db->prepare("INSERT INTO email_verification (email, code, purpose, expires_at) VALUES (?, ?, 'register', ?)");
                $stmt->execute([$test_email, $code, $expires_at]);
                
                // 发送验证码邮件
                if (sendVerificationEmail($test_email, $code)) {
                    $message = "验证码邮件已发送到 {$test_email}，验证码是：{$code}（测试模式显示）";
                    $message_type = 'success';
                } else {
                    $message = '邮件发送失败，请检查邮件配置';
                    $message_type = 'danger';
                }
            } else {
                // 发送简单测试邮件
                $code = generateVerificationCode();
                if (sendVerificationEmail($test_email, $code)) {
                    $message = "测试邮件已发送到 {$test_email}，验证码是：{$code}（测试模式显示）";
                    $message_type = 'success';
                } else {
                    $message = '邮件发送失败，请检查邮件配置';
                    $message_type = 'danger';
                }
            }
        } catch (Exception $e) {
            $message = '发送邮件时出错：' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// 获取最近的验证码记录
$recent_codes = $db->query("SELECT * FROM email_verification ORDER BY created_at DESC LIMIT 10")->fetchAll();

// 定义当前模式变量，供页面使用
$current_mode = defined('EMAIL_MODE') ? EMAIL_MODE : 'test';
$page_title = '邮件发送测试';
$extra_css = <<<'CSS'
.card {
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1.5rem;
}
.card-header {
    background-color: #fff;
    border-bottom: 1px solid rgba(0,0,0,.05);
    padding: 1rem 1.5rem;
}
.config-list-item {
    padding: 1rem;
    border-bottom: 1px solid #f0f0f0;
}
.config-list-item:last-child {
    border-bottom: none;
}
.config-label {
    color: #6c757d;
    font-size: 0.875rem;
    margin-bottom: 0.25rem;
}
.config-value {
    font-weight: 500;
    color: #212529;
}
.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,.02);
}
CSS;
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <h1 class="h2">邮件发送测试</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="config.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-gear me-1"></i> 系统设置
                        </a>
                    </div>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-<?= $message_type ?> alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>-fill me-2"></i>
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- 配置指南 (全宽) -->
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0 d-flex align-items-center"><i class="bi bi-book me-2 text-secondary"></i>配置指南</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info border-0 bg-info-subtle text-info-emphasis mb-3">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-info-circle-fill me-2 fs-5"></i>
                                        <div>
                                            <strong>当前模式：</strong>
                                            <?php if ($current_mode === 'production'): ?>
                                                生产模式。验证码将通过 SMTP 服务发送到用户邮箱。
                                            <?php else: ?>
                                                测试模式。验证码不会真实发送，而是直接显示在页面上，方便开发调试。
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold mt-2">如何配置生产环境？</h6>
                                        <ol class="text-muted small ps-3 mb-0">
                                            <li class="mb-1">确保已安装 PHPMailer: <code>composer require phpmailer/phpmailer</code></li>
                                            <li class="mb-1">编辑 <code>/config/email_config.php</code>，取消注释相关代码。</li>
                                            <li class="mb-1">填入您的 SMTP 服务器信息（Host, Username, Password, Port）。</li>
                                            <li class="mb-1">在 <code>config.php</code> 或相关位置将模式切换为 Production。</li>
                                        </ol>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fw-bold mt-2">常用 SMTP 设置</h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered small text-center mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>服务商</th>
                                                        <th>SMTP 服务器</th>
                                                        <th>端口 (SSL/TLS)</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td>QQ 邮箱</td>
                                                        <td>smtp.qq.com</td>
                                                        <td>465 / 587</td>
                                                    </tr>
                                                    <tr>
                                                        <td>163 邮箱</td>
                                                        <td>smtp.163.com</td>
                                                        <td>465 / 994</td>
                                                    </tr>
                                                    <tr>
                                                        <td>Gmail</td>
                                                        <td>smtp.gmail.com</td>
                                                        <td>465 / 587</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 左侧：测试表单和配置信息 -->
                    <div class="col-lg-5">
                        <!-- 测试表单 -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0 d-flex align-items-center"><i class="bi bi-send me-2 text-primary"></i>发送测试邮件</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label for="test_email" class="form-label">测试邮箱地址</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                            <input type="email" class="form-control" id="test_email" name="test_email" 
                                                   placeholder="name@example.com" required
                                                   value="<?= htmlspecialchars($_POST['test_email'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label d-block">邮件类型</label>
                                        <div class="btn-group w-100" role="group">
                                            <input type="radio" class="btn-check" name="test_type" id="test_simple" value="simple" checked>
                                            <label class="btn btn-outline-primary" for="test_simple">简单测试</label>

                                            <input type="radio" class="btn-check" name="test_type" id="test_verification" value="verification">
                                            <label class="btn btn-outline-primary" for="test_verification">验证码邮件</label>
                                        </div>
                                        <div class="form-text mt-2">
                                            <i class="bi bi-info-circle me-1"></i>
                                            "验证码邮件"会生成一条真实的验证记录存储在数据库中。
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-send me-1"></i> 发送邮件
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- 配置信息 -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0 d-flex align-items-center"><i class="bi bi-sliders me-2 text-info"></i>当前配置</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="config-list-item">
                                    <div class="config-label">网站名称</div>
                                    <div class="config-value"><?= htmlspecialchars($config['website_name']) ?></div>
                                </div>
                                <div class="config-list-item">
                                    <div class="config-label">发件人邮箱</div>
                                    <div class="config-value"><?= $config['contact_email'] ? htmlspecialchars($config['contact_email']) : '<span class="text-muted fst-italic">未设置</span>' ?></div>
                                </div>
                                <div class="config-list-item">
                                    <div class="config-label">运行模式</div>
                                    <div class="config-value">
                                        <?php 
                                        $current_mode = defined('EMAIL_MODE') ? EMAIL_MODE : 'test';
                                        if ($current_mode === 'production'): ?>
                                            <span class="badge bg-success rounded-pill">
                                                <i class="bi bi-check-circle me-1"></i>生产模式
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark rounded-pill">
                                                <i class="bi bi-bug me-1"></i>测试模式
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="config-list-item">
                                    <div class="config-label">函数状态</div>
                                    <div class="config-value">
                                        <?php if (function_exists('sendVerificationEmail')): ?>
                                            <span class="text-success"><i class="bi bi-check-lg me-1"></i>sendVerificationEmail 可用</span>
                                        <?php else: ?>
                                            <span class="text-danger"><i class="bi bi-x-lg me-1"></i>sendVerificationEmail 不可用</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 右侧：记录和说明 -->
                    <div class="col-lg-7">
                        <!-- 验证码记录 -->
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 d-flex align-items-center"><i class="bi bi-clock-history me-2 text-warning"></i>最近发送记录</h5>
                                <span class="badge bg-light text-dark border">近 10 条</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-4">邮箱 / 验证码</th>
                                            <th>用途</th>
                                            <th>状态</th>
                                            <th class="text-end pe-4">时间</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recent_codes)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                                                暂无记录
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($recent_codes as $record): ?>
                                            <?php 
                                            $is_used = $record['is_used'] == 1;
                                            $is_expired = strtotime($record['expires_at']) < time();
                                            $status_text = $is_used ? '已使用' : ($is_expired ? '已过期' : '有效');
                                            $status_class = $is_used ? 'success' : ($is_expired ? 'danger' : 'primary');
                                            ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <div class="fw-bold"><?= htmlspecialchars($record['email']) ?></div>
                                                    <code class="small text-muted bg-light px-1 rounded"><?= htmlspecialchars($record['code']) ?></code>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border">
                                                        <?= htmlspecialchars($record['purpose']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $status_class ?>-subtle text-<?= $status_class ?> border border-<?= $status_class ?>-subtle">
                                                        <?= $status_text ?>
                                                    </span>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <div class="small text-muted"><?= date('m-d H:i', strtotime($record['created_at'])) ?></div>
                                                    <div class="small text-muted" style="font-size: 0.75rem;">过期: <?= date('m-d H:i', strtotime($record['expires_at'])) ?></div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js') ?>"></script>
<?php require_once 'includes/footer.php'; ?>
