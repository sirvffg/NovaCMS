<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
recordVisit($_SERVER['REQUEST_URI']);

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$error = '';

// 检查是否为管理员
$isAdmin = isset($_SESSION['admin_id']);

// 处理发布说说
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_shuoshuo'])) {
    $content = trim($_POST['content'] ?? '');
    
    if (empty($content)) {
        $error = '内容不能为空';
    } else {
        $image_path = '';
        
        // 处理图片上传
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/shuoshuo/';
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
                    $image_path = '/uploads/shuoshuo/' . $newFileName;
                }
            }
        }
        
        $stmt = $db->prepare("INSERT INTO shuoshuo (content, image_path) VALUES (?, ?)");
        $stmt->execute([$content, $image_path]);
        
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// 获取说说列表
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$totalShuoshuo = $db->query("SELECT COUNT(*) FROM shuoshuo")->fetchColumn();
$totalPages = ceil($totalShuoshuo / $perPage);

$stmt = $db->prepare("SELECT * FROM shuoshuo ORDER BY created_at DESC LIMIT :offset, :perPage");
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
$stmt->execute();
$shuoshuos = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>说说 - <?= e($config['website_name']) ?></title>
    <meta name="description" content="<?= e($config['website_name']) ?> 的说说页面，记录生活中的点滴碎片">
    <meta property="og:title" content="说说 - <?= e($config['website_name']) ?>">
    <meta property="og:description" content="<?= e($config['website_name']) ?> 的说说页面，记录生活中的点滴碎片">
    <meta property="og:url" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <?php if (!empty($config['logo'])): ?>
    <meta property="og:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['logo']) ?>">
    <?php endif; ?>
    <?php if (!empty($config['favicon'])): ?>
    <meta property="og:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['favicon']) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="说说 - <?= e($config['website_name']) ?>">
    <meta name="twitter:description" content="<?= e($config['website_name']) ?> 的说说页面，记录生活中的点滴碎片">
    <?php if (!empty($config['favicon'])): ?>
    <meta name="twitter:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['favicon']) ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') ?>">
    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "<?= e($config['website_name']) ?> - 说说",
      "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ?>",
      "description": "<?= e($config['website_name']) ?> 的说说页面，记录生活中的点滴碎片。"
    }
    </script>
    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #6366f1;
            --primary-bg: #eef2ff;
            --text: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --radius: 14px;
            --radius-sm: 10px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.06);
            --shadow: 0 4px 12px rgba(0,0,0,0.04), 0 1px 3px rgba(0,0,0,0.05);
            --shadow-lg: 0 12px 32px rgba(0,0,0,0.06), 0 2px 8px rgba(0,0,0,0.04);
            --transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navbar */
        .navbar.fixed-top {
            background: rgba(255,255,255,0.85) !important;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 8px 0 !important;
            box-shadow: var(--shadow-sm);
        }
        .navbar-brand {
            color: var(--text) !important;
            font-weight: 700;
            font-size: 1.05rem !important;
            letter-spacing: -0.3px;
        }
        .navbar-nav .nav-link {
            color: var(--text-secondary) !important;
            font-weight: 500;
            font-size: 0.9rem;
            transition: color var(--transition);
        }
        .navbar-nav .nav-link:hover { color: var(--primary) !important; }
        .navbar .btn {
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.4rem 0.9rem;
            border-radius: 8px;
            transition: all var(--transition);
        }
        .navbar .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
        }
        .navbar .btn-outline-primary:hover {
            background: var(--primary);
            color: #fff;
        }

        @media (min-width: 992px) {
            .navbar-nav .dropdown:hover .dropdown-menu {
                display: block;
                margin-top: 0;
            }
        }

        /* Main Container */
        .page-container {
            flex: 1;
            padding-top: 72px;
        }

        .content-wrapper {
            max-width: 960px;
            margin: 0 auto;
            padding: 24px 32px 40px;
        }

        /* Hero */
        .page-hero {
            text-align: center;
            margin-bottom: 32px;
        }
        .page-hero .hero-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            border-radius: 18px;
            background: var(--primary-bg);
            color: var(--primary);
            font-size: 1.8rem;
            margin-bottom: 14px;
        }
        .page-hero h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text);
            margin: 0 0 6px;
        }
        .page-hero p {
            color: var(--text-secondary);
            font-size: 0.92rem;
            margin: 0;
        }

        /* Card */
        .shuoshuo-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            transition: all var(--transition);
            animation: fadeIn 0.4s ease-out both;
        }
        .shuoshuo-card:hover {
            box-shadow: var(--shadow-lg);
            border-color: #c7d2fe;
            transform: translateY(-3px);
        }

        .shuoshuo-header {
            display: flex;
            align-items: center;
            margin-bottom: 16px;
        }

        .avatar {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            object-fit: cover;
            margin-right: 14px;
            border: 2px solid var(--border);
        }

        .user-info h5 {
            margin: 0;
            font-size: 1rem;
            color: var(--text);
            font-weight: 700;
        }

        .time {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .shuoshuo-content {
            font-size: 0.98rem;
            color: var(--text);
            line-height: 1.7;
            margin-bottom: 14px;
            white-space: pre-wrap;
        }

        .shuoshuo-image {
            max-width: 100%;
            border-radius: var(--radius-sm);
            margin-top: 8px;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: opacity 0.3s, box-shadow 0.3s;
            border: 1px solid var(--border);
        }
        .shuoshuo-image:hover {
            opacity: 0.92;
            box-shadow: var(--shadow);
        }

        /* Pagination */
        .pagination-wrap {
            display: flex;
            justify-content: center;
            margin-top: 32px;
            gap: 6px;
        }
        .page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            height: 38px;
            padding: 0 12px;
            border-radius: var(--radius-sm);
            font-size: 0.88rem;
            font-weight: 500;
            border: 1.5px solid var(--border);
            background: var(--card-bg);
            color: var(--text-secondary);
            text-decoration: none;
            transition: all var(--transition);
        }
        .page-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-bg);
        }
        .page-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(79,70,229,0.25);
        }

        /* Empty */
        .empty-state {
            text-align: center;
            padding: 64px 20px;
        }
        .empty-state .empty-icon {
            font-size: 3.5rem;
            color: var(--text-muted);
            margin-bottom: 16px;
            display: block;
        }
        .empty-state h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }
        .empty-state p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin: 0;
        }

        /* Admin form */
        .form-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 28px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        .form-card-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-card-title i { color: var(--primary); font-size: 1.2rem; }

        .form-card textarea {
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 0.92rem;
            background: var(--card-bg);
            color: var(--text);
            width: 100%;
            resize: vertical;
            transition: all var(--transition);
        }
        .form-card textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
            outline: none;
        }
        .form-card textarea::placeholder { color: var(--text-muted); }

        .admin-form-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 12px;
        }

        .file-input-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .file-input-wrap .file-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 14px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            border: 1.5px solid var(--border);
            background: var(--card-bg);
            color: var(--text-secondary);
            transition: all var(--transition);
        }
        .file-input-wrap .file-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-bg);
        }
        .file-input-wrap input[type="file"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }
        .file-name {
            font-size: 0.82rem;
            color: var(--text-muted);
            max-width: 160px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .btn-submit {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 9px 24px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
        }
        .btn-submit:hover {
            background: var(--primary-light);
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(79,70,229,0.3);
        }

        .alert {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            color: #dc2626;
            font-size: 0.88rem;
            margin-bottom: 16px;
        }

        /* Image modal */
        .img-modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.9);
            justify-content: center;
            align-items: center;
        }
        .img-modal-content {
            margin: auto;
            display: block;
            max-width: 90%;
            max-height: 90vh;
            border-radius: 8px;
            object-fit: contain;
        }
        .img-modal-close {
            position: absolute;
            top: 15px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }
        .img-modal-close:hover { color: #bbb; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Footer */
        footer {
            background: var(--card-bg);
            border-top: 1px solid var(--border);
            padding: 12px 0;
            font-size: 0.8rem;
            color: var(--text-muted);
            flex-shrink: 0;
        }
        footer a { color: var(--text-muted); text-decoration: none; }
        footer a:hover { color: var(--primary); }

        @media (max-width: 576px) {
            .content-wrapper { padding: 20px 16px 32px; }
            .shuoshuo-card { padding: 18px; }
            .form-card { padding: 20px; }
        }
    </style>
</head>
<body>
    <h1 class="visually-hidden">说说 - <?= e($config['website_name']) ?></h1>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light fixed-top">
        <div class="container">
            <a class="navbar-brand" href="/">
                <span class="d-none d-lg-inline">说说 | <?= e($config['website_name']) ?></span>
                <span class="d-lg-none">说说</span>
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <a class="btn btn-outline-primary btn-sm" id="backButton">返回</a>
            </div>
        </div>
    </nav>

    <div class="page-container">
        <div class="content-wrapper">

            <div class="page-hero">
                <div class="hero-icon">
                    <i class="bi bi-chat-quote"></i>
                </div>
                <h1>我的说说</h1>
                <p>记录生活中的点滴碎片</p>
            </div>

            <?php if ($isAdmin): ?>
            <?php if ($error): ?>
            <div class="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <div class="form-card">
                <div class="form-card-title">
                    <i class="bi bi-pencil-square"></i>发布说说
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <textarea name="content" rows="3" placeholder="分享你的新鲜事..." required></textarea>
                    <div class="admin-form-bottom">
                        <div class="file-input-wrap">
                            <label class="file-btn" for="imageUpload">
                                <i class="bi bi-image"></i> 添加图片
                            </label>
                            <input type="file" name="image" id="imageUpload" accept="image/*">
                            <span class="file-name" id="fileName"></span>
                        </div>
                        <button type="submit" name="publish_shuoshuo" class="btn-submit">
                            <i class="bi bi-send me-1"></i> 发布
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if (empty($shuoshuos)): ?>
                <div class="empty-state">
                    <span class="empty-icon"><i class="bi bi-chat-square-dots"></i></span>
                    <h3>暂时还没有发布任何说说</h3>
                    <p>主人正在酝酿中...</p>
                </div>
            <?php else: ?>
                <?php foreach ($shuoshuos as $index => $item): ?>
                    <div class="shuoshuo-card" style="animation-delay: <?= $index * 0.08 ?>s">
                        <div class="shuoshuo-header">
                            <img src="<?= !empty($config['logo']) ? e($config['logo']) : '/assets/images/default-avatar.png' ?>" alt="Avatar" class="avatar">
                            <div class="user-info">
                                <h5><?= e($config['website_author'] ?? 'Admin') ?></h5>
                                <div class="time">
                                    <i class="bi bi-clock"></i>
                                    <?= date('Y年m月d日 H:i', strtotime($item['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                        <div class="shuoshuo-content"><?= nl2br(e($item['content'])) ?></div>
                        <?php if (!empty($item['image_path'])): ?>
                            <img src="<?= e($item['image_path']) ?>" alt="Image" class="shuoshuo-image" onclick="openImage(this.src)">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination-wrap">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a class="page-btn <?= $i === $page ? 'active' : '' ?>" href="?page=<?= $i ?>"><?= $i ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 图片查看模态框 -->
    <div id="imgModal" class="img-modal" onclick="closeImage()">
        <span class="img-modal-close">&times;</span>
        <img class="img-modal-content" id="img01">
    </div>

    <?php require_once __DIR__ . '/footer.php'; ?>

    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script>
        // Image modal
        function openImage(src) {
            var modal = document.getElementById("imgModal");
            var modalImg = document.getElementById("img01");
            modal.style.display = "flex";
            modalImg.src = src;
        }
        function closeImage() {
            document.getElementById("imgModal").style.display = "none";
        }

        // File name display
        var imageUpload = document.getElementById('imageUpload');
        var fileNameSpan = document.getElementById('fileName');
        if (imageUpload) {
            imageUpload.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    fileNameSpan.textContent = this.files[0].name;
                } else {
                    fileNameSpan.textContent = '';
                }
            });
        }

        // Back button
        var backButton = document.getElementById('backButton');
        if (backButton) {
            backButton.addEventListener('click', function(e) {
                e.preventDefault();
                var referrer = document.referrer;
                var currentHost = window.location.hostname;
                if (referrer && referrer.includes(currentHost) && window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.href = '/';
                }
            });
        }
    </script>
</body>
</html>
