<?php
/**
 * Nova JSON API: Nova_Backend_Page
 *
 * 后台页面基类。插件或主题可以继承此类创建标准化的后台管理页面，
 * 包含权限验证、页面标题、面包屑导航、表单处理、列表表格等。
 *
 * 用法：
 *   class MySettingsPage extends Nova_Backend_Page {
 *       protected $page_title = '插件设置';
 *       protected $menu_title = '设置';
 *       protected $menu_id    = 'my-settings';
 *       protected $menu_icon  = '设';
 *       protected $menu_position = 15;
 *
 *       public function render() {
 *           $this->header();
 *           $this->card('基本配置');
 *           $this->formOpen(['action' => '']);
 *           $this->formField('text', 'api_key', 'API Key');
 *           $this->submitButton('保存');
 *           $this->formClose();
 *           $this->endCard();
 *           $this->footer();
 *       }
 *   }
 *   new MySettingsPage();
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_Backend_Page {

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

    /** @var string 页面模板路径 */
    protected $template = '';

    /** @var array 模板数据 */
    protected $viewData = [];

    /** @var array 页面内联 CSS */
    protected $inlineCss = [];

    /** @var array 页面内联 JS */
    protected $inlineJs = [];

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

        $this->page_url = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($this->page_url, PHP_URL_PATH);
        $this->page_url = $path;

        // 注册菜单
        if (!$this->parent_menu) {
            Nova_Backend_Menu::add_menu(
                $this->menu_title,
                $this->menu_id,
                $this->page_url,
                $this->menu_icon,
                $this->menu_position
            );
        } else {
            Nova_Backend_Menu::add_submenu(
                $this->parent_menu,
                $this->menu_title,
                $this->menu_id,
                $this->page_url,
                $this->menu_position
            );
        }

        Nova_Hooks::add_action('nova_backend_render_' . $this->menu_id, [$this, 'render']);

        if (!self::$inited) {
            self::$inited = true;
            Nova_Hooks::add_action('nova_init', [__CLASS__, 'register_all']);
        }
    }

    public static function register_all() {}

    /**
     * 验证用户权限
     * @return bool
     */
    protected function checkPermission() {
        if (!$this->require_admin) return true;
        if (empty($_SESSION['admin_id']) && empty($_SESSION['user_id'])) return false;
        if (!empty($_SESSION['admin_id'])) return true;
        $userId = $_SESSION['user_id'] ?? 0;
        return $userId > 0 && v1_is_admin($userId);
    }

    /**
     * 权限不足处理
     */
    protected function permissionDenied() {
        if (!headers_sent()) header('HTTP/1.1 403 Forbidden');
        echo '<div class="alert alert-danger">权限不足，请先登录管理员账号。</div>';
        echo '<a href="/admin/login.php" class="btn btn-primary">前往登录</a>';
    }

    /**
     * 设置模板数据
     * @param string|array $key
     * @param mixed $value
     * @return $this
     */
    public function with($key, $value = null) {
        if (is_array($key)) {
            $this->viewData = array_merge($this->viewData, $key);
        } else {
            $this->viewData[$key] = $value;
        }
        return $this;
    }

    /**
     * 添加面包屑
     * @param string $title
     * @param string $url
     * @return $this
     */
    public function addBreadcrumb($title, $url = '') {
        $this->breadcrumbs[] = ['title' => $title, 'url' => $url];
        return $this;
    }

    /**
     * 添加内联 CSS
     * @param string $css
     * @return $this
     */
    public function addCss($css) {
        $this->inlineCss[] = $css;
        return $this;
    }

    /**
     * 添加内联 JS
     * @param string $js
     * @return $this
     */
    public function addJs($js) {
        $this->inlineJs[] = $js;
        return $this;
    }

    /**
     * 获取内联 CSS
     */
    public function getInlineCss() {
        if (empty($this->inlineCss)) return '';
        return '<style>' . implode("\n", $this->inlineCss) . '</style>';
    }

    /**
     * 获取内联 JS
     */
    public function getInlineJs() {
        if (empty($this->inlineJs)) return '';
        return '<script>' . implode("\n", $this->inlineJs) . '</script>';
    }

    /**
     * 输出页面头部
     */
    protected function header() {
        if (!$this->checkPermission()) {
            $this->permissionDenied();
            return;
        }
        echo $this->getInlineCss();
        ?>
        <div class="page-header mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap">
                <div>
                    <h1 class="h3 mb-2"><?= e($this->page_title) ?></h1>
                    <?php if ($this->page_desc): ?>
                        <p class="text-muted mb-0"><?= e($this->page_desc) ?></p>
                    <?php endif; ?>
                </div>
                <?php $this->headerActions(); ?>
            </div>
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
     * 页面头部右侧操作按钮区域（可被子类重写）
     */
    protected function headerActions() {}

    /**
     * 输出页面尾部
     */
    protected function footer() {
        echo $this->getInlineJs();
    }

    /**
     * 卡片容器开始
     * @param string $title
     * @param string $class
     */
    protected function card($title = '', $class = '') {
        ?>
        <div class="card mb-4 <?= e($class) ?>">
            <?php if ($title): ?>
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><?= e($title) ?></h5>
                    <?php $this->cardActions(); ?>
                </div>
            <?php endif; ?>
            <div class="card-body">
        <?php
    }

    /**
     * 卡片操作区域（可被子类重写）
     */
    protected function cardActions() {}

    /**
     * 卡片结束
     */
    protected function endCard() {
        echo '</div></div>';
    }

    /**
     * 显示提示消息
     * @param string $type    success | error | warning | info
     * @param string $message
     */
    protected function alert($type, $message) {
        $map = [
            'success' => 'alert-success',
            'error'   => 'alert-danger',
            'warning' => 'alert-warning',
            'info'    => 'alert-info',
        ];
        $class = $map[$type] ?? 'alert-info';
        echo '<div class="alert ' . $class . ' alert-dismissible fade show" role="alert">';
        echo e($message);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo '</div>';
    }

    protected function success($msg) { $this->alert('success', $msg); }
    protected function error($msg)   { $this->alert('error', $msg); }
    protected function warning($msg) { $this->alert('warning', $msg); }

    /**
     * 渲染数据表格
     * @param array $headers ['field' => '显示名', ...]
     * @param array $rows    [['field' => 'value', ...], ...]
     * @param array $options
     */
    protected function table(array $headers, array $rows, array $options = []) {
        $class = $options['class'] ?? 'table table-hover align-middle mb-0';
        $emptyText = $options['empty_text'] ?? '暂无数据';
        $actions = $options['actions'] ?? [];  // [['label' => '编辑', 'url' => '/edit.php?id={id}', 'class' => 'btn-sm']]
        ?>
        <div class="table-responsive">
            <table class="<?= e($class) ?>">
                <?php if (!empty($options['caption'])): ?>
                    <caption><?= e($options['caption']) ?></caption>
                <?php endif; ?>
                <thead class="table-light">
                    <tr>
                        <?php foreach ($headers as $key => $label): ?>
                            <th><?= e($label) ?></th>
                        <?php endforeach; ?>
                        <?php if (!empty($actions)): ?>
                            <th class="text-end" style="width:120px">操作</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= count($headers) + (!empty($actions) ? 1 : 0) ?>" class="text-center text-muted py-4"><?= e($emptyText) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php foreach ($headers as $key => $label): ?>
                                    <td><?= isset($row[$key]) ? $row[$key] : '' ?></td>
                                <?php endforeach; ?>
                                <?php if (!empty($actions)): ?>
                                    <td class="text-end text-nowrap">
                                        <?php foreach ($actions as $act): ?>
                                            <?php
                                            $url = str_replace(['{id}', '{key}'], [$row['id'] ?? '', $row['key'] ?? ''], $act['url']);
                                            $cls = $act['class'] ?? 'btn btn-outline-secondary btn-sm';
                                            ?>
                                            <a href="<?= e($url) ?>" class="<?= e($cls) ?>"><?= e($act['label']) ?></a>
                                        <?php endforeach; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * 生成分页 HTML
     * @param int $total   总数
     * @param int $perPage 每页数量
     * @param int $current 当前页码
     * @param string $baseUrl 基础 URL
     */
    protected function pagination($total, $perPage = 20, $current = null, $baseUrl = '') {
        if ($total <= $perPage) return;

        $current = $current ?: max(1, (int)($_GET['page'] ?? 1));
        $pages   = (int)ceil($total / max(1, $perPage));
        $baseUrl = $baseUrl ?: parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $query   = $_GET;
        ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php if ($current > 1): ?>
                    <?php $q = array_merge($query, ['page' => $current - 1]); ?>
                    <li class="page-item"><a class="page-link" href="<?= e($baseUrl) ?>?<?= http_build_query($q) ?>">上一页</a></li>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <?php if ($i == $current): ?>
                        <li class="page-item active"><span class="page-link"><?= $i ?></span></li>
                    <?php else: ?>
                        <?php $q = array_merge($query, ['page' => $i]); ?>
                        <li class="page-item"><a class="page-link" href="<?= e($baseUrl) ?>?<?= http_build_query($q) ?>"><?= $i ?></a></li>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($current < $pages): ?>
                    <?php $q = array_merge($query, ['page' => $current + 1]); ?>
                    <li class="page-item"><a class="page-link" href="<?= e($baseUrl) ?>?<?= http_build_query($q) ?>">下一页</a></li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php
    }

    /**
     * 搜索框
     * @param string $keyword 当前关键词
     * @param string $placeholder
     */
    protected function searchBox($keyword = '', $placeholder = '搜索...') {
        $action = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        ?>
        <form method="get" action="<?= e($action) ?>" class="mb-3">
            <div class="input-group" style="max-width:400px">
                <input type="text" name="s" class="form-control" placeholder="<?= e($placeholder) ?>" value="<?= e($keyword) ?>">
                <button class="btn btn-outline-secondary" type="submit">搜索</button>
                <?php if ($keyword): ?>
                    <a href="<?= e($action) ?>" class="btn btn-outline-danger">清除</a>
                <?php endif; ?>
            </div>
        </form>
        <?php
    }

    /**
     * 渲染表单开始
     */
    protected function formOpen($options = []) {
        $action  = $options['action'] ?? '';
        $method  = $options['method'] ?? 'post';
        $enctype = !empty($options['enctype']) ? ' enctype="' . e($options['enctype']) . '"' : '';
        $class   = $options['class'] ?? '';
        ?>
        <form action="<?= e($action) ?>" method="<?= e($method) ?>"<?= $enctype ?> class="<?= e($class) ?>">
            <?php if (strtolower($method) === 'post'): ?>
                <input type="hidden" name="_csrf" value="<?= e($_SESSION['_csrf'] ?? '') ?>">
            <?php endif; ?>
        <?php
    }

    /**
     * 表单结束
     */
    protected function formClose() {
        echo '</form>';
    }

    /**
     * 渲染表单字段
     * @param string $type    字段类型
     * @param string $name    字段名
     * @param string $label   标签
     * @param mixed  $value   当前值
     * @param array  $options
     */
    protected function formField($type, $name, $label, $value = '', $options = []) {
        $id = $options['id'] ?? 'field_' . $name;
        $placeholder = $options['placeholder'] ?? '';
        $help        = $options['help'] ?? '';
        $required    = !empty($options['required']) ? ' required' : '';
        $class       = $options['class'] ?? 'form-control';
        $inputGroup  = $options['input_group'] ?? []; // ['prepend' => '...', 'append' => '...']
        ?>
        <div class="mb-3">
            <label for="<?= e($id) ?>" class="form-label"><?= e($label) ?></label>
            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>

            <?php if ($type === 'hidden'): ?>
                <input type="hidden" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>">

            <?php elseif ($type === 'textarea'): ?>
                <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" class="<?= e($class) ?>" rows="4"<?= $required ?> placeholder="<?= e($placeholder) ?>"><?= e($value) ?></textarea>

            <?php elseif ($type === 'select'): ?>
                <select id="<?= e($id) ?>" name="<?= e($name) ?>" class="<?= e($class) ?>"<?= $required ?>>
                    <?php if (!empty($options['placeholder_option'])): ?>
                        <option value=""><?= e($options['placeholder_option']) ?></option>
                    <?php endif; ?>
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

            <?php elseif ($type === 'radio'): ?>
                <div class="mt-1">
                    <?php $radios = $options['options'] ?? []; ?>
                    <?php foreach ($radios as $rVal => $rLabel): ?>
                        <div class="form-check form-check-inline">
                            <input type="radio" id="<?= e($id) ?>_<?= e($rVal) ?>" name="<?= e($name) ?>" value="<?= e($rVal) ?>" class="form-check-input" <?= $value == $rVal ? 'checked' : '' ?>>
                            <label class="form-check-label" for="<?= e($id) ?>_<?= e($rVal) ?>"><?= e($rLabel) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($type === 'file'): ?>
                <input type="file" id="<?= e($id) ?>" name="<?= e($name) ?>" class="form-control"<?= $required ?>>

            <?php elseif ($type === 'color'): ?>
                <input type="color" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" class="form-control form-control-color" style="width:60px;height:38px">

            <?php elseif ($type === 'number'): ?>
                <input type="number" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" class="<?= e($class) ?>"<?= $required ?>
                    <?= isset($options['min']) ? ' min="' . (int)$options['min'] . '"' : '' ?>
                    <?= isset($options['max']) ? ' max="' . (int)$options['max'] . '"' : '' ?>
                    <?= isset($options['step']) ? ' step="' . $options['step'] . '"' : '' ?>>

            <?php elseif ($type === 'switch'): ?>
                <div class="form-check form-switch">
                    <input type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1" class="form-check-input" <?= $value ? 'checked' : '' ?>>
                    <label class="form-check-label" for="<?= e($id) ?>"><?= e($options['checkbox_label'] ?? $label) ?></label>
                </div>

            <?php else: ?>
                <?php if (!empty($inputGroup)): ?>
                <div class="input-group">
                <?php endif; ?>
                <?php if (!empty($inputGroup['prepend'])): ?>
                    <span class="input-group-text"><?= e($inputGroup['prepend']) ?></span>
                <?php endif; ?>
                <input type="<?= e($type) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" class="<?= e($class) ?>"<?= $required ?> placeholder="<?= e($placeholder) ?>">
                <?php if (!empty($inputGroup['append'])): ?>
                    <span class="input-group-text"><?= e($inputGroup['append']) ?></span>
                <?php endif; ?>
                <?php if (!empty($inputGroup)): ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($help): ?>
                <div class="form-text text-muted"><?= $help ?></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 提交按钮
     */
    protected function submitButton($text = '保存', $class = 'btn btn-primary') {
        echo '<button type="submit" class="' . e($class) . '">' . e($text) . '</button>';
    }

    /**
     * 页面渲染入口（子类重写）
     */
    public function render() {
        $this->header();
        echo '<p class="text-muted">请重写 render() 方法来自定义页面内容。</p>';
        $this->footer();
    }

    /**
     * 执行页面渲染（外部调用）
     */
    public function renderPage() {
        if (!$this->checkPermission()) {
            $this->permissionDenied();
            return;
        }
        $this->render();
    }

    /**
     * 获取当前页面 URL
     * @return string
     */
    public function getUrl() {
        return $this->page_url;
    }
}
