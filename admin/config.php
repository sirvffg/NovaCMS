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
                    <input type="hidden" name="home_bg_image" id="bgImageUrl" value="<?= e($config['home_bg_image'] ?? '') ?>">
                    <input type="hidden" name="home_bg_video" id="bgVideoUrl" value="<?= e($config['home_bg_video'] ?? '') ?>">

                    <div class="card mb-4">
                        <div class="card-header bg-white p-0 border-bottom-0">
                            <ul class="nav nav-tabs card-header-tabs m-0 px-3" id="configTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="tab-basic" data-bs-toggle="tab" data-bs-target="#content-basic" type="button" role="tab">
                                        <i class="bi bi-gear me-2"></i> 基本设置
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-home" data-bs-toggle="tab" data-bs-target="#content-home" type="button" role="tab">
                                        <i class="bi bi-house-door me-2"></i> 主页设置
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-appearance" data-bs-toggle="tab" data-bs-target="#content-appearance" type="button" role="tab">
                                        <i class="bi bi-palette me-2"></i> 外观设置
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-media" data-bs-toggle="tab" data-bs-target="#content-media" type="button" role="tab">
                                        <i class="bi bi-music-note-beamed me-2"></i> 播放器设置
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-newyear" data-bs-toggle="tab" data-bs-target="#content-newyear" type="button" role="tab">
                                        <i class="bi bi-gift me-2"></i> 新年祝福
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-ai" data-bs-toggle="tab" data-bs-target="#content-ai" type="button" role="tab">
                                        <i class="bi bi-stars me-2"></i> AI 管理
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
                                                <label class="form-label">一言Api地址(也可以是一句话介绍)</label>
                                                <input type="text" name="website_intro" class="form-control" value="<?= e($config['website_intro'] ?? '') ?>" placeholder="一言Api地址">
                                            </div>

                                            <div class="mb-3">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" id="use_local_hitokoto" name="use_local_hitokoto" value="1" <?= !empty($config['use_local_hitokoto']) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="use_local_hitokoto">使用本站一言</label>
                                                    <small class="text-muted d-block">开启后优先使用后台添加的本站一言（需先在 <a href="hitokoto.php" target="_blank">一言管理</a> 中添加内容）</small>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">网站公告(支持Markdown)</label>
                                                <textarea name="website_announcement" id="website_announcement" class="form-control" rows="4"><?= e($config['website_announcement'] ?? '') ?></textarea>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">公告发布日期</label>
                                                    <input type="datetime-local" name="website_announcement_date" class="form-control" value="<?= !empty($config['website_announcement_date']) ? date('Y-m-d\TH:i', strtotime($config['website_announcement_date'])) : '' ?>">
                                                    <small class="text-muted">设置公告的发布时间，用于判断用户是否已查看</small>
                                                </div>
                                                <div class="col-md-6 mb-3 d-flex align-items-end">
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" id="website_announcement_popup" name="website_announcement_popup" value="1" <?= !empty($config['website_announcement_popup']) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="website_announcement_popup">弹窗展示公告</label>
                                                        <small class="text-muted d-block">开启后用户首次访问首页时会弹窗显示公告</small>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <div class="form-check form-switch">
                                                    <input type="checkbox" class="form-check-input" id="website_announcement_enable" name="website_announcement_enable" value="1" <?= !empty($config['website_announcement_enable']) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="website_announcement_enable">开启公告展示</label>
                                                    <small class="text-muted d-block">关闭后首页不显示公告按钮和弹窗</small>
                                                </div>
                                            </div>
                                            
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
                                            
                                            <div class="mb-3">
                                                <label class="form-label">个人简短介绍(在主页简短介绍显示) (支持Markdown)</label>
                                                <textarea name="website_description" id="website_description" class="form-control" rows="8"><?= e($config['website_description']) ?></textarea>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">个人详细介绍(支持Markdown)</label>
                                                <textarea name="website_detail" id="website_detail" class="form-control" rows="12"><?= e($config['website_detail'] ?? '') ?></textarea>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">网站开办时间</label>
                                                <input type="datetime-local" name="website_start_time" class="form-control" 
                                                       value="<?= !empty($config['website_start_time']) ? date('Y-m-d\TH:i', strtotime($config['website_start_time'])) : '' ?>">
                                                <div class="form-text">设置后将在博客页脚显示网站已运行时间。</div>
                                            </div>
                                        </div>
                                    </div>
                        </div>

                        <!-- 主页设置 -->
                        <div class="tab-pane fade" id="content-home" role="tabpanel">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5>主页小站链接</h5>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="openLinkModal()">
                                        <i class="bi bi-plus-lg"></i> 添加链接
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle" id="homeLinksTable">
                                            <thead>
                                                <tr>
                                                    <th style="width: 50px;">排序</th>
                                                    <th style="width: 60px;">图标</th>
                                                    <th>名称</th>
                                                    <th>链接</th>
                                                    <th>徽章</th>
                                                    <th>状态</th>
                                                    <th style="width: 150px;">操作</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <!-- JS加载数据 -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="card mt-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5>我的个人项目</h5>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="openProjectModal()">
                                        <i class="bi bi-plus-lg"></i> 添加项目
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle" id="myProjectsTable">
                                            <thead>
                                                <tr>
                                                    <th style="width: 50px;">排序</th>
                                                    <th style="width: 60px;">介绍图</th>
                                                    <th>项目名称/描述</th>
                                                    <th>标签</th>
                                                    <th>开始时间</th>
                                                    <th>状态</th>
                                                    <th style="width: 150px;">操作</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <!-- JS加载数据 -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="card mt-4">
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

                        </div>

                        <!-- 外观设置 -->
                        <div class="tab-pane fade" id="content-appearance" role="tabpanel">
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

                                    <div class="card">
                                        <div class="card-header">
                                            <h5>背景设置</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-4">
                                                <div class="form-check form-switch mb-3">
                                                    <input class="form-check-input" type="checkbox" id="useBingBg" name="use_bing_bg" value="1" <?= !empty($config['use_bing_bg']) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="useBingBg">使用Bing每日图片作为默认背景</label>
                                                </div>
                                                <div class="mb-3 ps-4" id="bingApiContainer" style="<?= empty($config['use_bing_bg']) ? 'display: none;' : '' ?>">
                                                    <label class="form-label">Bing API 地址</label>
                                                    <input type="text" name="bing_api" class="form-control" value="<?= e($config['bing_api'] ?? '') ?>" placeholder="输入API地址">
                                                    <div class="form-text">设置后将优先使用此API获取背景图片。如果不填写，则使用默认的随机图片API。</div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 mb-4">
                                                    <label class="form-label">自定义背景图片</label>
                                                    <div class="preview-box mb-2" id="bgImagePreview" style="height: 150px;">
                                                        <?php if (!empty($config['home_bg_image'])): ?>
                                                        <img src="<?= e($config['home_bg_image']) ?>" style="width: 100%; height: 100%; object-fit: cover;" loading="lazy">
                                                        <?php else: ?>
                                                        <div class="text-center text-muted">
                                                            <i class="bi bi-image fs-1 d-block mb-1"></i>
                                                            <small>未设置</small>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="d-flex gap-2">
                                                        <input type="file" id="bgImageInput" accept="image/*" class="d-none">
                                                        <button type="button" class="btn btn-sm btn-outline-primary flex-grow-1" onclick="document.getElementById('bgImageInput').click()">
                                                            上传图片
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger <?= empty($config['home_bg_image']) ? 'd-none' : '' ?>" id="clearBgImageBtn" onclick="clearBgImage()">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="col-md-6 mb-4">
                                                    <label class="form-label">自定义背景视频</label>
                                                    <div class="preview-box mb-2" id="bgVideoPreview" style="height: 150px;">
                                                        <?php if (!empty($config['home_bg_video'])): ?>
                                                        <video src="<?= e($config['home_bg_video']) ?>" style="width: 100%; height: 100%; object-fit: cover;" muted loading="lazy"></video>
                                                        <?php else: ?>
                                                        <div class="text-center text-muted">
                                                            <i class="bi bi-camera-video fs-1 d-block mb-1"></i>
                                                            <small>未设置</small>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="d-flex gap-2">
                                                        <input type="file" id="bgVideoInput" accept="video/*" class="d-none">
                                                        <button type="button" class="btn btn-sm btn-outline-primary flex-grow-1" onclick="document.getElementById('bgVideoInput').click()">
                                                            上传视频
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger <?= empty($config['home_bg_video']) ? 'd-none' : '' ?>" id="clearBgVideoBtn" onclick="clearBgVideo()">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="alert alert-light border small">
                                                <i class="bi bi-info-circle me-1"></i> <strong>显示优先级：</strong> 背景视频 > 背景图片 > Bing每日图片 > 默认背景
                                            </div>
                                        </div>
                                    </div>
                        </div>

                        <!-- 媒体设置 -->
                        <div class="tab-pane fade" id="content-media" role="tabpanel">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>网易云音乐播放器</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="form-check form-switch mb-4">
                                                <input class="form-check-input" type="checkbox" id="musicEnabled" name="music_enabled" value="1" <?= !empty($config['music_enabled']) ? 'checked' : '' ?>>
                                                <label class="form-check-label fw-bold" for="musicEnabled">启用全局音乐播放器</label>
                                            </div>
                                            
                                            <div class="alert alert-info border-0 bg-info bg-opacity-10 small mb-4">
                                                <div class="d-flex align-items-center justify-content-between">
                                                    <div>
                                                        <i class="bi bi-info-circle-fill me-2"></i>
                                                        播放器核心资源（CSS/JS）存储在本地 <code>/config/music_local/</code> 目录。
                                                    </div>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="updateMusicResources(this)">
                                                        <i class="bi bi-cloud-download me-1"></i> 更新核心资源
                                                    </button>
                                                </div>
                                            </div>

                                            <div id="musicSettings" class="<?= empty($config['music_enabled']) ? 'd-none' : '' ?>">
                                                <div class="row g-3 mb-4">
                                                    <div class="col-md-6">
                                                        <label class="form-label">歌单 ID</label>
                                                        <input type="text" name="music_playlist_id" class="form-control" 
                                                               value="<?= e($config['music_playlist_id'] ?? '14273792576') ?>"
                                                               placeholder="例如: 14273792576">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">单曲 ID (可选)</label>
                                                        <input type="text" name="music_song_id" class="form-control" 
                                                               value="<?= e($config['music_song_id'] ?? '') ?>"
                                                               placeholder="例如: 1234567890">
                                                    </div>
                                                </div>

                                                <div class="row g-3 mb-4">
                                                    <div class="col-md-6">
                                                        <label class="form-label">显示位置</label>
                                                        <select name="music_position" class="form-select">
                                                            <option value="bottom-left" <?= ($config['music_position'] ?? 'bottom-left') === 'bottom-left' ? 'selected' : '' ?>>左下角</option>
                                                            <option value="bottom-right" <?= ($config['music_position'] ?? '') === 'bottom-right' ? 'selected' : '' ?>>右下角</option>
                                                            <option value="top-left" <?= ($config['music_position'] ?? '') === 'top-left' ? 'selected' : '' ?>>左上角</option>
                                                            <option value="top-right" <?= ($config['music_position'] ?? '') === 'top-right' ? 'selected' : '' ?>>右上角</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">播放器主题</label>
                                                        <select name="music_theme" class="form-select">
                                                            <option value="auto" <?= ($config['music_theme'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>自动（跟随系统）</option>
                                                            <option value="light" <?= ($config['music_theme'] ?? '') === 'light' ? 'selected' : '' ?>>浅色</option>
                                                            <option value="dark" <?= ($config['music_theme'] ?? '') === 'dark' ? 'selected' : '' ?>>深色</option>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="card bg-light border-0">
                                                    <div class="card-body">
                                                        <h6 class="card-title mb-3 fw-bold text-secondary"><i class="bi bi-toggles me-2"></i>功能开关</h6>
                                                        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
                                                            <!-- 嵌入模式 -->
                                                            <div class="col">
                                                                <div class="bg-white p-3 rounded shadow-sm h-100 d-flex align-items-center justify-content-between position-relative">
                                                                    <div class="d-flex align-items-center">
                                                                        <div class="bg-primary bg-opacity-10 p-2 rounded me-3 text-primary d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                                                            <i class="bi bi-box-arrow-in-down-right fs-4"></i>
                                                                        </div>
                                                                        <div>
                                                                            <label class="d-block fw-bold text-dark mb-0 stretched-link" for="musicEmbed" style="cursor: pointer;">嵌入模式</label>
                                                                            <small class="text-muted" style="font-size: 0.75rem;">仅对单曲有效</small>
                                                                        </div>
                                                                    </div>
                                                                    <div class="form-check form-switch mb-0" style="min-height: auto; padding-left: 0;">
                                                                        <input class="form-check-input m-0" type="checkbox" name="music_embed" id="musicEmbed" value="1" <?= !empty($config['music_embed']) ? 'checked' : '' ?> style="width: 2.5em; height: 1.25em;">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <!-- 默认最小化 -->
                                                            <div class="col">
                                                                <div class="bg-white p-3 rounded shadow-sm h-100 d-flex align-items-center justify-content-between position-relative">
                                                                    <div class="d-flex align-items-center">
                                                                        <div class="bg-warning bg-opacity-10 p-2 rounded me-3 text-warning d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                                                            <i class="bi bi-dash-square fs-4"></i>
                                                                        </div>
                                                                        <div>
                                                                            <label class="d-block fw-bold text-dark mb-0 stretched-link" for="musicDefaultMinimized" style="cursor: pointer;">默认最小化</label>
                                                                            <small class="text-muted" style="font-size: 0.75rem;">初始状态收起</small>
                                                                        </div>
                                                                    </div>
                                                                    <div class="form-check form-switch mb-0" style="min-height: auto; padding-left: 0;">
                                                                        <input class="form-check-input m-0" type="checkbox" name="music_default_minimized" id="musicDefaultMinimized" value="1" <?= !empty($config['music_default_minimized']) ? 'checked' : '' ?> style="width: 2.5em; height: 1.25em;">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <!-- 显示歌词 -->
                                                            <div class="col">
                                                                <div class="bg-white p-3 rounded shadow-sm h-100 d-flex align-items-center justify-content-between position-relative">
                                                                    <div class="d-flex align-items-center">
                                                                        <div class="bg-info bg-opacity-10 p-2 rounded me-3 text-info d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                                                            <i class="bi bi-file-music fs-4"></i>
                                                                        </div>
                                                                        <div>
                                                                            <label class="d-block fw-bold text-dark mb-0 stretched-link" for="musicLyric" style="cursor: pointer;">显示歌词</label>
                                                                            <small class="text-muted" style="font-size: 0.75rem;">开启歌词滚动</small>
                                                                        </div>
                                                                    </div>
                                                                    <div class="form-check form-switch mb-0" style="min-height: auto; padding-left: 0;">
                                                                        <input class="form-check-input m-0" type="checkbox" name="music_lyric" id="musicLyric" value="1" <?= !empty($config['music_lyric']) ? 'checked' : '' ?> style="width: 2.5em; height: 1.25em;">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <!-- 自动播放 -->
                                                            <div class="col">
                                                                <div class="bg-white p-3 rounded shadow-sm h-100 d-flex align-items-center justify-content-between position-relative">
                                                                    <div class="d-flex align-items-center">
                                                                        <div class="bg-success bg-opacity-10 p-2 rounded me-3 text-success d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                                                            <i class="bi bi-play-circle fs-4"></i>
                                                                        </div>
                                                                        <div>
                                                                            <label class="d-block fw-bold text-dark mb-0 stretched-link" for="musicAutoplay" style="cursor: pointer;">自动播放</label>
                                                                            <small class="text-muted" style="font-size: 0.75rem;">加载后自动播放</small>
                                                                        </div>
                                                                    </div>
                                                                    <div class="form-check form-switch mb-0" style="min-height: auto; padding-left: 0;">
                                                                        <input class="form-check-input m-0" type="checkbox" name="music_autoplay" id="musicAutoplay" value="1" <?= !empty($config['music_autoplay']) ? 'checked' : '' ?> style="width: 2.5em; height: 1.25em;">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <!-- 自动暂停 -->
                                                            <div class="col">
                                                                <div class="bg-white p-3 rounded shadow-sm h-100 d-flex align-items-center justify-content-between position-relative">
                                                                    <div class="d-flex align-items-center">
                                                                        <div class="bg-danger bg-opacity-10 p-2 rounded me-3 text-danger d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                                                            <i class="bi bi-pause-circle fs-4"></i>
                                                                        </div>
                                                                        <div>
                                                                            <label class="d-block fw-bold text-dark mb-0 stretched-link" for="musicAutoPause" style="cursor: pointer;">自动暂停</label>
                                                                            <small class="text-muted" style="font-size: 0.75rem;">与其他媒体互斥</small>
                                                                        </div>
                                                                    </div>
                                                                    <div class="form-check form-switch mb-0" style="min-height: auto; padding-left: 0;">
                                                                        <input class="form-check-input m-0" type="checkbox" name="music_auto_pause" id="musicAutoPause" value="1" <?= !empty($config['music_auto_pause']) ? 'checked' : '' ?> style="width: 2.5em; height: 1.25em;">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                        </div>

                        <!-- 新年祝福设置 -->
                        <div class="tab-pane fade" id="content-newyear" role="tabpanel">
                            <div class="card">
                                <div class="card-header">
                                    <h5>新年祝福设置</h5>
                                </div>
                                <div class="card-body">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="newyear_enable" id="newyearEnable" value="1" <?= !empty($config['newyear_enable']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="newyearEnable">开启新年祝福弹窗</label>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">祝福语</label>
                                        <textarea name="newyear_message" class="form-control" rows="3"><?= e($config['newyear_message'] ?? '祝大家新年快乐，万事如意！') ?></textarea>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">开始时间</label>
                                            <input type="datetime-local" name="newyear_start_time" class="form-control" 
                                                   value="<?= !empty($config['newyear_start_time']) ? date('Y-m-d\TH:i', strtotime($config['newyear_start_time'])) : '' ?>">
                                            <div class="form-text">设置祝福弹窗开始显示的时间，留空则立即开始。</div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">结束时间</label>
                                            <input type="datetime-local" name="newyear_end_time" class="form-control" 
                                                   value="<?= !empty($config['newyear_end_time']) ? date('Y-m-d\TH:i', strtotime($config['newyear_end_time'])) : '' ?>">
                                            <div class="form-text">设置祝福弹窗结束显示的时间，留空则一直显示。</div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">祝福视频</label>
                                        <input type="hidden" name="newyear_video" id="newyearVideoUrl" value="<?= e($config['newyear_video'] ?? '') ?>">
                                        <div class="preview-box mb-2" id="newyearVideoPreview" style="height: 150px;">
                                            <?php if (!empty($config['newyear_video'])): ?>
                                            <video src="<?= e($config['newyear_video']) ?>" style="width: 100%; height: 100%; object-fit: cover;" controls loading="lazy"></video>
                                            <?php else: ?>
                                            <div class="text-center text-muted">
                                                <i class="bi bi-camera-video fs-1 d-block mb-1"></i>
                                                <small>未设置</small>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <input type="file" id="newyearVideoInput" accept="video/*" class="d-none">
                                            <button type="button" class="btn btn-sm btn-outline-primary flex-grow-1" onclick="document.getElementById('newyearVideoInput').click()">
                                                上传视频
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger <?= empty($config['newyear_video']) ? 'd-none' : '' ?>" id="clearNewyearVideoBtn" onclick="clearNewyearVideo()">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">支持 mp4, webm 等格式。上传新视频将覆盖旧视频。</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- AI 管理 -->
                        <div class="tab-pane fade" id="content-ai" role="tabpanel" style="min-height: 600px;">
                            <iframe src="ai_manage.php?embed=1" style="width: 100%; height: 90vh; border: none; display: block;" id="aiManageFrame"></iframe>
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
                                            <h5><i class="bi bi-cloud-upload me-2"></i>图床设置</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="alert alert-info border-0 bg-info bg-opacity-10 mb-3">
                                                <i class="bi bi-info-circle-fill me-1"></i> “启用图床上传”控制后台是否将图片同步到图床；“前端使用图床照片”独立控制访客看到的是图床链接还是本地链接。
                                            </div>
                                            <div class="form-check form-switch mb-3">
                                                <input type="checkbox" class="form-check-input" id="imageBedEnabled" name="image_bed_enabled" value="1" <?= !empty($config['image_bed_enabled']) ? 'checked' : '' ?>>
                                                <label class="form-check-label fw-bold" for="imageBedEnabled">启用图床上传</label>
                                                <div class="form-text">开启后，文章编辑器上传图片时会同时上传到图床。</div>
                                            </div>
                                                <div id="imageBedSettings" style="<?= empty($config['image_bed_enabled']) ? 'display: none;' : '' ?>">
                                                    <div class="mb-3">
                                                        <label class="form-label">图床基础地址</label>
                                                        <input type="url" name="image_bed_api_url" class="form-control" value="<?= e($config['image_bed_api_url'] ?? 'https://image.lygalaxy.cn') ?>" placeholder="https://image.lygalaxy.cn">
                                                        <div class="form-text">填写图床服务的基础地址，系统自动拼接 /api/external/upload 和 /api/external/delete</div>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">API 密钥 (Key)</label>
                                                        <div class="input-group">
                                                            <input type="password" name="image_bed_api_key" id="imageBedApiKey" class="form-control" value="<?= e($config['image_bed_api_key'] ?? '') ?>" placeholder="请输入图床 API 密钥">
                                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword(this)">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                        </div>
                                                        <div class="form-text">图床服务商提供的 API 密钥。</div>
                                                    </div>
                                                </div>
                                            <hr>
                                            <div class="form-check form-switch mb-0">
                                                <input type="checkbox" class="form-check-input" id="imageBedDisplayEnabled" name="image_bed_display_enabled" value="1" <?= !empty($config['image_bed_display_enabled']) ? 'checked' : '' ?>>
                                                <label class="form-check-label fw-bold" for="imageBedDisplayEnabled">前端使用图床照片</label>
                                                <div class="form-text">开启后，访问者看到的图片将使用图床链接（加载更快）；关闭后，前端显示本地图片，但后台仍可正常上传到图床。</div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="card">
                                        <div class="card-header">
                                            <h5><i class="bi bi-collection me-2"></i>图片映射管理</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="row mb-3">
                                                <div class="col-md-4">
                                                    <div class="card bg-primary bg-opacity-10 border-0">
                                                        <div class="card-body text-center py-3">
                                                            <h4 class="mb-0" id="imageMapTotal">-</h4>
                                                            <small class="text-muted">总图片数</small>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="card bg-success bg-opacity-10 border-0">
                                                        <div class="card-body text-center py-3">
                                                            <h4 class="mb-0" id="imageMapWithBed">-</h4>
                                                            <small class="text-muted">已同步图床</small>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="card bg-warning bg-opacity-10 border-0">
                                                        <div class="card-body text-center py-3">
                                                            <h4 class="mb-0" id="imageMapPending">-</h4>
                                                            <small class="text-muted">待上传</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="d-flex gap-2 mb-3 flex-wrap">
                                                <button type="button" class="btn btn-primary" onclick="refreshImageMap()">
                                                    <i class="bi bi-arrow-clockwise me-1"></i>刷新统计
                                                </button>
                                                <button type="button" class="btn btn-info" onclick="scanLocalImages()">
                                                    <i class="bi bi-folder2-open me-1"></i>扫描本地图片
                                                </button>
                                                <button type="button" class="btn btn-secondary" onclick="scanPostsImages()">
                                                    <i class="bi bi-file-text me-1"></i>扫描文章图片
                                                </button>
                                                <button type="button" class="btn btn-success" onclick="batchUploadToImageBed()" id="batchUploadBtn" disabled>
                                                    <i class="bi bi-cloud-arrow-up me-1"></i>批量上传待同步图片
                                                </button>
                                            </div>
                                            <div id="batchUploadProgress" style="display: none;">
                                                <div class="progress mb-2" style="height: 25px;">
                                                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="uploadProgressBar" style="width: 0%"></div>
                                                </div>
                                                <small class="text-muted" id="uploadProgressText">准备上传...</small>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th>文件名</th>
                                                            <th>本地路径</th>
                                                            <th>图床URL</th>
                                                            <th>状态</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="imageMapList">
                                                        <tr><td colspan="4" class="text-center text-muted">加载中...</td></tr>
                                                    </tbody>
                                                </table>
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
    
    <!-- Link Modal (Moved outside of configForm) -->
    <div class="modal fade" id="linkModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="linkModalTitle">添加链接</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="linkForm">
                        <input type="hidden" name="id" id="linkId">
                        <div class="mb-3">
                            <label class="form-label">名称</label>
                            <input type="text" class="form-control" name="name" id="linkName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">链接 URL</label>
                            <input type="text" class="form-control" name="url" id="linkUrl" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">图标 (类名或图片URL)</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="icon" id="linkIcon" placeholder="bi bi-house 或 /assets/..." required>
                                <span class="input-group-text"><i id="iconPreview" class="bi bi-question"></i></span>
                            </div>
                            <div class="d-flex mt-2">
                                <input type="file" id="linkIconUpload" accept="image/*" class="d-none" onchange="uploadLinkIcon(this)">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('linkIconUpload').click()">
                                    <i class="bi bi-upload me-1"></i> 上传图标
                                </button>
                                <div class="form-text ms-2 mt-1">支持上传图片或直接输入 Bootstrap Icons 类名</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">描述</label>
                            <textarea class="form-control" name="description" id="linkDescription" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">徽章文本</label>
                                <input type="text" class="form-control" name="badge_text" id="linkBadgeText">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">徽章颜色</label>
                                <select class="form-select" name="badge_color" id="linkBadgeColor">
                                    <option value="primary">Primary (蓝)</option>
                                    <option value="success">Success (绿)</option>
                                    <option value="danger">Danger (红)</option>
                                    <option value="warning">Warning (黄)</option>
                                    <option value="info">Info (青)</option>
                                    <option value="dark">Dark (黑)</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">排序</label>
                                <input type="number" class="form-control" name="sort_order" id="linkSortOrder" value="0">
                            </div>
                            <div class="col-md-6 mb-3 d-flex align-items-end">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="linkIsActive" checked>
                                    <label class="form-check-label" for="linkIsActive">启用显示</label>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="saveLink()">保存</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>

    <script>
    // Link Management Scripts
    let linkModal;
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Modal
        const linkModalEl = document.getElementById('linkModal');
        if (linkModalEl) {
            linkModal = new bootstrap.Modal(linkModalEl);
        }
        
        // Load initial data
        loadLinks();
        
        // Icon preview
        const linkIconInput = document.getElementById('linkIcon');
        if (linkIconInput) {
            linkIconInput.addEventListener('input', function() {
                const val = this.value;
                const preview = document.getElementById('iconPreview');
                if (val.startsWith('http') || val.startsWith('/')) {
                    preview.className = '';
                    preview.style.backgroundImage = `url(${val})`;
                    preview.style.backgroundSize = 'contain';
                    preview.style.backgroundRepeat = 'no-repeat';
                    preview.style.backgroundPosition = 'center';
                    preview.style.width = '16px';
                    preview.style.height = '16px';
                    preview.style.display = 'inline-block';
                } else {
                    preview.style = '';
                    preview.className = val;
                }
            });
        }
    });

    function loadLinks() {
        const tbody = document.querySelector('#homeLinksTable tbody');
        if (!tbody) return;

        fetch('api/home_links.php?action=list')
            .then(response => response.text())
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch(e) {
                    console.error('home_links API 返回非 JSON:', text);
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">数据解析失败，请查看控制台</td></tr>';
                    return;
                }
                if (data.success && data.data) {
                    tbody.innerHTML = '';
                    if (data.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">暂无链接，点击上方"添加链接"按钮创建</td></tr>';
                        return;
                    }
                    data.data.forEach(link => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${link.sort_order}</td>
                            <td>${renderIcon(link.icon)}</td>
                            <td>
                                <div class="fw-bold">${link.name}</div>
                                <small class="text-muted">${link.description || ''}</small>
                            </td>
                            <td><a href="${link.url}" target="_blank" class="text-truncate d-inline-block" style="max-width: 150px;">${link.url}</a></td>
                            <td>${link.badge_text ? `<span class="badge bg-${link.badge_color}">${link.badge_text}</span>` : '-'}</td>
                            <td>
                                <span class="badge bg-${link.is_active == 1 ? 'success' : 'secondary'}">
                                    ${link.is_active == 1 ? '显示' : '隐藏'}
                                </span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="editLink(${link.id})"><i class="bi bi-pencil"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteLink(${link.id})"><i class="bi bi-trash"></i></button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">' + (data.message || '数据加载失败') + '</td></tr>';
                }
            })
            .catch(err => {
                console.error('loadLinks fetch error:', err);
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">网络错误，请检查控制台</td></tr>';
            });
    }

    function renderIcon(icon) {
        if (!icon) return '-';
        if (icon.startsWith('http') || icon.startsWith('/')) {
            return `<img src="${icon}" style="width: 24px; height: 24px; object-fit: contain;" loading="lazy">`;
        }
        return `<i class="${icon} fs-5"></i>`;
    }

    function openLinkModal() {
        if (!linkModal) {
            console.error('Link Modal not initialized');
            return;
        }
        document.getElementById('linkForm').reset();
        document.getElementById('linkId').value = '';
        document.getElementById('linkModalTitle').innerText = '添加链接';
        // Reset preview
        const preview = document.getElementById('iconPreview');
        if (preview) {
            preview.className = 'bi bi-question';
            preview.style = '';
        }
        linkModal.show();
    }

    function editLink(id) {
        fetch(`api/home_links.php?action=get&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const link = data.data;
                    document.getElementById('linkId').value = link.id;
                    document.getElementById('linkName').value = link.name;
                    document.getElementById('linkUrl').value = link.url;
                    document.getElementById('linkIcon').value = link.icon;
                    document.getElementById('linkDescription').value = link.description;
                    document.getElementById('linkBadgeText').value = link.badge_text;
                    document.getElementById('linkBadgeColor').value = link.badge_color;
                    document.getElementById('linkSortOrder').value = link.sort_order;
                    document.getElementById('linkIsActive').checked = link.is_active == 1;
                    document.getElementById('linkModalTitle').innerText = '编辑链接';
                    // 触发图标预览更新
                    document.getElementById('linkIcon').dispatchEvent(new Event('input'));
                    linkModal.show();
                }
            });
    }

    function saveLink() {
        const form = document.getElementById('linkForm');
        const formData = new FormData(form);
        const id = formData.get('id');
        const action = id ? 'edit' : 'add';
        
        fetch(`api/home_links.php?action=${action}`, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                linkModal.hide();
                loadLinks();
            } else {
                alert('保存失败: ' + data.message);
            }
        });
    }

    function deleteLink(id) {
        if (confirm('确定要删除这个链接吗？')) {
            const formData = new FormData();
            formData.append('id', id);
            
            fetch('api/home_links.php?action=delete', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadLinks();
                } else {
                    alert('删除失败: ' + data.message);
                }
            });
        }
    }

    function uploadLinkIcon(input) {
        if (!input.files || !input.files[0]) return;
        
        const file = input.files[0];
        const formData = new FormData();
        formData.append('image', file);
        
        // 使用通用的图片上传接口
        fetch('upload_image.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const linkIconInput = document.getElementById('linkIcon');
                linkIconInput.value = data.url;
                linkIconInput.dispatchEvent(new Event('input')); // 触发预览更新
            } else {
                alert('上传失败: ' + (data.error || '未知错误'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('上传出错，请重试');
        });
    }
    </script>
    
    <script>
        // 初始化 Markdown 编辑器
        initMarkdownEditor('website_description');
        initMarkdownEditor('website_detail');
        initMarkdownEditor('website_announcement');
        
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
        
        // 音乐播放器开关控制
        const musicEnabled = document.getElementById('musicEnabled');
        const musicSettings = document.getElementById('musicSettings');
        if (musicEnabled && musicSettings) {
            musicEnabled.addEventListener('change', function() {
                if (this.checked) {
                    musicSettings.classList.remove('d-none');
                } else {
                    musicSettings.classList.add('d-none');
                }
            });
        }
        
        // Bing背景开关控制
        const useBingBg = document.getElementById('useBingBg');
        const bingApiContainer = document.getElementById('bingApiContainer');
        if (useBingBg && bingApiContainer) {
            useBingBg.addEventListener('change', function() {
                if (this.checked) {
                    bingApiContainer.style.display = 'block';
                } else {
                    bingApiContainer.style.display = 'none';
                }
            });
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
        handleUpload('bgImageInput', '/admin/upload_image.php', 'image', 'bgImagePreview', 'bgImageUrl', '图片上传成功', 'image', 'clearBgImageBtn');
        handleUpload('bgVideoInput', '/admin/upload_video.php', 'video', 'bgVideoPreview', 'bgVideoUrl', '视频上传成功', 'video', 'clearBgVideoBtn');
        handleUpload('newyearVideoInput', '/admin/upload_video.php', 'video', 'newyearVideoPreview', 'newyearVideoUrl', '新年视频上传成功', 'video', 'clearNewyearVideoBtn');

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
        
        // 清除背景逻辑
        function clearBgImage() {
            if(confirm('确定清除背景图片？')) {
                document.getElementById('bgImageUrl').value = '';
                document.getElementById('bgImagePreview').innerHTML = '<div class="text-center text-muted"><i class="bi bi-image fs-1 d-block mb-1"></i><small>未设置</small></div>';
                document.getElementById('clearBgImageBtn').classList.add('d-none');
            }
        }
        function clearBgVideo() {
            if(confirm('确定清除背景视频？')) {
                document.getElementById('bgVideoUrl').value = '';
                document.getElementById('bgVideoPreview').innerHTML = '<div class="text-center text-muted"><i class="bi bi-camera-video fs-1 d-block mb-1"></i><small>未设置</small></div>';
                document.getElementById('clearBgVideoBtn').classList.add('d-none');
            }
        }
        function clearNewyearVideo() {
            if(confirm('确定清除新年祝福视频？')) {
                document.getElementById('newyearVideoUrl').value = '';
                document.getElementById('newyearVideoPreview').innerHTML = '<div class="text-center text-muted"><i class="bi bi-camera-video fs-1 d-block mb-1"></i><small>未设置</small></div>';
                document.getElementById('clearNewyearVideoBtn').classList.add('d-none');
            }
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

        // 图床设置开关
        const imageBedEnabled = document.getElementById('imageBedEnabled');
        const imageBedSettings = document.getElementById('imageBedSettings');
        if (imageBedEnabled && imageBedSettings) {
            imageBedEnabled.addEventListener('change', function() {
                if (this.checked) {
                    imageBedSettings.style.display = 'block';
                } else {
                    imageBedSettings.style.display = 'none';
                }
            });
        }

        // 图片映射管理
        function refreshImageMap() {
            // 获取统计
            fetch('/admin/api/image_map.php?action=stats')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('imageMapTotal').textContent = data.stats.total;
                        document.getElementById('imageMapWithBed').textContent = data.stats.with_image_bed;
                        document.getElementById('imageMapPending').textContent = data.stats.without_image_bed;
                        document.getElementById('batchUploadBtn').disabled = data.stats.without_image_bed === 0;
                    }
                });

            // 获取列表
            fetch('/admin/api/image_map.php?action=get_all')
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('imageMapList');
                    if (data.success && data.data.length > 0) {
                        tbody.innerHTML = data.data.slice(0, 50).map(item => `
                            <tr>
                                <td><small>${item.filename || '-'}</small></td>
                                <td><small class="text-break">${item.local_url || '-'}</small></td>
                                <td><small class="text-break">${item.image_bed_url ? '<a href="' + item.image_bed_url + '" target="_blank">已同步</a>' : '<span class="text-warning">待上传</span>'}</small></td>
                                <td>${item.image_bed_url ? '<span class="badge bg-success">已同步</span>' : '<span class="badge bg-warning">待上传</span>'}</td>
                            </tr>
                        `).join('');
                        if (data.data.length > 50) {
                            tbody.innerHTML += '<tr><td colspan="4" class="text-center text-muted">只显示前50条，更多请查看映射文件</td></tr>';
                        }
                    } else {
                        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">暂无数据</td></tr>';
                    }
                });
        }

        function batchUploadToImageBed() {
            const btn = document.getElementById('batchUploadBtn');
            const progressDiv = document.getElementById('batchUploadProgress');
            const progressBar = document.getElementById('uploadProgressBar');
            const progressText = document.getElementById('uploadProgressText');

            btn.disabled = true;
            progressDiv.style.display = 'block';
            progressText.textContent = '开始上传...';

            fetch('/admin/api/image_map.php?action=batch_upload')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        progressBar.style.width = '100%';
                        progressBar.classList.remove('progress-bar-animated');
                        progressText.textContent = `上传完成！成功: ${data.result.success}, 失败: ${data.result.failed}`;
                        setTimeout(() => {
                            progressDiv.style.display = 'none';
                            progressBar.style.width = '0%';
                            progressBar.classList.add('progress-bar-animated');
                            refreshImageMap();
                        }, 2000);
                    } else {
                        progressText.textContent = '上传失败: ' + (data.error || '未知错误');
                        progressBar.classList.remove('progress-bar-animated');
                        progressBar.classList.add('bg-danger');
                    }
                    btn.disabled = false;
                })
                .catch(err => {
                    progressText.textContent = '上传出错: ' + err;
                    progressBar.classList.remove('progress-bar-animated');
                    progressBar.classList.add('bg-danger');
                    btn.disabled = false;
                });
        }

        // 扫描本地uploads目录
        function scanLocalImages() {
            if (!confirm('确定要扫描本地 uploads 目录吗？这将识别所有历史图片并添加到映射表。')) {
                return;
            }

            const progressDiv = document.getElementById('batchUploadProgress');
            const progressBar = document.getElementById('uploadProgressBar');
            const progressText = document.getElementById('uploadProgressText');

            progressDiv.style.display = 'block';
            progressText.textContent = '正在扫描本地图片...';

            fetch('/admin/api/image_map.php?action=scan_local')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        progressBar.style.width = '100%';
                        progressBar.classList.remove('progress-bar-animated');
                        progressText.textContent = `扫描完成！共扫描 ${data.result.scanned} 张图片，新增 ${data.result.added} 条记录`;
                        setTimeout(() => {
                            progressDiv.style.display = 'none';
                            progressBar.style.width = '0%';
                            progressBar.classList.add('progress-bar-animated');
                            progressBar.classList.remove('bg-danger');
                            refreshImageMap();
                        }, 2000);
                    } else {
                        progressText.textContent = '扫描失败: ' + (data.error || '未知错误');
                        progressBar.classList.remove('progress-bar-animated');
                        progressBar.classList.add('bg-danger');
                    }
                })
                .catch(err => {
                    progressText.textContent = '扫描出错: ' + err;
                    progressBar.classList.remove('progress-bar-animated');
                    progressBar.classList.add('bg-danger');
                });
        }

        // 从文章内容扫描图片
        function scanPostsImages() {
            if (!confirm('确定要扫描文章内容中的图片吗？这将从数据库中读取所有文章并识别其中的图片URL。')) {
                return;
            }

            const progressDiv = document.getElementById('batchUploadProgress');
            const progressBar = document.getElementById('uploadProgressBar');
            const progressText = document.getElementById('uploadProgressText');

            progressDiv.style.display = 'block';
            progressText.textContent = '正在扫描文章图片...';

            fetch('/admin/api/image_map.php?action=scan_posts')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        progressBar.style.width = '100%';
                        progressBar.classList.remove('progress-bar-animated');
                        progressText.textContent = `扫描完成！共发现 ${data.result.found} 张图片，新增 ${data.result.added} 条记录`;
                        setTimeout(() => {
                            progressDiv.style.display = 'none';
                            progressBar.style.width = '0%';
                            progressBar.classList.add('progress-bar-animated');
                            progressBar.classList.remove('bg-danger');
                            refreshImageMap();
                        }, 2000);
                    } else {
                        progressText.textContent = '扫描失败: ' + (data.error || '未知错误');
                        progressBar.classList.remove('progress-bar-animated');
                        progressBar.classList.add('bg-danger');
                    }
                })
                .catch(err => {
                    progressText.textContent = '扫描出错: ' + err;
                    progressBar.classList.remove('progress-bar-animated');
                    progressBar.classList.add('bg-danger');
                });
        }

        // 页面加载时自动刷新
        if (document.getElementById('imageMapTotal')) {
            refreshImageMap();
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
        
        // 更新音乐播放器资源
        function updateMusicResources(btn) {
            if (!confirm('确定要从远程服务器更新播放器核心资源吗？\n这将覆盖本地的 /config/music_local/ 文件。')) {
                return;
            }
            
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> 更新中...';
            
            fetch('/admin/update_music_resources.php', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                } else {
                    alert('更新失败: ' + data.error);
                }
            })
            .catch(error => {
                alert('请求出错: ' + error.message);
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = originalText;
            });
        }

    </script>
    <!-- Project Modal -->
    <div class="modal fade" id="projectModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="projectModalTitle">添加项目</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="projectForm">
                        <input type="hidden" name="id" id="projectId">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label">项目名称</label>
                                    <input type="text" class="form-control" name="name" id="projectName" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">项目链接</label>
                                    <input type="text" class="form-control" name="url" id="projectUrl">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">图标 (类名或图片URL)</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="icon" id="projectIcon" placeholder="bi bi-github" required>
                                        <span class="input-group-text"><i id="projectIconPreview" class="bi bi-question"></i></span>
                                    </div>
                                    <div class="d-flex mt-2">
                                        <input type="file" id="projectIconUpload" accept="image/*" class="d-none" onchange="uploadProjectIcon(this)">
                                        <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="document.getElementById('projectIconUpload').click()">
                                            <i class="bi bi-upload me-1"></i> 上传图标
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">项目描述</label>
                            <textarea class="form-control" name="description" id="projectDescription" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">技术栈标签 (逗号分隔)</label>
                                <input type="text" class="form-control" name="tags" id="projectTags" placeholder="PHP, Redis, Docker">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">开始时间</label>
                                <input type="text" class="form-control" name="start_date" id="projectStartDate" placeholder="例如: 2024年2月">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">排序</label>
                                <input type="number" class="form-control" name="sort_order" id="projectSortOrder" value="0">
                            </div>
                            <div class="col-md-6 mb-3 d-flex align-items-end">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="projectIsActive" checked>
                                    <label class="form-check-label" for="projectIsActive">启用显示</label>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="saveProject()">保存</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Project Management Scripts
    let projectModal;
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Modal
        const projectModalEl = document.getElementById('projectModal');
        if (projectModalEl) {
            projectModal = new bootstrap.Modal(projectModalEl);
        }
        
        // Load initial data
        loadProjects();
        
        // Icon preview
        const projectIconInput = document.getElementById('projectIcon');
        if (projectIconInput) {
            projectIconInput.addEventListener('input', function() {
                const val = this.value;
                const preview = document.getElementById('projectIconPreview');
                if (val.startsWith('http') || val.startsWith('/')) {
                    preview.className = '';
                    preview.style.backgroundImage = `url(${val})`;
                    preview.style.backgroundSize = 'contain';
                    preview.style.backgroundRepeat = 'no-repeat';
                    preview.style.backgroundPosition = 'center';
                    preview.style.width = '16px';
                    preview.style.height = '16px';
                    preview.style.display = 'inline-block';
                } else {
                    preview.style = '';
                    preview.className = val;
                }
            });
        }
    });

    function loadProjects() {
        fetch('api/my_projects.php?action=list')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const tbody = document.querySelector('#myProjectsTable tbody');
                    if (!tbody) return;
                    
                    tbody.innerHTML = '';
                    data.data.forEach(project => {
                        let tagsHtml = '';
                        if (project.tags) {
                            project.tags.split(/[,，]/).forEach(tag => {
                                tag = tag.trim();
                                if (tag) tagsHtml += `<span class="badge bg-light text-dark border me-1">${tag}</span>`;
                            });
                        }

                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${project.sort_order}</td>
                            <td>${renderIcon(project.icon)}</td>
                            <td>
                                <div class="fw-bold">${project.name}</div>
                                <small class="text-muted text-truncate d-inline-block" style="max-width: 200px;">${project.description || ''}</small>
                            </td>
                            <td>${tagsHtml || '-'}</td>
                            <td>${project.start_date || '-'}</td>
                            <td>
                                <span class="badge bg-${project.is_active == 1 ? 'success' : 'secondary'}">
                                    ${project.is_active == 1 ? '显示' : '隐藏'}
                                </span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="editProject(${project.id})"><i class="bi bi-pencil"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteProject(${project.id})"><i class="bi bi-trash"></i></button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
            });
    }

    function openProjectModal() {
        if (!projectModal) return;
        document.getElementById('projectForm').reset();
        document.getElementById('projectId').value = '';
        document.getElementById('projectModalTitle').innerText = '添加项目';
        // Reset preview
        const preview = document.getElementById('projectIconPreview');
        if (preview) {
            preview.className = 'bi bi-question';
            preview.style = '';
        }
        projectModal.show();
    }

    function editProject(id) {
        fetch(`api/my_projects.php?action=get&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const project = data.data;
                    document.getElementById('projectId').value = project.id;
                    document.getElementById('projectName').value = project.name;
                    document.getElementById('projectUrl').value = project.url;
                    document.getElementById('projectIcon').value = project.icon;
                    document.getElementById('projectDescription').value = project.description;
                    document.getElementById('projectTags').value = project.tags;
                    document.getElementById('projectStartDate').value = project.start_date;
                    document.getElementById('projectSortOrder').value = project.sort_order;
                    document.getElementById('projectIsActive').checked = project.is_active == 1;
                    document.getElementById('projectModalTitle').innerText = '编辑项目';
                    
                    document.getElementById('projectIcon').dispatchEvent(new Event('input'));
                    projectModal.show();
                }
            });
    }

    function saveProject() {
        const form = document.getElementById('projectForm');
        const formData = new FormData(form);
        const id = formData.get('id');
        const action = id ? 'edit' : 'add';
        
        fetch(`api/my_projects.php?action=${action}`, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                projectModal.hide();
                loadProjects();
            } else {
                alert('保存失败: ' + data.message);
            }
        });
    }

    function deleteProject(id) {
        if (confirm('确定要删除这个项目吗？')) {
            const formData = new FormData();
            formData.append('id', id);
            
            fetch('api/my_projects.php?action=delete', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadProjects();
                } else {
                    alert('删除失败: ' + data.message);
                }
            });
        }
    }

    function uploadProjectIcon(input) {
        if (!input.files || !input.files[0]) return;
        
        const file = input.files[0];
        const formData = new FormData();
        formData.append('image', file);
        
        fetch('upload_image.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const iconInput = document.getElementById('projectIcon');
                iconInput.value = data.url;
                iconInput.dispatchEvent(new Event('input'));
            } else {
                alert('上传失败: ' + (data.error || '未知错误'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('上传出错，请重试');
        });
    }
    </script>
<?php require_once 'includes/footer.php'; ?>
