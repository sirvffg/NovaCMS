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

$base_dir = realpath('../logs');
$current_path = isset($_GET['path']) ? trim($_GET['path']) : '';

// Security check: 使用安全路径验证函数
$target_path = validatePath($current_path, '../logs', true);
if ($target_path === false) {
    $target_path = $base_dir;
    $current_path = '';
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function getDirectorySize($path) {
    $bytestotal = 0;
    $path = realpath($path);
    if($path!==false && $path!='' && file_exists($path)){
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $object){
            $bytestotal += $object->getSize();
        }
    }
    return $bytestotal;
}

// Security check: ensure path is within logs directory
$target_path = realpath($base_dir . '/' . $current_path);
if ($target_path === false || strpos($target_path, $base_dir) !== 0) {
    $target_path = $base_dir;
    $current_path = '';
}

$is_file = is_file($target_path);
$file_content = '';
if ($is_file) {
    // Only read if it's a text/log file
    $ext = pathinfo($target_path, PATHINFO_EXTENSION);
    if (in_array(strtolower($ext), ['log', 'txt'])) {
        $file_content = file_get_contents($target_path);
    } else {
        $file_content = '不支持预览的文件格式';
    }
} else {
    $items = scandir($target_path);
    $files = [];
    $dirs = [];
    foreach ($items as $item) {
        if ($item === '.') continue;
        if ($item === '..' && $current_path === '') continue;
        
        $item_path = $target_path . '/' . $item;
        $rel_path = ltrim($current_path . '/' . $item, '/');
        
        if ($item === '..') {
            $parent = dirname($current_path);
            if ($parent === '.' || $parent === '\\' || $parent === '/') $parent = '';
            $dirs[] = ['name' => '.. (返回上一级)', 'path' => $parent, 'type' => 'dir'];
        } elseif (is_dir($item_path)) {
            $dirs[] = [
                'name' => $item, 
                'path' => $rel_path, 
                'type' => 'dir',
                'size' => getDirectorySize($item_path),
                'mtime' => filemtime($item_path)
            ];
        } else {
            $files[] = [
                'name' => $item, 
                'path' => $rel_path, 
                'type' => 'file',
                'size' => filesize($item_path),
                'mtime' => filemtime($item_path)
            ];
        }
    }
}

// ========== 访问记录查询 ==========
$vlog_page = max(1, intval($_GET['vlog_page'] ?? 1));
$vlog_per_page = 20;
$vlog_offset = ($vlog_page - 1) * $vlog_per_page;

$vlog_filter = $_GET['vlog_filter'] ?? 'all';
$vlog_where = '';
$vlog_params = [];
if ($vlog_filter === 'logged') {
    $vlog_where = "WHERE visitor_username IS NOT NULL";
} elseif ($vlog_filter === 'guest') {
    $vlog_where = "WHERE visitor_username IS NULL";
}

// 处理删除访问记录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_visit_logs'])) {
    $db->exec("DELETE FROM visit_stats");
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tab=visit#visit');
    exit;
}

$vlog_total = $db->prepare("SELECT COUNT(*) FROM visit_stats $vlog_where");
$vlog_total->execute($vlog_params);
$vlog_total_count = (int)$vlog_total->fetchColumn();
$vlog_total_pages = ceil($vlog_total_count / $vlog_per_page);

$vlog_list = [];
if ($vlog_total_count > 0) {
    $vlog_sql = "SELECT * FROM visit_stats $vlog_where ORDER BY visit_time DESC LIMIT $vlog_per_page OFFSET $vlog_offset";
    $vlog_stmt = $db->prepare($vlog_sql);
    $vlog_stmt->execute($vlog_params);
    $vlog_list = $vlog_stmt->fetchAll();
}

