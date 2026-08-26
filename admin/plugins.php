<?php
/**
 * 插件管理页面
 * 扫描 vendor/nova-plugins/ 目录，读取 plugin.json 元数据，按插件 id 启用/禁用
 * 启用/禁用通过 AJAX（fetch）实现，无需页面刷新
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';

require_once __DIR__ . '/../vendor/nova-json/class/plugin/class-plugin-registry.php';

// 确保 active_plugins 字段存在
$checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'active_plugins'");
if (!$checkStmt->fetch()) {
    $db->exec("ALTER TABLE website_config ADD COLUMN active_plugins TEXT NULL COMMENT '已启用的插件 id(JSON 数组)，NULL 表示全部启用' AFTER active_theme");
}

// 确保 plugin_dirs 字段存在（存储插件 id → 目录名的映射）
$checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'plugin_dirs'");
if (!$checkStmt->fetch()) {
    $db->exec("ALTER TABLE website_config ADD COLUMN plugin_dirs TEXT NULL COMMENT '插件目录映射(JSON 对象：id→目录名)' AFTER active_plugins");
}

/**
 * 获取已启用的插件 id 列表
 * @return array|null 返回数组；若返回 null 表示未配置（全部启用）
 */
function get_active_plugins($db) {
    $stmt = $db->query("SELECT active_plugins FROM website_config LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (empty($row['active_plugins'])) {
        return null;
    }
    $decoded = json_decode($row['active_plugins'], true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * 保存已启用的插件 id 列表
 */
function save_active_plugins($db, array $ids) {
    $ids = array_values(array_unique($ids));
    $json = json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("UPDATE website_config SET active_plugins = ? WHERE id = 1");
    $stmt->execute([$json]);
}

// 扫描所有已安装插件
$plugins = Nova_Plugin_Registry::scan_all();

// 检测重复 id 的插件，删除重复的插件目录（保留第一个）
$deletedDirs = [];
$cleanedPlugins = [];
foreach ($plugins as $p) {
    if (!empty($p['duplicate'])) {
        // 重复插件：删除其目录
        $dir = $p['plugin_dir'];
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                @unlink($file->getRealPath()) ?: @rmdir($file->getRealPath());
            }
            @rmdir($dir);
        }
        $deletedDirs[] = $p['slug'];
        continue; // 不加入清理后的列表
    }
    $cleanedPlugins[] = $p;
}
if (!empty($deletedDirs)) {
    $plugins = $cleanedPlugins;
    Nova_Plugin_Registry::clear_cache();
}

// 构建 plugin_dirs 映射（id → 目录名）并保存到数据库
$pluginDirs = [];
foreach ($plugins as $p) {
    $pluginDirs[$p['id']] = $p['slug'];
}
$dirsJson = json_encode($pluginDirs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$stmt = $db->prepare("UPDATE website_config SET plugin_dirs = ? WHERE id = 1");
$stmt->execute([$dirsJson]);

$activePluginIds = get_active_plugins($db);

// AJAX 请求检测（提前定义，供下方各端点使用）
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || ($_SERVER['HTTP_X_PJAX'] ?? '') === 'true';

// AJAX 请求时抑制非致命错误输出，防止 HTML notice 混入 JSON 响应
if ($isAjax) {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// AJAX 端点：卸载插件（删除目录）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax && ($_POST['action'] ?? '') === 'uninstall_plugin') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => '安全验证失败，请刷新页面后重试']);
        exit;
    }

    $uninstallId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['plugin_id'] ?? '');
    if ($uninstallId === '') {
        echo json_encode(['ok' => false, 'msg' => '插件标识无效']);
        exit;
    }

    $uninstallTarget = null;
    foreach ($plugins as $p) {
        if ($p['id'] === $uninstallId) {
            $uninstallTarget = $p;
            break;
        }
    }
    if ($uninstallTarget === null) {
        echo json_encode(['ok' => false, 'msg' => '插件不存在']);
        exit;
    }

    // 先从启用列表移除
    if ($activePluginIds === null) {
        $activePluginIds = [];
        foreach ($plugins as $p) {
            $activePluginIds[] = $p['id'];
        }
    }
    $activePluginIds = array_values(array_diff($activePluginIds, [$uninstallId]));
    save_active_plugins($db, $activePluginIds);

    // 递归删除插件目录（使用真正的递归迭代器，支持任意深度嵌套）
    $pluginDir = $uninstallTarget['plugin_dir'];
    $deleted = false;
    $deleteErrors = [];
    if (is_dir($pluginDir)) {
        // 先关闭可能存在的目录句柄，避免 Windows 下占用
        @closedir(@opendir($pluginDir));
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $realPath = $file->getRealPath();
            if ($file->isDir()) {
                if (!@rmdir($realPath)) {
                    $deleteErrors[] = '目录: ' . $realPath
                        . ' (失败原因: ' . error_get_last()['message'] . ')';
                }
            } else {
                if (!@unlink($realPath)) {
                    $deleteErrors[] = '文件: ' . $realPath
                        . ' (失败原因: ' . error_get_last()['message'] . ')';
                }
            }
        }
        // 最后删除插件根目录
        if (empty($deleteErrors)) {
            $deleted = @rmdir($pluginDir);
            if (!$deleted) {
                $deleteErrors[] = '根目录: ' . $pluginDir
                    . ' (失败原因: ' . error_get_last()['message'] . ')';
            }
        }
    }

    if ($deleted) {
        Nova_Plugin_Registry::clear_cache();
        echo json_encode([
            'ok' => true,
            'msg' => '插件「' . $uninstallTarget['name'] . '」已卸载',
            'enabled_count' => count($activePluginIds),
            'disabled_count' => max(0, count($plugins) - 1 - count($activePluginIds)),
        ]);
    } else {
        // 构造详细错误信息
        $phpUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? posix_getpwuid(posix_geteuid())['name'] ?? '未知'
            : (getenv('APACHE_RUN_USER') ?: getenv('USER') ?: '未知');
        $errMsg = '删除目录失败';
        if (!empty($deleteErrors)) {
            // 只显示前 5 个错误，避免响应过长
            $errMsg .= '，以下项无法删除：' . implode('；', array_slice($deleteErrors, 0, 5));
            if (count($deleteErrors) > 5) {
                $errMsg .= '；等共 ' . count($deleteErrors) . ' 项';
            }
        }
        // Linux 权限提示
        if (DIRECTORY_SEPARATOR === '/') {
            $errMsg .= '。【排查建议】当前 PHP 运行用户: ' . $phpUser
                . '，请确保 vendor/nova-plugins/ 目录及子目录的属主为该用户'
                . '（chown -R ' . $phpUser . ':' . $phpUser . ' vendor/nova-plugins/）'
                . '，或赋予该用户 rwx 权限（chmod -R u+w vendor/nova-plugins/）。'
                . ' 注意：755 仅属主可写，若 PHP 用户不是属主依然删不了。';
        }
        echo json_encode(['ok' => false, 'msg' => $errMsg]);
    }
    exit;
}

