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

// 处理删除
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $db->prepare("DELETE FROM server_monitors WHERE id=?")->execute([$id]);
    $message = '监控项已删除';
    header('Location: monitors.php');
    exit;
}

// 处理添加/编辑
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $name = $_POST['name'] ?? '';
    $url = $_POST['url'] ?? '';
    $location = $_POST['location'] ?? 'CN';
    $type = $_POST['type'] ?? 'Web';
    $sort_order = (int)($_POST['sort_order'] ?? 0);

    if (empty($name) || empty($url)) {
        $error = '名称和URL不能为空';
    } else {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE server_monitors SET name=?, url=?, location=?, type=?, sort_order=? WHERE id=?");
            $stmt->execute([$name, $url, $location, $type, $sort_order, $id]);
        } else {
            $stmt = $db->prepare("INSERT INTO server_monitors (name, url, location, type, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $url, $location, $type, $sort_order]);
        }
        // PRG模式
        header('Location: monitors.php');
        exit;
    }
}

// 获取所有监控项
$monitors = $db->query("SELECT * FROM server_monitors ORDER BY sort_order ASC, id ASC")->fetchAll();

// 获取编辑项
$editMonitor = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editMonitor = $db->prepare("SELECT * FROM server_monitors WHERE id=?");
    $editMonitor->execute([$editId]);
    $editMonitor = $editMonitor->fetch();
}
$page_title = '监控管理';
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-1">站点监控管理</h1>
                        <p class="text-muted mb-0">管理前台监控页面的站点列表</p>
                    </div>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#monitorModal">
                        <i class="bi bi-plus-lg"></i> 添加监控
                    </button>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i> <?= e($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle-fill me-2"></i> <?= e($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">名称</th>
                                        <th>URL</th>
                                        <th>地区</th>
                                        <th>类型</th>
                                        <th>排序</th>
                                        <th class="text-end pe-4">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monitors as $monitor): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold"><?= e($monitor['name']) ?></td>
                                        <td>
                                            <a href="<?= e($monitor['url']) ?>" target="_blank" class="text-decoration-none text-muted">
                                                <?= e($monitor['url']) ?> <i class="bi bi-box-arrow-up-right small"></i>
                                            </a>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= e($monitor['location']) ?></span></td>
                                        <td><span class="badge bg-light text-dark border"><?= e($monitor['type']) ?></span></td>
                                        <td><span class="badge bg-secondary rounded-pill"><?= $monitor['sort_order'] ?></span></td>
                                        <td class="text-end pe-4">
                                            <div class="btn-group">
                                                <a href="?edit=<?= $monitor['id'] ?>" class="btn btn-sm btn-light text-primary" title="编辑">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="?delete=<?= $monitor['id'] ?>" class="btn btn-sm btn-light text-danger" onclick="return confirm('确定删除？')" title="删除">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($monitors)): ?>
                                    <tr><td colspan="6" class="text-center py-5 text-muted">暂无监控项</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

    <!-- Modal -->
    <div class="modal fade" id="monitorModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= $editMonitor ? '编辑监控' : '添加监控' ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="id" value="<?= $editMonitor ? $editMonitor['id'] : 0 ?>">
                        <div class="mb-3">
                            <label class="form-label">名称</label>
                            <input type="text" name="name" class="form-control" value="<?= $editMonitor ? e($editMonitor['name']) : '' ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">URL</label>
                            <input type="url" name="url" class="form-control" value="<?= $editMonitor ? e($editMonitor['url']) : '' ?>" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">地区代码 (CN, US, HK)</label>
                                <input type="text" name="location" class="form-control" value="<?= $editMonitor ? e($editMonitor['location']) : 'CN' ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">类型 (Web, API)</label>
                                <input type="text" name="type" class="form-control" value="<?= $editMonitor ? e($editMonitor['type']) : 'Web' ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">排序</label>
                            <input type="number" name="sort_order" class="form-control" value="<?= $editMonitor ? $editMonitor['sort_order'] : 0 ?>">
                        </div>
                        <div class="text-end">
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
        <?php if ($editMonitor): ?>
        new bootstrap.Modal(document.getElementById('monitorModal')).show();
        <?php endif; ?>
    </script>
<?php require_once 'includes/footer.php'; ?>