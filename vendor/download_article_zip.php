<?php
/**
 * 文章下载脚本 - 异步版
 * 
 * 流程：
 * 1. 检查权限、验证码、密码
 * 2. 显示异步页面，用户点击开始按钮
 * 3. AJAX 创建临时目录 → 复制资源 → 生成 ZIP → 提供下载
 * 4. 清理临时文件
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M');

ob_start();
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';

// ==================== 权限与安全验证 ====================

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    die('无权访问');
}

$db = getDB();

// 验证邮箱验证码
$email = $_SESSION['user_email'] ?? '';
$code = $_GET['code'] ?? '';

if (empty($email)) {
    die('当前登录用户未绑定有效邮箱');
}

if (empty($code)) {
    die('请提供验证码');
}

$stmt = $db->prepare("SELECT * FROM email_verification WHERE email = ? AND code = ? AND purpose = 'download_article' AND is_used = 0 AND expires_at > NOW()");
$stmt->execute([$email, $code]);
$verification = $stmt->fetch();

if (!$verification) {
    die('验证码无效或已过期');
}

// 验证密码
$password = $_GET['password'] ?? '';
$configPath = __DIR__ . '/../config/markdown_copy_password.config';
if (!file_exists($configPath)) {
    die('配置文件不存在');
}
$config = parse_ini_file($configPath);
$correctPasswordHash = $config['password'] ?? '';
$zipPassword = $password . $code;

if (md5($password) !== $correctPasswordHash) {
    die('密码错误');
}

// 获取文章信息
$postId = $_GET['id'] ?? 0;
if (!$postId) {
    die('未指定文章ID');
}

$stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
$stmt->execute([$postId]);
$post = $stmt->fetch();

if (!$post) {
    die('文章不存在');
}

$studioConfig = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

$tempBaseDir = __DIR__ . '/temp';
$maxFileSize = 50 * 1024 * 1024;

// ==================== AJAX 处理 ====================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_end_clean();

    $action = $_GET['action'];

    // 确保 temp 目录存在
    if (!is_dir($tempBaseDir)) {
        mkdir($tempBaseDir, 0777, true);
    }

    if ($action === 'prepare') {
        $content = $post['content'];
        $links = extractAllLinks($content);
        $currentDomain = $_SERVER['SERVER_NAME'] ?? '';

        $localUrls = [];
        $externalUrls = [];
        $skippedUrls = [];

        foreach ($links as $url) {
            if (empty($url)) continue;
            if (preg_match('/^(javascript|mailto|tel|data|#):/i', $url)) {
                $skippedUrls[] = ['url' => $url, 'reason' => '非文件协议'];
                continue;
            }
            $path = parse_url($url, PHP_URL_PATH);
            if (empty($path) || $path === '/') {
                $skippedUrls[] = ['url' => $url, 'reason' => '无效路径'];
                continue;
            }
            if (strpos($url, 'http') === 0) {
                $urlDomain = parse_url($url, PHP_URL_HOST);
                if ($urlDomain && $urlDomain !== $currentDomain) {
                    $externalUrls[] = $url;
                    continue;
                }
            }
            $localUrls[] = $url;
        }

        // 创建临时目录
        $uniqueId = uniqid();
        $tempDir = $tempBaseDir . '/' . $uniqueId;
        $assetsDir = $tempDir . '/assets';

        if (!mkdir($tempDir, 0777, true) || !mkdir($assetsDir, 0777, true)) {
            echo json_encode(['success' => false, 'error' => '无法创建临时目录']);
            exit;
        }

        $_SESSION['zip_unique_id'] = $uniqueId;
        $_SESSION['zip_temp_dir'] = $tempDir;
        $_SESSION['zip_assets_dir'] = $assetsDir;

        echo json_encode([
            'success' => true,
            'uniqueId' => $uniqueId,
            'tempDir' => $tempDir,
            'localUrls' => $localUrls,
            'externalUrls' => $externalUrls,
            'skippedUrls' => $skippedUrls
        ]);
        exit;
    }

    if ($action === 'start_copy') {
        $localUrls = $_SESSION['zip_local_urls'] ?? [];
        echo json_encode(['success' => true, 'localUrls' => $localUrls]);
        exit;
    }

    if ($action === 'copy_file') {
        $url = $_GET['url'] ?? '';
        if (empty($url)) {
            echo json_encode(['success' => false, 'error' => '无效URL']);
            exit;
        }

        $assetsDir = $_SESSION['zip_assets_dir'] ?? '';
        if (empty($assetsDir) || !is_dir($assetsDir)) {
            echo json_encode(['success' => false, 'error' => '临时目录不存在']);
            exit;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $filename = basename($path);
        $filename = preg_replace('/[\\/:*?"<>|]/', '_', urldecode($filename));
        if (!pathinfo($filename, PATHINFO_EXTENSION)) {
            $filename .= '.jpg';
        }

        // 确保文件名唯一
        $counter = 1;
        $originalFilename = $filename;
        while (file_exists($assetsDir . '/' . $filename)) {
            $filename = pathinfo($originalFilename, PATHINFO_FILENAME) . '_' . $counter . '.' . pathinfo($originalFilename, PATHINFO_EXTENSION);
            $counter++;
        }

        // 获取本地文件路径
        $localPath = null;
        if (strpos($url, 'http') !== 0) {
            $localPath = dirname(__DIR__) . '/' . ltrim($url, '/');
        } else {
            $urlDomain = parse_url($url, PHP_URL_HOST);
            $currentDomain = $_SERVER['SERVER_NAME'] ?? '';
            if ($urlDomain === $currentDomain) {
                $urlPath = parse_url($url, PHP_URL_PATH);
                $localPath = dirname(__DIR__) . '/' . ltrim($urlPath, '/');
            }
        }

        if (!$localPath || !file_exists($localPath)) {
            echo json_encode(['success' => false, 'error' => '文件不存在', 'url' => $url]);
            exit;
        }

        $fileSize = filesize($localPath);
        if ($fileSize > $maxFileSize) {
            echo json_encode(['success' => false, 'error' => '文件过大(' . round($fileSize/1024/1024,2) . 'MB)', 'url' => $url]);
            exit;
        }

        if (@copy($localPath, $assetsDir . '/' . $filename)) {
            echo json_encode(['success' => true, 'url' => $url, 'file' => $filename, 'size' => $fileSize]);
        } else {
            echo json_encode(['success' => false, 'error' => '复制失败', 'url' => $url]);
        }
        exit;
    }

    if ($action === 'generate_zip') {
        $tempDir = $_SESSION['zip_temp_dir'] ?? '';
        $assetsDir = $_SESSION['zip_assets_dir'] ?? '';
        $uniqueId = $_SESSION['zip_unique_id'] ?? '';
        $replacedUrls = json_decode($_POST['replaced_urls'] ?? '{}', true);

        if (empty($tempDir) || empty($assetsDir)) {
            echo json_encode(['success' => false, 'error' => '临时目录不存在']);
            exit;
        }

        $title = preg_replace('/[\\/:*?"<>|]/', '_', $post['title']);
        $author = $post['author'] ?? '原作者';
        $license = $post['license'] ?? '无协议';

        // 替换链接并写入 Markdown
        $content = $post['content'];
        $markdownContent = "# " . $post['title'] . "\n\n" . $content;
        foreach ($replacedUrls as $originalUrl => $newFile) {
            $markdownContent = str_replace($originalUrl, 'assets/' . $newFile, $markdownContent);
        }

        $mdFilePath = $tempDir . '/' . $title . '.md';
        if (file_put_contents($mdFilePath, $markdownContent) === false) {
            echo json_encode(['success' => false, 'error' => '无法写入Markdown文件']);
            exit;
        }

        // 写入版权声明
        $postInfo = [
            'title' => $title,
            'author' => $author,
            'license' => $license,
            'originalTitle' => $post['title'],
            'postId' => $postId
        ];
        $legalContent = generateLegalContent($postInfo, $studioConfig, $license);
        $legalFilePath = $tempDir . '/版权声明.txt';
        file_put_contents($legalFilePath, $legalContent);

        // 创建 ZIP（含加密）
        $zipFile = $tempBaseDir . '/' . $uniqueId . '.zip';
        $zipCreateResult = createZipArchive($tempDir, $zipFile, $zipPassword, $title);
        if (!$zipCreateResult) {
            echo json_encode(['success' => false, 'error' => 'ZIP创建失败']);
            exit;
        }

        $zipSize = filesize($zipFile);
        $assetsFiles = [];
        if (is_dir($assetsDir)) {
            $files = glob($assetsDir . '/*');
            foreach ($files as $f) {
                if (is_file($f)) $assetsFiles[] = basename($f);
            }
        }

        echo json_encode([
            'success' => true,
            'zipFile' => $uniqueId . '.zip',
            'zipSize' => round($zipSize / 1024, 2) . ' KB',
            'mdSize' => round(filesize($mdFilePath) / 1024, 2) . ' KB',
            'legalSize' => round(filesize($legalFilePath) / 1024, 2) . ' KB',
            'assetsCount' => count($assetsFiles)
        ]);
        exit;
    }

    if ($action === 'cleanup') {
        $tempDir = $_SESSION['zip_temp_dir'] ?? '';
        $uniqueId = $_SESSION['zip_unique_id'] ?? '';

        if (!empty($tempDir)) {
            deleteDirectory($tempDir);
        }
        if (!empty($uniqueId)) {
            $zf = $tempBaseDir . '/' . $uniqueId . '.zip';
            if (file_exists($zf)) @unlink($zf);
        }
        unset($_SESSION['zip_unique_id'], $_SESSION['zip_temp_dir'], $_SESSION['zip_assets_dir']);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'notify') {
        // 发送安全通知邮件
        $lastDownloadKey = 'last_download_email_' . $postId;
        $lastDownloadTime = $_SESSION[$lastDownloadKey] ?? 0;
        $currentTime = time();
        if ($currentTime - $lastDownloadTime > 60) {
            sendDownloadNotification($post, $postId, $studioConfig, $currentTime, $lastDownloadKey);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_used') {
        try {
            $updateStmt = $db->prepare("UPDATE email_verification SET is_used = 1 WHERE id = ?");
            $updateStmt->execute([$verification['id']]);
        } catch (Exception $e) {
            error_log("标记验证码已使用失败: " . $e->getMessage());
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'download') {
        $uniqueId = $_SESSION['zip_unique_id'] ?? '';
        if (empty($uniqueId)) {
            echo json_encode(['success' => false, 'error' => '无效的下载请求']);
            exit;
        }
        $zipFile = $tempBaseDir . '/' . $uniqueId . '.zip';
        if (!file_exists($zipFile)) {
            echo json_encode(['success' => false, 'error' => '文件不存在']);
            exit;
        }
        if (ob_get_length()) ob_end_clean();
        $fileSize = filesize($zipFile);
        $title = preg_replace('/[\\/:*?"<>|]/', '_', $post['title']);
        $encodedFilename = urlencode($title);
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $encodedFilename . '.zip"; filename*=UTF-8\'\'' . $encodedFilename . '.zip');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . $fileSize);
        readfile($zipFile);
        @unlink($zipFile);
        exit;
    }

    exit;
}

// 保存 localUrls 到 session 供 start_copy 使用
$content = $post['content'];
$links = extractAllLinks($content);
$currentDomain = $_SERVER['SERVER_NAME'] ?? '';
$localUrls = [];
$externalUrls = [];
$skippedUrls = [];

foreach ($links as $url) {
    if (empty($url)) continue;
    if (preg_match('/^(javascript|mailto|tel|data|#):/i', $url)) {
        $skippedUrls[] = ['url' => $url, 'reason' => '非文件协议'];
        continue;
    }
    $path = parse_url($url, PHP_URL_PATH);
    if (empty($path) || $path === '/') {
        $skippedUrls[] = ['url' => $url, 'reason' => '无效路径'];
        continue;
    }
    if (strpos($url, 'http') === 0) {
        $urlDomain = parse_url($url, PHP_URL_HOST);
        if ($urlDomain && $urlDomain !== $currentDomain) {
            $externalUrls[] = $url;
            continue;
        }
    }
    $localUrls[] = $url;
}
$_SESSION['zip_local_urls'] = $localUrls;

if (ob_get_length()) ob_end_clean();

// ==================== 主页面 ====================
?>
<html>
<head>
<meta charset="UTF-8">
<title>文章下载</title>
    <?php if (!empty($studioConfig['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($studioConfig['favicon']) ?>">
    <link rel="shortcut icon" href="<?= e($studioConfig['favicon']) ?>">
    <?php endif; ?>
<style>
body { font-family: Arial; padding: 20px; background: #f5f5f5; max-width: 1200px; margin: 0 auto; }
.step { margin: 10px 0; padding: 15px; background: white; border-radius: 5px; border-left: 4px solid #007bff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.step.pending { border-left-color: #6c757d; opacity: 0.6; }
.step.running { border-left-color: #007bff; background: #e8f4ff; }
.step.success { border-left-color: #28a745; }
.step.error { border-left-color: #dc3545; background: #fff5f5; }
.log-success { color: #28a745; }
.log-error { color: #dc3545; }
.log-info { color: #666; font-size: 12px; }
code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 13px; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
th { background: #f5f5f5; }
.btn { display: inline-block; padding: 12px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; color: white; text-decoration: none; }
.btn-primary { background: #28a745; }
.btn-primary:hover { background: #218838; }
.btn-primary:disabled { background: #6c757d; cursor: not-allowed; }
.btn-danger { background: #dc3545; }
.btn-danger:hover { background: #c82333; }
.btn-info { background: #007bff; }
.btn-info:hover { background: #0069d9; }
.progress-bar { width: 100%; background: #e9ecef; border-radius: 4px; margin: 10px 0; overflow: hidden; }
.progress-fill { height: 24px; background: linear-gradient(90deg, #28a745, #20c997); border-radius: 4px; transition: width 0.3s; text-align: center; line-height: 24px; color: white; font-size: 12px; }
.log-container { max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 12px; margin: 10px 0; }
.url-list { max-height: 300px; overflow-y: auto; }
.url-item { padding: 3px 8px; margin: 2px 0; border-radius: 3px; font-size: 12px; }
.url-local { background: #d4edda; }
.url-external { background: #fff3cd; }
.url-skipped { background: #f8d7da; }
</style>
</head>
<body>
<h1>📦 文章下载</h1>
<h2>文章: <?= htmlspecialchars($post['title']) ?> (ID: <?= $postId ?>)</h2>
<p class="log-info">安全验证通过 - <?= date('H:i:s') ?></p>

<div id="stepPrepare" class="step pending">📝 分析文章资源链接... <span class="log-info">等待</span></div>
<div id="stepProgress" class="step pending" style="display:none;">
  📋 复制资源文件
  <div class="progress-bar"><div id="progressFill" class="progress-fill" style="width:0%">0%</div></div>
  <div id="copyLog" class="log-container"></div>
</div>
<div id="stepZip" class="step pending" style="display:none;">📦 生成 ZIP 文件... <span class="log-info">等待</span></div>
<div id="stepResult" class="step pending" style="display:none;">🎯 最终结果... <span class="log-info">等待</span></div>

<div id="actions" style="margin: 20px 0; text-align: center;">
  <button id="btnStart" class="btn btn-primary" onclick="startProcess()">▶ 开始生成压缩包</button>
  <button id="btnCleanup" class="btn btn-danger" style="display:none;" onclick="cleanup()">🗑 清理临时文件</button>
  <button id="btnDownload" class="btn btn-info" style="display:none;" onclick="downloadZip()">📥 下载 ZIP 文件</button>
</div>

<script>
const postId = <?= $postId ?>;
let replacedUrls = {};

window.onload = function() {
    prepare();
};

function log(elId, msg, type) {
    const el = document.getElementById(elId);
    if (!el) return;
    const cls = type === 'success' ? 'log-success' : type === 'error' ? 'log-error' : type === 'warning' ? 'log-warning' : '';
    el.innerHTML += '<div class="' + cls + '">' + msg + '</div>';
}

function updateStep(id, status, content) {
    const el = document.getElementById(id);
    if (!el) return;
    el.className = 'step ' + status;
    if (content) el.innerHTML = content;
}

function prepare() {
    updateStep('stepPrepare', 'running', '📝 分析文章资源链接... <span class="log-info">分析中</span>');
    
    fetch('?id=' + postId + '&code=<?= urlencode($code) ?>&password=<?= urlencode($password) ?>&action=prepare')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                updateStep('stepPrepare', 'error', '📝 分析失败: <span class="log-error">' + data.error + '</span>');
                return;
            }
            
            let html = '<strong>📦 本地资源 (需打包 ' + data.localUrls.length + ' 个)</strong><div class="url-list">';
            data.localUrls.forEach(u => { html += '<div class="url-item url-local">✓ ' + htmlEscape(u) + '</div>'; });
            html += '</div>';
            
            if (data.externalUrls.length > 0) {
                html += '<strong>🌐 外部资源 (保持原链接 ' + data.externalUrls.length + ' 个)</strong><div class="url-list">';
                data.externalUrls.forEach(u => { html += '<div class="url-item url-external">⚡ ' + htmlEscape(u) + '</div>'; });
                html += '</div>';
            }
            
            if (data.skippedUrls.length > 0) {
                html += '<strong>⏭ 已跳过 ' + data.skippedUrls.length + ' 个</strong><div class="url-list">';
                data.skippedUrls.forEach(s => { html += '<div class="url-item url-skipped">✗ ' + htmlEscape(s.url) + ' (' + s.reason + ')</div>'; });
                html += '</div>';
            }
            
            updateStep('stepPrepare', 'success', '📝 文章资源分析完成<br>' + html);
            document.getElementById('btnStart').disabled = false;
        })
        .catch(err => {
            updateStep('stepPrepare', 'error', '📝 分析失败: <span class="log-error">' + err.message + '</span>');
        });
}

function startProcess() {
    document.getElementById('btnStart').disabled = true;
    
    fetch('?id=' + postId + '&code=<?= urlencode($code) ?>&password=<?= urlencode($password) ?>&action=start_copy')
        .then(r => r.json())
        .then(data => {
            if (!data.localUrls || data.localUrls.length === 0) {
                generateZip({});
                return;
            }
            
            const ps = document.getElementById('stepProgress');
            ps.style.display = 'block';
            ps.className = 'step running';
            
            const total = data.localUrls.length;
            let completed = 0, failed = 0;
            
            function copyNext(index) {
                if (index >= total) {
                    ps.className = 'step success';
                    log('copyLog', '复制完成: 成功 ' + completed + ' 个, 失败 ' + failed + ' 个', 'success');
                    generateZip(replacedUrls);
                    return;
                }
                
                const url = data.localUrls[index];
                fetch('?id=' + postId + '&code=<?= urlencode($code) ?>&password=<?= urlencode($password) ?>&action=copy_file&url=' + encodeURIComponent(url))
                    .then(r => r.json())
                    .then(result => {
                        if (result.success) {
                            replacedUrls[result.url] = result.file;
                            log('copyLog', '✓ ' + result.file + ' (' + Math.round(result.size/1024) + ' KB)', 'success');
                            completed++;
                        } else {
                            log('copyLog', '✗ ' + (result.error || '失败') + ' - ' + url, 'error');
                            failed++;
                        }
                        const pct = Math.round(((completed + failed) / total) * 100);
                        document.getElementById('progressFill').style.width = pct + '%';
                        document.getElementById('progressFill').textContent = pct + '% (' + (completed + failed) + '/' + total + ')';
                        copyNext(index + 1);
                    })
                    .catch(err => {
                        log('copyLog', '✗ 网络错误 - ' + url, 'error');
                        failed++;
                        copyNext(index + 1);
                    });
            }
            copyNext(0);
        });
}

function generateZip(replaced) {
    const zs = document.getElementById('stepZip');
    zs.style.display = 'block';
    zs.className = 'step running';
    zs.innerHTML = '📦 正在生成 ZIP 文件... <span class="log-info">处理中</span>';
    
    const fd = new FormData();
    fd.append('replaced_urls', JSON.stringify(replaced));
    
    fetch('?id=' + postId + '&code=<?= urlencode($code) ?>&password=<?= urlencode($password) ?>&action=generate_zip', {
        method: 'POST', body: fd
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                zs.className = 'step success';
                zs.innerHTML = '📦 ZIP 文件生成完成<br>' +
                    '- MD 文件: ' + data.mdSize + '<br>' +
                    '- 版权声明: ' + data.legalSize + '<br>' +
                    '- Assets: ' + data.assetsCount + ' 个文件<br>' +
                    '- ZIP 大小: ' + data.zipSize +
                    '<br><span class="log-info">⚠ 使用 AES-256 加密</span>';
                
                const rs = document.getElementById('stepResult');
                rs.style.display = 'block';
                rs.className = 'step success';
                rs.innerHTML = '<strong style="font-size:18px;color:#28a745;">✓ ZIP 文件生成成功！</strong><br><br>';
                
                // 标记验证码已使用
                fetch('?id=' + postId + '&code=<?= urlencode($code) ?>&password=<?= urlencode($password) ?>&action=mark_used');
                // 发送通知
                fetch('?id=' + postId + '&code=<?= urlencode($code) ?>&password=<?= urlencode($password) ?>&action=notify');
                
                document.getElementById('btnDownload').style.display = 'inline-block';
                document.getElementById('btnCleanup').style.display = 'inline-block';
            } else {
                zs.className = 'step error';
                zs.innerHTML = '📦 ZIP 生成失败: <span class="log-error">' + data.error + '</span>';
                document.getElementById('btnStart').disabled = false;
            }
        })
        .catch(err => {
            zs.className = 'step error';
            zs.innerHTML = '📦 ZIP 生成失败: <span class="log-error">' + err.message + '</span>';
            document.getElementById('btnStart').disabled = false;
        });
}

function downloadZip() {
    window.location.href = '?id=' + postId + '&code=<?= urlencode($code) ?>&password=<?= urlencode($password) ?>&action=download';
}

function cleanup() {
    fetch('?id=' + postId + '&code=<?= urlencode($code) ?>&password=<?= urlencode($password) ?>&action=cleanup')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('btnCleanup').style.display = 'none';
                document.getElementById('btnDownload').style.display = 'none';
                document.getElementById('stepResult').innerHTML += '<br><span class="log-success">✓ 临时文件已清理</span>';
                document.getElementById('btnStart').disabled = false;
            }
        });
}

function htmlEscape(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
</script>
</body>
</html>
<?php

// ==================== 辅助函数 ====================

function sendDownloadNotification($post, $postId, $studioConfig, $currentTime, $lastDownloadKey) {
    try {
        $adminEmail = $studioConfig['contact_email'] ?? '';
        if (empty($adminEmail) && defined('SMTP_USERNAME') && filter_var(SMTP_USERNAME, FILTER_VALIDATE_EMAIL)) {
            $adminEmail = SMTP_USERNAME;
        }
        if (empty($adminEmail)) return;
        
        $adminUsername = $_SESSION['admin_username'] ?? $_SESSION['user_username'] ?? '未知管理员';
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '未知IP';
        $ipLocation = getIpLocation($clientIP);
        $opTime = date('Y-m-d H:i:s');
        
        $logDir = __DIR__ . '/../logs/download';
        if (!is_dir($logDir)) mkdir($logDir, 0777, true);
        $logFile = $logDir . '/download_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, "[$opTime] User: $adminUsername, IP: $clientIP$ipLocation, Article: {$post['title']} (ID: $postId)\n", FILE_APPEND);
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
        $mail->addAddress($adminEmail);
        $mail->isHTML(true);
        $mail->Subject = '【安全提醒】管理员下载文章通知';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #eee; border-radius: 5px;'>
                <h3 style='color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px;'>管理员下载文章操作通知</h3>
                <p style='color: #666;'>系统检测到有管理员执行了文章下载操作，详情如下：</p>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>
                    <p style='margin: 5px 0;'><strong>操作人员：</strong> {$adminUsername}</p>
                    <p style='margin: 5px 0;'><strong>下载文章：</strong> " . htmlspecialchars($post['title']) . " (ID: {$postId})</p>
                    <p style='margin: 5px 0;'><strong>操作时间：</strong> {$opTime}</p>
                    <p style='margin: 5px 0;'><strong>操作IP：</strong> {$clientIP}{$ipLocation}</p>
                </div>
                <p style='color: #999; font-size: 12px; margin-top: 20px; border-top: 1px solid #eee; padding-top: 10px;'>
                    此邮件由系统自动发送，如果这不是您本人的操作，请立即检查账户安全。
                </p>
            </div>
        ";
        $mail->send();
        $_SESSION[$lastDownloadKey] = $currentTime;
    } catch (Exception $e) {
        error_log("下载通知邮件发送失败: " . $e->getMessage());
    }
}

function getIpLocation($clientIP) {
    if ($clientIP === '未知IP' || $clientIP === '127.0.0.1' || $clientIP === '::1') {
        return $clientIP === '127.0.0.1' || $clientIP === '::1' ? ' (本地)' : '';
    }
    $ipInfo = null;
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://whois.pconline.com.cn/ipJson.jsp?ip={$clientIP}&json=true");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && $response) {
            $response = mb_convert_encoding($response, 'UTF-8', 'GBK');
            $data = json_decode($response, true);
            if (!empty($data['addr'])) $ipInfo = $data['addr'];
            elseif (!empty($data['pro']) || !empty($data['city'])) $ipInfo = ($data['pro'] ?? '') . ' ' . ($data['city'] ?? '');
        }
    } catch (Exception $e) { error_log("pconline error: " . $e->getMessage()); }
    if (empty($ipInfo)) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "http://ip-api.com/json/{$clientIP}?lang=zh-CN");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            $response = curl_exec($ch);
            curl_close($ch);
            if ($response) {
                $data = json_decode($response, true);
                if (isset($data['status']) && $data['status'] === 'success') {
                    $ipInfo = ($data['country'] ?? '') . ' ' . ($data['regionName'] ?? '') . ' ' . ($data['city'] ?? '');
                }
            }
        } catch (Exception $e) { error_log("ip-api.com error: " . $e->getMessage()); }
    }
    return !empty($ipInfo) ? ' (' . trim($ipInfo) . ')' : '';
}

function extractAllLinks($content) {
    $allLinks = [];
    preg_match_all('/!\[.*?\]\((.*?)\)/', $content, $m); $allLinks = array_merge($allLinks, $m[1]);
    preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $m); $allLinks = array_merge($allLinks, $m[1]);
    preg_match_all('/<video[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $m); $allLinks = array_merge($allLinks, $m[1]);
    preg_match_all('/<source[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $m); $allLinks = array_merge($allLinks, $m[1]);
    preg_match_all('/<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $m); $allLinks = array_merge($allLinks, $m[1]);
    preg_match_all('/(?<!\!)\[.*?\]\((.*?)\)/', $content, $m); $allLinks = array_merge($allLinks, $m[1]);
    preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $m); $allLinks = array_merge($allLinks, $m[1]);
    return array_unique(array_filter($allLinks));
}

function generateLegalContent($postInfo, $studioConfig, $license) {
    $studioName = $studioConfig['website_name'] ?? 'SkytechStudio';
    $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $articleUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$serverName/blog.php?id=" . $postInfo['postId'];
    $licenseDescriptions = [
        'CC BY 4.0' => '允许他人自由共享、修改作品，但必须注明原作者姓名及来源',
        'CC BY-NC 4.0' => '允许他人非商业性使用、修改作品，必须注明原作者及来源，不得用于商业目的',
        'CC BY-SA 4.0' => '允许他人自由共享、修改作品，必须注明原作者，且衍生作品须采用相同许可协议',
        'CC BY-NC-SA 4.0' => '允许他人非商业性使用、修改作品，必须注明原作者，且衍生作品须采用相同许可协议，不得用于商业目的',
        'CC BY-ND 4.0' => '允许他人自由共享作品，但必须注明原作者及来源，不得对作品进行任何修改或衍生',
        'CC BY-NC-ND 4.0' => '允许他人非商业性共享作品，必须注明原作者及来源，不得修改、衍生或用于商业目的',
        'MIT' => '最宽松的开源许可，允许任何人以任何目的使用、复制、修改、合并、出版发行、散布、再授权及贩售软件的副本',
        'Apache-2.0' => '允许自由使用、修改和分发，要求保留版权声明和许可声明，提供专利授权，适用于大型商业项目',
        'GPL-3.0' => '强传染性开源协议，要求衍生作品也必须采用GPL协议，修改后的源码必须公开',
        'LGPL-3.0' => '较宽松的GPL，允许链接到库而不使整个程序受GPL约束，适用于库和组件',
        'BSD-3-Clause' => '宽松开源协议，允许使用、修改和分发，只需保留版权声明和免责条款，没有传染性',
        'ODbL' => '开放数据库许可，要求共享-相同方式，适用于数据库内容，如OpenStreetMap',
        'CC0 1.0' => '放弃所有版权，将作品完全置于公有领域，允许任何人以任何方式使用，无需署名',
        'PLOS' => 'PLOS期刊的开放获取许可，基于CC BY，允许自由使用、分发和改编，必须注明来源',
        'ArXiv' => 'arXiv预印本平台的许可协议，通常基于CC协议，促进学术成果的快速传播',
        'OGL' => '开放游戏许可，允许使用、修改和分发游戏内容，适用于桌面角色扮演游戏规则',
        'GFDL' => 'GNU自由文档许可，要求复制和修改时保留许可声明，适用于维基百科等文档',
        '无协议' => '保留所有版权，未经授权不得使用、复制、修改或分发'
    ];
    $licenseDesc = $licenseDescriptions[$license] ?? '未定义的许可协议';
    $content = "【版权声明】\n\n";
    $content .= "文章标题：{$postInfo['originalTitle']}\n";
    $content .= "文章作者：{$postInfo['author']}\n";
    $content .= "来源网站：{$studioName} ($serverName)\n";
    $content .= "文章链接：{$articleUrl}\n";
    $content .= "下载时间： " . date('Y-m-d H:i:s') . "\n\n";
    $content .= "【许可协议】\n";
    $content .= "本文章采用 [{$license}] 协议进行授权。\n";
    $content .= "协议说明：{$licenseDesc}\n\n";
    $content .= "【法律后果及责任声明】\n";
    $content .= "1. 著作权声明与侵权责任：\n";
    $content .= "   本资源受《中华人民共和国著作权法》、《中华人民共和国民法典》、《信息网络传播权保护条例》及国际版权公约的保护。\n";
    $content .= "   未经著作权人书面授权，任何单位或个人不得以任何方式（包括但不限于复制、转载、修改、通过信息网络传播、建立镜像、制作衍生品等）使用本资源。\n";
    $content .= "   对于侵犯著作权的行为，著作权人有权依法追究其法律责任，包括但不限于：\n";
    $content .= "   - 民事责任：停止侵害、消除影响、赔礼道歉、赔偿损失（包括但不限于实际损失、预期利益损失、维权成本、律师费、公证费等）。\n";
    $content .= "   - 行政责任：由著作权行政管理部门责令停止侵权行为，没收违法所得，没收、销毁侵权复制品，并可处以罚款。\n";
    $content .= "   - 刑事责任：情节严重，构成侵犯著作权罪的（如以营利为目的，违法所得数额较大或者有其他严重情节），将根据《中华人民共和国刑法》第二百一十七条等规定，依法追究刑事责任，最高可处七年有期徒刑并处罚金。\n\n";
    $content .= "2. 资源使用限制与禁止行为：\n";
    $content .= "   本压缩包内包含的所有资源（包括但不限于文字、图片、视频、音频、代码、数据等）仅供下载者个人非商业性质的学习、研究或欣赏使用。\n";
    $content .= "   使用者不得进行以下行为：\n";
    $content .= "   - 擅自将本资源用于任何商业用途（包括但不限于付费阅读、付费下载、广告植入、商业路演等）。\n";
    $content .= "   - 删除、掩盖或篡改本资源中包含的任何版权声明、作者署名、水印或其他权利标记。\n";
    $content .= "   - 对本资源进行反向工程、反向编译或反汇编（针对软件/代码类资源）。\n";
    $content .= "   - 将本资源用于任何违反法律法规、违背公序良俗或损害{$studioName}及第三方合法权益的活动。\n\n";
    $content .= "3. 许可终止：\n";
    $content .= "   如果使用者违反本声明的任何条款，其使用本资源的授权将自动终止。\n";
    $content .= "   使用者必须立即销毁其持有的所有本资源的副本（包括电子版和纸质版）。\n\n";
    $content .= "4. 兑责声明：\n";
    $content .= "   本资源按\"原样\"提供，{$studioName}不提供任何明示或暗示的保证（包括但不限于对适销性、特定用途适用性或不侵权的保证）。\n";
    $content .= "   {$studioName}不对因使用或无法使用本资源而导致的任何直接、间接、附带、特殊或后果性的损害（包括但不限于利润损失、业务中断、数据丢失等）承担责任，即使已被告知发生此类损害的可能性。\n";
    $content .= "   文章内容仅代表作者个人观点，不代表{$studioName}的官方立场。\n\n";
    $content .= "5. 法律适用与争议解决：\n";
    $content .= "   本声明的解释、效力及纠纷的解决，适用中华人民共和国法律。\n";
    $content .= "   若因本资源的使用发生争议，双方应友好协商解决；协商不成的，任何一方均有权向{$studioName}所在地的人民法院提起诉讼。\n\n";
    $content .= "--------------------------------------------------\n";
    $content .= "Copyright © " . date('Y') . " {$studioName}. All Rights Reserved.\n";
    return $content;
}

function createZipArchive($sourceDir, $zipFile, $password, $title) {
    $zip = new ZipArchive();
    $result = $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($result !== TRUE) {
        error_log("ZIP open failed: code=" . $result . ", file=" . $zipFile);
        return false;
    }

    $mdFile = $sourceDir . '/' . $title . '.md';
    if (file_exists($mdFile)) {
        $zip->addFile($mdFile, $title . '.md');
    }

    $legalFile = $sourceDir . '/版权声明.txt';
    if (file_exists($legalFile)) {
        $zip->addFile($legalFile, '版权声明.txt');
    }

    $assetsDir = $sourceDir . '/assets';
    $assetPaths = [];
    if (is_dir($assetsDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($assetsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = 'assets/' . basename($filePath);
                $zip->addFile($filePath, $relativePath);
                $assetPaths[] = $relativePath;
            }
        }
    }

    if (!empty($password) && method_exists($zip, 'setEncryptionName')) {
        $zip->setPassword($password);
        if (file_exists($mdFile)) $zip->setEncryptionName($title . '.md', ZipArchive::EM_AES_256);
        if (file_exists($legalFile)) $zip->setEncryptionName('版权声明.txt', ZipArchive::EM_AES_256);
        foreach ($assetPaths as $ap) {
            $zip->setEncryptionName($ap, ZipArchive::EM_AES_256);
        }
    }

    $closeResult = $zip->close();
    if (!$closeResult) {
        error_log("ZIP close failed for: " . $zipFile);
        return false;
    }
    if (!file_exists($zipFile)) {
        error_log("ZIP file not created: " . $zipFile);
        return false;
    }
    return true;
}

function deleteDirectory($dirPath) {
    if (!is_dir($dirPath)) return;
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        is_dir($file) ? deleteDirectory($file) : @unlink($file);
    }
    @rmdir($dirPath);
}
