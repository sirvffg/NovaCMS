<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';

$db = getDB();

// Get Website Config
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// 自动创建表
$db->exec("CREATE TABLE IF NOT EXISTS `hitokoto` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `hitokoto` text NOT NULL COMMENT '内容',
    `from` varchar(255) DEFAULT NULL COMMENT '来源',
    `from_who` varchar(255) DEFAULT NULL COMMENT '作者',
    `creator` varchar(255) DEFAULT NULL COMMENT '添加者',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一言表';");

$message = '';
$error = '';

// 获取Session消息
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// 处理删除
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $db->prepare("DELETE FROM hitokoto WHERE id=?")->execute([$id]);
    $_SESSION['message'] = '一言已删除';
    header('Location: hitokoto.php');
    exit;
}

// 处理添加/编辑
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $hitokoto = $_POST['hitokoto'] ?? '';
    $from = $_POST['from'] ?? '';
    $from_who = $_POST['from_who'] ?? '';
    $creator = $_SESSION['admin_username'] ?? 'Admin'; // 记录添加者

    if (empty($hitokoto)) {
        $_SESSION['error'] = '内容不能为空';
    } else {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE hitokoto SET hitokoto=?, `from`=?, from_who=? WHERE id=?");
            $stmt->execute([$hitokoto, $from, $from_who, $id]);
            $_SESSION['message'] = '一言已更新';
        } else {
            $stmt = $db->prepare("INSERT INTO hitokoto (hitokoto, `from`, from_who, creator) VALUES (?, ?, ?, ?)");
            $stmt->execute([$hitokoto, $from, $from_who, $creator]);
            $_SESSION['message'] = '一言已添加';
        }
    }
    header('Location: hitokoto.php');
    exit;
}

// 获取列表
$list = $db->query("SELECT * FROM hitokoto ORDER BY id ASC")->fetchAll();

$page_title = '一言管理';
$extra_css = <<<'CSS'
.table-hover tbody tr:hover { background-color: rgba(0,0,0,.02); }
.card { border: none; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); }
CSS;
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-1">一言管理</h1>
                        <p class="text-muted mb-0">管理随机一言API的内容库</p>
                    </div>
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="bi bi-plus-lg"></i> 添加一言
                    </button>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-exclamation-circle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">ID</th>
                                        <th style="width: 40%;">内容</th>
                                        <th>来源</th>
                                        <th>作者</th>
                                        <th>添加者</th>
                                        <th>时间</th>
                                        <th class="text-end pe-4">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($list as $item): ?>
                                    <tr>
                                        <td class="ps-4"><?= $item['id'] ?></td>
                                        <td><?= htmlspecialchars($item['hitokoto']) ?></td>
                                        <td><?= htmlspecialchars($item['from'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($item['from_who'] ?? '-') ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($item['creator'] ?? 'Unknown') ?></span></td>
                                        <td class="text-muted small"><?= date('Y-m-d H:i', strtotime($item['created_at'])) ?></td>
                                        <td class="text-end pe-4">
                                            <div class="btn-group">
                                                <button class="btn btn-sm btn-light text-primary" 
                                                        onclick='openEditModal(<?= json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) ?>)' 
                                                        title="编辑">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <a href="?delete=<?= $item['id'] ?>" class="btn btn-sm btn-light text-danger" 
                                                   onclick="return confirm('确定要删除这条一言吗？')" title="删除">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($list)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5">
                                            <div class="text-muted">
                                                <i class="bi bi-chat-quote display-4 d-block mb-3"></i>
                                                暂无内容，快去添加吧
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

    <!-- 编辑/添加模态框 -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">添加一言</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editForm">
                        <input type="hidden" name="id" id="editId" value="0">
                        
                        <div class="mb-3">
                            <label class="form-label">内容 <span class="text-danger">*</span></label>
                            <textarea name="hitokoto" id="editHitokoto" class="form-control" rows="3" required></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">来源</label>
                                    <input type="text" name="from" id="editFrom" class="form-control" placeholder="例如：某部动画">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">作者</label>
                                    <input type="text" name="from_who" id="editFromWho" class="form-control" placeholder="例如：某某某">
                                </div>
                            </div>
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

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        const editModal = new bootstrap.Modal(document.getElementById('editModal'));

        function openAddModal() {
            document.getElementById('modalTitle').textContent = '添加一言';
            document.getElementById('editForm').reset();
            document.getElementById('editId').value = 0;
            editModal.show();
        }

        function openEditModal(item) {
            document.getElementById('modalTitle').textContent = '编辑一言';
            document.getElementById('editId').value = item.id;
            document.getElementById('editHitokoto').value = item.hitokoto;
            document.getElementById('editFrom').value = item.from || '';
            document.getElementById('editFromWho').value = item.from_who || '';
            editModal.show();
        }
    </script>
<?php require_once 'includes/footer.php'; ?>
