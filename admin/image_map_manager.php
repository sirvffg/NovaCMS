<?php
/**
 * 图片映射管理页面
 * 按文章分类显示图片映射，支持删除和批量删除
 */

ob_start();
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once 'includes/image_mapper.php';

requireLogin();

$db = getDB();

// 获取网站配置
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$imageBedApiUrl = $config['image_bed_api_url'] ?? '';
$imageBedApiKey = $config['image_bed_api_key'] ?? '';

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }
    
    $action = $_POST['ajax_action'];
    
    switch ($action) {
        case 'delete_single':
            $localUrl = $_POST['local_url'] ?? '';
            if (empty($localUrl)) {
                echo json_encode(['success' => false, 'message' => '缺少URL参数']);
                exit;
            }
            
            // 获取图片信息
            $info = ImageMapper::getByUrl($localUrl);
            if (!$info) {
                echo json_encode(['success' => false, 'message' => '映射不存在']);
                exit;
            }
            
            // 检查是否被其他文章使用
            $usageCount = ImageMapper::checkImageUsage($localUrl, $db);
            
            $result = [
                'local_deleted' => false,
                'local_file_deleted' => false,
                'image_bed_deleted' => false,
                'errors' => []
            ];
            
            // 删除图床图片
            if (!empty($info['image_bed_url']) && !empty($info['image_bed_id']) && !empty($imageBedApiUrl) && !empty($imageBedApiKey)) {
                $bedResult = ImageMapper::deleteFromImageBed($imageBedApiUrl, $imageBedApiKey, $info['image_bed_id']);
                if ($bedResult['success']) {
                    $result['image_bed_deleted'] = true;
                } else {
                    $result['errors'][] = '图床删除失败: ' . ($bedResult['error'] ?? '');
                }
            }
            
            // 只有当图片只被这篇文章使用时才删除本地文件
            if ($usageCount <= 1 && !empty($info['local_path'])) {
                $localPath = $info['local_path'];
                if (file_exists($localPath)) {
                    if (unlink($localPath)) {
                        $result['local_file_deleted'] = true;
                    } else {
                        $result['errors'][] = '本地文件删除失败';
                    }
                }
            }
            
            // 删除映射表记录
            if (ImageMapper::delete($localUrl)) {
                $result['local_deleted'] = true;
            }
            
            $message = '删除成功';
            if ($result['local_file_deleted']) {
                $message .= '（本地文件已删除）';
            } elseif ($usageCount > 1) {
                $message .= '（文件被其他文章使用，已保留）';
            }
            if (!empty($result['errors'])) {
                $message .= '，但部分操作失败: ' . implode('; ', $result['errors']);
            }
            
            echo json_encode(['success' => true, 'message' => $message, 'details' => $result]);
            break;
            
        case 'delete_batch':
            $urls = $_POST['urls'] ?? [];
            if (empty($urls)) {
                echo json_encode(['success' => false, 'message' => '没有选择要删除的项']);
                exit;
            }
            
            $deleted = 0;
            $failed = 0;
            $details = [];
            
            foreach ($urls as $localUrl) {
                $info = ImageMapper::getByUrl($localUrl);
                if (!$info) {
                    $failed++;
                    $details[$localUrl] = ['success' => false, 'message' => '映射不存在'];
                    continue;
                }
                
                $usageCount = ImageMapper::checkImageUsage($localUrl, $db);
                
                $result = ['local_deleted' => false, 'local_file_deleted' => false, 'image_bed_deleted' => false];
                
                // 删除图床
                if (!empty($info['image_bed_url']) && !empty($info['image_bed_id']) && !empty($imageBedApiUrl) && !empty($imageBedApiKey)) {
                    $bedResult = ImageMapper::deleteFromImageBed($imageBedApiUrl, $imageBedApiKey, $info['image_bed_id']);
                    if ($bedResult['success']) {
                        $result['image_bed_deleted'] = true;
                    }
                }
                
                // 删除本地文件
                if ($usageCount <= 1 && !empty($info['local_path']) && file_exists($info['local_path'])) {
                    if (unlink($info['local_path'])) {
                        $result['local_file_deleted'] = true;
                    }
                }
                
                // 删除映射
                if (ImageMapper::delete($localUrl)) {
                    $result['local_deleted'] = true;
                    $deleted++;
                } else {
                    $failed++;
                }
                
                $details[$localUrl] = $result;
            }
            
            echo json_encode([
                'success' => true,
                'message' => "批量删除完成: 成功 {$deleted} 项, 失败 {$failed} 项",
                'deleted' => $deleted,
                'failed' => $failed,
                'details' => $details
            ]);
            break;
            
        case 'refresh':
            // 重新扫描本地图片更新映射表
            $result = ImageMapper::scanLocalImages();
            echo json_encode(['success' => true, 'message' => '扫描完成', 'result' => $result]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
            break;
    }
    exit;
}

