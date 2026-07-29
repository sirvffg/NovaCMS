<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

$page_title = '仪表盘';
$dashboardCssVersion = (string)(@filemtime(__DIR__ . '/../assets/css/admin-dashboard.css') ?: 1);
$dashboardJsVersion = (string)(@filemtime(__DIR__ . '/../assets/js/admin-dashboard.js') ?: 1);
$vueJsVersion = (string)(@filemtime(__DIR__ . '/../assets/js/vue.global.prod.js') ?: 1);
$head_scripts =
    '<link href="/assets/css/admin-dashboard.css?v=' . e($dashboardCssVersion) . '" rel="stylesheet">' .
    '<script src="' . getResourceUrl('/assets/js/chart.min.js', 'https://cdn.staticfile.net/Chart.js/4.4.0/chart.umd.min.js') . '"></script>' .
    '<script src="/assets/js/vue.global.prod.js?v=' . e($vueJsVersion) . '"></script>';

$dashboardConfig = [
    'endpoints' => [
        'stats' => '/admin/api/dashboard_stats.php',
        'trends' => '/admin/api/visit_trends.php',
        'popular' => '/admin/api/popular_pages.php',
        'realtime' => '/admin/api/realtime_visits.php',
    ],
    'csrfToken' => generateCSRFToken(),
    'refreshInterval' => 30000,
];

