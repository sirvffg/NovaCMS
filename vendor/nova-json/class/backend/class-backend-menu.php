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
     * @param array  $options     额外选项：target、badge、capability、group、group_label、group_order
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
     * Render a menu icon. Bootstrap Icon class names are supported while
     * preserving the original single-character plugin icon contract.
     */
    protected static function renderIcon($icon) {
        $icon = trim((string)$icon);
        if (preg_match('/^bi-[a-z0-9-]+$/', $icon)) {
            return '<i class="bi ' . e($icon) . '" aria-hidden="true"></i>';
        }
        return e($icon);
    }

    /**
     * 菜单分组用于呈现清晰的内容、系统和工具区块。未声明分组的旧插件菜单
     * 会自动进入“扩展”区，原 add_menu / add_submenu 调用无需调整。
     */
    protected static function getGroup($menu) {
        $group = trim((string)($menu['options']['group'] ?? 'extensions'));
        return $group !== '' ? $group : 'extensions';
    }

    protected static function getGroupLabel($menu) {
        if (array_key_exists('group_label', $menu['options'])) {
            return trim((string)$menu['options']['group_label']);
        }

        $labels = [
            'primary'    => '',
            'content'    => '内容',
            'system'     => '系统',
            'tools'      => '工具',
            'extensions' => '扩展',
        ];
        $group = self::getGroup($menu);
        return $labels[$group] ?? '扩展';
    }

    protected static function getGroupOrder($menu) {
        if (isset($menu['options']['group_order']) && is_numeric($menu['options']['group_order'])) {
            return (int)$menu['options']['group_order'];
        }

        $orders = [
            'primary'    => 0,
            'content'    => 100,
            'system'     => 200,
            'tools'      => 300,
            'extensions' => 400,
        ];
        return $orders[self::getGroup($menu)] ?? 400;
    }

    protected static function cssToken($value) {
        $token = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)$value);
        return trim((string)$token, '-') ?: 'item';
    }

    protected static function renderBadge($options) {
        if (empty($options['badge'])) return '';

        $type = !empty($options['badge_type'])
            ? ' badge-' . self::cssToken($options['badge_type'])
            : '';
        return '<span class="sidebar-badge' . $type . '">' . e($options['badge']) . '</span>';
    }

    protected static function renderTargetAttributes($options) {
        if (empty($options['target'])) return '';

        $target = (string)$options['target'];
        $attributes = ' target="' . e($target) . '"';
        if ($target === '_blank') {
            $attributes .= ' rel="noopener"';
        }
        return $attributes;
    }

    /**
     * 排序菜单
     */
    protected static function sortMenus() {
        if (self::$sorted) return;

        uasort(self::$menus, function($a, $b) {
            $groupOrder = self::getGroupOrder($a) <=> self::getGroupOrder($b);
            if ($groupOrder !== 0) return $groupOrder;

            $group = strcmp(self::getGroup($a), self::getGroup($b));
            if ($group !== 0) return $group;

            $position = $a['position'] <=> $b['position'];
            if ($position !== 0) return $position;

            return strcmp((string)$a['id'], (string)$b['id']);
        });

        foreach (self::$submenus as &$subs) {
            uasort($subs, function($a, $b) {
                $position = $a['position'] <=> $b['position'];
                if ($position !== 0) return $position;
                return strcmp((string)$a['id'], (string)$b['id']);
            });
        }
        unset($subs);

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
        $lastGroup = null;

        foreach (self::$menus as $menu_id => $menu) {
            if (!self::canShow($menu)) continue;

            $visibleSubmenus = [];
            if (!empty(self::$submenus[$menu_id])) {
                foreach (self::$submenus[$menu_id] as $subId => $sub) {
                    if (self::canShow($sub)) {
                        $visibleSubmenus[$subId] = $sub;
                    }
                }
            }

            $hasSubmenu = !empty($visibleSubmenus);
            if (!$hasSubmenu && trim((string)$menu['url']) === '') continue;

            $group = self::getGroup($menu);
            if ($group !== $lastGroup) {
                $groupLabel = self::getGroupLabel($menu);
                if ($groupLabel !== '') {
                    $html .= '<li class="sidebar-menu-section"><span>' . e($groupLabel) . '</span></li>';
                }
                $lastGroup = $group;
            }

            $menuToken = self::cssToken($menu_id);
            $itemClasses = 'menu-item menu-item--' . $menuToken;

            if ($hasSubmenu) {
                $submenuOpen = self::hasActiveSubmenu($menu_id);
                $activeClass = $submenuOpen ? ' open is-current' : '';
                $submenuId = 'admin-submenu-' . $menuToken;
                $html .= '<li class="' . $itemClasses . ' has-submenu">';
                $html .= '<button type="button" class="submenu-toggle' . $activeClass . '" aria-expanded="' . ($submenuOpen ? 'true' : 'false') . '" aria-controls="' . e($submenuId) . '" data-menu-label="' . e($menu['title']) . '">';
                $html .= '<span class="menu-icon">' . self::renderIcon($menu['icon']) . '</span>';
                $html .= '<span class="menu-text">' . e($menu['title']) . '</span>';
                $html .= self::renderBadge($menu['options']);
                $html .= '<span class="submenu-arrow"><i class="bi bi-chevron-right" aria-hidden="true"></i></span>';
                $html .= '</button>';
                $html .= '<ul class="submenu" id="' . e($submenuId) . '" aria-label="' . e($menu['title']) . '子菜单" aria-hidden="' . ($submenuOpen ? 'false' : 'true') . '"' . ($submenuOpen ? '' : ' inert') . '>';

                foreach ($visibleSubmenus as $subId => $sub) {
                    $isActive = self::isActive($sub['url']);
                    $active = $isActive ? ' active' : '';
                    $current = $isActive ? ' aria-current="page"' : '';
                    $html .= '<li class="submenu-item submenu-item--' . self::cssToken($subId) . '"><a href="' . e($sub['url']) . '" class="menu-link' . $active . '" data-menu-label="' . e($sub['title']) . '"' . self::renderTargetAttributes($sub['options']) . $current . '>';
                    $iconHtml = !empty($sub['options']['icon']) ? '<span class="menu-icon menu-icon-sub">' . self::renderIcon($sub['options']['icon']) . '</span>' : '<span class="submenu-dot" aria-hidden="true"></span>';
                    $html .= $iconHtml;
                    $html .= '<span class="menu-text">' . e($sub['title']) . '</span>';
                    $html .= self::renderBadge($sub['options']);
                    $html .= '</a></li>';
                }

                $html .= '</ul>';
                $html .= '</li>';
            } else {
                $isActive = self::isActive($menu['url']);
                $active = $isActive ? ' active' : '';
                $current = $isActive ? ' aria-current="page"' : '';

                $html .= '<li class="' . $itemClasses . '">';
                $html .= '<a href="' . e($menu['url']) . '" class="menu-link' . $active . '" data-menu-label="' . e($menu['title']) . '"' . self::renderTargetAttributes($menu['options']) . $current . '>';
                $html .= '<span class="menu-icon">' . self::renderIcon($menu['icon']) . '</span>';
                $html .= '<span class="menu-text">' . e($menu['title']) . '</span>';
                $html .= self::renderBadge($menu['options']);
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
