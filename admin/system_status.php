<?php
/**
 * NovaCMS 系统状态
 *
 * 只读诊断页面，不修改服务器配置。
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';

require_once __DIR__ . '/../vendor/nova-json/class/plugin/class-plugin-registry.php';


/* =========================================================
 * 工具函数
 * ========================================================= */

function nova_status_bytes($bytes, $precision = 1)
{
    $bytes = (float)$bytes;

    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(log($bytes, 1024));
    $pow = min($pow, count($units) - 1);

    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}


function nova_status_ini_bytes($value)
{
    $value = trim((string)$value);

    if ($value === '' || $value === '-1') {
        return -1;
    }

    $last = strtolower(substr($value, -1));
    $number = (float)$value;

    switch ($last) {
        case 'g':
            $number *= 1024;
            // no break
        case 'm':
            $number *= 1024;
            // no break
        case 'k':
            $number *= 1024;
            break;
    }

    return (int)$number;
}


function nova_status_bool_ini($name)
{
    $value = ini_get($name);

    if ($value === false) {
        return false;
    }

    $value = strtolower(trim((string)$value));

    return !in_array(
        $value,
        ['', '0', 'off', 'false', 'no', 'none'],
        true
    );
}


function nova_status_badge($status)
{
    switch ($status) {
        case 'ok':
            return ['success', '正常', 'bi-check-circle-fill'];

        case 'warn':
            return ['warning', '注意', 'bi-exclamation-triangle-fill'];

        case 'danger':
            return ['danger', '异常', 'bi-x-octagon-fill'];

        default:
            return ['secondary', '信息', 'bi-info-circle-fill'];
    }
}


function nova_status_add_check(&$checks, $group, $label, $value, $status, $detail = '')
{
    $checks[] = [
        'group' => $group,
        'label' => $label,
        'value' => $value,
        'status' => $status,
        'detail' => $detail,
    ];
}


/* =========================================================
 * 基础信息
 * ========================================================= */

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$isLocalHost = preg_match(
    '/^(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/i',
    $host
) === 1;

