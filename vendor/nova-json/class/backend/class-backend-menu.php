<?php
/**
 * Nova JSON API: Nova_Backend_Menu
 *
 * 后台菜单管理类，类似 WP 的 add_menu_page / add_submenu_page。
 * 插件和主题可通过此类注册侧边栏菜单项，无需手动修改 header.php。
 *
 * 用法：
 *   Nova_Backend_Menu::add_menu('插件中心', 'my-plugin', '/admin/my-plugin.php', '插', 30);
 *   Nova_Backend_Menu::add_submenu('my-plugin', '设置', 'my-plugin-settings', '/admin/my-plugin-settings.php');
 *
 *   // 在 header.php 中输出菜单：
 *   Nova_Backend_Menu::render();
 */

defined('NOVA_API') or exit('禁止直接访问');

class Nova_Backend_Menu {

    /** @var array[] 已注册的顶级菜单 */
    protected static $menus = [];

    /** @var array[] 已注册的子菜单 [parent_id => [items]] */
    protected static $submenus = [];

    /** @var bool 是否已排序 */
    protected static $sorted = false;

    /** @var string 当前高亮色 */
    protected static $activeColor = '#0d6efd';

    /**
     * 注册顶级菜单
     *
     * @param string $title       显示名称
     * @param string $id          唯一标识符
     * @param string $url         链接地址（支持 /admin/xxx.php 或外部 URL）
     * @param string $icon        图标（Bootstrap Icons 类名，或单个中文字符）
     * @param int    $position    排序位置（越小越靠前，默认 50）
     * @param array  $options     额外选项：['target' => '_blank', 'badge' => 'New', 'badge_type' => 'danger']
     * @return string 菜单 ID
     */
    public static function add_menu($title, $id, $url, $icon = '', $position = 50, $options = []) {
        self::$menus[$id] = [
            'title'    => $title,
            'id'       => $id,
            'url'      => $url,
            'icon'     => $icon,
            'position' => (int)$position,
            'options'  => $options,
        ];
        self::$sorted = false;
        return $id;
    }

    /**
     * 注册子菜单
     *
     * @param string $parent_id   父级菜单 ID
     * @param string $title       显示名称
     * @param string $id          子菜单唯一标识符
     * @param string $url         链接地址
     * @param int    $position    排序位置（默认 10）
     * @param array  $options     额外选项
     * @return string 子菜单 ID
     */
    public static function add_submenu($parent_id, $title, $id, $url, $position = 10, $options = []) {
        if (!isset(self::$submenus[$parent_id])) {
            self::$submenus[$parent_id] = [];
        }
        self::$submenus[$parent_id][$id] = [
            'title'    => $title,
            'id'       => $id,
            'url'      => $url,
            'position' => (int)$position,
            'options'  => $options,
        ];
        self::$sorted = false;
        return $id;
    }

    /**
     * 移除菜单
     *
     * @param string $id 菜单 ID
     * @return bool
     */
    public static function remove_menu($id) {
        if (isset(self::$menus[$id])) {
            unset(self::$menus[$id]);
            unset(self::$submenus[$id]);
            return true;
        }
        return false;
    }

    /**
     * 移除子菜单
     *
     * @param string $parent_id 父级菜单 ID
     * @param string $id        子菜单 ID
     * @return bool
     */
    public static function remove_submenu($parent_id, $id) {
        if (isset(self::$submenus[$parent_id][$id])) {
            unset(self::$submenus[$parent_id][$id]);
            return true;
        }
        return false;
    }

    /**
     * 获取当前页面文件名
     * @return string
     */
    protected static function getCurrentPage() {
        return basename($_SERVER['PHP_SELF'] ?? '');
    }

