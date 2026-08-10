<?php

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';

if (!defined('NOVA_API')) {
    define('NOVA_API', true);
}

require_once '../vendor/nova-json/class/plugin/class-plugin-manager.php';

$db = getDB();

$pluginManager = new Nova_Plugin_Manager(
    $db,
    __DIR__ . '/../vendor/nova-plugins'
);

$message = '';
$error = '';

// 处理启用 / 禁用
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = '请求验证失败，请刷新页面后重试';
    } else {

        $slug = preg_replace(
            '/[^a-zA-Z0-9_-]/',
            '',
            $_POST['plugin'] ?? ''
        );

        $action = $_POST['action'] ?? '';

        try {

            if ($action === 'enable') {

                $pluginManager->setEnabled(
                    $slug,
                    true
                );

                $message = '插件已启用';

            } elseif ($action === 'disable') {

                $pluginManager->setEnabled(
                    $slug,
                    false
                );

                $message = '插件已禁用';

            }

        } catch (Throwable $e) {

            $error = $e->getMessage();

        }
    }
}

$plugins = $pluginManager->getPlugins();

$page_title = '插件管理';

require_once 'includes/header.php';

?>

<div class="container-fluid px-4">

    <div class="d-flex align-items-center justify-content-between mt-4 mb-4">

        <div>
            <h1 class="mb-1">插件管理</h1>

            <p class="text-muted mb-0">
                管理 NovaCMS 已安装插件
            </p>
        </div>

    </div>

    <?php if ($message): ?>

        <div class="alert alert-success">
            <?= e($message) ?>
        </div>

    <?php endif; ?>

    <?php if ($error): ?>

        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <?php if (empty($plugins)): ?>

        <div class="alert alert-secondary">
            暂无插件
        </div>

    <?php endif; ?>


    <div class="row g-4">

        <?php foreach ($plugins as $plugin): ?>

            <div class="col-md-6 col-xl-4">

                <div class="card h-100">

                    <div class="card-body">

                        <div class="d-flex justify-content-between">

                            <h5 class="card-title">
                                <?= e($plugin['name']) ?>
                            </h5>

                            <?php if ($plugin['enabled']): ?>

                                <span class="badge bg-success">
                                    已启用
                                </span>

                            <?php else: ?>

                                <span class="badge bg-secondary">
                                    未启用
                                </span>

                            <?php endif; ?>

                        </div>


                        <p class="card-text text-muted">

                            <?= e($plugin['description']) ?>

                        </p>


                        <div class="small text-muted">

                            版本：
                            <?= e($plugin['version']) ?>

                            <br>

                            作者：
                            <?= e($plugin['author']) ?>

                            <br>

                            标识：
                            <?= e($plugin['slug']) ?>

                        </div>


                        <?php if (!empty($plugin['last_error'])): ?>

                            <div class="alert alert-danger mt-3 mb-0">

                                插件错误：

                                <?= e($plugin['last_error']) ?>

                            </div>

                        <?php endif; ?>

                    </div>


                    <div class="card-footer bg-transparent">

                        <form method="POST">

                            <?= csrfField() ?>

                            <input
                                type="hidden"
                                name="plugin"
                                value="<?= e($plugin['slug']) ?>"
                            >

                            <?php if ($plugin['enabled']): ?>

                                <button
                                    class="btn btn-outline-danger btn-sm"
                                    name="action"
                                    value="disable"
                                    type="submit"
                                >
                                    禁用
                                </button>

                            <?php else: ?>

                                <button
                                    class="btn btn-primary btn-sm"
                                    name="action"
                                    value="enable"
                                    type="submit"
                                >
                                    启用
                                </button>

                            <?php endif; ?>

                        </form>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

</div>

<?php require_once 'includes/footer.php'; ?>