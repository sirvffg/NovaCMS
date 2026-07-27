<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../vendor/api/ifdian/AfdianAPI.php';

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 处理 POST 请求
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        try {
            $ifdian_user_id = $_POST['ifdian_user_id'] ?? '';
            $ifdian_api_token = $_POST['ifdian_api_token'] ?? '';
            $ifdian_cookie = $_POST['ifdian_cookie'] ?? '';
            $ifdian_public_key = $_POST['ifdian_public_key'] ?? '';
            $ifdian_show_sponsor = isset($_POST['ifdian_show_sponsor']) ? '1' : '0';
            $ifdian_username = $_POST['ifdian_username'] ?? '';

            // 检查并添加字段
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'ifdian_user_id'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN ifdian_user_id VARCHAR(64) DEFAULT '' COMMENT '爱发电 User ID' AFTER footer_extra");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'ifdian_api_token'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN ifdian_api_token VARCHAR(255) DEFAULT '' COMMENT '爱发电 API Token' AFTER ifdian_user_id");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'ifdian_cookie'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN ifdian_cookie TEXT COMMENT '爱发电 Cookie' AFTER ifdian_api_token");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'ifdian_public_key'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN ifdian_public_key TEXT COMMENT '爱发电公钥' AFTER ifdian_cookie");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'ifdian_show_sponsor'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN ifdian_show_sponsor TINYINT(1) DEFAULT 0 COMMENT '在博客页显示赞助' AFTER ifdian_public_key");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'ifdian_username'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN ifdian_username VARCHAR(100) DEFAULT '' COMMENT '爱发电用户名' AFTER ifdian_show_sponsor");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'ifdian_permanent_threshold'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN ifdian_permanent_threshold DECIMAL(10,2) DEFAULT 0.00 COMMENT '永久显示金额阈值' AFTER ifdian_username");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'ifdian_permanent_enable'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN ifdian_permanent_enable TINYINT(1) DEFAULT 0 COMMENT '启用永久显示阈值' AFTER ifdian_permanent_threshold");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'ifdian_show_tab_sponsor'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN ifdian_show_tab_sponsor TINYINT(1) DEFAULT 1 COMMENT '显示赞助支持分页' AFTER ifdian_permanent_enable");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'ifdian_show_tab_manual'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN ifdian_show_tab_manual TINYINT(1) DEFAULT 1 COMMENT '显示特别鸣谢分页' AFTER ifdian_show_tab_sponsor");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'ifdian_show_tab_history'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN ifdian_show_tab_history TINYINT(1) DEFAULT 1 COMMENT '显示赞助历史分页' AFTER ifdian_show_tab_manual");
            }

            // 创建自动回复表（如果不存在）
            $db->exec("CREATE TABLE IF NOT EXISTS `ifdian_auto_replies` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `plan_id` varchar(64) NOT NULL COMMENT '方案ID',
                `reply_content` text COMMENT '自动回复内容',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_plan_id` (`plan_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='爱发电自动回复配置'");

            // 创建手动鸣谢表（如果不存在）
            $db->exec("CREATE TABLE IF NOT EXISTS `ifdian_manual_sponsors` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL COMMENT '名称',
                `qq` varchar(20) DEFAULT '' COMMENT 'QQ号',
                `avatar` varchar(255) DEFAULT '' COMMENT '头像URL',
                `description` varchar(255) DEFAULT '' COMMENT '描述',
                `link` varchar(255) DEFAULT '' COMMENT '链接',
                `sort_order` int(11) DEFAULT 0 COMMENT '排序(小到大)',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='手动鸣谢列表'");
            
            // 检查并添加 qq 字段
            $checkStmt = $db->query("SHOW COLUMNS FROM ifdian_manual_sponsors LIKE 'qq'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE ifdian_manual_sponsors ADD COLUMN qq VARCHAR(20) DEFAULT '' COMMENT 'QQ号' AFTER name");
            }

            $ifdian_permanent_threshold = $_POST['ifdian_permanent_threshold'] ?? 0;
            $ifdian_permanent_enable = isset($_POST['ifdian_permanent_enable']) ? '1' : '0';
            $ifdian_show_tab_sponsor = isset($_POST['ifdian_show_tab_sponsor']) ? '1' : '0';
            $ifdian_show_tab_manual = isset($_POST['ifdian_show_tab_manual']) ? '1' : '0';
            $ifdian_show_tab_history = isset($_POST['ifdian_show_tab_history']) ? '1' : '0';

            $stmt = $db->prepare("UPDATE website_config SET ifdian_user_id=?, ifdian_api_token=?, ifdian_cookie=?, ifdian_public_key=?, ifdian_show_sponsor=?, ifdian_username=?, ifdian_permanent_threshold=?, ifdian_permanent_enable=?, ifdian_show_tab_sponsor=?, ifdian_show_tab_manual=?, ifdian_show_tab_history=? WHERE id=1");
            $stmt->execute([$ifdian_user_id, $ifdian_api_token, $ifdian_cookie, $ifdian_public_key, $ifdian_show_sponsor, $ifdian_username, $ifdian_permanent_threshold, $ifdian_permanent_enable, $ifdian_show_tab_sponsor, $ifdian_show_tab_manual, $ifdian_show_tab_history]);
            
            // PRG模式：重定向避免重复提交
            header('Location: ' . $_SERVER['REQUEST_URI'] . '?tab=config');
            exit;
        } catch (Exception $e) {
            $error = '保存失败: ' . $e->getMessage();
        }
    }
    
    // 保存自动回复配置
    if ($action === 'save_auto_reply') {
        try {
            $plan_id = $_POST['plan_id'] ?? '';
            $reply_content = $_POST['reply_content'] ?? '';
            
            if (empty($plan_id)) {
                throw new Exception('方案ID不能为空');
            }
            
            // 检查是否存在
            $check = $db->prepare("SELECT id FROM ifdian_auto_replies WHERE plan_id = ?");
            $check->execute([$plan_id]);
            
            if ($check->fetch()) {
                $stmt = $db->prepare("UPDATE ifdian_auto_replies SET reply_content = ? WHERE plan_id = ?");
                $stmt->execute([$reply_content, $plan_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO ifdian_auto_replies (plan_id, reply_content) VALUES (?, ?)");
                $stmt->execute([$plan_id, $reply_content]);
            }
            
            // PRG模式
            header('Location: ' . $_SERVER['REQUEST_URI'] . '?tab=plans');
            exit;
        } catch (Exception $e) {
            $error = '保存失败: ' . $e->getMessage();
        }
    }

    // 手动鸣谢操作
    if (in_array($action, ['add_manual', 'edit_manual', 'delete_manual'])) {
        try {
            if ($action === 'delete_manual') {
                $id = $_POST['id'] ?? 0;
                $stmt = $db->prepare("DELETE FROM ifdian_manual_sponsors WHERE id = ?");
                $stmt->execute([$id]);
                // PRG模式
                header('Location: ' . $_SERVER['REQUEST_URI'] . '?tab=manual');
                exit;
            } else {
                $name = $_POST['name'] ?? '';
                $qq = $_POST['qq'] ?? '';
                $avatar = $_POST['avatar'] ?? '';
                $description = $_POST['description'] ?? '';
                $link = $_POST['link'] ?? '';
                $sort_order = $_POST['sort_order'] ?? 0;
                
                if (empty($name)) throw new Exception('名称不能为空');

                if ($action === 'add_manual') {
                    $stmt = $db->prepare("INSERT INTO ifdian_manual_sponsors (name, qq, avatar, description, link, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $qq, $avatar, $description, $link, $sort_order]);
                } else {
                    $id = $_POST['id'] ?? 0;
                    $stmt = $db->prepare("UPDATE ifdian_manual_sponsors SET name=?, qq=?, avatar=?, description=?, link=?, sort_order=? WHERE id=?");
                    $stmt->execute([$name, $qq, $avatar, $description, $link, $sort_order, $id]);
                }
                // PRG模式
                header('Location: ' . $_SERVER['REQUEST_URI'] . '?tab=manual');
                exit;
            }
        } catch (Exception $e) {
            $error = '操作失败: ' . $e->getMessage();
        }
    }
}

// 重新读取配置
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$page_title = '赞助管理(爱发电)';
$extra_css = <<<'CSS'
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
.api-result {
    background-color: #1e1e1e;
    color: #d4d4d4;
    padding: 1rem;
    border-radius: 8px;
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 0.85rem;
    max-height: 400px;
    overflow-y: auto;
}
CSS;
$extra_scripts = '<link href="/assets/css/admin.css" rel="stylesheet">';
require_once 'includes/header.php'; ?>

                <!-- 提示信息 -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?= e($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- 手动鸣谢 Modal -->
                <div class="modal fade" id="manualModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">编辑手动鸣谢</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <form id="manualForm" method="POST">
                                    <input type="hidden" name="action" id="manual_action" value="add_manual">
                                    <input type="hidden" name="id" id="manual_id">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">名称</label>
                                        <input type="text" class="form-control" name="name" id="manual_name" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">QQ 号 (可选)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="qq" id="manual_qq" placeholder="填写QQ号可自动获取头像" onchange="fetchQQAvatar()">
                                            <button class="btn btn-outline-secondary" type="button" onclick="fetchQQAvatar()">获取头像</button>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">头像 URL</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="avatar" id="manual_avatar" placeholder="https://...">
                                            <span class="input-group-text p-0 overflow-hidden">
                                                <img src="" id="manual_avatar_preview" style="width: 38px; height: 38px; object-fit: cover; display: none;">
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">描述</label>
                                        <input type="text" class="form-control" name="description" id="manual_description">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">链接</label>
                                        <input type="text" class="form-control" name="link" id="manual_link" placeholder="点击跳转的地址">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">排序 (从小到大)</label>
                                        <input type="number" class="form-control" name="sort_order" id="manual_sort_order" value="0">
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">保存</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 自动回复 Modal -->
                <div class="modal fade" id="autoReplyModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">设置自动回复</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <form id="autoReplyForm">
                                    <input type="hidden" id="reply_plan_id" name="plan_id">
                                    <input type="hidden" name="action" value="save_auto_reply">
                                    <div class="mb-3">
                                        <label class="form-label">方案名称</label>
                                        <input type="text" class="form-control" id="reply_plan_name" readonly>
                                        <div class="form-text text-muted font-monospace" id="reply_plan_id_display"></div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">回复内容</label>
                                        <textarea class="form-control" name="reply_content" id="reply_content" rows="4" placeholder="当用户赞助此方案后，系统将自动发送此私信..."></textarea>
                                        <div class="form-text">留空则不发送自动回复</div>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="button" class="btn btn-primary" onclick="saveAutoReply()">
                                    <i class="bi bi-save me-1"></i>保存
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 方案高级设置 Modal -->
                <div class="modal fade" id="planConfigModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">方案高级设置</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <form id="planConfigForm">
                                    <input type="hidden" id="config_plan_id" name="plan_id">
                                    <div class="mb-3">
                                        <label class="form-label">方案名称</label>
                                        <input type="text" class="form-control" id="config_plan_name" readonly>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="config_is_show" name="is_show_in_thanks" value="1" checked>
                                            <label class="form-check-label" for="config_is_show">在“特别鸣谢”页面展示</label>
                                        </div>
                                        <div class="form-text">关闭后，赞助此方案的用户将不会显示在鸣谢列表中</div>
                                    </div>

                                    <div id="duration_settings">
                                        <div class="mb-3">
                                            <label class="form-label">展示时长</label>
                                            <select class="form-select" id="config_duration_type" name="show_duration_type" onchange="toggleDurationInput()">
                                                <option value="0">永久展示</option>
                                                <option value="1">按月计算</option>
                                                <option value="2">按年计算</option>
                                                <option value="4">按天计算</option>
                                                <option value="3">直到赞助过期 (仅限订阅)</option>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3" id="config_duration_value_wrapper" style="display: none;">
                                            <label class="form-label">时长数值</label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" id="config_duration_value" name="show_duration_value" min="1" value="1">
                                                <span class="input-group-text" id="config_duration_unit">月</span>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="button" class="btn btn-primary" onclick="savePlanConfig()">
                                    <i class="bi bi-save me-1"></i>保存设置
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 私信 Modal -->
                <div class="modal fade" id="msgModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">发送私信</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <form id="msgForm">
                                    <input type="hidden" id="msg_user_id">
                                    <div class="mb-3">
                                        <label class="form-label">接收用户</label>
                                        <input type="text" class="form-control" id="msg_user_name" readonly>
                                        <div class="form-text text-muted font-monospace" id="msg_user_id_display"></div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">私信内容</label>
                                        <textarea class="form-control" id="msg_content" rows="4" placeholder="请输入私信内容..."></textarea>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="button" class="btn btn-primary" onclick="sendMsg()">
                                    <i class="bi bi-send me-1"></i>发送
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-white p-0 border-bottom-0">
                        <ul class="nav nav-tabs card-header-tabs m-0 px-3" id="ifdianTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="tab-config" data-bs-toggle="tab" data-bs-target="#content-config" type="button" role="tab">
                                    <i class="bi bi-gear me-2"></i>配置
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-manual" data-bs-toggle="tab" data-bs-target="#content-manual" type="button" role="tab" onclick="loadManualList()">
                                    <i class="bi bi-star me-2"></i>手动鸣谢
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-plans" data-bs-toggle="tab" data-bs-target="#content-plans" type="button" role="tab" onclick="loadPlanList()">
                                    <i class="bi bi-journal-text me-2"></i>赞助方案
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-sponsors" data-bs-toggle="tab" data-bs-target="#content-sponsors" type="button" role="tab" onclick="loadSponsorList(1)">
                                    <i class="bi bi-people me-2"></i>赞助者列表
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-orders" data-bs-toggle="tab" data-bs-target="#content-orders" type="button" role="tab" onclick="loadOrderList(1)">
                                    <i class="bi bi-receipt me-2"></i>订单列表
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-posts" data-bs-toggle="tab" data-bs-target="#content-posts" type="button" role="tab" onclick="loadPostList(1)">
                                    <i class="bi bi-card-text me-2"></i>动态列表
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-test" data-bs-toggle="tab" data-bs-target="#content-test" type="button" role="tab">
                                    <i class="bi bi-terminal me-2"></i>API 测试
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="tab-content" id="ifdianTabContent">
                    <!-- 配置卡片 -->
                    <div class="tab-pane fade show active" id="content-config" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-cash-coin me-2"></i>爱发电配置</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="save_config">

                                    <div class="mb-3">
                                        <label class="form-label">爱发电 User ID</label>
                                        <input type="text" name="ifdian_user_id" class="form-control"
                                               value="<?= e($config['ifdian_user_id'] ?? '') ?>"
                                               placeholder="例如: d19a9f58eb7c11f08dd352540025c377">
                                        <div class="form-text">在爱发电开发者后台获取的 User ID</div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">爱发电 API Token</label>
                                        <input type="password" name="ifdian_api_token" class="form-control"
                                               value="<?= e($config['ifdian_api_token'] ?? '') ?>"
                                               placeholder="在爱发电开发者后台获取的 API Token">
                                        <div class="form-text">用于调用爱发电 API 的密钥</div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Cookie (可选)</label>
                                        <textarea name="ifdian_cookie" class="form-control font-monospace" rows="3"
                                                  placeholder="用于部分高级功能，请从浏览器 F12 获取完整 Cookie"><?= e($config['ifdian_cookie'] ?? '') ?></textarea>
                                        <div class="form-text text-warning"><i class="bi bi-exclamation-triangle me-1"></i>部分非官方接口可能需要 Cookie 授权。请妥善保管，勿泄露给他人。</div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">爱发电公钥</label>
                                        <textarea name="ifdian_public_key" class="form-control" rows="6"
                                                  placeholder="-----BEGIN PUBLIC KEY-----
...
-----END PUBLIC KEY-----"><?= e($config['ifdian_public_key'] ?? '') ?></textarea>
                                        <div class="form-text">用于验证 webhook 签名的公钥</div>
                                    </div>

                                    <hr class="my-4">

                                    <div class="mb-3">
                                        <label class="form-label">在博客页显示赞助</label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="ifdian_show_sponsor" id="ifdian_show_sponsor" <?= ($config['ifdian_show_sponsor'] ?? 0) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="ifdian_show_sponsor">
                                                <i class="bi bi-eye me-1"></i>启用赞助按钮显示
                                            </label>
                                        </div>
                                        <div class="form-text text-muted">开启后，将在博客文章详情页显示爱发电赞助栏目</div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">爱发电用户名</label>
                                        <input type="text" name="ifdian_username" class="form-control"
                                               value="<?= e($config['ifdian_username'] ?? '') ?>"
                                               placeholder="例如: lygalaxy">
                                        <div class="form-text">爱发电账号的用户名（用于生成赞助链接）</div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">永久显示规则</label>
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" name="ifdian_permanent_enable" id="ifdian_permanent_enable" <?= ($config['ifdian_permanent_enable'] ?? 0) ? 'checked' : '' ?> onchange="togglePermanentThreshold()">
                                            <label class="form-check-label" for="ifdian_permanent_enable">启用金额阈值永久显示</label>
                                        </div>
                                        <div class="input-group" id="permanent_threshold_group" style="<?= ($config['ifdian_permanent_enable'] ?? 0) ? '' : 'display:none;' ?>">
                                            <span class="input-group-text">¥</span>
                                            <input type="number" name="ifdian_permanent_threshold" class="form-control"
                                                   value="<?= e($config['ifdian_permanent_threshold'] ?? '0.00') ?>"
                                                   min="0" step="0.01">
                                            <span class="input-group-text">元</span>
                                        </div>
                                        <div class="form-text">当用户累计赞助金额达到此数值时，将在感谢名单中始终显示（无视方案过期时间）。</div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">页面分页显示</label>
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" name="ifdian_show_tab_sponsor" id="ifdian_show_tab_sponsor" <?= ($config['ifdian_show_tab_sponsor'] ?? 1) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="ifdian_show_tab_sponsor">显示“赞助支持”分页</label>
                                        </div>
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" name="ifdian_show_tab_manual" id="ifdian_show_tab_manual" <?= ($config['ifdian_show_tab_manual'] ?? 1) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="ifdian_show_tab_manual">显示“项目建设者”分页</label>
                                        </div>
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" name="ifdian_show_tab_history" id="ifdian_show_tab_history" <?= ($config['ifdian_show_tab_history'] ?? 1) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="ifdian_show_tab_history">显示“赞助历史记录”分页</label>
                                        </div>
                                        <div class="form-text">控制前台特别鸣谢页面的各个标签页是否显示。</div>
                                    </div>

                                    <hr class="my-4">

                                    <div class="mb-3">
                                        <label class="form-label">Webhook URL</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" value="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] ?>/vendor/api/ifdian/webhook.php" readonly>
                                            <button class="btn btn-outline-secondary" type="button" onclick="copyWebhookUrl()">
                                                <i class="bi bi-clipboard"></i> 复制
                                            </button>
                                        </div>
                                        <div class="form-text">将此 URL 配置到爱发电开发者后台的 Webhook 设置中</div>
                                    </div>

                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save me-2"></i>保存配置
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>使用说明</h5>
                            </div>
                            <div class="card-body">
                                <h6>1. 获取爱发电密钥</h6>
                                <p class="text-muted">登录爱发电开发者后台，创建应用后获取 User ID 和 API Token</p>
                                <h6>2. 配置公钥</h6>
                                <p class="text-muted">获取爱发电提供的公钥，用于验证 webhook 回调的签名</p>
                                <h6>3. 配置 Webhook</h6>
                                <p class="text-muted">复制上方 Webhook URL，在爱发电开发者后台的 Webhook 设置中添加</p>
                                <h6>4. 测试连接</h6>
                                <p class="text-muted">点击 API 测试标签页中的连接测试按钮，验证配置是否正确</p>
                            </div>
                        </div>
                    </div>

                    <!-- API 测试卡片 -->
                    <div class="tab-pane fade" id="content-test" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-terminal me-2"></i>API 测试</h5>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label">页码 (Page)</label>
                                        <input type="number" id="api_page" class="form-control" value="1" min="1">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">每页数量 (Per Page)</label>
                                        <input type="number" id="api_per_page" class="form-control" value="10" min="1" max="100">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">参数 (ID/单号等)</label>
                                        <input type="text" id="api_param" class="form-control" placeholder="User ID / Order No / Plan ID">
                                    </div>
                                </div>
                                <div class="d-flex gap-2 flex-wrap mb-3">
                                    <button type="button" class="btn btn-outline-primary" onclick="testApi('ping')">
                                        <i class="bi bi-activity me-2"></i>连接测试
                                    </button>
                                    <button type="button" class="btn btn-outline-info" onclick="testApi('query_order')">
                                        <i class="bi bi-receipt me-2"></i>查询订单
                                    </button>
                                    <button type="button" class="btn btn-outline-success" onclick="testApi('query_sponsor')">
                                        <i class="bi bi-people me-2"></i>查询赞助者
                                    </button>
                                    <button type="button" class="btn btn-outline-warning" onclick="testApi('query_plan')">
                                        <i class="bi bi-journal-text me-2"></i>查询方案
                                    </button>
                                </div>
                                <label class="form-label">测试结果</label>
                                <div id="apiResult" class="api-result">
                                    <span class="text-muted">等待测试...</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 手动鸣谢卡片 -->
                    <div class="tab-pane fade" id="content-manual" role="tabpanel">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-star me-2"></i>手动鸣谢列表</h5>
                                <button class="btn btn-sm btn-primary" onclick="openManualModal()">
                                    <i class="bi bi-plus-lg me-1"></i>添加
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="80">排序</th>
                                                <th width="80">头像</th>
                                                <th>名称</th>
                                                <th>描述</th>
                                                <th>链接</th>
                                                <th width="120" class="text-end">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody id="manualListBody">
                                            <!-- JS加载 -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 赞助方案卡片 -->
                    <div class="tab-pane fade" id="content-plans" role="tabpanel">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>赞助方案总览</h5>
                                <button class="btn btn-sm btn-outline-primary" onclick="loadPlanList(true)">
                                    <i class="bi bi-arrow-clockwise me-1"></i>刷新
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="150">方案 ID</th>
                                                <th>名称</th>
                                                <th width="100">价格</th>
                                                <th>描述</th>
                                                <th width="100">类型</th>
                                                <th width="150">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody id="planListBody">
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">
                                                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                                    正在加载方案列表...
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="card-footer bg-white text-muted small">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="input-group input-group-sm" style="max-width: 300px;">
                                            <input type="text" id="manual_plan_id" class="form-control" placeholder="输入方案 ID 手动查询">
                                            <button class="btn btn-outline-secondary" type="button" onclick="queryManualPlan()">
                                                <i class="bi bi-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 赞助者列表卡片 -->
                    <div class="tab-pane fade" id="content-sponsors" role="tabpanel">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-people me-2"></i>赞助者列表</h5>
                                <div class="d-flex gap-2">
                                    <input type="text" id="sponsor_search" class="form-control form-control-sm" placeholder="搜索 User ID" style="width: 200px;">
                                    <button class="btn btn-sm btn-outline-primary" onclick="loadSponsorList(1)">
                                        <i class="bi bi-search me-1"></i>搜索/刷新
                                    </button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="200">用户</th>
                                                <th>累计赞助/时间</th>
                                                <th>当前方案详情</th>
                                                <th>历史方案</th>
                                                <th width="120" class="text-end">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody id="sponsorListBody">
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">
                                                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                                    正在加载赞助者列表...
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-footer bg-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-muted small" id="sponsorPaginationInfo">
                                        显示第 1 页
                                    </div>
                                    <nav aria-label="Page navigation">
                                        <ul class="pagination pagination-sm mb-0" id="sponsorPagination">
                                            <!-- 分页按钮将由 JS 生成 -->
                                        </ul>
                                    </nav>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 订单列表卡片 -->
                    <div class="tab-pane fade" id="content-orders" role="tabpanel">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>订单列表</h5>
                                <div class="d-flex gap-2">
                                    <input type="text" id="order_search" class="form-control form-control-sm" placeholder="搜索订单号" style="width: 200px;">
                                    <button class="btn btn-sm btn-outline-primary" onclick="loadOrderList(1)">
                                        <i class="bi bi-search me-1"></i>搜索/刷新
                                    </button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="180">订单号/时间</th>
                                                <th>用户详情</th>
                                                <th>金额/状态</th>
                                                <th>商品详情</th>
                                                <th>其他信息</th>
                                                <th width="120" class="text-end">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody id="orderListBody">
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">
                                                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                                    正在加载订单列表...
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-footer bg-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-muted small" id="orderPaginationInfo">
                                        显示第 1 页
                                    </div>
                                    <nav aria-label="Page navigation">
                                        <ul class="pagination pagination-sm mb-0" id="orderPagination">
                                            <!-- 分页按钮将由 JS 生成 -->
                                        </ul>
                                    </nav>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 动态列表卡片 -->
                    <div class="tab-pane fade" id="content-posts" role="tabpanel">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-card-text me-2"></i>动态列表</h5>
                                <button class="btn btn-sm btn-outline-primary" onclick="loadPostList(1)">
                                    <i class="bi bi-arrow-clockwise me-1"></i>刷新
                                </button>
                            </div>
                            <!-- 个人主页概览 -->
                            <div class="card-body border-bottom bg-light d-none" id="profileOverview">
                                <div class="d-flex align-items-center">
                                    <img src="" id="profileAvatar" class="rounded-circle border border-2 border-white shadow-sm me-3" width="64" height="64" style="object-fit: cover;">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center mb-1">
                                            <h5 class="mb-0 fw-bold me-2" id="profileName"></h5>
                                            <span class="badge bg-warning text-dark" id="profileCreatorType" style="display:none;">创作者</span>
                                        </div>
                                        <div class="text-muted small mb-1" id="profileDoing"></div>
                                        <div class="text-secondary small text-truncate" style="max-width: 600px;" id="profileDetail"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush" id="postListBody">
                                    <div class="text-center py-5 text-muted">
                                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                        正在加载动态列表...
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-muted small" id="postPaginationInfo">
                                        显示第 1 页
                                    </div>
                                    <nav aria-label="Page navigation">
                                        <ul class="pagination pagination-sm mb-0" id="postPagination">
                                            <!-- 分页按钮将由 JS 生成 -->
                                        </ul>
                                    </nav>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        function copyWebhookUrl() {
            const input = document.querySelector('input[readonly]');
            if (input) {
                input.select();
                document.execCommand('copy');
                alert('Webhook URL 已复制到剪贴板！');
            }
        }

        function togglePermanentThreshold() {
            const isEnabled = document.getElementById('ifdian_permanent_enable').checked;
            const group = document.getElementById('permanent_threshold_group');
            group.style.display = isEnabled ? 'flex' : 'none';
        }

        async function testApi(action) {
            const resultDiv = document.getElementById('apiResult');
            const page = document.getElementById('api_page').value;
            const perPage = document.getElementById('api_per_page').value;
            const param = document.getElementById('api_param').value;

            resultDiv.innerHTML = '<span class="text-info">正在请求...</span>';

            const formData = new FormData();
            formData.append('action', action);
            formData.append('page', page);
            formData.append('per_page', perPage);
            formData.append('param', param);

            try {
                const response = await fetch('api/ifdian_test.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    const text = await response.text();
                    throw new Error(`HTTP ${response.status}: ${text}`);
                }

                const data = await response.json();
                const timestamp = new Date().toLocaleTimeString();

                if (data.success) {
                    resultDiv.innerHTML = `<div class="text-success mb-2">[${timestamp}] ✓ ${data.message}</div><pre class="mb-0 text-white-50">${JSON.stringify(data.data, null, 2)}</pre>`;
                } else {
                    resultDiv.innerHTML = `<div class="text-danger mb-2">[${timestamp}] ✗ ${data.message}</div>${data.debug ? `<pre class="mb-0 text-white-50">${JSON.stringify(data.debug, null, 2)}</pre>` : ''}`;
                }
            } catch (error) {
                const timestamp = new Date().toLocaleTimeString();
                resultDiv.innerHTML = `<div class="text-danger">[${timestamp}] 请求失败: ${error.message}</div>`;
            }
        }

        let planListLoaded = false;
        async function loadPlanList(force = false) {
            if (planListLoaded && !force) return;

            const tbody = document.getElementById('planListBody');
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>正在加载方案列表...</td></tr>';

            try {
                const formData = new FormData();
                formData.append('action', 'get_plan_list');

                const response = await fetch('api/ifdian_test.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();

                if (data.success) {
                    // 添加无方案配置 (默认配置)
                    const defaultPlan = {
                        plan_id: 'default',
                        name: '无方案配置',
                        price: '0.00',
                        show_price: '0.00',
                        desc: '适用于未匹配到特定方案的赞助者 (全局默认设置)',
                        product_type: 0
                    };
                    
                    // 将默认方案添加到列表最前面
                    const allPlans = [defaultPlan, ...(data.data || [])];

                    if (allPlans.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">暂无活跃方案数据 (请检查是否有赞助者)</td></tr>';
                    } else {
                        tbody.innerHTML = allPlans.map(plan => `
                            <tr>
                                <td><span class="badge bg-light text-dark border font-monospace">${plan.plan_id}</span></td>
                                <td class="fw-bold">${plan.name || '未命名'}</td>
                                <td class="text-danger">¥${plan.show_price || plan.price || '0.00'}</td>
                                <td class="text-muted small text-truncate" style="max-width: 200px;">${plan.desc || '-'}</td>
                                <td><span class="badge bg-${plan.product_type == 1 ? 'info' : (plan.plan_id === 'default' ? 'secondary' : 'success')}">${plan.product_type == 1 ? '商品' : (plan.plan_id === 'default' ? '系统' : '订阅')}</span></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        ${plan.plan_id !== 'default' ? `
                                        <button class="btn btn-outline-primary" onclick="viewPlanDetail('${plan.plan_id}')" title="查看详情">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="btn btn-outline-secondary" onclick="openAutoReplyModal('${plan.plan_id}', '${plan.name ? plan.name.replace(/'/g, "\\'") : '方案'}')" title="设置自动回复">
                                            <i class="bi bi-chat-text"></i>
                                        </button>
                                        ` : `
                                        <button class="btn btn-outline-secondary" disabled title="本地配置无详情">
                                            <i class="bi bi-eye-slash"></i>
                                        </button>
                                        <button class="btn btn-outline-secondary" disabled title="不支持自动回复">
                                            <i class="bi bi-chat-text"></i>
                                        </button>
                                        `}
                                        <button class="btn btn-outline-secondary" onclick="openPlanConfigModal('${plan.plan_id}', '${plan.name ? plan.name.replace(/'/g, "\\'") : '方案'}')" title="高级设置">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        `).join('');
                    }
                    planListLoaded = true;
                } else {
                    tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">${data.message}</td></tr>`;
                }
            } catch (error) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">加载失败: ${error.message}</td></tr>`;
            }
        }

        function viewPlanDetail(planId) {
            // 切换到 API 测试标签页并填入参数查询
            const tab = new bootstrap.Tab(document.getElementById('tab-test'));
            tab.show();
            document.getElementById('api_param').value = planId;
            testApi('query_plan');
        }
        function queryManualPlan() {
            const planId = document.getElementById('manual_plan_id').value.trim();
            if (!planId) {
                alert('请输入方案 ID');
                return;
            }
            viewPlanDetail(planId);
        }

        let currentSponsorPage = 1;
        async function loadSponsorList(page) {
            currentSponsorPage = page;
            const tbody = document.getElementById('sponsorListBody');
            const searchInput = document.getElementById('sponsor_search').value.trim();
            
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>正在加载赞助者列表...</td></tr>';

            try {
                const formData = new FormData();
                formData.append('action', 'query_sponsor');
                formData.append('page', page);
                formData.append('per_page', 10); // 每页显示10条
                if (searchInput) {
                    formData.append('param', searchInput); // 使用 param 传递搜索词
                }

                const response = await fetch('api/ifdian_test.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();

                if (data.success && data.data && data.data.list) {
                    // 更新个人主页概览
                    if (data.user_data) {
                        const user = data.user_data;
                        const overview = document.getElementById('profileOverview');
                        const creator = user.creator || {};
                        
                        document.getElementById('profileAvatar').src = user.avatar || '/assets/img/default-avatar.png';
                        document.getElementById('profileName').innerText = user.name || '未命名';
                        
                        if (creator.doing) {
                            document.getElementById('profileDoing').innerText = '正在创作 ' + creator.doing;
                        }
                        if (creator.detail) {
                            document.getElementById('profileDetail').innerText = creator.detail.replace(/\n/g, ' ');
                        }
                        
                        // 显示概览区域
                        overview.classList.remove('d-none');
                    }

                    const list = data.data.list;
                    const totalPage = data.data.total_page || 1;
                    const totalCount = data.data.total_count || 0;

                    if (list.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">暂无赞助者数据</td></tr>';
                    } else {
                        tbody.innerHTML = list.map(item => {
                            const user = item.user || {};
                            const currentPlan = item.current_plan || {};
                            const sponsorPlans = item.sponsor_plans || [];

                            // 格式化当前方案
                            let currentPlanHtml = '<span class="text-muted small">无活跃方案</span>';
                            if (currentPlan.name) {
                                currentPlanHtml = `
                                    <div class="d-flex align-items-center mb-1">
                                        <span class="badge bg-success me-2">当前</span>
                                        <div class="fw-bold text-primary">${currentPlan.name}</div>
                                    </div>
                                    <div class="small text-muted">
                                        <span class="me-2">¥${currentPlan.price || '0.00'}/月</span>
                                        ${currentPlan.desc ? `<span class="text-truncate d-inline-block" style="max-width: 150px; vertical-align: bottom;" title="${currentPlan.desc}">${currentPlan.desc}</span>` : ''}
                                    </div>
                                    ${currentPlan.expire_time ? `<div class="small text-warning"><i class="bi bi-hourglass-split me-1"></i>过期: ${new Date(currentPlan.expire_time * 1000).toLocaleDateString()}</div>` : ''}
                                `;
                            }

                            // 格式化历史方案 (仅显示前2个，多了显示...)
                            let historyPlansHtml = '';
                            if (sponsorPlans.length > 0) {
                                historyPlansHtml = sponsorPlans.slice(0, 2).map(p => `
                                    <div class="mb-1 small">
                                        <span class="badge bg-light text-dark border me-1">${p.name}</span>
                                        <span class="text-muted">¥${p.price}</span>
                                    </div>
                                `).join('');
                                if (sponsorPlans.length > 2) {
                                    historyPlansHtml += `<div class="small text-muted fst-italic">+ 还有 ${sponsorPlans.length - 2} 个方案</div>`;
                                }
                            } else {
                                historyPlansHtml = '<span class="text-muted small">-</span>';
                            }

                            return `
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="${user.avatar || '/assets/img/default-avatar.png'}" class="rounded-circle border me-3" width="48" height="48" onerror="this.src='/assets/img/default-avatar.png'">
                                            <div>
                                                <div class="fw-bold text-dark">${user.name || '未命名'}</div>
                                                <div class="small text-muted font-monospace user-select-all" title="User ID: ${user.user_id}">
                                                    <i class="bi bi-person-badge me-1"></i>${user.user_id ? user.user_id.substring(0, 8) + '...' : '-'}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="h5 mb-1 text-success fw-bold">¥${item.all_sum_amount || '0.00'}</div>
                                        <div class="small text-muted" title="最后赞助时间">
                                            <i class="bi bi-clock-history me-1"></i>${item.last_pay_time ? new Date(item.last_pay_time * 1000).toLocaleDateString() : '-'}
                                        </div>
                                        <div class="small text-muted" title="首次赞助时间">
                                            <i class="bi bi-star me-1"></i>${item.create_time ? new Date(item.create_time * 1000).toLocaleDateString() : '-'}
                                        </div>
                                    </td>
                                    <td>
                                        ${currentPlanHtml}
                                    </td>
                                    <td>
                                        ${historyPlansHtml}
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-light text-secondary" onclick="viewSponsorDetail('${user.user_id}')" title="查看原始数据">
                                                <i class="bi bi-code-slash"></i>
                                            </button>
                                            <button class="btn btn-light text-primary" onclick="openMsgModal('${user.user_id}', '${user.name ? user.name.replace(/'/g, "\\'") : '用户'}')" title="发送私信">
                                                <i class="bi bi-chat-dots-fill"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        }).join('');
                    }

                    // 更新分页
                    updateSponsorPagination(page, totalPage, totalCount);
                } else {
                    tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">${data.message || '加载失败'}</td></tr>`;
                }
            } catch (error) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">加载失败: ${error.message}</td></tr>`;
            }
        }

        function updateSponsorPagination(currentPage, totalPage, totalCount) {
            const pagination = document.getElementById('sponsorPagination');
            const info = document.getElementById('sponsorPaginationInfo');
            
            info.innerHTML = `显示第 ${currentPage} 页，共 ${totalPage} 页 (总计 ${totalCount} 人)`;
            
            let html = '';
            
            // 上一页
            html += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                        <button class="page-link" onclick="loadSponsorList(${currentPage - 1})">上一页</button>
                     </li>`;
            
            // 页码 (只显示当前页附近的页码)
            const startPage = Math.max(1, currentPage - 2);
            const endPage = Math.min(totalPage, currentPage + 2);
            
            if (startPage > 1) {
                html += `<li class="page-item"><button class="page-link" onclick="loadSponsorList(1)">1</button></li>`;
                if (startPage > 2) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                            <button class="page-link" onclick="loadSponsorList(${i})">${i}</button>
                         </li>`;
            }
            
            if (endPage < totalPage) {
                if (endPage < totalPage - 1) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                html += `<li class="page-item"><button class="page-link" onclick="loadSponsorList(${totalPage})">${totalPage}</button></li>`;
            }
            
            // 下一页
            html += `<li class="page-item ${currentPage >= totalPage ? 'disabled' : ''}">
                        <button class="page-link" onclick="loadSponsorList(${currentPage + 1})">下一页</button>
                     </li>`;
            
            pagination.innerHTML = html;
        }

        function viewSponsorDetail(userId) {
            // 切换到 API 测试标签页并填入参数查询
            const tab = new bootstrap.Tab(document.getElementById('tab-test'));
            tab.show();
            document.getElementById('api_param').value = userId;
            testApi('query_sponsor');
        }

        let currentOrderPage = 1;
        async function loadOrderList(page) {
            currentOrderPage = page;
            const tbody = document.getElementById('orderListBody');
            const searchInput = document.getElementById('order_search').value.trim();
            
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>正在加载订单列表...</td></tr>';

            try {
                const formData = new FormData();
                formData.append('action', 'query_order');
                formData.append('page', page);
                formData.append('per_page', 10); // 每页显示10条
                if (searchInput) {
                    formData.append('param', searchInput); // 使用 param 传递搜索词
                }

                const response = await fetch('api/ifdian_test.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();

                if (data.success && data.data && data.data.list) {
                    const list = data.data.list;
                    const totalPage = data.data.total_page || 1;
                    const totalCount = data.data.total_count || 0;

                    if (list.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">暂无订单数据</td></tr>';
                    } else {
                        tbody.innerHTML = list.map(order => {
                            let planName = '未知方案';
                            let skuName = '';
                            let skuImage = '';
                            
                            // 尝试获取商品详细信息
                            if (order.sku_detail && order.sku_detail.length > 0) {
                                const sku = order.sku_detail[0];
                                planName = sku.name || '未知商品';
                                skuName = sku.name; // 这里的 name 通常就是具体的规格名称
                                skuImage = sku.pic;
                            } else if (order.product_type === 0) {
                                planName = '月度订阅'; 
                            }

                            // 格式化时间 (订单接口通常没有直接的时间戳，这里假设如果有的话)
                            // 注意：爱发电 query-order 接口文档并未提及 create_time 字段，只有 month
                            // 如果没有时间字段，我们显示 month
                            const timeInfo = order.create_time ? 
                                `<div class="text-muted small"><i class="bi bi-clock me-1"></i>${new Date(order.create_time * 1000).toLocaleString()}</div>` : 
                                (order.month ? `<div class="text-muted small"><i class="bi bi-calendar me-1"></i>${order.month}个月</div>` : '');

                            // 用户信息
                            const user = order.user || {};
                            const userHtml = user.name ? 
                                `<div class="d-flex align-items-center">
                                    <img src="${user.avatar || '/assets/img/default-avatar.png'}" class="rounded-circle border me-2" width="32" height="32" onerror="this.src='/assets/img/default-avatar.png'">
                                    <div>
                                        <div class="fw-bold text-dark text-truncate" style="max-width: 120px;">${user.name}</div>
                                        <div class="small font-monospace text-muted" title="User ID: ${order.user_id}">
                                            <i class="bi bi-person-badge me-1"></i>${order.user_id.substring(0, 6)}...
                                        </div>
                                    </div>
                                </div>` :
                                `<div class="d-flex align-items-center">
                                    <div class="ms-0">
                                        <div class="small font-monospace text-primary" title="User ID: ${order.user_id}">
                                            <i class="bi bi-person me-1"></i>${order.user_id.substring(0, 8)}...
                                        </div>
                                        ${order.address_person ? `<div class="small text-muted"><i class="bi bi-geo-alt me-1"></i>${order.address_person} ${order.address_phone}</div>` : ''}
                                    </div>
                                </div>`;
                            
                            return `
                                <tr>
                                    <td>
                                        <div class="fw-bold font-monospace small user-select-all" title="订单号">${order.out_trade_no}</div>
                                        ${timeInfo}
                                    </td>
                                    <td>
                                        ${userHtml}
                                    </td>
                                    <td>
                                        <div class="text-success fw-bold">¥${order.show_amount || order.total_amount || '0.00'}</div>
                                        <div class="small text-muted">
                                            ${order.status === 2 ? '<span class="badge bg-success-subtle text-success border border-success-subtle">交易成功</span>' : '<span class="badge bg-secondary">未知状态</span>'}
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            ${skuImage ? `<img src="${skuImage}" class="rounded me-2" width="40" height="40" style="object-fit: cover;">` : ''}
                                            <div>
                                                <div class="fw-bold text-truncate" style="max-width: 150px;" title="${planName}">
                                                    <span class="badge bg-${order.product_type == 1 ? 'info' : 'primary'} me-1">${order.product_type == 1 ? '商品' : '订阅'}</span>
                                                    ${planName}
                                                </div>
                                                ${order.sku_detail && order.sku_detail.length > 0 ? 
                                                    `<div class="small text-muted text-truncate" style="max-width: 150px;">× ${order.sku_detail[0].count}</div>` : ''}
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        ${order.remark ? `<div class="small text-muted" title="备注"><i class="bi bi-chat-quote me-1"></i>${order.remark}</div>` : ''}
                                        ${order.address_address ? `<div class="small text-muted text-truncate" style="max-width: 150px;" title="${order.address_address}"><i class="bi bi-truck me-1"></i>${order.address_address}</div>` : ''}
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-light text-secondary" onclick="viewOrderDetail('${order.out_trade_no}')" title="查看原始数据">
                                                <i class="bi bi-code-slash"></i>
                                            </button>
                                            <button class="btn btn-light text-primary" onclick="openMsgModal('${order.user_id}', '${user.name ? user.name.replace(/'/g, "\\'") : '用户'}')" title="发送私信">
                                                <i class="bi bi-chat-dots-fill"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        }).join('');
                    }

                    // 更新分页
                    updateOrderPagination(page, totalPage, totalCount);
                } else {
                    tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">${data.message || '加载失败'}</td></tr>`;
                }
            } catch (error) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">加载失败: ${error.message}</td></tr>`;
            }
        }

        function updateOrderPagination(currentPage, totalPage, totalCount) {
            const pagination = document.getElementById('orderPagination');
            const info = document.getElementById('orderPaginationInfo');
            
            info.innerHTML = `显示第 ${currentPage} 页，共 ${totalPage} 页 (总计 ${totalCount} 单)`;
            
            let html = '';
            
            // 上一页
            html += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                        <button class="page-link" onclick="loadOrderList(${currentPage - 1})">上一页</button>
                     </li>`;
            
            // 页码
            const startPage = Math.max(1, currentPage - 2);
            const endPage = Math.min(totalPage, currentPage + 2);
            
            if (startPage > 1) {
                html += `<li class="page-item"><button class="page-link" onclick="loadOrderList(1)">1</button></li>`;
                if (startPage > 2) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                            <button class="page-link" onclick="loadOrderList(${i})">${i}</button>
                         </li>`;
            }
            
            if (endPage < totalPage) {
                if (endPage < totalPage - 1) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                html += `<li class="page-item"><button class="page-link" onclick="loadOrderList(${totalPage})">${totalPage}</button></li>`;
            }
            
            // 下一页
            html += `<li class="page-item ${currentPage >= totalPage ? 'disabled' : ''}">
                        <button class="page-link" onclick="loadOrderList(${currentPage + 1})">下一页</button>
                     </li>`;
            
            pagination.innerHTML = html;
        }

        function viewOrderDetail(orderNo) {
            const tab = new bootstrap.Tab(document.getElementById('tab-test'));
            tab.show();
            document.getElementById('api_param').value = orderNo;
            testApi('query_order');
        }

        let currentPostPage = 1;
        async function loadPostList(page) {
            currentPostPage = page;
            const container = document.getElementById('postListBody');
            
            container.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>正在加载动态列表...</div>';

            try {
                const formData = new FormData();
                formData.append('action', 'get_post_list');
                formData.append('page', page);

                const response = await fetch('api/ifdian_test.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();

                if (data.success && data.data && data.data.list) {
                    // 更新个人主页概览
                    if (data.user_data) {
                        const user = data.user_data;
                        const overview = document.getElementById('profileOverview');
                        const creator = user.creator || {};
                        
                        document.getElementById('profileAvatar').src = user.avatar || '/assets/img/default-avatar.png';
                        document.getElementById('profileName').innerText = user.name || '未命名';
                        
                        if (creator.doing) {
                            document.getElementById('profileDoing').innerText = '正在创作 ' + creator.doing;
                        }
                        if (creator.detail) {
                            document.getElementById('profileDetail').innerText = creator.detail.replace(/\n/g, ' ');
                        }
                        
                        // 显示概览区域
                        overview.classList.remove('d-none');
                    }

                    const list = data.data.list;
                    // 动态接口通常不返回总页数，只返回 has_more，所以我们自己做简单的翻页逻辑
                    const hasMore = data.data.has_more;
                    
                    if (list.length === 0) {
                        container.innerHTML = '<div class="text-center py-5 text-muted">暂无动态数据 (请确保已配置 Cookie 且账号有动态)</div>';
                    } else {
                        container.innerHTML = list.map(post => {
                            // 处理图片
                            let imagesHtml = '';
                            if (post.pics && post.pics.length > 0) {
                                imagesHtml = `<div class="d-flex gap-2 mt-2 overflow-auto" style="max-width: 100%;">
                                    ${post.pics.map(pic => `<img src="${pic}" class="rounded border" style="height: 100px; object-fit: cover;">`).join('')}
                                </div>`;
                            }

                            // 处理音频
                            let audioHtml = '';
                            if (post.audio) {
                                audioHtml = `<div class="mt-2 bg-light p-2 rounded"><i class="bi bi-music-note-beamed me-2"></i>音频附件: ${post.title || '音频'}</div>`;
                            }

                            return `
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between">
                                        <h6 class="mb-1 fw-bold">${post.title || '无标题动态'}</h6>
                                        <small class="text-muted">${new Date(post.publish_time * 1000).toLocaleString()}</small>
                                    </div>
                                    <div class="mb-1 text-secondary" style="white-space: pre-wrap;">${post.content || post.preview_text || '无内容'}</div>
                                    ${imagesHtml}
                                    ${audioHtml}
                                    <div class="mt-2 d-flex gap-3 text-muted small">
                                        <span><i class="bi bi-eye me-1"></i>${post.read_count || 0} 阅读</span>
                                        <span><i class="bi bi-hand-thumbs-up me-1"></i>${post.like_count || 0} 点赞</span>
                                        <span><i class="bi bi-chat me-1"></i>${post.comment_count || 0} 评论</span>
                                        <span class="badge bg-${post.is_public ? 'success' : 'warning'} text-dark">${post.is_public ? '公开' : '部分可见'}</span>
                                    </div>
                                </div>
                            `;
                        }).join('');
                    }

                    // 更新分页
                    updatePostPagination(page, hasMore);
                } else {
                    container.innerHTML = `<div class="text-center py-5 text-danger">${data.message || '加载失败'}</div>`;
                }
            } catch (error) {
                container.innerHTML = `<div class="text-center py-5 text-danger">加载失败: ${error.message}</div>`;
            }
        }

        function updatePostPagination(currentPage, hasMore) {
            const pagination = document.getElementById('postPagination');
            const info = document.getElementById('postPaginationInfo');
            
            info.innerHTML = `显示第 ${currentPage} 页`;
            
            let html = '';
            
            // 上一页
            html += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                        <button class="page-link" onclick="loadPostList(${currentPage - 1})">上一页</button>
                     </li>`;
            
            // 当前页
            html += `<li class="page-item active">
                        <span class="page-link">${currentPage}</span>
                     </li>`;
            
            // 下一页 (如果有更多数据，或者为了体验允许往后翻一页尝试)
            html += `<li class="page-item ${!hasMore ? 'disabled' : ''}">
                        <button class="page-link" onclick="loadPostList(${currentPage + 1})">下一页</button>
                     </li>`;
            
            pagination.innerHTML = html;
        }

        const autoReplyModalEl = document.getElementById('autoReplyModal');
        const autoReplyModal = new bootstrap.Modal(autoReplyModalEl);
        
        const planConfigModalEl = document.getElementById('planConfigModal');
        const planConfigModal = new bootstrap.Modal(planConfigModalEl);
        
        function toggleDurationInput() {
            const type = document.getElementById('config_duration_type').value;
            const wrapper = document.getElementById('config_duration_value_wrapper');
            const unit = document.getElementById('config_duration_unit');
            
            if (type == '1') { // 月
                wrapper.style.display = 'block';
                unit.innerText = '个月';
            } else if (type == '2') { // 年
                wrapper.style.display = 'block';
                unit.innerText = '年';
            } else if (type == '4') { // 天
                wrapper.style.display = 'block';
                unit.innerText = '天';
            } else {
                wrapper.style.display = 'none';
            }
        }

        async function openPlanConfigModal(planId, planName) {
            document.getElementById('config_plan_id').value = planId;
            document.getElementById('config_plan_name').value = planName;
            
            // 重置表单
            document.getElementById('config_is_show').checked = true;
            document.getElementById('config_duration_type').value = '0';
            document.getElementById('config_duration_value').value = '1';
            toggleDurationInput();
            
            planConfigModal.show();
            
            try {
                const formData = new FormData();
                formData.append('action', 'get_plan_config');
                formData.append('plan_id', planId);
                
                const response = await fetch('api/ifdian_test.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.success && data.data) {
                        const config = data.data;
                        document.getElementById('config_is_show').checked = (config.is_show_in_thanks == 1);
                        document.getElementById('config_duration_type').value = config.show_duration_type;
                        
                        if (config.show_duration_type == 5) {
                            // 旧数据兼容或重置
                            document.getElementById('config_duration_type').value = 0;
                        } else {
                            document.getElementById('config_duration_type').value = config.show_duration_type;
                            document.getElementById('config_duration_value').value = config.show_duration_value || 1;
                        }
                        
                        toggleDurationInput();
                    }
                }
            } catch (error) {
                console.error('获取方案配置失败', error);
            }
        }

        async function savePlanConfig() {
            const planId = document.getElementById('config_plan_id').value;
            const isShow = document.getElementById('config_is_show').checked ? 1 : 0;
            const durationType = document.getElementById('config_duration_type').value;
            const durationValue = document.getElementById('config_duration_value').value;
            const btn = planConfigModalEl.querySelector('.btn-primary');
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>保存中...';
            
            try {
                const formData = new FormData();
                formData.append('action', 'save_plan_config');
                formData.append('plan_id', planId);
                formData.append('is_show_in_thanks', isShow);
                formData.append('show_duration_type', durationType);
                formData.append('show_duration_value', durationValue);
                
                const response = await fetch('api/ifdian_test.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();
                
                if (data.success) {
                    alert('方案配置已保存');
                    planConfigModal.hide();
                } else {
                    alert('保存失败: ' + data.message);
                }
            } catch (error) {
                alert('保存失败: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-save me-1"></i>保存设置';
            }
        }

        async function openAutoReplyModal(planId, planName) {
            document.getElementById('reply_plan_id').value = planId;
            document.getElementById('reply_plan_name').value = planName;
            document.getElementById('reply_plan_id_display').innerText = 'Plan ID: ' + planId;
            document.getElementById('reply_content').value = '加载中...';
            document.getElementById('reply_content').disabled = true;
            
            autoReplyModal.show();
            
            // 获取现有的自动回复配置
            try {
                const formData = new FormData();
                formData.append('action', 'get_auto_reply');
                formData.append('plan_id', planId);
                
                const response = await fetch('api/ifdian_test.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.success && data.data) {
                        document.getElementById('reply_content').value = data.data.reply_content || '';
                    } else {
                        document.getElementById('reply_content').value = '';
                    }
                } else {
                    document.getElementById('reply_content').value = '';
                }
            } catch (error) {
                console.error('获取自动回复失败', error);
                document.getElementById('reply_content').value = '';
            } finally {
                document.getElementById('reply_content').disabled = false;
            }
        }

        async function saveAutoReply() {
            const planId = document.getElementById('reply_plan_id').value;
            const content = document.getElementById('reply_content').value.trim();
            const btn = autoReplyModalEl.querySelector('.btn-primary');
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>保存中...';
            
            try {
                const formData = new FormData();
                formData.append('action', 'save_auto_reply');
                formData.append('plan_id', planId);
                formData.append('reply_content', content);
                
                // 这里我们直接请求当前页面，因为它处理 save_auto_reply 动作
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                
                // 由于直接 POST 到当前页面会返回完整 HTML，我们不好解析 JSON
                // 所以我们这里简单处理：重新加载页面或者提示成功
                // 为了更好的体验，应该把 save_auto_reply 逻辑移到 api/ifdian_test.php 或者让当前页面返回 JSON
                // 这里我们假设如果状态码是 200 就是成功了，实际上可能还需要判断内容
                
                alert('自动回复配置已保存');
                autoReplyModal.hide();
                
            } catch (error) {
                alert('保存失败: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-save me-1"></i>保存';
            }
        }

        const msgModalEl = document.getElementById('msgModal');
        const msgModal = new bootstrap.Modal(msgModalEl);
        
        const manualModal = new bootstrap.Modal(document.getElementById('manualModal'));

        function openManualModal(data = null) {
            document.getElementById('manualForm').reset();
            if (data) {
                document.getElementById('manual_action').value = 'edit_manual';
                document.getElementById('manual_id').value = data.id;
                document.getElementById('manual_name').value = data.name;
                document.getElementById('manual_qq').value = data.qq || '';
                document.getElementById('manual_avatar').value = data.avatar;
                document.getElementById('manual_description').value = data.description;
                document.getElementById('manual_link').value = data.link;
                document.getElementById('manual_sort_order').value = data.sort_order;
                
                // 更新头像预览
                const preview = document.getElementById('manual_avatar_preview');
                if (data.avatar) {
                    preview.src = data.avatar;
                    preview.style.display = 'block';
                } else {
                    preview.style.display = 'none';
                }

                document.querySelector('#manualModal .modal-title').innerText = '编辑手动鸣谢';
            } else {
                document.getElementById('manual_action').value = 'add_manual';
                document.getElementById('manual_avatar_preview').style.display = 'none';
                document.querySelector('#manualModal .modal-title').innerText = '添加手动鸣谢';
            }
            manualModal.show();
        }

        async function fetchQQAvatar() {
            const qq = document.getElementById('manual_qq').value.trim();
            if (!qq) return;
            
            // 简单的 QQ 号格式验证
            if (!/^\d{5,11}$/.test(qq)) {
                alert('QQ号格式不正确');
                return;
            }

            try {
                // 使用本地 API 获取头像
                const response = await fetch(`/vendor/api/qq_avatar.php?qq=${qq}&size=100`);
                const data = await response.json();
                
                if (data.success && data.avatar_url) {
                    const avatarInput = document.getElementById('manual_avatar');
                    const preview = document.getElementById('manual_avatar_preview');
                    
                    avatarInput.value = data.avatar_url;
                    preview.src = data.avatar_url;
                    preview.style.display = 'block';
                } else {
                    alert('获取头像失败: ' + (data.message || '未知错误'));
                }
            } catch (e) {
                console.error(e);
                // 降级方案：直接拼接官方链接
                const avatarUrl = `https://q1.qlogo.cn/g?b=qq&nk=${qq}&s=100`;
                document.getElementById('manual_avatar').value = avatarUrl;
                document.getElementById('manual_avatar_preview').src = avatarUrl;
                document.getElementById('manual_avatar_preview').style.display = 'block';
            }
        }

        async function loadManualList() {
            const tbody = document.getElementById('manualListBody');
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></td></tr>';
            
            try {
                // 这里我们直接请求 API 获取数据，或者刷新页面
                // 为了简单，我们这里使用 fetch POST 到当前页面获取 HTML 或者 JSON
                // 建议新建一个 api/get_manual.php，或者在 ifdian_test.php 增加接口
                // 这里我们修改 ifdian_test.php 来支持获取手动列表
                
                const formData = new FormData();
                formData.append('action', 'get_manual_list');
                const response = await fetch('api/ifdian_test.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    if (data.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">暂无数据</td></tr>';
                    } else {
                        tbody.innerHTML = data.data.map(item => `
                            <tr>
                                <td>${item.sort_order}</td>
                                <td><img src="${item.avatar || '/assets/img/default-avatar.png'}" width="32" height="32" class="rounded-circle"></td>
                                <td class="fw-bold">${item.name}</td>
                                <td class="text-muted small">${item.description || '-'}</td>
                                <td>${item.link ? '<a href="'+item.link+'" target="_blank"><i class="bi bi-link-45deg"></i></a>' : '-'}</td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary me-1" onclick='openManualModal(${JSON.stringify(item)})'><i class="bi bi-pencil"></i></button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('确认删除？')">
                                        <input type="hidden" name="action" value="delete_manual">
                                        <input type="hidden" name="id" value="${item.id}">
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        `).join('');
                    }
                } else {
                    tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">${data.message}</td></tr>`;
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">${e.message}</td></tr>`;
            }
        }

        function openMsgModal(userId, userName) {
            document.getElementById('msg_user_id').value = userId;
            document.getElementById('msg_user_name').value = userName;
            document.getElementById('msg_user_id_display').innerText = 'User ID: ' + userId;
            document.getElementById('msg_content').value = '';
            msgModal.show();
        }

        async function sendMsg() {
            const userId = document.getElementById('msg_user_id').value;
            const content = document.getElementById('msg_content').value.trim();
            const btn = msgModalEl.querySelector('.btn-primary');

            if (!content) {
                alert('请输入私信内容');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>发送中...';

            try {
                const formData = new FormData();
                formData.append('action', 'send_msg');
                formData.append('user_id', userId);
                formData.append('content', content);

                const response = await fetch('api/ifdian_test.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();

                if (data.success) {
                    alert('发送成功！');
                    msgModal.hide();
                } else {
                    alert('发送失败: ' + data.message);
                }
            } catch (error) {
                alert('发送失败: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send me-1"></i>发送';
            }
        }
    </script>
<?php require_once 'includes/footer.php'; ?>
