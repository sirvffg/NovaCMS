<?php
/**
 * 图片归类管理界面
 * 显示每篇文章中的图片，让用户选择要迁移的文章
 */

ob_start();

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

requireLogin();

$db = getDB();
$error_msg = '';

// 函数定义（必须在调用之前）
function extractImagesFromContent($content) {
    $images = [];
    
    // 匹配 Markdown 图片格式 ![alt](url)
    preg_match_all('/!\[.*?\]\((.*?)\)/', $content, $matches);
    foreach ($matches[1] as $url) {
        if (strpos($url, '/uploads/') !== false && strpos($url, '/uploads/posts/') === false) {
            $images[] = ['url' => $url, 'type' => 'markdown'];
        }
    }
    
    // 匹配 HTML img 标签
    preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches);
    foreach ($matches[1] as $url) {
        if (strpos($url, '/uploads/') !== false && strpos($url, '/uploads/posts/') === false) {
            $exists = false;
            foreach ($images as $img) {
                if ($img['url'] === $url) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $images[] = ['url' => $url, 'type' => 'html'];
            }
        }
    }
    
    return $images;
}

function getPostsWithImages($db) {
    $sql = "SELECT id, title, content, cover_image FROM blog_posts ORDER BY id DESC";
    $stmt = $db->query($sql);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    foreach ($posts as $post) {
        $images = extractImagesFromContent($post['content']);
        if (!empty($images)) {
            $result[] = [
                'id' => $post['id'],
                'title' => $post['title'],
                'images' => $images,
                'image_count' => count($images)
            ];
        }
    }
    return $result;
}

function migratePostImages($db, $postId) {
    $uploadsDir = dirname(__DIR__) . '/uploads/';
    $postsDir = $uploadsDir . 'posts/';
    
    $stmt = $db->prepare("SELECT content FROM blog_posts WHERE id = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$post) {
        return ['success' => false, 'message' => '文章不存在'];
    }
    
    $images = extractImagesFromContent($post['content']);
    if (empty($images)) {
        return ['success' => true, 'message' => '没有需要迁移的图片', 'migrated' => 0];
    }
    
    $migrated = 0;
    $errors = [];
    $newContent = $post['content'];
    
    foreach ($images as $image) {
        $oldUrl = $image['url'];
        $filename = basename($oldUrl);
        $oldPath = $uploadsDir . $filename;
        
        if (!file_exists($oldPath)) {
            $errors[] = "文件不存在: {$filename}";
            continue;
        }
        
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})_\d+_[a-f0-9]+\./i', $filename, $matches)) {
            $errors[] = "无法解析日期: {$filename}";
            continue;
        }
        
        $year = $matches[1];
        $month = $matches[2];
        $targetDir = $postsDir . "{$year}-{$month}/";
        $newPath = $targetDir . $filename;
        $newUrl = '/uploads/posts/' . "{$year}-{$month}/" . $filename;
        
        if (file_exists($newPath)) {
            $newContent = str_replace($oldUrl, $newUrl, $newContent);
            $migrated++;
            continue;
        }
        
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        if (rename($oldPath, $newPath)) {
            $newContent = str_replace($oldUrl, $newUrl, $newContent);
            $migrated++;
        } else {
            $errors[] = "移动失败: {$filename}";
        }
    }
    
    if ($migrated > 0) {
        $stmt = $db->prepare("UPDATE blog_posts SET content = ? WHERE id = ?");
        $stmt->execute([$newContent, $postId]);
    }
    
    return [
        'success' => true,
        'message' => "迁移完成: {$migrated} 个图片",
        'migrated' => $migrated,
        'errors' => $errors
    ];
}

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'migrate') {
        $postId = intval($_POST['post_id']);
        $result = migratePostImages($db, $postId);
    } elseif ($_POST['action'] === 'migrate_all') {
        $postIds = $_POST['post_ids'] ?? [];
        $results = [];
        foreach ($postIds as $postId) {
            $results[$postId] = migratePostImages($db, intval($postId));
        }
        $result = $results;
    } else {
        $result = ['success' => false, 'message' => '未知操作'];
    }
    echo json_encode($result);
    exit;
}