$isHttps =
    (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';

$serverSoftware = (string)($_SERVER['SERVER_SOFTWARE'] ?? '未知');
$serverSapi = PHP_SAPI;
$phpVersion = PHP_VERSION;
$timezone = date_default_timezone_get();

$checks = [];


/* =========================================================
 * PHP
 * ========================================================= */

$phpVersionOk = version_compare(PHP_VERSION, '7.4.0', '>=');

nova_status_add_check(
    $checks,
    'PHP',
    'PHP 版本',
    PHP_VERSION,
    $phpVersionOk ? 'ok' : 'danger',
    $phpVersionOk
        ? '满足 NovaCMS 的最低运行要求。'
        : '版本过低，建议升级到 PHP 8.x。'
);


$requiredExtensions = [
    'pdo_mysql' => 'PDO MySQL',
    'mbstring' => 'Mbstring',
    'openssl' => 'OpenSSL',
    'curl' => 'cURL',
    'gd' => 'GD',
];

foreach ($requiredExtensions as $extension => $label) {
    $loaded = extension_loaded($extension);

    nova_status_add_check(
        $checks,
        'PHP',
        $label,
        $loaded ? '已加载' : '未加载',
        $loaded ? 'ok' : 'danger',
        $loaded
            ? '扩展可用。'
            : 'NovaCMS 依赖此 PHP 扩展，请在 php.ini 中启用。'
    );
}


$optionalExtensions = [
    'fileinfo' => 'Fileinfo',
    'zip' => 'Zip',
    'opcache' => 'OPcache',
];

foreach ($optionalExtensions as $extension => $label) {
    $loaded = extension_loaded($extension);

    nova_status_add_check(
        $checks,
        'PHP',
        $label,
        $loaded ? '已加载' : '未加载',
        $loaded ? 'ok' : 'info',
        $loaded
            ? '扩展可用。'
            : '非核心必需，但部分功能或性能可能受益。'
    );
}


$memoryLimit = (string)ini_get('memory_limit');
$memoryBytes = nova_status_ini_bytes($memoryLimit);
$memoryOk = $memoryBytes === -1 || $memoryBytes >= 128 * 1024 * 1024;

nova_status_add_check(
    $checks,
    'PHP',
    '内存限制',
    $memoryLimit ?: '未设置',
    $memoryOk ? 'ok' : 'warn',
    $memoryOk
        ? '当前限制适合常规后台操作。'
        : '低于 128M，图片处理或备份时可能不够。'
);


$uploadMax = (string)ini_get('upload_max_filesize');
$postMax = (string)ini_get('post_max_size');

nova_status_add_check(
    $checks,
    'PHP',
    '上传限制',
    ($uploadMax ?: '-') . ' / POST ' . ($postMax ?: '-'),
    'info',
    '前者为单文件上限，后者为整个 POST 请求上限。'
);


$maxExecutionTime = (int)ini_get('max_execution_time');

nova_status_add_check(
    $checks,
    'PHP',
    '最大执行时间',
    $maxExecutionTime > 0 ? $maxExecutionTime . ' 秒' : '无限制',
    'info',
    '备份大站点时可能需要更长执行时间。'
);


/* =========================================================
 * 数据库
 * ========================================================= */

$dbVersion = '未知';
$dbLatencyMs = null;
$dbStatus = 'danger';
$dbDetail = '数据库连接测试失败。';

try {
    $start = microtime(true);

    $stmt = $db->query('SELECT VERSION() AS version, DATABASE() AS db_name');
    $dbInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $dbLatencyMs = round((microtime(true) - $start) * 1000, 1);
    $dbVersion = (string)($dbInfo['version'] ?? '未知');
    $dbName = (string)($dbInfo['db_name'] ?? '');

    if ($dbLatencyMs < 100) {
        $dbStatus = 'ok';
    } elseif ($dbLatencyMs < 500) {
        $dbStatus = 'warn';
    } else {
        $dbStatus = 'danger';
    }

    $dbDetail =
        '当前数据库：'
        . ($dbName !== '' ? $dbName : '未知')
        . '；简单查询耗时 '
        . $dbLatencyMs
        . ' ms。';

} catch (Throwable $e) {
    $dbDetail = '数据库检测失败：' . $e->getMessage();
}


nova_status_add_check(
    $checks,
    '数据库',
    'MySQL / MariaDB',
    $dbVersion,
    $dbVersion !== '未知' ? 'ok' : 'danger',
    $dbDetail
);


nova_status_add_check(
    $checks,
    '数据库',
    '数据库延迟',
    $dbLatencyMs !== null ? $dbLatencyMs . ' ms' : '无法测量',
    $dbStatus,
    $dbLatencyMs !== null
        ? '这是后台到数据库执行一次简单查询的耗时，不代表互联网延迟。'
        : '请检查数据库服务与连接配置。'
);


/* =========================================================
 * 磁盘与目录权限
 * ========================================================= */

$diskTotal = @disk_total_space($projectRoot);
$diskFree = @disk_free_space($projectRoot);
$diskUsedPercent = null;
$diskFreePercent = null;

if ($diskTotal !== false && $diskTotal > 0 && $diskFree !== false) {
    $diskFreePercent = ($diskFree / $diskTotal) * 100;
    $diskUsedPercent = 100 - $diskFreePercent;
}

$diskStatus = 'info';

if ($diskFreePercent !== null) {
    if ($diskFreePercent >= 15) {
        $diskStatus = 'ok';
    } elseif ($diskFreePercent >= 5) {
        $diskStatus = 'warn';
    } else {
        $diskStatus = 'danger';
    }
}

nova_status_add_check(
    $checks,
    '存储',
    '磁盘空间',
    $diskTotal !== false && $diskFree !== false
        ? nova_status_bytes($diskFree) . ' 可用'
        : '无法读取',
    $diskStatus,
    $diskTotal !== false && $diskFree !== false
        ? '总容量 '
            . nova_status_bytes($diskTotal)
            . '，已使用 '
            . round((float)$diskUsedPercent, 1)
            . '%。'
        : 'PHP 无法读取磁盘空间信息。'
);


$writableDirectories = [
    'uploads' => $projectRoot . '/uploads',
    'logs' => $projectRoot . '/logs',
    '备份目录' => $projectRoot . '/vendor/nova-plugins/backup/backups',
    '临时目录' => $projectRoot . '/vendor/temp',
];

foreach ($writableDirectories as $label => $path) {

    $exists = is_dir($path);
    $writable = $exists && is_writable($path);

    if (!$exists) {
        $status = 'warn';
        $value = '目录不存在';
        $detail = '目录：' . $path;
    } elseif (!$writable) {
        $status = 'danger';
        $value = '不可写';
        $detail = '目录：' . $path;
    } else {
        $status = 'ok';
        $value = '可写';
        $detail = '目录：' . $path;
    }

    nova_status_add_check(
        $checks,
        '存储',
        $label,
        $value,
        $status,
        $detail
    );
}


/* =========================================================
 * 安全与运行环境
 * ========================================================= */

if ($isHttps) {
    $httpsStatus = 'ok';
    $httpsValue = 'HTTPS';
    $httpsDetail = '当前后台通过 HTTPS 访问。';
} elseif ($isLocalHost) {
    $httpsStatus = 'info';
    $httpsValue = 'HTTP（本地）';
    $httpsDetail = 'localhost 开发环境使用 HTTP 很常见；上线后建议启用 HTTPS。';
} else {
    $httpsStatus = 'warn';
    $httpsValue = 'HTTP';
    $httpsDetail = '正式环境建议使用 HTTPS，尤其是登录和后台页面。';
}

nova_status_add_check(
    $checks,
    '安全',
    '连接协议',
    $httpsValue,
    $httpsStatus,
    $httpsDetail
);


$displayErrors = nova_status_bool_ini('display_errors');

nova_status_add_check(
    $checks,
    '安全',
    'display_errors',
    $displayErrors ? '开启' : '关闭',
    $displayErrors && !$isLocalHost ? 'warn' : 'ok',
    $displayErrors
        ? ($isLocalHost
            ? '本地调试环境可以开启；上线前建议关闭。'
            : '生产环境开启可能泄露文件路径和错误细节。')
        : '生产环境建议保持关闭，并将错误写入日志。'
);


$cookieHttpOnly = nova_status_bool_ini('session.cookie_httponly');

nova_status_add_check(
    $checks,
    '安全',
    'Session HttpOnly',
    $cookieHttpOnly ? '开启' : '关闭',
    $cookieHttpOnly ? 'ok' : 'warn',
    $cookieHttpOnly
        ? 'JavaScript 无法直接读取 Session Cookie。'
        : '建议开启 session.cookie_httponly。'
);


$cookieSecure = nova_status_bool_ini('session.cookie_secure');

nova_status_add_check(
    $checks,
    '安全',
    'Session Secure',
    $cookieSecure ? '开启' : '关闭',
    $isHttps && !$cookieSecure ? 'warn' : 'info',
    $isHttps
        ? ($cookieSecure
            ? 'Session Cookie 仅通过 HTTPS 发送。'
            : '当前已使用 HTTPS，建议开启 session.cookie_secure。')
        : '在纯 HTTP 本地开发环境中通常保持关闭。'
);


$sameSite = (string)ini_get('session.cookie_samesite');

nova_status_add_check(
    $checks,
    '安全',
    'Session SameSite',
    $sameSite !== '' ? $sameSite : '未设置',
    $sameSite !== '' ? 'ok' : 'warn',
    $sameSite !== ''
        ? '用于降低跨站请求携带 Cookie 的风险。'
        : '建议设置为 Lax 或 Strict。'
);


/* =========================================================
 * NovaCMS 状态
 * ========================================================= */

$pluginTotal = 0;
$pluginActive = 0;
$pluginError = '';

try {
    $installedPlugins = Nova_Plugin_Registry::scan_all();
    $pluginTotal = count($installedPlugins);

    $activePluginIds = null;

    if (!empty($config['active_plugins'])) {
        $decoded = json_decode(
            (string)$config['active_plugins'],
            true
        );

        if (is_array($decoded)) {
            $activePluginIds = $decoded;
        }
    }

    foreach ($installedPlugins as $plugin) {
        if (
            $activePluginIds === null
            || in_array($plugin['id'], $activePluginIds, true)
        ) {
            $pluginActive++;
        }
    }

} catch (Throwable $e) {
    $pluginError = $e->getMessage();
}


nova_status_add_check(
    $checks,
    'NovaCMS',
    '插件',
    $pluginError === ''
        ? $pluginActive . ' / ' . $pluginTotal . ' 已启用'
        : '读取失败',
    $pluginError === '' ? 'ok' : 'warn',
    $pluginError === ''
        ? '插件状态来自 website_config.active_plugins。'
        : $pluginError
);


$activeTheme = trim((string)($config['active_theme'] ?? ''));

nova_status_add_check(
    $checks,
    'NovaCMS',
    '当前主题',
    $activeTheme !== '' ? $activeTheme : '未设置',
    $activeTheme !== '' ? 'ok' : 'warn',
    '主题目录：vendor/nova-themes/'
);


$debugMode =
    defined('NOVA_DEBUG')
    ? (bool)constant('NOVA_DEBUG')
    : false;

nova_status_add_check(
    $checks,
    'NovaCMS',
    '调试模式',
    $debugMode ? '开启' : '未开启',
    $debugMode && !$isLocalHost ? 'warn' : 'info',
    $debugMode
        ? '上线环境建议关闭调试输出。'
        : '未检测到 NOVA_DEBUG=true。'
);


/* =========================================================
 * 总体健康度
 * ========================================================= */

$score = 100;
$dangerCount = 0;
$warningCount = 0;

foreach ($checks as $check) {

    if ($check['status'] === 'danger') {
        $score -= 15;
        $dangerCount++;
    } elseif ($check['status'] === 'warn') {
        $score -= 5;
        $warningCount++;
    }
}

$score = max(0, min(100, $score));

if ($dangerCount > 0 || $score < 70) {
    $healthStatus = 'danger';
    $healthLabel = '需要处理';
} elseif ($warningCount > 0 || $score < 90) {
    $healthStatus = 'warning';
    $healthLabel = '基本正常';
} else {
    $healthStatus = 'success';
    $healthLabel = '运行良好';
}


$groups = [
    'PHP' => 'bi-code-slash',
    '数据库' => 'bi-database',
    '存储' => 'bi-device-ssd',
    '安全' => 'bi-shield-check',
    'NovaCMS' => 'bi-stars',
];


$page_title = '系统状态';

$extra_css = <<<'CSS'
.system-status-page {
    max-width: 1500px;
    margin: 0 auto;
}

.system-status-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin: 8px 0 22px;
}

