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

// 1. Database Migration: Create photo_albums table and add album_id to photos
try {
    // Create albums table
    $db->exec("CREATE TABLE IF NOT EXISTS photo_albums (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        cover_image VARCHAR(255),
        sort_order INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Create Default Album if none exists
    $stmt = $db->query("SELECT id FROM photo_albums LIMIT 1");
    if (!$stmt->fetch()) {
        $db->exec("INSERT INTO photo_albums (name, description, sort_order) VALUES ('默认相册', '默认存储未分类的照片', 0)");
    }
    
    // Get default album ID for new photos or migration
    $defaultAlbumId = $db->query("SELECT id FROM photo_albums ORDER BY id ASC LIMIT 1")->fetchColumn();
    if (!$defaultAlbumId) $defaultAlbumId = 1;

    // Create Photos Table (if not exists)
    $db->exec("CREATE TABLE IF NOT EXISTS photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        album_id INT DEFAULT $defaultAlbumId,
        url VARCHAR(255) NOT NULL,
        title VARCHAR(100),
        description TEXT,
        sort_order INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_album_id (album_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Migration: Add album_id to photos table if it was created before this update
    // Check if column exists
    $colCheck = $db->query("SHOW COLUMNS FROM photos LIKE 'album_id'");
    if ($colCheck->rowCount() == 0) {
        $db->exec("ALTER TABLE photos ADD COLUMN album_id INT DEFAULT $defaultAlbumId");
        $db->exec("CREATE INDEX idx_album_id ON photos(album_id)");
    }

} catch (PDOException $e) {
    // Column might already exist or other non-critical error
}

// 2. Handle Logic
$action = $_GET['action'] ?? 'list_albums'; // list_albums, view_album, add_album, edit_album, delete_album

// --- Album Actions ---

// Delete Album
if (isset($_POST['delete_album_id'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $error = '安全验证失败，请刷新页面后重试';
    } else {
    $id = (int)$_POST['delete_album_id'];
    
    // 1. Get Album Info (for cover image)
    $stmt = $db->prepare("SELECT cover_image FROM photo_albums WHERE id=?");
    $stmt->execute([$id]);
    $album = $stmt->fetch();

    // 2. Get All Photos in Album
    $stmtPhotos = $db->prepare("SELECT url FROM photos WHERE album_id=?");
    $stmtPhotos->execute([$id]);
    $photos = $stmtPhotos->fetchAll();

    // 3. Delete Photo Files
    foreach ($photos as $p) {
        if (strpos($p['url'], '/uploads/gallery/') === 0) {
            $filePath = '..' . $p['url'];
            if (file_exists($filePath)) unlink($filePath);
        }
    }

    // 4. Delete Cover Image
    if ($album && !empty($album['cover_image']) && strpos($album['cover_image'], '/uploads/gallery/covers/') === 0) {
        $coverPath = '..' . $album['cover_image'];
        if (file_exists($coverPath)) unlink($coverPath);
    }

    $db->prepare("DELETE FROM photos WHERE album_id=?")->execute([$id]);
    $db->prepare("DELETE FROM photo_albums WHERE id=?")->execute([$id]);
    $message = '相册及其中照片文件已彻底删除';
    header('Location: gallery.php'); // PRG
    exit;
    }
}

// Add/Edit Album
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['album_form'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $error = '安全验证失败，请刷新页面后重试';
    } else {
    $album_id = isset($_POST['album_id']) ? (int)$_POST['album_id'] : 0;
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $cover_image = '';

    // Handle Cover Image Upload
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/gallery/covers/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        $fileExtension = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($_FILES['cover_image']['tmp_name']);
        
        if ($_FILES['cover_image']['size'] > $maxSize) {
            $error = '封面图大小不能超过5MB';
        } elseif (!in_array($fileExtension, $allowedExtensions) || !in_array($mimeType, $allowedMimes)) {
            $error = '只允许上传 JPG、PNG、GIF、WebP 格式的图片';
        } else {
            $newFileName = 'cover_' . date('Ymd_His_') . uniqid() . '.' . $fileExtension;
            $targetFile = $uploadDir . $newFileName;
            if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $targetFile)) {
                $cover_image = '/uploads/gallery/covers/' . $newFileName;
            }
        }
    }

    if (empty($name)) {
        $error = '相册名称不能为空';
    } else {
        if ($album_id > 0) {
            // Edit
            $sql = "UPDATE photo_albums SET name=?, description=?, sort_order=?";
            $params = [$name, $description, $sort_order];
            if ($cover_image) {
                $sql .= ", cover_image=?";
                $params[] = $cover_image;
            }
            $sql .= " WHERE id=?";
            $params[] = $album_id;
            $db->prepare($sql)->execute($params);
            $message = '相册已更新';
        } else {
            // Add
            $stmt = $db->prepare("INSERT INTO photo_albums (name, description, sort_order, cover_image) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $description, $sort_order, $cover_image]);
            $message = '相册已创建';
        }
        header('Location: gallery.php');
        exit;
    }
    }
}

// --- Photo Actions (Inside an Album) ---

// Delete Photo
if (isset($_POST['delete_photo_id'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $error = '安全验证失败，请刷新页面后重试';
    } else {
    $id = (int)$_POST['delete_photo_id'];
    $redirect_album = (int)$_POST['redirect_album_id'];
    
    $stmt = $db->prepare("SELECT url FROM photos WHERE id=?");
    $stmt->execute([$id]);
    $photo = $stmt->fetch();
    
    if ($photo) {
        $db->prepare("DELETE FROM photos WHERE id=?")->execute([$id]);
        if (strpos($photo['url'], '/uploads/gallery/') === 0) {
            $filePath = '..' . $photo['url'];
            if (file_exists($filePath)) unlink($filePath);
        }
        $message = '照片已删除';
    }
    header("Location: gallery.php?action=view_album&id=$redirect_album");
    exit;
    }
}

// Upload Photos to Album
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photos'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $error = '安全验证失败，请刷新页面后重试';
    } else {
    $album_id = (int)$_POST['album_id'];
    
    // Multiple Upload
    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        $uploadDir = '../uploads/gallery/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $files = $_FILES['images'];
        $successCount = 0;
        
        $defaultTitle = trim($_POST['default_title'] ?? '');
        $defaultDesc = trim($_POST['default_description'] ?? '');
        
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 10 * 1024 * 1024; // 10MB
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $fileExtension = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                $mimeType = $finfo->file($files['tmp_name'][$i]);
                
                if ($files['size'][$i] > $maxSize) {
                    $uploadErrors[] = htmlspecialchars($files['name'][$i]) . '：大小超过10MB';
                    continue;
                }
                if (!in_array($fileExtension, $allowedExtensions) || !in_array($mimeType, $allowedMimes)) {
                    $uploadErrors[] = htmlspecialchars($files['name'][$i]) . '：格式不支持，只允许 JPG、PNG、GIF、WebP';
                    continue;
                }
                
                $newFileName = date('Ymd_His_') . uniqid() . '.' . $fileExtension;
                $targetFile = $uploadDir . $newFileName;
                
                if (move_uploaded_file($files['tmp_name'][$i], $targetFile)) {
                    $url = '/uploads/gallery/' . $newFileName;
                    $stmt = $db->prepare("INSERT INTO photos (url, album_id, title, description) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$url, $album_id, $defaultTitle, $defaultDesc]);
                    $successCount++;
                    
                    // Update album cover if it has none
                    $album = $db->query("SELECT cover_image FROM photo_albums WHERE id=$album_id")->fetch();
                    if (empty($album['cover_image'])) {
                         $db->prepare("UPDATE photo_albums SET cover_image=? WHERE id=?")->execute([$url, $album_id]);
                    }
                }
            }
        }
        $message = "成功上传 {$successCount} 张照片";
        if (!empty($uploadErrors)) {
            $message .= '。以下文件上传失败：' . implode('；', $uploadErrors);
        }
    } elseif (!empty($_POST['url_input'])) {
         $url = trim($_POST['url_input']);
         $title = trim($_POST['default_title'] ?? '');
         $desc = trim($_POST['default_description'] ?? '');
         $db->prepare("INSERT INTO photos (url, album_id, title, description) VALUES (?, ?, ?, ?)")->execute([$url, $album_id, $title, $desc]);
         $message = '照片链接已添加';
    }
    
    header("Location: gallery.php?action=view_album&id=$album_id");
    exit;
    }
}

