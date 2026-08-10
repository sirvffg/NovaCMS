<?php
/**
 * 插件管理页面
 * 扫描 vendor/nova-plugins/ 目录，读取 plugin.json 元数据，按插件 id 启用/禁用
 * 启用/禁用通过 AJAX（fetch）实现，无需页面刷新
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';

if (!defined('NOVA_API')) {
    define('NOVA_API', true);
}
require_once __DIR__ . '/../vendor/nova-json/class/plugin/class-plugin-registry.php';

// 确保 active_plugins 字段存在
$checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'active_plugins'");
if (!$checkStmt->fetch()) {
    $db->exec("ALTER TABLE website_config ADD COLUMN active_plugins TEXT NULL COMMENT '已启用的插件 id(JSON 数组)，NULL 表示全部启用' AFTER active_theme");
}

/**
 * 获取已启用的插件 id 列表
 * @return array|null 返回数组；若返回 null 表示未配置（全部启用）
 */
function get_active_plugins($db) {
    $stmt = $db->query("SELECT active_plugins FROM website_config LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (empty($row['active_plugins'])) {
        return null;
    }
    $decoded = json_decode($row['active_plugins'], true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * 保存已启用的插件 id 列表
 */
function save_active_plugins($db, array $ids) {
    $ids = array_values(array_unique($ids));
    $json = json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("UPDATE website_config SET active_plugins = ? WHERE id = 1");
    $stmt->execute([$json]);
}

// 扫描所有已安装插件（同时自动生成缺失的 id）
$plugins = Nova_Plugin_Registry::scan_all();

// 旧版数据迁移：若 active_plugins 中存在非 id 格式（不以 p_ 开头）的条目，
// 视为旧版基于 slug 的记录，自动转换为对应的 id
$activePluginIds = get_active_plugins($db);
if ($activePluginIds !== null) {
    $needsMigration = false;
    foreach ($activePluginIds as $entry) {
        if (!is_string($entry) || strpos($entry, 'p_') !== 0) {
            $needsMigration = true;
            break;
        }
    }
    if ($needsMigration) {
        $slugToId = [];
        foreach ($plugins as $p) {
            $slugToId[$p['slug']] = $p['id'];
        }
        $migrated = [];
        foreach ($activePluginIds as $entry) {
            if (is_string($entry) && strpos($entry, 'p_') === 0) {
                $migrated[] = $entry;
            } elseif (isset($slugToId[$entry])) {
                $migrated[] = $slugToId[$entry];
            }
        }
        $activePluginIds = $migrated;
        save_active_plugins($db, $migrated);
    }
}

// AJAX 端点：处理启用/禁用请求，返回 JSON
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || ($_SERVER['HTTP_X_PJAX'] ?? '') === 'true';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => '安全验证失败，请刷新页面后重试']);
        exit;
    }

    $pluginId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['plugin_id'] ?? '');
    $action = $_POST['plugin_action'] ?? '';

    if ($pluginId === '') {
        echo json_encode(['ok' => false, 'msg' => '插件标识无效']);
        exit;
    }

    $targetPlugin = null;
    foreach ($plugins as $p) {
        if ($p['id'] === $pluginId) {
            $targetPlugin = $p;
            break;
        }
    }

    if ($targetPlugin === null) {
        echo json_encode(['ok' => false, 'msg' => '插件不存在']);
        exit;
    }

    // 首次操作：把当前所有已存在的插件 id 加入启用列表
    if ($activePluginIds === null) {
        $activePluginIds = [];
        foreach ($plugins as $p) {
            $activePluginIds[] = $p['id'];
        }
    }

    if ($action === 'activate') {
        if (!in_array($pluginId, $activePluginIds, true)) {
            $activePluginIds[] = $pluginId;
        }
        save_active_plugins($db, $activePluginIds);
        Nova_Plugin_Registry::clear_cache();
        echo json_encode([
            'ok' => true,
            'active' => true,
            'msg' => '插件「' . $targetPlugin['name'] . '」已启用',
            'enabled_count' => count($activePluginIds),
            'disabled_count' => max(0, count($plugins) - count($activePluginIds)),
        ]);
    } elseif ($action === 'deactivate') {
        $activePluginIds = array_values(array_diff($activePluginIds, [$pluginId]));
        save_active_plugins($db, $activePluginIds);
        Nova_Plugin_Registry::clear_cache();
        echo json_encode([
            'ok' => true,
            'active' => false,
            'msg' => '插件「' . $targetPlugin['name'] . '」已禁用',
            'enabled_count' => count($activePluginIds),
            'disabled_count' => max(0, count($plugins) - count($activePluginIds)),
        ]);
    } else {
        echo json_encode(['ok' => false, 'msg' => '未知操作']);
    }
    exit;
}

