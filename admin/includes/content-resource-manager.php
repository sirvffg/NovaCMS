<?php
/**
 * Shared CRUD screen for the lightweight content modules introduced in the
 * administration redesign.
 *
 * Required variables:
 *   $db             PDO connection
 *   $config         Website configuration used by the shared header
 *   $resourceConfig Screen and field configuration
 */

if (!isset($db, $resourceConfig) || !is_array($resourceConfig)) {
    throw new RuntimeException('内容管理器缺少必要配置');
}

if (!function_exists('novaResourceIdentifier')) {
    function novaResourceIdentifier($identifier) {
        $identifier = (string)$identifier;
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException('无效的数据字段');
        }
        return '`' . $identifier . '`';
    }

    function novaResourceStringLength($value) {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    function novaResourceSetFlash($type, $message) {
        $_SESSION[novaResourceSessionKey('flash')] = [
            'type' => $type === 'success' ? 'success' : 'danger',
            'message' => (string)$message,
        ];
    }

    function novaResourceSessionKey($suffix) {
        $namespace = $GLOBALS['novaContentResourceSessionNamespace'] ?? 'content_resource_default';
        return $namespace . '_' . preg_replace('/[^a-z0-9_-]+/i', '_', (string)$suffix);
    }

    function novaResourceRedirect($path, $query = []) {
        $url = $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        header('Location: ' . $url);
        exit;
    }

    function novaResourceStatusMeta($resourceConfig, $status) {
        $statuses = $resourceConfig['statuses'] ?? [];
        if (isset($statuses[$status])) {
            $meta = $statuses[$status];
            if (is_string($meta)) {
                return ['label' => $meta, 'tone' => 'secondary'];
            }
            return [
                'label' => $meta['label'] ?? $status,
                'tone' => $meta['tone'] ?? 'secondary',
            ];
        }
        return ['label' => $status !== '' ? $status : '未设置', 'tone' => 'secondary'];
    }

    function novaResourcePublicUrl($pattern, $row) {
        if (!$pattern) {
            return '';
        }
        return preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($matches) use ($row) {
            return rawurlencode((string)($row[$matches[1]] ?? ''));
        }, $pattern);
    }

    function novaResourceExcerpt($value, $length = 64) {
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value)));
        if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $length) {
            return mb_substr($value, 0, $length, 'UTF-8') . '…';
        }
        if (!function_exists('mb_strlen') && strlen($value) > $length) {
            return substr($value, 0, $length) . '…';
        }
        return $value;
    }

    function novaResourceOrderBy($sort) {
        $parts = array_filter(array_map('trim', explode(',', (string)$sort)));
        if (!$parts) {
            return '`id` DESC';
        }

        $safeParts = [];
        foreach ($parts as $part) {
            if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+(ASC|DESC))?$/i', $part, $matches)) {
                throw new InvalidArgumentException('无效的排序配置');
            }
            $safeParts[] = novaResourceIdentifier($matches[1]) . ' ' . strtoupper($matches[2] ?? 'ASC');
        }
        return implode(', ', $safeParts);
    }
}

$resourceTable = (string)($resourceConfig['table'] ?? '');
$resourceFields = $resourceConfig['fields'] ?? [];
$resourcePage = basename($_SERVER['PHP_SELF'] ?? '');
$resourcePrimaryKey = (string)($resourceConfig['primary_key'] ?? 'id');
$resourceStatusField = (string)($resourceConfig['status_field'] ?? '');
$resourceTitle = (string)($resourceConfig['title'] ?? '内容管理');
$resourceSingular = (string)($resourceConfig['singular'] ?? '内容');
$resourceIcon = (string)($resourceConfig['icon'] ?? 'bi-file-earmark');
$resourceDescription = (string)($resourceConfig['description'] ?? '');
$resourcePrimaryField = (string)($resourceConfig['primary_field'] ?? 'title');
$resourceSearchFields = $resourceConfig['search_fields'] ?? [$resourcePrimaryField];
$resourceListColumns = $resourceConfig['list_columns'] ?? [];
$resourcePerPage = max(5, min(100, (int)($resourceConfig['per_page'] ?? 15)));
$resourceDefaultSort = novaResourceOrderBy((string)($resourceConfig['default_sort'] ?? ($resourcePrimaryKey . ' DESC')));
$novaContentResourceSessionNamespace = 'content_resource_' . preg_replace('/[^a-z0-9_-]+/i', '_', $resourceTable);

