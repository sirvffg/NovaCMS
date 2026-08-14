<?php
/**
 * 通用插件后台页面渲染器
 *
 * 插件通过 plugin/admin/index.php 提供后台管理界面，
 * 本文件根据 ?plugin= 参数（接受 slug 或 id）定位插件并渲染其管理页面。
 * 菜单项由 header.php 扫描插件目录时自动注册，无需各插件手动添加。
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';

require_once __DIR__ . '/../vendor/nova-json/class/plugin/class-plugin-registry.php';

// 解析插件标识（接受 slug 或 id）
$pluginKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['plugin'] ?? '');
if ($pluginKey === '') {
    http_response_code(404);
    exit('插件标识无效');
}

$plugins = Nova_Plugin_Registry::scan_all();
$targetPlugin = null;
foreach ($plugins as $p) {
    if ($p['slug'] === $pluginKey || $p['id'] === $pluginKey) {
        $targetPlugin = $p;
        break;
    }
}

if ($targetPlugin === null) {
    http_response_code(404);
    exit('插件不存在');
}

// ── 插件禁用拦截：若插件已禁用，直接渲染提示页，不加载插件后台页面 ──
if (!Nova_Plugin_Registry::is_plugin_active($targetPlugin['id'])) {
    $page_title = $targetPlugin['name'] . ' - 已禁用';
    require_once 'includes/header.php';
    ?>
    <div class="container-fluid px-4">
        <div class="row justify-content-center mt-5">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm text-center py-5">
                    <div class="card-body">
                        <div class="mb-4">
                            <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#dc3545" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                            </svg>
                        </div>
                        <h3 class="card-title mb-2">此插件已禁用</h3>
                        <p class="text-muted mb-4">
                            插件「<strong><?= e($targetPlugin['name']) ?></strong>」当前为禁用状态，
                            无法访问其后台管理页面及相关 API。
                        </p>
                        <?php if (!empty($targetPlugin['description'])): ?>
                            <p class="small text-muted mb-4"><?= e($targetPlugin['description']) ?></p>
                        <?php endif; ?>
                        <a href="plugins.php" class="btn btn-primary">
                            <i class="bi bi-arrow-left me-1"></i>返回插件管理
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once 'includes/footer.php';
    exit;
}

$adminPageFile = $targetPlugin['plugin_dir'] . '/plugin/admin/index.php';
if (!is_file($adminPageFile)) {
    http_response_code(404);
    exit('该插件没有后台管理页面');
}

$page_title = $targetPlugin['name'];
require_once 'includes/header.php';

// 渲染插件后台页面（插件 admin/index.php 自行负责加载所需依赖）
include $adminPageFile;

require_once 'includes/footer.php';
