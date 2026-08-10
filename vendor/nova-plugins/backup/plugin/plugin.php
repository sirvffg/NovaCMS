<?php
/**
 * Backup Plugin Entry
 * 网站备份插件 - 自动备份文件和数据库
 *
 * 元数据请见本插件根目录的 plugin.json
 * 系统会在首次识别时为该插件生成唯一 id 并写入 plugin.json
 *
 * 后台管理页面位于 plugin/admin/index.php，由 admin/plugin-page.php 通用渲染器加载，
 * 菜单由 admin/includes/header.php 扫描插件目录时自动注册。
 */

defined('NOVA_API') or exit('禁止直接访问');

require_once __DIR__ . '/class-backup.php';

class Backup_Plugin extends Nova_Plugin {

    protected $name = 'backup';
    protected $version = '1.0.0';

    public function init() {
        // 后台管理菜单由 header.php 自动注册，无需在此声明
    }
}

// 初始化插件
new Backup_Plugin();