// 手动执行定时任务（cron-manager 插件详情页"定时任务"标签内 AJAX 调用）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax && ($_POST['action'] ?? '') === 'run_cron_task') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => '安全验证失败，请刷新页面后重试']);
        exit;
    }

    $cronTaskId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['task_id'] ?? '');
    if ($cronTaskId === '') {
        echo json_encode(['ok' => false, 'msg' => '任务 ID 无效']);
        exit;
    }

    // 加载定时任务依赖类（admin 上下文默认未加载）
    $novaClassDir = dirname(__DIR__) . '/vendor/nova-json/class';
    require_once $novaClassDir . '/system/class-hooks.php';
    require_once $novaClassDir . '/system/class-cron.php';
    require_once $novaClassDir . '/database/class-db.php';
    require_once $novaClassDir . '/rest/class-server.php';
    require_once $novaClassDir . '/plugin/class-plugin.php';

    // 加载所有已启用插件入口并触发 nova_init，让插件注册 cron 任务
    try {
        foreach ($plugins as $pi) {
            if (!empty($pi['duplicate'])) continue;
            if ($activePluginIds !== null && !in_array($pi['id'], $activePluginIds, true)) continue;
            if (!empty($pi['entry_path']) && is_file($pi['entry_path'])) {
                require_once $pi['entry_path'];
            }
        }
        Nova_Hooks::do_action('nova_init');
    } catch (Throwable $e) {
        error_log('[Cron run] plugin bootstrap failed: ' . $e->getMessage());
    }

    $res = Nova_Cron::run_one($cronTaskId, true);
    $status = $res['status'] ?? 'unknown';
    if ($status === 'success') {
        echo json_encode(['ok' => true, 'msg' => "任务 {$cronTaskId} 执行成功"]);
    } elseif ($status === 'failed') {
        echo json_encode(['ok' => false, 'msg' => "任务 {$cronTaskId} 执行失败：" . ($res['error'] ?? '未知错误')]);
    } else {
        echo json_encode(['ok' => false, 'msg' => "任务 {$cronTaskId} 跳过（" . ($res['reason'] ?? '未知') . "）"]);
    }
    exit;
}

// AJAX 端点：处理启用/禁用请求，返回 JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax && ($_POST['action'] ?? '') !== 'save_plugin_config') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => '安全验证失败，请刷新页面后重试']);
        exit;
    }

    $pluginId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['plugin_id'] ?? '');
    $action = $_POST['plugin_action'] ?? '';

    if ($pluginId === '') {
        echo json_encode(['ok' => false, 'msg' => '插件标识无效']);
        exit;
    }

    $targetPlugin = null;
    foreach ($plugins as $p) {
        if ($p['id'] === $pluginId) {
            $targetPlugin = $p;
            break;
        }
    }

    if ($targetPlugin === null) {
        echo json_encode(['ok' => false, 'msg' => '插件不存在']);
        exit;
    }

    // 首次操作：把当前所有已存在的插件 id 加入启用列表
    if ($activePluginIds === null) {
        $activePluginIds = [];
        foreach ($plugins as $p) {
            $activePluginIds[] = $p['id'];
        }
    }

    if ($action === 'activate') {
        if (!in_array($pluginId, $activePluginIds, true)) {
            $activePluginIds[] = $pluginId;
        }
        save_active_plugins($db, $activePluginIds);
        Nova_Plugin_Registry::clear_cache();
        echo json_encode([
            'ok' => true,
            'active' => true,
            'msg' => '插件「' . $targetPlugin['name'] . '」已启用',
            'enabled_count' => count($activePluginIds),
            'disabled_count' => max(0, count($plugins) - count($activePluginIds)),
        ]);
    } elseif ($action === 'deactivate') {
        $activePluginIds = array_values(array_diff($activePluginIds, [$pluginId]));
        save_active_plugins($db, $activePluginIds);
        Nova_Plugin_Registry::clear_cache();
        echo json_encode([
            'ok' => true,
            'active' => false,
            'msg' => '插件「' . $targetPlugin['name'] . '」已禁用',
            'enabled_count' => count($activePluginIds),
            'disabled_count' => max(0, count($plugins) - count($activePluginIds)),
        ]);
    } else {
        echo json_encode(['ok' => false, 'msg' => '未知操作']);
    }
    exit;
}

// AJAX：保存插件配置（config.json）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax && ($_POST['action'] ?? '') === 'save_plugin_config') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => '安全验证失败，请刷新页面后重试']);
        exit;
    }

    $cfgPluginKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['plugin'] ?? '');
    $cfgTarget = null;
    foreach ($plugins as $p) {
        if ($p['id'] === $cfgPluginKey || $p['slug'] === $cfgPluginKey) {
            $cfgTarget = $p;
            break;
        }
    }
    if ($cfgTarget === null) {
        echo json_encode(['ok' => false, 'msg' => '插件不存在']);
        exit;
    }
    $cfgIsActive = $activePluginIds === null ? true : in_array($cfgTarget['id'], $activePluginIds, true);
    if (!$cfgIsActive) {
        echo json_encode(['ok' => false, 'msg' => '插件已禁用，无法保存配置']);
        exit;
    }

    $cfgFile = Nova_Plugin_Registry::resolve_config_file($cfgTarget);
    $cfgRaw = is_file($cfgFile) ? file_get_contents($cfgFile) : '{"tabs":[]}';
    $cfgData = json_decode($cfgRaw, true);
    if (!is_array($cfgData)) {
        $cfgData = ['tabs' => []];
    }

    $savedValues = json_decode($_POST['values'] ?? '{}', true);

    // 数据库存储模式：写入 website_config 表
    if (!empty($cfgData['storage']) && $cfgData['storage'] === 'database') {
        $setClauses = [];
        $params = [];
        foreach ($cfgData['tabs'] as $tab) {
            if (empty($tab['fields'])) continue;
            foreach ($tab['fields'] as $field) {
                $fName = $field['name'] ?? '';
                if ($fName === '') continue;
                if (isset($savedValues[$fName])) {
                    $val = $savedValues[$fName];
                    if (($field['type'] ?? '') === 'switch') {
                        $val = ($val === '1' || $val === true || $val === 'on') ? 1 : 0;
                    } elseif (($field['type'] ?? '') === 'number') {
                        $val = is_numeric($val) ? $val + 0 : $val;
                    }
                    $setClauses[] = "`$fName` = ?";
                    $params[] = $val;
                } elseif (($field['type'] ?? '') === 'switch') {
                    $setClauses[] = "`$fName` = ?";
                    $params[] = 0;
                }
            }
        }
        if (!empty($setClauses)) {
            try {
                $db = getDB();
                $sql = "UPDATE website_config SET " . implode(', ', $setClauses) . " WHERE id = 1";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                echo json_encode(['ok' => true, 'msg' => '配置已保存']);
            } catch (Exception $e) {
                error_log('Plugin database config save error: ' . $e->getMessage());
                echo json_encode(['ok' => false, 'msg' => '保存失败：' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => true, 'msg' => '配置未变更']);
        }
        exit;
    }

    // 文件存储模式（默认）：写入 config.json
    if (is_array($savedValues) && !empty($cfgData['tabs'])) {
        foreach ($cfgData['tabs'] as &$tab) {
            if (empty($tab['fields'])) continue;
            foreach ($tab['fields'] as &$field) {
                $fName = $field['name'] ?? '';
                if ($fName === '') continue;
                if (isset($savedValues[$fName])) {
                    $val = $savedValues[$fName];
                    if ($field['type'] === 'switch') {
                        $field['value'] = ($val === '1' || $val === true || $val === 'on');
                    } elseif ($field['type'] === 'number') {
                        $field['value'] = is_numeric($val) ? $val + 0 : $val;
                    } else {
                        $field['value'] = $val;
                    }
                } elseif ($field['type'] === 'switch') {
                    $field['value'] = false;
                }
            }
        }
    }

    $writeResult = file_put_contents(
        $cfgFile,
        json_encode($cfgData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    if ($writeResult === false) {
        echo json_encode(['ok' => false, 'msg' => '写入 config.json 失败，请检查目录权限']);
    } else {
        Nova_Plugin_Registry::clear_cache();
        echo json_encode(['ok' => true, 'msg' => '配置已保存']);
    }
    exit;
}

// 非 AJAX 的 POST 回退（禁用 JS 时仍可用）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plugin_action']) && !$isAjax) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = '安全验证失败，请刷新页面后重试';
    } else {
        $pluginId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['plugin_id'] ?? '');
        $action = $_POST['plugin_action'];

        if ($pluginId !== '') {
            $targetPlugin = null;
            foreach ($plugins as $p) {
                if ($p['id'] === $pluginId) {
                    $targetPlugin = $p;
                    break;
                }
            }
            if ($targetPlugin !== null) {
                if ($activePluginIds === null) {
                    $activePluginIds = [];
                    foreach ($plugins as $p) {
                        $activePluginIds[] = $p['id'];
                    }
                }
                if ($action === 'activate') {
                    if (!in_array($pluginId, $activePluginIds, true)) {
                        $activePluginIds[] = $pluginId;
                    }
                    $message = '插件「' . $targetPlugin['name'] . '」已启用，相关功能已生效';
                } elseif ($action === 'deactivate') {
                    $activePluginIds = array_values(array_diff($activePluginIds, [$pluginId]));
                    $message = '插件「' . $targetPlugin['name'] . '」已禁用，相关 API 路由将不再注册';
                }
                if (!isset($error)) {
                    save_active_plugins($db, $activePluginIds);
                    Nova_Plugin_Registry::clear_cache();
                }
            }
        }
    }
}

