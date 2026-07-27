<?php
/**
 * Nova JSON API: Nova_Admin_Page
 *
 * 后台页面基类。插件或主题可以继承此类创建标准化的后台管理页面，
 * 包含权限验证、页面标题、面包屑导航、表单处理等。
 *
 * 用法：
 *   class MySettingsPage extends Nova_Admin_Page {
 *       protected $page_title = '插件设置';
 *       protected $menu_title = '设置';
 *       protected $menu_id    = 'my-settings';
 *       protected $menu_icon  = '设';
 *       protected $menu_position = 15;
 *
 *       public function render() {
 *           $this->header();
 *           echo '<form method="post">...';
 *           $this->footer();
 *       }
 *   }
 *   new MySettingsPage();
 */

defined('NOVA_API') or exit('禁止直接访问');

class Nova_Admin_Page {

    /** @var string 页面标题 */
    protected $page_title = '页面';

    /** @var string 菜单显示名称 */
    protected $menu_title = '';

    /** @var string 菜单唯一 ID */
    protected $menu_id = '';

    /** @var string 菜单图标 */
    protected $menu_icon = '';

    /** @var int 菜单位置 */
    protected $menu_position = 50;

    /** @var string 页面 URL */
    protected $page_url = '';

    /** @var bool 是否要求管理员权限 */
    protected $require_admin = true;

    /** @var array 面包屑导航 */
    protected $breadcrumbs = [];

    /** @var string 页面副标题/描述 */
    protected $page_desc = '';

    /** @var string 父级菜单 ID（设为子菜单时） */
    protected $parent_menu = '';

    /** @var bool 是否已初始化 */
    private static $inited = false;

    /**
     * 构造函数：自动注册菜单和钩子
     */
    public function __construct() {
        if (empty($this->menu_title)) {
            $this->menu_title = $this->page_title;
        }
        if (empty($this->menu_id)) {
            $this->menu_id = sanitize_title($this->menu_title);
        }

        // 自动检测当前页面 URL
        $this->page_url = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($this->page_url, PHP_URL_PATH);
        $this->page_url = $path;

        // 注册菜单
        if (!$this->parent_menu) {
            Nova_Admin_Menu::add_menu(
                $this->menu_title,
                $this->menu_id,
                $this->page_url,
                $this->menu_icon,
                $this->menu_position
            );
        } else {
            Nova_Admin_Menu::add_submenu(
                $this->parent_menu,
                $this->menu_title,
                $this->menu_id,
                $this->page_url,
                $this->menu_position
            );
        }

        // 注册钩子
        Nova_Hooks::add_action('nova_admin_render_' . $this->menu_id, [$this, 'render']);

        // 初始化时自动注册所有页面
        if (!self::$inited) {
            self::$inited = true;
            Nova_Hooks::add_action('nova_init', [__CLASS__, 'register_all']);
        }
    }

    /**
     * 注册所有页面实例（由钩子触发）
     */
    public static function register_all() {
        // 由各页面实例自行注册
    }

    /**
     * 验证用户权限
     * @return bool
     */
    protected function checkPermission() {
        if (!$this->require_admin) {
            return true;
        }
        if (empty($_SESSION['admin_id']) && empty($_SESSION['user_id'])) {
            return false;
        }

        // 检查 admin 角色
        if (!empty($_SESSION['admin_id'])) {
            return true;
        }

        // 检查数据库角色
        $userId = $_SESSION['user_id'] ?? 0;
        if ($userId > 0) {
            return v1_is_admin($userId);
        }

        return false;
    }

    /**
     * 权限不足时的处理
     */
    protected function permissionDenied() {
        if (!headers_sent()) {
            header('HTTP/1.1 403 Forbidden');
        }
        echo '<div class="alert alert-danger">权限不足，请先登录管理员账号。</div>';
        echo '<a href="/admin/login.php" class="btn btn-primary">前往登录</a>';
    }

