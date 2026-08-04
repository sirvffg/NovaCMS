<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
requireLogin();

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 参数处理
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
$startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

// --- 数据获取 ---

// 1. 基础统计
$overview = $db->query("
    SELECT 
        COUNT(*) as total_visits,
        COUNT(DISTINCT ip_address) as unique_visitors,
        COUNT(DISTINCT page_url) as unique_pages,
        COUNT(DISTINCT country) as unique_countries
    FROM visit_stats 
    WHERE visit_time >= '$startDate'
")->fetch();

// 2. 访问趋势 (用于图表)
$trendData = $db->query("
    SELECT 
        DATE(visit_time) as date, 
        COUNT(*) as visits, 
        COUNT(DISTINCT ip_address) as unique_visitors
    FROM visit_stats 
    WHERE visit_time >= '$startDate'
    GROUP BY DATE(visit_time) 
    ORDER BY date ASC
")->fetchAll();

// 3. 浏览器和操作系统统计
$userAgents = $db->query("
    SELECT user_agent 
    FROM visit_stats 
    WHERE visit_time >= '$startDate'
")->fetchAll(PDO::FETCH_COLUMN);

$browserStats = [];
$osStats = [];

foreach ($userAgents as $ua) {
    $browser = '其他';
    $os = '其他';
    
    // 简单解析逻辑
    if (strpos($ua, 'MSIE') !== false || strpos($ua, 'Trident') !== false) $browser = 'IE';
    elseif (strpos($ua, 'Edge') !== false) $browser = 'Edge';
    elseif (strpos($ua, 'Firefox') !== false) $browser = 'Firefox';
    elseif (strpos($ua, 'Chrome') !== false) $browser = 'Chrome';
    elseif (strpos($ua, 'Safari') !== false) $browser = 'Safari';
    elseif (strpos($ua, 'Opera') !== false) $browser = 'Opera';
    
    if (strpos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (strpos($ua, 'Macintosh') !== false || strpos($ua, 'Mac OS X') !== false) $os = 'macOS';
    elseif (strpos($ua, 'Android') !== false) $os = 'Android';
    elseif (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) $os = 'iOS';
    elseif (strpos($ua, 'Linux') !== false) $os = 'Linux';
    
    $browserStats[$browser] = ($browserStats[$browser] ?? 0) + 1;
    $osStats[$os] = ($osStats[$os] ?? 0) + 1;
}
arsort($browserStats);
arsort($osStats);

// 4. 热门页面
$popularPages = $db->query("
    SELECT page_url, COUNT(*) as visits, COUNT(DISTINCT ip_address) as unique_visitors
    FROM visit_stats 
    WHERE visit_time >= '$startDate'
    GROUP BY page_url 
    ORDER BY visits DESC 
    LIMIT 10
")->fetchAll();

// 5. 地理分布 (用于列表)
$geoList = $db->query("
    SELECT 
        country,
        province,
        city,
        COUNT(*) as visits
    FROM visit_stats 
    WHERE visit_time >= '$startDate'
    AND country IS NOT NULL AND country != ''
    GROUP BY country, province, city
    ORDER BY visits DESC
    LIMIT 100
")->fetchAll();

// 6. 详细访问记录
$recentVisits = $db->query("
    SELECT *
    FROM visit_stats 
    WHERE visit_time >= '$startDate'
    ORDER BY visit_time DESC 
    LIMIT 1000
")->fetchAll();

$page_title = '访问统计';
$extra_css = <<<'CSS'
.stat-card {
    transition: transform 0.2s;
    border: none;
    border-radius: 10px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
}
.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 15px rgba(0,0,0,0.1);
}
.card-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}
.bg-gradient-primary { background: linear-gradient(45deg, #4e73df, #224abe); }
.bg-gradient-success { background: linear-gradient(45deg, #1cc88a, #13855c); }
.bg-gradient-info { background: linear-gradient(45deg, #36b9cc, #258391); }
.bg-gradient-warning { background: linear-gradient(45deg, #f6c23e, #dda20a); }

.chart-container { position: relative; height: 300px; }

.geo-loading { display: none; position: fixed; top: 1rem; right: 1rem; z-index: 9999; }
CSS;
$extra_scripts = '<link rel="stylesheet" href="/assets/css/dataTables.bootstrap5.min.css">';
require_once 'includes/header.php'; ?>

                <!-- 顶部控制栏 -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <h1 class="h2 text-gray-800">访问统计</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="?days=7" class="btn btn-sm btn-outline-secondary <?= $days == 7 ? 'active' : '' ?>">最近7天</a>
                            <a href="?days=30" class="btn btn-sm btn-outline-secondary <?= $days == 30 ? 'active' : '' ?>">最近30天</a>
                            <a href="?days=90" class="btn btn-sm btn-outline-secondary <?= $days == 90 ? 'active' : '' ?>">最近90天</a>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-primary dropdown-toggle" type="button" id="geoActionDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear-fill"></i> 数据维护
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="geoActionDropdown" style="position: absolute; right: 0;">
                                <li><a class="dropdown-item" href="#" onclick="refreshGeoData()"><i class="bi bi-lightning-charge"></i> 更新IP归属地</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- 统计卡片 -->
                <div class="row g-4 mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">总访问量</div>
                                        <div class="h4 mb-0 font-weight-bold text-gray-800"><?= number_format($overview['total_visits']) ?></div>
                                    </div>
                                    <div class="card-icon bg-gradient-primary text-white">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">独立访客 (UV)</div>
                                        <div class="h4 mb-0 font-weight-bold text-gray-800"><?= number_format($overview['unique_visitors']) ?></div>
                                    </div>
                                    <div class="card-icon bg-gradient-success text-white">
                                        <i class="bi bi-people"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">受访页面</div>
                                        <div class="h4 mb-0 font-weight-bold text-gray-800"><?= number_format($overview['unique_pages']) ?></div>
                                    </div>
                                    <div class="card-icon bg-gradient-info text-white">
                                        <i class="bi bi-file-earmark-text"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">覆盖国家/地区</div>
                                        <div class="h4 mb-0 font-weight-bold text-gray-800"><?= number_format($overview['unique_countries']) ?></div>
                                    </div>
                                    <div class="card-icon bg-gradient-warning text-white">
                                        <i class="bi bi-globe"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 图表区域 -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-graph-up"></i> 访问趋势</h6>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-lg-4 mb-4 mb-lg-0">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-white py-3">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-browser-chrome"></i> 浏览器分布</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="browserChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 mb-4 mb-lg-0">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-white py-3">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-laptop"></i> 操作系统分布</h6>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="osChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-white py-3">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-list-ol"></i> 地域排行 TOP 10</h6>
                            </div>
                            <div class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
                                <div class="list-group list-group-flush">
                                    <?php 
                                    $topGeo = array_slice($geoList, 0, 10);
                                    foreach($topGeo as $index => $geo): 
                                    ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-light text-dark me-2"><?= $index + 1 ?></span>
                                            <?= e($geo['country']) ?> <?= e($geo['province']) ?>
                                        </div>
                                        <span class="badge bg-primary rounded-pill"><?= $geo['visits'] ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if(empty($topGeo)): ?>
                                    <div class="list-group-item text-center text-muted">暂无数据</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 热门页面表格 -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-star"></i> 热门页面 TOP 10</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>排名</th>
                                        <th>页面 URL</th>
                                        <th>访问次数</th>
                                        <th>独立访客</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($popularPages as $index => $page): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td class="text-truncate" style="max-width: 300px;" title="<?= e($page['page_url']) ?>">
                                            <?= e($page['page_url']) ?>
                                        </td>
                                        <td><?= number_format($page['visits']) ?></td>
                                        <td><?= number_format($page['unique_visitors']) ?></td>
                                        <td>
                                            <a href="<?= e($page['page_url']) ?>" target="_blank" class="btn btn-sm btn-outline-info">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 详细访问记录表格 -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-table"></i> 详细访问记录 (最近1000条)</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="visitsTable" class="table table-striped table-hover w-100">
                                <thead>
                                    <tr>
                                        <th>时间</th>
                                        <th>IP 地址</th>
                                        <th>用户</th>
                                        <th>位置</th>
                                        <th>页面</th>
                                        <th>来源</th>
                                        <th>设备</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recentVisits as $visit): ?>
                                    <tr>
                                        <td data-sort="<?= strtotime($visit['visit_time']) ?>">
                                            <?= date('m-d H:i', strtotime($visit['visit_time'])) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border ip-copy" role="button" onclick="copyToClipboard('<?= e($visit['ip_address']) ?>')" title="点击复制">
                                                <?= e($visit['ip_address']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($visit['visitor_username'])): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                <i class="bi bi-person-fill me-1"></i><?= e($visit['visitor_username']) ?>
                                            </span>
                                            <?php if (!empty($visit['visitor_email'])): ?>
                                            <br><small class="text-muted"><?= e($visit['visitor_email']) ?></small>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php
                                                    $locParts = [];
                                                    if (!empty($visit['country'])) $locParts[] = e($visit['country']);
                                                    if (!empty($visit['province'])) $locParts[] = e($visit['province']);
                                                    if (!empty($visit['city'])) $locParts[] = e($visit['city']);
                                                    echo implode(' ', $locParts) ?: '-';
                                                ?>
                                            </small>
                                        </td>
                                        <td class="text-truncate" style="max-width: 200px;">
                                            <a href="<?= e($visit['page_url']) ?>" target="_blank" class="text-decoration-none">
                                                <?= e($visit['page_url']) ?>
                                            </a>
                                        </td>
                                        <td class="text-truncate" style="max-width: 150px;">
                                            <?= $visit['referer'] ? e(parse_url($visit['referer'], PHP_URL_HOST) ?? '直接访问') : '直接访问' ?>
                                        </td>
                                        <td>
                                            <?php
                                                $ua = $visit['user_agent'];
                                                $icon = 'bi-question-circle';
                                                if(strpos($ua, 'Windows')!==false) $icon = 'bi-windows';
                                                elseif(strpos($ua, 'Android')!==false) $icon = 'bi-android2';
                                                elseif(strpos($ua, 'iPhone')!==false || strpos($ua, 'Mac')!==false) $icon = 'bi-apple';
                                            ?>
                                            <i class="bi <?= $icon ?>" title="<?= e($ua) ?>"></i>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>


    <!-- 提示框 -->
    <div class="geo-loading alert alert-primary shadow">
        <div class="d-flex align-items-center">
            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
            <span id="loadingText">正在处理...</span>
        </div>
    </div>

    <!-- 更新进度模态框 -->
    <div class="modal fade" id="updateLogModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-lightning-charge"></i> IP归属地更新</h5>
                    <button type="button" class="btn-close" onclick="stopUpdate()" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span id="progressText">正在初始化...</span>
                            <span id="progressPercent">0%</span>
                        </div>
                        <div class="progress">
                            <div id="updateProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                        </div>
                    </div>
                    
                    <div class="card bg-light">
                        <div class="card-header py-1 small fw-bold">实时日志</div>
                        <div class="card-body p-0">
                            <div id="updateLogContainer" style="height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;" class="p-2">
                                <!-- 日志内容 -->
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="stopUpdate()">停止更新</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 脚本库 -->
    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= getResourceUrl('/assets/js/jquery-3.7.0.min.js', 'https://cdn.bootcdn.net/ajax/libs/jquery/3.7.0/jquery.min.js') ?>"></script>
    <script src="<?= getResourceUrl('/assets/js/jquery.dataTables.min.js', 'https://cdn.bootcdn.net/ajax/libs/datatables.net/1.13.4/jquery.dataTables.min.js') ?>"></script>
    <script src="<?= getResourceUrl('/assets/js/dataTables.bootstrap5.min.js', 'https://cdn.bootcdn.net/ajax/libs/datatables.net-bs5/1.13.4/dataTables.bootstrap5.min.js') ?>"></script>
    <script src="<?= getResourceUrl('/assets/js/chart.min.js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js') ?>"></script>

    <script>
        // 兜底：手动下拉切换，不依赖 Bootstrap JS
        (function() {
            const dropdownBtn = document.getElementById('geoActionDropdown');
            const dropdownMenu = dropdownBtn ? dropdownBtn.nextElementSibling : null;
            if (dropdownBtn && dropdownMenu) {
                dropdownBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isOpen = dropdownMenu.classList.contains('show');
                    dropdownMenu.classList.toggle('show', !isOpen);
                    dropdownBtn.setAttribute('aria-expanded', !isOpen);
                    // 确保 Bootstrap 定位生效（如果已加载）
                    if (typeof bootstrap !== 'undefined' && window.bootstrap.Dropdown) {
                        const bsDropdown = bootstrap.Dropdown.getInstance(dropdownBtn);
                        if (bsDropdown) {
                            if (!isOpen) bsDropdown.show(); else bsDropdown.hide();
                            return;
                        }
                    }
                });
                document.addEventListener('click', function() {
                    dropdownMenu.classList.remove('show');
                    dropdownBtn.setAttribute('aria-expanded', 'false');
                });
            }
        })();

        // 复制 IP 功能
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                // 可以添加一个小提示
            });
        }

        // --- 图表数据准备 ---
        const trendData = <?= json_encode($trendData ?: []) ?>;
        const browserStats = <?= json_encode($browserStats ?: []) ?>;
        const osStats = <?= json_encode($osStats ?: []) ?>;

        // 所有初始化和图表创建放在 DOM 就绪后执行
        $(document).ready(function() {
            // 初始化 DataTables
            $('#visitsTable').DataTable({
                "order": [[ 0, "desc" ]],
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/zh.json"
                },
                "pageLength": 25
            });

            // 初始化所有图表
            initCharts();
        });

        function initCharts() {
            if (typeof Chart === 'undefined') {
                console.error('Chart.js 未加载，图表无法显示');
                return;
            }

            // 1. 访问趋势图
            const trendCanvas = document.getElementById('trendChart');
            if (trendCanvas && trendData.length > 0) {
                new Chart(trendCanvas, {
                    type: 'line',
                    data: {
                        labels: trendData.map(d => d.date),
                        datasets: [{
                            label: '访问量 (PV)',
                            data: trendData.map(d => d.visits),
                            borderColor: '#4e73df',
                            backgroundColor: 'rgba(78, 115, 223, 0.1)',
                            fill: true,
                            tension: 0.4
                        }, {
                            label: '独立访客 (UV)',
                            data: trendData.map(d => d.unique_visitors),
                            borderColor: '#1cc88a',
                            backgroundColor: 'rgba(28, 200, 138, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { position: 'top' } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            } else if (trendCanvas) {
                trendCanvas.parentElement.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100 text-muted"><i class="bi bi-inbox me-2"></i>暂无访问数据</div>';
            }

            // 2. 浏览器分布图
            const browserCanvas = document.getElementById('browserChart');
            if (browserCanvas && Object.keys(browserStats).length > 0) {
                new Chart(browserCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: Object.keys(browserStats),
                        datasets: [{
                            data: Object.values(browserStats),
                            backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796', '#5a5c69']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'right' } }
                    }
                });
            } else if (browserCanvas) {
                browserCanvas.parentElement.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100 text-muted"><i class="bi bi-inbox me-2"></i>暂无数据</div>';
            }

            // 3. 操作系统分布图
            const osCanvas = document.getElementById('osChart');
            if (osCanvas && Object.keys(osStats).length > 0) {
                new Chart(osCanvas, {
                    type: 'pie',
                    data: {
                        labels: Object.keys(osStats),
                        datasets: [{
                            data: Object.values(osStats),
                            backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'right' } }
                    }
                });
            } else if (osCanvas) {
                osCanvas.parentElement.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100 text-muted"><i class="bi bi-inbox me-2"></i>暂无数据</div>';
            }
        }

        // --- 数据维护功能 (SSE 实时流) ---
        let isUpdating = false;
        let shouldStop = false;
        let updateModal = null;
        let abortController = null;

        function refreshGeoData(e) {
            if (e) e.preventDefault();
            if (isUpdating) return;
            
            // 初始化模态框
            if (!updateModal) {
                const modalEl = document.getElementById('updateLogModal');
                if (!modalEl || typeof bootstrap === 'undefined') {
                    alert('模态框初始化失败，请检查 Bootstrap 是否加载');
                    return;
                }
                updateModal = new bootstrap.Modal(modalEl);
            }

            isUpdating  = true;
            shouldStop  = false;

            // 重置 UI
            $('#updateLogContainer').html('');
            $('#updateProgressBar').css('width', '0%').removeClass('bg-success').addClass('progress-bar-animated');
            $('#progressText').text('正在连接服务器...');
            $('#progressPercent').text('0%');

            updateModal.show();
            processUpdateQueue();
        }

        function stopUpdate() {
            if (isUpdating) {
                shouldStop = true;
                if (abortController) abortController.abort();
                addLog('系统', '正在停止...', 'warning');
            } else if (updateModal) {
                updateModal.hide();
            }
        }

        function addLog(ip, msg, type = 'info') {
            const time = new Date().toLocaleTimeString();
            const colors = { success: 'text-success', error: 'text-danger', warning: 'text-warning' };
            const color = colors[type] || 'text-dark';

            const html = `<div class="border-bottom border-light py-1">
                <span class="text-muted me-2">[${time}]</span>
                <span class="fw-bold me-2" style="min-width:130px;display:inline-block;">${ip}</span>
                <span class="${color}">${msg}</span>
            </div>`;

            const container = $('#updateLogContainer');
            container.append(html);
            container.scrollTop(container[0].scrollHeight);
        }

        async function processUpdateQueue() {
            if (shouldStop) {
                finishUpdate('用户已停止更新');
                return;
            }

            abortController = new AbortController();

            try {
                const res = await fetch('./api/update_ip_geo.php', {
                    signal: abortController.signal
                });

                if (!res.ok) {
                    throw new Error(`服务器错误: HTTP ${res.status}`);
                }

                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let currentEvent = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop() || '';

                    for (const line of lines) {
                        if (line.startsWith('event: ')) {
                            currentEvent = line.substring(7).trim();
                        } else if (line.startsWith('data: ') && currentEvent) {
                            try {
                                const data = JSON.parse(line.substring(6));
                                handleSSEEvent(currentEvent, data);
                            } catch (e) {
                                console.warn('SSE 解析失败:', e, line);
                            }
                            currentEvent = '';
                        }
                    }
                }
            } catch (e) {
                if (e.name === 'AbortError') {
                    finishUpdate('用户已停止更新');
                } else {
                    addLog('系统', '连接错误: ' + e.message, 'error');
                    finishUpdate('发生错误');
                }
            }
        }

        function handleSSEEvent(event, data) {
            switch (event) {
                case 'init':
                    $('#progressText').text(`共 ${data.total} 个待更新 IP`);
                    $('#progressPercent').text('0%');
                    break;

                case 'log':
                    totalProcessed = data.index;
                    const percent = Math.round((data.index / data.total) * 100);
                    $('#updateProgressBar').css('width', percent + '%');
                    $('#progressText').text(`${data.index}/${data.total} 已处理`);
                    $('#progressPercent').text(percent + '%');

                    if (data.status === 'success') {
                        addLog(data.ip, data.message + (data.source ? ` [${data.source}]` : ''), 'success');
                    } else {
                        addLog(data.ip, `失败: ${data.message}`, 'error');
                    }
                    break;

                case 'done':
                    finishUpdate(data.message);
                    break;
            }
        }

        function finishUpdate(msg) {
            isUpdating = false;
            abortController = null;
            addLog('系统', msg, 'success');

            $('#updateProgressBar')
                .removeClass('progress-bar-animated')
                .addClass('bg-success');
            $('#progressText').text(msg);

            setTimeout(() => {
                if (updateModal) updateModal.hide();
                location.reload();
            }, 1500);
        }
    </script>
<?php require_once 'includes/footer.php'; ?>