$message = $message ?? '';
$error = $error ?? '';

// 校验插件 id：必须为英文格式，且不能重复
$idWarnings = [];
$seenIds = [];
foreach ($plugins as $p) {
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $p['id'])) {
        $idWarnings[] = "插件「{$p['name']}」的 id「{$p['id']}」格式无效，id 必须为英文（字母开头，仅含字母、数字、下划线、连字符）";
    }
    if (in_array($p['id'], $seenIds, true)) {
        $idWarnings[] = "插件 id「{$p['id']}」重复（插件「{$p['name']}」与其他插件使用了相同的 id）";
    } else {
        $seenIds[] = $p['id'];
    }
}

// 为每个插件附加 active 状态
foreach ($plugins as &$p) {
    $p['active'] = $activePluginIds === null ? true : in_array($p['id'], $activePluginIds, true);
}
unset($p);

// 排序：已启用的在前
usort($plugins, function ($a, $b) {
    if ($a['active'] === $b['active']) {
        return strcmp($a['name'], $b['name']);
    }
    return $a['active'] ? -1 : 1;
});

$enabledCount = count(array_filter($plugins, fn($p) => $p['active']));
$disabledCount = max(0, count($plugins) - $enabledCount);
$totalCount = count($plugins);

// =============================================
// 插件详情视图（?plugin=xxx 时显示）
// =============================================
$detailPluginKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['plugin'] ?? '');
if ($detailPluginKey !== '') {
    $detailPlugin = null;
    foreach ($plugins as $p) {
        if ($p['id'] === $detailPluginKey || $p['slug'] === $detailPluginKey) {
            $detailPlugin = $p;
            break;
        }
    }
    if ($detailPlugin !== null) {
        $detailPluginId = $detailPlugin['id'];
        $detailIsActive = $detailPlugin['active'];
        $detailConfigFile = Nova_Plugin_Registry::resolve_config_file($detailPlugin);
        $detailConfigSchema = [];
        if (is_file($detailConfigFile)) {
            $decoded = json_decode(file_get_contents($detailConfigFile), true);
            if (is_array($decoded) && !empty($decoded['tabs'])) {
                $detailConfigSchema = $decoded;
            }
        }
        // 数据库存储模式：从 website_config 表读取当前值覆盖 schema 中的默认 value
        if (!empty($detailConfigSchema['storage']) && $detailConfigSchema['storage'] === 'database') {
            try {
                $detailDbConfig = getDB()->query("SELECT * FROM website_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if ($detailDbConfig) {
                    foreach ($detailConfigSchema['tabs'] as &$dTab) {
                        if (empty($dTab['fields'])) continue;
                        foreach ($dTab['fields'] as &$dField) {
                            $dFName = $dField['name'] ?? '';
                            if ($dFName !== '' && array_key_exists($dFName, $detailDbConfig)) {
                                if (($dField['type'] ?? '') === 'switch') {
                                    $dField['value'] = !empty($detailDbConfig[$dFName]);
                                } else {
                                    $dField['value'] = $detailDbConfig[$dFName];
                                }
                            }
                        }
                    }
                    unset($dTab, $dField);
                }
            } catch (Exception $e) {
                // 字段不存在时保持 schema 默认值，ensureCommentSchema 会负责创建
            }
        }
        $detailCsrfToken = generateCSRFToken();

        // 自定义详情页标签：插件可提供 plugin/admin/detail.php 将管理功能嵌入详情页标签
        // （替代独立 plugin-page.php 管理页）。plugin.json 的 detail_tab 字段指定标签标题。
        $customDetailFile = $detailPlugin['plugin_dir'] . '/plugin/admin/detail.php';
        $hasCustomDetail  = $detailIsActive && is_file($customDetailFile);
        $customDetailTab  = $detailPlugin['detail_tab'] ?: '管理';

        $page_title = $detailPlugin['name'] . ' - 插件详情';
        require_once 'includes/header.php';
        ?>
        <style>
        .plugin-detail-header { display:flex; align-items:center; justify-content:space-between; padding:1.25rem 1.5rem; border-bottom:1px solid var(--bs-border-color); }
        .plugin-detail-header .plugin-icon-lg { width:48px; height:48px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.5rem; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; flex-shrink:0; }
        .plugin-detail-tabs { border-bottom:2px solid var(--bs-border-color); padding:0 1.5rem; display:flex; gap:.25rem; }
        .plugin-detail-tab { padding:.75rem 1.25rem; cursor:pointer; border:none; background:none; color:var(--bs-secondary-color); font-weight:500; font-size:.9rem; border-bottom:2px solid transparent; margin-bottom:-2px; transition:all .2s; white-space:nowrap; }
        .plugin-detail-tab:hover { color:var(--bs-primary); }
        .plugin-detail-tab.active { color:var(--bs-primary); border-bottom-color:var(--bs-primary); }
        .plugin-detail-body { padding:1.5rem; }
        .plugin-info-table th { color:var(--bs-secondary-color); font-weight:500; font-size:.875rem; vertical-align:top; padding:.5rem .75rem; }
        .plugin-info-table td { color:var(--bs-body-color); font-size:.875rem; vertical-align:top; padding:.5rem .75rem; }
        .config-field-row { margin-bottom:1.25rem; }
        .config-field-row label { font-weight:500; font-size:.875rem; margin-bottom:.375rem; display:block; }
        .config-field-help { font-size:.75rem; color:var(--bs-secondary-color); margin-top:.25rem; }
        .form-switch-lg .form-check-input { width:2.5em; height:1.25em; cursor:pointer; }
        </style>

        <div class="card border-0 shadow-sm mb-4">
            <div class="plugin-detail-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="plugin-icon-lg"><i class="bi bi-puzzle-fill"></i></div>
                    <div>
                        <h4 class="mb-0"><?= e($detailPlugin['name']) ?></h4>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <span class="badge bg-<?= $detailIsActive ? 'success' : 'secondary' ?>"><?= $detailIsActive ? '运行中' : '已停用' ?></span>
                            <?php if (!empty($detailPlugin['version'])): ?>
                                <span class="text-muted small">v<?= e($detailPlugin['version']) ?></span>
                            <?php endif; ?>
                            <code class="small text-muted"><?= e($detailPluginId) ?></code>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($detailIsActive && is_file($detailPlugin['plugin_dir'] . '/plugin/admin/index.php') && !$hasCustomDetail): ?>
                        <a href="/admin/plugin-page.php?plugin=<?= rawurlencode($detailPluginId) ?>" class="btn btn-outline-primary btn-sm" data-pjax>
                            <i class="bi bi-sliders me-1"></i>管理
                        </a>
                    <?php endif; ?>
                    <a href="plugins.php" class="btn btn-outline-secondary btn-sm" data-pjax>
                        <i class="bi bi-arrow-left me-1"></i>返回
                    </a>
                </div>
            </div>

            <div class="plugin-detail-tabs">
                <button class="plugin-detail-tab active" data-tab="info" type="button">详情</button>
                <?php if ($hasCustomDetail): ?>
                    <button class="plugin-detail-tab" data-tab="custom-detail" type="button"><?= e($customDetailTab) ?></button>
                <?php endif; ?>
                <?php if (!empty($detailConfigSchema['tabs'])): ?>
                    <?php foreach ($detailConfigSchema['tabs'] as $dIdx => $dTab): ?>
                        <button class="plugin-detail-tab" data-tab="config-<?= $dIdx ?>" type="button"><?= e($dTab['title'] ?? '配置') ?></button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="plugin-detail-body">
                <!-- 详情 Tab -->
                <div class="tab-pane-content" id="tab-info">
                    <table class="table table-borderless mb-0 plugin-info-table">
                        <tbody>
                            <tr>
                                <th scope="row" style="width:160px;">ID</th>
                                <td><code><?= e($detailPluginId) ?></code></td>
                            </tr>
                            <tr>
                                <th scope="row">名称</th>
                                <td><?= e($detailPlugin['name']) ?></td>
                            </tr>
                            <?php if (!empty($detailPlugin['description'])): ?>
                            <tr>
                                <th scope="row">描述</th>
                                <td><?= e($detailPlugin['description']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($detailPlugin['version'])): ?>
                            <tr>
                                <th scope="row">版本</th>
                                <td><?= e($detailPlugin['version']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($detailPlugin['author'])): ?>
                            <tr>
                                <th scope="row">作者</th>
                                <td>
                                    <?php if (!empty($detailPlugin['author_uri'])): ?>
                                        <a href="<?= e($detailPlugin['author_uri']) ?>" target="_blank"><?= e($detailPlugin['author']) ?></a>
                                    <?php else: ?>
                                        <?= e($detailPlugin['author']) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th scope="row">目录</th>
                                <td><code><?= e($detailPlugin['slug']) ?></code></td>
                            </tr>
                            <tr>
                                <th scope="row">入口文件</th>
                                <td><code><?= e($detailPlugin['entry']) ?></code></td>
                            </tr>
                            <?php if (!empty($detailPlugin['min_nova_version'])): ?>
                            <tr>
                                <th scope="row">最低版本要求</th>
                                <td>NovaCMS <?= e($detailPlugin['min_nova_version']) ?>+</td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($detailPlugin['page_routes'])): ?>
                            <tr>
                                <th scope="row">页面路由</th>
                                <td>
                                    <?php foreach ($detailPlugin['page_routes'] as $route => $file): ?>
                                        <code class="me-2"><?= e($route) ?></code>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($detailConfigSchema['storage']) && $detailConfigSchema['storage'] === 'database'): ?>
                            <tr>
                                <th scope="row">配置存储</th>
                                <td><span class="badge bg-info">数据库</span> <code class="small">website_config</code></td>
                            </tr>
                            <?php elseif (is_file($detailConfigFile)): ?>
                            <tr>
                                <th scope="row">配置文件</th>
                                <td><code><?= e(str_replace(str_replace('\\','/',dirname(__DIR__)).'/','',str_replace('\\','/',$detailConfigFile))) ?: 'config.json' ?></code> <span class="text-muted small">（<?= filesize($detailConfigFile) ?> 字节）</span></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 配置 Tab -->
                <?php if (!empty($detailConfigSchema['tabs'])): ?>
                    <?php foreach ($detailConfigSchema['tabs'] as $tabIdx => $tab): ?>
                        <div class="tab-pane-content d-none" id="tab-config-<?= $tabIdx ?>">
                            <form class="plugin-config-form">
                                <?php if (!empty($tab['description'])): ?>
                                    <p class="text-muted small mb-3"><?= e($tab['description']) ?></p>
                                <?php endif; ?>
                                <?php foreach ($tab['fields'] ?? [] as $field):
                                    $fName = $field['name'] ?? ''; $fType = $field['type'] ?? 'text';
                                    $fLabel = $field['label'] ?? $fName; $fValue = $field['value'] ?? '';
                                    $fHelp = $field['help'] ?? ''; $fPlaceholder = $field['placeholder'] ?? '';
                                ?>
                                    <div class="config-field-row">
                                        <label><?= e($fLabel) ?></label>
                                        <?php if ($fType === 'text'): ?>
                                            <input type="text" class="form-control" name="<?= e($fName) ?>" value="<?= e($fValue) ?>" placeholder="<?= e($fPlaceholder) ?>">
                                        <?php elseif ($fType === 'number'): ?>
                                            <input type="number" class="form-control" name="<?= e($fName) ?>" value="<?= e($fValue) ?>" placeholder="<?= e($fPlaceholder) ?>"
                                                <?= isset($field['min']) ? 'min="' . e($field['min']) . '"' : '' ?>
                                                <?= isset($field['max']) ? 'max="' . e($field['max']) . '"' : '' ?>
                                                <?= isset($field['step']) ? 'step="' . e($field['step']) . '"' : '' ?>>
                                        <?php elseif ($fType === 'textarea'): ?>
                                            <textarea class="form-control" name="<?= e($fName) ?>" rows="<?= $field['rows'] ?? 4 ?>" placeholder="<?= e($fPlaceholder) ?>"><?= e($fValue) ?></textarea>
                                        <?php elseif ($fType === 'switch'): ?>
                                            <div class="form-check form-switch form-switch-lg">
                                                <input class="form-check-input" type="checkbox" name="<?= e($fName) ?>" value="1" <?= $fValue ? 'checked' : '' ?>>
                                            </div>
                                        <?php elseif ($fType === 'select'): $options = $field['options'] ?? []; ?>
                                            <select class="form-select" name="<?= e($fName) ?>">
                                                <?php foreach ($options as $optVal => $optLabel): ?>
                                                    <option value="<?= e($optVal) ?>" <?= (string)$fValue === (string)$optVal ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                        <?php if ($fHelp): ?>
                                            <div class="config-field-help"><?= e($fHelp) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($detailIsActive): ?>
                                    <button type="button" class="btn btn-primary btn-save-config">
                                        <i class="bi bi-check-lg me-1"></i>保存配置
                                    </button>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0">
                                        <i class="bi bi-exclamation-triangle me-1"></i>插件已禁用，请先启用后再修改配置。
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- 自定义详情页 Tab（由 plugin/admin/detail.php 提供） -->
                <?php if ($hasCustomDetail): ?>
                    <div class="tab-pane-content d-none" id="tab-custom-detail">
                        <?php require $customDetailFile; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script type="text/pjax-script">
        (function() {
            document.querySelectorAll('.plugin-detail-tab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.plugin-detail-tab').forEach(function(t) { t.classList.remove('active'); });
                    document.querySelectorAll('.tab-pane-content').forEach(function(p) { p.classList.add('d-none'); });
                    tab.classList.add('active');
                    var target = document.getElementById('tab-' + tab.dataset.tab);
                    if (target) target.classList.remove('d-none');
                });
            });
            document.querySelectorAll('.btn-save-config').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var form = btn.closest('.plugin-config-form');
                    var values = {};
                    form.querySelectorAll('input[type="text"], input[type="number"], textarea, select').forEach(function(input) { values[input.name] = input.value; });
                    form.querySelectorAll('input[type="checkbox"]').forEach(function(input) { values[input.name] = input.checked ? '1' : '0'; });
                    var formData = new FormData();
                    formData.append('action', 'save_plugin_config');
                    formData.append('csrf_token', '<?= $detailCsrfToken ?>');
                    formData.append('plugin', '<?= e($detailPluginKey) ?>');
                    formData.append('values', JSON.stringify(values));
                    btn.disabled = true;
                    var orig = btn.innerHTML;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>保存中…';
                    fetch('plugins.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:formData })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        var t = document.createElement('div');
                        t.className = 'position-fixed top-0 start-50 translate-middle-x mt-3 p-2 rounded shadow-lg';
                        t.style.cssText = 'z-index:9999;background:' + (data.ok ? '#198754' : '#dc3545') + ';color:#fff;font-size:.875rem;';
                        t.textContent = data.msg || (data.ok ? '保存成功' : '保存失败');
                        document.body.appendChild(t);
                        setTimeout(function(){ t.remove(); }, 2500);
                    })
                    .catch(function(){ alert('网络错误，请重试'); })
                    .finally(function(){ btn.disabled = false; btn.innerHTML = orig; });
                });
            });
        })();
        </script>

        <?php
        require_once 'includes/footer.php';
        exit;
    }
}

