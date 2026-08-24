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
$error = '';

// Handle Delete
if (isset($_POST['delete_id'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $error = '安全验证失败，请刷新页面后重试';
    } else {
        $id = (int)$_POST['delete_id'];
        $db->prepare("DELETE FROM instant WHERE id=?")->execute([$id]);
        resetInstantIds($db);
        // PRG模式：重定向避免重复提交
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// 重置ID函数（最新的ID=最大）
function resetInstantIds($db) {
    try {
        $db->exec("SET @row := 0");
        $db->exec("UPDATE instant SET id = (@row := @row + 1) ORDER BY created_at ASC");
        $db->exec("ALTER TABLE instant AUTO_INCREMENT = 1");
    } catch (Exception $e) {
        // 忽略错误
    }
}

// Handle Add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_id'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $error = '安全验证失败，请刷新页面后重试';
    } else {
    $content = trim($_POST['content'] ?? '');
    $image_path = '';

    if (empty($content)) {
        $error = '内容不能为空';
    } else {
        // Handle Image Upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/instant/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            $fileExtension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($_FILES['image']['tmp_name']);
            
            if ($_FILES['image']['size'] > $maxSize) {
                $error = '图片大小不能超过5MB';
            } elseif (!in_array($fileExtension, $allowedExtensions) || !in_array($mimeType, $allowedMimes)) {
                $error = '只允许上传 JPG、PNG、GIF、WebP 格式的图片';
            } else {
                $newFileName = uniqid() . '.' . $fileExtension;
                $targetFile = $uploadDir . $newFileName;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                    $image_path = '/uploads/instant/' . $newFileName;
                } else {
                    $error = '图片上传失败';
                }
            }
        }

        if (empty($error)) {
            $stmt = $db->prepare("INSERT INTO instant (content, image_path) VALUES (?, ?)");
            $stmt->execute([$content, $image_path]);
            resetInstantIds($db);
            // PRG模式：重定向避免重复提交
            header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
        }
    }
}

// Get All Instant
$instants = $db->query("SELECT * FROM instant ORDER BY created_at DESC")->fetchAll();
$page_title = '片刻管理';
$extra_css = <<<'CSS'
.instant-img-preview {
    max-width: 100px;
    max-height: 100px;
    object-fit: cover;
    border-radius: 5px;
}
CSS;
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-1">片刻管理</h1>
                        <p class="text-muted mb-0">发布和管理你的日常动态</p>
                    </div>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-plus-lg"></i> 发布片刻
                    </button>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
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
                                        <th class="ps-4">ID</th>
                                        <th>内容</th>
                                        <th>图片</th>
                                        <th>发布时间</th>
                                        <th class="text-end pe-4">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($instants as $item): ?>
                                    <tr>
                                        <td class="ps-4"><?= $item['id'] ?></td>
                                        <td style="max-width: 400px;">
                                            <div class="text-truncate" style="white-space: pre-wrap; max-height: 3em; overflow: hidden;"><?= e($item['content']) ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($item['image_path'])): ?>
                                            <a href="<?= e($item['image_path']) ?>" target="_blank">
                                                <img src="<?= e($item['image_path']) ?>" class="instant-img-preview" alt="Image">
                                            </a>
                                            <?php else: ?>
                                            <span class="text-muted small">无图片</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted small"><?= $item['created_at'] ?></td>
                                        <td class="text-end pe-4">
                                            <button type="button" class="btn btn-sm btn-light text-danger" 
                                               onclick="deleteInstant(<?= $item['id'] ?>)" title="删除">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($instants)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5">
                                            <div class="text-muted">
                                                <i class="bi bi-chat-square-dots display-4 d-block mb-3"></i>
                                                暂无片刻
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

    <!-- Add Modal -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">发布新片刻</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addForm" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <div class="mb-3">
                            <label class="form-label">内容 <span class="text-danger">*</span></label>
                            <textarea name="content" class="form-control" rows="5" required placeholder="分享你的新鲜事..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">配图 (可选)</label>
                            <input type="file" name="image" class="form-control" accept="image/*">
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">发布</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Form -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="delete_id" id="deleteId">
        <?= csrfField() ?>
    </form>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        function deleteInstant(id) {
            if (confirm('确定要删除这条片刻吗？')) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }
    </script>
<?php require_once 'includes/footer.php'; ?>