    /**
     * 获取当前 URL 路径
     * @return string
     */
    protected static function getCurrentUrl() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return parse_url($uri, PHP_URL_PATH) ?: '';
    }

    /**
     * 判断菜单项是否为当前页
     */
    protected static function isActive($url) {
        $currentUrl = self::getCurrentUrl();
        $currentPage = self::getCurrentPage();

        if ($currentUrl === $url) return true;

        $urlPage = basename(parse_url($url, PHP_URL_PATH));
        if ($urlPage && $urlPage === $currentPage) return true;

        return false;
    }

    /**
     * 判断是否有子菜单处于激活状态
     */
    protected static function hasActiveSubmenu($parent_id) {
        if (!isset(self::$submenus[$parent_id])) return false;
        foreach (self::$submenus[$parent_id] as $item) {
            if (self::isActive($item['url'])) return true;
        }
        return false;
    }

    /**
     * 判断菜单是否有权限显示
     * @param array $menu
     * @return bool
     */
    protected static function canShow($menu) {
        if (isset($menu['options']['capability'])) {
            $cap = $menu['options']['capability'];
            // 如果有自定义权限检查钩子，插件可自行注册
            return (bool)Nova_Hooks::apply_filter('nova_backend_menu_capability', true, $cap);
        }
        return true;
    }

    /**
     * 排序菜单
     */
    protected static function sortMenus() {
        if (self::$sorted) return;

        uasort(self::$menus, function($a, $b) {
            return $a['position'] - $b['position'];
        });

        foreach (self::$submenus as &$subs) {
            uasort($subs, function($a, $b) {
                return $a['position'] - $b['position'];
            });
        }

        self::$sorted = true;
    }

    /**
     * 获取所有已注册的菜单数据
     * @return array
     */
    public static function getMenus() {
        self::sortMenus();
        return [
            'menus'    => self::$menus,
            'submenus' => self::$submenus,
        ];
    }

    /**
     * 渲染侧边栏菜单 HTML
     *
     * @param bool $echo 是否直接输出
     * @return string|null
     */
    public static function render($echo = true) {
        self::sortMenus();

        $html = '';

        foreach (self::$menus as $menu_id => $menu) {
            if (!self::canShow($menu)) continue;

            $hasSubmenu = isset(self::$submenus[$menu_id]) && !empty(self::$submenus[$menu_id]);

            if ($hasSubmenu) {
                $submenuOpen = self::hasActiveSubmenu($menu_id);
                $activeClass = $submenuOpen ? ' open' : '';
                $html .= '<li>';
                $html .= '<a class="submenu-toggle' . $activeClass . '" onclick="toggleSubmenu(this)">';
                $html .= '<span class="menu-icon">' . e($menu['icon']) . '</span>';
                $html .= '<span class="menu-text">' . e($menu['title']) . '</span>';
                $html .= '<span class="submenu-arrow">▶</span>';
                $html .= '</a>';
                $html .= '<ul class="submenu">';

                foreach (self::$submenus[$menu_id] as $sub) {
                    if (!self::canShow($sub)) continue;
                    $active = self::isActive($sub['url']) ? ' active' : '';
                    $badge = '';
                    if (!empty($sub['options']['badge'])) {
                        $bt = !empty($sub['options']['badge_type']) ? ' badge-' . $sub['options']['badge_type'] : '';
                        $badge = ' <span class="badge' . $bt . '">' . e($sub['options']['badge']) . '</span>';
                    }
                    $html .= '<li><a href="' . e($sub['url']) . '" class="' . $active . '">';
                    $html .= '<span class="menu-text">' . e($sub['title']) . $badge . '</span>';
                    $html .= '</a></li>';
                }

                $html .= '</ul>';
                $html .= '</li>';
            } else {
                $active = self::isActive($menu['url']) ? ' active' : '';
                $target = !empty($menu['options']['target']) ? ' target="' . e($menu['options']['target']) . '"' : '';
                $badge = '';
                if (!empty($menu['options']['badge'])) {
                    $bt = !empty($menu['options']['badge_type']) ? ' badge-' . $menu['options']['badge_type'] : '';
                    $badge = ' <span class="badge' . $bt . '">' . e($menu['options']['badge']) . '</span>';
                }

                $html .= '<li>';
                $html .= '<a href="' . e($menu['url']) . '" class="' . $active . '"' . $target . '>';
                $html .= '<span class="menu-icon">' . e($menu['icon']) . '</span>';
                $html .= '<span class="menu-text">' . e($menu['title']) . $badge . '</span>';
                $html .= '</a>';
                $html .= '</li>';
            }
        }

        if ($echo) {
            echo $html;
            return null;
        }

        return $html;
    }

    /**
     * 重置所有菜单
     */
    public static function reset() {
        self::$menus = [];
        self::$submenus = [];
        self::$sorted = false;
    }
}
