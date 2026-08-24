<?php
/**
 * NovaCMS 定时任务入口 (cron.php)
 *
 * 适用于宝塔面板 / 1Panel / 虚拟主机 cron 等定时调度器调用。
 *
 * 调用方式：
 *   1. CLI（推荐，宝塔"Shell 脚本"任务）：
 *      php /www/wwwroot/站点目录/vendor/public/cron/cron.php
 *   2. HTTP（宝塔/1Panel "访问 URL" 任务）：
 *      面板填写：https://你的域名/vendor/public/cron/cron.php
 *      或：curl https://你的域名/vendor/public/cron/cron.php
 *
 * 建议执行频率：每 1~5 分钟。
 * 频繁调用是安全的——未到期任务会通过原子锁 + 间隔检查快速跳过。
 * 各任务的实际执行周期由其注册时的 interval 决定。
 *
 * 插件注册任务：在插件 init() 中调用 Nova_Cron::register()，与 Nova_Hooks 一致。
 *   Nova_Cron::register('backup_cleanup', 3600, [$this, 'cleanup'], '清理过期备份');
 */

// 框架启动标记（与 index.php 一致），防止类文件被直接访问
define('NOVA_BOOTSTRAP', true);

$isCli = (PHP_SAPI === 'cli');

if ($isCli) {
    set_time_limit(0);
} else {
    // HTTP 模式：避免被代理/浏览器缓存
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Content-Type: text/plain; charset=utf-8');
}

// 引导框架基础
$root = dirname(__DIR__, 3);                              // → 项目根
require_once $root . '/config/database.php';
require_once $root . '/config/functions.php';

// 加载定时任务管理类与插件系统（让插件能在 init() 中注册 cron 任务）
$novaClassDir = dirname(__DIR__, 2) . '/nova-json/class'; // → vendor/nova-json/class
require_once $novaClassDir . '/system/class-hooks.php';
require_once $novaClassDir . '/system/class-cron.php';
require_once $novaClassDir . '/database/class-db.php';
require_once $novaClassDir . '/rest/class-server.php';
require_once $novaClassDir . '/plugin/class-plugin.php';
require_once $novaClassDir . '/plugin/class-plugin-registry.php';

// 加载已启用插件入口并触发 nova_init，让插件通过 Nova_Cron::register() 注册任务
try {
    foreach (Nova_Plugin_Registry::scan_all() as $pi) {
        if (!empty($pi['duplicate'])) continue;
        if (!Nova_Plugin_Registry::is_plugin_active($pi['id'])) continue;
        if (!empty($pi['entry_path']) && is_file($pi['entry_path'])) {
            require_once $pi['entry_path'];
        }
    }
    Nova_Hooks::do_action('nova_init');
} catch (Throwable $e) {
    error_log('[Nova_Cron] plugin bootstrap failed: ' . $e->getMessage());
}

// 执行所有到期任务
$results = Nova_Cron::run_due();

// 输出结果
$now   = date('Y-m-d H:i:s');
$total = count($results);
echo "[Nova_Cron] {$now} | 共 {$total} 个任务\n";
foreach ($results as $r) {
    $line = '  - ' . $r['id'] . ': ' . $r['status'];
    if (!empty($r['reason'])) $line .= ' (' . $r['reason'] . ')';
    if (!empty($r['error']))  $line .= ' [' . $r['error'] . ']';
    echo $line . "\n";
}

exit(0);