.system-status-header h1 {
    margin: 0;
    color: var(--admin-heading);
    font-size: 1.55rem;
    font-weight: 700;
}

.system-status-header p {
    margin: 5px 0 0;
    color: var(--admin-muted);
    font-size: .88rem;
}

.health-overview {
    display: grid;
    grid-template-columns: minmax(260px, 1.25fr) repeat(3, minmax(150px, .55fr));
    gap: 14px;
    margin-bottom: 20px;
}

.health-card,
.status-panel {
    border: 1px solid var(--admin-border);
    border-radius: 16px;
    background: var(--admin-surface);
    box-shadow: var(--admin-shadow-sm);
}

.health-card {
    min-height: 140px;
    padding: 20px;
}

.health-card-primary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}

.health-score {
    display: flex;
    align-items: baseline;
    gap: 4px;
    margin-top: 8px;
}

.health-score strong {
    color: var(--admin-heading);
    font-size: 2.35rem;
    line-height: 1;
}

.health-score span {
    color: var(--admin-muted);
    font-size: .85rem;
}

.health-gauge {
    width: 82px;
    height: 82px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    position: relative;
    background:
        conic-gradient(
            var(--health-color) calc(var(--health-score) * 1%),
            var(--admin-surface-muted) 0
        );
}

.health-gauge::before {
    content: "";
    position: absolute;
    inset: 8px;
    border-radius: inherit;
    background: var(--admin-surface);
}