// 非 AJAX 的 POST 回退（禁用 JS 时仍可用）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plugin_action']) && !$isAjax) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = '安全验证失败，请刷新页面后重试';
    } else {
        $pluginId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['plugin_id'] ?? '');
        $action = $_POST['plugin_action'];

        if ($pluginId !== '') {
            $targetPlugin = null;
            foreach ($plugins as $p) {
                if ($p['id'] === $pluginId) {
                    $targetPlugin = $p;
                    break;
                }
            }
            if ($targetPlugin !== null) {
                if ($activePluginIds === null) {
                    $activePluginIds = [];
                    foreach ($plugins as $p) {
                        $activePluginIds[] = $p['id'];
                    }
                }
                if ($action === 'activate') {
                    if (!in_array($pluginId, $activePluginIds, true)) {
                        $activePluginIds[] = $pluginId;
                    }
                    $message = '插件「' . $targetPlugin['name'] . '」已启用，相关功能已生效';
                } elseif ($action === 'deactivate') {
                    $activePluginIds = array_values(array_diff($activePluginIds, [$pluginId]));
                    $message = '插件「' . $targetPlugin['name'] . '」已禁用，相关 API 路由将不再注册';
                }
                if (!isset($error)) {
                    save_active_plugins($db, $activePluginIds);
                    Nova_Plugin_Registry::clear_cache();
                }
            }
        }
    }
}

$message = $message ?? '';
$error = $error ?? '';

// 为每个插件附加 active 状态
foreach ($plugins as &$p) {
    $p['active'] = $activePluginIds === null ? true : in_array($p['id'], $activePluginIds, true);
}
unset($p);

// 排序：已启用的在前
usort($plugins, function ($a, $b) {
    if ($a['active'] === $b['active']) {
        return strcmp($a['name'], $b['name']);
    }
    return $a['active'] ? -1 : 1;
});

$enabledCount = count(array_filter($plugins, fn($p) => $p['active']));
$disabledCount = max(0, count($plugins) - $enabledCount);
$totalCount = count($plugins);

$page_title = '插件管理';
require_once 'includes/header.php';
?>

<style>
.plugin-page {
    max-width: 1500px;
    margin: 0 auto;
}

.plugin-page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin: 28px 0 22px;
}

.plugin-page-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.plugin-page-title-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(13, 110, 253, .1);
    color: var(--bs-primary);
    font-size: 22px;
}

.plugin-page-header h1 {
    font-size: 1.6rem;
    font-weight: 650;
    margin: 0;
}

.plugin-page-header p {
    margin: 4px 0 0;
    color: var(--bs-secondary-color);
    font-size: .9rem;
}


/* 顶部状态 */
.plugin-summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 18px;
}

.plugin-summary-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 11px;
    border-radius: 999px;
    background: var(--bs-tertiary-bg);
    border: 1px solid var(--bs-border-color);
    font-size: .82rem;
}

.plugin-summary-item strong {
    font-weight: 650;
}


/* 工具栏 */
.plugin-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 20px;
}

.plugin-search {
    position: relative;
    flex: 1;
    max-width: 420px;
}

.plugin-search i {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--bs-secondary-color);
    pointer-events: none;
}

.plugin-search input {
    padding-left: 38px;
    border-radius: 10px;
}

.plugin-filters {
    display: flex;
    gap: 6px;
    padding: 4px;
    border-radius: 10px;
    background: var(--bs-tertiary-bg);
}

.plugin-filter-btn {
    border: 0;
    background: transparent;
    color: var(--bs-secondary-color);
    padding: 6px 12px;
    border-radius: 7px;
    font-size: .85rem;
    white-space: nowrap;
}

