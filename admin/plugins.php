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
.plugin-card { transition: all 0.25s; }
.plugin-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
.plugin-card.is-active { border-color: #1890ff; }
.plugin-card.is-inactive { opacity: 0.85; }
.plugin-card.is-inactive .plugin-name { color: #6c757d; }
.plugin-icon {
    width: 56px; height: 56px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; color: #fff;
    background: linear-gradient(135deg, #6a8dff 0%, #4a6cf7 100%);
    flex-shrink: 0;
}
.plugin-card.is-inactive .plugin-icon {
    background: linear-gradient(135deg, #b0b8c4 0%, #8a93a0 100%);
}
.plugin-meta-item { font-size: 0.8125rem; }
.plugin-stats-card .stat-value { font-size: 1.75rem; font-weight: 600; line-height: 1; }
.plugin-id-badge {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.7rem; color: #6c757d;
    background: rgba(108,117,125,0.1); padding: 2px 6px; border-radius: 4px;
}
</style>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-puzzle me-2"></i>插件管理</h1>
            <p class="text-muted mb-0 small">管理已安装的插件，启用或禁用功能模块</p>
        </div>
        <a href="https://lygalaxy.cn" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-box-arrow-up-right me-1"></i>获取更多插件
        </a>
    </div>

    <div id="plugin-alert"></div>

    <!-- 统计卡片 -->
    <div class="row g-3 mb-4 plugin-stats-card">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="flex-grow-1">
                        <div class="text-muted small">已安装插件</div>
                        <div class="stat-value" id="stat-total"><?= $totalCount ?></div>
                    </div>
                    <div class="text-primary fs-1"><i class="bi bi-puzzle"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="flex-grow-1">
                        <div class="text-muted small">已启用</div>
                        <div class="stat-value text-success" id="stat-enabled"><?= $enabledCount ?></div>
                    </div>
                    <div class="text-success fs-1"><i class="bi bi-check2-circle"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="flex-grow-1">
                        <div class="text-muted small">已禁用</div>
                        <div class="stat-value text-secondary" id="stat-disabled"><?= $disabledCount ?></div>
                    </div>
                    <div class="text-secondary fs-1"><i class="bi bi-pause-circle"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="flex-grow-1">
                        <div class="text-muted small">插件目录</div>
                        <div class="text-truncate" style="font-size: 0.875rem;"><code>/vendor/nova-plugins/</code></div>
                    </div>
                    <div class="text-info fs-1"><i class="bi bi-folder2"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 插件列表 -->
    <?php if (empty($plugins)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox fs-1 text-muted"></i>
            <p class="mt-3 mb-0 text-muted">暂无已安装的插件</p>
            <p class="small text-muted">将插件目录放入 <code>/vendor/nova-plugins/</code> 并提供 <code>plugin.json</code> 即可自动识别</p>
        </div>
    </div>
    <?php else: ?>
    <div class="row g-3" id="plugin-list">
        <?php foreach ($plugins as $plugin): ?>
        <div class="col-md-6 col-xl-4">
            <div class="card plugin-card h-100 <?= $plugin['active'] ? 'is-active' : 'is-inactive' ?>" data-plugin-id="<?= e($plugin['id']) ?>" data-plugin-name="<?= e($plugin['name']) ?>">
                <div class="card-body">
                    <div class="d-flex align-items-start mb-3">
                        <div class="plugin-icon me-3">
                            <i class="bi bi-puzzle-fill"></i>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <h5 class="plugin-name mb-1 text-truncate"><?= e($plugin['name']) ?></h5>
                            <div class="d-flex flex-wrap gap-2 plugin-meta-item text-muted">
                                <?php if ($plugin['version']): ?>
                                <span><i class="bi bi-tag me-1"></i>v<?= e($plugin['version']) ?></span>
                                <?php endif; ?>
                                <?php if ($plugin['author']): ?>
                                <span>
                                    <i class="bi bi-person me-1"></i>
                                    <?php if ($plugin['author_uri']): ?>
                                    <a href="<?= e($plugin['author_uri']) ?>" target="_blank" rel="noopener"><?= e($plugin['author']) ?></a>
                                    <?php else: ?>
                                    <?= e($plugin['author']) ?>
                                    <?php endif; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="mt-1">
                                <span class="plugin-id-badge" title="系统识别符 ID"><?= e($plugin['id']) ?></span>
                            </div>
                        </div>
                        <span class="badge plugin-status-badge <?= $plugin['active'] ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary bg-opacity-10 text-secondary' ?>">
                            <?= $plugin['active'] ? '已启用' : '已禁用' ?>
                        </span>
                    </div>

                    <?php if ($plugin['description']): ?>
                    <p class="card-text small text-muted mb-3"><?= e($plugin['description']) ?></p>
                    <?php else: ?>
                    <p class="card-text small text-muted mb-3 fst-italic">暂无描述</p>
                    <?php endif; ?>

                    <div class="d-flex align-items-center justify-content-between">
                        <span class="text-muted small" title="插件目录名">
                            <i class="bi bi-folder me-1"></i><code><?= e($plugin['slug']) ?></code>
                        </span>
                        <?php if ($plugin['uri']): ?>
                        <a href="<?= e($plugin['uri']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-info-circle"></i> 详情
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top">
                    <form method="POST" class="plugin-toggle-form">
                        <input type="hidden" name="csrf_token" value="<?= e(generateCSRFToken()) ?>">
                        <input type="hidden" name="plugin_id" value="<?= e($plugin['id']) ?>">
                        <button type="submit" name="plugin_action"
                                value="<?= $plugin['active'] ? 'deactivate' : 'activate' ?>"
                                class="btn btn-sm w-100 plugin-toggle-btn <?= $plugin['active'] ? 'btn-outline-secondary' : 'btn-primary' ?>">
                            <?php if ($plugin['active']): ?>
                            <i class="bi bi-pause-circle me-1"></i>禁用插件
                            <?php else: ?>
                            <i class="bi bi-play-circle me-1"></i>启用插件
                            <?php endif; ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="mt-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="mb-2"><i class="bi bi-info-circle me-1"></i>说明</h6>
                <ul class="small text-muted mb-0 ps-3">
                    <li>插件存放于 <code>/vendor/nova-plugins/{插件目录}/plugin.json</code>，系统自动扫描识别。</li>
                    <li>系统首次识别插件时会自动生成唯一 <strong>id</strong> 并写入 <code>plugin.json</code>，作为启用/禁用的识别依据。</li>
                    <li>禁用插件后，该插件注册的 REST API 路由、钩子和后台菜单将不再生效。</li>
                    <li>新增插件后刷新本页即可自动识别并生成 id，无需额外配置。</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script type="text/pjax-script">
(function () {
    var csrfToken = '<?= e(generateCSRFToken()) ?>';

    // 根据激活状态切换卡片 UI
    function applyCardState(card, active) {
        card.classList.toggle('is-active', active);
        card.classList.toggle('is-inactive', !active);
        var badge = card.querySelector('.plugin-status-badge');
        if (badge) {
            badge.className = 'badge plugin-status-badge ' + (active
                ? 'bg-success-subtle text-success border border-success-subtle'
                : 'bg-secondary bg-opacity-10 text-secondary');
            badge.textContent = active ? '已启用' : '已禁用';
        }
        var btn = card.querySelector('.plugin-toggle-btn');
        if (btn) {
            btn.value = active ? 'deactivate' : 'activate';
            btn.className = 'btn btn-sm w-100 plugin-toggle-btn ' + (active ? 'btn-outline-secondary' : 'btn-primary');
            btn.innerHTML = active
                ? '<i class="bi bi-pause-circle me-1"></i>禁用插件'
                : '<i class="bi bi-play-circle me-1"></i>启用插件';
            btn.disabled = false;
        }
    }

    // 同步刷新侧边栏菜单（启用/禁用后，插件注册的菜单项需要相应增减）
    function refreshSidebar() {
        fetch(window.location.pathname + window.location.search, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.text(); })
        .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var newMenu = doc.querySelector('.sidebar-menu');
            var currentMenu = document.querySelector('.sidebar-menu');
            if (newMenu && currentMenu) {
                currentMenu.innerHTML = newMenu.innerHTML;
            }
        })
        .catch(function () {});
    }

    function updateStats(enabled, disabled) {
        var el = document.getElementById('stat-enabled');
        if (el) el.textContent = enabled;
        el = document.getElementById('stat-disabled');
        if (el) el.textContent = disabled;
    }

    function showAlert(type, msg) {
        var container = document.getElementById('plugin-alert');
        if (!container) return;
        var icon = type === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle';
        var cls = type === 'success' ? 'alert-success' : 'alert-danger';
        container.innerHTML =
            '<div class="alert ' + cls + ' alert-dismissible fade show" role="alert">' +
            '<i class="bi ' + icon + ' me-1"></i>' + msg +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
            '</div>';
        setTimeout(function () {
            var alert = container.querySelector('.alert');
            if (alert) {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            }
        }, 3000);
    }

    document.querySelectorAll('.plugin-toggle-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var card = form.closest('[data-plugin-id]');
            var btn = form.querySelector('.plugin-toggle-btn');
            var action = btn ? btn.value : '';
            var pluginId = form.querySelector('[name="plugin_id"]').value;
            var pluginName = card.getAttribute('data-plugin-name');

            if (action === 'deactivate' && !confirm('确定要禁用插件「' + pluginName + '」吗？相关功能将不可用。')) {
                return;
            }

            // 禁用按钮，显示加载状态
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>处理中…';
            }

            var formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('plugin_id', pluginId);
            formData.append('plugin_action', action);

            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    applyCardState(card, data.active);
                    updateStats(data.enabled_count, data.disabled_count);
                    showAlert('success', data.msg);
                    // 同步刷新侧边栏（增减插件注册的菜单项）
                    refreshSidebar();
                } else {
                    showAlert('danger', data.msg || '操作失败');
                    if (btn) btn.disabled = false;
                }
            })
            .catch(function () {
                showAlert('danger', '网络错误，请重试');
                if (btn) btn.disabled = false;
            });
        });
    });

    // PJAX 导航后无需额外初始化——按钮由 PHP 渲染正确状态
})();
</script>

<?php require_once 'includes/footer.php'; ?>