// --- View Logic ---

$currentAlbum = null;
if ($action === 'view_album' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT * FROM photo_albums WHERE id=?");
    $stmt->execute([$id]);
    $currentAlbum = $stmt->fetch();
    
    if ($currentAlbum) {
        $photos = $db->prepare("SELECT * FROM photos WHERE album_id=? ORDER BY created_at DESC");
        $photos->execute([$id]);
        $photos = $photos->fetchAll();
    } else {
        $action = 'list_albums'; // Fallback
    }
}

if ($action === 'list_albums') {
    // Get albums with photo count
    try {
        $albums = $db->query("
            SELECT a.*, COUNT(p.id) as photo_count 
            FROM photo_albums a 
            LEFT JOIN photos p ON a.id = p.album_id 
            GROUP BY a.id 
            ORDER BY a.sort_order ASC, a.created_at DESC
        ")->fetchAll();
    } catch (PDOException $e) {
        $albums = [];
    }
}
$page_title = '相册管理';
$extra_css = <<<'CSS'
.album-card {
    transition: transform 0.2s, box-shadow 0.2s;
    cursor: pointer;
    height: 100%;
}
.album-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
}
.album-cover {
    height: 200px;
    object-fit: cover;
    width: 100%;
    background-color: #eee;
}
.photo-preview {
    width: 100%;
    height: 150px;
    object-fit: cover;
    border-radius: 4px;
}
.photo-card {
    position: relative;
    margin-bottom: 20px;
}
.photo-actions {
    position: absolute;
    top: 5px;
    right: 5px;
    display: none;
}
.photo-card:hover .photo-actions {
    display: block;
}
CSS;
require_once 'includes/header.php'; ?>

                
                <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= e($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- View: Album List -->
                <?php if ($action === 'list_albums'): ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h2 mb-1">相册列表</h1>
                            <p class="text-muted mb-0">创建和管理您的相册集</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#albumModal">
                            <i class="bi bi-plus-lg"></i> 新建相册
                        </button>
                    </div>

                    <div class="row g-4">
                        <?php foreach ($albums as $album): ?>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="card border-0 shadow-sm album-card" onclick="location.href='?action=view_album&id=<?= $album['id'] ?>'">
                                <img src="<?= !empty($album['cover_image']) ? e($album['cover_image']) : '/assets/images/default-album.png' ?>" 
                                     class="card-img-top album-cover" 
                                     onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBwcmVzZXJ2ZUFzcGVjdFJhdGlvPSJub25lIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZWVlIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtc2l6ZT0iMjAiIGR5PSIuM2VtIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjYWFhIj7bnOWGjzwvdGV4dD48L3N2Zz4='">
                                <div class="card-body">
                                    <h5 class="card-title text-truncate"><?= e($album['name']) ?></h5>
                                    <p class="card-text text-muted small mb-2">
                                        <?= $album['photo_count'] ?> 张照片
                                    </p>
                                    <?php if ($album['description']): ?>
                                    <p class="card-text text-muted small text-truncate"><?= e($album['description']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer bg-white border-top-0 d-flex justify-content-between align-items-center">
                                    <small class="text-muted"><?= date('Y-m-d', strtotime($album['created_at'])) ?></small>
                                    <div class="btn-group" onclick="event.stopPropagation()">
                                        <button class="btn btn-sm btn-outline-secondary" onclick="editAlbum(<?= htmlspecialchars(json_encode($album)) ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteAlbum(<?= $album['id'] ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- View: Single Album (Photos) -->
                <?php if ($action === 'view_album' && $currentAlbum): ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <nav aria-label="breadcrumb">
                                <ol class="breadcrumb mb-1">
                                    <li class="breadcrumb-item"><a href="gallery.php">相册列表</a></li>
                                    <li class="breadcrumb-item active" aria-current="page"><?= e($currentAlbum['name']) ?></li>
                                </ol>
                            </nav>
                            <h1 class="h2 mb-0"><?= e($currentAlbum['name']) ?></h1>
                        </div>
                        <div>
                            <button class="btn btn-outline-primary me-2" onclick="editAlbum(<?= htmlspecialchars(json_encode($currentAlbum)) ?>)">
                                <i class="bi bi-gear"></i> 设置
                            </button>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                                <i class="bi bi-cloud-upload"></i> 上传照片
                            </button>
                        </div>
                    </div>

                    <div class="row g-3">
                        <?php foreach ($photos as $photo): ?>
                        <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                            <div class="photo-card">
                                <a href="<?= e($photo['url']) ?>" target="_blank">
                                    <img src="<?= e($photo['url']) ?>" class="photo-preview shadow-sm" title="<?= e($photo['description'] ?? '') ?>">
                                </a>
                                <?php if (!empty($photo['title'])): ?>
                                <div class="text-truncate small mt-1 text-center text-muted"><?= e($photo['title']) ?></div>
                                <?php endif; ?>
                                <div class="photo-actions">
                                    <button class="btn btn-sm btn-danger shadow-sm" onclick="deletePhoto(<?= $photo['id'] ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($photos)): ?>
                        <div class="col-12 text-center py-5">
                            <div class="text-muted">
                                <i class="bi bi-images display-4 d-block mb-3"></i>
                                此相册暂无照片
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>


    <!-- Album Modal (Add/Edit) -->
    <div class="modal fade" id="albumModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="albumModalTitle">新建相册</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="album_form" value="1">
                        <input type="hidden" name="album_id" id="albumId">
                        
                        <div class="mb-3">
                            <label class="form-label">相册名称 <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="albumName" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">描述</label>
                            <textarea name="description" id="albumDesc" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">封面图</label>
                            <input type="file" name="cover_image" class="form-control" accept="image/*">
                            <div class="form-text">若不上传，将自动使用相册内的第一张图片作为封面</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">排序</label>
                            <input type="number" name="sort_order" id="albumSort" class="form-control" value="0">
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

    <!-- Upload Photo Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">上传照片</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" enctype="multipart/form-data" id="uploadPhotoForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="upload_photos" value="1">
                        <input type="hidden" name="album_id" value="<?= $currentAlbum['id'] ?? 0 ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">选择图片 (可多选)</label>
                            <input type="file" name="images[]" class="form-control" accept="image/*" multiple>
                        </div>
                        
                        <div class="text-center my-2">- 或 -</div>
                        
                        <div class="mb-3">
                            <label class="form-label">网络图片链接</label>
                            <input type="url" name="url_input" class="form-control" placeholder="http://...">
                        </div>

                        <hr>
                        
                        <div class="mb-3">
                            <label class="form-label">标题 (选填)</label>
                            <input type="text" name="default_title" class="form-control" placeholder="为照片添加标题">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">描述 (选填)</label>
                            <textarea name="default_description" class="form-control" rows="2" placeholder="为照片添加详细描述"></textarea>
                        </div>

                        <!-- Progress Bar -->
                        <div id="uploadProgress" class="mt-3" style="display:none;">
                            <div class="progress">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
                            </div>
                            <div class="text-center mt-2 small text-muted" id="uploadStatus">正在准备...</div>
                        </div>
                        
                        <div class="text-end mt-3">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">开始上传</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden Forms -->
    <form id="deleteAlbumForm" method="POST" style="display:none">
        <?= csrfField() ?>
        <input type="hidden" name="delete_album_id" id="deleteAlbumId">
    </form>
    <form id="deletePhotoForm" method="POST" style="display:none">
        <?= csrfField() ?>
        <input type="hidden" name="delete_photo_id" id="deletePhotoId">
        <input type="hidden" name="redirect_album_id" value="<?= $currentAlbum['id'] ?? 0 ?>">
    </form>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        const albumModal = new bootstrap.Modal(document.getElementById('albumModal'));
        
        function editAlbum(album) {
            document.getElementById('albumModalTitle').textContent = '编辑相册';
            document.getElementById('albumId').value = album.id;
            document.getElementById('albumName').value = album.name;
            document.getElementById('albumDesc').value = album.description;
            document.getElementById('albumSort').value = album.sort_order;
            albumModal.show();
        }

        // Reset modal on close if it was edit
        document.getElementById('albumModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('albumModalTitle').textContent = '新建相册';
            document.getElementById('albumId').value = '';
            document.forms[0].reset();
        });

        function deleteAlbum(id) {
            if(confirm('确定要删除整个相册吗？相册内的所有照片也会被删除！')) {
                document.getElementById('deleteAlbumId').value = id;
                document.getElementById('deleteAlbumForm').submit();
            }
        }

        function deletePhoto(id) {
            if(confirm('确定要删除这张照片吗？')) {
                document.getElementById('deletePhotoId').value = id;
                document.getElementById('deletePhotoForm').submit();
            }
        }

        // Upload Progress Handling
        document.getElementById('uploadPhotoForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            var progressBar = document.querySelector('#uploadProgress .progress-bar');
            var progressDiv = document.getElementById('uploadProgress');
            var statusText = document.getElementById('uploadStatus');
            var submitBtn = this.querySelector('button[type="submit"]');
            
            // Check if files are selected or URL is entered
            var hasFiles = formData.get('images[]') && formData.get('images[]').size > 0;
            var hasUrl = formData.get('url_input') && formData.get('url_input').trim() !== '';

            if (!hasFiles && !hasUrl) {
                alert('请选择图片或输入链接');
                return;
            }

            progressDiv.style.display = 'block';
            submitBtn.disabled = true;
            
            var xhr = new XMLHttpRequest();
            
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    var percent = Math.round((e.loaded / e.total) * 100);
                    progressBar.style.width = percent + '%';
                    progressBar.textContent = percent + '%';
                    
                    if (percent < 100) {
                        statusText.textContent = '正在上传... ' + percent + '%';
                    } else {
                        statusText.textContent = '上传完成，正在处理图片压缩...';
                        progressBar.classList.add('bg-info'); // Change color to indicate processing
                    }
                }
            });
            
            xhr.addEventListener('load', function() {
                if (xhr.status === 200) {
                    progressBar.classList.remove('progress-bar-animated');
                    progressBar.classList.remove('bg-info');
                    progressBar.classList.add('bg-success');
                    statusText.textContent = '处理完成，即将刷新...';
                    setTimeout(function() {
                        window.location.reload();
                    }, 500);
                } else {
                    statusText.textContent = '上传失败，服务器返回错误';
                    progressBar.classList.add('bg-danger');
                    submitBtn.disabled = false;
                }
            });
            
            xhr.addEventListener('error', function() {
                statusText.textContent = '网络错误，上传失败';
                progressBar.classList.add('bg-danger');
                submitBtn.disabled = false;
            });
            
            xhr.open('POST', window.location.href);
            xhr.send(formData);
        });
    </script>
<?php require_once 'includes/footer.php'; ?>
