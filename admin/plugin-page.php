<?php
/**
 * 通用插件后台页面渲染器
 *
 * 插件通过 plugin/admin/index.php 提供后台管理界面，
 * 本文件根据 ?plugin= 参数（接受 slug 或 id）定位插件并渲染其管理页面。
 * 菜单项由 header.php 扫描插件目录时自动注册，无需各插件手动添加。
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';

if (!defined('NOVA_API')) {
    define('NOVA_API', true);
}
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
