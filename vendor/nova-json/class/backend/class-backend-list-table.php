<?php
/**
 * Nova JSON API: Nova_Backend_List_Table
 *
 * 数据列表表格类，类似 WordPress 的 WP_List_Table。
 * 提供分页、搜索、排序、批量操作等功能，适用于后台数据管理页面。
 *
 * 用法：
 *   class MyPostsTable extends Nova_Backend_List_Table {
 *       public function prepareItems() {
 *           $this->data = get_posts();      // 从数据库获取数据
 *           $this->total = count_all_posts();
 *       }
 *       public function column($row, $col) {
 *           if ($col === 'actions') return '<a href="...">编辑</a>';
 *           return e($row[$col] ?? '');
 *       }
 *   }
 *   $table = new MyPostsTable(['per_page' => 20]);
 *   $table->render();
 */

defined('NOVA_API') or exit('禁止直接访问');

class Nova_Backend_List_Table {

    /** @var array 当前页数据行 */
    protected $data = [];

    /** @var int 数据总数 */
    protected $total = 0;

    /** @var array 列配置 ['field' => '显示名称'] */
    protected $columns = [];

    /** @var array 额外选项 */
    protected $options = [];

    /** @var int 每页数量 */
    protected $perPage = 20;

    /** @var int 当前页码 */
    protected $currentPage = 1;

    /** @var string 搜索关键词 */
    protected $search = '';

    /** @var string 排序字段 */
    protected $sortBy = '';

    /** @var string 排序方向 */
    protected $sortOrder = 'ASC';

    /** @var array 批量操作选项 */
    protected $bulkActions = [];

    /** @var string 行 CSS 类回调 */
    protected $rowClass = '';

    /** @var bool 是否显示序号列 */
    protected $showRowNumber = false;

    /** @var bool 是否已准备数据 */
    protected $prepared = false;

    /**
     * @param array $options
     *  - per_page       每页数量
     *  - columns        列定义 ['field' => '显示名']
     *  - bulk_actions   批量操作 ['delete' => '删除']
     *  - sort_by        默认排序字段
     *  - sort_order     默认排序方向
     *  - search         是否启用搜索
     *  - row_number     是否显示序号
     *  - empty_text     空数据提示文字
     *  - class          表格 CSS 类
     */
    public function __construct($options = []) {
        $this->options = $options;
        $this->perPage  = $options['per_page'] ?? 20;
        $this->columns  = $options['columns'] ?? [];
        $this->showRowNumber = !empty($options['row_number']);

        if (!empty($options['bulk_actions'])) {
            $this->bulkActions = $options['bulk_actions'];
        }

        // 从请求获取当前页码
        $this->currentPage = max(1, (int)($_GET['paged'] ?? 1));
        $this->search      = trim($_GET['s'] ?? '');

        // 排序
        if (!empty($_GET['orderby'])) {
            $this->sortBy = $_GET['orderby'];
            $this->sortOrder = strtoupper($_GET['order'] ?? 'ASC');
            if (!in_array($this->sortOrder, ['ASC', 'DESC'])) {
                $this->sortOrder = 'ASC';
            }
        }

        $this->rowClass = $options['row_class'] ?? '';
    }

    /**
     * 准备数据（子类应重写此方法）
     * 设置 $this->data 和 $this->total
     */
    public function prepareItems() {
        // 子类重写
    }

    /**
     * 渲染单元格内容（子类可重写）
     * @param array  $row  当前行数据
     * @param string $col  字段名
     * @return string
     */
    public function column($row, $col) {
        if ($col === 'actions') {
            return '';
        }
        return e($row[$col] ?? '');
    }

    /**
     * 渲染行额外属性
     * @param array $row
     * @return string
     */
    public function rowAttrs($row) {
        return '';
    }

