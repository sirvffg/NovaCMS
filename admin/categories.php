<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';

requireLogin();

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$success = '';
$error = '';

// 删除分类 - 改用 POST + CSRF 验证
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = '安全验证失败，请刷新页面后重试';
    } else {
        $deleteId = (int)$_POST['delete_category'];
        if ($deleteId > 0) {
            $db->prepare("DELETE FROM blog_categories WHERE id=?")->execute([$deleteId]);
            $success = '分类已删除';
        } else {
            $error = '无效的分类ID';
        }
    }
}

// 添加/编辑分类
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_category'])) {
    // CSRF 验证
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = '安全验证失败，请刷新页面后重试';
    } else {
        $id = $_POST['id'] ?? null;
        $name = $_POST['name'] ?? '';
        $slug = $_POST['slug'] ?? '';
        $description = $_POST['description'] ?? '';
        $sort_order = intval($_POST['sort_order'] ?? 0);
        $color = $_POST['color'] ?? '#007bff';

        // 生成slug
        if (empty($slug)) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        }

        // 检查重复
        if ($id) {
            $check = $db->prepare("SELECT id FROM blog_categories WHERE slug=? AND id!=?");
            $check->execute([$slug, $id]);
        } else {
            $check = $db->prepare("SELECT id FROM blog_categories WHERE slug=?");
            $check->execute([$slug]);
        }

        if ($check->fetch()) {
            $error = '分类别名已存在';
        } else {
            if ($id) {
                $stmt = $db->prepare("UPDATE blog_categories SET name=?, slug=?, description=?, sort_order=?, color=? WHERE id=?");
                $stmt->execute([$name, $slug, $description, $sort_order, $color, $id]);
                $success = '分类已更新';
            } else {
                $stmt = $db->prepare("INSERT INTO blog_categories (name, slug, description, sort_order, color) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $slug, $description, $sort_order, $color]);
                $success = '分类已添加';
            }
        }
    }
}

// 获取编辑的分类
$editCategory = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    if ($editId > 0) {
        $stmt = $db->prepare("SELECT * FROM blog_categories WHERE id=?");
        $stmt->execute([$editId]);
        $editCategory = $stmt->fetch();
    }
}