$page_title = '插件管理';
require_once 'includes/header.php';
?>

<style>
.plugin-page {
    max-width: 1500px;
    margin: 0 auto;
}

.plugin-page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin: 28px 0 22px;
}

.plugin-page-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.plugin-page-title-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(13, 110, 253, .1);
    color: var(--bs-primary);
    font-size: 22px;
}

.plugin-page-header h1 {
    font-size: 1.6rem;
    font-weight: 650;
    margin: 0;
}

.plugin-page-header p {
    margin: 4px 0 0;
    color: var(--bs-secondary-color);
    font-size: .9rem;
}


/* 顶部状态 */
.plugin-summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 18px;
}

.plugin-summary-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 11px;
    border-radius: 999px;
    background: var(--bs-tertiary-bg);
    border: 1px solid var(--bs-border-color);
    font-size: .82rem;
}

.plugin-summary-item strong {
    font-weight: 650;
}


/* 工具栏 */
.plugin-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 20px;
}

.plugin-search {
    position: relative;
    flex: 1;
    max-width: 420px;
}

.plugin-search i {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--bs-secondary-color);
    pointer-events: none;
}

.plugin-search input {
    padding-left: 38px;
    border-radius: 10px;
}

.plugin-filters {
    display: flex;
    gap: 6px;
    padding: 4px;
    border-radius: 10px;
    background: var(--bs-tertiary-bg);
}

