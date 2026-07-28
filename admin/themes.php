<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$message = '';
$error = '';

// 确保 active_theme 字段存在
$checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'active_theme'");
if (!$checkStmt->fetch()) {
    $db->exec("ALTER TABLE website_config ADD COLUMN active_theme VARCHAR(100) DEFAULT 'default' COMMENT '激活的主题' AFTER allow_register");
}

$currentTheme = $config['active_theme'] ?? 'default';

// 处理切换主题
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_theme'])) {
    $theme = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['activate_theme']);
    $themeDir = __DIR__ . '/../vendor/nova-themes/' . $theme;
    if (is_dir($themeDir) && file_exists($themeDir . '/theme.json')) {
        $db->exec("UPDATE website_config SET active_theme = " . $db->quote($theme) . " WHERE id = 1");
        $currentTheme = $theme;
        $message = '主题已切换为: ' . $theme;
    } else {
        $error = '主题不存在或无效';
    }
}

// 扫描已安装的主题
$themesDir = __DIR__ . '/../vendor/nova-themes';
$themes = [];
if (is_dir($themesDir)) {
    $dirs = scandir($themesDir);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;
        $themePath = $themesDir . '/' . $dir;
        $jsonFile = $themePath . '/theme.json';
        if (is_dir($themePath) && file_exists($jsonFile)) {
            $info = json_decode(file_get_contents($jsonFile), true);
            $themes[] = [
                'slug'    => $dir,
                'name'    => $info['name'] ?? $dir,
                'version' => $info['version'] ?? '1.0.0',
                'author'  => $info['author'] ?? 'Unknown',
                'desc'    => $info['description'] ?? '',
            ];
        }
    }
}

require_once 'includes/header.php';
?>

<style>
.theme-card { transition: all 0.3s; cursor: pointer; }
.theme-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
.theme-card.active { border: 2px solid #1890ff; }
.theme-screenshot { height: 200px; background: #f0f2f5; display: flex; align-items: center; justify-content: center; font-size: 48px; color: #999; border-radius: 8px 8px 0 0; overflow: hidden; }
.theme-screenshot img { width: 100%; height: 100%; object-fit: cover; }
</style>

<div class="container-fluid px-4">
    <h1 class="mt-4">主题管理</h1>
    <ol class="breadcrumb mb-4"><li class="breadcrumb-item active">管理已安装的主题</li></ol>

    <?php if ($message): ?>
    <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <?php if (empty($themes)): ?>
        <div class="col-12"><p class="text-muted">暂无已安装的主题</p></div>
        <?php else: ?>
        <?php foreach ($themes as $theme): ?>
        <div class="col-md-4 col-lg-3">
            <div class="card theme-card h-100 <?= $theme['slug'] === $currentTheme ? 'active' : '' ?>">
                <div class="theme-screenshot">
                    <?php
                    $screenshot = $themePath = __DIR__ . '/../vendor/nova-themes/' . $theme['slug'] . '/screenshot.png';
                    if (file_exists($screenshot)): ?>
                    <img src="/vendor/nova-themes/<?= $theme['slug'] ?>/screenshot.png" alt="<?= e($theme['name']) ?>">
                    <?php else: ?>
                    <i class="bi bi-palette"></i>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <h5 class="card-title"><?= e($theme['name']) ?></h5>
                    <p class="card-text small text-muted"><?= e($theme['desc']) ?></p>
                    <p class="card-text small">
                        <span class="text-muted">版本：</span><?= e($theme['version']) ?>
                        <span class="ms-3 text-muted">作者：</span><?= e($theme['author']) ?>
                    </p>
                </div>
                <div class="card-footer bg-transparent border-top-0">
                    <?php if ($theme['slug'] === $currentTheme): ?>
                    <span class="badge bg-primary">当前激活</span>
                    <?php else: ?>
                    <form method="POST" style="display:inline;">
                        <button type="submit" name="activate_theme" value="<?= e($theme['slug']) ?>" class="btn btn-outline-primary btn-sm">启用</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