.plugin-filter-btn:hover {
    color: var(--bs-body-color);
}

.plugin-filter-btn.active {
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
}


/* 插件卡片 */
.plugin-card {
    height: 100%;
    border: 1px solid var(--bs-border-color);
    border-radius: 14px;
    overflow: hidden;
    transition:
        border-color .2s ease,
        box-shadow .2s ease,
        transform .2s ease;
}

.plugin-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 28px rgba(0,0,0,.07);
}

.plugin-card.is-active {
    border-color: rgba(25, 135, 84, .35);
}

.plugin-card.is-inactive {
    opacity: .82;
}

.plugin-card-body {
    padding: 20px;
}

.plugin-card-head {
    display: flex;
    align-items: flex-start;
    gap: 14px;
}

.plugin-icon {
    width: 48px;
    height: 48px;
    flex: 0 0 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    font-size: 22px;
    color: var(--bs-primary);
    background: rgba(13, 110, 253, .1);
}

.plugin-card.is-active .plugin-icon {
    color: var(--bs-success);
    background: rgba(25, 135, 84, .1);
}

.plugin-card.is-inactive .plugin-icon {
    color: var(--bs-secondary-color);
    background: var(--bs-tertiary-bg);
}

.plugin-main {
    min-width: 0;
    flex: 1;
}

.plugin-name-row {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}

.plugin-name {
    margin: 0;
    font-size: 1rem;
    font-weight: 650;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.plugin-status {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: .72rem;
    padding: 3px 8px;
    border-radius: 999px;
}

.plugin-status::before {
    content: "";
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}

.plugin-status.active {
    color: var(--bs-success);
    background: rgba(25, 135, 84, .1);
}

.plugin-status.inactive {
    color: var(--bs-secondary-color);
    background: var(--bs-tertiary-bg);
}

.plugin-description {
    min-height: 42px;
    margin: 15px 0;
    color: var(--bs-secondary-color);
    font-size: .86rem;
    line-height: 1.55;
}

.plugin-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 14px;
    color: var(--bs-secondary-color);
    font-size: .78rem;
}

.plugin-meta span,
.plugin-meta a {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.plugin-meta a {
    color: inherit;
    text-decoration: none;
}

.plugin-meta a:hover {
    color: var(--bs-primary);
}

.plugin-id {
    margin-top: 12px;
}

.plugin-id code {
    color: var(--bs-secondary-color);
    font-size: .7rem;
}


/* 卡片底部按钮 */
.plugin-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 12px 16px;
    border-top: 1px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
}

.plugin-actions-main {
    display: flex;
    align-items: center;
    gap: 8px;
}

.plugin-actions form {
    margin: 0;
}

.plugin-action-btn {
    border-radius: 8px;
}

.plugin-enable-btn {
    min-width: 90px;
}

.plugin-disable-btn {
    color: var(--bs-secondary-color);
}

.plugin-disable-btn:hover {
    color: var(--bs-danger);
    border-color: var(--bs-danger);
}


/* 空状态 */
.plugin-empty {
    padding: 70px 20px;
    text-align: center;
}

.plugin-empty-icon {
    width: 64px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    border-radius: 16px;
    background: var(--bs-tertiary-bg);
    color: var(--bs-secondary-color);
    font-size: 28px;
}

.plugin-search-empty {
    display: none;
    padding: 48px 20px;
    text-align: center;
    color: var(--bs-secondary-color);
}


/* AJAX 提示 */
#plugin-alert {
    position: fixed;
    z-index: 1090;
    top: 78px;
    right: 24px;
    width: min(380px, calc(100vw - 48px));
}


/* 手机 */
@media (max-width: 767.98px) {

    .plugin-page-header {
        flex-direction: column;
        margin-top: 20px;
    }

    .plugin-toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .plugin-search {
        max-width: none;
    }

    .plugin-filters {
        overflow-x: auto;
    }

    .plugin-filter-btn {
        flex: 1;
    }

    .plugin-actions {
        align-items: stretch;
    }

    .plugin-actions-main {
        flex: 1;
    }

    .plugin-actions-main .btn {
        flex: 1;
    }
}
</style>