.health-gauge i {
    position: relative;
    z-index: 1;
    color: var(--health-color);
    font-size: 1.5rem;
}

.health-card-label {
    color: var(--admin-muted);
    font-size: .78rem;
}

.health-card-value {
    margin-top: 10px;
    color: var(--admin-heading);
    font-size: 1.45rem;
    font-weight: 700;
}

.health-card-note {
    margin-top: 7px;
    color: var(--admin-muted);
    font-size: .76rem;
}

.status-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.status-panel {
    overflow: hidden;
}

.status-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 18px;
    border-bottom: 1px solid var(--admin-border);
}

.status-panel-title {
    display: flex;
    align-items: center;
    gap: 9px;
}

.status-panel-title i {
    color: var(--admin-primary);
}

.status-panel-title h2 {
    margin: 0;
    color: var(--admin-heading);
    font-size: .98rem;
    font-weight: 700;
}

.status-panel-count {
    color: var(--admin-muted);
    font-size: .72rem;
}

.status-list {
    display: grid;
}

.status-row {
    display: grid;
    grid-template-columns: minmax(130px, .8fr) minmax(130px, 1fr) auto;
    gap: 14px;
    align-items: center;
    padding: 13px 18px;
    border-bottom: 1px solid var(--admin-border);
}

.status-row:last-child {
    border-bottom: 0;
}

