<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
recordVisit($_SERVER['REQUEST_URI']);

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// Determine view mode
$view = isset($_GET['album_id']) ? 'single_album' : 'list_albums';
$currentAlbum = null;
$photos = [];
$albums = [];

if ($view === 'single_album') {
    $albumId = (int)$_GET['album_id'];
    $stmt = $db->prepare("SELECT * FROM photo_albums WHERE id=?");
    $stmt->execute([$albumId]);
    $currentAlbum = $stmt->fetch();
    
    if ($currentAlbum) {
        $photos = $db->prepare("SELECT * FROM photos WHERE album_id=? ORDER BY created_at DESC");
        $photos->execute([$albumId]);
        $photos = $photos->fetchAll();
    } else {
        $view = 'list_albums'; // Fallback if not found
    }
}

if ($view === 'list_albums') {
    // Get albums with photo count and first photo as cover if cover_image is empty
    try {
        $albums = $db->query("
            SELECT a.*, COUNT(p.id) as photo_count,
            (SELECT url FROM photos WHERE album_id = a.id ORDER BY created_at ASC LIMIT 1) as first_photo
            FROM photo_albums a 
            LEFT JOIN photos p ON a.id = p.album_id 
            GROUP BY a.id 
            ORDER BY a.sort_order ASC, a.created_at DESC
        ")->fetchAll();
    } catch (PDOException $e) {
        $albums = [];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $currentAlbum ? e($currentAlbum['name']) . ' - ' : '' ?>相册 - <?= e($config['website_name']) ?></title>
    
    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: url('https://api.fuchenboke.cn/api/dongman.php') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.3); /* Slightly darker overlay for better text contrast */
            z-index: -1;
        }

        .navbar.fixed-top {
            background: rgba(255, 255, 255, 0.85) !important;
            backdrop-filter: blur(12px);
            border-bottom: 1px solid #dee2e6 !important;
        }

        .container-wrapper {
            padding: 100px 20px 40px;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Album Card Styles */
        .album-card {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            height: 250px;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            background: #000;
            margin-bottom: 20px;
        }

        .album-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.3);
        }

        .album-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0.7;
            transition: opacity 0.3s, transform 0.5s;
        }

        .album-card:hover img {
            opacity: 0.4;
            transform: scale(1.05);
        }

        .album-info {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            padding: 20px;
            color: white;
            background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
            pointer-events: none;
        }

        .album-title {
            font-size: 1.4rem;
            font-weight: bold;
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.5);
        }

        .album-desc {
            font-size: 0.9rem;
            opacity: 0.9;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Photo Grid Styles */
        .gallery-item {
            position: relative;
            margin-bottom: 20px;
            break-inside: avoid;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            background: rgba(255, 255, 255, 0.95);
        }

        .gallery-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }

        .gallery-item img {
            width: 100%;
            height: auto;
            display: block;
            cursor: pointer;
        }

        .gallery-caption {
            padding: 15px;
            background: rgba(255, 255, 255, 0.95);
        }

        .gallery-title {
            font-weight: 600;
            margin-bottom: 5px;
            font-size: 1rem;
            color: #333;
        }

        .gallery-desc {
            font-size: 0.9rem;
            color: #666;
        }

        /* Masonry Layout */
        .masonry-grid {
            column-count: 4;
            column-gap: 20px;
        }

        @media (max-width: 1200px) { .masonry-grid { column-count: 3; } }
        @media (max-width: 768px) { .masonry-grid { column-count: 2; } }
        @media (max-width: 576px) { .masonry-grid { column-count: 1; } }
        
        /* Modal */
        .modal-img {
            width: 100%;
            height: auto;
            border-radius: 5px;
        }

        /* 页脚样式 */
        footer {
            background-color: #ffffff !important;
            color: #6c757d !important;
            border-top: 1px solid #dee2e6 !important;
        }

        footer .footer-links a,
        footer .footer-extra a {
            color: #6c757d;
            text-decoration: none;
            transition: color 0.2s;
        }

        footer .footer-links a:hover,
        footer .footer-extra a:hover {
            color: #0d6efd;
        }

        footer .footer-info {
            color: #6c757d;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light fixed-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="/">
                <?= e($config['website_name']) ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="/">首页</a></li>
                    <li class="nav-item"><a class="nav-link" href="/blog.php">博客</a></li>
                    <li class="nav-item"><a class="nav-link" href="/vendor/shuoshuo.php">说说</a></li>
                    <li class="nav-item"><a class="nav-link active" href="/vendor/gallery.php">相册</a></li>
                    <li class="nav-item"><a class="nav-link" href="/vendor/guestbook.php">留言板</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="/vendor/thanks.php">🎁 特别鸣谢</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-wrapper">
        <?php if ($view === 'list_albums'): ?>
            <!-- Album List View -->
            <h2 class="mb-4 text-white text-center fw-bold" style="text-shadow: 0 2px 4px rgba(0,0,0,0.5);">
                <i class="bi bi-images me-2"></i>我的相册
            </h2>
            <p class="text-white text-center mb-5" style="text-shadow: 0 1px 2px rgba(0,0,0,0.5);">记录生活中的美好瞬间</p>

            <?php if (empty($albums)): ?>
                <div class="text-center py-5 bg-white bg-opacity-75 rounded">
                    <div class="text-muted">
                        <i class="bi bi-images display-1 mb-3 d-block"></i>
                        <p>暂时还没有创建相册哦~</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($albums as $album): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="album-card" onclick="location.href='?album_id=<?= $album['id'] ?>'">
                            <?php 
                                $cover = !empty($album['cover_image']) ? $album['cover_image'] : ($album['first_photo'] ?? '/assets/images/default-album.png');
                            ?>
                            <img src="<?= e($cover) ?>" 
                                 alt="<?= e($album['name']) ?>"
                                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBwcmVzZXJ2ZUFzcGVjdFJhdGlvPSJub25lIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjMzMzIi8+PC9zdmc+'">
                            <div class="album-info">
                                <div class="album-title">
                                    <?= e($album['name']) ?> 
                                    <span class="fs-6 fw-normal opacity-75">(<?= $album['photo_count'] ?>)</span>
                                </div>
                                <?php if (!empty($album['description'])): ?>
                                <div class="album-desc"><?= e($album['description']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($view === 'single_album'): ?>
            <!-- Single Album View -->
            <div class="d-flex align-items-center mb-4 text-white">
                <a href="/vendor/gallery.php" class="btn btn-outline-light me-3 border-0">
                    <i class="bi bi-arrow-left fs-4"></i>
                </a>
                <div>
                    <h2 class="mb-0 fw-bold" style="text-shadow: 0 2px 4px rgba(0,0,0,0.5);"><?= e($currentAlbum['name']) ?></h2>
                    <?php if (!empty($currentAlbum['description'])): ?>
                    <div class="opacity-75 mt-1"><?= e($currentAlbum['description']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($photos)): ?>
                <div class="text-center py-5 bg-white bg-opacity-75 rounded">
                    <div class="text-muted">
                        <i class="bi bi-images display-1 mb-3 d-block"></i>
                        <p>此相册暂时还没有照片哦~</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="masonry-grid">
                    <?php foreach ($photos as $photo): ?>
                    <div class="gallery-item">
                        <img src="<?= e($photo['url']) ?>" 
                             alt="<?= e($photo['title'] ?: $currentAlbum['name']) ?>"
                             data-bs-toggle="modal" 
                             data-bs-target="#imageModal" 
                             data-src="<?= e($photo['url']) ?>"
                             data-title="<?= e($photo['title'] ?: $currentAlbum['name']) ?>"
                             data-desc="<?= e($photo['description'] ?: $photo['created_at']) ?>">
                        
                        <?php if (!empty($photo['title']) || !empty($photo['description'])): ?>
                        <div class="gallery-caption">
                            <?php if (!empty($photo['title'])): ?>
                            <div class="gallery-title"><?= e($photo['title']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($photo['description'])): ?>
                            <div class="gallery-desc"><?= e($photo['description']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content bg-transparent border-0">
                <div class="modal-header border-0">
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <img src="" class="modal-img rounded shadow-lg" id="modalImage">
                    <div class="mt-3 text-white">
                        <h4 id="modalTitle"></h4>
                        <p id="modalDesc"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/footer.php'; ?>

    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle Image Modal
        const imageModal = document.getElementById('imageModal');
        imageModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const src = button.getAttribute('data-src');
            const title = button.getAttribute('data-title');
            const desc = button.getAttribute('data-desc');

            const modalImage = imageModal.querySelector('#modalImage');
            const modalTitle = imageModal.querySelector('#modalTitle');
            const modalDesc = imageModal.querySelector('#modalDesc');

            modalImage.src = src;
            modalTitle.textContent = title;
            modalDesc.textContent = desc;
        });
    </script>
</body>
</html>
