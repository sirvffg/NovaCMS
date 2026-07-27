<?php
/**
 * IP 数据库下载更新脚本 (Web 版)
 * 页面秒开 → JS 按序 fetch → 实时显示下载大小/速度
 */

set_time_limit(300);
date_default_timezone_set('Asia/Shanghai');

$dataDir = __DIR__;

// ==================== AJAX 下载端点（NDJSON 流） ====================
if (isset($_GET['action'])) {
    @ini_set('output_buffering', 0);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache');

    $action = $_GET['action'];
    $target = $_GET['target'] ?? '';

    if ($action === 'download') {
        switch ($target) {
            case 'qqwry':
                $url  = 'https://github.com/nmgliangwei/qqwry.ipdb/releases/latest/download/qqwry.ipdb';
                $file = 'qqwry.ipdb'; $minSize = 1_000_000; break;
            case 'geocn1':
                $url  = 'https://github.com/ljxi/GeoCN/releases/latest/download/GeoCN.mmdb';
                $file = 'GeoCN.mmdb'; $minSize = 100_000; break;
            case 'geocn2':
                $url  = 'https://raw.githubusercontent.com/ljxi/GeoCN/main/db/GeoCN.mmdb';
                $file = 'GeoCN.mmdb'; $minSize = 100_000; break;
            case 'full':
                $url  = 'https://raw.githubusercontent.com/ljxi/GeoCN/main/data/full.txt';
                $file = 'full.txt';   $minSize = 1000; break;
            case 'short':
                $url  = 'https://raw.githubusercontent.com/ljxi/GeoCN/main/data/short.txt';
                $file = 'short.txt';  $minSize = 1000; break;
            default:
                ndjson(['event'=>'error','error'=>'未知目标']); exit;
        }

        streamDownload($url, $dataDir . '/' . $file, $minSize);
        exit;
    }

    if ($action === 'version') {
        $lines = [
            '更新时间: ' . date('Y-m-d H:i:s'),
            '===================================',
            '纯真: ' . (file_exists($dataDir . '/qqwry.ipdb') ? '已下载 ' . fmtsize(filesize($dataDir . '/qqwry.ipdb')) : '未下载'),
            'GeoCN: ' . (file_exists($dataDir . '/GeoCN.mmdb') ? '已下载 ' . fmtsize(filesize($dataDir . '/GeoCN.mmdb')) : '下载失败'),
            'full.txt: ' . (file_exists($dataDir . '/full.txt') ? '已下载 ' . fmtsize(filesize($dataDir . '/full.txt')) : '下载失败'),
            'short.txt: ' . (file_exists($dataDir . '/short.txt') ? '已下载 ' . fmtsize(filesize($dataDir . '/short.txt')) : '下载失败'),
            '===================================',
        ];
        file_put_contents($dataDir . '/version.txt', implode("\n", $lines) . "\n");
        ndjson(['event'=>'done', 'ok'=>true]);
        exit;
    }

    ndjson(['event'=>'error','error'=>'未知 action']); exit;
}

