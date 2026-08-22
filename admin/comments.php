<?php
session_start();

// 如果未登录，重定向到登录页
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/comment_functions.php';

$db = getDB();

// AJAX 操作处理：批准 / 拒绝 / 删除，返回 JSON 并由前端就地更新行状态
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => '安全验证失败，请刷新页面后重试']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $comment_id = (int)($_POST['comment_id'] ?? 0);
    $response = ['success' => false, 'message' => '未知操作'];

    if ($comment_id > 0) {
        if ($action === 'approve' || $action === 'reject') {
            $status = $action === 'approve' ? 'approved' : 'rejected';
            try {
                $stmt = $db->prepare("UPDATE blog_comments SET status = ? WHERE id = ?");
                $stmt->execute([$status, $comment_id]);
                $response = [
                    'success' => true,
                    'status'  => $status,
                    'message' => $status === 'approved' ? '评论已批准' : '评论已拒绝',
                ];
            } catch (Exception $e) {
                error_log('Admin comment status update failed: ' . $e->getMessage());
                $response = ['success' => false, 'message' => '操作失败，请稍后重试'];
            }
        } elseif ($action === 'delete') {
            $result = deleteComment($comment_id);
            $response = $result['success']
                ? ['success' => true, 'removed' => true, 'message' => '评论及其回复已删除']
                : ['success' => false, 'message' => $result['message'] ?? '删除失败'];
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 分页参数
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// 筛选参数（状态使用白名单）
$status_map = ['pending' => '待审核', 'approved' => '已批准', 'rejected' => '已拒绝'];
$status_filter = isset($status_map[$_GET['status'] ?? '']) ? $_GET['status'] : 'all';
$post_filter = is_numeric($_GET['post'] ?? '') ? (int)$_GET['post'] : 'all';
$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
if (function_exists('mb_substr')) {
    $search = mb_substr($search, 0, 100, 'UTF-8');
} else {
    $search = substr($search, 0, 100);
}

// 构建查询条件
$where_conditions = [];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "c.status = ?";
    $params[] = $status_filter;
}
if ($post_filter !== 'all') {
    $where_conditions[] = "c.post_id = ?";
    $params[] = $post_filter;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where_conditions[] = "(c.content LIKE ? OR c.username LIKE ? OR c.email LIKE ? OR c.ip_address LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

// 评论总数
$count_stmt = $db->prepare("SELECT COUNT(*) as total FROM blog_comments c {$where_clause}");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetch()['total'];
$total_pages = max(1, (int)ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// 评论列表：含文章标题、评论者身份（登录用户 JOIN admins / 匿名用户取自身字段）、回复目标与回复数
$sql = "
    SELECT c.*,
           p.title AS post_title,
           a.username AS admin_username, a.role AS user_role,
           parent.username AS parent_author_name,
           pa.username AS parent_admin_name,
           (SELECT COUNT(*) FROM blog_comments WHERE parent_id = c.id) AS reply_count
    FROM blog_comments c
    LEFT JOIN blog_posts p ON c.post_id = p.id
    LEFT JOIN admins a ON c.user_id = a.id
    LEFT JOIN blog_comments parent ON c.parent_id = parent.id
    LEFT JOIN admins pa ON parent.user_id = pa.id
    {$where_clause}
    ORDER BY c.created_at DESC
    LIMIT {$per_page} OFFSET {$offset}
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$comments = $stmt->fetchAll();

// 有评论的文章（用于筛选下拉，避免全量标题撑爆选项）
$posts = $db->query("SELECT DISTINCT p.id, p.title FROM blog_posts p INNER JOIN blog_comments c ON c.post_id = p.id ORDER BY p.title ASC")->fetchAll();

// 状态统计
$stats = [
    'total'    => (int)$db->query("SELECT COUNT(*) as count FROM blog_comments")->fetch()['count'],
    'approved' => (int)$db->query("SELECT COUNT(*) as count FROM blog_comments WHERE status = 'approved'")->fetch()['count'],
    'pending'  => (int)$db->query("SELECT COUNT(*) as count FROM blog_comments WHERE status = 'pending'")->fetch()['count'],
    'rejected' => (int)$db->query("SELECT COUNT(*) as count FROM blog_comments WHERE status = 'rejected'")->fetch()['count'],
];

// 状态徽章映射
$status_badges = [
    'pending'  => ['text' => '待审核', 'class' => 'text-bg-warning', 'icon' => 'bi-clock'],
    'approved' => ['text' => '已批准', 'class' => 'text-bg-success', 'icon' => 'bi-check-circle'],
    'rejected' => ['text' => '已拒绝', 'class' => 'text-bg-danger',  'icon' => 'bi-x-circle'],
];

$page_title = '评论管理';
require_once 'includes/header.php'; ?>

                <?php if (!isCommentsEnabled()): ?>
                <div class="alert alert-warning d-flex align-items-center mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div>
                        评论功能已关闭（comments 插件未启用）。已有评论仍可查看和管理，但访客无法发表新评论。
                        <a href="plugins.php?plugin=comments" class="alert-link ms-1">前往启用 →</a>
                    </div>
                </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">评论管理</h1>
                    <?php if ($stats['pending'] > 0): ?>
                    <a href="comments.php?status=pending" class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-clock"></i> <?= $stats['pending'] ?> 条待审核
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= e($_SESSION['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= e($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>

                <div class="alert-toast" id="toastMessage"></div>
                <style>
                .alert-toast {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 10000;
                    min-width: 300px;
                    opacity: 0;
                    transform: translateY(-20px);
                    transition: all 0.3s ease;
                    pointer-events: none;
                }
                .alert-toast.show {
                    opacity: 1;
                    transform: translateY(0);
                    pointer-events: auto;
                }
                </style>

                <!-- 状态统计（可点击作为筛选） -->
                <div class="row g-3 mb-4">
                    <?php
                    $stat_cards = [
                        ['key' => 'total',    'label' => '全部评论', 'icon' => 'bi-chat-square-text', 'filter' => 'all',       'accent' => 'primary'],
                        ['key' => 'approved', 'label' => '已批准',   'icon' => 'bi-check-circle',      'filter' => 'approved',  'accent' => 'success'],
                        ['key' => 'pending',  'label' => '待审核',   'icon' => 'bi-clock',             'filter' => 'pending',   'accent' => 'warning'],
                        ['key' => 'rejected', 'label' => '已拒绝',   'icon' => 'bi-x-circle',          'filter' => 'rejected',  'accent' => 'danger'],
                    ];
                    foreach ($stat_cards as $card):
                        $is_active = $status_filter === $card['filter'];
                        $link = 'comments.php?status=' . $card['filter'];
                        if ($post_filter !== 'all') $link .= '&post=' . $post_filter;
                        if ($search !== '') $link .= '&q=' . urlencode($search);
                    ?>
                    <div class="col-6 col-lg-3">
                        <a href="<?= e($link) ?>" class="d-block text-decoration-none rounded-3 border <?= $is_active ? 'border-primary shadow-sm' : '' ?> p-3 h-100" style="<?= $is_active ? 'background: var(--bs-primary-bg-subtle, #eef2ff);' : '' ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="text-secondary small"><i class="bi <?= $card['icon'] ?>"></i> <?= $card['label'] ?></div>
                                    <div class="fs-3 fw-semibold lh-1 mt-1" data-stat-count="<?= $card['key'] ?>"><?= $stats[$card['key']] ?></div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- 筛选与搜索 -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="comments.php" class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label small text-secondary mb-1" for="filter-q">关键词</label>
                                <input type="search" id="filter-q" name="q" class="form-control" value="<?= e($search) ?>" placeholder="搜索内容 / 昵称 / 邮箱 / IP">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-secondary mb-1" for="filter-post">文章</label>
                                <select id="filter-post" name="post" class="form-select">
                                    <option value="all">全部文章</option>
                                    <?php foreach ($posts as $post): ?>
                                    <option value="<?= (int)$post['id'] ?>" <?= $post_filter === (int)$post['id'] ? 'selected' : '' ?>>
                                        <?= e($post['title']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <input type="hidden" name="status" value="<?= e($status_filter) ?>">
                            <div class="col-md-4 d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i> 筛选</button>
                                <a href="comments.php" class="btn btn-outline-secondary">重置</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- 评论列表 -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-chat-square-text"></i> 评论列表</h5>
                        <span class="text-secondary small" data-comment-total>共 <?= $total ?> 条<?= $search !== '' ? '（关键词：' . e($search) . '）' : '' ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div id="commentList">
                            <?php if (empty($comments)): ?>
                            <div class="text-center py-5" data-empty-state>
                                <i class="bi bi-chat-square-dots display-5 text-secondary d-block mb-2"></i>
                                <p class="text-secondary mb-0">没有符合条件的评论</p>
                            </div>
                            <?php else: ?>
                            <div class="list-group list-group-flush" data-comment-rows>
                                <?php foreach ($comments as $comment):
                                    // 评论者展示：登录用户取 admins 表身份，匿名用户取评论自身字段
                                    $is_logged_in = !empty($comment['user_id']);
                                    $display_name = $is_logged_in ? ($comment['admin_username'] ?? '已注销用户') : ($comment['username'] ?: '游客');
                                    $avatar_url = getCommentAvatarUrl((string)($comment['email'] ?? ''), 100);
                                    $content_text = strip_tags((string)$comment['content']);
                                    $content_len = function_exists('mb_strlen') ? mb_strlen($content_text, 'UTF-8') : strlen($content_text);
                                    $is_truncatable = $content_len > 140;
                                    $short_text = $is_truncatable && function_exists('mb_substr')
                                        ? mb_substr($content_text, 0, 140, 'UTF-8') . '…'
                                        : ($is_truncatable ? substr($content_text, 0, 140) . '…' : $content_text);
                                    $badge = $status_badges[$comment['status']] ?? $status_badges['pending'];
                                    // 父评论若已被删除则 JOIN 不到，提示已删除
                                    $parent_name = '';
                                    if (!empty($comment['parent_id'])) {
                                        if (!empty($comment['parent_admin_name'])) {
                                            $parent_name = $comment['parent_admin_name'];
                                        } elseif (!empty($comment['parent_author_name'])) {
                                            $parent_name = $comment['parent_author_name'];
                                        } else {
                                            $parent_name = '已删除的评论';
                                        }
                                    }
                                    $created_full = date('Y-m-d H:i:s', strtotime($comment['created_at']));
                                ?>
                                <div class="list-group-item px-3 py-3" data-comment-row data-comment-id="<?= (int)$comment['id'] ?>" data-status="<?= e($comment['status']) ?>">
                                    <div class="d-flex gap-3">
                                        <img src="<?= e($avatar_url) ?>" alt="" width="40" height="40" class="rounded-circle flex-shrink-0 mt-1" loading="lazy" style="width:40px;height:40px;object-fit:cover;background:var(--bs-secondary-bg);">
                                        <div class="flex-grow-1 min-w-0">
                                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                <strong class="text-truncate"><?= e($display_name) ?></strong>
                                                <?php if ($is_logged_in && ($comment['user_role'] ?? '') === 'admin'): ?>
                                                <span class="badge text-bg-danger"><i class="bi bi-shield-check"></i> 管理员</span>
                                                <?php elseif ($is_logged_in): ?>
                                                <span class="badge text-bg-secondary"><i class="bi bi-person"></i> 用户</span>
                                                <?php else: ?>
                                                <span class="badge text-bg-light text-dark border"><i class="bi bi-person-badge"></i> 游客</span>
                                                <?php endif; ?>
                                                <?php if (!empty($comment['is_private'])): ?>
                                                <span class="badge text-bg-dark"><i class="bi bi-lock"></i> 私密</span>
                                                <?php endif; ?>
                                                <span class="badge <?= $badge['class'] ?> status-badge" data-status-badge>
                                                    <i class="bi <?= $badge['icon'] ?>"></i> <?= $badge['text'] ?>
                                                </span>
                                                <small class="text-secondary" title="<?= e($created_full) ?>">
                                                    <i class="bi bi-clock"></i> <?= e(formatCommentTime($comment['created_at'])) ?>
                                                </small>
                                            </div>

                                            <?php if (!empty($comment['parent_id'])): ?>
                                            <div class="small text-secondary mb-1">
                                                <i class="bi bi-arrow-90deg-up"></i> 回复 <strong><?= e($parent_name) ?></strong>
                                            </div>
                                            <?php endif; ?>

                                            <div class="comment-text" data-truncated="<?= e($short_text) ?>" data-full="<?= e($content_text) ?>"><?= e($short_text) ?></div>
                                            <?php if ($is_truncatable): ?>
                                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" data-toggle-expand>展开全文 <i class="bi bi-chevron-down"></i></button>
                                            <?php endif; ?>

                                            <div class="d-flex flex-wrap gap-3 align-items-center mt-2 small text-secondary">
                                                <span>
                                                    <i class="bi bi-file-text"></i>
                                                    <a href="/blog?id=<?= (int)$comment['post_id'] ?>" target="_blank" rel="noopener" class="text-decoration-none">
                                                        <?= e($comment['post_title'] ?? ('#' . $comment['post_id'] . '（文章已删除）')) ?>
                                                    </a>
                                                </span>
                                                <?php if (!empty($comment['reply_count'])): ?>
                                                <span><i class="bi bi-reply"></i> <?= (int)$comment['reply_count'] ?> 条回复</span>
                                                <?php endif; ?>
                                                <?php if (!empty($comment['device_info'])): ?>
                                                <span title="设备信息"><i class="bi bi-pc-display"></i> <?= e($comment['device_info']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($comment['ip_address'])): ?>
                                                <span><i class="bi bi-geo-alt"></i> <?= e($comment['ip_address']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!$is_logged_in && !empty($comment['email'])): ?>
                                                <span><i class="bi bi-envelope"></i> <?= e($comment['email']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!$is_logged_in && !empty($comment['website']) && preg_match('#^https?://#i', $comment['website'])): ?>
                                                <span><i class="bi bi-link-45deg"></i> <a href="<?= e($comment['website']) ?>" target="_blank" rel="noopener nofollow" class="text-decoration-none"><?= e($comment['website']) ?></a></span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="d-flex gap-2 mt-2" data-comment-actions>
                                                <?php if ($comment['status'] !== 'approved'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" data-action-btn="approve">
                                                    <i class="bi bi-check-lg"></i> 批准
                                                </button>
                                                <?php endif; ?>
                                                <?php if ($comment['status'] !== 'rejected'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-warning" data-action-btn="reject">
                                                    <i class="bi bi-x-lg"></i> 拒绝
                                                </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" data-action-btn="delete">
                                                    <i class="bi bi-trash"></i> 删除
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- 分页 -->
                        <?php if ($total_pages > 1 && !empty($comments)): ?>
                        <?php
                        // 保留现有筛选参数构造干净的分页链接
                        $query_params = [];
                        if ($status_filter !== 'all') $query_params['status'] = $status_filter;
                        if ($post_filter !== 'all') $query_params['post'] = $post_filter;
                        if ($search !== '') $query_params['q'] = $search;
                        $page_url = static fn(int $p): string => 'comments.php'
                            . (empty($query_params) ? '?' : '?' . http_build_query($query_params) . '&')
                            . 'page=' . $p;
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        ?>
                        <div class="card-footer">
                            <nav aria-label="评论分页">
                                <ul class="pagination justify-content-center mb-0">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $page <= 1 ? '#' : e($page_url($page - 1)) ?>">上一页</a>
                                    </li>
                                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= e($page_url($i)) ?>"><?= $i ?></a>
                                    </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $page >= $total_pages ? '#' : e($page_url($page + 1)) ?>">下一页</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

<script>
(function () {
    'use strict';

    var statusMeta = {
        pending:  { text: '待审核', cls: 'text-bg-warning', icon: 'bi-clock' },
        approved: { text: '已批准', cls: 'text-bg-success', icon: 'bi-check-circle' },
        rejected: { text: '已拒绝', cls: 'text-bg-danger',  icon: 'bi-x-circle' }
    };
    var busy = false;

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function showToast(message, type) {
        var toast = document.getElementById('toastMessage');
        if (!toast) return;
        var alertType = type === 'error' ? 'danger' : 'success';
        var icon = type === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill';
        toast.innerHTML = '<div class="alert alert-' + alertType + ' alert-dismissible fade show" role="alert">' +
            '<i class="bi ' + icon + '"></i> ' + message +
            '<button type="button" class="btn-close" data-toast-close></button></div>';
        toast.classList.add('show');
        window.clearTimeout(toast._timer);
        toast._timer = window.setTimeout(hideToast, 4000);
    }

    function hideToast() {
        var toast = document.getElementById('toastMessage');
        if (!toast) return;
        toast.classList.remove('show');
        window.setTimeout(function () { toast.innerHTML = ''; }, 300);
    }

    function refreshActions(row) {
        var status = row.getAttribute('data-status');
        var wrap = row.querySelector('[data-comment-actions]');
        if (!wrap) return;
        wrap.innerHTML =
            (status !== 'approved'
                ? '<button type="button" class="btn btn-sm btn-outline-success" data-action-btn="approve"><i class="bi bi-check-lg"></i> 批准</button> '
                : '') +
            (status !== 'rejected'
                ? '<button type="button" class="btn btn-sm btn-outline-warning" data-action-btn="reject"><i class="bi bi-x-lg"></i> 拒绝</button> '
                : '') +
            '<button type="button" class="btn btn-sm btn-outline-danger" data-action-btn="delete"><i class="bi bi-trash"></i> 删除</button>';
    }

    function refreshStatusBadge(row) {
        var status = row.getAttribute('data-status');
        var meta = statusMeta[status];
        var badge = row.querySelector('[data-status-badge]');
        if (meta && badge) {
            badge.className = 'badge ' + meta.cls + ' status-badge';
            badge.innerHTML = '<i class="bi ' + meta.icon + '"></i> ' + meta.text;
        }
    }

    function adjustStat(key, delta) {
        var el = document.querySelector('[data-stat-count="' + key + '"]');
        if (!el) return;
        var value = parseInt(el.textContent, 10);
        if (!isNaN(value)) el.textContent = Math.max(0, value + delta);
    }

    function removeRow(row) {
        var rows = document.querySelectorAll('[data-comment-row]');
        adjustStat(row.getAttribute('data-status'), -1);
        adjustStat('total', -1);
        row.style.transition = 'opacity .18s ease';
        row.style.opacity = '0';
        window.setTimeout(function () { row.remove(); }, 180);
        if (rows.length <= 1) {
            window.setTimeout(function () {
                var list = document.querySelector('[data-comment-rows]');
                if (list) list.remove();
                var container = document.getElementById('commentList');
                if (container && !container.querySelector('[data-empty-state]')) {
                    container.insertAdjacentHTML('afterbegin',
                        '<div class="text-center py-5" data-empty-state>' +
                        '<i class="bi bi-chat-square-dots display-5 text-secondary d-block mb-2"></i>' +
                        '<p class="text-secondary mb-0">没有符合条件的评论</p></div>');
                }
            }, 200);
        }
        var totalEl = document.querySelector('[data-comment-total]');
        if (totalEl) {
            var match = totalEl.textContent.match(/\d+/);
            if (match) totalEl.textContent = totalEl.textContent.replace(match[0], String(Math.max(0, parseInt(match[0], 10) - 1)));
        }
    }

    function submitAction(row, action) {
        if (busy) return;
        var buttons = row.querySelectorAll('[data-action-btn]');
        buttons.forEach(function (btn) { btn.disabled = true; });
        busy = true;

        var body = new URLSearchParams({
            ajax: '1',
            action: action,
            comment_id: row.getAttribute('data-comment-id'),
            csrf_token: getCsrfToken()
        });

        fetch('comments.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (!data.success) throw new Error(data.message || '操作失败');
            showToast(data.message, 'success');
            if (data.removed) {
                removeRow(row);
            } else if (data.status) {
                var prev = row.getAttribute('data-status');
                row.setAttribute('data-status', data.status);
                adjustStat(prev, -1);
                adjustStat(data.status, 1);
                refreshStatusBadge(row);
                refreshActions(row);
            }
        })
        .catch(function (error) {
            showToast(error.message, 'error');
            buttons.forEach(function (btn) { btn.disabled = false; });
        })
        .finally(function () { busy = false; });
    }

    function onListClick(event) {
        var toggle = event.target.closest('[data-toggle-expand]');
        if (toggle) {
            var textEl = toggle.previousElementSibling;
            if (!textEl || !textEl.classList.contains('comment-text')) return;
            var expanded = toggle.getAttribute('data-expanded') === '1';
            textEl.textContent = expanded ? textEl.getAttribute('data-truncated') : textEl.getAttribute('data-full');
            toggle.setAttribute('data-expanded', expanded ? '0' : '1');
            toggle.innerHTML = (expanded ? '展开全文' : '收起') + ' <i class="bi bi-chevron-' + (expanded ? 'down' : 'up') + '"></i>';
            return;
        }

        var actionBtn = event.target.closest('[data-action-btn]');
        if (actionBtn) {
            var row = actionBtn.closest('[data-comment-row]');
            var action = actionBtn.getAttribute('data-action-btn');
            if (!row || !action) return;
            if (action === 'delete' && !window.confirm('确定要删除这条评论吗？其下所有回复也会一并删除。')) return;
            submitAction(row, action);
        }
    }

    var list = document.getElementById('commentList');
    if (list) list.addEventListener('click', onListClick);

    var toast = document.getElementById('toastMessage');
    if (toast) {
        toast.addEventListener('click', function (event) {
            if (event.target.closest('[data-toast-close]')) hideToast();
        });
    }
})();
</script>
<?php require_once 'includes/footer.php'; ?>
