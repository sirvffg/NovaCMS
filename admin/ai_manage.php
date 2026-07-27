<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/ai_functions.php';

$db = getDB();
aiEnsureSchema($db);

$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$message = '';
$error = '';

if (isset($_SESSION['ai_flash_ok'])) {
    $message = $_SESSION['ai_flash_ok'];
    unset($_SESSION['ai_flash_ok']);
}
if (isset($_SESSION['ai_flash_err'])) {
    $error = $_SESSION['ai_flash_err'];
    unset($_SESSION['ai_flash_err']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['ai_flash_err'] = '安全验证失败，请刷新后重试';
        header('Location: ai_manage.php');
        exit;
    }

    $action = $_POST['ai_action'] ?? '';

    try {
        if ($action === 'save_global') {
            $en = isset($_POST['ai_feature_enabled']) ? 1 : 0;
            $tp = (float)($_POST['ai_temperature'] ?? 0.3);
            $tp = max(0, min(2, $tp));
            $title = trim($_POST['ai_summary_section_title'] ?? '文章摘要');
            if ($title === '') {
                $title = '文章摘要';
            }
            $stmt = $db->prepare("UPDATE website_config SET ai_feature_enabled=?, ai_temperature=?, ai_summary_section_title=? WHERE id=1");
            $stmt->execute([$en, $tp, $title]);
            $_SESSION['ai_flash_ok'] = '全局 AI 参数已保存';
            header('Location: ai_manage.php');
            exit;
        }

        if ($action === 'save_model') {
            $id = (int)($_POST['model_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $api_base = trim($_POST['api_base'] ?? '');
            $model_id = trim($_POST['model_id_str'] ?? '');
            $api_key_new = trim($_POST['api_key'] ?? '');
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            $sort_order = (int)($_POST['sort_order'] ?? 0);

            if ($name === '' || $api_base === '' || $model_id === '') {
                throw new Exception('名称、API Base、模型 ID 不能为空');
            }

            if ($id > 0) {
                $stmt = $db->prepare("SELECT api_key FROM blog_ai_models WHERE id=?");
                $stmt->execute([$id]);
                $old = $stmt->fetch();
                if (!$old) {
                    throw new Exception('模型不存在');
                }
                $keyToStore = $api_key_new !== '' ? $api_key_new : $old['api_key'];
                if ($enabled && (trim($keyToStore) === '')) {
                    throw new Exception('启用状态下必须配置 API Key（或保留原密钥）');
                }
                if ($is_default) {
                    $db->exec("UPDATE blog_ai_models SET is_default=0");
                }
                $stmt = $db->prepare("UPDATE blog_ai_models SET name=?, api_base=?, api_key=?, model_id=?, enabled=?, is_default=?, sort_order=? WHERE id=?");
                $stmt->execute([$name, $api_base, $keyToStore, $model_id, $enabled, $is_default, $sort_order, $id]);
            } else {
                if ($api_key_new === '') {
                    throw new Exception('新建模型必须填写 API Key');
                }
                if ($enabled && $api_key_new === '') {
                    throw new Exception('启用模型必须填写 API Key');
                }
                if ($is_default) {
                    $db->exec("UPDATE blog_ai_models SET is_default=0");
                }
                $stmt = $db->prepare("INSERT INTO blog_ai_models (name, api_base, api_key, model_id, enabled, is_default, sort_order) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$name, $api_base, $api_key_new, $model_id, $enabled, $is_default, $sort_order]);
            }

            $_SESSION['ai_flash_ok'] = '模型已保存';
            header('Location: ai_manage.php');
            exit;
        }

        if ($action === 'delete_model') {
            $id = (int)($_POST['delete_id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('无效 ID');
            }
            $db->prepare("DELETE FROM blog_ai_models WHERE id=?")->execute([$id]);
            $_SESSION['ai_flash_ok'] = '已删除模型';
            header('Location: ai_manage.php');
            exit;
        }

        if ($action === 'set_default') {
            $id = (int)($_POST['default_id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('无效 ID');
            }
            $db->exec("UPDATE blog_ai_models SET is_default=0");
            $db->prepare("UPDATE blog_ai_models SET is_default=1 WHERE id=?")->execute([$id]);
            $_SESSION['ai_flash_ok'] = '已设为默认摘要模型';
            header('Location: ai_manage.php');
            exit;
        }

        if ($action === 'test_model') {
            $id = (int)($_POST['test_id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM blog_ai_models WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || trim($row['api_key']) === '') {
                throw new Exception('模型不存在或未配置 Key');
            }
            $r = aiTestModelConnection($row);
            if ($r['ok']) {
                $_SESSION['ai_flash_ok'] = '连通成功（HTTP ' . $r['http_code'] . '）';
            } else {
                $_SESSION['ai_flash_err'] = '连通失败：' . ($r['error'] ?? '未知');
            }
            header('Location: ai_manage.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['ai_flash_err'] = $e->getMessage();
        header('Location: ai_manage.php');
        exit;
    }
}

$models = $db->query("SELECT * FROM blog_ai_models ORDER BY sort_order ASC, id ASC")->fetchAll();

// —— 调用日志：筛选 + 分页 ——
$logPerPage = 25;
$logPage = max(1, (int)($_GET['log_page'] ?? 1));
$logStatus = $_GET['log_status'] ?? '';
if (!in_array($logStatus, ['', 'ok', 'fail'], true)) {
    $logStatus = '';
}
$logModelFilter = max(0, (int)($_GET['log_model'] ?? 0));

$logWhere = [];
$logParams = [];
if ($logStatus === 'ok') {
    $logWhere[] = 'l.success = 1';
} elseif ($logStatus === 'fail') {
    $logWhere[] = 'l.success = 0';
}
if ($logModelFilter > 0) {
    $logWhere[] = 'l.ai_model_id = ?';
    $logParams[] = $logModelFilter;
}
$logWhereSql = count($logWhere) ? implode(' AND ', $logWhere) : '1=1';

try {
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM blog_ai_usage_log l WHERE $logWhereSql");
    $cntStmt->execute($logParams);
    $logTotalRows = (int)$cntStmt->fetchColumn();
} catch (Exception $e) {
    $logTotalRows = 0;
}

$logTotalPages = max(1, (int)ceil($logTotalRows / $logPerPage));
if ($logPage > $logTotalPages) {
    $logPage = $logTotalPages;
}
$logOffset = ($logPage - 1) * $logPerPage;

$usageRows = [];
try {
    $sqlLog = "SELECT l.*, p.title AS post_title, a.username AS admin_name, m.name AS ai_model_name
        FROM blog_ai_usage_log l
        LEFT JOIN blog_posts p ON p.id = l.post_id
        LEFT JOIN admins a ON a.id = l.admin_id
        LEFT JOIN blog_ai_models m ON m.id = l.ai_model_id
        WHERE $logWhereSql
        ORDER BY l.id DESC
        LIMIT " . (int)$logPerPage . " OFFSET " . (int)$logOffset;
    $stmtLog = $db->prepare($sqlLog);
    $stmtLog->execute($logParams);
    $usageRows = $stmtLog->fetchAll();
} catch (Exception $e) {
    $usageRows = [];
}

// 全局统计（不受筛选影响，便于掌握总量）
$logStats = ['cnt' => 0, 'ok_cnt' => 0, 'fail_cnt' => 0, 'token_sum' => 0];
try {
    $logStats = $db->query("SELECT COUNT(*) AS cnt,
        SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS ok_cnt,
        SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS fail_cnt,
        COALESCE(SUM(total_tokens), 0) AS token_sum
        FROM blog_ai_usage_log")->fetch() ?: $logStats;
} catch (Exception $e) {
    // ignore
}

$buildLogQuery = function (array $overrides = []) use ($logStatus, $logModelFilter, $logPage) {
    $q = array_merge([
        'log_status' => $logStatus,
        'log_model' => $logModelFilter,
        'log_page' => $logPage,
    ], $overrides);
    $parts = [];
    if ($q['log_status'] !== '') {
        $parts['log_status'] = $q['log_status'];
    }
    if ($q['log_model'] > 0) {
        $parts['log_model'] = $q['log_model'];
    }
    if (($q['log_page'] ?? 1) > 1) {
        $parts['log_page'] = $q['log_page'];
    }
    return $parts ? ('?' . http_build_query($parts)) : '';
};

$ai = aiGetSiteAiSettings($db);
$csrf = generateCSRFToken();
$is_embedded = !empty($_GET['embed']);
$extra_css = <<<'CSS'
.mono-sm { font-size: 0.8rem; word-break: break-all; }
.ai-log-stats .badge { font-weight: 500; }
.ai-log-table td { vertical-align: middle; }
.ai-log-err { max-width: 220px; font-size: 0.8rem; }
CSS;

if (!$is_embedded) {
    $page_title = 'AI 管理';
    require_once 'includes/header.php';
} else {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= getResourceUrl('/assets/css/bootstrap-icons.css', 'https://cdn.staticfile.net/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <style><?= $extra_css ?></style>
</head>
<body class="p-3">
<?php }
?>
            <?php if (!$is_embedded): ?>
            <h1 class="h3 mb-4"><i class="bi bi-stars"></i> AI 管理</h1>
            <?php endif; ?>

            <?php if ($message): ?>
            <div class="alert alert-success"><?= e($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

                    <div class="card mb-4">
                        <div class="card-header bg-white p-0 border-bottom-0">
                            <ul class="nav nav-tabs card-header-tabs m-0 px-3" id="aiTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="tab-overview" data-bs-toggle="tab" data-bs-target="#content-overview" type="button" role="tab">
                                        <i class="bi bi-bar-chart-fill me-2"></i>总览统计
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-config" data-bs-toggle="tab" data-bs-target="#content-config" type="button" role="tab">
                                        <i class="bi bi-gear-fill me-2"></i>全局配置
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-api" data-bs-toggle="tab" data-bs-target="#content-api" type="button" role="tab">
                                        <i class="bi bi-key-fill me-2"></i>API 配置
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-logs" data-bs-toggle="tab" data-bs-target="#content-logs" type="button" role="tab">
                                        <i class="bi bi-journal-text me-2"></i>日志管理
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="tab-content" id="aiTabsContent">
                        <!-- 1. 总览统计 -->
                        <div class="tab-pane fade show active" id="content-overview" role="tabpanel">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>总览统计</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="row g-3 text-center">
                                                <div class="col-6 col-md-3">
                                                    <div class="p-3 bg-light rounded">
                                                        <div class="h4 mb-1 text-primary"><?= number_format((int)($logStats['cnt'] ?? 0)) ?></div>
                                                        <small class="text-muted">总调用次数</small>
                                                    </div>
                                                </div>
                                                <div class="col-6 col-md-3">
                                                    <div class="p-3 bg-success bg-opacity-10 rounded">
                                                        <div class="h4 mb-1 text-success"><?= number_format((int)($logStats['ok_cnt'] ?? 0)) ?></div>
                                                        <small class="text-muted">成功次数</small>
                                                    </div>
                                                </div>
                                                <div class="col-6 col-md-3">
                                                    <div class="p-3 bg-danger bg-opacity-10 rounded">
                                                        <div class="h4 mb-1 text-danger"><?= number_format((int)($logStats['fail_cnt'] ?? 0)) ?></div>
                                                        <small class="text-muted">失败次数</small>
                                                    </div>
                                                </div>
                                                <div class="col-6 col-md-3">
                                                    <div class="p-3 bg-primary bg-opacity-10 rounded">
                                                        <div class="h4 mb-1 text-primary"><?= number_format((int)($logStats['token_sum'] ?? 0)) ?></div>
                                                        <small class="text-muted">累计 Tokens</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if (!empty($ai['ai_feature_enabled'])): ?>
                                            <div class="mt-3 text-center">
                                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>AI 摘要功能已启用</span>
                                            </div>
                                            <?php else: ?>
                                            <div class="mt-3 text-center">
                                                <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>AI 摘要功能已关闭</span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                        </div>

                        <!-- 2. 全局配置 -->
                        <div class="tab-pane fade" id="content-config" role="tabpanel">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>全局参数</h5>
                                        </div>
                                        <div class="card-body">
                                            <form method="post" class="row g-3">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="ai_action" value="save_global">
                                                <div class="col-md-12">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="ai_feature_enabled" id="ai_feature_enabled" value="1" <?= !empty($ai['ai_feature_enabled']) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="ai_feature_enabled">启用 AI 摘要（关闭后无法生成，前台不展示）</label>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">摘要区块标题</label>
                                                    <input type="text" name="ai_summary_section_title" class="form-control" value="<?= e($ai['ai_summary_section_title'] ?? '文章摘要') ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">温度</label>
                                                    <input type="number" name="ai_temperature" class="form-control" step="0.05" min="0" max="2" value="<?= e((string)($ai['ai_temperature'] ?? 0.3)) ?>">
                                                </div>
                                                <div class="col-12">
                                                    <p class="small text-muted mb-0">摘要将发送<strong>全文</strong>（已排除 <code>[Paid]</code> / <code>[Privacy]</code>）；接口 <code>max_tokens</code> 在程序内固定为较大值以尽量输出完整内容，实际长度仍受所选模型与服务商限制。</p>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-primary">保存全局参数</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                        </div>

                        <!-- 3. API 配置 -->
                        <div class="tab-pane fade" id="content-api" role="tabpanel">
                                    <div class="card">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">模型列表</h5>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modelModal" onclick="editModel(0)"><i class="bi bi-plus-lg"></i> 新增模型</button>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-hover mb-0 align-middle">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>名称</th>
                                                            <th>API Base</th>
                                                            <th>模型 ID</th>
                                                            <th>Key</th>
                                                            <th>启用</th>
                                                            <th>默认</th>
                                                            <th class="text-end">操作</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($models as $m): ?>
                                                        <tr>
                                                            <td><?= e($m['name']) ?></td>
                                                            <td class="mono-sm"><?= e($m['api_base']) ?></td>
                                                            <td><code><?= e($m['model_id']) ?></code></td>
                                                            <td><?= e(aiMaskApiKey($m['api_key'])) ?></td>
                                                            <td><?= $m['enabled'] ? '<span class="badge bg-success">是</span>' : '<span class="badge bg-secondary">否</span>' ?></td>
                                                            <td><?= $m['is_default'] ? '<span class="badge bg-primary">默认</span>' : '—' ?></td>
                                                            <td class="text-end text-nowrap">
                                                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick='editModel(<?= (int)$m['id'] ?>)'>编辑</button>
                                                                <form method="post" class="d-inline" onsubmit="return confirm('设为默认摘要模型？');">
                                                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                                    <input type="hidden" name="ai_action" value="set_default">
                                                                    <input type="hidden" name="default_id" value="<?= (int)$m['id'] ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-primary" <?= !$m['enabled'] ? 'disabled title="请先启用"' : '' ?>>设默认</button>
                                                                </form>
                                                                <form method="post" class="d-inline">
                                                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                                    <input type="hidden" name="ai_action" value="test_model">
                                                                    <input type="hidden" name="test_id" value="<?= (int)$m['id'] ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-info">测试</button>
                                                                </form>
                                                                <form method="post" class="d-inline" onsubmit="return confirm('确定删除该模型？');">
                                                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                                                    <input type="hidden" name="ai_action" value="delete_model">
                                                                    <input type="hidden" name="delete_id" value="<?= (int)$m['id'] ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($models)): ?>
                                                        <tr><td colspan="7" class="text-center text-muted py-4">暂无模型，请点击「新增模型」</td></tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                        </div>

                        <!-- 4. 日志管理 -->
                        <div class="tab-pane fade" id="content-logs" role="tabpanel">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>调用日志</h5>
                                        </div>
                                        <div class="card-body border-bottom bg-light py-3">
                                            <form method="get" class="row g-2 align-items-end">
                                                <input type="hidden" name="log_page" value="1">
                                                <div class="col-md-3 col-lg-2">
                                                    <label class="form-label small mb-1 text-muted">结果</label>
                                                    <select name="log_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                                        <option value="" <?= $logStatus === '' ? 'selected' : '' ?>>全部</option>
                                                        <option value="ok" <?= $logStatus === 'ok' ? 'selected' : '' ?>>仅成功</option>
                                                        <option value="fail" <?= $logStatus === 'fail' ? 'selected' : '' ?>>仅失败</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4 col-lg-3">
                                                    <label class="form-label small mb-1 text-muted">模型配置</label>
                                                    <select name="log_model" class="form-select form-select-sm" onchange="this.form.submit()">
                                                        <option value="0">全部模型</option>
                                                        <?php foreach ($models as $m): ?>
                                                        <option value="<?= (int)$m['id'] ?>" <?= $logModelFilter === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?> (#<?= (int)$m['id'] ?>)</option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-auto">
                                                    <a href="ai_manage.php" class="btn btn-sm btn-outline-secondary">重置筛选</a>
                                                </div>
                                                <div class="col-md-auto ms-md-auto text-muted small">
                                                    当前筛选共 <strong><?= (int)$logTotalRows ?></strong> 条 · 每页 <?= (int)$logPerPage ?> 条
                                                </div>
                                            </form>
                                        </div>
                                        
                                        <!-- 分页控件 -->
                                        <?php if ($logTotalPages > 1): ?>
                                        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-3 py-3 px-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="text-muted small">
                                                    <i class="bi bi-file-text me-1"></i>共 <?= (int)$logTotalRows ?> 条记录
                                                </span>
                                                <span class="badge bg-light text-dark border">
                                                    第 <?= (int)$logPage ?> / <?= (int)$logTotalPages ?> 页
                                                </span>
                                            </div>
                                            <nav aria-label="日志分页">
                                                <ul class="pagination pagination-sm mb-0 shadow-sm rounded" style="overflow: hidden;">
                                                    <?php
                                                    $prevP = max(1, $logPage - 1);
                                                    $nextP = min($logTotalPages, $logPage + 1);
                                                    ?>
                                                    <li class="page-item <?= $logPage <= 1 ? 'disabled' : '' ?>">
                                                        <a class="page-link border-0 rounded-0 <?= $logPage <= 1 ? 'text-muted' : 'text-primary' ?>" href="<?= $logPage <= 1 ? '#' : ('ai_manage.php' . $buildLogQuery(['log_page' => $prevP])) ?>">
                                                            <i class="bi bi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    <?php
                                                    $winStart = max(1, $logPage - 2);
                                                    $winEnd = min($logTotalPages, $logPage + 2);
                                                    if ($winEnd - $winStart < 4) {
                                                        if ($winStart === 1) {
                                                            $winEnd = min($logTotalPages, $winStart + 4);
                                                        } else {
                                                            $winStart = max(1, $winEnd - 4);
                                                        }
                                                    }
                                                    if ($winStart > 1):
                                                    ?>
                                                    <li class="page-item">
                                                        <a class="page-link border-0 text-secondary" href="ai_manage.php<?= e($buildLogQuery(['log_page' => 1])) ?>">1</a>
                                                    </li>
                                                    <?php if ($winStart > 2): ?>
                                                    <li class="page-item disabled"><span class="page-link border-0 text-secondary">…</span></li>
                                                    <?php endif; endif; ?>
                                                    
                                                    <?php for ($p = $winStart; $p <= $winEnd; $p++): ?>
                                                    <li class="page-item <?= $p === $logPage ? 'active' : '' ?>">
                                                        <?php if ($p === $logPage): ?>
                                                        <span class="page-link border-0 bg-primary"><?= (int)$p ?></span>
                                                        <?php else: ?>
                                                        <a class="page-link border-0 text-primary" href="ai_manage.php<?= e($buildLogQuery(['log_page' => $p])) ?>"><?= (int)$p ?></a>
                                                        <?php endif; ?>
                                                    </li>
                                                    <?php endfor; ?>
                                                    
                                                    <?php
                                                    if ($winEnd < $logTotalPages):
                                                    if ($winEnd < $logTotalPages - 1):
                                                    ?>
                                                    <li class="page-item disabled"><span class="page-link border-0 text-secondary">…</span></li>
                                                    <?php endif; ?>
                                                    <li class="page-item">
                                                        <a class="page-link border-0 text-secondary" href="ai_manage.php<?= e($buildLogQuery(['log_page' => $logTotalPages])) ?>"><?= (int)$logTotalPages ?></a>
                                                    </li>
                                                    <?php endif; ?>
                                                    
                                                    <li class="page-item <?= $logPage >= $logTotalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link border-0 rounded-0 <?= $logPage >= $logTotalPages ? 'text-muted' : 'text-primary' ?>" href="<?= $logPage >= $logTotalPages ? '#' : ('ai_manage.php' . $buildLogQuery(['log_page' => $nextP])) ?>">
                                                            <i class="bi bi-chevron-right"></i>
                                                        </a>
                                                    </li>
                                                </ul>
                                            </nav>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="card-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-hover table-sm mb-0 ai-log-table">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th style="width:60px">ID</th>
                                                            <th style="width:152px">时间</th>
                                                            <th style="width:100px">操作者</th>
                                                            <th>文章</th>
                                                            <th style="width:120px">模型配置</th>
                                                            <th style="width:110px">模型 ID</th>
                                                            <th style="width:100px">Tokens</th>
                                                            <th style="width:60px">结果</th>
                                                            <th>备注</th>
                                                            <th style="width:70px">操作</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($usageRows as $u): ?>
                                                        <tr>
                                                            <td><small class="text-muted">#<?= (int)$u['id'] ?></small></td>
                                                            <td><small><?= e($u['created_at']) ?></small></td>
                                                            <td><small><?= $u['admin_id'] ? e($u['admin_name'] ?? ('#' . $u['admin_id'])) : '—' ?></small></td>
                                                            <td>
                                                                <?php if (!empty($u['post_id'])): ?>
                                                                <a href="/blog.php?id=<?= (int)$u['post_id'] ?>" target="_blank" rel="noopener" class="text-decoration-none"><?= e(mb_substr($u['post_title'] ?? ('文章#'.$u['post_id']), 0, 30)) ?><?= mb_strlen($u['post_title'] ?? '') > 30 ? '…' : '' ?></a>
                                                                <?php else: ?>
                                                                <span class="text-muted">—</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><small><?= $u['ai_model_id'] ? e($u['ai_model_name'] ?? ('#'.$u['ai_model_id'])) : '—' ?></small></td>
                                                            <td class="mono-sm"><code><?= e($u['model_id_str'] ?? '') ?></code></td>
                                                            <td><small><strong>Σ <?= (int)$u['total_tokens'] ?></strong></small></td>
                                                            <td><?= !empty($u['success']) ? '<span class="badge bg-success">成功</span>' : '<span class="badge bg-danger">失败</span>' ?></td>
                                                            <td class="ai-log-err text-break">
                                                                <?php if (!empty($u['success'])): ?>
                                                                <span class="text-muted">—</span>
                                                                <?php else: ?>
                                                                <span class="text-danger" title="<?= e($u['error_message'] ?? '') ?>"><?= e(mb_substr($u['error_message'] ?? '', 0, 60)) ?><?= mb_strlen($u['error_message'] ?? '') > 60 ? '…' : '' ?></span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="showLogDetail(<?= (int)$u['id'] ?>)">查看</button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($usageRows)): ?>
                                                        <tr><td colspan="10" class="text-center text-muted py-4">暂无记录<?= ($logStatus !== '' || $logModelFilter > 0) ? '（请调整筛选条件）' : '' ?></td></tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        
                                        <?php if ($logTotalPages > 1): ?>
                                        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-3 py-3 px-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="text-muted small">
                                                    <i class="bi bi-file-text me-1"></i>共 <?= (int)$logTotalRows ?> 条记录
                                                </span>
                                                <span class="badge bg-light text-dark border">
                                                    第 <?= (int)$logPage ?> / <?= (int)$logTotalPages ?> 页
                                                </span>
                                            </div>
                                            <nav aria-label="日志分页">
                                                <ul class="pagination pagination-sm mb-0 shadow-sm rounded" style="overflow: hidden;">
                                                    <?php
                                                    $prevP = max(1, $logPage - 1);
                                                    $nextP = min($logTotalPages, $logPage + 1);
                                                    ?>
                                                    <li class="page-item <?= $logPage <= 1 ? 'disabled' : '' ?>">
                                                        <a class="page-link border-0 rounded-0 <?= $logPage <= 1 ? 'text-muted' : 'text-primary' ?>" href="<?= $logPage <= 1 ? '#' : ('ai_manage.php' . $buildLogQuery(['log_page' => $prevP])) ?>">
                                                            <i class="bi bi-chevron-left"></i>
                                                        </a>
                                                    </li>
                                                    <?php
                                                    $winStart = max(1, $logPage - 2);
                                                    $winEnd = min($logTotalPages, $logPage + 2);
                                                    if ($winEnd - $winStart < 4) {
                                                        if ($winStart === 1) {
                                                            $winEnd = min($logTotalPages, $winStart + 4);
                                                        } else {
                                                            $winStart = max(1, $winEnd - 4);
                                                        }
                                                    }
                                                    if ($winStart > 1):
                                                    ?>
                                                    <li class="page-item">
                                                        <a class="page-link border-0 text-secondary" href="ai_manage.php<?= e($buildLogQuery(['log_page' => 1])) ?>">1</a>
                                                    </li>
                                                    <?php if ($winStart > 2): ?>
                                                    <li class="page-item disabled"><span class="page-link border-0 text-secondary">…</span></li>
                                                    <?php endif; endif; ?>
                                                    
                                                    <?php for ($p = $winStart; $p <= $winEnd; $p++): ?>
                                                    <li class="page-item <?= $p === $logPage ? 'active' : '' ?>">
                                                        <?php if ($p === $logPage): ?>
                                                        <span class="page-link border-0 bg-primary"><?= (int)$p ?></span>
                                                        <?php else: ?>
                                                        <a class="page-link border-0 text-primary" href="ai_manage.php<?= e($buildLogQuery(['log_page' => $p])) ?>"><?= (int)$p ?></a>
                                                        <?php endif; ?>
                                                    </li>
                                                    <?php endfor; ?>
                                                    
                                                    <?php
                                                    if ($winEnd < $logTotalPages):
                                                    if ($winEnd < $logTotalPages - 1):
                                                    ?>
                                                    <li class="page-item disabled"><span class="page-link border-0 text-secondary">…</span></li>
                                                    <?php endif; ?>
                                                    <li class="page-item">
                                                        <a class="page-link border-0 text-secondary" href="ai_manage.php<?= e($buildLogQuery(['log_page' => $logTotalPages])) ?>"><?= (int)$logTotalPages ?></a>
                                                    </li>
                                                    <?php endif; ?>
                                                    
                                                    <li class="page-item <?= $logPage >= $logTotalPages ? 'disabled' : '' ?>">
                                                        <a class="page-link border-0 rounded-0 <?= $logPage >= $logTotalPages ? 'text-muted' : 'text-primary' ?>" href="<?= $logPage >= $logTotalPages ? '#' : ('ai_manage.php' . $buildLogQuery(['log_page' => $nextP])) ?>">
                                                            <i class="bi bi-chevron-right"></i>
                                                        </a>
                                                    </li>
                                                </ul>
                                            </nav>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                        </div>
                    </div>

<!-- Modal -->
<div class="modal fade" id="modelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="ai_action" value="save_model">
                <input type="hidden" name="model_id" id="m_id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="modelModalTitle">新增模型</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">显示名称</label>
                        <input type="text" name="name" id="m_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">API Base（如 https://api.openai.com 或已含 /v1 的网关根地址）</label>
                        <input type="text" name="api_base" id="m_api_base" class="form-control" required placeholder="https://...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">模型 ID（请求体 model）</label>
                        <input type="text" name="model_id_str" id="m_model_id_str" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">API Key（每条模型独立，必填；编辑时留空表示保留原密钥）</label>
                        <input type="password" name="api_key" id="m_api_key" class="form-control" autocomplete="new-password" placeholder="新建必填">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">排序</label>
                            <input type="number" name="sort_order" id="m_sort" class="form-control" value="0">
                        </div>
                        <div class="col-md-6 mb-3 d-flex align-items-end gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="enabled" id="m_enabled" value="1" checked>
                                <label class="form-check-label" for="m_enabled">启用</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_default" id="m_is_default" value="1">
                                <label class="form-check-label" for="m_is_default">设为默认摘要模型</label>
                            </div>
                        </div>
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

<!-- 日志详情 Modal -->
<div class="modal fade" id="logDetailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">调用详情 #<span id="logDetailId"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-request-tab" data-bs-toggle="tab" data-bs-target="#tab-request" type="button" role="tab">
                            <i class="bi bi-send me-1"></i>请求体
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-response-tab" data-bs-toggle="tab" data-bs-target="#tab-response" type="button" role="tab">
                            <i class="bi bi-inbox me-1"></i>响应体
                        </button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tab-request" role="tabpanel">
                        <div class="bg-dark text-light p-3 rounded" style="max-height: 500px; overflow-y: auto;">
                            <pre class="mb-0" style="white-space: pre-wrap; word-break: break-all; font-size: 0.85rem;" id="logRequestPayload"></pre>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tab-response" role="tabpanel">
                        <div class="bg-dark text-light p-3 rounded" style="max-height: 500px; overflow-y: auto;">
                            <pre class="mb-0" style="white-space: pre-wrap; word-break: break-all; font-size: 0.85rem;" id="logResponsePayload"></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
<script>
const MODELS = <?= json_encode(array_map(function ($m) {
    return [
        'id' => (int)$m['id'],
        'name' => $m['name'],
        'api_base' => $m['api_base'],
        'model_id' => $m['model_id'],
        'enabled' => (int)$m['enabled'],
        'is_default' => (int)$m['is_default'],
        'sort_order' => (int)$m['sort_order'],
    ];
}, $models), JSON_UNESCAPED_UNICODE) ?>;

// 日志详情数据
const LOG_RECORDS = <?= json_encode(array_map(function ($u) {
    return [
        'id' => (int)$u['id'],
        'request_payload' => $u['request_payload'] ?? null,
        'response_payload' => $u['response_payload'] ?? null,
    ];
}, $usageRows), JSON_UNESCAPED_UNICODE) ?>;

function editModel(id) {
    document.getElementById('modelModalTitle').textContent = id ? '编辑模型' : '新增模型';
    document.getElementById('m_id').value = id || 0;
    document.getElementById('m_api_key').value = '';
    document.getElementById('m_api_key').required = !id;
    if (!id) {
        document.getElementById('m_name').value = '';
        document.getElementById('m_api_base').value = '';
        document.getElementById('m_model_id_str').value = '';
        document.getElementById('m_sort').value = 0;
        document.getElementById('m_enabled').checked = true;
        document.getElementById('m_is_default').checked = false;
        new bootstrap.Modal(document.getElementById('modelModal')).show();
        return;
    }
    const m = MODELS.find(x => x.id === id);
    if (!m) return;
    document.getElementById('m_name').value = m.name;
    document.getElementById('m_api_base').value = m.api_base;
    document.getElementById('m_model_id_str').value = m.model_id;
    document.getElementById('m_sort').value = m.sort_order;
    document.getElementById('m_enabled').checked = !!m.enabled;
    document.getElementById('m_is_default').checked = !!m.is_default;
    new bootstrap.Modal(document.getElementById('modelModal')).show();
}

function showLogDetail(id) {
    const log = LOG_RECORDS.find(x => x.id === id);
    if (!log) return;
    document.getElementById('logDetailId').textContent = id;
    document.getElementById('logRequestPayload').textContent = log.request_payload ? formatJson(log.request_payload) : '(无数据)';
    document.getElementById('logResponsePayload').textContent = log.response_payload ? formatJson(log.response_payload) : '(无数据)';
    new bootstrap.Modal(document.getElementById('logDetailModal')).show();
}

function formatJson(str) {
    try {
        const obj = JSON.parse(str);
        return JSON.stringify(obj, null, 2);
    } catch (e) {
        return str;
    }
}
</script>
<?php if (!$is_embedded): require_once 'includes/footer.php'; else: ?>
</body>
</html>
<?php endif; ?>
