<?php
/**
 * Cron Manager 插件主类
 *
 * 职责：
 *   1. init() 读取 config.json 中的 mode 字段，按模式启用/禁用访问触发
 *      - server（默认）：禁用访问触发，由面板定时任务调用 cron.php
 *      - visit         ：启用访问触发，由访客请求异步触发（虚拟主机）
 *   2. 提供静态方法供后台管理页面查询任务状态
 *
 * 配置文件：vendor/public/cron/config.json（声明式表单 + 保存值）
 *   - 由 plugins.php?plugin=cron-manager 的"执行模式"配置 Tab 读写
 *   - 通过 plugin.json 的 config_path 字段指向插件目录之外，防止误删插件丢失配置
 *   - 类内通过 Nova_Plugin_Registry::resolve_config_file() 解析路径（单一来源）
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Cron_Manager_Plugin extends Nova_Plugin {

    protected $name    = 'cron-manager';
    protected $version = '1.0.0';

    /** 默认模式：服务器模式（访问触发关闭，依赖面板 cron） */
    const DEFAULT_MODE = 'server';

    /**
     * 配置文件路径（由 plugin.json 的 config_path 解析，指向 vendor/public/cron/config.json）
     * 回退：直接拼接路径
     */
    public static function config_file() {
        $plugin = Nova_Plugin_Registry::find_plugin('cron-manager');
        if ($plugin) {
            return Nova_Plugin_Registry::resolve_config_file($plugin);
        }
        // 回退：本文件位于 vendor/nova-plugins/cron-manager/plugin/，上溯 3 级到 vendor
        return dirname(__DIR__, 3) . '/public/cron/config.json';
    }

    /**
     * 插件初始化：根据 config.json 的 mode 字段应用执行模式
     */
    public function init() {
        $mode = self::get_config_value('mode', self::DEFAULT_MODE);
        $mode = ($mode === 'visit') ? 'visit' : 'server';

        if ($mode === 'visit') {
            Nova_Cron::enable_visit_trigger();
        } else {
            // server 模式（默认）：禁用访问触发，由面板 cron 接管
            Nova_Cron::disable_visit_trigger();
        }
    }

    /**
     * 从 config.json 读取字段值（declarative 配置系统保存于此）
     *
     * @param string $name    字段名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public static function get_config_value($name, $default = null) {
        $file = self::config_file();
        if (!is_file($file)) return $default;
        $data = json_decode(@file_get_contents($file), true);
        if (!is_array($data) || empty($data['tabs'])) return $default;
        foreach ($data['tabs'] as $tab) {
            foreach ($tab['fields'] ?? [] as $field) {
                if (($field['name'] ?? '') === $name) {
                    return $field['value'] ?? $default;
                }
            }
        }
        return $default;
    }

    /**
     * 获取所有已注册任务（合并 DB 执行状态）
     * 注意：调用前需已加载所有插件入口并触发 nova_init，任务才会被注册。
     *
     * @return array 每个元素含 id/interval/description + last_run_at/last_status/last_error/is_due
     */
    public static function get_tasks_with_status() {
        $tasks = Nova_Cron::get_tasks();
        $result = [];
        foreach ($tasks as $t) {
            $state = Nova_Cron::get_last_run($t['id']);
            $result[] = [
                'id'          => $t['id'],
                'description' => $t['description'] ?: '<无描述>',
                'interval'    => self::format_interval($t['interval']),
                'interval_s'  => $t['interval'],
                'last_run_at' => $state['last_run_at'] ?? '—',
                'last_status' => $state['last_status'] ?? '—',
                'last_error'  => $state['last_error'] ?? null,
                'is_due'      => Nova_Cron::is_due($t['id']),
            ];
        }
        return $result;
    }

    /**
     * 把秒数格式化为人类可读的间隔描述
     */
    private static function format_interval($seconds) {
        $seconds = (int)$seconds;
        if ($seconds >= 86400) {
            $d = floor($seconds / 86400);
            $r = $seconds % 86400;
            return $d . ' 天' . ($r >= 3600 ? ' ' . floor($r / 3600) . ' 小时' : '');
        }
        if ($seconds >= 3600) {
            $h = floor($seconds / 3600);
            $r = $seconds % 3600;
            return $h . ' 小时' . ($r >= 60 ? ' ' . floor($r / 60) . ' 分钟' : '');
        }
        if ($seconds >= 60) {
            return floor($seconds / 60) . ' 分钟';
        }
        return $seconds . ' 秒';
    }
}