// 获取所有映射数据
function getPostsWithMappedImages($db) {
    $allMaps = ImageMapper::getAll();
    
    // 按 local_url 建立索引
    $mapIndex = [];
    foreach ($allMaps as $item) {
        if (!empty($item['local_url'])) {
            $mapIndex[$item['local_url']] = $item;
        }
    }
    
    // 获取所有文章
    $posts = $db->query("SELECT id, title, content FROM blog_posts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    foreach ($posts as $post) {
        $images = [];
        
        // 提取 Markdown 图片
        preg_match_all('/!\[.*?\]\((.*?)\)/', $post['content'], $mdMatches);
        foreach ($mdMatches[1] as $url) {
            if (strpos($url, '/uploads/') !== false) {
                $images[$url] = ['url' => $url, 'type' => 'markdown'];
            }
        }
        
        // 提取 HTML img 标签
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $post['content'], $imgMatches);
        foreach ($imgMatches[1] as $url) {
            if (strpos($url, '/uploads/') !== false && !isset($images[$url])) {
                $images[$url] = ['url' => $url, 'type' => 'html'];
            }
        }
        
        if (!empty($images)) {
            $mappedImages = [];
            $unmappedImages = [];
            
            foreach ($images as $img) {
                $url = $img['url'];
                $hasMap = isset($mapIndex[$url]);
                $mapInfo = $hasMap ? $mapIndex[$url] : null;
                
                $item = [
                    'url' => $url,
                    'type' => $img['type'],
                    'has_map' => $hasMap,
                    'has_image_bed' => $hasMap && !empty($mapInfo['image_bed_url']),
                    'image_bed_url' => $hasMap ? ($mapInfo['image_bed_url'] ?? '') : '',
                    'local_path' => $hasMap ? ($mapInfo['local_path'] ?? '') : '',
                    'filename' => $hasMap ? ($mapInfo['filename'] ?? basename($url)) : basename($url),
                    'updated_at' => $hasMap ? ($mapInfo['updated_at'] ?? '') : ''
                ];
                
                if ($hasMap) {
                    $mappedImages[] = $item;
                } else {
                    $unmappedImages[] = $item;
                }
            }
            
            if (!empty($mappedImages) || !empty($unmappedImages)) {
                $result[] = [
                    'id' => $post['id'],
                    'title' => $post['title'],
                    'mapped_images' => $mappedImages,
                    'unmapped_images' => $unmappedImages,
                    'mapped_count' => count($mappedImages),
                    'unmapped_count' => count($unmappedImages),
                    'total_count' => count($images)
                ];
            }
        }
    }
    
    return $result;
}

// 获取统计数据
$stats = ImageMapper::getStats();
$allMaps = ImageMapper::getAll();

// 有图床URL的映射数
$withImageBed = 0;
$withoutImageBed = 0;
foreach ($allMaps as $item) {
    if (!empty($item['image_bed_url'])) {
        $withImageBed++;
    } else {
        $withoutImageBed++;
    }
}

