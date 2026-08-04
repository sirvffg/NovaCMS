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

// 获取配置信息
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 处理删除操作
if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['comment_id'])) {
    // CSRF 验证
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = '安全验证失败，请刷新页面后重试';
        header('Location: comments.php');
        exit;
    }
    $comment_id = $_POST['comment_id'];
    deleteComment($comment_id);
    $_SESSION['success'] = '评论已删除';
    header('Location: comments.php');
    exit;
}

// 处理批准/拒绝操作
if (isset($_POST['action']) && ($_POST['action'] === 'approve' || $_POST['action'] === 'reject') && isset($_POST['comment_id'])) {
    // CSRF 验证
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = '安全验证失败，请刷新页面后重试';
        header('Location: comments.php');
        exit;
    }
    $comment_id = $_POST['comment_id'];
    $status = $_POST['action'] === 'approve' ? 'approved' : 'rejected';
    $stmt = $db->prepare("UPDATE blog_comments SET status = ? WHERE id = ?");
    $stmt->execute([$status, $comment_id]);
    $_SESSION['success'] = $status === 'approved' ? '评论已批准' : '评论已拒绝';
    header('Location: comments.php');
    exit;
}

// 获取分页参数
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// 获取筛选参数
$status_filter = $_GET['status'] ?? 'all';
$post_filter = $_GET['post'] ?? 'all';

// 构建查询条件
$where_conditions = [];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "c.status = ?";
    $params[] = $status_filter;
}

