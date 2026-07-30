<?php
require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../config/content_module_functions.php';

ensureContentModuleTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['ai_settings_flash'] = ['type' => 'danger', 'message' => '页面已过期，请刷新后重试'];
        header('Location: /admin/ai_settings.php');
        exit;
    }

    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $welcomeMessage = trim((string)($_POST['welcome_message'] ?? ''));
    $fallbackMessage = trim((string)($_POST['fallback_message'] ?? ''));
    $matchThresholdRaw = trim((string)($_POST['match_threshold'] ?? ''));
    $maxResultsRaw = trim((string)($_POST['max_results'] ?? ''));
    $matchThreshold = is_numeric($matchThresholdRaw) ? (float)$matchThresholdRaw : 0.35;
    $maxResults = preg_match('/^\d+$/', $maxResultsRaw) ? (int)$maxResultsRaw : 3;
    $errors = [];

    if ($welcomeMessage === '') {
        $errors[] = '欢迎语不能为空';
    }
    if ($fallbackMessage === '') {
        $errors[] = '未命中回复不能为空';
    }
    if ((function_exists('mb_strlen') ? mb_strlen($welcomeMessage, 'UTF-8') : strlen($welcomeMessage)) > 500) {
        $errors[] = '欢迎语不能超过 500 个字符';
    }
    if ((function_exists('mb_strlen') ? mb_strlen($fallbackMessage, 'UTF-8') : strlen($fallbackMessage)) > 500) {
        $errors[] = '未命中回复不能超过 500 个字符';
    }
    if (!is_numeric($matchThresholdRaw) || !is_finite($matchThreshold) || $matchThreshold < 0.01 || $matchThreshold > 1) {
        $errors[] = '匹配阈值需填写 0.01 至 1 之间的数字';
    }
    if (!preg_match('/^\d+$/', $maxResultsRaw) || $maxResults < 1 || $maxResults > 10) {
        $errors[] = '最多返回需填写 1 至 10 之间的整数';
    }

    if (!$errors) {
        try {
            $statement = $db->prepare(
                'INSERT INTO cms_ai_settings
                    (id, enabled, welcome_message, fallback_message, match_threshold, max_results)
                 VALUES (1, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    welcome_message = VALUES(welcome_message),
                    fallback_message = VALUES(fallback_message),
                    match_threshold = VALUES(match_threshold),
                    max_results = VALUES(max_results)'
            );
            $statement->execute([$enabled, $welcomeMessage, $fallbackMessage, $matchThreshold, $maxResults]);
            $_SESSION['ai_settings_flash'] = ['type' => 'success', 'message' => '助手设置已保存'];
        } catch (Throwable $exception) {
            error_log('AI settings update failed: ' . $exception->getMessage());
            $_SESSION['ai_settings_flash'] = ['type' => 'danger', 'message' => '设置保存失败，请稍后重试'];
        }
    } else {
        $_SESSION['ai_settings_flash'] = ['type' => 'danger', 'message' => implode('；', $errors)];
    }

    header('Location: /admin/ai_settings.php');
    exit;
}

$settings = $db->query('SELECT * FROM cms_ai_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [
    'enabled' => 1,
    'welcome_message' => '您好，请问有什么可以帮您？',
    'fallback_message' => '暂时没有找到合适的答案，请换个说法再试。',
    'match_threshold' => 0.35,
    'max_results' => 3,
];
$activeQaCount = (int)$db->query("SELECT COUNT(*) FROM cms_ai_qa WHERE status = 'active'")->fetchColumn();
$draftQaCount = (int)$db->query("SELECT COUNT(*) FROM cms_ai_qa WHERE status = 'draft'")->fetchColumn();
$flash = $_SESSION['ai_settings_flash'] ?? null;
unset($_SESSION['ai_settings_flash']);

$contentCssPath = __DIR__ . '/../assets/css/admin-content.css';
$head_scripts = '<link href="/assets/css/admin-content.css?v=' . e((string)(@filemtime($contentCssPath) ?: 1)) . '" rel="stylesheet">';
$page_title = '智能问答设置';
require __DIR__ . '/includes/header.php';
?>