.plugin-filter-btn {
    border: 0;
    background: transparent;
    color: var(--bs-secondary-color);
    padding: 6px 12px;
    border-radius: 7px;
    font-size: .85rem;
    white-space: nowrap;
}

.plugin-filter-btn:hover {
    color: var(--bs-body-color);
}

.plugin-filter-btn.active {
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
}


/* 插件表格 */
.plugin-table-card {
    border: 1px solid var(--bs-border-color);
    border-radius: 14px;
    overflow: hidden;
}

.plugin-table {
    margin-bottom: 0;
    font-size: .9rem;
}

.plugin-table thead th {
    background: var(--bs-tertiary-bg);
    border-bottom: 1px solid var(--bs-border-color);
    font-size: .78rem;
    font-weight: 600;
    color: var(--bs-secondary-color);
    letter-spacing: .03em;
    white-space: nowrap;
}

.plugin-table tbody td {
    vertical-align: middle;
}

.plugin-row {
    transition: background-color .15s ease;
}

.plugin-row-inactive {
    opacity: .78;
}

.plugin-icon {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    font-size: 18px;
    color: var(--bs-primary);
    background: rgba(13, 110, 253, .1);
}

.plugin-row:not(.plugin-row-inactive) .plugin-icon {
    color: var(--bs-success);
    background: rgba(25, 135, 84, .1);
}

.plugin-row-inactive .plugin-icon {
    color: var(--bs-secondary-color);
    background: var(--bs-tertiary-bg);
}

.plugin-name {
    margin: 0;
    font-weight: 650;
    color: var(--bs-body-color);
    transition: color .2s;
}
.plugin-name:hover {
    color: var(--bs-primary);
}

.plugin-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: .72rem;
    padding: 2px 8px;
    border-radius: 999px;
    white-space: nowrap;
}

.plugin-status::before {
    content: "";
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}

.plugin-status.active {
    color: var(--bs-success);
    background: rgba(25, 135, 84, .1);
}

.plugin-status.inactive {
    color: var(--bs-secondary-color);
    background: var(--bs-tertiary-bg);
}

.plugin-desc {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    max-width: 320px;
}

.plugin-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    white-space: nowrap;
}

.plugin-actions form {
    margin: 0;
}

/* 滑动开关 */
.plugin-switch {
    width: 2.5em;
    height: 1.4em;
    cursor: pointer;
    transition: background-color 0.2s;
}

.plugin-switch:checked {
    background-color: var(--bs-primary);
    border-color: var(--bs-primary);
}

