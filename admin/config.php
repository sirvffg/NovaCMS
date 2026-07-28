<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';
require_once 'includes/config_controller.php';

$db = getDB();
$data = handleConfigPage($db);
$config = $data['config'];
$beianConfig = $data['beianConfig'];
$success = $data['success'];
$error = $data['error'];

// 保存成功后重定向，避免刷新页面重新提交表单
if ($success && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}
$page_title = '网站配置';
$extra_css = <<<'CSS'
.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.05);
    margin-bottom: 1.5rem;
}
.card-header {
    background-color: #fff;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    padding: 1.25rem 1.5rem;
    border-radius: 12px 12px 0 0 !important;
}
.card-header h5 {
    margin: 0;
    font-weight: 600;
    color: #333;
}
.nav-tabs .nav-link {
    color: #6c757d;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 1rem 1.5rem;
    font-weight: 500;
    transition: all 0.2s;
}
.nav-tabs .nav-link.active {
    color: #0d6efd;
    border-bottom: 2px solid #0d6efd;
    background: none;
}
.nav-tabs .nav-link:hover:not(.active) {
    color: #0d6efd;
    border-color: transparent;
    background-color: #f8f9fa;
}
.form-label {
    font-weight: 500;
    color: #495057;
    margin-bottom: 0.5rem;
}
.form-control:focus, .form-select:focus {
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
    border-color: #86b7fe;
}
.preview-box {
    background-color: #f8f9fa;
    border: 2px dashed #dee2e6;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    transition: all 0.3s;
}
.preview-box:hover {
    border-color: #0d6efd;
    background-color: #f1f7ff;
}
.upload-area {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}
.upload-progress-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}
.upload-progress-container {
    background: white;
    border-radius: 12px;
    padding: 24px;
    min-width: 400px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
}
.upload-progress-bar-container {
    background: #e9ecef;
    border-radius: 4px;
    height: 8px;
    overflow: hidden;
    margin: 12px 0;
}
.upload-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #0d6efd, #0dcaf0);
    transition: width 0.3s ease;
}
CSS;
require_once 'includes/header.php';
?>
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 text-gray-800">系统配置</h1>
                        <p class="text-muted">管理网站的基本信息、外观和功能设置</p>
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary" onclick="document.getElementById('configForm').submit()">
                            <i class="bi bi-save me-1"></i> 保存所有更改
                        </button>
                    </div>
                </div>
                
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i> <?= e($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= e($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <form method="POST" id="configForm" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="logo" id="logoUrl" value="<?= e($config['logo'] ?? '') ?>">

                    <div class="card mb-4">
                        <div class="card-header bg-white p-0 border-bottom-0">
                            <ul class="nav nav-tabs card-header-tabs m-0 px-3" id="configTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="tab-basic" data-bs-toggle="tab" data-bs-target="#content-basic" type="button" role="tab">
                                        <i class="bi bi-gear me-2"></i> 基本设置
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-contact" data-bs-toggle="tab" data-bs-target="#content-contact" type="button" role="tab">
                                        <i class="bi bi-telephone me-2"></i> 联系方式
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-advanced" data-bs-toggle="tab" data-bs-target="#content-advanced" type="button" role="tab">
                                        <i class="bi bi-sliders me-2"></i> 高级设置
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-payment" data-bs-toggle="tab" data-bs-target="#content-payment" type="button" role="tab">
                                        <i class="bi bi-wallet2 me-2"></i> 支付设置
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="tab-content" id="configTabContent">
                        <!-- 基本设置 -->
                        <div class="tab-pane fade show active" id="content-basic" role="tabpanel">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>基本信息</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">站点名称</label>
                                                <input type="text" name="website_name" class="form-control" value="<?= e($config['website_name']) ?>" required>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">站点所有者(Name)</label>
                                                <input type="text" name="website_author" class="form-control" value="<?= e($config['website_author'] ?? '') ?>" placeholder="您的名字">
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">网站开办时间</label>
                                                <input type="datetime-local" name="website_start_time" class="form-control" 
                                                       value="<?= !empty($config['website_start_time']) ? date('Y-m-d\TH:i', strtotime($config['website_start_time'])) : '' ?>">
                                                <div class="form-text">设置后将在博客页脚显示网站已运行时间。</div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="card">
                                        <div class="card-header">
                                            <h5>Logo与图标</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-4">
                                                <label class="form-label">网站图标 (Favicon)</label>
                                                <div class="upload-area">
                                                    <div id="faviconPreview" class="preview-box" style="width: 80px; height: 80px;">
                                                        <?php if (!empty($config['favicon'])): ?>
                                                        <img src="<?= e($config['favicon']) ?>?v=<?= time() ?>" style="max-width: 48px; max-height: 48px;" loading="lazy">
                                                        <?php else: ?>
                                                        <i class="bi bi-image text-muted fs-3"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <input type="file" id="faviconInput" accept=".ico,.png,.jpg,.jpeg,.gif" class="d-none">
                                                        <button type="button" class="btn btn-outline-primary btn-sm mb-2" onclick="document.getElementById('faviconInput').click()">
                                                            <i class="bi bi-upload me-1"></i> 更换图标
                                                        </button>
                                                        <div class="form-text">支持 ICO, PNG, JPG (推荐 32x32 或 64x64)</div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">网站Logo</label>
                                                <div class="upload-area">
                                                    <div id="logoPreview" class="preview-box" style="width: 160px; height: 80px;">
                                                        <?php if (!empty($config['logo'])): ?>
                                                        <img src="<?= e($config['logo']) ?>" style="max-width: 90%; max-height: 90%;" loading="lazy">
                                                        <?php else: ?>
                                                        <i class="bi bi-image text-muted fs-3"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <input type="file" id="logoInput" accept="image/*" class="d-none">
                                                        <button type="button" class="btn btn-outline-primary btn-sm mb-2" onclick="document.getElementById('logoInput').click()">
                                                            <i class="bi bi-upload me-1"></i> 更换Logo
                                                        </button>
                                                        <div class="form-text">支持 PNG, JPG, ICO (推荐透明背景)</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                        </div>



                        <!-- 联系方式 -->
                        <div class="tab-pane fade" id="content-contact" role="tabpanel">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>联系方式</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">QQ号(用于网站首页头像与联系方式)</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-tencent-qq"></i></span>
                                                        <input type="text" name="contact_qq" class="form-control" value="<?= e($config['contact_qq'] ?? '') ?>">
                                                    </div>
                                                </div>

                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">微信号</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-wechat"></i></span>
                                                        <input type="text" name="social_wechat" class="form-control" value="<?= e($config['social_wechat'] ?? '') ?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">联系邮箱</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                                        <input type="email" name="contact_email" class="form-control" value="<?= e($config['contact_email']) ?>">
                                                    </div>
                                                </div>

                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Github</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-github"></i></span>
                                                        <input type="text" name="social_github" class="form-control" value="<?= e($config['social_github'] ?? '') ?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">抖音号</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-tiktok"></i></span>
                                                        <input type="text" name="social_douyin" class="form-control" value="<?= e($config['social_douyin'] ?? '') ?>">
                                                    </div>
                                                </div>

                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">快手号</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-camera-video"></i></span>
                                                        <input type="text" name="social_kuaishou" class="form-control" value="<?= e($config['social_kuaishou'] ?? '') ?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">B站号</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-play-btn"></i></span>
                                                        <input type="text" name="social_bilibili" class="form-control" value="<?= e($config['social_bilibili'] ?? '') ?>">
                                                    </div>
                                                </div>

                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">小红书号</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-journal-album"></i></span>
                                                        <input type="text" name="social_xiaohongshu" class="form-control" value="<?= e($config['social_xiaohongshu'] ?? '') ?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">WhatsApp</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-whatsapp"></i></span>
                                                        <input type="text" name="social_whatsapp" class="form-control" value="<?= e($config['social_whatsapp'] ?? '') ?>">
                                                    </div>
                                                </div>

                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">X (Twitter)</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-twitter"></i></span>
                                                        <input type="text" name="social_x" class="form-control" value="<?= e($config['social_x'] ?? '') ?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Discord</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-discord"></i></span>
                                                        <input type="text" name="social_discord" class="form-control" value="<?= e($config['social_discord'] ?? '') ?>">
                                                    </div>
                                                </div>

                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">YouTube</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="bi bi-youtube"></i></span>
                                                        <input type="text" name="social_youtube" class="form-control" value="<?= e($config['social_youtube'] ?? '') ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                        </div>



                        <!-- 高级设置 -->
                        <div class="tab-pane fade" id="content-advanced" role="tabpanel">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>SEO 优化</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">Robot 网站介绍</label>
                                                <textarea name="robot_description" class="form-control" rows="4" maxlength="300" placeholder="用于搜索引擎收录的网站介绍，建议160字符以内"><?= e($config['robot_description'] ?? '') ?></textarea>
                                                <div class="d-flex justify-content-between mt-1">
                                                    <small class="text-muted">此内容将显示在搜索引擎结果中。</small>
                                                    <small id="robotDescLength" class="text-muted">0 / 300</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="card">
                                        <div class="card-header">
                                            <h5>友链信息</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">友链网站描述</label>
                                                <input type="text" name="description" class="form-control" value="<?= e($config['description'] ?? '') ?>" placeholder="显示在友链页面的网站简短描述">
                                                <small class="text-muted">此内容将显示在友情链接页面的"本站信息"中。</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="card">
                                        <div class="card-header">
                                            <h5>邮件服务</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">运行模式</label>
                                                <select name="email_mode" class="form-select" id="emailModeSelect">
                                                    <option value="test" <?= ($config['email_mode'] ?? 'test') === 'test' ? 'selected' : '' ?>>测试模式 (仅显示验证码，不发送)</option>
                                                    <option value="production" <?= ($config['email_mode'] ?? 'test') === 'production' ? 'selected' : '' ?>>生产模式 (发送真实邮件)</option>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">允许注册的邮箱后缀</label>
                                                <textarea name="allowed_email_domains" class="form-control" rows="3" placeholder="qq.com,163.com,gmail.com"><?= e($config['allowed_email_domains'] ?? 'qq.com,vip.qq.com,foxmail.com,163.com,126.com,yeah.net,sina.com,sina.cn,sohu.com,139.com,aliyun.com,gmail.com,outlook.com,hotmail.com,live.com,yahoo.com,yahoo.co.jp,icloud.com,proton.me,protonmail.com,mail.com,gmx.com,gmx.de') ?></textarea>
                                                <div class="form-text">多个后缀请用英文逗号分隔，例如：qq.com,163.com。不要加 @ 符号。</div>
                                            </div>

                                            <div id="smtpSettings" style="<?= ($config['email_mode'] ?? 'test') === 'test' ? 'display: none;' : '' ?>">
                                                <h6 class="mb-3 mt-4 text-primary"><i class="bi bi-hdd-network me-2"></i>SMTP 配置</h6>
                                                
                                                <div class="row">
                                                    <div class="col-md-8 mb-3">
                                                        <label class="form-label">SMTP 服务器地址</label>
                                                        <input type="text" name="smtp_host" class="form-control" value="<?= e($config['smtp_host'] ?? 'smtp.qq.com') ?>" placeholder="例如: smtp.qq.com">
                                                    </div>
                                                    <div class="col-md-4 mb-3">
                                                        <label class="form-label">端口</label>
                                                        <input type="number" name="smtp_port" class="form-control" value="<?= e($config['smtp_port'] ?? '465') ?>" placeholder="例如: 465">
                                                    </div>
                                                </div>

                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">SMTP 用户名 (邮箱账号)</label>
                                                        <input type="email" name="smtp_username" class="form-control" value="<?= e($config['smtp_username'] ?? '') ?>" placeholder="example@qq.com">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">SMTP 密码 (或授权码)</label>
                                                        <div class="input-group">
                                                            <input type="password" name="smtp_password" class="form-control" value="<?= e($config['smtp_password'] ?? '') ?>" placeholder="请输入授权码">
                                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword(this)">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                        </div>
                                                        <div class="form-text">QQ邮箱请使用授权码，而非QQ密码</div>
                                                    </div>
                                                </div>

                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">加密方式</label>
                                                        <select name="smtp_encryption" class="form-select">
                                                            <option value="ssl" <?= ($config['smtp_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL (推荐, 端口465)</option>
                                                            <option value="tls" <?= ($config['smtp_encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS (端口587)</option>
                                                            <option value="" <?= ($config['smtp_encryption'] ?? '') === '' ? 'selected' : '' ?>>无加密 (不推荐)</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">发件人名称</label>
                                                        <input type="text" name="smtp_from_name" class="form-control" value="<?= e($config['smtp_from_name'] ?? 'LyGalaxy') ?>" placeholder="例如: 我的博客">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="card">
                                        <div class="card-header">
                                            <h5><i class="bi bi-shield-lock me-2"></i>登录安全设置</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">单用户最大同时在线设备数</label>
                                                    <input type="number" name="max_devices" class="form-control" min="1" max="10" value="<?= e($config['max_devices'] ?? 2) ?>" required>
                                                    <div class="form-text">设置每个用户最多可同时在多少台设备上登录（1-10），超出限制将自动下线最久未活跃的设备。</div>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">记住我有效期（天）</label>
                                                    <input type="number" name="remember_duration" class="form-control" min="1" max="365" value="<?= e($config['remember_duration'] ?? 30) ?>" required>
                                                    <div class="form-text">勾选"记住我"后 Cookie 的有效天数（1-365天）。</div>
                                                </div>
                                            </div>

                                        </div>
                                    </div>

                                    <div class="card">
                                        <div class="card-header">
                                            <h5>备案信息</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">ICP备案号</label>
                                                    <input type="text" name="icp_record" class="form-control"
                                                           value="<?= e($beianConfig['ICP_RECORD'] ?? '') ?>"
                                                           placeholder="京ICP备12345678号-1">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">公安备案号</label>
                                                    <input type="text" name="public_security_record" class="form-control"
                                                           value="<?= e($beianConfig['PUBLIC_SECURITY_RECORD'] ?? '') ?>"
                                                           placeholder="京公网安备11010502012345号">
                                                </div>
                                            </div>
                                            <div class="alert alert-warning border-0 bg-warning bg-opacity-10 small mb-0">
                                                <i class="bi bi-exclamation-triangle me-1"></i> 请确保备案信息真实有效，将显示在网站底部。
                                            </div>
                                        </div>
                                    </div>

                            <div class="card">
                                <div class="card-header">
                                    <h5>协议与政策</h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label">服务条款内容 (支持HTML)</label>
                                        <textarea name="terms_content" class="form-control" rows="10"><?= e($config['terms_content'] ?? '') ?></textarea>
                                        <small class="text-muted">留空则使用系统默认服务条款。支持HTML标签。</small>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">隐私政策内容 (支持HTML)</label>
                                        <textarea name="privacy_content" class="form-control" rows="10"><?= e($config['privacy_content'] ?? '') ?></textarea>
                                        <small class="text-muted">留空则使用系统默认隐私政策。支持HTML标签。</small>
                                    </div>
                                </div>
                            </div>

                                    <div class="card">
                                        <div class="card-header">
                                            <h5>页脚设置</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">页脚附加信息</label>
                                                <textarea name="footer_extra" class="form-control" rows="5" placeholder="例如：Powered by LyGalaxy"><?= e($config['footer_extra'] ?? '') ?></textarea>
                                                <div class="form-text">显示在网站底部的附加文字，支持HTML</div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="card">
                                        <div class="card-header">
                                            <h5>跳转设置</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label">跳转白名单</label>
                                                <textarea name="redirect_whitelist" class="form-control" rows="5" placeholder="example.com&#10;https://baidu.com/search?q=test&#10;github.com/user/repo"><?= e($config['redirect_whitelist'] ?? '') ?></textarea>
                                                <div class="form-text">支持域名和完整链接，每行一个。域名会匹配该域名及所有子域名，完整链接只匹配该具体地址。</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                        <!-- 支付设置 -->
                        <div class="tab-pane fade" id="content-payment" role="tabpanel">
                            <div class="card">
                                <div class="card-header">
                                    <h5>易支付网关设置</h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label">支付API地址</label>
                                        <input type="url" name="epay_url" class="form-control" value="<?= e($config['epay_url'] ?? '') ?>" placeholder="https://pay.example.com/">
                                        <div class="form-text">必须以 http:// 或 https:// 开头，以 / 结尾</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">商户ID (PID)</label>
                                        <input type="text" name="epay_pid" class="form-control" value="<?= e($config['epay_pid'] ?? '') ?>" placeholder="10001">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">商户密钥 (Key)</label>
                                        <input type="password" name="epay_key" class="form-control" value="<?= e($config['epay_key'] ?? '') ?>" placeholder="您的商户密钥">
                                    </div>
                                </div>
                            </div>
                        </div>
                        </div>
                    </div>
                </form>

    <?php include 'includes/markdown_editor.php'; ?>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>

    <script>
        // Robot描述字符计数
        const robotDescTextarea = document.querySelector('textarea[name="robot_description"]');
        const robotDescLength = document.getElementById('robotDescLength');
        
        function updateRobotDescLength() {
            if (!robotDescTextarea || !robotDescLength) return;
            const length = robotDescTextarea.value.length;
            robotDescLength.textContent = `${length} / 300`;
            if (length > 160) robotDescLength.className = 'text-warning';
            else if (length > 300) robotDescLength.className = 'text-danger';
            else robotDescLength.className = 'text-muted';
        }
        
        if (robotDescTextarea) {
            updateRobotDescLength();
            robotDescTextarea.addEventListener('input', updateRobotDescLength);
        }

        // 通用上传处理函数
        function handleUpload(inputId, apiUrl, paramName, previewId, urlInputId, successMsg, type = 'image', clearBtnId = null) {
            const input = document.getElementById(inputId);
            if (!input) return;

            input.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;
                
                // 验证
                if (type === 'image' && !file.type.startsWith('image/')) {
                    alert('请选择图片文件');
                    return;
                }
                if (type === 'video' && !file.type.startsWith('video/')) {
                    alert('请选择视频文件');
                    return;
                }

                // 创建进度条
                const progressOverlay = createProgressOverlay(file.name, file.size);
                
                const formData = new FormData();
                formData.append(paramName, file);
                
                const xhr = new XMLHttpRequest();
                let startTime = Date.now();
                let lastLoaded = 0;
                let lastTime = startTime;
                
                xhr.upload.addEventListener('progress', (event) => {
                    if (event.lengthComputable) {
                        const percent = (event.loaded / event.total) * 100;
                        const currentTime = Date.now();
                        const timeDiff = (currentTime - lastTime) / 1000;
                        const loadedDiff = event.loaded - lastLoaded;
                        const speed = timeDiff > 0 ? loadedDiff / timeDiff : 0;
                        updateProgress(progressOverlay, percent, speed);
                        lastLoaded = event.loaded;
                        lastTime = currentTime;
                    }
                });
                
                xhr.addEventListener('load', () => {
                    if (xhr.status === 200) {
                        try {
                            const data = JSON.parse(xhr.responseText);
                            if (data.success) {
                                const previewBox = document.getElementById(previewId);
                                if (type === 'video') {
                                    previewBox.innerHTML = `<video src="${data.url}" style="width: 100%; height: 100%; object-fit: cover;" muted loading="lazy"></video>`;
                                } else {
                                    const suffix = inputId === 'faviconInput' ? `?v=${Date.now()}` : '';
                                    const style = inputId === 'faviconInput' ? 'max-width: 48px; max-height: 48px;' : 'width: 100%; height: 100%; object-fit: cover;';
                                    previewBox.innerHTML = `<img src="${data.url}${suffix}" style="${style}" loading="lazy">`;
                                }
                                
                                if (urlInputId) {
                                    document.getElementById(urlInputId).value = data.url;
                                }
                                
                                // 显示删除按钮
                                if (clearBtnId) {
                                    const clearBtn = document.getElementById(clearBtnId);
                                    if (clearBtn) clearBtn.classList.remove('d-none');
                                }
                                
                                showUploadSuccess(progressOverlay, successMsg);
                                // Favicon 特殊处理：刷新
                                if (inputId === 'faviconInput') {
                                    setTimeout(() => location.reload(), 1500);
                                }
                            } else {
                                showUploadError(progressOverlay, data.error || '上传失败');
                            }
                        } catch (error) {
                            showUploadError(progressOverlay, '服务器响应错误');
                        }
                    } else {
                        showUploadError(progressOverlay, `上传失败 (${xhr.status})`);
                    }
                });
                
                xhr.addEventListener('error', () => showUploadError(progressOverlay, '网络错误'));
                xhr.addEventListener('abort', () => showUploadError(progressOverlay, '上传取消'));
                
                xhr.open('POST', apiUrl);
                xhr.send(formData);
            });
        }

        // 初始化上传
        handleUpload('faviconInput', '/admin/upload_favicon.php', 'favicon', 'faviconPreview', null, '图标上传成功');
        handleUpload('logoInput', '/admin/upload_image.php', 'image', 'logoPreview', 'logoUrl', 'Logo上传成功');

        // 进度条相关辅助函数
        function createProgressOverlay(fileName, fileSize) {
            const overlay = document.createElement('div');
            overlay.className = 'upload-progress-overlay';
            overlay.innerHTML = `
                <div class="upload-progress-container">
                    <div class="d-flex align-items-center mb-3">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-2 me-3">
                            <i class="bi bi-cloud-upload text-primary fs-4"></i>
                        </div>
                        <div>
                            <h5 class="mb-0">正在上传</h5>
                            <small class="text-muted">${fileName} (${formatFileSize(fileSize)})</small>
                        </div>
                    </div>
                    <div class="upload-progress-bar-container">
                        <div class="upload-progress-bar" style="width: 0%"></div>
                    </div>
                    <div class="d-flex justify-content-between text-muted small">
                        <span class="progress-percent">0%</span>
                        <span class="progress-speed">准备中...</span>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            return overlay;
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function updateProgress(overlay, percent, speed) {
            const bar = overlay.querySelector('.upload-progress-bar');
            const percentEl = overlay.querySelector('.progress-percent');
            const speedEl = overlay.querySelector('.progress-speed');
            if (bar) bar.style.width = percent + '%';
            if (percentEl) percentEl.textContent = Math.round(percent) + '%';
            if (speedEl && speed) speedEl.textContent = formatFileSize(speed) + '/s';
        }

        function showUploadSuccess(overlay, msg) {
            const container = overlay.querySelector('.upload-progress-container');
            container.innerHTML = `
                <div class="text-center py-3">
                    <div class="mb-3">
                        <i class="bi bi-check-circle-fill text-success fs-1"></i>
                    </div>
                    <h5 class="text-success">${msg}</h5>
                </div>
            `;
            setTimeout(() => overlay.remove(), 1500);
        }

        function showUploadError(overlay, msg) {
            const container = overlay.querySelector('.upload-progress-container');
            container.innerHTML = `
                <div class="text-center py-3">
                    <div class="mb-3">
                        <i class="bi bi-x-circle-fill text-danger fs-1"></i>
                    </div>
                    <h5 class="text-danger">上传失败</h5>
                    <p class="text-muted small">${msg}</p>
                </div>
            `;
            setTimeout(() => overlay.remove(), 2000);
        }

        // 邮件模式切换
        const emailModeSelect = document.getElementById('emailModeSelect');
        const smtpSettings = document.getElementById('smtpSettings');
        if (emailModeSelect && smtpSettings) {
            emailModeSelect.addEventListener('change', function() {
                if (this.value === 'production') {
                    smtpSettings.style.display = 'block';
                } else {
                    smtpSettings.style.display = 'none';
                }
            });
        }

        // 密码显示切换
        function togglePassword(btn) {
            const input = btn.previousElementSibling;
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }

    </script>
<?php require_once 'includes/footer.php'; ?>
