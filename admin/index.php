<?php
session_start();

// 如果未登录，重定向到登录页
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';

$page_title = '仪表盘';

$extra_css = <<<CSS
.card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
}
.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 15px rgba(0,0,0,0.1);
}
.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}
.bg-light-primary { background-color: rgba(13, 110, 253, 0.1); color: #0d6efd; }
.bg-light-success { background-color: rgba(25, 135, 84, 0.1); color: #198754; }
.bg-light-info { background-color: rgba(13, 202, 240, 0.1); color: #0dcaf0; }
.bg-light-warning { background-color: rgba(255, 193, 7, 0.1); color: #ffc107; }
.chart-container {
    position: relative;
    height: 300px;
    width: 100%;
}
.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,0.02);
}
CSS;

$head_scripts = '<script src="' . getResourceUrl('/assets/js/chart.min.js', 'https://cdn.staticfile.net/Chart.js/4.4.0/chart.umd.min.js') . '"></script>';

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h1 class="h2 text-gray-800">仪表盘</h1>
        <p class="text-muted">欢迎回来，这里是网站概览</p>
    </div>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button class="btn btn-sm btn-outline-primary me-2" onclick="refreshRealtimeData()">
            <i class="bi bi-arrow-clockwise"></i> 刷新数据
        </button>
    </div>
</div>
                
                <!-- 统计卡片（异步加载） -->
                <div class="row g-4 mb-4" id="dashboardStatsCards">
                    <div class="col-xl-3 col-md-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h6 class="text-muted text-uppercase mb-1">总访问量</h6>
                                        <h3 class="fw-bold mb-0"><span id="statTotalVisits" class="placeholder-glow"><span class="placeholder col-6"></span></span></h3>
                                    </div>
                                    <div class="stat-icon bg-light-primary">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center text-sm">
                                    <span class="text-success me-2"><i class="bi bi-arrow-up-short"></i> +<span id="statTodayVisits">-</span></span>
                                    <span class="text-muted">今日新增</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h6 class="text-muted text-uppercase mb-1">独立访客</h6>
                                        <h3 class="fw-bold mb-0"><span id="statUniqueVisitors" class="placeholder-glow"><span class="placeholder col-6"></span></span></h3>
                                    </div>
                                    <div class="stat-icon bg-light-success">
                                        <i class="bi bi-people"></i>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center text-sm">
                                    <span class="text-success me-2"><i class="bi bi-arrow-up-short"></i> +<span id="statTodayUnique">-</span></span>
                                    <span class="text-muted">今日新增</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h6 class="text-muted text-uppercase mb-1">内容发布</h6>
                                        <h3 class="fw-bold mb-0"><span id="statTotalPosts" class="placeholder-glow"><span class="placeholder col-4"></span></span></h3>
                                    </div>
                                    <div class="stat-icon bg-light-info">
                                        <i class="bi bi-file-text"></i>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center text-sm">
                                    <span class="text-muted">共 <span id="statTotalComments">-</span> 条评论</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h6 class="text-muted text-uppercase mb-1">表单提交</h6>
                                        <h3 class="fw-bold mb-0"><span id="statTotalForms" class="placeholder-glow"><span class="placeholder col-4"></span></span></h3>
                                    </div>
                                    <div class="stat-icon bg-light-warning">
                                        <i class="bi bi-file-earmark-text"></i>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center text-sm" id="statPendingInfo">
                                    <span class="text-muted">加载中...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 实时访问监控 -->
                <div class="card mb-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-primary"><i class="bi bi-activity me-2"></i>实时访客</h6>
                        <div>
                            <button class="btn btn-sm btn-outline-secondary me-1" onclick="clearCacheAndRefresh()">
                                <i class="bi bi-trash"></i> 清理缓存
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="realtimeVisits" class="row g-3">
                            <!-- 实时数据将通过JavaScript加载 -->
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <!-- 访问趋势图表 -->
                    <div class="col-lg-8 mb-4 mb-lg-0">
                        <div class="card h-100">
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                <h6 class="m-0 fw-bold text-primary">流量趋势 (7天)</h6>
                                <a href="stats.php" class="btn btn-sm btn-link text-decoration-none">查看详情 &rarr;</a>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="visitTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 热门页面 -->
                    <div class="col-lg-4">
                        <div class="card h-100">
                            <div class="card-header bg-white py-3">
                                <h6 class="m-0 fw-bold text-primary">热门页面</h6>
                            </div>
                            <div class="card-body p-0">
                                <div id="popularPagesList" class="list-group list-group-flush">
                                    <div class="text-center py-5 text-muted">
                                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                        加载中…
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

<script>
    let visitTrendChart = null;

    // ========== 仪表盘统计卡片（异步加载） ==========
    function loadDashboardStats() {
        fetch('./api/dashboard_stats.php')
            .then(res => res.json())
            .then(data => {
                document.getElementById('statTotalVisits').textContent = Number(data.totalVisits || 0).toLocaleString();
                document.getElementById('statTodayVisits').textContent = Number(data.todayVisits || 0).toLocaleString();
                document.getElementById('statUniqueVisitors').textContent = Number(data.uniqueVisitors || 0).toLocaleString();
                document.getElementById('statTodayUnique').textContent = Number(data.todayUnique || 0).toLocaleString();
                document.getElementById('statTotalPosts').textContent = Number(data.totalPosts || 0).toLocaleString();
                document.getElementById('statTotalComments').textContent = Number(data.totalComments || 0).toLocaleString();
                document.getElementById('statTotalForms').textContent = Number(data.totalForms || 0).toLocaleString();

                const pendingInfo = document.getElementById('statPendingInfo');
                const pending = Number(data.pendingForms || 0);
                if (pending > 0) {
                    pendingInfo.innerHTML = `<span class="text-danger fw-bold me-2"><i class="bi bi-exclamation-circle"></i> ${pending.toLocaleString()}</span><span class="text-muted">待审核</span>`;
                } else {
                    pendingInfo.innerHTML = `<span class="text-success me-2"><i class="bi bi-check-circle"></i></span><span class="text-muted">无待办</span>`;
                }
            })
            .catch(err => console.error('Dashboard stats fetch error:', err));
    }

    // ========== 流量趋势图表（异步加载） ==========
    function loadVisitTrends() {
        if (typeof Chart === 'undefined') return;
        fetch('./api/visit_trends.php')
            .then(res => res.json())
            .then(data => {
                if (data.error) {
                    console.error('Visit trends error:', data.error);
                    return;
                }
                const ctx = document.getElementById('visitTrendChart').getContext('2d');
                if (visitTrendChart) visitTrendChart.destroy();
                visitTrendChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [
                            {
                                label: '总访问量',
                                data: data.datasets.total_visits,
                                borderColor: '#0d6efd',
                                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                                fill: true,
                                tension: 0.4
                            },
                            {
                                label: '独立访客',
                                data: data.datasets.unique_visitors,
                                borderColor: '#198754',
                                backgroundColor: 'rgba(25, 135, 84, 0.1)',
                                fill: true,
                                tension: 0.4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: { borderDash: [2, 2] }
                            },
                            x: {
                                grid: { display: false }
                            }
                        }
                    }
                });
            })
            .catch(err => console.error('Visit trends fetch error:', err));
    }

    // ========== 热门页面（异步加载） ==========
    function loadPopularPages() {
        fetch('./api/popular_pages.php')
            .then(res => res.json())
            .then(data => {
                const container = document.getElementById('popularPagesList');
                if (data.error) {
                    container.innerHTML = '<div class="text-center py-3 text-danger">加载失败</div>';
                    return;
                }
                if (!data.pages || data.pages.length === 0) {
                    container.innerHTML = '<div class="text-center py-4 text-muted">暂无数据</div>';
                    return;
                }
                let html = '';
                data.pages.forEach(p => {
                    html += `
                        <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-4 py-3">
                            <div class="d-flex align-items-center overflow-hidden">
                                <span class="badge bg-light text-dark me-3">${p.rank}</span>
                                <div class="text-truncate">
                                    <div class="fw-bold text-truncate" style="max-width: 150px;" title="${escapeHtml(p.page_url)}">
                                        ${escapeHtml(p.display_url)}
                                    </div>
                                    <small class="text-muted">${p.unique_visitors.toLocaleString()} UV</small>
                                </div>
                            </div>
                            <span class="badge bg-primary rounded-pill">${p.visits.toLocaleString()}</span>
                        </div>`;
                });
                container.innerHTML = html;
            })
            .catch(err => {
                document.getElementById('popularPagesList').innerHTML = '<div class="text-center py-3 text-danger">加载失败</div>';
                console.error('Popular pages fetch error:', err);
            });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // 格式化URL显示，确保 vendor 前缀可见
    function formatUrlDisplay(url) {
        if (!url) return '/';
        // 如果是 vendor 目录，提取显示关键部分（保留查询参数）
        if (url.startsWith('/vendor/')) {
            // 解析路径和查询参数
            const urlObj = new URL(url, window.location.origin);
            const pathname = urlObj.pathname;
            const search = urlObj.search;
            // 显示 /vendor/xxx.php?key=value（简化显示）
            return pathname + (search ? '?' + search.substring(1, 50) : '');
        }
        return url;
    }

    // 实时访问监控
    function refreshRealtimeData() {
        const container = document.getElementById('realtimeVisits');
        container.innerHTML = '<div class="col-12 text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">正在加载实时数据...</p></div>';
        
        fetch('./api/realtime_visits.php')
            .then(response => {
                if (!response.ok) throw new Error('网络请求失败');
                return response.json();
            })
            .then(data => {
                container.innerHTML = '';
                
                if (data.error) {
                    container.innerHTML = `<div class="col-12 alert alert-danger">${data.error}</div>`;
                    return;
                }
                
                // 在线人数概览
                const statsHtml = `
                    <div class="col-12 mb-3">
                        <div class="d-flex align-items-center p-3 bg-light rounded">
                            <div class="me-4">
                                <small class="text-muted d-block">当前在线</small>
                                <span class="h4 fw-bold text-success">${data.online_count || 0}</span>
                            </div>
                            <div>
                                <small class="text-muted d-block">今日访问</small>
                                <span class="h4 fw-bold text-primary">${data.total_today || 0}</span>
                            </div>
                        </div>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', statsHtml);

                if (!data.visits || data.visits.length === 0) {
                    container.insertAdjacentHTML('beforeend', '<div class="col-12 text-center text-muted py-3">暂无活跃访客</div>');
                    return;
                }
                
                data.visits.forEach(visit => {
                    const statusClass = visit.status === 'online' ? 'bg-success' : 'bg-secondary';
                    const locationStr = visit.location ? (visit.location.location || '未知') : '未知';
                    const countryStr = visit.location ? (visit.location.country || '') : '';
                    
                    const userAgent = visit.user_agent || '';
                    const isMobile = /Mobile|Android|iPhone|iPad|iPod|Windows Phone/i.test(userAgent);
                    const deviceIcon = isMobile ? 'bi-phone' : 'bi-laptop';
                    
                    const isLoggedInUser = visit.visitor_username || visit.visitor_email;
                    const userInfoHtml = isLoggedInUser ? `
                                    <div class="mb-2 d-flex align-items-center">
                                        <i class="bi bi-person-check-fill text-success me-2"></i>
                                        <span class="fw-bold text-success">${visit.visitor_username || ''}</span>
                                        ${visit.visitor_email ? `<small class="text-muted ms-2">${visit.visitor_email}</small>` : ''}
                                    </div>` : '';
                    
                    const visitCard = `
                        <div class="col-md-6 col-lg-4 col-xl-3 mb-4">
                            <div class="card h-100 border-0 shadow-sm rounded-4 position-relative overflow-hidden ${isLoggedInUser ? 'border-start border-3 border-success' : ''}" style="background: #fff;">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="badge ${statusClass} rounded-pill px-3 py-2 fw-normal d-flex align-items-center">
                                            <i class="bi bi-circle-fill me-2" style="font-size: 0.5rem;"></i>
                                            ${visit.status === 'online' ? '在线' : '离线'}
                                            ${isLoggedInUser ? '<i class="bi bi-person-fill ms-2"></i>' : ''}
                                        </span>
                                        <small class="text-muted" style="font-size: 0.9rem;">${visit.time_ago}</small>
                                    </div>

                                    ${userInfoHtml}

                                    <div class="mb-2 d-flex align-items-center">
                                        <i class="bi bi-hdd-network text-primary me-2"></i>
                                        <span class="fs-5 fw-bold text-primary font-monospace">${visit.ip_address}</span>
                                    </div>

                                    <div class="mb-2 text-muted d-flex align-items-center">
                                        <i class="bi bi-geo-alt me-2 text-danger"></i>
                                        <span class="text-truncate" title="${locationStr}">${locationStr}</span>
                                    </div>

                                    ${countryStr && countryStr !== '未知' ? `
                                    <div class="mb-2 text-muted d-flex align-items-center">
                                        <i class="bi bi-flag me-2 text-success"></i>
                                        <span>${countryStr}</span>
                                    </div>` : ''}

                                    <div class="mb-2 text-muted d-flex align-items-center">
                                        <i class="bi bi-globe2 me-2 text-info"></i>
                                        <span class="text-truncate" title="${visit.page_url}">
                                            <a href="${visit.page_url}" target="_blank" class="text-decoration-none text-muted">${formatUrlDisplay(visit.page_url)}</a>
                                        </span>
                                    </div>

                                    <hr class="my-3 opacity-10">

                                    <div class="text-muted small d-flex align-items-center" style="max-width: 85%;">
                                        <i class="bi ${deviceIcon} me-2"></i>
                                        <span class="text-truncate" title="${userAgent || '未知设备'}">${userAgent || '未知设备'}</span>
                                    </div>
                                </div>
                                
                                <div class="position-absolute bottom-0 end-0 opacity-25" style="pointer-events: none; margin-bottom: -10px; margin-right: -10px;">
                                    <i class="bi bi-globe text-primary" style="font-size: 4rem; transform: rotate(-15deg); display: block;"></i>
                                </div>
                            </div>
                        </div>
                    `;
                    container.insertAdjacentHTML('beforeend', visitCard);
                });
            })
            .catch(error => {
                console.error('Error:', error);
                container.innerHTML = `<div class="col-12 alert alert-danger">加载失败: ${error.message}</div>`;
            });
    }

    // 生成测试数据
    function generateTestVisit() {
        fetch('./api/test_visits.php?create_test=1')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const toast = document.createElement('div');
                    toast.className = 'position-fixed bottom-0 end-0 p-3';
                    toast.style.zIndex = '11';
                    toast.innerHTML = `
                        <div class="toast show align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
                            <div class="d-flex">
                                <div class="toast-body">
                                    <i class="bi bi-check-circle me-2"></i> 测试数据已生成
                                </div>
                                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close" onclick="this.closest('.toast').remove()"></button>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(toast);
                    setTimeout(() => toast.remove(), 3000);
                    refreshRealtimeData();
                }
            });
    }

    // 清理缓存
    function clearCacheAndRefresh() {
        if (confirm('确定要清理IP地理位置缓存吗？')) {
            fetch('./api/realtime_visits.php?clear_cache=true')
                .then(res => res.json())
                .then(data => {
                    alert(data.message || '清理完成');
                    refreshRealtimeData();
                });
        }
    }

    // 初始化
    document.addEventListener('DOMContentLoaded', function() {
        loadDashboardStats();
        refreshRealtimeData();
        loadVisitTrends();
        loadPopularPages();
        setInterval(refreshRealtimeData, 30000); // 30秒自动刷新
    });
</script>
<?php
$skip_bootstrap_js = true; // index.php 无需 Bootstrap JS（无模态框/下拉等组件）
require_once 'includes/footer.php';
?>
