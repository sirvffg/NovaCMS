<?php
/**
 * Cron Manager 插件入口
 *
 * 定时任务管理插件：在后台切换执行模式（服务器/虚拟面板），查看已注册任务及执行状态。
 * 配置存储于 vendor/public/cron/config.json（经 plugin.json 的 config_path 指向插件目录之外，卸载插件不丢失）。
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

require_once __DIR__ . '/class-cron-manager.php';

// 实例化插件（构造函数会注册 init() 到 nova_init）
new Cron_Manager_Plugin();