    /**
     * 获取数据（自动应用分页和搜索）
     */
    protected function fetchData() {
        if (!$this->prepared) {
            $this->prepareItems();
            $this->prepared = true;
        }

        // 如果有 search 但子类没处理，过滤
        $filtered = $this->data;
        if ($this->search) {
            $filtered = array_filter($filtered, function($row) {
                foreach ($row as $val) {
                    if (is_string($val) && mb_stripos($val, $this->search) !== false) {
                        return true;
                    }
                }
                return false;
            });
            $this->total = count($filtered);
        }

        // 排序
        if ($this->sortBy && !empty($filtered)) {
            $field = $this->sortBy;
            $order = $this->sortOrder;
            usort($filtered, function($a, $b) use ($field, $order) {
                $va = $a[$field] ?? '';
                $vb = $b[$field] ?? '';
                $cmp = is_numeric($va) && is_numeric($vb) ? $va - $vb : strcmp($va, $vb);
                return $order === 'DESC' ? -$cmp : $cmp;
            });
        }

        // 分页
        $offset = ($this->currentPage - 1) * $this->perPage;
        return array_slice($filtered, $offset, $this->perPage);
    }

    /**
     * 获取总页数
     */
    public function totalPages() {
        return (int)ceil($this->total / max(1, $this->perPage));
    }

    /**
     * 渲染搜索框
     */
    protected function renderSearch() {
        if (empty($this->options['search'])) return;
        $action = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        ?>
        <form method="get" action="<?= e($action) ?>" class="mb-3">
            <div class="input-group" style="max-width:400px">
                <input type="text" name="s" class="form-control" placeholder="搜索..." value="<?= e($this->search) ?>">
                <button class="btn btn-outline-secondary" type="submit">搜索</button>
                <?php if ($this->search): ?>
                    <a href="<?= e($action) ?>" class="btn btn-outline-danger">清除</a>
                <?php endif; ?>
            </div>
        </form>
        <?php
    }

    /**
     * 渲染批量操作
     */
    protected function renderBulkActions() {
        if (empty($this->bulkActions)) return;
        ?>
        <div class="bulk-actions mb-3 d-flex align-items-center gap-2">
            <select class="form-select form-select-sm" style="width:auto" name="_bulk_action">
                <option value="">批量操作</option>
                <?php foreach ($this->bulkActions as $val => $label): ?>
                    <option value="<?= e($val) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="NovaBulkAction.execute()">应用</button>
            <span class="text-muted small">
                已选 <span id="nova-bulk-count">0</span> 项
            </span>
        </div>
        <script>
        (function() {
            var checkAll = document.getElementById('nova-check-all');
            if (checkAll) {
                checkAll.addEventListener('change', function() {
                    document.querySelectorAll('.nova-row-checkbox').forEach(function(cb) {
                        cb.checked = checkAll.checked;
                    });
                    updateBulkCount();
                });
            }
            document.querySelectorAll('.nova-row-checkbox').forEach(function(cb) {
                cb.addEventListener('change', updateBulkCount);
            });
            function updateBulkCount() {
                var n = document.querySelectorAll('.nova-row-checkbox:checked').length;
                var el = document.getElementById('nova-bulk-count');
                if (el) el.textContent = n;
            }
            window.NovaBulkAction = {
                execute: function() {
                    var action = document.querySelector('[name="_bulk_action"]');
                    if (!action || !action.value) { alert('请选择操作'); return; }
                    var ids = [];
                    document.querySelectorAll('.nova-row-checkbox:checked').forEach(function(cb) {
                        ids.push(cb.value);
                    });
                    if (ids.length === 0) { alert('请选择数据项'); return; }
                    if (!confirm('确定执行 ' + action.options[action.selectedIndex].text + ' 吗？')) return;
                    var form = document.createElement('form');
                    form.method = 'post';
                    form.action = window.location.href;
                    form.innerHTML = '<input name="_bulk_action" value="' + action.value + '">' +
                        ids.map(function(id) { return '<input name="ids[]" value="' + id + '">'; }).join('') +
                        '<input name="_csrf" value="' + (window.NovaAjax ? NovaAjax.csrf : '') + '">';
                    document.body.appendChild(form);
                    form.submit();
                }
            };
        })();
        </script>
        <?php
    }