novaResourceIdentifier($resourceTable);
novaResourceIdentifier($resourcePrimaryKey);
foreach ($resourceFields as $fieldName => $fieldConfig) {
    novaResourceIdentifier($fieldName);
}
foreach ($resourceSearchFields as $fieldName) {
    novaResourceIdentifier($fieldName);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        novaResourceSetFlash('danger', '页面已过期，请刷新后重试');
        novaResourceRedirect($resourcePage);
    }

    $resourceAction = (string)($_POST['action'] ?? '');
    $resourceId = max(0, (int)($_POST[$resourcePrimaryKey] ?? 0));

    try {
        if ($resourceAction === 'delete') {
            if ($resourceId <= 0) {
                throw new InvalidArgumentException('请选择要删除的记录');
            }
            $statement = $db->prepare(
                'DELETE FROM ' . novaResourceIdentifier($resourceTable) .
                ' WHERE ' . novaResourceIdentifier($resourcePrimaryKey) . ' = ?'
            );
            $statement->execute([$resourceId]);
            if ($statement->rowCount() < 1) {
                throw new InvalidArgumentException('记录不存在或已删除');
            }
            novaResourceSetFlash('success', $resourceSingular . '已删除');
            novaResourceRedirect($resourcePage);
        }

        if ($resourceAction === 'toggle_status') {
            if ($resourceId <= 0 || $resourceStatusField === '') {
                throw new InvalidArgumentException('当前记录不支持状态切换');
            }
            novaResourceIdentifier($resourceStatusField);
            $allowedStatuses = array_keys($resourceConfig['statuses'] ?? []);
            $nextStatus = (string)($_POST['next_status'] ?? '');
            if (!in_array($nextStatus, $allowedStatuses, true)) {
                throw new InvalidArgumentException('无效的目标状态');
            }
            $toggleAssignments = [novaResourceIdentifier($resourceStatusField) . ' = ?'];
            $toggleParams = [$nextStatus];
            $timestampConfig = $resourceConfig['status_timestamp'] ?? null;
            if (is_array($timestampConfig) && !empty($timestampConfig['field'])) {
                $timestampField = novaResourceIdentifier($timestampConfig['field']);
                $timestampStatus = (string)($timestampConfig['status'] ?? '');
                $toggleAssignments[] = $timestampField . ' = ' . ($nextStatus === $timestampStatus ? 'NOW()' : 'NULL');
            }
            $toggleParams[] = $resourceId;
            $statement = $db->prepare(
                'UPDATE ' . novaResourceIdentifier($resourceTable) .
                ' SET ' . implode(', ', $toggleAssignments) .
                ' WHERE ' . novaResourceIdentifier($resourcePrimaryKey) . ' = ?'
            );
            $statement->execute($toggleParams);
            if ($statement->rowCount() < 1) {
                $check = $db->prepare(
                    'SELECT 1 FROM ' . novaResourceIdentifier($resourceTable) .
                    ' WHERE ' . novaResourceIdentifier($resourcePrimaryKey) . ' = ? LIMIT 1'
                );
                $check->execute([$resourceId]);
                if (!$check->fetchColumn()) {
                    throw new InvalidArgumentException('记录不存在或已删除');
                }
            }
            $statusMeta = novaResourceStatusMeta($resourceConfig, $nextStatus);
            novaResourceSetFlash('success', $resourceSingular . '已切换为“' . $statusMeta['label'] . '”');
            novaResourceRedirect($resourcePage);
        }

        if ($resourceAction !== 'save') {
            throw new InvalidArgumentException('未知操作');
        }

        $values = [];
        $errors = [];
        foreach ($resourceFields as $fieldName => $fieldConfig) {
            if (!empty($fieldConfig['readonly']) || (array_key_exists('persist', $fieldConfig) && $fieldConfig['persist'] === false)) {
                continue;
            }

            $fieldType = (string)($fieldConfig['type'] ?? 'text');
            if ($fieldType === 'checkbox') {
                $value = isset($_POST[$fieldName]) ? 1 : 0;
            } elseif ($fieldType === 'number') {
                $rawNumber = trim((string)($_POST[$fieldName] ?? ''));
                $validNumber = !empty($fieldConfig['decimal'])
                    ? is_numeric($rawNumber)
                    : (bool)preg_match('/^-?\d+$/', $rawNumber);
                if (!$validNumber) {
                    $errors[] = ($fieldConfig['label'] ?? $fieldName) . '必须是有效数字';
                    $rawNumber = (string)($fieldConfig['default'] ?? 0);
                }
                $value = !empty($fieldConfig['decimal']) ? (float)$rawNumber : (int)$rawNumber;
                if (is_float($value) && !is_finite($value)) {
                    $errors[] = ($fieldConfig['label'] ?? $fieldName) . '超出允许范围';
                    $value = (float)($fieldConfig['default'] ?? 0);
                }
                if (isset($fieldConfig['min'])) {
                    $value = max($fieldConfig['min'], $value);
                }
                if (isset($fieldConfig['max'])) {
                    $value = min($fieldConfig['max'], $value);
                }
            } else {
                $value = trim((string)($_POST[$fieldName] ?? ''));
            }

            if ($fieldType === 'slug') {
                if ($value === '') {
                    $slugSource = (string)($fieldConfig['source'] ?? $resourcePrimaryField);
                    $value = sanitize_title((string)($_POST[$slugSource] ?? ''));
                }
                $normalizedSlug = function_exists('contentModuleNormalizeSlug')
                    ? contentModuleNormalizeSlug($value)
                    : (preg_match('/^[\p{L}\p{N}_-]+$/u', $value) ? strtolower($value) : '');
                if ($normalizedSlug === '') {
                    $errors[] = ($fieldConfig['label'] ?? $fieldName) . '只能包含中文、字母、数字、下划线和连字符';
                } else {
                    $value = $normalizedSlug;
                }
            }

            if ($fieldType === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ($fieldConfig['label'] ?? $fieldName) . '格式不正确';
            }

            if ($fieldType === 'url' && $value !== '') {
                $urlScheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
                $urlParts = parse_url($value);
                $validAbsoluteUrl = in_array($urlScheme, ['http', 'https'], true)
                    && filter_var($value, FILTER_VALIDATE_URL)
                    && is_array($urlParts)
                    && !isset($urlParts['user'])
                    && !isset($urlParts['pass'])
                    && strpos($value, '\\') === false
                    && !preg_match('/%(?:0a|0d|5c)/i', $value);
                $validSitePath = (bool)preg_match('#^/(?!/)[^\x00-\x1F\x7F\\\\]*$#', $value)
                    && !preg_match('/%(?:0a|0d|5c)/i', $value);
                $validUrl = $validAbsoluteUrl || $validSitePath;
                if (!$validUrl) {
                    $errors[] = ($fieldConfig['label'] ?? $fieldName) . '需填写完整网址或站内路径';
                }
            }

            if ($fieldType === 'select') {
                $allowedOptions = array_map('strval', array_keys($fieldConfig['options'] ?? []));
                if (!in_array((string)$value, $allowedOptions, true)) {
                    $errors[] = ($fieldConfig['label'] ?? $fieldName) . '选项无效';
                }
            }

            if (!empty($fieldConfig['required']) && ($value === '' || $value === null)) {
                $errors[] = ($fieldConfig['label'] ?? $fieldName) . '不能为空';
            }

            $maxLength = (int)($fieldConfig['maxlength'] ?? 0);
            if ($maxLength > 0 && is_string($value) && novaResourceStringLength($value) > $maxLength) {
                $errors[] = ($fieldConfig['label'] ?? $fieldName) . '不能超过 ' . $maxLength . ' 个字符';
            }

            $values[$fieldName] = $value;
        }

        foreach (($resourceConfig['unique_fields'] ?? []) as $uniqueField) {
            if (!array_key_exists($uniqueField, $values) || $values[$uniqueField] === '') {
                continue;
            }
            novaResourceIdentifier($uniqueField);
            $sql = 'SELECT ' . novaResourceIdentifier($resourcePrimaryKey) .
                ' FROM ' . novaResourceIdentifier($resourceTable) .
                ' WHERE ' . novaResourceIdentifier($uniqueField) . ' = ?';
            $params = [$values[$uniqueField]];
            if ($resourceId > 0) {
                $sql .= ' AND ' . novaResourceIdentifier($resourcePrimaryKey) . ' != ?';
                $params[] = $resourceId;
            }
            $sql .= ' LIMIT 1';
            $statement = $db->prepare($sql);
            $statement->execute($params);
            if ($statement->fetch()) {
                $fieldLabel = $resourceFields[$uniqueField]['label'] ?? $uniqueField;
                $errors[] = $fieldLabel . '已存在';
            }
        }

        $timestampConfig = $resourceConfig['status_timestamp'] ?? null;
        if (is_array($timestampConfig) && !empty($timestampConfig['field']) && $resourceStatusField !== '') {
            $timestampField = (string)$timestampConfig['field'];
            novaResourceIdentifier($timestampField);
            $timestampStatus = (string)($timestampConfig['status'] ?? '');
            $nextResourceStatus = (string)($values[$resourceStatusField] ?? '');
            $existingStatus = '';
            $existingTimestamp = null;
            if ($resourceId > 0) {
                $stateStatement = $db->prepare(
                    'SELECT ' . novaResourceIdentifier($resourceStatusField) . ', ' . novaResourceIdentifier($timestampField) .
                    ' FROM ' . novaResourceIdentifier($resourceTable) .
                    ' WHERE ' . novaResourceIdentifier($resourcePrimaryKey) . ' = ? LIMIT 1'
                );
                $stateStatement->execute([$resourceId]);
                $existingState = $stateStatement->fetch(PDO::FETCH_ASSOC) ?: [];
                $existingStatus = (string)($existingState[$resourceStatusField] ?? '');
                $existingTimestamp = $existingState[$timestampField] ?? null;
            }
            if ($nextResourceStatus !== $timestampStatus) {
                $values[$timestampField] = null;
            } elseif ($existingStatus === $timestampStatus && $existingTimestamp) {
                $values[$timestampField] = $existingTimestamp;
            } else {
                $values[$timestampField] = $db->query('SELECT NOW()')->fetchColumn();
            }
        }

        if (!empty($resourceConfig['validate']) && is_callable($resourceConfig['validate'])) {
            $customErrors = call_user_func($resourceConfig['validate'], $values, $resourceId, $db);
            if (is_array($customErrors)) {
                $errors = array_merge($errors, $customErrors);
            }
        }

        if ($errors) {
            $_SESSION[novaResourceSessionKey('old')] = $values;
            novaResourceSetFlash('danger', implode('；', array_unique($errors)));
            $query = ['action' => $resourceId > 0 ? 'edit' : 'add'];
            if ($resourceId > 0) {
                $query[$resourcePrimaryKey] = $resourceId;
            }
            novaResourceRedirect($resourcePage, $query);
        }

        if ($resourceId > 0) {
            $assignments = [];
            $params = [];
            foreach ($values as $fieldName => $value) {
                $assignments[] = novaResourceIdentifier($fieldName) . ' = ?';
                $params[] = $value;
            }
            $params[] = $resourceId;
            $statement = $db->prepare(
                'UPDATE ' . novaResourceIdentifier($resourceTable) .
                ' SET ' . implode(', ', $assignments) .
                ' WHERE ' . novaResourceIdentifier($resourcePrimaryKey) . ' = ?'
            );
            $statement->execute($params);
            if ($statement->rowCount() < 1) {
                $check = $db->prepare(
                    'SELECT 1 FROM ' . novaResourceIdentifier($resourceTable) .
                    ' WHERE ' . novaResourceIdentifier($resourcePrimaryKey) . ' = ? LIMIT 1'
                );
                $check->execute([$resourceId]);
                if (!$check->fetchColumn()) {
                    throw new InvalidArgumentException('记录不存在或已删除');
                }
            }
            novaResourceSetFlash('success', $resourceSingular . '已更新');
        } else {
            $columns = array_keys($values);
            $statement = $db->prepare(
                'INSERT INTO ' . novaResourceIdentifier($resourceTable) .
                ' (' . implode(', ', array_map('novaResourceIdentifier', $columns)) . ')' .
                ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            );
            $statement->execute(array_values($values));
            novaResourceSetFlash('success', $resourceSingular . '已创建');
        }

        novaResourceRedirect($resourcePage);
    } catch (InvalidArgumentException $exception) {
        novaResourceSetFlash('danger', $exception->getMessage());
    } catch (PDOException $exception) {
        error_log('Content resource save failed: ' . $exception->getMessage());
        novaResourceSetFlash('danger', '保存失败，请检查填写内容后重试');
    } catch (Throwable $exception) {
        error_log('Content resource action failed: ' . $exception->getMessage());
        novaResourceSetFlash('danger', '操作未完成，请稍后重试');
    }

    $fallbackQuery = [];
    if ($resourceAction === 'save') {
        $fallbackQuery['action'] = $resourceId > 0 ? 'edit' : 'add';
        if ($resourceId > 0) {
            $fallbackQuery[$resourcePrimaryKey] = $resourceId;
        }
    }
    novaResourceRedirect($resourcePage, $fallbackQuery);
}