$vlog_today_count = (int)$db->query("SELECT COUNT(*) FROM visit_stats WHERE DATE(visit_time) = CURDATE()")->fetchColumn();
$vlog_logged_count = (int)$db->query("SELECT COUNT(*) FROM visit_stats WHERE visitor_username IS NOT NULL")->fetchColumn();
$page_title = '系统日志查看';
$extra_css = <<<'CSS'
.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,.02);
}
.card {
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}
.log-content {
    background-color: #1e1e1e;
    color: #d4d4d4;
    padding: 15px;
    border-radius: 5px;
    max-height: 600px;
    overflow-y: auto;
    font-family: Consolas, Monaco, 'Andale Mono', 'Ubuntu Mono', monospace;
    white-space: pre-wrap;
    word-wrap: break-word;
}
.nav-tabs .nav-link {
    border: none;
    color: #6c757d;
}
.nav-tabs .nav-link.active {
    color: #0d6efd;
    border-bottom: 2px solid #0d6efd;
    font-weight: bold;
}
CSS;
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-1">系统日志查看</h1>
                        <p class="text-muted mb-0">
                            查看服务器生成的各类系统日志文件。
                            <?php if (!$is_file): ?>
                                当前目录大小：<strong><?= formatBytes(getDirectorySize($target_path)) ?></strong>
                            <?php else: ?>
                                文件大小：<strong><?= formatBytes(filesize($target_path)) ?></strong>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <ul class="nav nav-tabs card-header-tabs" id="logTabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link <?= (!isset($_GET['tab']) || $_GET['tab'] !== 'visit') ? 'active' : '' ?>" data-bs-toggle="tab" href="#filelogs" role="tab">
                                    <i class="bi bi-journal-text"></i> 文件日志
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= (isset($_GET['tab']) && $_GET['tab'] === 'visit') ? 'active' : '' ?>" data-bs-toggle="tab" href="#visit" role="tab">
                                    <i class="bi bi-people"></i> 访问记录
                                    <?php if ($vlog_logged_count > 0): ?>
                                    <span class="badge bg-success ms-1"><?= $vlog_logged_count ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content" id="logTabsContent">
                            <!-- 文件日志 -->
                            <div class="tab-pane fade <?= (!isset($_GET['tab']) || $_GET['tab'] !== 'visit') ? 'show active' : '' ?>" id="filelogs" role="tabpanel">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-3">
                                <li class="breadcrumb-item"><a href="?path=">logs</a></li>
                                <?php
                                if ($current_path) {
                                    $parts = explode('/', $current_path);
                                    $build_path = '';
                                    foreach ($parts as $i => $part) {
                                        $build_path .= ($i > 0 ? '/' : '') . $part;
                                        if ($i === count($parts) - 1) {
                                            echo '<li class="breadcrumb-item active">' . e($part) . '</li>';
                                        } else {
                                            echo '<li class="breadcrumb-item"><a href="?path=' . urlencode($build_path) . '">' . e($part) . '</a></li>';
                                        }
                                    }
                                }
                                ?>
                            </ol>
                        </nav>
                        <?php if ($is_file): ?>
                            <div class="mb-3">
                                <a href="?path=<?= urlencode(dirname($current_path) === '.' || dirname($current_path) === '\\' || dirname($current_path) === '/' ? '' : dirname($current_path)) ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-arrow-left"></i> 返回目录
                                </a>
                            </div>
                            <pre class="log-content"><?= htmlspecialchars($file_content) ?></pre>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-4" style="width: 50%;">名称</th>
                                            <th>大小</th>
                                            <th>修改时间</th>
                                            <th class="text-end pe-4">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_merge($dirs, $files) as $item): ?>
                                        <tr>
                                            <td class="ps-4">
                                                <a href="?path=<?= urlencode($item['path']) ?>" class="text-decoration-none text-dark">
                                                    <?php if ($item['type'] === 'dir'): ?>
                                                        <i class="bi bi-folder-fill text-warning me-2"></i>
                                                    <?php else: ?>
                                                        <i class="bi bi-file-text-fill text-secondary me-2"></i>
                                                    <?php endif; ?>
                                                    <?= e($item['name']) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <?= isset($item['size']) ? formatBytes($item['size']) : '-' ?>
                                            </td>
                                            <td>
                                                <?= isset($item['mtime']) ? date('Y-m-d H:i:s', $item['mtime']) : '-' ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <?php if ($item['type'] === 'file'): ?>
                                                <a href="?path=<?= urlencode($item['path']) ?>" class="btn btn-sm btn-light text-primary">
                                                    <i class="bi bi-eye"></i> 查看
                                                </a>
                                                <?php else: ?>
                                                <a href="?path=<?= urlencode($item['path']) ?>" class="btn btn-sm btn-light text-primary">
                                                    <i class="bi bi-folder2-open"></i> 打开
                                                </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if (empty($dirs) && empty($files)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-5 text-muted">
                                                目录为空
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                            </div>

                            <!-- 访问记录 -->
                            <div class="tab-pane fade <?= (isset($_GET['tab']) && $_GET['tab'] === 'visit') ? 'show active' : '' ?>" id="visit" role="tabpanel">
                                <!-- 统计 -->
                                <div class="row g-3 mb-4">
                                    <div class="col-6 col-md-3">
                                        <div class="card bg-light border-0 h-100">
                                            <div class="card-body text-center py-3">
                                                <div class="text-muted small">今日访问</div>
                                                <div class="fs-3 fw-bold text-primary"><?= number_format($vlog_today_count) ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="card bg-light border-0 h-100">
                                            <div class="card-body text-center py-3">
                                                <div class="text-muted small">累计访问</div>
                                                <div class="fs-3 fw-bold text-info"><?= number_format($vlog_total_count) ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="card bg-light border-0 h-100">
                                            <div class="card-body text-center py-3">
                                                <div class="text-muted small">登录用户访问</div>
                                                <div class="fs-3 fw-bold text-success"><?= number_format($vlog_logged_count) ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="card bg-light border-0 h-100">
                                            <div class="card-body text-center py-3">
                                                <div class="text-muted small">游客访问</div>
                                                <div class="fs-3 fw-bold text-secondary"><?= number_format($vlog_total_count - $vlog_logged_count) ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- 访问记录表 -->
                                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                    <?php
                                    $vlog_base = strtok($_SERVER['REQUEST_URI'], '?') . '?tab=visit&';
                                    ?>
                                    <div class="btn-group">
                                        <a href="<?= $vlog_base ?>vlog_filter=all&vlog_page=1" class="btn btn-sm <?= $vlog_filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">全部</a>
                                        <a href="<?= $vlog_base ?>vlog_filter=logged&vlog_page=1" class="btn btn-sm <?= $vlog_filter === 'logged' ? 'btn-success' : 'btn-outline-secondary' ?>">
                                            <i class="bi bi-person-check"></i> 登录用户
                                        </a>
                                        <a href="<?= $vlog_base ?>vlog_filter=guest&vlog_page=1" class="btn btn-sm <?= $vlog_filter === 'guest' ? 'btn-secondary' : 'btn-outline-secondary' ?>">
                                            <i class="bi bi-person"></i> 游客
                                        </a>
                                    </div>
                                    <form method="post" class="d-inline" onsubmit="return confirm('确定清空所有访问记录吗？此操作不可撤销。')">
                                        <button type="submit" name="clear_visit_logs" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i> 清空记录
                                        </button>
                                    </form>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm mb-0">
                                        <thead>
                                            <tr>
                                                <th>访客</th>
                                                <th>IP 地址</th>
                                                <th>访问页面</th>
                                                <th>时间</th>
                                                <th>User Agent</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($vlog_list as $row): ?>
                                            <tr class="<?= $row['visitor_username'] ? 'table-success bg-opacity-10' : '' ?>">
                                                <td>
                                                    <?php if ($row['visitor_username']): ?>
                                                    <span class="fw-bold text-success"><i class="bi bi-person-fill-check me-1"></i><?= e($row['visitor_username']) ?></span>
                                                    <?php if ($row['visitor_email']): ?>
                                                        <div class="small text-muted"><?= e($row['visitor_email']) ?></div>
                                                    <?php endif; ?>
                                                    <?php else: ?>
                                                    <span class="text-muted"><i class="bi bi-person me-1"></i>游客</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><code><?= e($row['ip_address']) ?></code></td>
                                                <td class="text-break" style="max-width:200px;">
                                                    <a href="<?= e($row['page_url']) ?>" target="_blank" class="text-decoration-none"><?= e($row['page_url'] ?: '/') ?></a>
                                                </td>
                                                <td class="small text-nowrap"><?= e($row['visit_time']) ?></td>
                                                <td class="small text-muted text-break" style="max-width:180px;" title="<?= e($row['user_agent']) ?>"><?= e(mb_substr($row['user_agent'], 0, 40)) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($vlog_list)): ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4">暂无访问记录</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if ($vlog_total_pages > 1): ?>
                                <div class="mt-3">
                                    <nav>
                                        <ul class="pagination pagination-sm justify-content-center mb-0">
                                            <?php if ($vlog_page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?= $vlog_base ?>vlog_filter=<?= $vlog_filter ?>&vlog_page=<?= $vlog_page - 1 ?>">上一页</a>
                                            </li>
                                            <?php endif; ?>
                                            <?php
                                            $vlog_start = max(1, $vlog_page - 2);
                                            $vlog_end = min($vlog_total_pages, $vlog_page + 2);
                                            for ($i = $vlog_start; $i <= $vlog_end; $i++):
                                            ?>
                                            <li class="page-item <?= $i === $vlog_page ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= $vlog_base ?>vlog_filter=<?= $vlog_filter ?>&vlog_page=<?= $i ?>"><?= $i ?></a>
                                            </li>
                                            <?php endfor; ?>
                                            <?php if ($vlog_page < $vlog_total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?= $vlog_base ?>vlog_filter=<?= $vlog_filter ?>&vlog_page=<?= $vlog_page + 1 ?>">下一页</a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        // 激活 URL 参数对应的 Tab
        var tabParam = new URLSearchParams(window.location.search).get('tab');
        if (tabParam === 'visit') {
            var triggerEl = document.querySelector('#logTabs a[href="#visit"]');
            if (triggerEl && !triggerEl.classList.contains('active')) {
                var tab = new bootstrap.Tab(triggerEl);
                tab.show();
            }
        }
        // 监听 Tab 切换，更新 URL 参数
        document.querySelectorAll('#logTabs a[data-bs-toggle="tab"]').forEach(function(tabEl) {
            tabEl.addEventListener('shown.bs.tab', function (event) {
                var target = event.target.getAttribute('href');
                var url = new URL(window.location);
                if (target === '#visit') {
                    url.searchParams.set('tab', 'visit');
                } else {
                    url.searchParams.delete('tab');
                }
                history.replaceState(null, null, url);
            });
        });
    </script>
<?php require_once 'includes/footer.php'; ?>