.plugin-switch:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.plugin-switch-wrap {
    margin: 0;
    display: inline-flex;
    align-items: center;
}

/* 下拉菜单按钮 */
.plugin-dropdown-btn {
    border: 1px solid var(--bs-border-color);
    border-radius: 8px;
    padding: 0.25rem 0.5rem;
    line-height: 1;
    color: var(--bs-secondary-color);
}

.plugin-dropdown-btn:hover {
    background-color: var(--bs-tertiary-bg);
    color: var(--bs-body-color);
}

.plugin-dropdown-menu {
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border: 1px solid var(--bs-border-color);
    min-width: 160px;
}

.plugin-dropdown-menu .dropdown-item {
    border-radius: 6px;
    margin: 2px 4px;
    font-size: 0.875rem;
    padding: 0.4rem 0.6rem;
}

.plugin-dropdown-menu .dropdown-item:hover {
    background-color: var(--bs-tertiary-bg);
}


/* 空状态 */
.plugin-empty {
    padding: 70px 20px;
    text-align: center;
}

.plugin-empty-icon {
    width: 64px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    border-radius: 16px;
    background: var(--bs-tertiary-bg);
    color: var(--bs-secondary-color);
    font-size: 28px;
}

.plugin-search-empty {
    display: none;
    padding: 48px 20px;
    text-align: center;
    color: var(--bs-secondary-color);
}


/* AJAX 提示 */
#plugin-alert {
    position: fixed;
    z-index: 1090;
    top: 78px;
    right: 24px;
    width: min(380px, calc(100vw - 48px));
}


/* 手机 */
@media (max-width: 767.98px) {

    .plugin-page-header {
        flex-direction: column;
        margin-top: 20px;
    }

    .plugin-toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .plugin-search {
        max-width: none;
    }

    .plugin-filters {
        overflow-x: auto;
    }

    .plugin-filter-btn {
        flex: 1;
    }
}
</style>


<div class="container-fluid px-4 plugin-page">

    <!-- 页面标题 -->
    <div class="plugin-page-header">

        <div class="plugin-page-title">

            <div class="plugin-page-title-icon">
                <i class="bi bi-puzzle"></i>
            </div>

            <div>
                <h1>插件</h1>
                <p>管理 NovaCMS 的扩展功能</p>
            </div>

        </div>


        <a
            href="https://lygalaxy.cn"
            target="_blank"
            rel="noopener"
            class="btn btn-outline-secondary btn-sm"
        >
            <i class="bi bi-box-arrow-up-right me-1"></i>
            获取插件
        </a>

    </div>


    <div id="plugin-alert"></div>

    <?php if (!empty($deletedDirs)): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <div class="d-flex align-items-start gap-2">
                <i class="bi bi-trash-fill text-danger fs-5 mt-1"></i>
                <div>
                    <strong>已删除重复插件目录</strong>
                    <p class="mb-0 mt-1 small">
                        以下插件目录因 id 与其他插件重复，已被自动删除：
                        <strong><?= e(implode('、', $deletedDirs)) ?></strong>
                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($idWarnings)): ?>
        <div class="alert alert-warning border-0 shadow-sm">
            <div class="d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-triangle-fill text-warning fs-5 mt-1"></i>
                <div>
                    <strong>插件 id 校验警告</strong>
                    <ul class="mb-0 mt-1 small">
                        <?php foreach ($idWarnings as $w): ?>
                            <li><?= e($w) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>


    <!-- 简洁统计 -->
    <div class="plugin-summary">

        <div class="plugin-summary-item">
            <i class="bi bi-box-seam"></i>
            已安装
            <strong id="stat-total"><?= $totalCount ?></strong>
        </div>

        <div class="plugin-summary-item">
            <i class="bi bi-check-circle text-success"></i>
            已启用
            <strong id="stat-enabled"><?= $enabledCount ?></strong>
        </div>

        <div class="plugin-summary-item">
            <i class="bi bi-pause-circle"></i>
            已停用
            <strong id="stat-disabled"><?= $disabledCount ?></strong>
        </div>

    </div>


    <!-- 搜索 / 筛选 -->
    <div class="plugin-toolbar">

        <div class="plugin-search">

            <i class="bi bi-search"></i>

            <input
                id="plugin-search-input"
                type="search"
                class="form-control form-control-sm"
                placeholder="搜索插件名称、作者或目录…"
                autocomplete="off"
            >

        </div>


        <div class="plugin-filters">

            <button
                type="button"
                class="plugin-filter-btn active"
                data-plugin-filter="all"
            >
                全部
            </button>

            <button
                type="button"
                class="plugin-filter-btn"
                data-plugin-filter="active"
            >
                已启用
            </button>

            <button
                type="button"
                class="plugin-filter-btn"
                data-plugin-filter="inactive"
            >
                已停用
            </button>

        </div>

    </div>


    <?php if (empty($plugins)): ?>

        <div class="card border-0 shadow-sm">

            <div class="plugin-empty">

                <div class="plugin-empty-icon">
                    <i class="bi bi-puzzle"></i>
                </div>

                <h5>还没有安装插件</h5>

                <p class="text-muted small mb-2">
                    将插件放入
                    <code>/vendor/nova-plugins/</code>
                    后刷新此页面
                </p>

            </div>

        </div>

    <?php else: ?>


        <div class="card border-0 shadow-sm plugin-table-card">

            <div class="table-responsive">

                <table class="table plugin-table align-middle">

                    <thead>

                        <tr>
                            <th scope="col" style="width: 30%;">插件</th>
                            <th scope="col">版本</th>
                            <th scope="col">作者</th>
                            <th scope="col">ID</th>
                            <th scope="col" class="text-end">操作</th>
                        </tr>

                    </thead>

                    <tbody id="plugin-list">

                        <?php foreach ($plugins as $plugin): ?>

                            <?php
                            $hasAdminPage = is_file(
                                $plugin['plugin_dir']
                                . '/plugin/admin/index.php'
                            );

                            $searchText =
                                $plugin['name'] . ' '
                                . $plugin['slug'] . ' '
                                . $plugin['author'] . ' '
                                . $plugin['description'];
                            ?>

                            <tr
                                class="plugin-row <?= $plugin['active'] ? '' : 'plugin-row-inactive' ?>"
                                data-plugin-id="<?= e($plugin['id']) ?>"
                                data-plugin-name="<?= e($plugin['name']) ?>"
                                data-plugin-active="<?= $plugin['active'] ? '1' : '0' ?>"
                                data-plugin-search="<?= e(mb_strtolower($searchText)) ?>"
                            >

                                <td>

                                    <div class="d-flex align-items-center gap-2">

                                        <div class="plugin-icon">
                                            <i class="bi bi-puzzle-fill"></i>
                                        </div>

                                        <div class="min-w-0">

                                            <div class="d-flex align-items-center gap-2">

                                                <a href="plugins.php?plugin=<?= rawurlencode($plugin['id']) ?>"
                                                   class="plugin-name text-truncate text-decoration-none"
                                                   data-pjax
                                                   style="cursor:pointer;">
                                                    <?= e($plugin['name']) ?>
                                                </a>

                                                <span class="plugin-status <?= $plugin['active'] ? 'active' : 'inactive' ?>">

                                                    <?= $plugin['active']
                                                        ? '运行中'
                                                        : '已停用'
                                                    ?>

                                                </span>

                                            </div>


                                            <span
                                                class="plugin-desc small text-muted mt-1"
                                                title="<?= e($plugin['description']) ?>"
                                            >

                                                <?= $plugin['description']
                                                    ? e($plugin['description'])
                                                    : '这个插件没有提供说明。'
                                                ?>

                                            </span>

                                        </div>

                                    </div>

                                </td>

                                <td class="text-nowrap">

                                    <?php if ($plugin['version']): ?>

                                        <span class="text-muted small">
                                            <i class="bi bi-tag me-1"></i>
                                            v<?= e($plugin['version']) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?php if ($plugin['author']): ?>

                                        <?php if ($plugin['author_uri']): ?>

                                            <a
                                                href="<?= e($plugin['author_uri']) ?>"
                                                target="_blank"
                                                rel="noopener"
                                                class="small text-decoration-none"
                                            >
                                                <i class="bi bi-person me-1"></i>
                                                <?= e($plugin['author']) ?>
                                            </a>

                                        <?php else: ?>

                                            <span class="small text-muted">
                                                <i class="bi bi-person me-1"></i>
                                                <?= e($plugin['author']) ?>
                                            </span>

                                        <?php endif; ?>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <code class="small" title="插件内部 ID">
                                        <?= e($plugin['id']) ?>
                                    </code>

                                </td>

                                <td class="text-end">

                                    <div class="plugin-actions">

                                        <!-- 滑动开关 -->
                                        <form method="POST" class="plugin-toggle-form d-inline-block">
                                            <input type="hidden" name="csrf_token" value="<?= e(generateCSRFToken()) ?>">
                                            <input type="hidden" name="plugin_id" value="<?= e($plugin['id']) ?>">
                                            <div class="form-check form-switch plugin-switch-wrap" data-plugin-active="<?= $plugin['active'] ? '1' : '0' ?>">
                                                <input class="form-check-input plugin-switch" type="checkbox" role="switch"
                                                    <?= $plugin['active'] ? 'checked' : '' ?>
                                                    data-plugin-id="<?= e($plugin['id']) ?>"
                                                    data-plugin-name="<?= e($plugin['name']) ?>"
                                                    data-csrf="<?= e(generateCSRFToken()) ?>">
                                            </div>
                                        </form>

                                        <!-- 下拉菜单 -->
                                        <div class="dropdown plugin-dropdown d-inline-block">
                                            <button class="btn btn-sm btn-light plugin-dropdown-btn" type="button" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                                                <i class="bi bi-three-dots"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end plugin-dropdown-menu">
                                                <li>
                                                    <a class="dropdown-item" href="plugins.php?plugin=<?= rawurlencode($plugin['id']) ?>" data-pjax>
                                                        <i class="bi bi-info-circle me-2"></i>详情
                                                    </a>
                                                </li>
                                                <?php if ($plugin['active'] && $hasAdminPage): ?>
                                                <li>
                                                    <a class="dropdown-item" href="/admin/plugin-page.php?plugin=<?= rawurlencode($plugin['id']) ?>" data-pjax>
                                                        <i class="bi bi-sliders me-2"></i>管理
                                                    </a>
                                                </li>
                                                <?php endif; ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button class="dropdown-item text-danger plugin-uninstall-btn" type="button"
                                                        data-plugin-id="<?= e($plugin['id']) ?>"
                                                        data-plugin-name="<?= e($plugin['name']) ?>"
                                                        data-csrf="<?= e(generateCSRFToken()) ?>">
                                                        <i class="bi bi-trash3 me-2"></i>卸载
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>


        <div
            class="plugin-search-empty"
            id="plugin-search-empty"
        >
            <i class="bi bi-search fs-3 d-block mb-2"></i>
            没找到符合条件的插件
        </div>


    <?php endif; ?>


    <div class="mt-4 mb-4">

        <details class="small text-muted">

            <summary style="cursor:pointer;">
                插件开发信息
            </summary>

            <div class="mt-2">

                插件目录：

                <code>
                    /vendor/nova-plugins/
                </code>

                <br>

                系统会读取插件目录中的

                <code>plugin.json</code>

                并自动分配唯一 ID。

            </div>

        </details>

    </div>