$resourceFlashKey = novaResourceSessionKey('flash');
$resourceOldKey = novaResourceSessionKey('old');
$resourceFlash = $_SESSION[$resourceFlashKey] ?? null;
$resourceOld = $_SESSION[$resourceOldKey] ?? [];
unset($_SESSION[$resourceFlashKey], $_SESSION[$resourceOldKey]);

$resourceMode = (string)($_GET['action'] ?? 'list');
$resourceEditId = max(0, (int)($_GET[$resourcePrimaryKey] ?? 0));
$resourceEditing = null;
if ($resourceMode === 'edit' && $resourceEditId > 0) {
    $statement = $db->prepare(
        'SELECT * FROM ' . novaResourceIdentifier($resourceTable) .
        ' WHERE ' . novaResourceIdentifier($resourcePrimaryKey) . ' = ? LIMIT 1'
    );
    $statement->execute([$resourceEditId]);
    $resourceEditing = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$resourceEditing) {
        novaResourceSetFlash('danger', '记录不存在或已删除');
        novaResourceRedirect($resourcePage);
    }
}
if ($resourceOld) {
    $resourceEditing = array_merge($resourceEditing ?: [], $resourceOld);
}
$resourceIsForm = $resourceMode === 'add' || ($resourceMode === 'edit' && $resourceEditing);

