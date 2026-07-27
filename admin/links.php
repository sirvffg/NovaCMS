<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$message = $_SESSION['flash_message'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_error']);

// 处理删除链接
if (isset($_GET['delete']) && isset($_GET['type']) && $_GET['type'] === 'link') {
    $id = (int)$_GET['delete'];
    $db->prepare("DELETE FROM friend_links WHERE id=?")->execute([$id]);
    $message = '链接已删除';
    header('Location: links.php');
    exit;
}

// 处理删除分类
if (isset($_GET['delete']) && isset($_GET['type']) && $_GET['type'] === 'category') {
    $id = (int)$_GET['delete'];
    // 检查是否有链接使用此分类
    $check = $db->prepare("SELECT COUNT(*) as count FROM friend_links WHERE category_id=?");
    $check->execute([$id]);
    $result = $check->fetch();

    if ($result['count'] > 0) {
        $error = '该分类下还有友情链接，无法删除';
    } else {
        $db->prepare("DELETE FROM friend_link_categories WHERE id=?")->execute([$id]);
        $message = '分类已删除';
    }
}

// 处理添加/编辑链接
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type']) && $_POST['type'] === 'link') {
    $id = $_POST['id'] ?? 0;
    $name = $_POST['name'] ?? '';
    $url = $_POST['url'] ?? '';
    $logo = $_POST['logo'] ?? '';
    $description = $_POST['description'] ?? '';
    $rss_url = $_POST['rss_url'] ?? '';
    $contact_email = $_POST['contact_email'] ?? '';
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // 检查是否需要自动计算排序值（分类变更或新增链接）
    $needAutoSort = false;
    $oldCategoryId = null;
    if ($id > 0) {
        // 编辑时检查分类是否变更
        $stmt = $db->prepare("SELECT category_id, sort_order FROM friend_links WHERE id=?");
        $stmt->execute([$id]);
        $oldLink = $stmt->fetch();
        if ($oldLink && $oldLink['category_id'] != $category_id) {
            $needAutoSort = true;
            $oldCategoryId = $oldLink['category_id'];
        }
    } else {
        // 新增链接时自动计算排序
        $needAutoSort = true;
    }

    // 自动计算新分类的排序值
    if ($needAutoSort) {
        $stmt = $db->prepare("SELECT MAX(sort_order) as max_sort FROM friend_links WHERE category_id=?");
        $stmt->execute([$category_id]);
        $maxSort = $stmt->fetch()['max_sort'];
        $sort_order = $maxSort ? $maxSort + 1 : 1;
    }

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE friend_links SET name=?, url=?, logo=?, description=?, rss_url=?, contact_email=?, category_id=?, sort_order=?, is_active=? WHERE id=?");
        $stmt->execute([$name, $url, $logo, $description, $rss_url, $contact_email, $category_id, $sort_order, $is_active, $id]);
        $message = '链接已更新';

        // 如果分类变更，重新排序原分类的剩余链接
        if ($oldCategoryId !== null) {
            $stmt = $db->prepare("SELECT id FROM friend_links WHERE category_id=? ORDER BY sort_order ASC, id ASC");
            $stmt->execute([$oldCategoryId]);
            $remainingLinks = $stmt->fetchAll();
            $newSort = 1;
            foreach ($remainingLinks as $link) {
                $updateStmt = $db->prepare("UPDATE friend_links SET sort_order=? WHERE id=?");
                $updateStmt->execute([$newSort, $link['id']]);
                $newSort++;
            }
        }
    } else {
        $stmt = $db->prepare("INSERT INTO friend_links (name, url, logo, description, rss_url, contact_email, category_id, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $url, $logo, $description, $rss_url, $contact_email, $category_id, $sort_order, $is_active]);
        $message = '链接已添加';
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $message, 'sort_order' => $sort_order]);
        exit;
    }
    $_SESSION['flash_message'] = $message;
    header('Location: links.php');
    exit;
}

// 处理添加/编辑分类
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type']) && $_POST['type'] === 'category') {
    $id = $_POST['id'] ?? 0;
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    $sort_order = (int)($_POST['sort_order'] ?? 0);

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if (empty($name)) {
        $error = '分类名称不能为空';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }
    } else {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE friend_link_categories SET name=?, description=?, sort_order=? WHERE id=?");
            $stmt->execute([$name, $description, $sort_order, $id]);
            $message = '分类已更新';
        } else {
            $stmt = $db->prepare("INSERT INTO friend_link_categories (name, description, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$name, $description, $sort_order]);
            $message = '分类已添加';
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $message]);
            exit;
        }
        $_SESSION['flash_message'] = $message;
        header('Location: links.php');
        exit;
    }
}

// 处理审批申请
if (isset($_GET['approve']) && isset($_GET['type']) && $_GET['type'] === 'application') {
    $id = (int)$_GET['approve'];

    // 获取申请信息
    $stmt = $db->prepare("SELECT * FROM friend_link_applications WHERE id=?");
    $stmt->execute([$id]);
    $application = $stmt->fetch();

    if ($application) {
        // 获取当前最大的排序数字
        $maxSort = $db->query("SELECT MAX(sort_order) as max_sort FROM friend_links")->fetch()['max_sort'] ?? 0;
        $newSortOrder = $maxSort + 1;

        // 将申请添加到友情链接表
        $insertStmt = $db->prepare("INSERT INTO friend_links (name, url, logo, description, rss_url, contact_email, category_id, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $insertStmt->execute([
            $application['name'],
            $application['url'],
            $application['logo'],
            $application['description'],
            $application['rss_url'],
            $application['contact_email'],
            $application['category_id'],
            $newSortOrder
        ]);

        // 更新申请状态
        $updateStmt = $db->prepare("UPDATE friend_link_applications SET status=1, reviewed_at=NOW() WHERE id=?");
        $updateStmt->execute([$id]);

        // 发送邮件通知
        if (!empty($application['contact_email'])) {
            $emailResult = sendApprovalEmail($application['contact_email'], $application['name'], $config['website_name']);
            if ($emailResult) {
                $message = '已同意申请，链接已添加，已发送邮件通知';
            } else {
                $message = '已同意申请，链接已添加，但邮件发送失败';
            }
        } else {
            $message = '已同意申请，链接已添加（未提供邮箱地址，无法发送邮件）';
        }
    } else {
        $error = '申请不存在';
    }
}