$categories = $db->query("SELECT * FROM blog_categories ORDER BY sort_order, name")->fetchAll();
$page_title = '分类管理';
$extra_css = <<<'CSS'
.form-control-color {
    width: 3rem;
    height: calc(1.5em + 0.75rem + 2px);
    padding: 0.375rem;
}
.color-preview {
    width: 20px;
    height: 20px;
    display: inline-block;
    border-radius: 3px;
    margin-right: 8px;
    vertical-align: middle;
    border: 1px solid #dee2e6;
}
CSS;
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">分类管理</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal">
                            <i class="bi bi-plus-lg"></i> 添加分类
                        </button>
                    </div>
                </div>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?= e($success) ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
                <?php endif; ?>
                
                <!-- 分类列表 -->
                <div class="card">
                    <div class="card-header">
                        <h5>分类列表</h5>
                        <small class="text-muted">分类将作为文章标签使用，支持多选</small>
                    </div>
                    <div class="card-body">
                        <?php if (empty($categories)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-tags" style="font-size: 3rem;"></i>
                            <p class="mt-2">暂无分类，请添加分类</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>排序</th>
                                        <th>分类名称</th>
                                        <th>颜色</th>
                                        <th>别名</th>
                                        <th>描述</th>
                                        <th>创建时间</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td><?= $category['sort_order'] ?></td>
                                        <td>
                                            <span class="color-preview" style="background-color: <?= e($category['color'] ?? '#007bff') ?>"></span>
                                            <strong><?= e($category['name']) ?></strong>
                                        </td>
                                        <td>
                                            <code><?= e($category['color'] ?? '#007bff') ?></code>
                                        </td>
                                        <td><code><?= e($category['slug']) ?></code></td>
                                        <td><?= e($category['description'] ?? '-') ?></td>
                                        <td><?= date('Y-m-d H:i', strtotime($category['created_at'])) ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-primary"
                                                    data-bs-toggle="modal" data-bs-target="#categoryModal"
                                                    onclick="editCategory(<?= htmlspecialchars(json_encode($category), ENT_QUOTES, 'UTF-8') ?>)">
                                                编辑
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('确定删除此分类？');">
                                                <input type="hidden" name="csrf_token" value="<?= e(generateCSRFToken()) ?>">
                                                <input type="hidden" name="delete_category" value="<?= $category['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

    <!-- 分类添加/编辑模态框 -->
    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><?= $editCategory ? '编辑分类' : '添加分类' ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="categoryForm">
                    <?= csrfField() ?>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="categoryId" value="<?= $editCategory['id'] ?? '' ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">分类名称 <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" 
                                   value="<?= e($editCategory['name'] ?? '') ?>" required>
                            <small class="text-muted">分类显示名称，如：技术分享</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">分类别名</label>
                            <input type="text" name="slug" class="form-control" 
                                   value="<?= e($editCategory['slug'] ?? '') ?>">
                            <small class="text-muted">用于URL和标识符，如：tech。留空将自动生成</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">分类描述</label>
                            <textarea name="description" class="form-control" rows="3"><?= e($editCategory['description'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">标签颜色</label>
                            <div class="input-group">
                                <input type="color" id="colorPicker" name="color" class="form-control form-control-color" 
                                       value="<?= e($editCategory['color'] ?? '#007bff') ?>" 
                                       title="选择颜色">
                                <input type="text" id="colorText" name="color_text" class="form-control" 
                                       value="<?= e($editCategory['color'] ?? '#007bff') ?>" 
                                       placeholder="#007bff" maxlength="7">
                            </div>
                            <small class="text-muted">分类标签的显示颜色，支持#RRGGBB格式</small>
                            <div class="mt-2">
                                <small>预设颜色：</small>
                                <?php 
                                $presetColors = ['#007bff', '#28a745', '#fd7e14', '#6f42c1', '#e83e8c', '#20c997', '#ffc107', '#dc3545', '#17a2b8', '#6c757d'];
                                foreach ($presetColors as $presetColor):
                                ?>
                                <button type="button" class="btn btn-sm m-1" 
                                        style="background-color: <?= $presetColor ?>; width: 24px; height: 24px; padding: 0; border: 1px solid #dee2e6;"
                                        onclick="setColor('<?= $presetColor ?>')"
                                        title="<?= $presetColor ?>">
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">排序</label>
                            <input type="number" name="sort_order" class="form-control" 
                                   value="<?= $editCategory['sort_order'] ?? 0 ?>" min="0">
                            <small class="text-muted">数字越小越靠前</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary">保存</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        function editCategory(category) {
            // 设置表单数据
            document.getElementById('categoryId').value = category.id;
            document.querySelector('input[name="name"]').value = category.name;
            document.querySelector('input[name="slug"]').value = category.slug;
            document.querySelector('textarea[name="description"]').value = category.description || '';
            document.querySelector('input[name="sort_order"]').value = category.sort_order;
            
            // 设置颜色字段
            const color = category.color || '#007bff';
            document.getElementById('colorPicker').value = color;
            document.getElementById('colorText').value = color;
            
            // 更新模态框标题为编辑分类
            document.getElementById('modalTitle').textContent = '编辑分类';
        }
        
        // 设置颜色函数
        function setColor(color) {
            document.getElementById('colorPicker').value = color;
            document.getElementById('colorText').value = color;
        }
        
        // 同步颜色输入框
        document.getElementById('colorText').addEventListener('input', function(e) {
            const color = e.target.value;
            if (color.match(/^#[0-9A-F]{6}$/i)) {
                document.getElementById('colorPicker').value = color;
            }
        });
        
        document.getElementById('colorPicker').addEventListener('input', function(e) {
            document.getElementById('colorText').value = e.target.value;
        });
        
        // 当模态框显示时，如果没有categoryId，则设置标题为添加分类
        document.getElementById('categoryModal').addEventListener('show.bs.modal', function (e) {
            const categoryId = document.getElementById('categoryId').value;
            if (!categoryId) {
                document.getElementById('modalTitle').textContent = '添加分类';
            }
        });
        
        // 模态框隐藏时重置表单和标题
        document.getElementById('categoryModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('modalTitle').textContent = '添加分类';
            document.getElementById('categoryForm').reset();
            document.getElementById('categoryId').value = '';
            document.getElementById('colorPicker').value = '#007bff';
            document.getElementById('colorText').value = '#007bff';
        });
    </script>
<?php require_once 'includes/footer.php'; ?>