<div class="container-fluid px-4 plugin-page">

    <!-- 页面标题 -->
    <div class="plugin-page-header">

        <div class="plugin-page-title">

            <div class="plugin-page-title-icon">
                <i class="bi bi-puzzle"></i>
            </div>

            <div>
                <h1>插件</h1>
                <p>管理 NovaCMS 的扩展功能</p>
            </div>

        </div>


        <a
            href="https://lygalaxy.cn"
            target="_blank"
            rel="noopener"
            class="btn btn-outline-secondary btn-sm"
        >
            <i class="bi bi-box-arrow-up-right me-1"></i>
            获取插件
        </a>

    </div>


    <div id="plugin-alert"></div>


    <!-- 简洁统计 -->
    <div class="plugin-summary">

        <div class="plugin-summary-item">
            <i class="bi bi-box-seam"></i>
            已安装
            <strong id="stat-total"><?= $totalCount ?></strong>
        </div>

        <div class="plugin-summary-item">
            <i class="bi bi-check-circle text-success"></i>
            已启用
            <strong id="stat-enabled"><?= $enabledCount ?></strong>
        </div>

        <div class="plugin-summary-item">
            <i class="bi bi-pause-circle"></i>
            已停用
            <strong id="stat-disabled"><?= $disabledCount ?></strong>
        </div>

    </div>


    <!-- 搜索 / 筛选 -->
    <div class="plugin-toolbar">

        <div class="plugin-search">

            <i class="bi bi-search"></i>

            <input
                id="plugin-search-input"
                type="search"
                class="form-control form-control-sm"
                placeholder="搜索插件名称、作者或目录…"
                autocomplete="off"
            >

        </div>


        <div class="plugin-filters">

            <button
                type="button"
                class="plugin-filter-btn active"
                data-plugin-filter="all"
            >
                全部
            </button>

            <button
                type="button"
                class="plugin-filter-btn"
                data-plugin-filter="active"
            >
                已启用
            </button>

            <button
                type="button"
                class="plugin-filter-btn"
                data-plugin-filter="inactive"
            >
                已停用
            </button>

        </div>

    </div>


    <?php if (empty($plugins)): ?>

        <div class="card plugin-card">

            <div class="plugin-empty">

                <div class="plugin-empty-icon">
                    <i class="bi bi-puzzle"></i>
                </div>

                <h5>还没有安装插件</h5>

                <p class="text-muted small mb-2">
                    将插件放入
                    <code>/vendor/nova-plugins/</code>
                    后刷新此页面
                </p>

            </div>

        </div>

    <?php else: ?>


        <div class="row g-3" id="plugin-list">

            <?php foreach ($plugins as $plugin): ?>

                <?php
                $hasAdminPage = is_file(
                    $plugin['plugin_dir']
                    . '/plugin/admin/index.php'
                );

                $searchText =
                    $plugin['name'] . ' '
                    . $plugin['slug'] . ' '
                    . $plugin['author'] . ' '
                    . $plugin['description'];
                ?>


                <div
                    class="col-md-6 col-xl-4 plugin-grid-item"
                    data-plugin-active="<?= $plugin['active'] ? '1' : '0' ?>"
                    data-plugin-search="<?= e(mb_strtolower($searchText)) ?>"
                >

                    <div
                        class="card plugin-card <?= $plugin['active'] ? 'is-active' : 'is-inactive' ?>"
                        data-plugin-id="<?= e($plugin['id']) ?>"
                        data-plugin-name="<?= e($plugin['name']) ?>"
                    >

                        <div class="plugin-card-body">

                            <div class="plugin-card-head">

                                <div class="plugin-icon">
                                    <i class="bi bi-puzzle-fill"></i>
                                </div>


                                <div class="plugin-main">

                                    <div class="plugin-name-row">

                                        <h5 class="plugin-name">
                                            <?= e($plugin['name']) ?>
                                        </h5>


                                        <span class="plugin-status <?= $plugin['active'] ? 'active' : 'inactive' ?>">

                                            <?= $plugin['active']
                                                ? '运行中'
                                                : '已停用'
                                            ?>

                                        </span>

                                    </div>


                                    <div class="plugin-meta mt-2">

                                        <?php if ($plugin['version']): ?>

                                            <span>
                                                <i class="bi bi-tag"></i>
                                                v<?= e($plugin['version']) ?>
                                            </span>

                                        <?php endif; ?>


                                        <?php if ($plugin['author']): ?>

                                            <?php if ($plugin['author_uri']): ?>

                                                <a
                                                    href="<?= e($plugin['author_uri']) ?>"
                                                    target="_blank"
                                                    rel="noopener"
                                                >
                                                    <i class="bi bi-person"></i>
                                                    <?= e($plugin['author']) ?>
                                                </a>

                                            <?php else: ?>

                                                <span>
                                                    <i class="bi bi-person"></i>
                                                    <?= e($plugin['author']) ?>
                                                </span>

                                            <?php endif; ?>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>


                            <div class="plugin-description">

                                <?= $plugin['description']
                                    ? e($plugin['description'])
                                    : '这个插件没有提供说明。'
                                ?>

                            </div>


                            <div class="plugin-meta">

                                <span title="插件目录">
                                    <i class="bi bi-folder"></i>
                                    <?= e($plugin['slug']) ?>
                                </span>

                                <?php if ($hasAdminPage): ?>

                                    <span>
                                        <i class="bi bi-sliders"></i>
                                        提供管理页面
                                    </span>

                                <?php endif; ?>

                            </div>


                            <div class="plugin-id">
                                <code title="插件内部 ID">
                                    <?= e($plugin['id']) ?>
                                </code>
                            </div>

                        </div>


                        <div class="plugin-actions">


                            <div class="plugin-actions-main">

                                <?php if ($plugin['active'] && $hasAdminPage): ?>

                                    <a
                                        href="/admin/plugin-page.php?plugin=<?= rawurlencode($plugin['id']) ?>"
                                        class="btn btn-primary btn-sm plugin-action-btn"
                                    >
                                        <i class="bi bi-sliders me-1"></i>
                                        管理
                                    </a>

                                <?php endif; ?>


                                <?php if ($plugin['uri']): ?>

                                    <a
                                        href="<?= e($plugin['uri']) ?>"
                                        target="_blank"
                                        rel="noopener"
                                        class="btn btn-outline-secondary btn-sm plugin-action-btn"
                                        title="查看插件主页"
                                    >
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>

                                <?php endif; ?>

                            </div>


                            <form
                                method="POST"
                                class="plugin-toggle-form"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(generateCSRFToken()) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="plugin_id"
                                    value="<?= e($plugin['id']) ?>"
                                >


                                <?php if ($plugin['active']): ?>

                                    <button
                                        type="submit"
                                        name="plugin_action"
                                        value="deactivate"
                                        class="btn btn-sm btn-outline-secondary plugin-action-btn plugin-toggle-btn plugin-disable-btn"
                                    >
                                        <i class="bi bi-power me-1"></i>
                                        停用
                                    </button>

                                <?php else: ?>

                                    <button
                                        type="submit"
                                        name="plugin_action"
                                        value="activate"
                                        class="btn btn-sm btn-primary plugin-action-btn plugin-toggle-btn plugin-enable-btn"
                                    >
                                        <i class="bi bi-play-fill me-1"></i>
                                        启用
                                    </button>

                                <?php endif; ?>

                            </form>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>


        <div
            class="plugin-search-empty"
            id="plugin-search-empty"
        >
            <i class="bi bi-search fs-3 d-block mb-2"></i>
            没找到符合条件的插件
        </div>


    <?php endif; ?>


    <div class="mt-4 mb-4">

        <details class="small text-muted">

            <summary style="cursor:pointer;">
                插件开发信息
            </summary>

            <div class="mt-2">

                插件目录：

                <code>
                    /vendor/nova-plugins/
                </code>

                <br>

                系统会读取插件目录中的

                <code>plugin.json</code>

                并自动分配唯一 ID。

            </div>

        </details>

    </div>