.status-row:hover {
    background: var(--admin-surface-muted);
}

.status-name {
    min-width: 0;
}

.status-name strong {
    display: block;
    color: var(--admin-heading);
    font-size: .82rem;
    font-weight: 650;
}

.status-name small {
    display: block;
    margin-top: 3px;
    overflow: hidden;
    color: var(--admin-muted);
    font-size: .7rem;
    line-height: 1.45;
    text-overflow: ellipsis;
}

.status-value {
    min-width: 0;
    overflow: hidden;
    color: var(--admin-text);
    font-size: .8rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: .68rem;
    font-weight: 650;
    white-space: nowrap;
}

.status-badge-success {
    color: var(--admin-success);
    background: color-mix(in srgb, var(--admin-success) 12%, transparent);
}

.status-badge-warning {
    color: var(--admin-warning);
    background: color-mix(in srgb, var(--admin-warning) 14%, transparent);
}

.status-badge-danger {
    color: var(--admin-danger);
    background: color-mix(in srgb, var(--admin-danger) 12%, transparent);
}

.status-badge-secondary {
    color: var(--admin-muted);
    background: var(--admin-surface-muted);
}

.system-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 16px;
    margin-top: 18px;
    padding: 13px 16px;
    border: 1px dashed var(--admin-border);
    border-radius: 12px;
    color: var(--admin-muted);
    font-size: .72rem;
}

.system-meta span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

