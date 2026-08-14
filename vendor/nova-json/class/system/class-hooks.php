<?php
/**
 * Nova JSON API: Nova_Hooks
 *
 * Actions & Filters 钩子系统，类似 WordPress 的 add_action / add_filter。
 *
 * 用法：
 *   Nova_Hooks::add_action('nova_init', 'my_function');
 *   Nova_Hooks::do_action('nova_init');
 *
 *   Nova_Hooks::add_filter('nova_post_data', 'my_filter');
 *   $data = Nova_Hooks::apply_filters('nova_post_data', $data);
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_Hooks {

    protected static $actions = [];
    protected static $filters = [];

    // ── Actions ──

    /**
     * 注册一个动作钩子
     * @param string   $tag      钩子名称
     * @param callable $callback 回调函数
     * @param int      $priority 优先级，越小越先执行（默认 10）
     */
    public static function add_action($tag, $callback, $priority = 10) {
        self::$actions[$tag][$priority][] = $callback;
    }

    /**
     * 执行动作钩子
     * @param string $tag  钩子名称
     * @param mixed  ...$args 参数
     */
    public static function do_action($tag, ...$args) {
        if (empty(self::$actions[$tag])) return;
        ksort(self::$actions[$tag]);
        foreach (self::$actions[$tag] as $callbacks) {
            foreach ($callbacks as $callback) {
                call_user_func_array($callback, $args);
            }
        }
    }

    /**
     * 移除一个动作钩子
     */
    public static function remove_action($tag, $callback, $priority = 10) {
        if (empty(self::$actions[$tag][$priority])) return;
        $key = array_search($callback, self::$actions[$tag][$priority], true);
        if ($key !== false) {
            unset(self::$actions[$tag][$priority][$key]);
        }
    }

    /**
     * 检查动作钩子是否已注册
     */
    public static function has_action($tag, $callback = null) {
        if (!isset(self::$actions[$tag])) return false;
        if ($callback === null) return !empty(self::$actions[$tag]);
        foreach (self::$actions[$tag] as $callbacks) {
            if (in_array($callback, $callbacks, true)) return true;
        }
        return false;
    }

    // ── Filters ──

    /**
     * 注册一个过滤器钩子
     * @param string   $tag      钩子名称
     * @param callable $callback 回调函数（接收 $value 并返回修改后的值）
     * @param int      $priority 优先级，越小越先执行
     */
    public static function add_filter($tag, $callback, $priority = 10) {
        self::$filters[$tag][$priority][] = $callback;
    }

    /**
     * 执行过滤器钩子
     * @param string $tag   钩子名称
     * @param mixed  $value 要过滤的值
     * @param mixed  ...$args 额外参数
     * @return mixed 过滤后的值
     */
    public static function apply_filters($tag, $value, ...$args) {
        if (empty(self::$filters[$tag])) return $value;
        ksort(self::$filters[$tag]);
        foreach (self::$filters[$tag] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = call_user_func_array($callback, array_merge([$value], $args));
            }
        }
        return $value;
    }

    /**
     * 移除一个过滤器钩子
     */
    public static function remove_filter($tag, $callback, $priority = 10) {
        if (empty(self::$filters[$tag][$priority])) return;
        $key = array_search($callback, self::$filters[$tag][$priority], true);
        if ($key !== false) {
            unset(self::$filters[$tag][$priority][$key]);
        }
    }

    /**
     * 检查过滤器钩子是否已注册
     */
    public static function has_filter($tag, $callback = null) {
        if (!isset(self::$filters[$tag])) return false;
        if ($callback === null) return !empty(self::$filters[$tag]);
        foreach (self::$filters[$tag] as $callbacks) {
            if (in_array($callback, $callbacks, true)) return true;
        }
        return false;
    }

    // ── 辅助 ──

    /**
     * 清空所有钩子（用于测试）
     */
    public static function clear_all() {
        self::$actions = [];
        self::$filters = [];
    }
}