// ==================== 工具函数 ====================
function ndjson(array $data): void { echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n"; flush(); }

function fmtsize(int $bytes): string {
    if ($bytes >= 1_048_576) { $v = round($bytes / 1_048_576, 2); return strpos((string)$v, '.') === false ? $v . '.00 MB' : $v . ' MB'; }
    if ($bytes >= 1024)      return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function speedFmt(int $bytesPerSec): string {
    if ($bytesPerSec >= 1_048_576) return round($bytesPerSec / 1_048_576, 2) . ' MB/s';
    if ($bytesPerSec >= 1024)      return round($bytesPerSec / 1024, 2) . ' KB/s';
    return $bytesPerSec . ' B/s';
}

/**
 * 流式下载：通过 WRITEFUNCTION 逐块收集，每 ~200ms 输出一次进度
 */
function streamDownload(string $url, string $savePath, int $minSize): void {
    $buffer        = '';
    $downloaded    = 0;
    $lastEmit      = 0;
    $startTime     = microtime(true);
    $speedWindow   = []; // [timestamp => bytes]  用于计算瞬时速度

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => false,          // 启用 WRITEFUNCTION
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; IPDB-Updater/2.0)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_BUFFERSIZE     => 65536,          // 64KB 缓冲区,减少回调频率
        CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$buffer, &$downloaded, &$lastEmit, &$startTime, &$speedWindow): int {
            $len = strlen($chunk);
            $buffer     .= $chunk;
            $downloaded += $len;

            $now = microtime(true);
            // 速度采样窗口：记录最近 2 秒内的数据点
            $speedWindow[$now] = $downloaded;
            $cutoff = $now - 2.5;
            foreach ($speedWindow as $t => $v) { if ($t < $cutoff) unset($speedWindow[$t]); }

            // 每 200ms 输出进度
            if ($now - $lastEmit > 0.2) {
                $elapsed  = $now - $startTime;
                $avgSpeed = $elapsed > 0 ? (int)($downloaded / $elapsed) : 0;

                // 瞬时速度：用窗口首尾计算
                $keys = array_keys($speedWindow);
                $instSpeed = 0;
                if (count($keys) >= 2) {
                    $t1 = reset($keys); $b1 = reset($speedWindow);
                    $t2 = end($keys);   $b2 = end($speedWindow);
                    $dt = $t2 - $t1;
                    if ($dt > 0) $instSpeed = (int)(($b2 - $b1) / $dt);
                }

                ndjson([
                    'event'          => 'progress',
                    'downloaded'     => $downloaded,
                    'downloaded_fmt' => fmtsize($downloaded),
                    'speed'          => speedFmt($instSpeed ?: $avgSpeed),
                    'avg_speed'      => speedFmt($avgSpeed),
                ]);
                $lastEmit = $now;
            }
            return $len;
        },
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    $elapsed  = round(microtime(true) - $startTime, 3);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        ndjson(['event'=>'error', 'error'=>'cURL 失败: ' . ($error ?: 'HTTP ' . $httpCode)]);
        return;
    }

    if ($downloaded < $minSize) {
        ndjson(['event'=>'error', 'error'=>'文件太小 (' . fmtsize($downloaded) . ')，下载可能不完整']);
        return;
    }

    $md5 = md5($buffer);
    file_put_contents($savePath, $buffer);
    file_put_contents($savePath . '.md5', $md5 . '  ' . basename($savePath));

    $avgSpeed = $elapsed > 0 ? (int)($downloaded / $elapsed) : 0;
    ndjson([
        'event'      => 'done',
        'ok'         => true,
        'size'       => $downloaded,
        'size_fmt'   => fmtsize($downloaded),
        'speed'      => speedFmt($avgSpeed),
        'elapsed'    => $elapsed,
        'md5'        => substr($md5, 0, 16),
    ]);
}