// 处理拒绝申请
if (isset($_GET['reject']) && isset($_GET['type']) && $_GET['type'] === 'application') {
    $id = (int)$_GET['reject'];
    $review_notes = $_GET['notes'] ?? '';

    // 获取申请信息
    $stmt = $db->prepare("SELECT * FROM friend_link_applications WHERE id=?");
    $stmt->execute([$id]);
    $application = $stmt->fetch();

    // 更新申请状态
    $updateStmt = $db->prepare("UPDATE friend_link_applications SET status=2, review_notes=?, reviewed_at=NOW() WHERE id=?");
    $updateStmt->execute([$review_notes, $id]);

    // 发送邮件通知
    if ($application && !empty($application['contact_email'])) {
        $emailResult = sendRejectionEmail($application['contact_email'], $application['name'], $config['website_name'], $review_notes);
        if ($emailResult) {
            $message = '已拒绝申请，已发送邮件通知';
        } else {
            $message = '已拒绝申请，但邮件发送失败';
        }
    } else {
        $message = '已拒绝申请（未提供邮箱地址，无法发送邮件）';
    }
}

// 处理删除申请
if (isset($_GET['delete']) && isset($_GET['type']) && $_GET['type'] === 'application') {
    $id = (int)$_GET['delete'];
    $db->prepare("DELETE FROM friend_link_applications WHERE id=?")->execute([$id]);
    $message = '申请已删除';
}


// 获取所有分类
$categories = $db->query("SELECT * FROM friend_link_categories ORDER BY sort_order ASC, id ASC")->fetchAll();

// 将单向友链和失联博客分类排在最后，失联博客排在单向友链后面
$specialCategories = ['单向友链', '失联博客'];
$sortedCategories = [];
$lastCategories = [];
foreach ($categories as $cat) {
    if (in_array($cat['name'], $specialCategories)) {
        $lastCategories[] = $cat;
    } else {
        $sortedCategories[] = $cat;
    }
}
// 对特殊分类排序：单向友链在前，失联博客在后
$oneWayLinks = array_filter($lastCategories, fn($c) => $c['name'] === '单向友链');
$lostBlogs = array_filter($lastCategories, fn($c) => $c['name'] === '失联博客');
$lastCategories = array_merge(array_values($oneWayLinks), array_values($lostBlogs));
$categories = array_merge($sortedCategories, $lastCategories);

// 获取所有链接（关联查询分类名称），按分类分组，每个分类内单独排序
$links = $db->query("
    SELECT fl.*, flc.name as category_name
    FROM friend_links fl
    LEFT JOIN friend_link_categories flc ON fl.category_id = flc.id
    ORDER BY fl.category_id ASC, fl.sort_order ASC, fl.id DESC
")->fetchAll();

// 将链接按分类分组
$linksByCategory = [];
foreach ($links as $link) {
    $catId = $link['category_id'] ?: 0;
    $catName = $link['category_name'] ?: '未分类';
    if (!isset($linksByCategory[$catId])) {
        $linksByCategory[$catId] = [
            'name' => $catName,
            'links' => []
        ];
    }
    $linksByCategory[$catId]['links'][] = $link;
}

// 按分类的排序顺序重新排列链接分组，未分类在最前面
$sortedLinksByCategory = [];
// 先添加未分类的链接
if (isset($linksByCategory[0])) {
    $sortedLinksByCategory[0] = $linksByCategory[0];
}
// 然后按分类顺序添加
foreach ($categories as $cat) {
    if (isset($linksByCategory[$cat['id']])) {
        $sortedLinksByCategory[$cat['id']] = $linksByCategory[$cat['id']];
        $sortedLinksByCategory[$cat['id']]['name'] = $cat['name']; // 使用分类表中的名称
    }
}
$linksByCategory = $sortedLinksByCategory;

// 获取编辑的链接
$editLink = null;
if (isset($_GET['edit']) && isset($_GET['type']) && $_GET['type'] === 'link') {
    $editId = (int)$_GET['edit'];
    $editLink = $db->prepare("SELECT * FROM friend_links WHERE id=?");
    $editLink->execute([$editId]);
    $editLink = $editLink->fetch();
}

// 获取编辑的分类
$editCategory = null;
if (isset($_GET['edit']) && isset($_GET['type']) && $_GET['type'] === 'category') {
    $editId = (int)$_GET['edit'];
    $editCategory = $db->prepare("SELECT * FROM friend_link_categories WHERE id=?");
    $editCategory->execute([$editId]);
    $editCategory = $editCategory->fetch();
}

// 获取所有申请（关联查询分类名称）
$applications = $db->query("
    SELECT fla.*, flc.name as category_name
    FROM friend_link_applications fla
    LEFT JOIN friend_link_categories flc ON fla.category_id = flc.id
    ORDER BY fla.created_at DESC
")->fetchAll();

// 确定当前激活的标签页
$activeTab = $_GET['tab'] ?? 'links';
$page_title = '友情链接管理';
$extra_css = <<<'CSS'
.category-dropdown {
    position: relative;
}
.category-dropdown .dropdown-menu {
    display: none;
    position: absolute;
    inset: 0 0 auto auto;
    margin: 0;
    transform: translate(0px, 26px);
}
.category-dropdown.open .dropdown-menu {
    display: block;
}
.avatar-upload {
    position: relative;
    max-width: 205px;
    margin: 10px auto;
}
.avatar-edit {
    position: absolute;
    right: 12px;
    z-index: 1;
    top: 10px;
}
.avatar-preview {
    width: 100%;
    height: 150px;
    position: relative;
    border: 2px dashed #ddd;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: #f8f9fa;
}
.avatar-preview img {
    max-width: 100%;
    max-height: 100%;
}
.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,.02);
}
.card {
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    transition: all 0.3s ease;
}
.nav-pills .nav-link {
    color: #6c757d;
    border-radius: 0.5rem;
    padding: 0.5rem 1rem;
    margin-right: 0.5rem;
}
.nav-pills .nav-link.active {
    background-color: #0d6efd;
    color: white;
    box-shadow: 0 2px 4px rgba(13, 110, 253, 0.3);
}
.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 5px;
}
.modal-content {
    border: none;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}
.category-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
}
.stats-card {
    border-radius: 1rem;
    overflow: hidden;
}
.stats-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
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
    border-radius: 8px;
    padding: 24px;
    min-width: 400px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