</div>


<script type="text/pjax-script">
(function () {

    var csrfToken = '<?= e(generateCSRFToken()) ?>';

    var currentFilter = 'all';


    /*
     * 更新插件卡片状态
     */
    function applyCardState(card, active) {

        card.classList.toggle(
            'plugin-row-inactive',
            !active
        );

        card.dataset.pluginActive =
            active ? '1' : '0';

        var status =
            card.querySelector('.plugin-status');

        if (status) {

            status.className =
                'plugin-status '
                + (active ? 'active' : 'inactive');

            status.textContent =
                active ? '运行中' : '已停用';

        }

        // 更新滑动开关
        var sw =
            card.querySelector('.plugin-switch');

        if (sw) {

            sw.checked = active;

        }

        // 更新管理菜单项可见性
        var manageLink =
            card.querySelector(
                'a[href*="plugin-page.php"]'
            );

        if (manageLink) {

            manageLink.style.display =
                active ? '' : 'none';

        }

        filterPlugins();

    }


    /*
     * 更新统计数字
     */
    function updateStats(enabled, disabled) {

        var enabledEl =
            document.getElementById(
                'stat-enabled'
            );

        var disabledEl =
            document.getElementById(
                'stat-disabled'
            );

        if (enabledEl) {
            enabledEl.textContent = enabled;
        }

        if (disabledEl) {
            disabledEl.textContent = disabled;
        }

    }


    /*
     * 提示
     */
    function showAlert(type, message) {

        var container =
            document.getElementById(
                'plugin-alert'
            );

        if (!container) {
            return;
        }


        var success =
            type === 'success';


        container.innerHTML =
            '<div class="alert '
            + (success
                ? 'alert-success'
                : 'alert-danger')
            + ' shadow-sm alert-dismissible fade show">'
            + '<i class="bi '
            + (success
                ? 'bi-check-circle'
                : 'bi-exclamation-triangle')
            + ' me-2"></i>'
            + message
            + '<button type="button" '
            + 'class="btn-close" '
            + 'data-bs-dismiss="alert">'
            + '</button>'
            + '</div>';


        window.setTimeout(function () {

            var alertEl =
                container.querySelector(
                    '.alert'
                );

            if (
                alertEl
                && window.bootstrap
            ) {

                bootstrap.Alert
                    .getOrCreateInstance(
                        alertEl
                    )
                    .close();

            }

        }, 3000);

    }


    /*
     * 刷新左侧菜单
     */
    function refreshSidebar() {

        fetch(
            window.location.pathname
            + window.location.search,
            {
                headers: {
                    'X-Requested-With':
                        'XMLHttpRequest'
                }
            }
        )

        .then(function (response) {

            return response.text();

        })

        .then(function (html) {

            var doc =
                new DOMParser()
                    .parseFromString(
                        html,
                        'text/html'
                    );

            var newMenu =
                doc.querySelector(
                    '.sidebar-menu'
                );

            var currentMenu =
                document.querySelector(
                    '.sidebar-menu'
                );

            if (
                newMenu
                && currentMenu
            ) {

                currentMenu.innerHTML =
                    newMenu.innerHTML;

            }

        })

        .catch(function () {});

    }


    /*
     * 搜索 + 筛选
     */
    function filterPlugins() {

        var input =
            document.getElementById(
                'plugin-search-input'
            );

        var keyword =
            input
                ? input.value
                    .trim()
                    .toLowerCase()
                : '';


        var visibleCount = 0;


        document
            .querySelectorAll(
                '.plugin-row'
            )
            .forEach(function (item) {

                var active =
                    item.dataset.pluginActive
                    === '1';

                var text =
                    (
                        item.dataset.pluginSearch
                        || ''
                    ).toLowerCase();


                var matchesSearch =
                    keyword === ''
                    || text.includes(keyword);


                var matchesFilter =
                    currentFilter === 'all'
                    || (
                        currentFilter
                        === 'active'
                        && active
                    )
                    || (
                        currentFilter
                        === 'inactive'
                        && !active
                    );


                var visible =
                    matchesSearch
                    && matchesFilter;


                item.style.display =
                    visible ? '' : 'none';


                if (visible) {
                    visibleCount++;
                }

            });


        var empty =
            document.getElementById(
                'plugin-search-empty'
            );

        if (empty) {

            empty.style.display =
                visibleCount === 0
                    ? 'block'
                    : 'none';

        }

    }


    /*
     * 搜索框
     */
    var searchInput =
        document.getElementById(
            'plugin-search-input'
        );

    if (searchInput) {

        searchInput.addEventListener(
            'input',
            filterPlugins
        );

    }


    /*
     * 状态筛选
     */
    document
        .querySelectorAll(
            '.plugin-filter-btn'
        )
        .forEach(function (button) {

            button.addEventListener(
                'click',
                function () {

                    currentFilter =
                        button.dataset
                            .pluginFilter;

                    document
                        .querySelectorAll(
                            '.plugin-filter-btn'
                        )
                        .forEach(
                            function (item) {
                                item.classList
                                    .remove(
                                        'active'
                                    );
                            }
                        );

                    button.classList
                        .add('active');

                    filterPlugins();

                }
            );

        });


    /*
     * 启用 / 停用（滑动开关）
     */
    document
        .querySelectorAll(
            '.plugin-switch'
        )
        .forEach(function (sw) {

            sw.addEventListener(
                'change',
                function () {

                    // 注意：必须匹配 .plugin-row（tr），不能用 [data-plugin-id]，
                    // 因为开关 input 自身也带 data-plugin-id，closest 会命中自身
                    var card =
                        sw.closest(
                            '.plugin-row'
                        );

                    if (!card) {
                        return;
                    }

                    var pluginId =
                        card.dataset.pluginId;

                    var pluginName =
                        card.dataset.pluginName;

                    var action =
                        sw.checked
                            ? 'activate'
                            : 'deactivate';

                    // 停用才确认
                    if (
                        action === 'deactivate'
                        && !confirm(
                            '确定停用「'
                            + pluginName
                            + '」吗？\n\n'
                            + '停用后，该插件提供的功能将暂时不可用。'
                        )
                    ) {

                        sw.checked = true;
                        return;

                    }

                    sw.disabled = true;

                    var formData =
                        new FormData();

                    formData.append(
                        'csrf_token',
                        csrfToken
                    );

                    formData.append(
                        'plugin_id',
                        pluginId
                    );

                    formData.append(
                        'plugin_action',
                        action
                    );

                    fetch(
                        window.location.pathname,
                        {
                            method: 'POST',

                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            },

                            body: formData
                        }
                    )

                    .then(function (response) {

                        return response.text().then(function (text) {

                            var data;

                            try {
                                data = JSON.parse(text);
                            } catch (e) {
                                throw new Error(
                                    '响应异常（HTTP '
                                    + response.status
                                    + '）：'
                                    + text.slice(0, 150)
                                );
                            }

                            return data;

                        });

                    })

                    .then(function (data) {

                        sw.disabled = false;

                        if (!data.ok) {

                            throw new Error(
                                data.msg
                                || '操作失败'
                            );

                        }

                        applyCardState(
                            card,
                            data.active
                        );

                        updateStats(
                            data.enabled_count,
                            data.disabled_count
                        );

                        showAlert(
                            'success',
                            data.msg
                        );

                        refreshSidebar();

                    })

                    .catch(function (error) {

                        console.error('[plugins] 启用/停用失败:', error);

                        sw.disabled = false;

                        sw.checked =
                            action === 'activate';

                        showAlert(
                            'danger',
                            error.message
                            || '操作失败，请重试'
                        );

                    });

                }
            );

        });


    /*
     * 卸载插件
     */
    document
        .querySelectorAll(
            '.plugin-uninstall-btn'
        )
        .forEach(function (btn) {

            btn.addEventListener(
                'click',
                function () {

                    var pluginId =
                        btn.getAttribute(
                            'data-plugin-id'
                        );

                    var pluginName =
                        btn.getAttribute(
                            'data-plugin-name'
                        );

                    if (
                        !confirm(
                            '确定卸载「'
                            + pluginName
                            + '」吗？\n\n'
                            + '卸载将永久删除插件目录及所有文件，此操作不可撤销。'
                        )
                    ) {

                        return;

                    }

                    // 匹配 .plugin-row（tr），不能用 [data-plugin-id]（按钮自身也带该属性）
                    var card =
                        btn.closest(
                            '.plugin-row'
                        );

                    btn.disabled = true;
                    var orig = btn.innerHTML;
                    btn.innerHTML =
                        '<span class="spinner-border '
                        + 'spinner-border-sm me-2">'
                        + '</span>卸载中…';

                    var formData =
                        new FormData();

                    formData.append(
                        'action',
                        'uninstall_plugin'
                    );

                    formData.append(
                        'csrf_token',
                        csrfToken
                    );

                    formData.append(
                        'plugin_id',
                        pluginId
                    );

                    fetch(
                        window.location.pathname,
                        {
                            method: 'POST',

                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            },

                            body: formData
                        }
                    )

                    .then(function (response) {

                        return response.text().then(function (text) {

                            var data;

                            try {
                                data = JSON.parse(text);
                            } catch (e) {
                                throw new Error(
                                    '响应异常（HTTP '
                                    + response.status
                                    + '）：'
                                    + text.slice(0, 150)
                                );
                            }

                            return data;

                        });

                    })

                    .then(function (data) {

                        if (!data.ok) {

                            throw new Error(
                                data.msg
                                || '卸载失败'
                            );

                        }

                        if (card) {
                            card.remove();
                        }

                        updateStats(
                            data.enabled_count,
                            data.disabled_count
                        );

                        showAlert(
                            'success',
                            data.msg
                        );

                        refreshSidebar();

                    })

                    .catch(function (error) {

                        console.error('[plugins] 卸载失败:', error);

                        btn.disabled = false;
                        btn.innerHTML = orig;

                        showAlert(
                            'danger',
                            error.message
                            || '卸载失败，请重试'
                        );

                    });

                }
            );

        });


})();
</script>

<?php require_once 'includes/footer.php';
