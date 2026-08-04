<?php
session_start();

// 如果未登录，重定向到登录页
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';

$db = getDB();

// 获取配置信息
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 处理删除操作
if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $stmt = $db->prepare("DELETE FROM guestbook WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['success'] = '留言已删除';
    header('Location: guestbook.php');
    exit;
}

// 处理回复操作
if (isset($_POST['action']) && $_POST['action'] === 'reply' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $reply_content = trim($_POST['reply_content']);
    $stmt = $db->prepare("UPDATE guestbook SET reply_content = ?, reply_time = NOW() WHERE id = ?");
    $stmt->execute([$reply_content, $id]);
    $_SESSION['success'] = '回复已提交';
    header('Location: guestbook.php');
    exit;
}

// 获取分页参数
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// 获取留言总数
$total = $db->query("SELECT COUNT(*) FROM guestbook")->fetchColumn();
$total_pages = ceil($total / $per_page);

// 获取留言列表
$sql = "SELECT * FROM guestbook ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$stmt->execute([$per_page, $offset]);
$messages = $stmt->fetchAll();

$page_title = '留言管理';
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">留言管理</h1>
                </div>
                
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $_SESSION['success'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">留言列表</h5>
                        <span class="badge bg-secondary">共 <?= $total ?> 条留言</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($messages)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-chat-text display-4 text-muted"></i>
                            <p class="text-muted mt-2">暂无留言</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 20%;">留言者</th>
                                        <th style="width: 35%;">内容</th>
                                        <th style="width: 25%;">回复</th>
                                        <th style="width: 10%;">时间</th>
                                        <th style="width: 10%;">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($messages as $msg): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= e($msg['nickname']) ?></div>
                                            <?php if (!empty($msg['email'])): ?>
                                            <div class="small text-muted"><i class="bi bi-envelope"></i> <?= e($msg['email']) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($msg['website'])): ?>
                                            <div class="small text-muted">
                                                <i class="bi bi-link-45deg"></i> 
                                                <a href="<?= e($msg['website']) ?>" target="_blank" class="text-muted text-decoration-none">
                                                    <?= e(mb_substr($msg['website'], 0, 20)) ?><?= mb_strlen($msg['website']) > 20 ? '...' : '' ?>
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                            <div class="small text-muted"><i class="bi bi-geo-alt"></i> <?= e($msg['ip_address']) ?></div>
                                        </td>
                                        <td>
                                            <div class="text-wrap" style="white-space: pre-wrap;"><?= e($msg['content']) ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($msg['reply_content'])): ?>
                                            <div class="bg-light p-2 rounded small">
                                                <strong>回复：</strong><?= nl2br(e($msg['reply_content'])) ?>
                                                <div class="text-end text-muted mt-1" style="font-size: 0.75rem;">
                                                    <?= date('Y-m-d H:i', strtotime($msg['reply_time'])) ?>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <span class="text-muted fst-italic">暂无回复</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?= date('Y-m-d H:i', strtotime($msg['created_at'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#replyModal<?= $msg['id'] ?>" title="回复">
                                                    <i class="bi bi-reply"></i>
                                                </button>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('确定要删除这条留言吗？')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $msg['id'] ?>">
                                                    <button type="submit" class="btn btn-danger" title="删除">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>

                                            <!-- 回复模态框 -->
                                            <div class="modal fade" id="replyModal<?= $msg['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="POST">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">回复 <?= e($msg['nickname']) ?></h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <input type="hidden" name="action" value="reply">
                                                                <input type="hidden" name="id" value="<?= $msg['id'] ?>">
                                                                <div class="mb-3">
                                                                    <label class="form-label">原留言：</label>
                                                                    <div class="form-control bg-light" style="white-space: pre-wrap;"><?= e($msg['content']) ?></div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label">回复内容：</label>
                                                                    <textarea class="form-control" name="reply_content" rows="5" required><?= e($msg['reply_content']) ?></textarea>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                                                <button type="submit" class="btn btn-primary">提交回复</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
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
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js') ?>"></script>
<?php require_once 'includes/footer.php'; ?>
