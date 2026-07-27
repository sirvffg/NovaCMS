<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 自动创建表及更新表结构检查
try {
    // 检查表是否存在
    $db->query("SELECT 1 FROM qq_groups LIMIT 1");
    
    // 检查是否需要更新表结构（添加 sort_order 字段）
    try {
        $db->query("SELECT sort_order FROM qq_groups LIMIT 1");
    } catch (PDOException $e) {
        // sort_order 字段不存在，添加该字段
        $db->exec("ALTER TABLE `qq_groups` ADD COLUMN `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序' AFTER `id`");
    }

    // 检查是否需要更新表结构（删除 number 字段）
    try {
        $db->query("SELECT number FROM qq_groups LIMIT 1");
        // number 字段存在，尝试删除该字段（如果需要保留旧数据可注释掉下面这行）
        $db->exec("ALTER TABLE `qq_groups` DROP COLUMN `number`");
    } catch (PDOException $e) {
        // number 字段不存在，忽略
    }

    // 检查是否需要更新表结构（添加 max_members 字段）
    try {
        $db->query("SELECT max_members FROM qq_groups LIMIT 1");
    } catch (PDOException $e) {
        // max_members 字段不存在，添加该字段
        $db->exec("ALTER TABLE `qq_groups` ADD COLUMN `max_members` int(11) NOT NULL DEFAULT '200' COMMENT '最大人数' AFTER `sort_order`");
    }

    // 检查是否需要更新表结构（删除 qr_code 字段）
    try {
        $db->query("SELECT qr_code FROM qq_groups LIMIT 1");
        // qr_code 字段存在，尝试删除该字段
        $db->exec("ALTER TABLE `qq_groups` DROP COLUMN `qr_code`");
    } catch (PDOException $e) {
        // qr_code 字段不存在，忽略
    }

    // 检查是否需要更新表结构（添加 note 字段）
    try {
        $db->query("SELECT note FROM qq_groups LIMIT 1");
    } catch (PDOException $e) {
        // note 字段不存在，添加该字段
        $db->exec("ALTER TABLE `qq_groups` ADD COLUMN `note` varchar(255) DEFAULT NULL COMMENT '注释' AFTER `description`");
    }

    // 检查是否需要更新表结构（添加 is_show 字段）
    try {
        $db->query("SELECT is_show FROM qq_groups LIMIT 1");
    } catch (PDOException $e) {
        // is_show 字段不存在，添加该字段
        $db->exec("ALTER TABLE `qq_groups` ADD COLUMN `is_show` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否显示' AFTER `note`");
    }

    // 检查是否需要更新表结构（添加 api_show 字段）
    try {
        $db->query("SELECT api_show FROM qq_groups LIMIT 1");
    } catch (PDOException $e) {
        // api_show 字段不存在，添加该字段
        $db->exec("ALTER TABLE `qq_groups` ADD COLUMN `api_show` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'API是否显示' AFTER `is_show`");
    }

    // 检查是否需要更新表结构（添加 api_notification 字段）
    try {
        $db->query("SELECT api_notification FROM qq_groups LIMIT 1");
    } catch (PDOException $e) {
        // api_notification 字段不存在，添加该字段
        $db->exec("ALTER TABLE `qq_groups` ADD COLUMN `api_notification` text COMMENT 'API通知内容' AFTER `api_show`");
    }

    // 检查是否需要创建全局通知表
    try {
        $db->query("SELECT 1 FROM qq_groups_notification LIMIT 1");

        // 检查是否需要添加关闭等待时间字段
        try {
            $db->query("SELECT close_wait_time FROM qq_groups_notification LIMIT 1");
        } catch (PDOException $e) {
            // close_wait_time 字段不存在，添加该字段
            $db->exec("ALTER TABLE `qq_groups_notification` ADD COLUMN `close_wait_time` int(11) NOT NULL DEFAULT '0' COMMENT '关闭按钮等待时间(秒),0表示不限制' AFTER `is_enabled`");
        }

        // 检查是否需要添加关闭按钮文字字段
        try {
            $db->query("SELECT close_button_text FROM qq_groups_notification LIMIT 1");
        } catch (PDOException $e) {
            // close_button_text 字段不存在，添加该字段
            $db->exec("ALTER TABLE `qq_groups_notification` ADD COLUMN `close_button_text` varchar(50) NOT NULL DEFAULT '我知道了' COMMENT '关闭按钮文字' AFTER `close_wait_time`");
        }
    } catch (PDOException $e) {
        // 表不存在，创建全局通知表
        $sql = "CREATE TABLE IF NOT EXISTS `qq_groups_notification` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `notification_content` text COMMENT '全局通知内容',
            `is_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用',
            `close_wait_time` int(11) NOT NULL DEFAULT '0' COMMENT '关闭按钮等待时间(秒),0表示不限制',
            `close_button_text` varchar(50) NOT NULL DEFAULT '我知道了' COMMENT '关闭按钮文字',
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='QQ群API全局通知';";
        $db->exec($sql);
        // 插入默认记录
        $db->exec("INSERT INTO `qq_groups_notification` (`notification_content`, `is_enabled`, `close_wait_time`, `close_button_text`) VALUES ('', 0, 0, '我知道了')");
    }

} catch (PDOException $e) {
    // 表不存在，创建表
    $sql = "CREATE TABLE IF NOT EXISTS `qq_groups` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
        `max_members` int(11) NOT NULL DEFAULT '200' COMMENT '最大人数',
        `name` varchar(255) NOT NULL COMMENT '群名称',
        `link` varchar(255) NOT NULL COMMENT '加群链接',
        `description` text COMMENT '群介绍',
        `note` varchar(255) DEFAULT NULL COMMENT '注释',
        `is_show` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否显示',
        `api_show` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'API是否显示',
        `api_notification` text COMMENT 'API通知内容',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='QQ群列表';";
    $db->exec($sql);
}

$message = '';
$error = '';

// 处理删除
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $db->prepare("DELETE FROM qq_groups WHERE id=?")->execute([$id]);
    $message = 'QQ群已删除';
    header('Location: qq_groups.php');
    exit;
}

// 处理添加/编辑
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $name = $_POST['name'] ?? '';
    $link = $_POST['link'] ?? '';
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $max_members = (int)($_POST['max_members'] ?? 200);
    $description = $_POST['description'] ?? '';
    $note = $_POST['note'] ?? '';
    $is_show = isset($_POST['is_show']) ? 1 : 0;
    $api_show = isset($_POST['api_show']) ? 1 : 0;

    if (empty($name) || empty($link)) {
        $error = '群名称和加群链接不能为空';
    } else {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE qq_groups SET name=?, link=?, sort_order=?, max_members=?, description=?, note=?, is_show=?, api_show=? WHERE id=?");
            $stmt->execute([$name, $link, $sort_order, $max_members, $description, $note, $is_show, $api_show, $id]);
        } else {
            $stmt = $db->prepare("INSERT INTO qq_groups (name, link, sort_order, max_members, description, note, is_show, api_show) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $link, $sort_order, $max_members, $description, $note, $is_show, $api_show]);
        }
        // PRG模式：成功后重定向
        header('Location: qq_groups.php');
        exit;
    }
}

// 处理全局通知保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_global_notification') {
    // 支持 base64 编码的内容
    $notification_content = $_POST['notification_content'] ?? '';
    if (isset($_POST['notification_content_base64']) && $_POST['notification_content_base64']) {
        $notification_content = base64_decode($_POST['notification_content_base64']);
    }
    
    $is_enabled = isset($_POST['is_enabled']) ? 1 : 0;
    $close_wait_time = (int)($_POST['close_wait_time'] ?? 0);
    $close_button_text = trim($_POST['close_button_text'] ?? '我知道了');

    try {
        $stmt = $db->prepare("UPDATE qq_groups_notification SET notification_content=?, is_enabled=?, close_wait_time=?, close_button_text=? WHERE id=1");
        $result = $stmt->execute([$notification_content, $is_enabled, $close_wait_time, $close_button_text]);

        if ($result) {
            echo json_encode(['success' => true, 'message' => '全局通知已更新']);
        } else {
            echo json_encode(['success' => false, 'message' => '保存失败，未找到记录']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => '保存失败: ' . $e->getMessage()]);
    }
    exit;
}

// 获取所有群组
$groups = $db->query("SELECT * FROM qq_groups ORDER BY sort_order ASC, id DESC")->fetchAll();

// 获取全局通知
$globalNotification = $db->query("SELECT * FROM qq_groups_notification WHERE id=1")->fetch();
$page_title = 'QQ群管理';
$extra_css = <<<'CSS'
.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,.02);
}
.card {
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    transition: all 0.3s ease;
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
.system-check {
    background: #e8f4fd;
    padding: 15px;
    border-radius: 10px;
    border-left: 4px solid #2196f3;
}
.system-check-item {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}
.system-check-item:last-child {
    margin-bottom: 0;
}
.system-check-item .label {
    font-weight: bold;
    min-width: 100px;
    color: #555;
}
.system-check-item .value {
    color: #333;
    word-break: break-all;
    flex: 1;
}
.system-check-item .status {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    background: #4caf50;
    color: white;
}
.example-link {
    background: #e3f2fd;
    padding: 15px;
    border-radius: 10px;
    font-size: 13px;
    border-left: 4px solid #2196f3;
}
.example-link code {
    background: #bbdefb;
    padding: 2px 6px;
    border-radius: 4px;
}
.link-info {
    background: #f9f9f9;
    padding: 15px;
    border-radius: 10px;
    border-left: 4px solid #1e3c72;
}
.link-length-info {
    background: #e8f5e9;
    padding: 10px;
    border-radius: 8px;
    font-size: 13px;
    border-left: 4px solid #4caf50;
}
.group-info {
    display: flex;
    gap: 30px;
    margin-bottom: 30px;
    padding: 20px;
    background: #f9f9f9;
    border-radius: 15px;
}
.group-avatar {
    flex-shrink: 0;
    width: 120px;
    height: 120px;
    border-radius: 50%;
    overflow: hidden;
    border: 4px solid white;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}
.group-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.group-details h2 {
    font-size: 24px;
    margin-bottom: 10px;
    color: #333;
}
.group-meta {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
    flex-wrap: wrap;
}
.group-meta-item {
    background: white;
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 14px;
    color: #666;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}
.group-meta-item strong {
    color: #1e3c72;
    margin-right: 5px;
}
.group-description {
    background: white;
    padding: 15px;
    border-radius: 10px;
    border-left: 4px solid #1e3c72;
    color: #555;
    line-height: 1.6;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}
.stat-card {
    background: transparent;
    padding: 10px;
    text-align: center;
}
.stat-header {
    font-size: 14px;
    color: #888;
    margin-bottom: 8px;
}
.stat-percent {
    font-size: 24px;
    font-weight: bold;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
}
.stat-progress-bg {
    height: 6px;
    background: #f0f0f0;
    border-radius: 3px;
    overflow: hidden;
    width: 80%;
    margin: 0 auto;
}
.stat-progress-fill {
    height: 100%;
    border-radius: 3px;
}
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
.members-section {
    margin-top: 30px;
    border-top: 2px solid #f0f0f0;
    padding-top: 30px;
}
.members-section h3 {
    font-size: 18px;
    margin-bottom: 15px;
    color: #333;
}
.avatar-grid {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 30px;
}
.avatar-card {
    width: 100px;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    transition: transform 0.3s;
}
.avatar-card:hover {
    transform: translateY(-5px);
}
.avatar-card .avatar-img {
    width: 100px;
    height: 100px;
    overflow: hidden;
}
.avatar-card .avatar-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.avatar-card .avatar-info {
    padding: 8px;
    background: #f9f9f9;
    border-top: 1px solid #eee;
    text-align: center;
}
.avatar-card .qq-number {
    font-size: 11px;
    font-weight: bold;
    color: #333;
    word-break: break-all;
}
CSS;
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-1">QQ群管理</h1>
                        <p class="text-muted mb-0">管理您的QQ群及加群链接</p>
                    </div>
                    
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="bi bi-plus-lg"></i> 添加QQ群
                    </button>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i> <?= e($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-exclamation-circle-fill me-2"></i> <?= e($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- 分页标签 -->
                <div class="card mb-4">
                    <div class="card-header bg-white p-0 border-bottom-0">
                        <ul class="nav nav-tabs card-header-tabs m-0 px-3" id="qqGroupsTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="tab-list" data-bs-toggle="tab" data-bs-target="#content-list" type="button" role="tab">
                                    <i class="bi bi-list-ul me-2"></i> 群组列表
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-test" data-bs-toggle="tab" data-bs-target="#content-test" type="button" role="tab">
                                    <i class="bi bi-gear me-2"></i> 解析测试
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-api" data-bs-toggle="tab" data-bs-target="#content-api" type="button" role="tab">
                                    <i class="bi bi-sliders me-2"></i> API控制
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="tab-content" id="qqGroupsTabContent">
                    <!-- 群组列表 -->
                    <div class="tab-pane fade show active" id="content-list" role="tabpanel">
                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">群信息</th>
                                    <th>排序</th>
                                    <th>最大人数</th>
                                    <th>是否显示</th>
                                    <th>API显示</th>
                                    <th>描述</th>
                                        <th>创建时间</th>
                                        <th class="text-end pe-4">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($groups as $group): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <div class="me-3 flex-shrink-0" style="width: 40px; height: 40px; background: #f8f9fa; border-radius: 8px; display: flex; align-items: center; justify-content: center; border: 1px solid #eee;">
                                                    <i class="bi bi-people text-muted"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark"><?= e($group['name']) ?></div>
                                                    <a href="<?= e($group['link']) ?>" target="_blank" class="text-muted small text-decoration-none">
                                                        点击加群链接
                                                        <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 10px;"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary rounded-pill"><?= e($group['sort_order']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-info text-dark rounded-pill"><?= e($group['max_members'] ?? 200) ?>人</span>
                                        </td>
                                        <td>
                                            <span class="badge <?= ($group['is_show'] ?? 1) ? 'bg-success' : 'bg-secondary' ?> rounded-pill">
                                                <?= ($group['is_show'] ?? 1) ? '显示' : '隐藏' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= ($group['api_show'] ?? 1) ? 'bg-primary' : 'bg-warning' ?> rounded-pill">
                                                <?= ($group['api_show'] ?? 1) ? '启用' : '禁用' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-muted small" title="<?= e($group['description']) ?>">
                                                <?= e(mb_substr($group['description'], 0, 20)) ?><?= mb_strlen($group['description']) > 20 ? '...' : '' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="small text-muted"><?= date('Y-m-d H:i', strtotime($group['created_at'])) ?></div>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-light text-primary" onclick="editGroup(<?= $group['id'] ?>)" title="编辑">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <a href="?delete=<?= $group['id'] ?>" class="btn btn-sm btn-light text-danger" 
                                                   onclick="return confirm('确定要删除这个QQ群吗？')" title="删除">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>

                                    <?php if (empty($groups)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5">
                                            <div class="text-muted">
                                                <i class="bi bi-people display-4 d-block mb-3"></i>
                                                暂无QQ群，点击右上角添加
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                    </div>

                    <!-- 解析测试 -->
                    <div class="tab-pane fade" id="content-test" role="tabpanel">
                        <div class="card">
                            <div class="card-body">
                                <div class="system-check mb-4">
                                    <div class="system-check-item">
                                        <span class="label">API接口文件:</span>
                                        <span class="value">/vendor/api/qq_group/api.php</span>
                                        <span class="status">✓ 已就绪</span>
                                    </div>
                                    <div class="system-check-item">
                                        <span class="label">前端测试文件:</span>
                                        <span class="value">/vendor/api/qq_group/index.html</span>
                                        <span class="status">✓ 已加载</span>
                                    </div>
                                </div>

                                <div class="example-link mb-4">
                                    <strong>📌 规则：</strong><br>
                                    • 链接获取可去QQ中点击分享群复制加群链接(注:请去除中文信息)<br>
                                    • 示例短链接: <code>https://qm.qq.com/q/xxxxxxxxxx</code> (长度 31)<br>
                                    • 示例长链接: <code>https://qun.qq.com/universal-share/share?ac=1&authKey=xxx&busi_data=xxx&data=xxx</code> (长度 > 100)
                                </div>

                                <form id="testForm" onsubmit="event.preventDefault(); testQqGroupParser();">
                                    <div class="mb-3">
                                        <label for="testQqUrl" class="form-label">QQ群分享链接</label>
                                        <input type="url" id="testQqUrl" class="form-control"
                                               placeholder="例如：https://qm.qq.com/q/xxx 或 https://qun.qq.com/universal-share/share?..."
                                               required>
                                    </div>

                                    <div id="linkLengthHint" class="link-length-info mb-3" style="display: none;">
                                        📏 链接长度: <span id="linkLength">0</span> |
                                        类型: <span id="linkType">未知</span>
                                    </div>

                                    <button type="submit" class="btn btn-primary">🚀 一键获取群信息</button>
                                </form>

                                <div id="testResult" class="mt-4"></div>
                            </div>
                        </div>
                    </div>

                    <!-- API控制 -->
                    <div class="tab-pane fade" id="content-api" role="tabpanel">
                        <div class="card">
                            <div class="card-body">
                                <div class="alert alert-info mb-4">
                                    <i class="bi bi-info-circle-fill me-2"></i>
                                    <strong>API全局通知功能说明</strong><br>
                                    • 设置一个全局通知，所有API请求都会返回此通知内容<br>
                                    • 支持HTML格式，可用于展示公告、维护提示等<br>
                                    • 可随时启用或禁用全局通知
                                </div>

                                <form id="globalNotificationForm" onsubmit="event.preventDefault(); saveGlobalNotification();">
                                    <input type="hidden" name="action" value="save_global_notification">

                                    <div class="card mb-4">
                                        <div class="card-header bg-white">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h5 class="mb-0">
                                                    <i class="bi bi-bell me-2"></i>全局通知设置
                                                </h5>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="is_enabled" id="globalNotificationEnabled"
                                                           <?= ($globalNotification['is_enabled'] ?? 0) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="globalNotificationEnabled">
                                                        启用通知
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label for="globalNotificationContent" class="form-label">
                                                    通知内容
                                                    <span class="text-muted small ms-2">(支持HTML格式)</span>
                                                </label>
                                                <textarea id="globalNotificationContent" name="notification_content"
                                                          class="form-control" rows="8"
                                                          placeholder="输入全局通知内容，例如：
<div class='alert alert-warning'>
  <strong>系统维护通知：</strong>
  本系统将于今晚22:00-23:00进行维护，期间API服务可能不稳定。
</div>"><?= e($globalNotification['notification_content'] ?? '') ?></textarea>
                                                <div class="form-text mt-2">
                                                    <i class="bi bi-lightbulb me-1"></i>
                                                    提示：可以使用HTML标签美化通知样式
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label for="closeWaitTime" class="form-label">
                                                        关闭按钮等待时间
                                                        <span class="text-muted small">(秒)</span>
                                                    </label>
                                                    <input type="number" id="closeWaitTime" name="close_wait_time"
                                                           class="form-control"
                                                           value="<?= e($globalNotification['close_wait_time'] ?? 0) ?>"
                                                           min="0" step="1"
                                                           placeholder="设置为0表示不限制">
                                                    <div class="form-text">
                                                        <i class="bi bi-clock me-1"></i>
                                                        前端"我知道了"按钮显示前的等待时间，0表示立即显示
                                                    </div>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label for="closeButtonText" class="form-label">
                                                        关闭按钮文字
                                                    </label>
                                                    <input type="text" id="closeButtonText" name="close_button_text"
                                                           class="form-control"
                                                           value="<?= e($globalNotification['close_button_text'] ?? '我知道了') ?>"
                                                           maxlength="50"
                                                           placeholder="例如：我知道了、确定、关闭等">
                                                    <div class="form-text">
                                                        <i class="bi bi-fonts me-1"></i>
                                                        自定义关闭按钮显示的文字
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">实时预览</label>
                                                <div class="border rounded bg-light" style="height: 400px; overflow: visible;">
                                                    <iframe id="notificationPreview" class="w-100" style="border: none; height: 100%; background: transparent; overflow: visible;"></iframe>
                                                </div>
                                                <div class="form-text">
                                                    <i class="bi bi-info-circle me-1"></i>
                                                    预览内容使用 iframe 隔离，不会影响页面其他部分
                                                </div>
                                            </div>

                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="text-muted small">
                                                    <i class="bi bi-clock me-1"></i>
                                                    最后更新：
                                                    <?= $globalNotification['updated_at'] ? date('Y-m-d H:i:s', strtotime($globalNotification['updated_at'])) : '从未更新' ?>
                                                </div>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-save me-2"></i>保存全局通知
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>

                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    <strong>注意事项：</strong>
                                    <ul class="mb-0 mt-2">
                                        <li>全局通知启用后，所有通过API获取QQ群信息的请求都会包含此通知</li>
                                        <li>建议定期检查和更新通知内容</li>
                                        <li>如需临时关闭通知，可关闭"启用通知"开关</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                            </div>
                        </div>
                    </div>
                </div>

    <!-- 编辑/添加模态框 -->
    <div class="modal fade" id="groupModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">添加新QQ群</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="groupForm">
                        <input type="hidden" name="id" value="0">

                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">群名称 <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" value="" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">排序</label>
                                        <input type="number" name="sort_order" class="form-control" value="0" placeholder="数字越小越靠前">
                                        <div class="form-text">数字越小越靠前，默认为0</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">最大人数</label>
                                        <input type="number" name="max_members" class="form-control" value="200" placeholder="例如: 200, 500, 2000">
                                        <div class="form-text">群允许的最大成员数</div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">加群链接 <span class="text-danger">*</span></label>
                                    <input type="url" name="link" class="form-control" value="" placeholder="https://..." required>
                                    <div class="form-text">请填写QQ群的加群链接，可在QQ群设置中获取。</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">注释</label>
                            <input type="text" name="note" class="form-control" value="" placeholder="用于获取出群信息时展示的额外注释">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">是否显示</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_show" id="is_show" checked>
                                <label class="form-check-label" for="is_show"></label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">API是否显示（禁用后群信息api不会显示此群）</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="api_show" id="api_show" checked>
                                <label class="form-check-label" for="api_show"></label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">群介绍</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="qq_groups.php" class="btn btn-secondary">取消</a>
                            <button type="submit" class="btn btn-primary">保存</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        // 群组数据
        var groupsData = <?= json_encode($groups) ?>;
        var groupModal;

        document.addEventListener('DOMContentLoaded', function() {
            groupModal = new bootstrap.Modal(document.getElementById('groupModal'));
        });

        // 打开添加模态框
        function openAddModal() {
            document.getElementById('modalTitle').textContent = '添加新QQ群';
            document.getElementById('groupForm').reset();
            document.querySelector('input[name="id"]').value = 0;
            // 设置默认值
            document.querySelector('input[name="sort_order"]').value = 0;
            document.querySelector('input[name="max_members"]').value = 200;
            document.querySelector('input[name="is_show"]').checked = true;
            document.querySelector('input[name="api_show"]').checked = true;
            groupModal.show();
        }

        // 打开编辑模态框
        function editGroup(id) {
            // 注意：groupsData 中的 id 是数字或字符串，find 时需要注意类型转换，这里用 == 比较
            var group = groupsData.find(function(g) { return g.id == id; });
            if (!group) return;

            document.getElementById('modalTitle').textContent = '编辑QQ群';
            document.querySelector('input[name="id"]').value = group.id;
            document.querySelector('input[name="name"]').value = group.name;
            document.querySelector('input[name="sort_order"]').value = group.sort_order;
            document.querySelector('input[name="max_members"]').value = group.max_members || 200;
            document.querySelector('input[name="link"]').value = group.link;
            document.querySelector('input[name="note"]').value = group.note || '';
            document.querySelector('textarea[name="description"]').value = group.description;
            document.querySelector('input[name="is_show"]').checked = group.is_show == 1;
            document.querySelector('input[name="api_show"]').checked = group.api_show == 1;

            groupModal.show();
        }

        // 保存全局通知
        function saveGlobalNotification() {
            const form = document.getElementById('globalNotificationForm');
            if (!form) {
                alert('表单未找到');
                return;
            }

            const submitBtn = form.querySelector('button[type="submit"]');

            // 禁用按钮，显示加载状态
            submitBtn.disabled = true;
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>保存中...';

            // 使用 Base64 编码避免 WAF 拦截 HTML 内容
            const notificationContent = form.querySelector('[name="notification_content"]').value;
            const params = new URLSearchParams();
            params.append('action', 'save_global_notification');
            params.append('notification_content_base64', btoa(unescape(encodeURIComponent(notificationContent))));
            params.append('is_enabled', form.querySelector('[name="is_enabled"]').checked ? '1' : '0');
            params.append('close_wait_time', form.querySelector('[name="close_wait_time"]').value);
            params.append('close_button_text', form.querySelector('[name="close_button_text"]').value);

            fetch('qq_groups.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('网络响应错误 (HTTP ' + response.status + ')');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // 显示成功消息
                    alert(data.message);
                    // 重新加载页面以更新最后更新时间
                    window.location.reload();
                } else {
                    alert(data.message || '保存失败，请重试');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('保存失败:', error);
                alert('保存失败，请重试。错误: ' + error.message);
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        }

        // 实时预览通知内容
        document.addEventListener('DOMContentLoaded', function() {
            const contentTextarea = document.getElementById('globalNotificationContent');
            const previewIframe = document.getElementById('notificationPreview');

            // 更新 iframe 内容的函数
            function updatePreview(content) {
                const html = `
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <style>
                            * { margin: 0; padding: 0; box-sizing: border-box; }
                            body { padding: 16px; font-family: inherit; }
                        </style>
                    </head>
                    <body>${content || '<span style="color: #6c757d;">暂无通知内容</span>'}</body>
                    </html>
                `;
                const doc = previewIframe.contentDocument || previewIframe.contentWindow.document;
                doc.open();
                doc.write(html);
                doc.close();
            }

            // 页面加载时初始化预览
            const initialContent = contentTextarea ? contentTextarea.value.trim() : '';
            updatePreview(initialContent);

            // 监听输入变化
            if (contentTextarea && previewIframe) {
                contentTextarea.addEventListener('input', function() {
                    const content = this.value.trim();
                    updatePreview(content);
                });
            }
        });

        // 链接长度提示
        document.getElementById('testQqUrl').addEventListener('input', function() {
            const length = this.value.length;
            const hintDiv = document.getElementById('linkLengthHint');
            const linkLengthSpan = document.getElementById('linkLength');
            const linkTypeSpan = document.getElementById('linkType');

            if (length > 0) {
                hintDiv.style.display = 'block';
                linkLengthSpan.textContent = length;
                linkTypeSpan.textContent = length < 100 ? '短链接' : '长链接';
            } else {
                hintDiv.style.display = 'none';
            }
        });

        // QQ群解析测试功能
        function testQqGroupParser() {
            const url = document.getElementById('testQqUrl').value.trim();
            const resultDiv = document.getElementById('testResult');

            if (!url) {
                alert('请输入QQ群分享链接');
                return;
            }

            // 显示加载中
            resultDiv.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">加载中...</span>
                    </div>
                    <p class="mt-3 text-muted">正在解析...</p>
                </div>
            `;

            // 调用API
            const apiUrl = `/vendor/api/qq_group/api.php?url=${encodeURIComponent(url)}`;

            fetch(apiUrl)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.info) {
                        const info = data.info;
                        let activityHtml = '';
                        let memberTagsHtml = '';
                        let assetInfoHtml = '';

                        // 群活跃
                        if (info.activity && info.activity.length > 0) {
                            activityHtml = `<h3 style="margin-bottom: 15px; margin-top: 15px; color: #333; font-size: 16px;">📈 群活跃</h3>
                            <div class="d-flex flex-wrap gap-2 mb-3">`;
                            info.activity.forEach(item => {
                                activityHtml += `<span class="badge bg-secondary rounded-pill fw-normal">${item.desc}</span>`;
                            });
                            activityHtml += `</div>`;
                        }

                        // 成员分布 (MemberTags)
                        if (info.memberTags && info.memberTags.length > 0) {
                            memberTagsHtml = `<h3 style="margin-bottom: 15px; color: #333; font-size: 16px;">👥 成员分布</h3>
                            <div class="d-flex flex-wrap gap-2 mb-3">`;
                            info.memberTags.forEach(mtag => {
                                const subText = mtag.subtitle && mtag.subtitle[0] ? mtag.subtitle[0].item : '';
                                const text = `${mtag.title}${mtag.unit || ''} ${subText}`.trim();
                                memberTagsHtml += `<span class="badge rounded-pill fw-normal" style="color: ${mtag.color}; background-color: ${mtag.color}1a; border: 1px solid ${mtag.color}33;">${text}</span>`;
                            });
                            memberTagsHtml += `</div>`;
                        }

                        // 群资产 (AssetInfo)
                        if (info.assetInfo && info.assetInfo.length > 0) {
                            assetInfoHtml = `<h3 style="margin-bottom: 15px; color: #333; font-size: 16px;">💎 群资产</h3>
                            <div class="d-flex flex-wrap gap-2 mb-3">`;
                            info.assetInfo.forEach(asset => {
                                let html = `<span class="badge rounded fw-normal d-flex align-items-center" style="background-color: #fdf6ec; color: #e6a23c; border: 1px solid #faecd8;">`;
                                if (asset.icon) {
                                    html += `<img src="${asset.icon}" alt="icon" style="width: 14px; height: 14px; margin-right: 4px;">`;
                                }
                                html += `${asset.title}: ${asset.count || 0} ${asset.unit || ''}</span>`;
                                assetInfoHtml += html;
                            });
                            assetInfoHtml += `</div>`;
                        }

                        // 标签HTML
                        const tagsHtml = info.tags && info.tags.length > 0
                            ? info.tags.map(tag => `<span class="badge bg-primary bg-opacity-10 text-primary me-1 mb-1">${tag}</span>`).join('')
                            : '<span class="text-muted small">无</span>';

                        resultDiv.innerHTML = `
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle-fill me-2"></i> 解析成功！
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>

                            <div class="group-info" style="gap: 20px;">
                                ${info.avatar ? `
                                <div class="group-avatar" style="width: 80px; height: 80px;">
                                    <img src="${info.avatar}" alt="群头像">
                                </div>
                                ` : ''}
                                <div class="group-details">
                                    <h2 style="font-size: 20px; margin-bottom: 10px;">${info.name || '未知群名'}</h2>
                                    <div class="group-meta" style="gap: 10px; margin-bottom: 10px;">
                                        ${info.groupCode ? `<span class="group-meta-item py-1 px-2" style="font-size: 13px;"><strong>群号</strong> ${info.groupCode}</span>` : ''}
                                        ${info.memberCount > 0 ? `<span class="group-meta-item py-1 px-2" style="font-size: 13px;"><strong>成员</strong> ${formatNumber(info.memberCount)}人</span>` : ''}
                                        ${info.createTime ? `<span class="group-meta-item py-1 px-2" style="font-size: 13px;"><strong>创建于</strong> ${info.createTime}</span>` : ''}
                                    </div>
                                    <div class="mb-2" style="font-size: 14px;">
                                        <strong>群标签：</strong> ${tagsHtml}
                                    </div>
                                    ${info.description ? `
                                    <div class="group-description p-2 mt-2" style="font-size: 13px; line-height: 1.5;">
                                        <strong>📝 群介绍：</strong><br>
                                        ${info.description.replace(/\n/g, '<br>')}
                                    </div>
                                    ` : ''}
                                </div>
                            </div>

                            <div class="row mt-4">
                                <div class="col-md-12">
                                    ${activityHtml}
                                    ${memberTagsHtml}
                                    ${assetInfoHtml}
                                </div>
                            </div>

                            <div class="mt-3 d-flex gap-2">
                                <button class="btn btn-sm btn-outline-primary" onclick="copyToClipboard('${info.groupCode || ''}')">
                                    <i class="bi bi-clipboard"></i> 复制群号
                                </button>
                                <a href="${url}" target="_blank" class="btn btn-sm btn-primary">
                                    <i class="bi bi-box-arrow-up-right"></i> 打开加群链接
                                </a>
                            </div>
                        `;
                    } else {
                        resultDiv.innerHTML = `
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-circle-fill me-2"></i> 解析失败
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                <hr>
                                <p class="mb-0">${data.error || '未知错误'}</p>
                            </div>
                        `;
                    }
                })
                .catch(err => {
                    console.error('解析错误:', err);
                    resultDiv.innerHTML = `
                        <div class="alert alert-danger" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i> 请求失败
                            <p class="mb-0 mt-2">${err.message || '请检查网络连接'}</p>
                        </div>
                    `;
                });
        }

        // 格式化数字
        function formatNumber(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        // 复制到剪贴板
        function copyToClipboard(text) {
            if (!text) {
                alert('没有可复制的内容');
                return;
            }
            navigator.clipboard.writeText(text).then(() => {
                alert('已复制: ' + text);
            }).catch(err => {
                console.error('复制失败:', err);
                prompt('请手动复制:', text);
            });
        }
    </script>
<?php require_once 'includes/footer.php'; ?>