$resourceQuery = trim((string)($_GET['q'] ?? ''));
if (novaResourceStringLength($resourceQuery) > 100) {
    $resourceQuery = function_exists('mb_substr')
        ? mb_substr($resourceQuery, 0, 100, 'UTF-8')
        : substr($resourceQuery, 0, 100);
}
$resourceStatusFilter = trim((string)($_GET['status'] ?? ''));
$resourceCurrentPage = max(1, (int)($_GET['page'] ?? 1));
$resourceWhere = [];
$resourceParams = [];

if ($resourceQuery !== '' && $resourceSearchFields) {
    $searchClauses = [];
    foreach ($resourceSearchFields as $index => $fieldName) {
        $placeholder = ':search_' . $index;
        $searchClauses[] = novaResourceIdentifier($fieldName) . ' LIKE ' . $placeholder;
        $resourceParams[$placeholder] = '%' . $resourceQuery . '%';
    }
    $resourceWhere[] = '(' . implode(' OR ', $searchClauses) . ')';
}

if ($resourceStatusField !== '' && $resourceStatusFilter !== '') {
    $allowedStatuses = array_keys($resourceConfig['statuses'] ?? []);
    if (in_array($resourceStatusFilter, $allowedStatuses, true)) {
        $resourceWhere[] = novaResourceIdentifier($resourceStatusField) . ' = :status_filter';
        $resourceParams[':status_filter'] = $resourceStatusFilter;
    } else {
        $resourceStatusFilter = '';
    }
}