    /**
     * 渲染表头排序链接
     */
    protected function sortableLink($field, $label) {
        $order = ($this->sortBy === $field && $this->sortOrder === 'ASC') ? 'DESC' : 'ASC';
        $arrow = '';
        if ($this->sortBy === $field) {
            $arrow = $this->sortOrder === 'ASC' ? ' ▲' : ' ▼';
        }
        $query = array_merge($_GET, ['orderby' => $field, 'order' => $order]);
        $url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?' . http_build_query($query);
        return '<a href="' . e($url) . '" class="sort-link text-decoration-none">' . e($label) . $arrow . '</a>';
    }

    /**
     * 渲染表格
     */
    public function render() {
        $rows = $this->fetchData();
        $columns = $this->columns;
        $hasBulk = !empty($this->bulkActions);
        $colCount = count($columns) + ($hasBulk ? 1 : 0) + ($this->showRowNumber ? 1 : 0);

        $this->renderSearch();

        if ($hasBulk) {
            echo '<form method="post" id="nova-list-form">';
        }

        $this->renderBulkActions();

        $tableClass = $this->options['class'] ?? 'table table-hover align-middle mb-0';
        $emptyText  = $this->options['empty_text'] ?? '暂无数据';
        ?>
        <div class="table-responsive">
            <table class="<?= e($tableClass) ?>">
                <thead class="table-light">
                    <tr>
                        <?php if ($hasBulk): ?>
                            <th style="width:40px"><input type="checkbox" id="nova-check-all"></th>
                        <?php endif; ?>
                        <?php if ($this->showRowNumber): ?>
                            <th style="width:50px">#</th>
                        <?php endif; ?>
                        <?php foreach ($columns as $field => $label): ?>
                            <th><?php
                                $sortable = $this->options['sortable'] ?? [];
                                if (in_array($field, $sortable)) {
                                    echo $this->sortableLink($field, $label);
                                } else {
                                    echo e($label);
                                }
                            ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= $colCount ?>" class="text-center text-muted py-5"><?= e($emptyText) ?></td></tr>
                    <?php else: ?>
                        <?php $startNum = ($this->currentPage - 1) * $this->perPage; ?>
                        <?php foreach ($rows as $i => $row): ?>
                            <tr <?= $this->rowAttrs($row) ?>>
                                <?php if ($hasBulk): ?>
                                    <td><input type="checkbox" class="nova-row-checkbox" value="<?= e($row['id'] ?? $i) ?>"></td>
                                <?php endif; ?>
                                <?php if ($this->showRowNumber): ?>
                                    <td class="text-muted"><?= $startNum + $i + 1 ?></td>
                                <?php endif; ?>
                                <?php foreach ($columns as $field => $label): ?>
                                    <td><?= $this->column($row, $field) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php

        // 分页
        $this->renderPagination();

        if ($hasBulk) {
            echo '</form>';
        }
    }

    /**
     * 渲染分页
     */
    protected function renderPagination() {
        $pages = $this->totalPages();
        if ($pages <= 1) return;

        $baseUrl = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $query   = $_GET;
        ?>
        <nav class="mt-3">
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted">共 <?= $this->total ?> 条，第 <?= $this->currentPage ?>/<?= $pages ?> 页</small>
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($this->currentPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= e($baseUrl) ?>?<?= http_build_query(array_merge($query, ['paged' => 1])) ?>">首页</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?= e($baseUrl) ?>?<?= http_build_query(array_merge($query, ['paged' => $this->currentPage - 1])) ?>">上一页</a>
                        </li>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $this->currentPage - 2);
                    $end   = min($pages, $this->currentPage + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <li class="page-item <?= $i == $this->currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e($baseUrl) ?>?<?= http_build_query(array_merge($query, ['paged' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($this->currentPage < $pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= e($baseUrl) ?>?<?= http_build_query(array_merge($query, ['paged' => $this->currentPage + 1])) ?>">下一页</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?= e($baseUrl) ?>?<?= http_build_query(array_merge($query, ['paged' => $pages])) ?>">末页</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>
        <?php
    }

    /**
     * 获取搜索关键词
     * @return string
     */
    public function getSearch() {
        return $this->search;
    }

    /**
     * 获取当前页码
     * @return int
     */
    public function getPage() {
        return $this->currentPage;
    }

    /**
     * 获取每页数量
     * @return int
     */
    public function getPerPage() {
        return $this->perPage;
    }
}
