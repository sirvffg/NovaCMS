<?php
/**
 * Nova JSON API: Nova_Backend_Notice
 *
 * 后台通知/提示消息管理类。
 * 插件可以在后台任意位置显示一次性或持久化的提示消息，
 * 类似 WP 的 admin_notices。
 *
 * 用法：
 *   // 在后台任意位置添加消息
 *   Nova_Backend_Notice::add('插件已激活', 'success');
 *   Nova_Backend_Notice::add('配置有误', 'error', true); // 持久化到 Session
 *
 *   // 在 header.php 或页面中输出所有消息
 *   Nova_Backend_Notice::render();
 *
 *   // 检查权限
 *   Nova_Backend_Notice::hasCapability('manage_options');
 */

defined('NOVA_API') or exit('禁止直接访问');

class Nova_Backend_Notice {

    const SESSION_KEY = '_nova_backend_notices';

    /** @var array 内存中的通知列表 */
    protected static $notices = [];

    /**
     * 添加通知消息
     *
     * @param string $message  消息内容
     * @param string $type     类型：success, error, warning, info
     * @param bool   $persist  是否持久化到 Session（跨请求显示）
     * @param array  $options  额外选项：['dismissible' => true, 'icon' => '...']
     */
    public static function add($message, $type = 'info', $persist = false, $options = []) {
        $notice = array_merge([
            'message'      => $message,
            'type'         => $type,
            'dismissible'  => $options['dismissible'] ?? true,
            'icon'         => $options['icon'] ?? '',
            'created_at'   => time(),
        ], $options);

        if ($persist && session_status() === PHP_SESSION_ACTIVE) {
            $sessionNotices = $_SESSION[self::SESSION_KEY] ?? [];
            $sessionNotices[] = $notice;
            $_SESSION[self::SESSION_KEY] = $sessionNotices;
        } else {
            self::$notices[] = $notice;
        }
    }

    /**
     * 快捷：成功消息
     * @param string $msg
     * @param bool   $persist
     */
    public static function success($msg, $persist = true) {
        self::add($msg, 'success', $persist);
    }

    /**
     * 快捷：错误消息
     * @param string $msg
     * @param bool   $persist
     */
    public static function error($msg, $persist = true) {
        self::add($msg, 'error', $persist);
    }

    /**
     * 快捷：警告消息
     * @param string $msg
     * @param bool   $persist
     */
    public static function warning($msg, $persist = true) {
        self::add($msg, 'warning', $persist);
    }

    /**
     * 快捷：信息消息
     * @param string $msg
     * @param bool   $persist
     */
    public static function info($msg, $persist = true) {
        self::add($msg, 'info', $persist);
    }

    /**
     * 获取所有待显示的通知
     * @return array
     */
    public static function getNotices() {
        $notices = self::$notices;

        // 从 Session 取出持久化消息
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[self::SESSION_KEY])) {
            $notices = array_merge($notices, $_SESSION[self::SESSION_KEY]);
            $_SESSION[self::SESSION_KEY] = []; // 取出后清空
        }

        return $notices;
    }

    /**
     * 渲染所有通知消息
     * @param bool $echo 是否直接输出
     * @return string|null
     */
    public static function render($echo = true) {
        $notices = self::getNotices();
        if (empty($notices)) {
            return $echo ? null : '';
        }

        $typeMap = [
            'success' => 'alert-success',
            'error'   => 'alert-danger',
            'warning' => 'alert-warning',
            'info'    => 'alert-primary',
        ];

        $html = '<div class="nova-notices">';
        foreach ($notices as $n) {
            $cls = $typeMap[$n['type']] ?? 'alert-primary';
            $dismiss = !empty($n['dismissible']) ? ' alert-dismissible fade show' : '';
            $iconHtml = !empty($n['icon']) ? '<span class="me-1">' . e($n['icon']) . '</span> ' : '';
            $id = 'notice-' . md5($n['message'] . $n['type']);

            $html .= '<div id="' . $id . '" class="alert ' . $cls . $dismiss . '" role="alert">';
            $html .= $iconHtml . e($n['message']);
            if ($dismiss) {
                $html .= '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        if ($echo) {
            echo $html;
            return null;
        }
        return $html;
    }

    /**
     * 清除所有通知
     */
    public static function clear() {
        self::$notices = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    /**
     * 检查用户是否拥有指定权限（占位方法，插件可通过 Hook 扩展）
     * @param string $cap
     * @return bool
     */
    public static function hasCapability($cap) {
        // 默认内置权限表
        $caps = [
            'manage_options' => true,
            'edit_posts'     => true,
            'publish_posts'  => true,
            'delete_posts'   => true,
            'upload_files'   => true,
        ];
        $allowed = $caps[$cap] ?? false;
        return (bool)Nova_Hooks::apply_filter('nova_backend_user_capability', $allowed, $cap);
    }
}