@media (max-width: 1100px) {
    .health-overview {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .status-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .system-status-header {
        flex-direction: column;
    }

    .health-overview {
        grid-template-columns: 1fr;
    }

    .status-row {
        grid-template-columns: 1fr auto;
    }

    .status-value {
        grid-column: 1 / -1;
        grid-row: 2;
        white-space: normal;
        word-break: break-word;
    }
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>


<div class="system-status-page">

    <header class="system-status-header">

        <div>
            <h1>系统状态</h1>
            <p>检查 NovaCMS、PHP、数据库、目录权限与基础安全配置。此页面只读取状态，不会修改服务器设置。</p>
        </div>

        <div class="d-flex gap-2">
            <a
                class="btn btn-light"
                href="/admin/view_logs.php"
            >
                <i class="bi bi-journal-text me-1"></i>
                系统日志
            </a>

            <button
                class="btn btn-primary"
                type="button"
                onclick="window.location.reload()"
            >
                <i class="bi bi-arrow-clockwise me-1"></i>
                重新检测
            </button>
        </div>

    </header>


    <section class="health-overview" aria-label="系统健康概览">

        <article class="health-card health-card-primary">

            <div>
                <div class="health-card-label">总体健康度</div>

                <div class="health-score">
                    <strong><?= (int)$score ?></strong>
                    <span>/ 100 · <?= e($healthLabel) ?></span>
                </div>

                <div class="health-card-note">
                    <?= $dangerCount > 0
                        ? '检测到 ' . (int)$dangerCount . ' 项异常，请优先处理。'
                        : ($warningCount > 0
                            ? '有 ' . (int)$warningCount . ' 项建议可以优化。'
                            : '核心检查全部通过。')
                    ?>
                </div>
            </div>

            <div
                class="health-gauge"
                style="
                    --health-score: <?= (int)$score ?>;
                    --health-color:
                    <?= $healthStatus === 'success'
                        ? 'var(--admin-success)'
                        : ($healthStatus === 'warning'
                            ? 'var(--admin-warning)'
                            : 'var(--admin-danger)')
                    ?>;
                "
                aria-label="健康度 <?= (int)$score ?> 分"
            >
                <i class="bi bi-heart-pulse-fill"></i>
            </div>

        </article>


        <article class="health-card">
            <div class="health-card-label">PHP</div>
            <div class="health-card-value"><?= e($phpVersion) ?></div>
            <div class="health-card-note"><?= e($serverSapi) ?></div>
        </article>


        <article class="health-card">
            <div class="health-card-label">数据库</div>
            <div class="health-card-value">
                <?= $dbLatencyMs !== null ? e((string)$dbLatencyMs) . ' ms' : '异常' ?>
            </div>
            <div class="health-card-note">
                <?= e(mb_substr($dbVersion, 0, 32)) ?>
            </div>
        </article>


        <article class="health-card">
            <div class="health-card-label">插件</div>
            <div class="health-card-value"><?= (int)$pluginActive ?> / <?= (int)$pluginTotal ?></div>
            <div class="health-card-note">已启用 / 已安装</div>
        </article>

    </section>


    <section class="status-grid">

        <?php foreach ($groups as $groupName => $groupIcon): ?>

            <?php
            $groupChecks = array_values(array_filter(
                $checks,
                static function ($item) use ($groupName) {
                    return $item['group'] === $groupName;
                }
            ));
            ?>

            <article class="status-panel">

                <header class="status-panel-head">

                    <div class="status-panel-title">
                        <i class="bi <?= e($groupIcon) ?>"></i>
                        <h2><?= e($groupName) ?></h2>
                    </div>

                    <span class="status-panel-count">
                        <?= count($groupChecks) ?> 项
                    </span>

                </header>


                <div class="status-list">

                    <?php foreach ($groupChecks as $item): ?>

                        <?php
                        [$badgeClass, $badgeLabel, $badgeIcon]
                            = nova_status_badge($item['status']);
                        ?>

                        <div class="status-row">

                            <div class="status-name">
                                <strong><?= e($item['label']) ?></strong>

                                <?php if ($item['detail'] !== ''): ?>
                                    <small title="<?= e($item['detail']) ?>">
                                        <?= e($item['detail']) ?>
                                    </small>
                                <?php endif; ?>
                            </div>


                            <div
                                class="status-value"
                                title="<?= e((string)$item['value']) ?>"
                            >
                                <?= e((string)$item['value']) ?>
                            </div>


                            <span class="status-badge status-badge-<?= e($badgeClass) ?>">
                                <i class="bi <?= e($badgeIcon) ?>"></i>
                                <?= e($badgeLabel) ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>

            </article>

        <?php endforeach; ?>

    </section>


    <div class="system-meta">

        <span>
            <i class="bi bi-server"></i>
            <?= e($serverSoftware) ?>
        </span>

        <span>
            <i class="bi bi-clock"></i>
            <?= e($timezone) ?>
        </span>

        <span>
            <i class="bi bi-hdd"></i>
            <?= e($projectRoot) ?>
        </span>

        <span>
            <i class="bi bi-globe2"></i>
            <?= e($host !== '' ? $host : '未知主机') ?>
        </span>

    </div>

</div>


<?php
require_once __DIR__ . '/includes/footer.php';
?>