if ($post_filter !== 'all' && is_numeric($post_filter)) {
    $where_conditions[] = "c.post_id = ?";
    $params[] = $post_filter;
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

// 获取评论总数
$count_sql = "
    SELECT COUNT(*) as total
    FROM blog_comments c
    $where_clause
";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total = $stmt->fetch()['total'];
$total_pages = ceil($total / $per_page);

// 获取评论列表
$sql = "
    SELECT c.*, p.title as post_title, a.username as admin_username, a.role as user_role,
           (SELECT COUNT(*) FROM blog_comments WHERE parent_id = c.id) as reply_count
    FROM blog_comments c
    LEFT JOIN blog_posts p ON c.post_id = p.id
    LEFT JOIN admins a ON c.user_id = a.id
    $where_clause
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
";
$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$comments = $stmt->fetchAll();

// 获取所有文章用于筛选
$posts = $db->query("SELECT id, title FROM blog_posts ORDER BY title ASC")->fetchAll();

// 获取评论统计
$stats = [
    'total' => $db->query("SELECT COUNT(*) as count FROM blog_comments")->fetch()['count'],
    'approved' => $db->query("SELECT COUNT(*) as count FROM blog_comments WHERE status = 'approved'")->fetch()['count'],
    'pending' => $db->query("SELECT COUNT(*) as count FROM blog_comments WHERE status = 'pending'")->fetch()['count'],
    'rejected' => $db->query("SELECT COUNT(*) as count FROM blog_comments WHERE status = 'rejected'")->fetch()['count']
];
$page_title = '评论管理';
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">评论管理</h1>
                </div>
                
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $_SESSION['success'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
                
                <!-- 统计卡片 -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <h5 class="card-title"><i class="bi bi-chat-dots"></i> 总评论数</h5>
                                <h3><?= $stats['total'] ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <h5 class="card-title"><i class="bi bi-check-circle"></i> 已批准</h5>
                                <h3><?= $stats['approved'] ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <h5 class="card-title"><i class="bi bi-clock"></i> 待审核</h5>
                                <h3><?= $stats['pending'] ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-danger">
                            <div class="card-body">
                                <h5 class="card-title"><i class="bi bi-x-circle"></i> 已拒绝</h5>
                                <h3><?= $stats['rejected'] ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 筛选器 -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">状态筛选</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>全部状态</option>
                                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>待审核</option>
                                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>已批准</option>
                                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>已拒绝</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">文章筛选</label>
                                <select name="post" class="form-select">
                                    <option value="all">全部文章</option>
                                    <?php foreach ($posts as $post): ?>
                                    <option value="<?= $post['id'] ?>" <?= $post_filter == $post['id'] ? 'selected' : '' ?>>
                                        <?= e($post['title']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary w-100">筛选</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- 评论列表 -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">评论列表</h5>
                        <span class="badge bg-secondary">共 <?= $total ?> 条评论</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($comments)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-chat-dots display-4 text-muted"></i>
                            <p class="text-muted mt-2">暂无评论</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>评论内容</th>
                                        <th>文章</th>
                                        <th>作者</th>
                                        <th>状态</th>
                                        <th>时间</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($comments as $comment): ?>
                                    <tr>
                                        <td>
                                            <div class="comment-content">
                                                <div class="text-truncate" style="max-width: 300px;" title="<?= e($comment['content']) ?>">
                                                    <?= e(mb_substr(strip_tags($comment['content']), 0, 50)) ?>
                                                    <?= mb_strlen(strip_tags($comment['content'])) > 50 ? '...' : '' ?>
                                                </div>
                                                <?php if ($comment['reply_count'] > 0): ?>
                                                <small class="text-muted">
                                                    <i class="bi bi-reply"></i> <?= $comment['reply_count'] ?> 条回复
                                                </small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="/blog.php?id=<?= $comment['post_id'] ?>" target="_blank" class="text-decoration-none">
                                                <?= e($comment['post_title']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($comment['admin_username']): ?>
                                            <?php if ($comment['user_role'] === 'admin'): ?>
                                            <span class="badge bg-danger">
                                                <i class="bi bi-shield-check"></i> <?= e($comment['admin_username']) ?> (管理员)
                                            </span>
                                            <?php elseif ($comment['user_role'] === 'user'): ?>
                                            <span class="badge bg-secondary">
                                                <i class="bi bi-person"></i> <?= e($comment['admin_username']) ?> (普通用户)
                                            </span>
                                            <?php else: ?>
                                            <span class="badge bg-info">
                                                <i class="bi bi-person-circle"></i> <?= e($comment['admin_username']) ?>
                                            </span>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <span class="text-muted">游客</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status_colors = [
                                                'pending' => 'warning',
                                                'approved' => 'success',
                                                'rejected' => 'danger'
                                            ];
                                            $status_texts = [
                                                'pending' => '待审核',
                                                'approved' => '已批准',
                                                'rejected' => '已拒绝'
                                            ];
                                            ?>
                                            <span class="badge bg-<?= $status_colors[$comment['status']] ?>">
                                                <?= $status_texts[$comment['status']] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?= date('Y-m-d H:i', strtotime($comment['created_at'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <?php if ($comment['status'] === 'pending'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?= e(generateCSRFToken()) ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                    <button type="submit" class="btn btn-success" title="批准">
                                                        <i class="bi bi-check"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?= e(generateCSRFToken()) ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                    <button type="submit" class="btn btn-warning" title="拒绝">
                                                        <i class="bi bi-x"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('确定要删除这条评论吗？')">
                                                    <input type="hidden" name="csrf_token" value="<?= e(generateCSRFToken()) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                    <button type="submit" class="btn btn-danger" title="删除">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- 分页 -->
                        <?php if ($total_pages > 1): ?>
                        <div class="card-footer">
                            <nav>
                                <ul class="pagination justify-content-center mb-0">
                                    <?php
                                    $current_url = $_SERVER['REQUEST_URI'];
                                    $url_parts = parse_url($current_url);
                                    $query_params = [];
                                    if (isset($url_parts['query'])) {
                                        parse_str($url_parts['query'], $query_params);
                                    }
                                    unset($query_params['page']);
                                    $base_url = $url_parts['path'] . '?' . http_build_query($query_params);
                                    ?>
                                    
                                    <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?= $base_url ?>&page=<?= $page - 1 ?>">上一页</a>
                                    </li>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                    ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= $base_url ?>&page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?= $base_url ?>&page=<?= $page + 1 ?>">下一页</a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js') ?>"></script>
<?php require_once 'includes/footer.php'; ?>