    /**
     * 输出页面头部（包含 header.php 的内容骨架）
     * 在后台独立页面中使用
     */
    protected function header() {
        if (!$this->checkPermission()) {
            $this->permissionDenied();
            return;
        }
        ?>
        <div class="page-header mb-4">
            <h1 class="h3 mb-2"><?= e($this->page_title) ?></h1>
            <?php if ($this->page_desc): ?>
                <p class="text-muted mb-0"><?= e($this->page_desc) ?></p>
            <?php endif; ?>
            <?php if (!empty($this->breadcrumbs)): ?>
                <nav aria-label="breadcrumb" class="mt-2">
                    <ol class="breadcrumb mb-0">
                        <?php foreach ($this->breadcrumbs as $crumb): ?>
                            <?php if (!empty($crumb['url'])): ?>
                                <li class="breadcrumb-item"><a href="<?= e($crumb['url']) ?>"><?= e($crumb['title']) ?></a></li>
                            <?php else: ?>
                                <li class="breadcrumb-item active"><?= e($crumb['title']) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 输出页面尾部
     */
    protected function footer() {
        // 预留，可被子类重写
    }

    /**
     * 渲染标准卡片容器开始
     * @param string $title 卡片标题
     * @param string $class 额外 CSS 类
     */
    protected function card($title = '', $class = '') {
        ?>
        <div class="card mb-4 <?= e($class) ?>">
            <?php if ($title): ?>
                <div class="card-header bg-white py-3">
                    <h5 class="card-title mb-0"><?= e($title) ?></h5>
                </div>
            <?php endif; ?>
            <div class="card-body">
        <?php
    }

    /**
     * 渲染卡片结束
     */
    protected function endCard() {
        echo '</div></div>';
    }

    /**
     * 显示成功提示
     * @param string $message
     */
    protected function success($message) {
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
        echo e($message);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo '</div>';
    }

    /**
     * 显示错误提示
     * @param string $message
     */
    protected function error($message) {
        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
        echo e($message);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo '</div>';
    }

    /**
     * 显示警告提示
     * @param string $message
     */
    protected function warning($message) {
        echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">';
        echo e($message);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo '</div>';
    }

    /**
     * 渲染表格
     * @param array  $headers 表头 ['字段名' => '显示名']
     * @param array  $rows    数据行
     * @param array  $options 选项（class, empty_text 等）
     */
    protected function table(array $headers, array $rows, array $options = []) {
        $class = $options['class'] ?? 'table table-hover align-middle mb-0';
        $emptyText = $options['empty_text'] ?? '暂无数据';
        ?>
        <div class="table-responsive">
            <table class="<?= e($class) ?>">
                <thead class="table-light">
                    <tr>
                        <?php foreach ($headers as $key => $label): ?>
                            <th><?= e($label) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= count($headers) ?>" class="text-center text-muted py-4"><?= e($emptyText) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php foreach ($headers as $key => $label): ?>
                                    <td><?= isset($row[$key]) ? $row[$key] : '' ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * 渲染表单开始
     * @param array $options action, method, enctype 等
     */
    protected function formOpen($options = []) {
        $action = $options['action'] ?? '';
        $method = $options['method'] ?? 'post';
        $enctype = !empty($options['enctype']) ? ' enctype="' . e($options['enctype']) . '"' : '';
        $class = $options['class'] ?? '';
        ?>
        <form action="<?= e($action) ?>" method="<?= e($method) ?>"<?= $enctype ?> class="<?= e($class) ?>">
            <?php if (strtolower($method) === 'post'): ?>
                <input type="hidden" name="_csrf" value="<?= e($_SESSION['_csrf'] ?? '') ?>">
            <?php endif; ?>
        <?php
    }

    /**
     * 渲染表单结束
     */
    protected function formClose() {
        echo '</form>';
    }

    /**
     * 渲染表单字段
     * @param string $type    字段类型（text, password, textarea, select, checkbox, file 等）
     * @param string $name    字段名
     * @param string $label   标签
     * @param mixed  $value   当前值
     * @param array  $options 额外选项（placeholder, help, required, options 等）
     */
    protected function formField($type, $name, $label, $value = '', $options = []) {
        $id = $options['id'] ?? 'field_' . $name;
        $placeholder = $options['placeholder'] ?? '';
        $help = $options['help'] ?? '';
        $required = !empty($options['required']) ? ' required' : '';
        $class = $options['class'] ?? 'form-control';
        ?>
        <div class="mb-3">
            <label for="<?= e($id) ?>" class="form-label"><?= e($label) ?></label>
            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>

            <?php if ($type === 'textarea'): ?>
                <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" class="<?= e($class) ?>" rows="4"<?= $required ?> placeholder="<?= e($placeholder) ?>"><?= e($value) ?></textarea>

            <?php elseif ($type === 'select'): ?>
                <select id="<?= e($id) ?>" name="<?= e($name) ?>" class="<?= e($class) ?>"<?= $required ?>>
                    <?php $opts = $options['options'] ?? []; ?>
                    <?php foreach ($opts as $optVal => $optLabel): ?>
                        <option value="<?= e($optVal) ?>" <?= $value == $optVal ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ($type === 'checkbox'): ?>
                <div class="form-check">
                    <input type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1" class="form-check-input" <?= $value ? 'checked' : '' ?>>
                    <label class="form-check-label" for="<?= e($id) ?>"><?= e($options['checkbox_label'] ?? '') ?></label>
                </div>

            <?php elseif ($type === 'file'): ?>
                <input type="file" id="<?= e($id) ?>" name="<?= e($name) ?>" class="form-control"<?= $required ?>>

            <?php else: ?>
                <input type="<?= e($type) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" class="<?= e($class) ?>"<?= $required ?> placeholder="<?= e($placeholder) ?>">
            <?php endif; ?>

            <?php if ($help): ?>
                <div class="form-text text-muted"><?= $help ?></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 渲染提交按钮
     * @param string $text  按钮文字
     * @param string $class CSS 类
     */
    protected function submitButton($text = '保存', $class = 'btn btn-primary') {
        echo '<button type="submit" class="' . e($class) . '">' . e($text) . '</button>';
    }

    /**
     * 页面渲染入口（子类需重写）
     */
    public function render() {
        $this->header();
        echo '<p class="text-muted">请重写 render() 方法来自定义页面内容。</p>';
        $this->footer();
    }

    /**
     * 魔术方法：在页面中调用 $this->renderPage() 执行渲染
     */
    public function renderPage() {
        if (!$this->checkPermission()) {
            $this->permissionDenied();
            return;
        }
        $this->render();
    }

    /**
     * 获取当前页面实例的 URL
     * @return string
     */
    public function getUrl() {
        return $this->page_url;
    }
}