// 获取文章数据
try {
    $posts = getPostsWithImages($db);
} catch (Exception $e) {
    $posts = [];
    $error_msg = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>图片归类管理</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { margin-bottom: 20px; color: #333; }
        .header-bar { 
            background: #fff; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .summary { color: #666; font-size: 14px; }
        .btn {
            padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer;
            font-size: 14px; transition: all 0.2s;
        }
        .btn-primary { background: #1890ff; color: #fff; }
        .btn-primary:hover { background: #40a9ff; }
        .btn-success { background: #52c41a; color: #fff; }
        .btn-success:hover { background: #73d13d; }
        
        .post-item {
            background: #fff; border-radius: 8px; margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden;
        }
        .post-header {
            padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;
            cursor: pointer; border-bottom: 1px solid #f0f0f0;
        }
        .post-header:hover { background: #fafafa; }
        .post-title { font-size: 16px; font-weight: 500; color: #333; }
        .post-meta { font-size: 12px; color: #999; margin-top: 4px; }
        .post-checkbox { margin-right: 15px; transform: scale(1.2); cursor: pointer; }
        .post-body { display: none; padding: 20px; background: #fafafa; }
        .post-body.show { display: block; }
        
        .image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; }
        .image-item {
            background: #fff; border-radius: 6px; overflow: hidden;
            border: 2px solid transparent; transition: all 0.2s; position: relative;
        }
        .image-item img { width: 100%; height: 120px; object-fit: cover; }
        .image-item .filename { padding: 8px; font-size: 11px; color: #666; word-break: break-all; }
        .image-item.migrated { border-color: #52c41a; }
        .image-item.migrated::after {
            content: '已迁移'; position: absolute; top: 5px; right: 5px;
            background: #52c41a; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 10px;
        }
        
        .arrow { transition: transform 0.2s; margin-left: 10px; }
        .post-header.open .arrow { transform: rotate(90deg); }
        
        .result-message { padding: 10px; border-radius: 4px; margin-top: 10px; font-size: 14px; }
        .result-message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: #52c41a; }
        .result-message.error { background: #fff2f0; border: 1px solid #ffccc7; color: #ff4d4f; }
        
        .batch-bar { 
            position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
            background: #fff; padding: 15px 25px; border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: none;
        }
        .batch-bar.show { display: flex; gap: 15px; align-items: center; }
        
        .error-box {
            background: #fff2f0; border: 1px solid #ffccc7; color: #ff4d4f;
            padding: 15px; border-radius: 8px; margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>图片归类管理</h1>
        
        <?php if ($error_msg): ?>
        <div class="error-box">
            错误: <?= htmlspecialchars($error_msg) ?>
        </div>
        <?php endif; ?>
        
        <div class="header-bar">
            <div class="summary">
                共找到 <strong><?= count($posts) ?></strong> 篇文章包含散落的图片，共 <strong><?= array_sum(array_column($posts, 'image_count')) ?></strong> 张图片
            </div>
            <div>
                <button class="btn btn-primary" onclick="selectAll()">全选</button>
                <button class="btn btn-primary" onclick="selectNone()">取消全选</button>
            </div>
        </div>
        
        <?php if (empty($posts)): ?>
            <div class="post-item">
                <div class="post-header" style="cursor:default;">
                    <span>没有找到包含散落图片的文章</span>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
                <div class="post-item" data-post-id="<?= $post['id'] ?>">
                    <div class="post-header" onclick="togglePost(this)">
                        <div style="display:flex;align-items:center;">
                            <input type="checkbox" class="post-checkbox" onchange="updateBatchBar()" onclick="event.stopPropagation()">
                            <div>
                                <div class="post-title"><?= htmlspecialchars($post['title']) ?></div>
                                <div class="post-meta">文章ID: <?= $post['id'] ?> | 图片数量: <?= $post['image_count'] ?></div>
                            </div>
                        </div>
                        <span class="arrow">▶</span>
                    </div>
                    <div class="post-body">
                        <div class="image-grid">
                            <?php foreach ($post['images'] as $img): ?>
                                <div class="image-item" data-url="<?= htmlspecialchars($img['url']) ?>">
                                    <img src="<?= htmlspecialchars($img['url']) ?>" alt="" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22100%22 height=%22100%22><rect fill=%22%23f0f0f0%22 width=%22100%22 height=%22100%22/><text x=%2250%22 y=%2255%22 text-anchor=%22middle%22 fill=%22%23999%22 font-size=%2212%22>图片加载失败</text></svg>'">
                                    <div class="filename"><?= htmlspecialchars(basename($img['url'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:15px;">
                            <button class="btn btn-success" onclick="migratePost(<?= $post['id'] ?>, this)">迁移这篇文章的图片</button>
                            <span class="result-message" style="display:inline-block;"></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div class="batch-bar" id="batchBar">
        <span>已选择 <strong id="selectedCount">0</strong> 篇文章</span>
        <button class="btn btn-success" onclick="migrateSelected()">批量迁移</button>
    </div>
    
    <script>
        function togglePost(el) {
            el.classList.toggle('open');
            el.nextElementSibling.classList.toggle('show');
        }
        
        function selectAll() {
            document.querySelectorAll('.post-checkbox').forEach(cb => cb.checked = true);
            updateBatchBar();
        }
        
        function selectNone() {
            document.querySelectorAll('.post-checkbox').forEach(cb => cb.checked = false);
            updateBatchBar();
        }
        
        function updateBatchBar() {
            const checked = document.querySelectorAll('.post-checkbox:checked');
            const bar = document.getElementById('batchBar');
            const count = document.getElementById('selectedCount');
            count.textContent = checked.length;
            bar.classList.toggle('show', checked.length > 0);
        }
        
        function migratePost(postId, btn) {
            const msg = btn.nextElementSibling;
            btn.disabled = true;
            btn.textContent = '迁移中...';
            msg.style.display = 'none';
            
            fetch('migrate_images.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=migrate&post_id=' + postId
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.textContent = '迁移这篇文章的图片';
                
                if (data.success) {
                    msg.className = 'result-message success';
                    msg.textContent = data.message;
                    
                    if (data.migrated > 0) {
                        const postItem = document.querySelector(`.post-item[data-post-id="${postId}"]`);
                        postItem.querySelectorAll('.image-item').forEach(item => {
                            item.classList.add('migrated');
                        });
                    }
                } else {
                    msg.className = 'result-message error';
                    msg.textContent = data.message;
                }
                msg.style.display = 'inline-block';
            })
            .catch(err => {
                btn.disabled = false;
                btn.textContent = '迁移这篇文章的图片';
                msg.className = 'result-message error';
                msg.textContent = '请求失败: ' + err;
                msg.style.display = 'inline-block';
            });
        }
        
        function migrateSelected() {
            const checked = document.querySelectorAll('.post-checkbox:checked');
            if (checked.length === 0) return;
            
            const postIds = [];
            checked.forEach(cb => {
                postIds.push(cb.closest('.post-item').dataset.postId);
            });
            
            if (!confirm('确定要迁移选中的 ' + postIds.length + ' 篇文章的图片吗？')) return;
            
            fetch('migrate_images.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=migrate_all&post_ids=' + postIds.join(',')
            })
            .then(r => r.json())
            .then(data => {
                let success = 0, fail = 0;
                for (const [id, result] of Object.entries(data)) {
                    if (result.success && result.migrated > 0) {
                        success++;
                        const postItem = document.querySelector(`.post-item[data-post-id="${id}"]`);
                        if (postItem) {
                            postItem.querySelectorAll('.image-item').forEach(item => {
                                item.classList.add('migrated');
                            });
                        }
                    } else if (!result.success) {
                        fail++;
                    }
                }
                alert('批量迁移完成: 成功 ' + success + ' 篇, 失败 ' + fail + ' 篇');
                location.reload();
            });
        }
    </script>
</body>
</html>
