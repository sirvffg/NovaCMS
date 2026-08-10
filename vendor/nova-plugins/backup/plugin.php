<?php

/**
 * Plugin Name: Backup
 * Description: 网站备份插件
 * Version: 1.0.0
 * Author: LyGalaxy
 */

defined('NOVA_API') or exit('禁止直接访问');

require_once __DIR__ . '/class-backup.php';

class Backup_Plugin extends Nova_Plugin
{
    protected $name = 'backup';
    protected $version = '1.0.0';

    public function init()
    {
        // 注册后台菜单
        Nova_Backend_Menu::add_menu(
            '备份管理',
            'backup',
            '/admin/backup.php',
            'bi-database-check',
            60,
            [
                'group' => 'tools',
                'group_label' => '工具',
                'group_order' => 300
            ]
        );

        // 插件设置
        Nova_Hooks::add_filter(
            'plugin_settings',
            [$this, 'register_settings']
        );
    }

    public function register_settings($settings)
    {
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

new Backup_Plugin();