.upload-progress-header {
    display: flex;
    align-items: center;
    margin-bottom: 16px;
}
.upload-progress-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e3f2fd;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 20px;
}
.upload-progress-info h5 {
    margin: 0;
    font-size: 16px;
    color: #333;
}
.upload-progress-info p {
    margin: 4px 0 0 0;
    font-size: 13px;
    color: #666;
}
.upload-progress-bar-container {
    background: #f0f0f0;
    border-radius: 4px;
    height: 8px;
    overflow: hidden;
    margin-bottom: 8px;
}
.upload-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #2196F3, #1976D2);
    transition: width 0.3s ease;
    border-radius: 4px;
}
.upload-progress-text {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: #666;
}
.upload-progress-success {
    color: #4CAF50;
    font-weight: 500;
}
.upload-progress-error {
    color: #f44336;
    font-weight: 500;
}
CSS;
require_once 'includes/header.php'; ?>

                <!-- 头部区域 -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-1">友情链接管理</h1>
                        <p class="text-muted mb-0">管理您的友情链接、分类及申请审核</p>
                    </div>
                    
                    <button class="btn btn-primary tab-btn" data-tab="links" data-bs-toggle="modal" data-bs-target="#linkModal" <?= $activeTab !== 'links' ? 'style="display:none"' : '' ?>>
                        <i class="bi bi-plus-lg"></i> 添加链接
                    </button>
                    <button class="btn btn-primary tab-btn" data-tab="categories" data-bs-toggle="modal" data-bs-target="#categoryModal" <?= $activeTab !== 'categories' ? 'style="display:none"' : '' ?>>
                        <i class="bi bi-plus-lg"></i> 添加分类
                    </button>
                </div>

                <!-- 消息提示 -->
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

                <!-- 导航标签 -->
                <div class="card mb-4">
                    <div class="card-body p-2">
                        <ul class="nav nav-pills" id="linksTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $activeTab === 'links' ? 'active' : '' ?>" id="links-tab" data-bs-toggle="tab" data-bs-target="#links-content" type="button" role="tab" onclick="updateUrl('links')">
                                    <i class="bi bi-link-45deg me-1"></i> 链接列表
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $activeTab === 'categories' ? 'active' : '' ?>" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories-content" type="button" role="tab" onclick="updateUrl('categories')">
                                    <i class="bi bi-grid me-1"></i> 分类管理
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $activeTab === 'applications' ? 'active' : '' ?>" id="applications-tab" data-bs-toggle="tab" data-bs-target="#applications-content" type="button" role="tab" onclick="updateUrl('applications')">
                                    <i class="bi bi-inbox me-1"></i> 申请审核
                                    <?php
                                    $pendingCount = count(array_filter($applications, fn($a) => $a['status'] == 0));
                                    if ($pendingCount > 0):
                                    ?>
                                    <span class="badge bg-danger rounded-pill ms-1"><?= $pendingCount ?></span>
                                    <?php endif; ?>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- 标签页内容 -->
                <div class="tab-content" id="linksTabsContent">

                    <!-- 链接管理标签页 -->
                    <div class="tab-pane fade <?= $activeTab === 'links' ? 'show active' : '' ?>" id="links-content" role="tabpanel">
                        <?php if (!empty($linksByCategory)): ?>
                        <?php foreach ($linksByCategory as $catId => $catData): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-light border-bottom">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0">
                                        <i class="bi bi-folder2-open me-2"></i><?= e($catData['name']) ?>
                                        <span class="badge bg-secondary ms-2"><?= count($catData['links']) ?> 个</span>
                                    </h6>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="ps-4">网站信息</th>
                                                <th>描述</th>
                                                <th>RSS / 联系邮箱</th>
                                                <th>排序</th>
                                                <th>状态</th>
                                                <th class="text-end pe-4">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($catData['links'] as $link): ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <div class="d-flex align-items-center">
                                                        <div class="me-3 flex-shrink-0" style="width: 40px; height: 40px; background: #f8f9fa; border-radius: 8px; display: flex; align-items: center; justify-content: center; border: 1px solid #eee;">
                                                            <?php if ($link['logo']): ?>
                                                            <img src="/assets/images/default-link.svg" data-src="<?= e($link['logo']) ?>" class="friend-logo" style="max-width:32px;max-height:32px;object-fit:contain;" alt="Logo" loading="lazy">
                                                            <?php else: ?>
                                                            <i class="bi bi-globe text-muted"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold text-dark"><?= e($link['name']) ?></div>
                                                            <a href="<?= e($link['url']) ?>" target="_blank" class="text-muted small text-decoration-none">
                                                                <?= e(substr($link['url'], 0, 30)) ?><?= strlen($link['url']) > 30 ? '...' : '' ?>
                                                                <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 10px;"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="text-muted small" title="<?= e($link['description']) ?>">
                                                        <?= e(mb_substr($link['description'], 0, 20)) ?><?= mb_strlen($link['description']) > 20 ? '...' : '' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <?php if ($link['rss_url']): ?>
                                                        <i class="bi bi-rss text-warning"></i> <a href="<?= e($link['rss_url']) ?>" target="_blank" class="text-decoration-none" title="<?= e($link['rss_url']) ?>"><?= e(mb_substr($link['rss_url'], 0, 25)) ?><?= mb_strlen($link['rss_url']) > 25 ? '...' : '' ?></a>
                                                        <?php else: ?>
                                                        <span class="text-muted">无RSS</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="small text-muted mt-1">
                                                        <?php if (!empty($link['contact_email'])): ?>
                                                        <i class="bi bi-envelope"></i> <?= e($link['contact_email']) ?>
                                                        <?php else: ?>
                                                        <span class="text-muted">无邮箱</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td><span class="badge bg-secondary rounded-pill"><?= $link['sort_order'] ?></span></td>
                                                <td>
                                                    <?php if ($link['is_active']): ?>
                                                    <span class="status-dot bg-success"></span><span class="small text-success">显示</span>
                                                    <?php else: ?>
                                                    <span class="status-dot bg-secondary"></span><span class="small text-muted">隐藏</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <div class="btn-group">
                                                        <button type="button" class="btn btn-sm btn-light text-primary" title="编辑"
                                                                data-id="<?= $link['id'] ?>"
                                                                data-name="<?= e($link['name']) ?>"
                                                                data-url="<?= e($link['url']) ?>"
                                                                data-description="<?= e($link['description']) ?>"
                                                                data-logo="<?= e($link['logo']) ?>"
                                                                data-rss_url="<?= e($link['rss_url']) ?>"
                                                                data-contact_email="<?= e($link['contact_email'] ?? '') ?>"
                                                                data-category_id="<?= $link['category_id'] ?>"
                                                                data-sort_order="<?= $link['sort_order'] ?>"
                                                                data-is_active="<?= $link['is_active'] ?>" onclick="editLink(this)">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <a href="?delete=<?= $link['id'] ?>&type=link" class="btn btn-sm btn-light text-danger"
                                                           onclick="return confirm('确定要删除这个链接吗？')" title="删除">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="text-center py-5">
                            <div class="text-muted">
                                <i class="bi bi-link-45deg display-4 d-block mb-3"></i>
                                暂无友情链接，点击右上角添加
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 分类管理标签页 -->
                    <div class="tab-pane fade <?= $activeTab === 'categories' ? 'show active' : '' ?>" id="categories-content" role="tabpanel">
                        <?php if (!empty($categories)): ?>
                        <div class="row g-4">
                            <?php foreach ($categories as $category):
                                $stmt = $db->prepare("SELECT COUNT(*) as count FROM friend_links WHERE category_id=?");
                                $stmt->execute([$category['id']]);
                                $linkCount = $stmt->fetch()['count'];
                            ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="card h-100 category-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div class="bg-primary bg-opacity-10 text-primary rounded-3 p-3">
                                                <i class="bi bi-folder2-open fs-4"></i>
                                            </div>
                                            <div class="dropdown category-dropdown">
                                                <button class="btn btn-link text-muted p-0 dropdown-toggle-btn" type="button">
                                                    <i class="bi bi-three-dots-vertical"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end category-dropdown-menu">
                                                    <li>
                                                        <a class="dropdown-item edit-category-btn" href="javascript:void(0)"
                                                            data-id="<?= $category['id'] ?>"
                                                            data-name="<?= e($category['name']) ?>"
                                                            data-description="<?= e($category['description']) ?>"
                                                            data-sort_order="<?= $category['sort_order'] ?>" onclick="editCategory(this)">
                                                            <i class="bi bi-pencil me-2"></i> 编辑
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <a class="dropdown-item text-danger" href="?delete=<?= $category['id'] ?>&type=category"
                                                           onclick="return confirm('确定要删除这个分类吗？')">
                                                            <i class="bi bi-trash me-2"></i> 删除
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                        <h5 class="card-title"><?= e($category['name']) ?></h5>
                                        <p class="card-text text-muted small mb-3" style="min-height: 40px;">
                                            <?= $category['description'] ? e($category['description']) : '暂无描述' ?>
                                        </p>
                                        <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                                            <span class="badge bg-light text-dark border">
                                                <i class="bi bi-link-45deg"></i> <?= $linkCount ?> 个链接
                                            </span>
                                            <small class="text-muted">排序: <?= $category['sort_order'] ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-5">
                            <div class="text-muted">
                                <i class="bi bi-folder2-open display-4 d-block mb-3"></i>
                                暂无分类，点击右上角添加
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 申请管理标签页 -->
                    <div class="tab-pane fade <?= $activeTab === 'applications' ? 'show active' : '' ?>" id="applications-content" role="tabpanel">
                        <!-- 统计卡片 -->
                        <div class="row g-4 mb-4">
                            <div class="col-md-4">
                                <div class="card stats-card bg-primary text-white h-100">
                                    <div class="card-body d-flex align-items-center">
                                        <div class="stats-icon bg-white bg-opacity-25 me-3">
                                            <i class="bi bi-hourglass-split"></i>
                                        </div>
                                        <div>
                                            <h2 class="mb-0"><?= count(array_filter($applications, fn($a) => $a['status'] == 0)) ?></h2>
                                            <div class="small opacity-75">待审核申请</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stats-card bg-success text-white h-100">
                                    <div class="card-body d-flex align-items-center">
                                        <div class="stats-icon bg-white bg-opacity-25 me-3">
                                            <i class="bi bi-check-lg"></i>
                                        </div>
                                        <div>
                                            <h2 class="mb-0"><?= count(array_filter($applications, fn($a) => $a['status'] == 1)) ?></h2>
                                            <div class="small opacity-75">已通过申请</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card stats-card bg-secondary text-white h-100">
                                    <div class="card-body d-flex align-items-center">
                                        <div class="stats-icon bg-white bg-opacity-25 me-3">
                                            <i class="bi bi-x-lg"></i>
                                        </div>
                                        <div>
                                            <h2 class="mb-0"><?= count(array_filter($applications, fn($a) => $a['status'] == 2)) ?></h2>
                                            <div class="small opacity-75">已拒绝申请</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 申请列表 -->
                        <div class="card">
                            <div class="card-header bg-white py-3">
                                <h5 class="mb-0">申请记录</h5>
                            </div>
                            <div class="card-body p-0">
                                <?php if (!empty($applications)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="ps-4">申请信息</th>
                                                <th>RSS/联系邮箱</th>
                                                <th>申请时间</th>
                                                <th>状态</th>
                                                <th class="text-end pe-4">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($applications as $app): ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <div class="d-flex align-items-center">
                                                        <div class="me-3 flex-shrink-0" style="width: 40px; height: 40px; background: #f8f9fa; border-radius: 8px; display: flex; align-items: center; justify-content: center; border: 1px solid #eee;">
                                                            <?php if ($app['logo']): ?>
                                                            <img src="/assets/images/default-link.svg" data-src="<?= e($app['logo']) ?>" class="friend-logo" style="max-width:32px;max-height:32px;object-fit:contain;" alt="Logo" loading="lazy">
                                                            <?php else: ?>
                                                            <i class="bi bi-globe text-muted"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold"><?= e($app['name']) ?></div>
                                                            <a href="<?= e($app['url']) ?>" target="_blank" class="text-muted small text-decoration-none">
                                                                <?= e(substr($app['url'], 0, 30)) ?>
                                                                <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 10px;"></i>
                                                            </a>
                                                            <div class="small text-muted mt-1"><?= e($app['description']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <?php if (!empty($app['rss_url'])): ?>
                                                        <i class="bi bi-rss text-warning"></i> <a href="<?= e($app['rss_url']) ?>" target="_blank" class="text-decoration-none" title="<?= e($app['rss_url']) ?>"><?= e(mb_substr($app['rss_url'], 0, 30)) ?><?= mb_strlen($app['rss_url']) > 30 ? '...' : '' ?></a>
                                                        <?php else: ?>
                                                        <span class="text-muted">无RSS</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="small text-muted mt-1">
                                                        <i class="bi bi-envelope"></i> <?= e($app['contact_email']) ?> (<?= e($app['contact_name']) ?>)
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="small"><?= date('Y-m-d', strtotime($app['created_at'])) ?></div>
                                                    <div class="small text-muted"><?= date('H:i', strtotime($app['created_at'])) ?></div>
                                                </td>
                                                <td>
                                                    <?php if ($app['status'] == 0): ?>
                                                    <span class="badge bg-warning text-dark">待审核</span>
                                                    <?php elseif ($app['status'] == 1): ?>
                                                    <span class="badge bg-success">已通过</span>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary">已拒绝</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <?php if ($app['status'] == 0): ?>
                                                    <div class="btn-group">
                                                        <a href="?approve=<?= $app['id'] ?>&type=application" 
                                                           class="btn btn-sm btn-success"
                                                           onclick="return confirm('确定同意此申请？\n同意后链接将自动添加到友情链接列表。')" title="通过">
                                                            <i class="bi bi-check-lg"></i>
                                                        </a>
                                                        <button class="btn btn-sm btn-danger" 
                                                                onclick="showRejectModal(<?= $app['id'] ?>, '<?= e($app['name']) ?>')" title="拒绝">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    </div>
                                                    <?php endif; ?>
                                                    <a href="?delete=<?= $app['id'] ?>&type=application" 
                                                       class="btn btn-sm btn-outline-danger ms-1"
                                                       onclick="return confirm('确定删除此记录？')" title="删除">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="bi bi-inbox display-4 d-block mb-3"></i>
                                        暂无申请记录
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>

    <!-- 链接编辑/添加模态框 -->
    <div class="modal fade" id="linkModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= $editLink ? '编辑链接' : '添加新链接' ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="linkForm">
                        <input type="hidden" name="type" value="link">
                        <input type="hidden" name="id" value="<?= $editLink ? $editLink['id'] : 0 ?>">

                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label">网站名称 <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" value="<?= $editLink ? e($editLink['name']) : '' ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">网站链接 <span class="text-danger">*</span></label>
                                    <input type="url" name="url" class="form-control" value="<?= $editLink ? e($editLink['url']) : '' ?>" placeholder="https://..." required>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">分类</label>
                                            <select name="category_id" class="form-select">
                                                <option value="">不分类</option>
                                                <?php foreach ($categories as $cat): ?>
                                                <option value="<?= $cat['id'] ?>" <?= $editLink && $editLink['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                                    <?= e($cat['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">排序</label>
                                            <input type="number" name="sort_order" class="form-control" value="<?= $editLink ? $editLink['sort_order'] : 0 ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">网站Logo</label>
                                    <div class="avatar-upload">
                                        <div class="avatar-edit">
                                            <input type='file' id="logoFile" accept=".png, .jpg, .jpeg" style="display:none;"/>
                                            <label class="btn btn-sm btn-light shadow-sm" for="logoFile">
                                                <i class="bi bi-pencil"></i>
                                            </label>
                                        </div>
                                        <div class="avatar-preview">
                                            <img id="logoPreview" src="/assets/images/default-link.svg" data-src="<?= $editLink && $editLink['logo'] ? e($editLink['logo']) : '' ?>" class="friend-logo" loading="lazy">
                                        </div>
                                    </div>
                                    <input type="text" name="logo" id="logoInput" class="form-control form-control-sm mt-2" 
                                           value="<?= $editLink ? e($editLink['logo']) : '' ?>" placeholder="Logo URL">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">描述</label>
                            <textarea name="description" class="form-control" rows="3"><?= $editLink ? e($editLink['description']) : '' ?></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">RSS 地址</label>
                                    <input type="url" name="rss_url" class="form-control" value="<?= $editLink ? e($editLink['rss_url']) : '' ?>" placeholder="https://example.com/feed.xml">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">联系邮箱</label>
                                    <input type="email" name="contact_email" id="contactEmailInput" class="form-control" value="<?= $editLink ? e($editLink['contact_email'] ?? '') : '' ?>" placeholder="example@email.com">
                                </div>
                            </div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= (!$editLink || $editLink['is_active']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="isActive">在前台显示此链接</label>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">保存</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- 分类编辑/添加模态框 -->
    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= $editCategory ? '编辑分类' : '添加新分类' ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="type" value="category">
                        <input type="hidden" name="id" value="<?= $editCategory ? $editCategory['id'] : 0 ?>">

                        <div class="mb-3">
                            <label class="form-label">分类名称 <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= $editCategory ? e($editCategory['name']) : '' ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">排序</label>
                            <input type="number" name="sort_order" class="form-control" value="<?= $editCategory ? $editCategory['sort_order'] : 0 ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">描述</label>
                            <textarea name="description" class="form-control" rows="3"><?= $editCategory ? e($editCategory['description']) : '' ?></textarea>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">保存</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- 拒绝申请模态框 -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">拒绝申请</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>拒绝 <strong id="rejectAppName"></strong> 的友链申请</p>
                    <div class="mb-3">
                        <label class="form-label">拒绝原因（可选）</label>
                        <textarea id="rejectNotes" class="form-control" rows="3" placeholder="请说明拒绝原因..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-danger" onclick="confirmReject()">确认拒绝</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        // 自动打开模态框（如果是编辑模式）
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($editLink): ?>
            new bootstrap.Modal(document.getElementById('linkModal')).show();
            <?php endif; ?>

            <?php if ($editCategory): ?>
            new bootstrap.Modal(document.getElementById('categoryModal')).show();
            <?php endif; ?>
        });

        // 编辑链接
        function editLink(btn) {
            const d = btn.dataset;
            const modal = document.getElementById('linkModal');
            // 清理旧的 Modal 实例，避免多次编辑状态混乱
            const old = bootstrap.Modal.getInstance(modal);
            if (old) old.dispose();
            // 重置保存按钮状态（上次保存可能留下 disabled/保存中）
            const saveBtn = modal.querySelector('button[type="submit"]');
            saveBtn.disabled = false;
            saveBtn.textContent = '保存';
            modal.querySelector('.modal-title').textContent = '编辑链接';
            modal.querySelector('input[name="id"]').value = d.id;
            modal.querySelector('input[name="name"]').value = d.name;
            modal.querySelector('input[name="url"]').value = d.url;
            modal.querySelector('textarea[name="description"]').value = d.description;
            modal.querySelector('input[name="logo"]').value = d.logo;
            const preview = modal.querySelector('#logoPreview');
            if (preview) {
                preview.src = '/assets/images/default-link.svg';
                preview.dataset.src = d.logo || '';
                preview.removeAttribute('data-loaded');
            }
            modal.querySelector('input[name="rss_url"]').value = d.rss_url || '';
            modal.querySelector('input[name="contact_email"]').value = d.contact_email || '';
            modal.querySelector('select[name="category_id"]').value = d.category_id || '';
            modal.querySelector('input[name="sort_order"]').value = d.sort_order || 0;
            modal.querySelector('input[name="is_active"]').checked = d.is_active === '1';
            new bootstrap.Modal(modal).show();
        }

        // 编辑分类
        function editCategory(btn) {
            const d = btn.dataset;
            const modal = document.getElementById('categoryModal');
            // 清理旧的 Modal 实例
            const old = bootstrap.Modal.getInstance(modal);
            if (old) old.dispose();
            // 重置保存按钮状态
            const saveBtn = modal.querySelector('button[type="submit"]');
            saveBtn.disabled = false;
            saveBtn.textContent = '保存';
            modal.querySelector('.modal-title').textContent = '编辑分类';
            modal.querySelector('input[name="id"]').value = d.id;
            modal.querySelector('input[name="name"]').value = d.name;
            modal.querySelector('input[name="sort_order"]').value = d.sort_order || 0;
            modal.querySelector('textarea[name="description"]').value = d.description;
            new bootstrap.Modal(modal).show();
        }

        // 切换Tab时更新URL（不刷新）
        function updateUrl(tab) {
            // 切换对应的按钮显示
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.style.display = btn.dataset.tab === tab ? '' : 'none';
            });
        }

        // 分类管理下拉菜单点击切换
        document.addEventListener('click', function(e) {
            const toggleBtn = e.target.closest('.category-dropdown .dropdown-toggle-btn');
            if (toggleBtn) {
                e.preventDefault();
                const dropdown = toggleBtn.closest('.category-dropdown');
                // 关闭其他打开的菜单
                document.querySelectorAll('.category-dropdown.open').forEach(d => {
                    if (d !== dropdown) d.classList.remove('open');
                });
                dropdown.classList.toggle('open');
                return;
            }
            // 点击菜单外部关闭
            if (!e.target.closest('.category-dropdown')) {
                document.querySelectorAll('.category-dropdown.open').forEach(d => {
                    d.classList.remove('open');
                });
            }
        });
        
        // 拒绝申请相关
        let rejectApplicationId = null;
        const rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));

        function showRejectModal(id, name) {
            rejectApplicationId = id;
            document.getElementById('rejectAppName').textContent = name;
            document.getElementById('rejectNotes').value = '';
            rejectModal.show();
        }

        function confirmReject() {
            const notes = document.getElementById('rejectNotes').value;
            window.location.href = `?reject=${rejectApplicationId}&type=application&notes=${encodeURIComponent(notes)}`;
        }

        // 创建上传进度条
        function createProgressOverlay(fileName, fileSize) {
            const overlay = document.createElement('div');
            overlay.className = 'upload-progress-overlay';
            overlay.innerHTML = `
                <div class="upload-progress-container">
                    <div class="upload-progress-header">
                        <div class="upload-progress-icon">📤</div>
                        <div class="upload-progress-info">
                            <h5>正在上传文件</h5>
                            <p>${fileName} (${formatFileSize(fileSize)})</p>
                        </div>
                    </div>
                    <div class="upload-progress-bar-container">
                        <div class="upload-progress-bar" style="width: 0%"></div>
                    </div>
                    <div class="upload-progress-text">
                        <span class="progress-percent">0%</span>
                        <span class="progress-speed">准备中...</span>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            return overlay;
        }

        // 格式化文件大小
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        // 格式化上传速度
        function formatSpeed(bytesPerSecond) {
            return formatFileSize(bytesPerSecond) + '/s';
        }

        // 更新进度条
        function updateProgress(overlay, percent, speed) {
            const progressBar = overlay.querySelector('.upload-progress-bar');
            const progressPercent = overlay.querySelector('.progress-percent');
            const progressSpeed = overlay.querySelector('.progress-speed');
            
            if (progressBar) progressBar.style.width = percent + '%';
            if (progressPercent) progressPercent.textContent = Math.round(percent) + '%';
            if (progressSpeed && speed !== null) {
                progressSpeed.textContent = formatSpeed(speed);
            }
        }

        // 显示上传成功
        function showUploadSuccess(overlay, message = '上传成功！') {
            const icon = overlay.querySelector('.upload-progress-icon');
            const title = overlay.querySelector('.upload-progress-info h5');
            const progressText = overlay.querySelector('.upload-progress-text');
            
            if (icon) icon.textContent = '✓';
            if (icon) icon.style.background = '#e8f5e9';
            if (title) title.textContent = message;
            if (title) title.className = 'upload-progress-success';
            if (progressText) progressText.innerHTML = '<span class="upload-progress-success">完成</span>';
            
            setTimeout(() => {
                overlay.remove();
            }, 1500);
        }

        // 显示上传失败
        function showUploadError(overlay, message = '上传失败') {
            const icon = overlay.querySelector('.upload-progress-icon');
            const title = overlay.querySelector('.upload-progress-info h5');
            const progressText = overlay.querySelector('.upload-progress-text');
            
            if (icon) icon.textContent = '✗';
            if (icon) icon.style.background = '#ffebee';
            if (title) title.textContent = message;
            if (title) title.className = 'upload-progress-error';
            if (progressText) progressText.innerHTML = '<span class="upload-progress-error">失败</span>';
            
            setTimeout(() => {
                overlay.remove();
            }, 3000);
        }
        
        // Logo上传（带进度条）
        document.getElementById('logoFile').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            if (!file.type.startsWith('image/')) {
                alert('请选择图片文件');
                return;
            }
            
            if (file.size > 2 * 1024 * 1024) {
                alert('图片大小不能超过 2MB');
                return;
            }
            
            // 创建进度条
            const progressOverlay = createProgressOverlay(file.name, file.size);
            
            const formData = new FormData();
            formData.append('image', file);
            formData.append('source', 'friendlink');
            
            const xhr = new XMLHttpRequest();
            
            let startTime = Date.now();
            let lastLoaded = 0;
            let lastTime = startTime;
            
            // 监听上传进度
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
            
            // 监听完成
            xhr.addEventListener('load', () => {
                if (xhr.status === 200) {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            document.getElementById('logoInput').value = data.url;
                            document.getElementById('logoPreview').src = data.url;
                            showUploadSuccess(progressOverlay, 'Logo上传成功！');
                        } else {
                            showUploadError(progressOverlay, data.error || '上传失败');
                        }
                    } catch (error) {
                        console.error('解析响应错误:', error);
                        showUploadError(progressOverlay, '服务器响应错误');
                    }
                } else {
                    showUploadError(progressOverlay, '上传失败 (HTTP ' + xhr.status + ')');
                }
            });
            
            // 监听错误
            xhr.addEventListener('error', () => {
                console.error('上传错误');
                showUploadError(progressOverlay, '网络错误，请重试');
            });
            
            // 监听中止
            xhr.addEventListener('abort', () => {
                showUploadError(progressOverlay, '上传已取消');
            });
            
            // 发送请求
            xhr.open('POST', '/admin/upload_image.php');
            xhr.send(formData);
        });

        // 友链 Logo 懒加载：切到哪个 tab 才加载对应图片
        function lazyLoadFriendLogos(container) {
            if (!container) return;
            container.querySelectorAll('.friend-logo').forEach(img => {
                const realSrc = img.dataset.src;
                if (!realSrc || img.getAttribute('data-loaded')) return;
                img.setAttribute('data-loaded', '1');

                const loader = new Image();
                let finished = false;

                const timer = setTimeout(() => {
                    if (finished) return;
                    finished = true;
                    loader.src = '';
                }, 2200);

                loader.onload = () => {
                    if (finished) return;
                    finished = true;
                    clearTimeout(timer);
                    img.src = realSrc;
                };

                loader.onerror = () => {
                    if (finished) return;
                    finished = true;
                    clearTimeout(timer);
                };

                loader.src = realSrc;
            });
        }

        // 标签切换时加载对应 tab 内的 logo
        document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
            tab.addEventListener('shown.bs.tab', function (e) {
                const targetId = e.target.getAttribute('data-bs-target');
                if (targetId) {
                    lazyLoadFriendLogos(document.querySelector(targetId));
                }
            });
        });

        // 页面加载时加载当前激活 tab 内的 logo
        const activePane = document.querySelector('.tab-pane.show.active');
        if (activePane) lazyLoadFriendLogos(activePane);

        // 编辑弹窗打开时加载预览图
        const linkModal = document.getElementById('linkModal');
        if (linkModal) {
            linkModal.addEventListener('shown.bs.modal', function () {
                lazyLoadFriendLogos(this);
            });
        }

        // 无刷新刷新当前 tab 内容
        async function refreshCurrentTab() {
            const activePane = document.querySelector('.tab-pane.show.active');
            if (!activePane) return;
            const paneId = activePane.id;
            try {
                const resp = await fetch(window.location.href);
                const html = await resp.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newPane = doc.getElementById(paneId);
                if (newPane) {
                    activePane.innerHTML = newPane.innerHTML;
                    // 重新触发懒加载
                    lazyLoadFriendLogos(activePane);
                }
            } catch(e) {
                // 失败就简单刷新
                window.location.reload();
            }
        }

        // AJAX 提交表单（无刷新）
        async function submitForm(form, successMessage) {
            const btn = form.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 保存中...';

            try {
                const resp = await fetch(window.location.href, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await resp.json();

                if (data.success) {
                    // 关闭弹窗
                    const modalEl = form.closest('.modal');
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                    // 确保遮罩层清理干净
                    document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
                    document.body.classList.remove('modal-open');
                    // 无刷新：后台拉取最新页面内容替换当前 tab
                    refreshCurrentTab();
                } else {
                    alert(data.error || '保存失败');
                    btn.disabled = false;
                    btn.textContent = '保存';
                }
            } catch (e) {
                alert('网络错误，请重试');
                btn.disabled = false;
                btn.textContent = '保存';
            }
        }

        // 链接表单提交
        document.getElementById('linkForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            submitForm(this, '链接已保存');
        });

        // 分类表单提交
        document.querySelector('#categoryModal form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            submitForm(this, '分类已保存');
        });
    </script>

    <?php
    /**
     * 发送友链申请通过邮件
     */
    function sendApprovalEmail($email, $siteName, $studioName) {
        if (EMAIL_MODE === 'test') {
            logEmailSending($email, '友情链接申请通过', 'test_mode', '测试模式，未实际发送');
            return true;
        }

        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = '友链申请通过 - ' . $studioName;
            $mail->Body = getApprovalEmailTemplate($siteName, $studioName);
            $mail->AltBody = "您的友链申请已通过审核！\n\n网站名称: {$siteName}\n\n您的链接已添加到我们的友链列表，感谢您的申请！";

            if ($mail->send()) {
                logEmailSending($email, '友情链接申请通过', 'success', '邮件发送成功');
                return true;
            } else {
                logEmailSending($email, '友情链接申请通过', 'error', '发送失败但无异常');
                return false;
            }
        } catch (Exception $e) {
            $error_msg = "邮件发送失败: " . ($mail->ErrorInfo ?? $e->getMessage());
            logEmailSending($email, '友情链接申请通过', 'error', $error_msg);
            error_log($error_msg);
            return false;
        }
    }

    /**
     * 发送友链申请拒绝邮件
     */
    function sendRejectionEmail($email, $siteName, $studioName, $reason = '') {
        if (EMAIL_MODE === 'test') {
            logEmailSending($email, '友情链接申请拒绝', 'test_mode', '测试模式，未实际发送');
            return true;
        }

        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = '友链申请结果 - ' . $studioName;
            $mail->Body = getRejectionEmailTemplate($siteName, $studioName, $reason);
            $mail->AltBody = "很遗憾，您的友链申请未能通过审核。\n\n网站名称: {$siteName}\n" . ($reason ? "拒绝原因: {$reason}\n" : "") . "\n感谢您的理解，期待未来有机会合作！";

            if ($mail->send()) {
                logEmailSending($email, '友情链接申请拒绝', 'success', '邮件发送成功');
                return true;
            } else {
                logEmailSending($email, '友情链接申请拒绝', 'error', '发送失败但无异常');
                return false;
            }
        } catch (Exception $e) {
            $error_msg = "邮件发送失败: " . ($mail->ErrorInfo ?? $e->getMessage());
            logEmailSending($email, '友情链接申请拒绝', 'error', $error_msg);
            error_log($error_msg);
            return false;
        }
    }

    /**
     * 友链申请通过邮件模板
     */
    function getApprovalEmailTemplate($siteName, $studioName) {
        $siteNameEscaped = htmlspecialchars($siteName);
        $studioNameEscaped = htmlspecialchars($studioName);

        return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>友链申请通过 - ' . $studioNameEscaped . '</title>
    <style>
        body { font-family: "Microsoft YaHei", Arial, sans-serif; line-height: 1.6; color: #333; background: #f5f5f5; margin: 0; padding: 20px; }
        .email-container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .email-header { background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%); padding: 30px 20px; text-align: center; }
        .email-header h1 { color: white; margin: 0; font-size: 24px; }
        .email-header p { color: rgba(255,255,255,0.9); margin: 10px 0 0 0; }
        .email-content { padding: 30px; }
        .success-icon { font-size: 60px; text-align: center; margin: 20px 0; }
        .site-info { background: #e8f5e9; border-left: 4px solid #4CAF50; padding: 20px; margin: 20px 0; border-radius: 4px; }
        .site-info p { margin: 5px 0; font-size: 16px; }
        .site-info strong { color: #2e7d32; }
        .info-box { background: #e3f2fd; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .email-footer { text-align: center; padding: 20px; background: #f8f9fa; border-top: 1px solid #eee; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>✅ 友链申请通过</h1>
            <p>' . $studioNameEscaped . ' - 申请审核结果通知</p>
        </div>
        
        <div class="email-content">
            <div class="success-icon">🎉</div>
            
            <p style="font-size: 18px; text-align: center; margin-bottom: 30px;">
                <strong>恭喜！您的友情链接申请已通过审核！</strong>
            </p>
            
            <div class="site-info">
                <p><strong>📌 网站名称：</strong>' . $siteNameEscaped . '</p>
                <p><strong>✨ 审核结果：</strong><span style="color: #4CAF50; font-weight: bold;">已通过</span></p>
                <p><strong>⏰ 审核时间：</strong>' . date('Y-m-d H:i:s') . '</p>
            </div>
            
            <div class="info-box">
                <strong>后续说明：</strong>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>您的链接已成功添加到我们的友链列表</li>
                    <li>请确保我们的链接也在您的网站上线</li>
                    <li>我们定期会检查友链状态</li>
                </ul>
            </div>
            
            <p style="text-align: center; margin-top: 30px; color: #666;">
                感谢您对 <strong>' . $studioNameEscaped . '</strong> 的支持！
            </p>
        </div>
        
        <div class="email-footer">
            <p>此邮件由 <strong>' . $studioNameEscaped . '</strong> 系统自动发送，请勿直接回复</p>
            <p>如有疑问，请联系网站管理员</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * 友链申请拒绝邮件模板
     */
    function getRejectionEmailTemplate($siteName, $studioName, $reason = '') {
        $siteNameEscaped = htmlspecialchars($siteName);
        $studioNameEscaped = htmlspecialchars($studioName);
        $reasonEscaped = $reason ? htmlspecialchars($reason) : '';
        $reasonHtml = $reason ? '<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <strong>拒绝原因：</strong>
                <p style="margin: 10px 0 0 0;">' . $reasonEscaped . '</p>
            </div>' : '';

        return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>友链申请结果 - ' . $studioNameEscaped . '</title>
    <style>
        body { font-family: "Microsoft YaHei", Arial, sans-serif; line-height: 1.6; color: #333; background: #f5f5f5; margin: 0; padding: 20px; }
        .email-container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .email-header { background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%); padding: 30px 20px; text-align: center; }
        .email-header h1 { color: white; margin: 0; font-size: 24px; }
        .email-header p { color: rgba(255,255,255,0.9); margin: 10px 0 0 0; }
        .email-content { padding: 30px; }
        .info-icon { font-size: 60px; text-align: center; margin: 20px 0; }
        .site-info { background: #ffebee; border-left: 4px solid #f44336; padding: 20px; margin: 20px 0; border-radius: 4px; }
        .site-info p { margin: 5px 0; font-size: 16px; }
        .site-info strong { color: #c62828; }
        .info-box { background: #e3f2fd; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .email-footer { text-align: center; padding: 20px; background: #f8f9fa; border-top: 1px solid #eee; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>📧 友链申请结果</h1>
            <p>' . $studioNameEscaped . ' - 申请审核结果通知</p>
        </div>
        
        <div class="email-content">
            <div class="info-icon">📋</div>
            
            <p style="font-size: 18px; text-align: center; margin-bottom: 30px;">
                <strong>很遗憾，您的友情链接申请未能通过审核</strong>
            </p>
            
            <div class="site-info">
                <p><strong>📌 网站名称：</strong>' . $siteNameEscaped . '</p>
                <p><strong>✨ 审核结果：</strong><span style="color: #f44336; font-weight: bold;">未通过</span></p>
                <p><strong>⏰ 审核时间：</strong>' . date('Y-m-d H:i:s') . '</p>
            </div>
            
            ' . $reasonHtml . '
            
            <div class="info-box">
                <strong>温馨提示：</strong>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>这并不代表您的网站不好，可能与我们的友链标准不符</li>
                    <li>如果您改进了网站，欢迎再次提交申请</li>
                    <li>我们真诚感谢您的关注和支持</li>
                </ul>
            </div>
            
            <p style="text-align: center; margin-top: 30px; color: #666;">
                感谢您对 <strong>' . $studioNameEscaped . '</strong> 的关注与支持！
            </p>
        </div>
        
        <div class="email-footer">
            <p>此邮件由 <strong>' . $studioNameEscaped . '</strong> 系统自动发送，请勿直接回复</p>
            <p>如有疑问，请联系网站管理员</p>
        </div>
    </div>
</body>
</html>';
    }
    ?>
<?php require_once 'includes/footer.php'; ?>