</div>


<script type="text/pjax-script">
(function () {

    var csrfToken = '<?= e(generateCSRFToken()) ?>';

    var currentFilter = 'all';


    /*
     * 更新插件卡片状态
     */
    function applyCardState(card, active) {

        card.classList.toggle(
            'is-active',
            active
        );

        card.classList.toggle(
            'is-inactive',
            !active
        );


        var gridItem =
            card.closest('.plugin-grid-item');

        if (gridItem) {
            gridItem.dataset.pluginActive =
                active ? '1' : '0';
        }


        var status =
            card.querySelector('.plugin-status');

        if (status) {

            status.className =
                'plugin-status '
                + (active ? 'active' : 'inactive');

            status.textContent =
                active ? '运行中' : '已停用';

        }


        var btn =
            card.querySelector('.plugin-toggle-btn');

        if (btn) {

            btn.value =
                active
                    ? 'deactivate'
                    : 'activate';

            if (active) {

                btn.className =
                    'btn btn-sm btn-outline-secondary '
                    + 'plugin-action-btn plugin-toggle-btn '
                    + 'plugin-disable-btn';

                btn.innerHTML =
                    '<i class="bi bi-power me-1"></i>'
                    + '停用';

            } else {

                btn.className =
                    'btn btn-sm btn-primary '
                    + 'plugin-action-btn plugin-toggle-btn '
                    + 'plugin-enable-btn';

                btn.innerHTML =
                    '<i class="bi bi-play-fill me-1"></i>'
                    + '启用';

            }

            btn.disabled = false;

        }


        /*
         * 管理按钮：
         * 停用后隐藏
         * 启用后显示
         */
        var manageBtn =
            card.querySelector(
                'a[href*="plugin-page.php"]'
            );

        if (manageBtn) {

            manageBtn.style.display =
                active ? '' : 'none';

        }


        filterPlugins();

    }


    /*
     * 更新统计数字
     */
    function updateStats(enabled, disabled) {

        var enabledEl =
            document.getElementById(
                'stat-enabled'
            );

        var disabledEl =
            document.getElementById(
                'stat-disabled'
            );

        if (enabledEl) {
            enabledEl.textContent = enabled;
        }

        if (disabledEl) {
            disabledEl.textContent = disabled;
        }

    }


    /*
     * 提示
     */
    function showAlert(type, message) {

        var container =
            document.getElementById(
                'plugin-alert'
            );

        if (!container) {
            return;
        }


        var success =
            type === 'success';


        container.innerHTML =
            '<div class="alert '
            + (success
                ? 'alert-success'
                : 'alert-danger')
            + ' shadow-sm alert-dismissible fade show">'
            + '<i class="bi '
            + (success
                ? 'bi-check-circle'
                : 'bi-exclamation-triangle')
            + ' me-2"></i>'
            + message
            + '<button type="button" '
            + 'class="btn-close" '
            + 'data-bs-dismiss="alert">'
            + '</button>'
            + '</div>';


        window.setTimeout(function () {

            var alertEl =
                container.querySelector(
                    '.alert'
                );

            if (
                alertEl
                && window.bootstrap
            ) {

                bootstrap.Alert
                    .getOrCreateInstance(
                        alertEl
                    )
                    .close();

            }

        }, 3000);

    }


    /*
     * 刷新左侧菜单
     */
    function refreshSidebar() {

        fetch(
            window.location.pathname
            + window.location.search,
            {
                headers: {
                    'X-Requested-With':
                        'XMLHttpRequest'
                }
            }
        )

        .then(function (response) {

            return response.text();

        })

        .then(function (html) {

            var doc =
                new DOMParser()
                    .parseFromString(
                        html,
                        'text/html'
                    );

            var newMenu =
                doc.querySelector(
                    '.sidebar-menu'
                );

            var currentMenu =
                document.querySelector(
                    '.sidebar-menu'
                );

            if (
                newMenu
                && currentMenu
            ) {

                currentMenu.innerHTML =
                    newMenu.innerHTML;

            }

        })

        .catch(function () {});

    }


    /*
     * 搜索 + 筛选
     */
    function filterPlugins() {

        var input =
            document.getElementById(
                'plugin-search-input'
            );

        var keyword =
            input
                ? input.value
                    .trim()
                    .toLowerCase()
                : '';


        var visibleCount = 0;


        document
            .querySelectorAll(
                '.plugin-grid-item'
            )
            .forEach(function (item) {

                var active =
                    item.dataset.pluginActive
                    === '1';

                var text =
                    (
                        item.dataset.pluginSearch
                        || ''
                    ).toLowerCase();


                var matchesSearch =
                    keyword === ''
                    || text.includes(keyword);


                var matchesFilter =
                    currentFilter === 'all'
                    || (
                        currentFilter
                        === 'active'
                        && active
                    )
                    || (
                        currentFilter
                        === 'inactive'
                        && !active
                    );


                var visible =
                    matchesSearch
                    && matchesFilter;


                item.style.display =
                    visible ? '' : 'none';


                if (visible) {
                    visibleCount++;
                }

            });


        var empty =
            document.getElementById(
                'plugin-search-empty'
            );

        if (empty) {

            empty.style.display =
                visibleCount === 0
                    ? 'block'
                    : 'none';

        }

    }


    /*
     * 搜索框
     */
    var searchInput =
        document.getElementById(
            'plugin-search-input'
        );

    if (searchInput) {

        searchInput.addEventListener(
            'input',
            filterPlugins
        );

    }


    /*
     * 状态筛选
     */
    document
        .querySelectorAll(
            '.plugin-filter-btn'
        )
        .forEach(function (button) {

            button.addEventListener(
                'click',
                function () {

                    currentFilter =
                        button.dataset
                            .pluginFilter;

                    document
                        .querySelectorAll(
                            '.plugin-filter-btn'
                        )
                        .forEach(
                            function (item) {
                                item.classList
                                    .remove(
                                        'active'
                                    );
                            }
                        );

                    button.classList
                        .add('active');

                    filterPlugins();

                }
            );

        });


    /*
     * 启用 / 停用
     */
    document
        .querySelectorAll(
            '.plugin-toggle-form'
        )
        .forEach(function (form) {

            form.addEventListener(
                'submit',
                function (event) {

                    event.preventDefault();


                    var card =
                        form.closest(
                            '[data-plugin-id]'
                        );

                    var btn =
                        form.querySelector(
                            '.plugin-toggle-btn'
                        );

                    if (!card || !btn) {
                        return;
                    }


                    var action =
                        btn.value;

                    var pluginId =
                        form.querySelector(
                            '[name="plugin_id"]'
                        ).value;

                    var pluginName =
                        card.dataset.pluginName;


                    /*
                     * 启用不询问。
                     * 停用才确认。
                     */
                    if (
                        action
                        === 'deactivate'
                        && !confirm(
                            '确定停用「'
                            + pluginName
                            + '」吗？\n\n'
                            + '停用后，该插件提供的功能将暂时不可用。'
                        )
                    ) {

                        return;

                    }


                    btn.disabled = true;


                    if (
                        action === 'activate'
                    ) {

                        btn.innerHTML =
                            '<span class="spinner-border '
                            + 'spinner-border-sm me-1">'
                            + '</span>'
                            + '正在启用…';

                    } else {

                        btn.innerHTML =
                            '<span class="spinner-border '
                            + 'spinner-border-sm me-1">'
                            + '</span>'
                            + '正在停用…';

                    }


                    var formData =
                        new FormData();

                    formData.append(
                        'csrf_token',
                        csrfToken
                    );

                    formData.append(
                        'plugin_id',
                        pluginId
                    );

                    formData.append(
                        'plugin_action',
                        action
                    );


                    fetch(
                        window.location.pathname,
                        {
                            method: 'POST',

                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            },

                            body: formData
                        }
                    )

                    .then(function (response) {

                        return response.json();

                    })

                    .then(function (data) {

                        if (!data.ok) {

                            throw new Error(
                                data.msg
                                || '操作失败'
                            );

                        }


                        applyCardState(
                            card,
                            data.active
                        );

                        updateStats(
                            data.enabled_count,
                            data.disabled_count
                        );

                        showAlert(
                            'success',
                            data.msg
                        );

                        refreshSidebar();

                    })

                    .catch(function (error) {

                        btn.disabled = false;

                        applyCardState(
                            card,
                            action
                                === 'deactivate'
                        );

                        showAlert(
                            'danger',
                            error.message
                            || '操作失败，请重试'
                        );

                    });

                }
            );

        });


})();
</script>