try {
    $postsWithMappedImages = getPostsWithMappedImages($db);
} catch (Exception $e) {
    $postsWithMappedImages = [];
    $error_msg = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>图片映射管理</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { margin-bottom: 20px; color: #333; }
        
        .stats-bar {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .stat-item {
            background: #fff;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
            min-width: 150px;
        }
        .stat-value { font-size: 28px; font-weight: bold; color: #1890ff; }
        .stat-label { font-size: 12px; color: #666; margin-top: 5px; }
        .stat-item.green .stat-value { color: #52c41a; }
        .stat-item.orange .stat-value { color: #fa8c16; }
        .stat-item.red .stat-value { color: #ff4d4f; }
        
        .header-bar {
            background: #fff;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header-actions { display: flex; gap: 10px; }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn-primary { background: #1890ff; color: #fff; }
        .btn-primary:hover { background: #40a9ff; }
        .btn-danger { background: #ff4d4f; color: #fff; }
        .btn-danger:hover { background: #ff7875; }
        .btn-success { background: #52c41a; color: #fff; }
        .btn-success:hover { background: #73d13d; }
        .btn-orange { background: #fa8c16; color: #fff; }
        .btn-orange:hover { background: #ffa940; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .post-item {
            background: #fff;
            border-radius: 8px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .post-header {
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }
        .post-header:hover { background: #fafafa; }
        .post-title { font-size: 16px; font-weight: 500; color: #333; }
        .post-meta { font-size: 12px; color: #999; margin-top: 4px; }
        .post-checkbox { margin-right: 15px; transform: scale(1.2); cursor: pointer; }
        .post-body { display: none; padding: 20px; background: #fafafa; }
        .post-body.show { display: block; }
        
        .arrow { transition: transform 0.2s; margin-left: 10px; }
        .post-header.open .arrow { transform: rotate(90deg); }
        
        .image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
        .image-item {
            background: #fff;
            border-radius: 6px;
            overflow: hidden;
            border: 2px solid #e8e8e8;
            transition: all 0.2s;
            position: relative;
        }
        .image-item.mapped { border-color: #52c41a; }
        .image-item.unmapped { border-color: #fa8c16; }
        .image-item.selected { border-color: #1890ff; background: #e6f7ff; }
        
        .image-item img {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }
        
        .image-item .info {
            padding: 10px;
        }
        .image-item .filename {
            font-size: 11px;
            color: #666;
            word-break: break-all;
            margin-bottom: 5px;
        }
        .image-item .url {
            font-size: 10px;
            color: #999;
            word-break: break-all;
            margin-bottom: 8px;
        }
        .image-item .actions {
            display: flex;
            gap: 5px;
        }
        .image-item .btn-sm {
            padding: 4px 8px;
            font-size: 11px;
            border-radius: 3px;
            border: none;
            cursor: pointer;
        }
        .btn-delete { background: #ff4d4f; color: #fff; }
        .btn-delete:hover { background: #ff7875; }
        
        .badge {
            position: absolute;
            top: 5px;
            right: 5px;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            color: #fff;
        }
        .badge-uploaded { background: #52c41a; }
        .badge-local { background: #fa8c16; }
        
        .section-title {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e8e8e8;
        }
        .section-mapped { color: #52c41a; }
        .section-unmapped { color: #fa8c16; }
        
        .batch-bar {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #fff;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: none;
            gap: 15px;
            align-items: center;
        }
        .batch-bar.show { display: flex; }
        
        .result-toast {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            border-radius: 4px;
            font-size: 14px;
            z-index: 1000;
            display: none;
        }
        .result-toast.success { background: #52c41a; color: #fff; }
        .result-toast.error { background: #ff4d4f; color: #fff; }
        .result-toast.show { display: block; }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .empty-state i { font-size: 48px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📁 图片映射管理</h1>
        
        <!-- 统计信息 -->
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-value"><?= $stats['total'] ?></div>
                <div class="stat-label">总映射数</div>
            </div>
            <div class="stat-item green">
                <div class="stat-value"><?= $withImageBed ?></div>
                <div class="stat-label">已上传图床</div>
            </div>
            <div class="stat-item orange">
                <div class="stat-value"><?= $withoutImageBed ?></div>
                <div class="stat-label">仅本地</div>
            </div>
            <div class="stat-item red">
                <div class="stat-value"><?= count($postsWithMappedImages) ?></div>
                <div class="stat-label">涉及文章</div>
            </div>
        </div>
        
        <!-- 操作栏 -->
        <div class="header-bar">
            <div>
                <span>共 <strong><?= count($postsWithMappedImages) ?></strong> 篇文章包含映射图片</span>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="selectAll()">全选</button>
                <button class="btn btn-primary" onclick="selectNone()">取消全选</button>
                <button class="btn btn-success" onclick="selectWithMap()">仅选有图床</button>
                <button class="btn btn-orange" onclick="refreshMaps()">刷新扫描</button>
                <button class="btn btn-danger" onclick="deleteSelected()" id="deleteBtn" disabled>删除选中</button>
            </div>
        </div>
        
        <!-- 文章列表 -->
        <?php if (empty($postsWithMappedImages)): ?>
            <div class="empty-state">
                <div>暂无图片映射数据</div>
                <div style="margin-top:10px;font-size:12px;">点击"刷新扫描"按钮扫描本地图片</div>
            </div>
        <?php else: ?>
            <?php foreach ($postsWithMappedImages as $post): ?>
                <div class="post-item" data-post-id="<?= $post['id'] ?>">
                    <div class="post-header" onclick="togglePost(this)">
                        <div style="display:flex;align-items:center;">
                            <input type="checkbox" class="post-checkbox" onchange="updateBatchBar(); event.stopPropagation();" data-post-checkbox>
                            <div>
                                <div class="post-title"><?= htmlspecialchars($post['title']) ?></div>
                                <div class="post-meta">
                                    文章ID: <?= $post['id'] ?> | 
                                    总计: <?= $post['total_count'] ?> 张 |
                                    <span style="color:#52c41a;">已映射: <?= $post['mapped_count'] ?></span> |
                                    <span style="color:#fa8c16;">未映射: <?= $post['unmapped_count'] ?></span>
                                </div>
                            </div>
                        </div>
                        <span class="arrow">▶</span>
                    </div>
                    <div class="post-body" data-loaded="false">
                        <?php if (!empty($post['mapped_images'])): ?>
                            <div class="section-title section-mapped">✓ 已映射图片 (<?= count($post['mapped_images']) ?>)</div>
                            <div class="image-grid">
                                <?php foreach ($post['mapped_images'] as $img): ?>
                                    <div class="image-item mapped" data-url="<?= htmlspecialchars($img['url']) ?>">
                                        <span class="badge <?= $img['has_image_bed'] ? 'badge-uploaded' : 'badge-local' ?>">
                                            <?= $img['has_image_bed'] ? '已上传' : '仅本地' ?>
                                        </span>
                                        <img src="<?= $img['url'] ?>" alt="" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22100%22 height=%22100%22><rect fill=%22%23f0f0f0%22 width=%22100%22 height=%22100%22/><text x=%2250%22 y=%2255%22 text-anchor=%22middle%22 fill=%22%23999%22 font-size=%2212%22>?</text></svg>'">
                                        <div class="info">
                                            <div class="filename"><?= htmlspecialchars($img['filename']) ?></div>
                                            <div class="url"><?= htmlspecialchars($img['url']) ?></div>
                                            <div class="actions">
                                                <input type="checkbox" class="image-checkbox" data-url="<?= htmlspecialchars($img['url']) ?>" onchange="updateBatchBar()">
                                                <button class="btn-sm btn-delete" onclick="deleteSingle('<?= htmlspecialchars($img['url'], ENT_QUOTES) ?>')">删除</button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($post['unmapped_images'])): ?>
                            <div class="section-title section-unmapped" style="margin-top:<?= !empty($post['mapped_images']) ? '20px' : '0' ?>">
                                ⚠ 未映射图片 (<?= count($post['unmapped_images']) ?>) - 这些图片未在映射表中
                            </div>
                            <div class="image-grid">
                                <?php foreach ($post['unmapped_images'] as $img): ?>
                                    <div class="image-item unmapped" data-url="<?= htmlspecialchars($img['url']) ?>">
                                        <span class="badge badge-local">未映射</span>
                                        <img src="<?= $img['url'] ?>" alt="" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22100%22 height=%22100%22><rect fill=%22%23f0f0f0%22 width=%22100%22 height=%22100%22/><text x=%2250%22 y=%2255%22 text-anchor=%22middle%22 fill=%22%23999%22 font-size=%2212%22>?</text></svg>'">
                                        <div class="info">
                                            <div class="filename"><?= htmlspecialchars($img['filename']) ?></div>
                                            <div class="url"><?= htmlspecialchars($img['url']) ?></div>
                                            <div class="actions">
                                                <input type="checkbox" class="image-checkbox" data-url="<?= htmlspecialchars($img['url']) ?>" onchange="updateBatchBar()">
                                                <button class="btn-sm btn-delete" onclick="deleteSingle('<?= htmlspecialchars($img['url'], ENT_QUOTES) ?>')">删除</button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- 批量操作栏 -->
    <div class="batch-bar" id="batchBar">
        <span>已选择 <strong id="selectedCount">0</strong> 项</span>
        <button class="btn btn-danger" onclick="deleteSelected()">批量删除</button>
        <button class="btn btn-primary" onclick="selectNone()">取消</button>
    </div>
    
    <!-- 结果提示 -->
    <div class="result-toast" id="resultToast"></div>
    
    <script>
        function togglePost(el) {
            el.classList.toggle('open');
            el.closest('.post-item').querySelector('.post-body').classList.toggle('show');
        }
        
        function selectAll() {
            document.querySelectorAll('.image-checkbox').forEach(cb => cb.checked = true);
            document.querySelectorAll('.post-checkbox').forEach(cb => cb.checked = true);
            updateBatchBar();
        }
        
        function selectNone() {
            document.querySelectorAll('.image-checkbox').forEach(cb => cb.checked = false);
            document.querySelectorAll('.post-checkbox').forEach(cb => cb.checked = false);
            updateBatchBar();
        }
        
        function selectWithMap() {
            selectNone();
            document.querySelectorAll('.image-item.mapped .image-checkbox').forEach(cb => cb.checked = true);
            updateBatchBar();
        }
        
        function updateBatchBar() {
            const checked = document.querySelectorAll('.image-checkbox:checked');
            const bar = document.getElementById('batchBar');
            const count = document.getElementById('selectedCount');
            const deleteBtn = document.getElementById('deleteBtn');
            count.textContent = checked.length;
            bar.classList.toggle('show', checked.length > 0);
            deleteBtn.disabled = checked.length === 0;
        }
        
        function showToast(message, type = 'success') {
            const toast = document.getElementById('resultToast');
            toast.textContent = message;
            toast.className = 'result-toast ' + type + ' show';
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
        
        function deleteSingle(url) {
            if (!confirm('确定要删除此映射吗？\n\n' + url)) return;
            
            const formData = new FormData();
            formData.append('ajax_action', 'delete_single');
            formData.append('local_url', url);
            
            fetch('image_map_manager.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    // 移除已删除的图片项
                    const item = document.querySelector('.image-item[data-url="' + decodeURIComponent(url) + '"]');
                    if (item) {
                        item.style.transition = 'all 0.3s';
                        item.style.opacity = '0';
                        item.style.transform = 'scale(0.8)';
                        setTimeout(() => item.remove(), 300);
                    }
                    updateStats();
                }
            })
            .catch(err => showToast('删除失败: ' + err, 'error'));
        }
        
        function deleteSelected() {
            const checked = document.querySelectorAll('.image-checkbox:checked');
            if (checked.length === 0) {
                showToast('请先选择要删除的项', 'error');
                return;
            }
            
            if (!confirm('确定要删除选中的 ' + checked.length + ' 项映射吗？')) return;
            
            const urls = Array.from(checked).map(cb => cb.dataset.url);
            
            const formData = new FormData();
            formData.append('ajax_action', 'delete_batch');
            formData.append('urls', JSON.stringify(urls));
            
            fetch('image_map_manager.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    // 移除已删除的项
                    urls.forEach(url => {
                        const item = document.querySelector('.image-item[data-url="' + decodeURIComponent(url) + '"]');
                        if (item) {
                            item.style.transition = 'all 0.3s';
                            item.style.opacity = '0';
                            item.style.transform = 'scale(0.8)';
                            setTimeout(() => item.remove(), 300);
                        }
                    });
                    setTimeout(() => {
                        selectNone();
                        updateStats();
                    }, 350);
                }
            })
            .catch(err => showToast('删除失败: ' + err, 'error'));
        }
        
        function updateStats() {
            // 简单刷新页面更新统计
            location.reload();
        }
        
        function refreshMaps() {
            if (!confirm('确定要重新扫描本地图片吗？这将更新映射表统计信息。')) return;
            
            const formData = new FormData();
            formData.append('ajax_action', 'refresh');
            
            fetch('image_map_manager.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                showToast(data.message + ' (扫描: ' + data.result.scanned + ', 新增: ' + data.result.added + ')', 'success');
                setTimeout(() => location.reload(), 1500);
            })
            .catch(err => showToast('刷新失败: ' + err, 'error'));
        }
        
        // 复选框事件委托
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('post-checkbox')) {
                const postItem = e.target.closest('.post-item');
                const checkboxes = postItem.querySelectorAll('.image-checkbox');
                checkboxes.forEach(cb => cb.checked = e.target.checked);
                updateBatchBar();
            }
        });
    </script>
</body>
</html>