// ==================== 主页面 HTML ====================
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IP 数据库更新</title>
<style>
:root{--bg:#f8f9fa;--card:#fff;--border:#e5e7eb;--green:#10b981;--red:#ef4444;--yellow:#d97706;--text:#1f2937;--muted:#6b7280;--blue:#2563eb}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",sans-serif;min-height:100vh;padding:24px}
.container{max-width:780px;margin:0 auto}
h1{font-size:1.5rem;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:8px}
.overall{display:flex;align-items:center;gap:20px;margin-bottom:24px;flex-wrap:wrap}
.overall .stat{font-size:.85rem;color:var(--muted)}
.overall .stat span{color:var(--text);font-weight:600}
.btn{display:inline-block;padding:8px 20px;background:var(--blue);color:#fff;border:none;border-radius:8px;font-size:.9rem;cursor:pointer;font-weight:500}
.btn:hover{opacity:.9}
.btn:disabled{opacity:.4;cursor:not-allowed}
.card-list{display:flex;flex-direction:column;gap:10px;margin-bottom:28px}
.card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 18px;box-shadow:0 1px 3px rgba(0,0,0,.04);transition:border-color .3s,box-shadow .3s}
.card.active{border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.card-header{display:flex;align-items:center;gap:10px;margin-bottom:6px}
.card-header .icon{font-size:1.1rem;width:26px;text-align:center;flex-shrink:0}
.card-header .name{font-weight:500;font-size:.92rem}
.card-header .status{margin-left:auto;font-size:.72rem;padding:2px 10px;border-radius:12px;white-space:nowrap;font-weight:500}
.st-pending{background:#f3f4f6;color:var(--muted)}
.st-working{background:#dbeafe;color:var(--blue)}
.st-ok{background:#d1fae5;color:#059669}
.st-fail{background:#fee2e2;color:#dc2626}
.st-skip{background:#fef3c7;color:var(--yellow)}
.card-body{font-size:.82rem;color:var(--text);line-height:1.6}
.card-body .live{display:flex;flex-wrap:wrap;gap:14px;align-items:center}
.card-body .live .kv{font-size:.82rem;color:var(--muted)}
.card-body .live .kv b{color:var(--text);font-weight:600}
.card-body .final{display:flex;flex-wrap:wrap;gap:10px}
.card-body .final .kv{color:var(--muted);font-size:.78rem}
.card-body .final .kv b{color:var(--text);font-weight:500}
.progress-wrap{width:100%;height:6px;background:var(--border);border-radius:3px;margin-top:8px;overflow:hidden}
.progress-bar{height:100%;width:0;background:var(--blue);border-radius:3px;transition:width .15s linear}
.summary{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.04);margin-top:4px}
.summary h3{font-size:1rem;margin-bottom:12px}
.summary table{width:100%;font-size:.84rem;border-collapse:collapse}
.summary td{padding:7px 6px;border-bottom:1px solid var(--border)}
.summary td:last-child{text-align:right}
.ok{color:var(--green);font-weight:600}
.fail{color:var(--red)}
footer{text-align:center;margin-top:28px;color:#9ca3af;font-size:.73rem}
@media(max-width:500px){body{padding:12px}}
</style>
</head>
<body>
<div class="container">
<h1>IP 数据库在线更新</h1>
<div class="overall">
  <div class="stat">状态: <span id="hStatus">就绪</span></div>
  <div class="stat">进度: <span id="hProgress">0/4</span></div>
  <div class="stat">耗时: <span id="hElapsed">0s</span></div>
  <button class="btn" id="btnGo" onclick="startAll()">▶ 开始更新</button>
</div>

<div class="card-list">
  <div class="card" id="c_qqwry">
    <div class="card-header">
      <span class="icon">⏳</span><span class="name">纯真标准版 (qqwry.ipdb)</span>
      <span class="status st-pending">等待中</span>
    </div>
    <div class="card-body">—</div>
    <div class="progress-wrap"><div class="progress-bar" id="b_qqwry"></div></div>
  </div>
  <div class="card" id="c_geocn">
    <div class="card-header">
      <span class="icon">⏳</span><span class="name">GeoCN 高精度 (GeoCN.mmdb)</span>
      <span class="status st-pending">等待中</span>
    </div>
    <div class="card-body">—</div>
    <div class="progress-wrap"><div class="progress-bar" id="b_geocn"></div></div>
  </div>
  <div class="card" id="c_full">
    <div class="card-header">
      <span class="icon">⏳</span><span class="name">行政区划全称 (full.txt)</span>
      <span class="status st-pending">等待中</span>
    </div>
    <div class="card-body">—</div>
    <div class="progress-wrap"><div class="progress-bar" id="b_full"></div></div>
  </div>
  <div class="card" id="c_short">
    <div class="card-header">
      <span class="icon">⏳</span><span class="name">行政区划简称 (short.txt)</span>
      <span class="status st-pending">等待中</span>
    </div>
    <div class="card-body">—</div>
    <div class="progress-wrap"><div class="progress-bar" id="b_short"></div></div>
  </div>
</div>

<div class="summary">
  <h3>📋 更新结果</h3>
  <table>
    <tr><td>纯真数据库 (qqwry.ipdb)</td>  <td id="r_qqwry">—</td></tr>
    <tr><td>GeoCN 数据库 (GeoCN.mmdb)</td> <td id="r_geocn">—</td></tr>
    <tr><td>行政区划全称 (full.txt)</td>   <td id="r_full">—</td></tr>
    <tr><td>行政区划简称 (short.txt)</td>  <td id="r_short">—</td></tr>
    <tr style="font-weight:600"><td>总计</td><td id="r_total">0 / 4 成功</td></tr>
  </table>
</div>

<footer>Powered by PHP & cURL · <?php echo date('Y-m-d H:i:s'); ?></footer>
</div>

<script>
var BASE = 'update.php?action=download&target=';
var okCnt = 0, total = 4, startTime = 0;

// ---- 卡片操作 ----
function setCard(id, icon, cls, bodyHtml) {
    var c = document.getElementById('c_'+id); if(!c) return;
    c.querySelector('.icon').textContent = icon;
    c.querySelector('.card-body').innerHTML = bodyHtml;
    var st = c.querySelector('.status');
    st.className = 'status st-' + cls;
    var t = {pending:'等待中',working:'下载中',ok:'完成',fail:'失败',skip:'跳过'};
    st.textContent = t[cls] || '';
}
function focusCard(id) {
    var all = document.querySelectorAll('.card.active');
    all.forEach(function(a){ a.classList.remove('active'); });
    var el = document.getElementById('c_'+id);
    if(el){ el.classList.add('active'); el.scrollIntoView({behavior:'smooth',block:'center'}); }
}
function setResult(id, ok) {
    var el = document.getElementById('r_'+id);
    el.className = ok ? 'ok' : 'fail';
    el.innerHTML = ok ? '✅' : '❌';
}
function tick() {
    document.getElementById('r_total').textContent = okCnt + ' / ' + total + ' 成功';
    document.getElementById('hProgress').textContent = okCnt + '/' + total;
    var e = startTime ? ((performance.now() - startTime)/1000).toFixed(1) : '0';
    document.getElementById('hElapsed').textContent = e + 's';
}

// ---- NDJSON 流式读取 ----
async function fetchStream(url, onProgress, onDone, onError) {
    var resp = await fetch(url, {cache:'no-store'});
    if (!resp.ok) { onError('HTTP ' + resp.status); return; }
    var reader = resp.body.getReader();
    var decoder = new TextDecoder();
    var buf = '';

    while (true) {
        var r = await reader.read();
        if (r.value) buf += decoder.decode(r.value, {stream:true});
        var lines = buf.split('\n');
        buf = lines.pop();
        for (var i = 0; i < lines.length; i++) {
            if (!lines[i]) continue;
            try { var d = JSON.parse(lines[i]); } catch(e) { continue; }
            if (d.event === 'progress') onProgress(d);
            else if (d.event === 'done')   { onDone(d); return; }
            else if (d.event === 'error')  { onError(d.error); return; }
        }
        if (r.done) break;
    }
    onError('连接意外关闭');
}

// ---- 单任务下载 ----
function downloadOne(id, target) {
    return new Promise(function(resolve) {
        var bar = document.getElementById('b_'+id);
        bar.style.width = '0%';
        bar.style.background = 'var(--blue)';

        fetchStream(BASE + target,
            // onProgress
            function(d) {
                setCard(id, '📥', 'working',
                    '<div class="live">' +
                    '<span class="kv">已下载: <b>' + d.downloaded_fmt + '</b></span>' +
                    '<span class="kv">速度: <b>' + d.speed + '</b></span>' +
                    '</div>');
                // 进度条：用平均速度估算百分比（假设 qqwry~200MB, geocn~80MB, 文本~500KB）
                var est = 0.95;
                if (id === 'qqwry')  est = Math.min(d.downloaded / 250_000_000, 0.95);
                else if (id === 'geocn') est = Math.min(d.downloaded / 80_000_000, 0.95);
                else est = 0.5;
                bar.style.width = (est * 100) + '%';
            },
            // onDone
            function(d) {
                bar.style.width = '100%';
                bar.style.background = 'var(--green)';
                setCard(id, '✅', 'ok',
                    '<div class="final">' +
                    '<span class="kv">大小: <b>' + d.size_fmt + '</b></span>' +
                    '<span class="kv">均速: <b>' + d.speed + '</b></span>' +
                    '<span class="kv">耗时: <b>' + d.elapsed + 's</b></span>' +
                    '<span class="kv">MD5: <b>' + d.md5 + '</b></span>' +
                    '</div>');
                okCnt++;
                setResult(id, true);
                tick();
                resolve(true);
            },
            // onError
            function(err) {
                bar.style.width = '100%';
                bar.style.background = 'var(--red)';
                setCard(id, '❌', 'fail', err);
                setResult(id, false);
                tick();
                resolve(false);
            }
        );
    });
}

// ---- GeoCN 双通道 ----
async function downloadGeoCN() {
    var id = 'geocn', bar = document.getElementById('b_geocn');
    bar.style.width = '0%'; bar.style.background = 'var(--blue)';
    focusCard(id);

    // 通道1
    setCard(id, '📥', 'working', '<div class="live"><span class="kv">通道1 (Release): 连接中…</span></div>');
    var r1 = await new Promise(function(r) {
        fetchStream(BASE + 'geocn1',
            function(d) {
                setCard(id, '📥', 'working',
                    '<div class="live">' +
                    '<span class="kv">通道1: 已下载 <b>' + d.downloaded_fmt + '</b></span>' +
                    '<span class="kv">速度 <b>' + d.speed + '</b></span>' +
                    '</div>');
                var est = Math.min(d.downloaded / 80_000_000, 0.95);
                bar.style.width = (est * 100) + '%';
            },
            function(d) {
                bar.style.width = '100%'; bar.style.background = 'var(--green)';
                setCard(id, '✅', 'ok',
                    '<div class="final">' +
                    '<span class="kv">大小: <b>' + d.size_fmt + '</b></span>' +
                    '<span class="kv">均速: <b>' + d.speed + '</b></span>' +
                    '<span class="kv">耗时: <b>' + d.elapsed + 's</b></span>' +
                    '<span class="kv">MD5: <b>' + d.md5 + '</b></span>' +
                    '<span class="kv" style="color:var(--green)">通道1 成功</span>' +
                    '</div>');
                r(d);
            },
            function() { r(null); }
        );
    });

    if (r1) { okCnt++; setResult(id, true); tick(); return; }

    // 通道2
    bar.style.width = '0%'; bar.style.background = 'var(--yellow)';
    setCard(id, '📥', 'working', '<div class="live"><span class="kv">通道1 失败，切换通道2 (Raw): 连接中…</span></div>');

    var r2 = await new Promise(function(r) {
        fetchStream(BASE + 'geocn2',
            function(d) {
                setCard(id, '📥', 'working',
                    '<div class="live">' +
                    '<span class="kv">通道2: 已下载 <b>' + d.downloaded_fmt + '</b></span>' +
                    '<span class="kv">速度 <b>' + d.speed + '</b></span>' +
                    '</div>');
                var est = Math.min(d.downloaded / 80_000_000, 0.95);
                bar.style.width = (est * 100) + '%';
            },
            function(d) {
                bar.style.width = '100%'; bar.style.background = 'var(--green)';
                setCard(id, '✅', 'ok',
                    '<div class="final">' +
                    '<span class="kv">大小: <b>' + d.size_fmt + '</b></span>' +
                    '<span class="kv">均速: <b>' + d.speed + '</b></span>' +
                    '<span class="kv">耗时: <b>' + d.elapsed + 's</b></span>' +
                    '<span class="kv">MD5: <b>' + d.md5 + '</b></span>' +
                    '<span class="kv" style="color:var(--yellow)">通道2 成功</span>' +
                    '</div>');
                r(d);
            },
            function(err) {
                bar.style.width = '100%'; bar.style.background = 'var(--red)';
                setCard(id, '❌', 'fail', err || '双通道均下载失败');
                r(null);
            }
        );
    });

    if (r2) { okCnt++; setResult(id, true); } else { setResult(id, false); }
    tick();
}

function sleep(ms) { return new Promise(function(r){ setTimeout(r, ms); }); }

// ---- 主流程 ----
async function startAll() {
    document.getElementById('btnGo').disabled = true;
    document.getElementById('hStatus').textContent = '运行中';
    startTime = performance.now();
    okCnt = 0; total = 4;
    tick();

    await downloadOne('qqwry', 'qqwry');
    await sleep(500);

    await downloadGeoCN();
    await sleep(500);

    await downloadOne('full', 'full');
    await sleep(500);

    await downloadOne('short', 'short');

    // 生成 version.txt
    try { await fetch(BASE + 'version', {cache:'no-store'}); } catch(e){}

    document.getElementById('hStatus').textContent = '完成';
    document.getElementById('btnGo').textContent = '✅ 更新完成';
    var e = ((performance.now() - startTime)/1000).toFixed(1);
    document.getElementById('hElapsed').textContent = e + 's';
}
</script>
</body>
</html>
