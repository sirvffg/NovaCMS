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
$message = '';
$error = '';

// Check and create table if not exists
try {
    $db->query("SELECT 1 FROM website_logs LIMIT 1");
} catch (PDOException $e) {
    // Table doesn't exist, create it
    $sql = "CREATE TABLE IF NOT EXISTS `website_logs` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `version` varchar(50) NOT NULL,
      `date` date NOT NULL,
      `title` varchar(255) NOT NULL,
      `content` text NOT NULL,
      `type` varchar(20) NOT NULL DEFAULT 'update' COMMENT 'release, update, fix',
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $db->exec($sql);
    
    // Check if empty and insert initial data
    $count = $db->query("SELECT COUNT(*) FROM website_logs")->fetchColumn();
    if ($count == 0) {
        $logs = [
            [
                'date' => '2024-05-20',
                'version' => 'v1.0.0',
                'title' => '网站正式上线',
                'content' => '经过一段时间的开发和测试，个人博客系统正式上线运行。包含文章发布、评论、友链申请等基础功能。',
                'type' => 'release'
            ],
            [
                'date' => '2024-06-01',
                'version' => 'v1.1.0',
                'title' => '新增RSS订阅功能',
                'content' => '为了方便读者订阅，新增了RSS 2.0订阅源功能。',
                'type' => 'update'
            ]
        ];
        $insert = $db->prepare("INSERT INTO website_logs (version, date, title, content, type) VALUES (:version, :date, :title, :content, :type)");
        foreach ($logs as $log) {
            $insert->execute([
                ':version' => $log['version'],
                ':date' => $log['date'],
                ':title' => $log['title'],
                ':content' => $log['content'],
                ':type' => $log['type']
            ]);
        }
    }
}

// Handle Delete
if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    $db->prepare("DELETE FROM website_logs WHERE id=?")->execute([$id]);
    // PRG模式
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_id'])) {
    $id = $_POST['id'] ?? 0;
    $version = $_POST['version'] ?? '';
    $date = $_POST['date'] ?? date('Y-m-d');
    $title = $_POST['title'] ?? '';
    $content = $_POST['content'] ?? '';
    $type = $_POST['type'] ?? 'update';

    if (empty($version) || empty($title)) {
        $error = '版本号和标题不能为空';
    } else {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE website_logs SET version=?, date=?, title=?, content=?, type=? WHERE id=?");
            $stmt->execute([$version, $date, $title, $content, $type, $id]);
        } else {
            $stmt = $db->prepare("INSERT INTO website_logs (version, date, title, content, type) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$version, $date, $title, $content, $type]);
        }
        // PRG模式
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Get All Logs
$logs = $db->query("SELECT * FROM website_logs ORDER BY date DESC, id DESC")->fetchAll();
$page_title = '建站日志管理';
$extra_css = <<<'CSS'
.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,.02);
}
.card {
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}
.type-badge {
    width: 80px;
    display: inline-block;
    text-align: center;
}
CSS;
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-1">建站日志管理</h1>
                        <p class="text-muted mb-0">记录网站的更新与维护历史</p>
                    </div>
                    <button class="btn btn-primary" onclick="openLogModal()">
                        <i class="bi bi-plus-lg"></i> 添加日志
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

                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">版本/日期</th>
                                        <th>类型</th>
                                        <th>标题/内容</th>
                                        <th class="text-end pe-4">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold"><?= e($log['version']) ?></div>
                                            <div class="text-muted small"><?= e($log['date']) ?></div>
                                        </td>
                                        <td>
                                            <?php if ($log['type'] === 'release'): ?>
                                            <span class="badge bg-success type-badge">正式版</span>
                                            <?php elseif ($log['type'] === 'fix'): ?>
                                            <span class="badge bg-danger type-badge">修复</span>
                                            <?php else: ?>
                                            <span class="badge bg-warning text-dark type-badge">更新</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?= e($log['title']) ?></div>
                                            <div class="text-muted small text-truncate" style="max-width: 400px;"><?= e($log['content']) ?></div>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-light text-primary" title="编辑" 
                                                    onclick='openLogModal(<?= json_encode($log, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-light text-danger" 
                                                   onclick="deleteLog(<?= $log['id'] ?>)" title="删除">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-5">
                                            <div class="text-muted">
                                                <i class="bi bi-journal-text display-4 d-block mb-3"></i>
                                                暂无日志记录
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

    <!-- Modal -->
    <div class="modal fade" id="logModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="logModalLabel">添加新日志</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="logForm">
                        <input type="hidden" name="id" id="logId" value="0">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">版本号 <span class="text-danger">*</span></label>
                                    <input type="text" name="version" id="logVersion" class="form-control" value="v1.0.0" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">日期 <span class="text-danger">*</span></label>
                                    <input type="date" name="date" id="logDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">更新类型</label>
                            <select name="type" id="logType" class="form-select">
                                <option value="update">更新 (Update)</option>
                                <option value="release">发布 (Release)</option>
                                <option value="fix">修复 (Fix)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">标题 <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="logTitle" class="form-control" value="" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">内容详情</label>
                            <textarea name="content" id="logContent" class="form-control" rows="5" required></textarea>
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
    
    <!-- 删除表单 -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="delete_id" id="deleteId">
    </form>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        const logModal = new bootstrap.Modal(document.getElementById('logModal'));
        
        function openLogModal(log = null) {
            if (log) {
                // 编辑模式
                document.getElementById('logModalLabel').textContent = '编辑日志';
                document.getElementById('logId').value = log.id;
                document.getElementById('logVersion').value = log.version;
                document.getElementById('logDate').value = log.date;
                document.getElementById('logType').value = log.type;
                document.getElementById('logTitle').value = log.title;
                document.getElementById('logContent').value = log.content;
            } else {
                // 添加模式
                document.getElementById('logModalLabel').textContent = '添加新日志';
                document.getElementById('logForm').reset();
                document.getElementById('logId').value = 0;
                document.getElementById('logVersion').value = 'v1.0.0';
                document.getElementById('logDate').value = new Date().toISOString().split('T')[0];
                document.getElementById('logType').value = 'update';
            }
            logModal.show();
        }

        function deleteLog(id) {
            if (confirm('确定要删除这条日志吗？')) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }
    </script>
<?php require_once 'includes/footer.php'; ?>
