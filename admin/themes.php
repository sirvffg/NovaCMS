<?php
/**
 * Theme administration: discovery, validation, preview and activation.
 */
require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../config/theme_functions.php';

$page_title = '主题管理';
$message = '';
$error = '';

// Older installations may not have the active_theme column yet.
$checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'active_theme'");
if (!$checkStmt->fetch()) {
    $db->exec("ALTER TABLE website_config ADD COLUMN active_theme VARCHAR(100) NOT NULL DEFAULT 'default' COMMENT '激活的主题'");
    $config = $db->query('SELECT * FROM website_config LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
}

if (!empty($_SESSION['theme_flash']) && is_array($_SESSION['theme_flash'])) {
    $flash = $_SESSION['theme_flash'];
    unset($_SESSION['theme_flash']);
    $message = (string)($flash['message'] ?? '');
    $error = (string)($flash['error'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate_theme') {
    $submittedCsrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedCsrfToken) || !validateCSRFToken($submittedCsrfToken)) {
        http_response_code(403);
        $error = '安全验证失败，请刷新页面后重试。';
    } else {
        $requestedSlug = is_string($_POST['theme'] ?? null) ? trim($_POST['theme']) : '';
        $requestedTheme = novaThemeFind($requestedSlug);

        if ($requestedTheme === null) {
            $error = '主题不存在或主题标识无效。';
        } elseif (!$requestedTheme['valid']) {
            $error = '无法启用该主题：' . implode('；', $requestedTheme['errors']);
        } elseif (empty($config['id'])) {
            $error = '网站配置记录不存在，无法保存主题。';
        } else {
            $statement = $db->prepare('UPDATE website_config SET active_theme = ? WHERE id = ?');
            $statement->execute([$requestedTheme['slug'], (int)$config['id']]);
            $_SESSION['theme_flash'] = [
                'message' => '主题「' . $requestedTheme['name'] . '」已启用。',
                'error' => '',
            ];
            header('Location: /admin/themes.php');
            exit;
        }
    }
}

$themes = novaThemeScan();
$configuredTheme = (string)($config['active_theme'] ?? 'default');
$runtimeTheme = novaThemeResolveActive($configuredTheme);
$validThemeCount = count(array_filter($themes, static function ($theme) {
    return !empty($theme['valid']);
}));
$knownTemplateCount = count(novaThemeKnownTemplates());
generateCSRFToken();

require_once __DIR__ . '/includes/header.php';
?>

<style>
.theme-page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1.5rem;
    margin: 1.75rem 0;
}
.theme-page-header h1 { margin: 0 0 .35rem; font-size: clamp(1.65rem, 2vw, 2.15rem); font-weight: 750; }
.theme-page-header p { margin: 0; color: var(--bs-secondary-color); }
.theme-summary { display: flex; flex-wrap: wrap; gap: .65rem; }
.theme-summary-item {
    min-width: 105px;
    padding: .7rem .9rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .8rem;
    background: var(--bs-body-bg);
}
.theme-summary-item strong { display: block; font-size: 1.05rem; line-height: 1.2; }
.theme-summary-item span { color: var(--bs-secondary-color); font-size: .76rem; }
.theme-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 310px), 1fr)); gap: 1.25rem; }
.theme-card {
    position: relative;
    overflow: hidden;
    border: 1px solid var(--bs-border-color);
    border-radius: 1rem;
    background: var(--bs-body-bg);
    box-shadow: 0 12px 30px rgba(15, 23, 42, .05);
    transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
}
.theme-card:hover { transform: translateY(-2px); box-shadow: 0 18px 36px rgba(15, 23, 42, .09); }
.theme-card.is-active { border-color: var(--bs-primary); box-shadow: 0 0 0 1px var(--bs-primary), 0 18px 36px rgba(13, 110, 253, .1); }
.theme-card.is-invalid { border-color: rgba(220, 53, 69, .45); }
.theme-shot { position: relative; aspect-ratio: 16 / 9; background: linear-gradient(135deg, #eef2ff, #f8fafc); overflow: hidden; }
.theme-shot img { width: 100%; height: 100%; object-fit: cover; display: block; }
.theme-shot-placeholder { height: 100%; display: grid; place-items: center; color: #94a3b8; font-size: 3.25rem; }
.theme-shot-badges { position: absolute; inset: .75rem .75rem auto; display: flex; justify-content: space-between; gap: .5rem; pointer-events: none; }
.theme-shot-badges .badge { box-shadow: 0 4px 14px rgba(15, 23, 42, .15); }
.theme-card-body { padding: 1rem 1.05rem .8rem; }
.theme-title-row { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
.theme-title-main { display: flex; align-items: center; gap: .55rem; min-width: 0; }
.theme-logo { width: 28px; height: 28px; border-radius: 8px; object-fit: cover; flex: none; background: linear-gradient(135deg, #eef2ff, #f8fafc); box-shadow: 0 1px 3px rgba(15, 23, 42, .08); }
.theme-title { margin: 0; font-size: 1.08rem; font-weight: 700; }
.theme-version { flex: none; color: var(--bs-secondary-color); font-size: .76rem; }
.theme-description { min-height: 2.7em; margin: .55rem 0 .85rem; color: var(--bs-secondary-color); font-size: .88rem; line-height: 1.5; }
.theme-meta { display: flex; flex-wrap: wrap; gap: .45rem .9rem; color: var(--bs-secondary-color); font-size: .78rem; }
.theme-meta i { margin-right: .3rem; }
.theme-details { margin-top: .85rem; border-top: 1px solid var(--bs-border-color); padding-top: .7rem; }
.theme-details summary { cursor: pointer; color: var(--bs-secondary-color); font-size: .8rem; }
.theme-details ul { margin: .55rem 0 0; padding-left: 1.1rem; font-size: .78rem; }
.theme-details li + li { margin-top: .25rem; }
.theme-card-footer { display: flex; align-items: center; justify-content: space-between; gap: .7rem; padding: .85rem 1.05rem 1.05rem; }
.theme-card-footer form { margin: 0; }
.theme-guide { margin-top: 1.5rem; border: 1px dashed var(--bs-border-color); border-radius: 1rem; padding: 1rem 1.1rem; }
.theme-guide summary { cursor: pointer; font-weight: 650; }
.theme-guide code { color: var(--bs-code-color); }
@media (max-width: 767.98px) {
    .theme-page-header { align-items: flex-start; flex-direction: column; }
    .theme-summary { width: 100%; }
    .theme-summary-item { flex: 1; }
}
</style>

<div class="container-fluid px-4 pb-4">
    <header class="theme-page-header">
        <div>
            <span class="text-primary small fw-semibold text-uppercase">外观 / Themes</span>
            <h1>主题管理</h1>
            <p>检查主题完整性、预览外观并安全切换前台主题。</p>
        </div>
        <div class="theme-summary" aria-label="主题统计">
            <div class="theme-summary-item"><strong><?= count($themes) ?></strong><span>已安装</span></div>
            <div class="theme-summary-item"><strong><?= $validThemeCount ?></strong><span>可启用</span></div>
            <div class="theme-summary-item"><strong><?= e($runtimeTheme['name'] ?? '不可用') ?></strong><span>当前运行</span></div>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success d-flex align-items-center gap-2" role="status"><i class="bi bi-check-circle-fill"></i><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2" role="alert"><i class="bi bi-exclamation-octagon-fill"></i><?= e($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($runtimeTheme['using_fallback'])): ?>
        <div class="alert alert-warning" role="alert">
            <strong>已启用安全回退。</strong>
            配置的主题「<?= e($configuredTheme !== '' ? $configuredTheme : '空') ?>」不可用，前台正在使用「<?= e($runtimeTheme['name'] ?? 'default') ?>」。
            <?= e($runtimeTheme['fallback_reason'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php if (!$themes): ?>
        <div class="alert alert-light border text-center py-5">
            <i class="bi bi-palette d-block fs-1 text-secondary mb-2"></i>
            <strong>没有发现主题</strong>
            <p class="text-secondary small mb-0 mt-1">请将主题目录放入 <code>vendor/nova-themes/</code>。</p>
        </div>
    <?php else: ?>
        <div class="theme-grid">
            <?php foreach ($themes as $theme): ?>
                <?php
                $isConfigured = $theme['slug'] === $configuredTheme;
                $isRuntime = $theme['slug'] === ($runtimeTheme['slug'] ?? '');
                $isFallback = $isRuntime && !empty($runtimeTheme['using_fallback']);
                $coverage = count($theme['templates']);
                $previewUrl = '/?nova_theme_preview=' . rawurlencode($theme['slug'])
                    . '&nova_theme_token=' . rawurlencode(novaThemePreviewToken($theme['slug']));
                ?>
                <article class="theme-card<?= $isRuntime ? ' is-active' : '' ?><?= !$theme['valid'] ? ' is-invalid' : '' ?>">
                    <div class="theme-shot">
                        <?php if ($theme['screenshot_url'] !== ''): ?>
                            <img src="<?= e($theme['screenshot_url']) ?>" alt="<?= e($theme['name']) ?> 主题截图" loading="lazy">
                        <?php else: ?>
                            <div class="theme-shot-placeholder"><i class="bi bi-palette2" aria-hidden="true"></i></div>
                        <?php endif; ?>
                        <div class="theme-shot-badges">
                            <span>
                                <?php if (!$theme['valid']): ?><span class="badge text-bg-danger">校验失败</span><?php endif; ?>
                            </span>
                            <span>
                                <?php if ($isConfigured && !$isFallback): ?>
                                    <span class="badge text-bg-primary">当前主题</span>
                                <?php elseif ($isFallback): ?>
                                    <span class="badge text-bg-warning">安全回退</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="theme-card-body">
                        <div class="theme-title-row">
                            <div class="theme-title-main">
                                <?php if (($theme['logo_url'] ?? '') !== ''): ?>
                                    <img src="<?= e($theme['logo_url']) ?>" alt="" class="theme-logo" loading="lazy">
                                <?php endif; ?>
                                <h2 class="theme-title"><?= e($theme['name']) ?></h2>
                            </div>
                            <span class="theme-version">v<?= e($theme['version']) ?></span>
                        </div>
                        <p class="theme-description"><?= e($theme['description'] !== '' ? $theme['description'] : '此主题未提供说明。') ?></p>
                        <div class="theme-meta">
                            <span><i class="bi bi-person"></i><?= e($theme['author']) ?></span>
                            <span><i class="bi bi-files"></i><?= $coverage ?>/<?= $knownTemplateCount ?> 个页面</span>
                            <span><i class="bi bi-layout-text-window"></i><?= count($theme['page_templates']) ?> 个页面布局</span>
                            <?php if ($theme['parent'] !== ''): ?>
                                <span><i class="bi bi-diagram-2"></i>继承 <?= e($theme['parent']) ?></span>
                            <?php endif; ?>
                            <?php if ($theme['min_nova_version'] !== ''): ?>
                                <span><i class="bi bi-box-arrow-up"></i>NovaCMS <?= e($theme['min_nova_version']) ?>+</span>
                            <?php endif; ?>
                            <?php if (($theme['license'] ?? '') !== ''): ?>
                                <span><i class="bi bi-award"></i><?= e($theme['license']) ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if ($theme['errors'] || $theme['warnings'] || $theme['missing_templates']): ?>
                            <details class="theme-details">
                                <summary>查看完整性报告</summary>
                                <?php if ($theme['errors']): ?>
                                    <ul class="text-danger">
                                        <?php foreach ($theme['errors'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if ($theme['warnings']): ?>
                                    <ul class="text-warning-emphasis">
                                        <?php foreach ($theme['warnings'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if ($theme['missing_templates']): ?>
                                    <p class="small text-secondary mb-0 mt-2">未覆盖：<?= e(implode('、', $theme['missing_templates'])) ?></p>
                                <?php endif; ?>
                            </details>
                        <?php endif; ?>
                    </div>
                    <footer class="theme-card-footer">
                        <div class="d-flex gap-2">
                            <?php if ($theme['valid']): ?>
                                <a class="btn btn-outline-secondary btn-sm" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-eye me-1"></i>预览
                                </a>
                            <?php endif; ?>
                            <?php if ($theme['homepage'] !== ''): ?>
                                <a class="btn btn-link btn-sm px-1" href="<?= e($theme['homepage']) ?>" target="_blank" rel="noopener noreferrer" aria-label="打开主题主页">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php if ($isConfigured && !$isFallback): ?>
                            <span class="text-primary small fw-semibold"><i class="bi bi-check2-circle me-1"></i>已启用</span>
                        <?php else: ?>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="activate_theme">
                                <input type="hidden" name="theme" value="<?= e($theme['slug']) ?>">
                                <button class="btn btn-primary btn-sm" type="submit"<?= !$theme['valid'] ? ' disabled' : '' ?>>启用主题</button>
                            </form>
                        <?php endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <details class="theme-guide">
        <summary><i class="bi bi-code-slash me-2"></i>主题开发约定</summary>
        <div class="small text-secondary mt-3">
            <p>每个主题使用独立目录，根目录只放 <code>theme.json</code>、<code>LICENSE</code>、<code>logo.png</code>、<code>screenshot.png</code> 四个文件。所有 PHP/JS/CSS 等主题文件必须放在 <code>themes/</code> 子目录内，至少提供 <code>themes/index.php</code> 与 <code>themes/404.php</code>。目录名和清单中的 <code>slug</code> 必须一致。</p>
            <p>建议在根目录放置 <code>logo.png</code>（小图标，28×28 显示，支持 png/jpg/webp/svg/ico）、<code>screenshot.png</code>（大截图，16:9）与 <code>LICENSE</code>（许可证文件），并在 <code>theme.json</code> 中通过 <code>logo</code>、<code>screenshot</code>、<code>license</code> 字段声明。</p>
            <p class="mb-0">可在清单中通过 <code>page_templates</code> 对象声明页面布局，例如 <code>{"default":"默认页面","wide":"宽版页面"}</code>；页面编辑器会自动读取当前主题的选项。</p>
        </div>
    </details>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