$resourceWhereSql = $resourceWhere ? ' WHERE ' . implode(' AND ', $resourceWhere) : '';
$statement = $db->prepare(
    'SELECT COUNT(*) FROM ' . novaResourceIdentifier($resourceTable) . $resourceWhereSql
);
foreach ($resourceParams as $placeholder => $value) {
    $statement->bindValue($placeholder, $value, PDO::PARAM_STR);
}
$statement->execute();
$resourceTotal = (int)$statement->fetchColumn();
$resourceTotalPages = max(1, (int)ceil($resourceTotal / $resourcePerPage));
$resourceCurrentPage = min($resourceCurrentPage, $resourceTotalPages);
$resourceOffset = ($resourceCurrentPage - 1) * $resourcePerPage;

$resourceRows = [];
if (!$resourceIsForm) {
    $listSql = 'SELECT * FROM ' . novaResourceIdentifier($resourceTable) .
        $resourceWhereSql . ' ORDER BY ' . $resourceDefaultSort .
        ' LIMIT :resource_limit OFFSET :resource_offset';
    $statement = $db->prepare($listSql);
    foreach ($resourceParams as $placeholder => $value) {
        $statement->bindValue($placeholder, $value, PDO::PARAM_STR);
    }
    $statement->bindValue(':resource_limit', $resourcePerPage, PDO::PARAM_INT);
    $statement->bindValue(':resource_offset', $resourceOffset, PDO::PARAM_INT);
    $statement->execute();
    $resourceRows = $statement->fetchAll(PDO::FETCH_ASSOC);
}

$resourceStatusCounts = [];
$statement = $db->query('SELECT COUNT(*) FROM ' . novaResourceIdentifier($resourceTable));
$resourceGrandTotal = (int)$statement->fetchColumn();
if ($resourceStatusField !== '') {
    novaResourceIdentifier($resourceStatusField);
    $statement = $db->query(
        'SELECT ' . novaResourceIdentifier($resourceStatusField) . ' AS resource_status, COUNT(*) AS resource_count' .
        ' FROM ' . novaResourceIdentifier($resourceTable) .
        ' GROUP BY ' . novaResourceIdentifier($resourceStatusField)
    );
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $statusRow) {
        $resourceStatusCounts[(string)$statusRow['resource_status']] = (int)$statusRow['resource_count'];
    }
}

$contentCssPath = __DIR__ . '/../../assets/css/admin-content.css';
$contentCssVersion = is_file($contentCssPath) ? (string)filemtime($contentCssPath) : '1';
$head_scripts = ($head_scripts ?? '') . '<link href="/assets/css/admin-content.css?v=' . e($contentCssVersion) . '" rel="stylesheet">';
$page_title = $resourceIsForm
    ? (($resourceEditing && !empty($resourceEditing[$resourcePrimaryKey])) ? '编辑' : '新建') . $resourceSingular
    : $resourceTitle;

require __DIR__ . '/header.php';
?>