<section class="content-resource-shell">
    <div class="content-page-heading">
        <div class="content-page-heading-copy">
            <span class="content-page-icon"><i class="bi bi-stars" aria-hidden="true"></i></span>
            <div>
                <div class="content-page-eyebrow">内容 / 智能问答</div>
                <h1>助手设置</h1>
                <p>控制站内问答助手的启停、匹配灵敏度与默认回复，并可在保存后立即测试。</p>
            </div>
        </div>
        <div class="content-page-actions">
            <a class="btn btn-outline-secondary" href="/admin/ai_qa.php">
                <i class="bi bi-database" aria-hidden="true"></i> 管理知识库
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show content-resource-alert" role="alert">
        <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle' : 'bi-exclamation-circle' ?>" aria-hidden="true"></i>
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
    </div>
    <?php endif; ?>

    <div class="content-stat-grid">
        <article class="content-stat-card tone-<?= !empty($settings['enabled']) ? 'success' : 'warning' ?>">
            <span><i class="bi <?= !empty($settings['enabled']) ? 'bi-toggle-on' : 'bi-toggle-off' ?>" aria-hidden="true"></i></span>
            <div><strong><?= !empty($settings['enabled']) ? '运行中' : '已停用' ?></strong><small>助手状态</small></div>
        </article>
        <article class="content-stat-card tone-success">
            <span><i class="bi bi-check-circle" aria-hidden="true"></i></span>
            <div><strong><?= number_format($activeQaCount) ?></strong><small>启用知识</small></div>
        </article>
        <article class="content-stat-card tone-warning">
            <span><i class="bi bi-pencil-square" aria-hidden="true"></i></span>
            <div><strong><?= number_format($draftQaCount) ?></strong><small>草稿知识</small></div>
        </article>
        <article class="content-stat-card">
            <span><i class="bi bi-bullseye" aria-hidden="true"></i></span>
            <div><strong><?= e(number_format((float)$settings['match_threshold'] * 100, 0)) ?>%</strong><small>匹配阈值</small></div>
        </article>
    </div>

    <div class="ai-settings-grid">
        <form class="content-panel ai-settings-form" method="post" action="/admin/ai_settings.php">
            <?= csrfField() ?>
            <div class="ai-settings-panel-head">
                <div>
                    <h2>回答策略</h2>
                    <p>设置会即时作用于公共问答接口。</p>
                </div>
                <label class="content-switch">
                    <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
                    <span aria-hidden="true"></span>
                    <strong>启用助手</strong>
                </label>
            </div>

            <div class="ai-settings-fields">
                <div>
                    <label class="form-label" for="welcome-message">欢迎语</label>
                    <textarea class="form-control" id="welcome-message" name="welcome_message" maxlength="500" rows="3" required><?= e($settings['welcome_message']) ?></textarea>
                    <div class="form-text">首次打开助手时向访客展示。</div>
                </div>
                <div>
                    <label class="form-label" for="fallback-message">未命中回复</label>
                    <textarea class="form-control" id="fallback-message" name="fallback_message" maxlength="500" rows="3" required><?= e($settings['fallback_message']) ?></textarea>
                    <div class="form-text">知识库没有合适答案时返回此内容。</div>
                </div>
                <div class="ai-settings-inline">
                    <div>
                        <label class="form-label" for="match-threshold">匹配阈值</label>
                        <input class="form-control" id="match-threshold" name="match_threshold" type="number" min="0.01" max="1" step="0.01" value="<?= e($settings['match_threshold']) ?>">
                        <div class="form-text">越高越严格，建议 0.30–0.50。</div>
                    </div>
                    <div>
                        <label class="form-label" for="max-results">最多返回</label>
                        <input class="form-control" id="max-results" name="max_results" type="number" min="1" max="10" value="<?= (int)$settings['max_results'] ?>">
                        <div class="form-text">单次问题返回的候选答案数量。</div>
                    </div>
                </div>
            </div>

            <div class="ai-settings-actions">
                <button class="btn btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> 保存设置</button>
            </div>
        </form>

        <section class="content-panel ai-test-panel" aria-labelledby="ai-test-title">
            <div class="ai-settings-panel-head">
                <div>
                    <h2 id="ai-test-title">问答测试</h2>
                    <p>使用已启用的知识即时模拟访客提问。</p>
                </div>
                <span class="ai-test-live"><i aria-hidden="true"></i> 本地知识库</span>
            </div>
            <div class="ai-test-conversation" data-ai-test-conversation aria-live="polite">
                <div class="ai-message is-assistant">
                    <span><i class="bi bi-stars" aria-hidden="true"></i></span>
                    <p><?= e($settings['welcome_message']) ?></p>
                </div>
            </div>
            <form class="ai-test-compose" data-ai-test-form>
                <label class="visually-hidden" for="ai-test-question">测试问题</label>
                <input id="ai-test-question" type="text" maxlength="500" placeholder="输入一个访客可能提出的问题…" data-ai-test-input>
                <button type="submit" aria-label="发送测试问题"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
            </form>
        </section>
    </div>
</section>

<?php
$extra_scripts = <<<'HTML'
<script>
(function () {
    'use strict';
    var form = document.querySelector('[data-ai-test-form]');
    var input = document.querySelector('[data-ai-test-input]');
    var conversation = document.querySelector('[data-ai-test-conversation]');
    if (!form || !input || !conversation) return;

    function appendMessage(text, role, pending) {
        var item = document.createElement('div');
        item.className = 'ai-message is-' + role + (pending ? ' is-pending' : '');
        var avatar = document.createElement('span');
        avatar.innerHTML = role === 'assistant'
            ? '<i class="bi bi-stars" aria-hidden="true"></i>'
            : '<i class="bi bi-person" aria-hidden="true"></i>';
        var copy = document.createElement('p');
        copy.textContent = text;
        item.appendChild(avatar);
        item.appendChild(copy);
        conversation.appendChild(item);
        conversation.scrollTop = conversation.scrollHeight;
        return item;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var question = input.value.trim();
        if (!question) return;
        appendMessage(question, 'user');
        input.value = '';
        input.disabled = true;
        var pending = appendMessage('正在匹配知识库…', 'assistant', true);

        fetch('/nova-json/v1/content/ai/ask', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ question: question })
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            var data = payload && payload.data ? payload.data : {};
            var answer = data.answer || data.fallback_message || payload.message || '暂时没有找到合适的答案。';
            pending.remove();
            appendMessage(answer, 'assistant');
        }).catch(function () {
            pending.remove();
            appendMessage('测试请求未完成，请检查服务状态后再试。', 'assistant');
        }).finally(function () {
            input.disabled = false;
            input.focus();
        });
    });
}());
</script>
HTML;

require __DIR__ . '/includes/footer.php';
?>