$dashboardConfigJson = json_encode(
    $dashboardConfig,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
$extra_scripts =
    '<script>window.NovaAdminDashboardConfig = ' . $dashboardConfigJson . ';</script>' .
    '<script src="/assets/js/admin-dashboard.js?v=' . e($dashboardJsVersion) . '"></script>';

require_once __DIR__ . '/includes/header.php';
?>

<div id="admin-dashboard-app" class="dashboard-app" v-cloak>
    <header class="admin-page-header">
        <div>
            <span class="admin-page-eyebrow"><i class="bi bi-stars" aria-hidden="true"></i> Overview</span>
            <h1>今天，从全局开始</h1>
            <p>集中查看内容、流量与待办状态，实时数据每 30 秒自动更新。</p>
        </div>
        <div class="admin-page-actions">
            <div class="d-none d-sm-flex flex-column align-items-end me-1">
                <span class="dashboard-status"><i class="dashboard-status-dot" aria-hidden="true"></i>系统运行正常</span>
                <span class="dashboard-refresh-time" v-if="lastUpdated">更新于 {{ lastUpdated }}</span>
            </div>
            <a class="btn btn-light" href="/admin/posts.php?action=add">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>新建文章
            </a>
            <button class="btn btn-primary" type="button" @click="refreshAll(true)" :disabled="refreshing">
                <i class="bi bi-arrow-clockwise me-1 dashboard-refresh-icon" :class="{ 'is-spinning': refreshing }" aria-hidden="true"></i>
                {{ refreshing ? '更新中' : '刷新数据' }}
            </button>
        </div>
    </header>

    <div v-if="globalError" class="alert alert-danger d-flex align-items-center justify-content-between gap-3" role="alert">
        <span><i class="bi bi-exclamation-circle me-2" aria-hidden="true"></i>{{ globalError }}</span>
        <button class="btn btn-sm btn-outline-danger" type="button" @click="refreshAll(true)">重新加载</button>
    </div>

    <section class="dashboard-grid-stats" aria-label="核心数据">
        <article
            v-for="card in statCards"
            :key="card.key"
            class="dashboard-stat-card"
            :style="{ '--stat-color': card.color }"
        >
            <div class="dashboard-stat-head">
                <div class="min-w-0">
                    <div class="dashboard-stat-label">{{ card.label }}</div>
                    <div v-if="statsLoading" class="dashboard-skeleton" aria-label="正在加载"></div>
                    <div v-else class="dashboard-stat-value">{{ formatNumber(card.value) }}</div>
                </div>
                <span class="dashboard-stat-icon" aria-hidden="true"><i class="bi" :class="card.icon"></i></span>
            </div>
            <div class="dashboard-stat-meta">
                <template v-if="!statsLoading">
                    <strong>{{ card.metaValue }}</strong>
                    <span>{{ card.metaLabel }}</span>
                </template>
            </div>
        </article>
    </section>

    <section class="dashboard-lower-grid" aria-label="实时状态与快捷操作">
        <article class="dashboard-panel">
            <header class="dashboard-panel-head">
                <div class="dashboard-panel-title">
                    <h2><i class="bi bi-broadcast" aria-hidden="true"></i>实时访客</h2>
                    <p>最近 15 分钟的站内活动</p>
                </div>
                <div class="dashboard-live-summary">
                    <span class="dashboard-live-metric"><i class="dashboard-live-dot" aria-hidden="true"></i>在线 <strong>{{ realtime.online_count }}</strong></span>
                    <span class="dashboard-live-metric">今日 <strong>{{ formatNumber(realtime.total_today) }}</strong></span>
                </div>
            </header>
            <div v-if="realtimeLoading" class="dashboard-empty"><span class="loading-spinner" aria-hidden="true"></span><p>正在读取访客状态…</p></div>
            <div v-else-if="realtimeError" class="dashboard-error"><i class="bi bi-wifi-off" aria-hidden="true"></i><p>{{ realtimeError }}</p></div>
            <div v-else-if="realtime.visits.length === 0" class="dashboard-empty"><i class="bi bi-person-slash" aria-hidden="true"></i><p>当前没有活跃访客</p></div>
            <div v-else class="dashboard-visitor-list">
                <div v-for="(visit, index) in visibleVisits" :key="visit.ip_address + '-' + visit.page_url + '-' + index" class="dashboard-visitor">
                    <span class="dashboard-device-icon" aria-hidden="true"><i class="bi" :class="deviceIcon(visit.user_agent)"></i></span>
                    <div class="dashboard-visitor-primary">
                        <strong>{{ visit.visitor_username || visit.ip_address || '匿名访客' }}</strong>
                        <small>{{ visit.visitor_email || (visit.visitor_username ? visit.ip_address : '访客') }}</small>
                    </div>
                    <div class="dashboard-visitor-secondary">
                        <strong>{{ locationLabel(visit.location) }}</strong>
                        <small>{{ compactUserAgent(visit.user_agent) }}</small>
                    </div>
                    <a class="dashboard-visitor-page" :href="safePageHref(visit.page_url)" target="_blank" rel="noopener" :title="visit.page_url">{{ displayPath(visit.page_url) }}</a>
                    <span class="dashboard-presence" :class="{ 'is-online': visit.status === 'online' }">
                        {{ visit.status === 'online' ? '在线' : visit.time_ago }}
                    </span>
                </div>
            </div>
        </article>

        <aside class="dashboard-panel">
            <header class="dashboard-panel-head">
                <div class="dashboard-panel-title">
                    <h2><i class="bi bi-lightning-charge" aria-hidden="true"></i>快捷操作</h2>
                    <p>常用管理入口</p>
                </div>
            </header>
            <div class="dashboard-quick-grid">
                <a v-for="action in quickActions" :key="action.href" class="dashboard-quick-link" :href="action.href">
                    <i class="bi" :class="action.icon" aria-hidden="true"></i>
                    <span>{{ action.label }}</span>
                </a>
            </div>
        </aside>
    </section>

    <section class="dashboard-main-grid" aria-label="流量概览">
        <article class="dashboard-panel">
            <header class="dashboard-panel-head">
                <div class="dashboard-panel-title">
                    <h2><i class="bi bi-graph-up-arrow" aria-hidden="true"></i>近 7 天流量趋势</h2>
                    <p>页面访问量与独立访客变化</p>
                </div>
                <div class="dashboard-legend" aria-label="图表图例">
                    <span><i style="--legend-color: var(--admin-primary)"></i>访问量</span>
                    <span><i style="--legend-color: var(--admin-success)"></i>独立访客</span>
                </div>
            </header>
            <div class="dashboard-chart-wrap">
                <canvas ref="trendChart" aria-label="近 7 天访问趋势图"></canvas>
            </div>
        </article>

        <article class="dashboard-panel">
            <header class="dashboard-panel-head">
                <div class="dashboard-panel-title">
                    <h2><i class="bi bi-fire" aria-hidden="true"></i>热门页面</h2>
                    <p>近 7 天访问排行</p>
                </div>
                <a class="dashboard-panel-link" href="/admin/stats.php">详情 <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </header>
            <div v-if="popularLoading" class="dashboard-empty"><span class="loading-spinner" aria-hidden="true"></span><p>正在读取排行…</p></div>
            <div v-else-if="popularError" class="dashboard-error"><i class="bi bi-cloud-slash" aria-hidden="true"></i><p>{{ popularError }}</p></div>
            <div v-else-if="popularPages.length === 0" class="dashboard-empty"><i class="bi bi-inbox" aria-hidden="true"></i><p>暂无页面访问数据</p></div>
            <ol v-else class="dashboard-popular-list">
                <li v-for="page in popularPages" :key="page.rank + '-' + page.page_url" class="dashboard-popular-item">
                    <span class="dashboard-popular-rank">{{ page.rank }}</span>
                    <div class="dashboard-popular-copy">
                        <a :href="safePageHref(page.page_url)" target="_blank" rel="noopener" :title="page.page_url">{{ page.display_url }}</a>
                        <small>{{ formatNumber(page.unique_visitors) }} 位访客</small>
                    </div>
                    <span class="dashboard-popular-value">{{ formatNumber(page.visits) }} PV</span>
                </li>
            </ol>
        </article>
    </section>
</div>

<noscript>
    <div class="alert alert-warning">仪表盘需要启用 JavaScript 才能显示实时数据，其他后台管理功能仍可正常使用。</div>
</noscript>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