<section class="content-resource-shell">
    <div class="content-page-heading">
        <div class="content-page-heading-copy">
            <span class="content-page-icon"><i class="bi <?= e($resourceIcon) ?>" aria-hidden="true"></i></span>
            <div>
                <div class="content-page-eyebrow"><?= e($resourceConfig['eyebrow'] ?? '内容工作台') ?></div>
                <h1><?= e($page_title) ?></h1>
                <?php if ($resourceDescription !== ''): ?>
                <p><?= e($resourceDescription) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="content-page-actions">
            <?php if ($resourceIsForm): ?>
            <a class="btn btn-outline-secondary" href="<?= e($resourcePage) ?>">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> 返回列表
            </a>
            <?php else: ?>
            <a class="btn btn-primary" href="<?= e($resourcePage) ?>?action=add">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> 新建<?= e($resourceSingular) ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($resourceFlash): ?>
    <div class="alert alert-<?= e($resourceFlash['type']) ?> alert-dismissible fade show content-resource-alert" role="alert">
        <i class="bi <?= $resourceFlash['type'] === 'success' ? 'bi-check-circle' : 'bi-exclamation-circle' ?>" aria-hidden="true"></i>
        <?= e($resourceFlash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
    </div>
    <?php endif; ?>

    <?php if (!$resourceIsForm): ?>
    <div class="content-stat-grid">
        <article class="content-stat-card">
            <span><i class="bi bi-collection" aria-hidden="true"></i></span>
            <div><strong><?= number_format($resourceGrandTotal) ?></strong><small>全部<?= e($resourceSingular) ?></small></div>
        </article>
        <?php foreach (array_slice($resourceConfig['statuses'] ?? [], 0, 3, true) as $statusKey => $statusConfig):
            $statusMeta = novaResourceStatusMeta($resourceConfig, $statusKey);
        ?>
        <article class="content-stat-card tone-<?= e($statusMeta['tone']) ?>">
            <span><i class="bi <?= e($statusConfig['icon'] ?? 'bi-circle') ?>" aria-hidden="true"></i></span>
            <div><strong><?= number_format($resourceStatusCounts[$statusKey] ?? 0) ?></strong><small><?= e($statusMeta['label']) ?></small></div>
        </article>
        <?php endforeach; ?>
    </div>

    <div class="content-panel">
        <form class="content-toolbar" method="get" action="<?= e($resourcePage) ?>">
            <label class="content-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" value="<?= e($resourceQuery) ?>" placeholder="<?= e($resourceConfig['search_placeholder'] ?? ('搜索' . $resourceSingular . '…')) ?>">
            </label>
            <?php if ($resourceStatusField !== ''): ?>
            <label class="content-filter">
                <span class="visually-hidden">状态筛选</span>
                <select name="status">
                    <option value="">全部状态</option>
                    <?php foreach (($resourceConfig['statuses'] ?? []) as $statusKey => $statusConfig):
                        $statusMeta = novaResourceStatusMeta($resourceConfig, $statusKey);
                    ?>
                    <option value="<?= e($statusKey) ?>" <?= $resourceStatusFilter === (string)$statusKey ? 'selected' : '' ?>>
                        <?= e($statusMeta['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <button class="btn btn-outline-secondary" type="submit">筛选</button>
            <?php if ($resourceQuery !== '' || $resourceStatusFilter !== ''): ?>
            <a class="btn btn-link" href="<?= e($resourcePage) ?>">清除</a>
            <?php endif; ?>
            <span class="content-toolbar-count">找到 <?= number_format($resourceTotal) ?> 条</span>
        </form>

        <?php if (!$resourceRows): ?>
        <div class="content-empty-state">
            <span><i class="bi <?= e($resourceIcon) ?>" aria-hidden="true"></i></span>
            <h2><?= $resourceQuery !== '' || $resourceStatusFilter !== '' ? '没有匹配的结果' : '还没有' . e($resourceSingular) ?></h2>
            <p><?= $resourceQuery !== '' || $resourceStatusFilter !== '' ? '换个关键词或筛选条件再试一次。' : '从第一条内容开始，建立你的内容库。' ?></p>
            <a class="btn btn-primary" href="<?= e($resourcePage) ?>?action=add">新建<?= e($resourceSingular) ?></a>
        </div>
        <?php else: ?>
        <div class="table-responsive content-resource-table-wrap">
            <table class="table content-resource-table align-middle">
                <thead>
                    <tr>
                        <?php foreach ($resourceListColumns as $column): ?>
                        <th scope="col"><?= e($column['label'] ?? $column['key']) ?></th>
                        <?php endforeach; ?>
                        <th scope="col" class="text-end">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resourceRows as $row): ?>
                    <tr>
                        <?php foreach ($resourceListColumns as $column):
                            $columnKey = (string)($column['key'] ?? '');
                            $columnType = (string)($column['type'] ?? 'text');
                            $columnValue = $row[$columnKey] ?? '';
                        ?>
                        <td class="<?= $columnType === 'primary' ? 'content-primary-cell' : '' ?>">
                            <?php if ($columnType === 'primary'): ?>
                            <strong><?= e(novaResourceExcerpt($columnValue, 72)) ?></strong>
                            <?php if (!empty($column['subtitle']) && !empty($row[$column['subtitle']])): ?>
                            <small><?= e(novaResourceExcerpt($row[$column['subtitle']], 72)) ?></small>
                            <?php endif; ?>
                            <?php elseif ($columnType === 'status'):
                                $columnStatus = novaResourceStatusMeta($resourceConfig, (string)$columnValue);
                            ?>
                            <span class="content-status content-status-<?= e($columnStatus['tone']) ?>">
                                <i aria-hidden="true"></i><?= e($columnStatus['label']) ?>
                            </span>
                            <?php elseif ($columnType === 'boolean'): ?>
                            <span class="content-boolean <?= $columnValue ? 'is-yes' : '' ?>">
                                <i class="bi <?= $columnValue ? 'bi-check2' : 'bi-dash' ?>" aria-hidden="true"></i>
                                <?= $columnValue ? e($column['yes'] ?? '是') : e($column['no'] ?? '否') ?>
                            </span>
                            <?php elseif ($columnType === 'email'): ?>
                            <a href="mailto:<?= e($columnValue) ?>"><?= e($columnValue) ?></a>
                            <?php elseif ($columnType === 'url'): ?>
                            <?php if ($columnValue): ?>
                            <a href="<?= e($columnValue) ?>" target="_blank" rel="noopener">打开链接 <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            <?php elseif ($columnType === 'number'): ?>
                            <?= number_format((int)$columnValue) ?>
                            <?php elseif ($columnType === 'datetime'): ?>
                            <time datetime="<?= e($columnValue) ?>"><?= e($columnValue ?: '—') ?></time>
                            <?php else: ?>
                            <?= e(novaResourceExcerpt($columnValue, (int)($column['length'] ?? 54)) ?: '—') ?>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="text-end">
                            <div class="content-row-actions">
                                <?php
                                $publicUrl = novaResourcePublicUrl($resourceConfig['public_url'] ?? '', $row);
                                $canPreview = $publicUrl !== '' && ($resourceStatusField === '' || ($row[$resourceStatusField] ?? '') === ($resourceConfig['public_status'] ?? 'published'));
                                ?>
                                <?php if ($canPreview): ?>
                                <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener" title="预览">
                                    <i class="bi bi-eye" aria-hidden="true"></i><span class="visually-hidden">预览</span>
                                </a>
                                <?php endif; ?>
                                <a href="<?= e($resourcePage) ?>?action=edit&amp;<?= e($resourcePrimaryKey) ?>=<?= (int)$row[$resourcePrimaryKey] ?>" title="编辑">
                                    <i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">编辑</span>
                                </a>
                                <?php
                                $toggleMap = $resourceConfig['toggle_map'] ?? [];
                                $currentStatus = $resourceStatusField !== '' ? (string)($row[$resourceStatusField] ?? '') : '';
                                $nextStatus = $toggleMap[$currentStatus] ?? '';
                                ?>
                                <?php if ($nextStatus !== ''):
                                    $nextStatusMeta = novaResourceStatusMeta($resourceConfig, $nextStatus);
                                ?>
                                <form method="post" action="<?= e($resourcePage) ?>" title="切换为<?= e($nextStatusMeta['label']) ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="<?= e($resourcePrimaryKey) ?>" value="<?= (int)$row[$resourcePrimaryKey] ?>">
                                    <input type="hidden" name="next_status" value="<?= e($nextStatus) ?>">
                                    <button type="submit"><i class="bi bi-arrow-repeat" aria-hidden="true"></i><span class="visually-hidden">切换状态</span></button>
                                </form>
                                <?php endif; ?>
                                <form method="post" action="<?= e($resourcePage) ?>" onsubmit="return confirm('确定删除这条<?= e($resourceSingular) ?>吗？此操作不可撤回。')" title="删除">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="<?= e($resourcePrimaryKey) ?>" value="<?= (int)$row[$resourcePrimaryKey] ?>">
                                    <button type="submit" class="is-danger"><i class="bi bi-trash3" aria-hidden="true"></i><span class="visually-hidden">删除</span></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($resourceTotalPages > 1): ?>
        <nav class="content-pagination" aria-label="<?= e($resourceTitle) ?>分页">
            <span>第 <?= $resourceCurrentPage ?> / <?= $resourceTotalPages ?> 页</span>
            <div>
                <?php
                $baseQuery = array_filter(['q' => $resourceQuery, 'status' => $resourceStatusFilter], function ($value) {
                    return $value !== '';
                });
                ?>
                <a class="btn btn-sm btn-outline-secondary <?= $resourceCurrentPage <= 1 ? 'disabled' : '' ?>"
                   href="<?= e($resourcePage) ?>?<?= e(http_build_query(array_merge($baseQuery, ['page' => max(1, $resourceCurrentPage - 1)]))) ?>">上一页</a>
                <a class="btn btn-sm btn-outline-secondary <?= $resourceCurrentPage >= $resourceTotalPages ? 'disabled' : '' ?>"
                   href="<?= e($resourcePage) ?>?<?= e(http_build_query(array_merge($baseQuery, ['page' => min($resourceTotalPages, $resourceCurrentPage + 1)]))) ?>">下一页</a>
            </div>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <form class="content-editor-layout" method="post" action="<?= e($resourcePage) ?>">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="<?= e($resourcePrimaryKey) ?>" value="<?= (int)($resourceEditing[$resourcePrimaryKey] ?? 0) ?>">

        <div class="content-editor-main">
            <?php foreach ($resourceFields as $fieldName => $fieldConfig):
                if (!empty($fieldConfig['hidden'])) {
                    continue;
                }
                $fieldType = (string)($fieldConfig['type'] ?? 'text');
                $fieldValue = $resourceEditing[$fieldName] ?? ($fieldConfig['default'] ?? ($fieldType === 'checkbox' ? 0 : ''));
                $fieldId = 'resource-field-' . $fieldName;
                $isSideField = !empty($fieldConfig['side']);
                if ($isSideField) {
                    continue;
                }
            ?>
            <div class="content-form-card">
                <label class="form-label" for="<?= e($fieldId) ?>">
                    <?= e($fieldConfig['label'] ?? $fieldName) ?>
                    <?php if (!empty($fieldConfig['required'])): ?><span class="required-mark">*</span><?php endif; ?>
                </label>
                <?php if ($fieldType === 'textarea' || $fieldType === 'markdown'): ?>
                <textarea class="form-control <?= $fieldType === 'markdown' ? 'content-markdown-field' : '' ?>"
                          id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>"
                          rows="<?= (int)($fieldConfig['rows'] ?? ($fieldType === 'markdown' ? 14 : 5)) ?>"
                          <?= !empty($fieldConfig['required']) ? 'required' : '' ?>
                          <?= !empty($fieldConfig['maxlength']) ? 'maxlength="' . (int)$fieldConfig['maxlength'] . '"' : '' ?>
                          placeholder="<?= e($fieldConfig['placeholder'] ?? '') ?>"><?= e($fieldValue) ?></textarea>
                <?php elseif ($fieldType === 'select'): ?>
                <select class="form-select" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>">
                    <?php foreach (($fieldConfig['options'] ?? []) as $optionValue => $optionLabel): ?>
                    <option value="<?= e($optionValue) ?>" <?= (string)$fieldValue === (string)$optionValue ? 'selected' : '' ?>><?= e($optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php elseif ($fieldType === 'checkbox'): ?>
                <label class="content-switch">
                    <input id="<?= e($fieldId) ?>" type="checkbox" name="<?= e($fieldName) ?>" value="1" <?= $fieldValue ? 'checked' : '' ?>>
                    <span aria-hidden="true"></span>
                    <strong><?= e($fieldConfig['switch_label'] ?? '启用') ?></strong>
                </label>
                <?php else: ?>
                <input class="form-control" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>"
                       type="<?= in_array($fieldType, ['email', 'url', 'number'], true) ? e($fieldType) : 'text' ?>"
                       value="<?= e($fieldValue) ?>"
                       <?= !empty($fieldConfig['required']) ? 'required' : '' ?>
                       <?= !empty($fieldConfig['maxlength']) ? 'maxlength="' . (int)$fieldConfig['maxlength'] . '"' : '' ?>
                       <?= isset($fieldConfig['min']) ? 'min="' . e($fieldConfig['min']) . '"' : '' ?>
                       <?= isset($fieldConfig['max']) ? 'max="' . e($fieldConfig['max']) . '"' : '' ?>
                       placeholder="<?= e($fieldConfig['placeholder'] ?? '') ?>">
                <?php endif; ?>
                <?php if (!empty($fieldConfig['help'])): ?><div class="form-text"><?= e($fieldConfig['help']) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <aside class="content-editor-side">
            <div class="content-editor-side-card">
                <h2>发布设置</h2>
                <?php foreach ($resourceFields as $fieldName => $fieldConfig):
                    if (empty($fieldConfig['side']) || !empty($fieldConfig['hidden'])) {
                        continue;
                    }
                    $fieldType = (string)($fieldConfig['type'] ?? 'text');
                    $fieldValue = $resourceEditing[$fieldName] ?? ($fieldConfig['default'] ?? ($fieldType === 'checkbox' ? 0 : ''));
                    $fieldId = 'resource-field-' . $fieldName;
                ?>
                <div class="content-side-field">
                    <label class="form-label" for="<?= e($fieldId) ?>"><?= e($fieldConfig['label'] ?? $fieldName) ?></label>
                    <?php if ($fieldType === 'select'): ?>
                    <select class="form-select" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>">
                        <?php foreach (($fieldConfig['options'] ?? []) as $optionValue => $optionLabel): ?>
                        <option value="<?= e($optionValue) ?>" <?= (string)$fieldValue === (string)$optionValue ? 'selected' : '' ?>><?= e($optionLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php elseif ($fieldType === 'checkbox'): ?>
                    <label class="content-switch">
                        <input id="<?= e($fieldId) ?>" type="checkbox" name="<?= e($fieldName) ?>" value="1" <?= $fieldValue ? 'checked' : '' ?>>
                        <span aria-hidden="true"></span>
                        <strong><?= e($fieldConfig['switch_label'] ?? '启用') ?></strong>
                    </label>
                    <?php else: ?>
                    <input class="form-control" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>"
                           type="<?= $fieldType === 'number' ? 'number' : 'text' ?>"
                           value="<?= e($fieldValue) ?>"
                           <?= isset($fieldConfig['min']) ? 'min="' . e($fieldConfig['min']) . '"' : '' ?>
                           <?= isset($fieldConfig['max']) ? 'max="' . e($fieldConfig['max']) . '"' : '' ?>>
                    <?php endif; ?>
                    <?php if (!empty($fieldConfig['help'])): ?><div class="form-text"><?= e($fieldConfig['help']) ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div class="content-editor-submit">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check2" aria-hidden="true"></i>
                        <?= !empty($resourceEditing[$resourcePrimaryKey]) ? '保存更改' : '创建' . e($resourceSingular) ?>
                    </button>
                    <a class="btn btn-outline-secondary" href="<?= e($resourcePage) ?>">取消</a>
                </div>
            </div>
        </aside>
    </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/footer.php'; ?>
