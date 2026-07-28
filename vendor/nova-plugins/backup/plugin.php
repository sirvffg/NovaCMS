<?php
/**
 * Plugin Name: Backup
 * Plugin URI: https://lygalaxy.cn
 * Description: 网站备份插件 - 自动备份文件和数据库
 * Version: 1.0.0
 * Author: LyGalaxy
 * Author URI: https://lygalaxy.cn
 */

defined('NOVA_API') or exit('禁止直接访问');

require_once __DIR__ . '/class-backup.php';

class Backup_Plugin extends Nova_Plugin {

    protected $name = 'backup';
    protected $version = '1.0.0';

    public function init() {
        // 注册管理菜单
        Nova_Hooks::add_filter('admin_menu', [$this, 'add_admin_menu']);

        // 注册插件设置
        Nova_Hooks::add_filter('plugin_settings', [$this, 'register_settings']);
    }

    /**
     * 添加管理菜单
     */
    public function add_admin_menu($menus) {
        $menus[] = [
            'title' => '备份管理',
            'slug' => 'backup',
            'icon' => 'bi-cloud-download',
            'callback' => [$this, 'render_admin_page'],
            'capability' => 'manage_options'
        ];
        return $menus;
    }

    /**
     * 渲染管理页面
     */
    public function render_admin_page() {
        include __DIR__ . '/admin/index.php';
    }

    /**
     * 注册插件设置
     */
    public function register_settings($settings) {
        $settings['backup'] = [
            'max_backups' => [
                'type' => 'number',
                'default' => 10,
                'label' => '最大备份数量'
            ],
            'auto_backup' => [
                'type' => 'boolean',
                'default' => false,
                'label' => '自动备份'
            ]
        ];
        return $settings;
    }
}

// 初始化插件
new Backup_Plugin();