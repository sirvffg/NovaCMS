<?php
/**
 * Cron Manager 详情页内容（plugins.php?plugin=cron-manager 的"定时任务"标签）
 *
 * 由 admin/plugins.php 在插件详情页通过 plugin.json 的 detail_tab 字段加载。
 * 职责：
 *   1. 加载所有已启用插件并触发 nova_init，使插件注册 cron 任务
 *   2. 展示已注册任务及其执行状态（查看注册的服务）
 *   3. 支持手动执行单个任务（AJAX 调用 plugins.php 的 run_cron_task 动作，强制执行忽略间隔）
 *   4. 展示当前执行模式与服务器模式配置说明
 *
 * 执行模式切换在本页"执行模式"配置 Tab（声明式 config.json）。
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

// ── 加载依赖类（admin 上下文未加载 Nova_Cron / Nova_Hooks / Nova_Plugin）──
$novaClassDir = dirname(__DIR__, 4) . '/nova-json/class';
require_once $novaClassDir . '/system/class-hooks.php';
require_once $novaClassDir . '/system/class-cron.php';
require_once $novaClassDir . '/database/class-db.php';
require_once $novaClassDir . '/rest/class-server.php';
require_once $novaClassDir . '/plugin/class-plugin.php';
require_once __DIR__ . '/../class-cron-manager.php';

// ── 加载所有已启用插件并触发 nova_init，让插件注册 cron 任务 ──
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
    error_log('[Cron_Manager detail] plugin bootstrap failed: ' . $e->getMessage());
}

// ── 读取当前状态 ──
$mode  = Cron_Manager_Plugin::get_config_value('mode', Cron_Manager_Plugin::DEFAULT_MODE);
$tasks = Cron_Manager_Plugin::get_tasks_with_status();
$csrf  = $detailCsrfToken;
?>

<div class="row g-3">
    <!-- 当前执行模式 -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <h6 class="mb-1"><i class="bi bi-toggles me-1"></i>当前执行模式</h6>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-<?= $mode === 'server' ? 'primary' : 'info' ?> fs-6">
                                <?= $mode === 'server' ? '服务器模式' : '虚拟面板模式' ?>
                            </span>
                            <span class="text-muted small">
                                <?= $mode === 'server'
                                    ? '访问触发已关闭，由面板定时任务调用 cron.php'
                                    : '访问触发已启用，由访客请求异步触发' ?>
                            </span>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.querySelectorAll('.plugin-detail-tab').forEach(function(t){ if(t.dataset.tab==='config-0') t.click(); });">
                        <i class="bi bi-sliders me-1"></i>切换模式
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 已注册任务 -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
                <span><i class="bi bi-list-task me-1"></i>已注册任务</span>
                <span class="badge bg-light text-dark"><?= count($tasks) ?> 个</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($tasks)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        暂无已注册任务
                        <div class="small mt-1">插件可在 <code>init()</code> 中调用 <code>Nova_Cron::register()</code> 注册周期性任务</div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>任务 ID</th>
                                    <th>描述</th>
                                    <th>间隔</th>
                                    <th>上次执行</th>
                                    <th>状态</th>
                                    <th class="text-end">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $t): ?>
                                    <tr>
                                        <td><code><?= e($t['id']) ?></code><?= $t['is_due'] ? ' <span class="badge bg-warning-subtle text-warning-emphasis small">到期</span>' : '' ?></td>
                                        <td><?= e($t['description']) ?></td>
                                        <td><span class="text-muted"><?= e($t['interval']) ?></span></td>
                                        <td><span class="text-muted small"><?= e($t['last_run_at']) ?></span></td>
                                        <td>
                                            <?php
                                                $st = $t['last_status'];
                                                $badge = ['success' => 'success', 'failed' => 'danger', '—' => 'secondary'];
                                                $cls = $badge[$st] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $cls ?>-subtle text-<?= $cls ?>-emphasis"><?= e($st) ?></span>
                                            <?php if ($t['last_error']): ?>
                                                <i class="bi bi-exclamation-circle text-danger ms-1" data-bs-toggle="tooltip" data-bs-title="<?= e($t['last_error']) ?>"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-run-task" data-task-id="<?= e($t['id']) ?>">
                                                <i class="bi bi-play-fill"></i> 执行
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 服务器模式配置说明 -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent">
                <i class="bi bi-terminal me-1"></i>服务器模式配置说明
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">在宝塔 / 1Panel 后台创建定时任务，二选一。建议执行频率每 1~5 分钟——未到期任务会快速跳过，无副作用。</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Shell 脚本（推荐）</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" readonly value="php <?= e(dirname(__DIR__, 4)) ?>/public/cron/cron.php">
                            <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">访问 URL</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" readonly value="<?= e($_SERVER['REQUEST_SCHEME'] ?? 'https') ?>://<?= e($_SERVER['HTTP_HOST'] ?? '域名') ?>/vendor/public/cron/cron.php">
                            <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="alert alert-info mt-3 mb-0 small">
                    <i class="bi bi-info-circle me-1"></i>
                    <?= $mode === 'server'
                        ? '当前为服务器模式：访问触发已关闭，必须配置面板定时任务，否则任务不会执行。'
                        : '当前为虚拟面板模式：无需配置面板定时任务，由访客请求自动触发。仍可配置面板定时任务作为兜底。' ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/pjax-script">
(function() {
    // 初始化 tooltip
    if (window.bootstrap) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
            new bootstrap.Tooltip(el);
        });
    }

    // 手动执行任务（AJAX 调用 plugins.php 的 run_cron_task 动作）
    document.querySelectorAll('.btn-run-task').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var taskId = btn.dataset.taskId;
            if (!confirm('确定立即执行任务 ' + taskId + '？')) return;
            var formData = new FormData();
            formData.append('action', 'run_cron_task');
            formData.append('csrf_token', '<?= e($csrf) ?>');
            formData.append('task_id', taskId);
            var orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            fetch('plugins.php?plugin=cron-manager', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:formData })
            .then(function(r){ return r.json(); })
            .then(function(data){
                var t = document.createElement('div');
                t.className = 'position-fixed top-0 start-50 translate-middle-x mt-3 p-2 rounded shadow-lg';
                t.style.cssText = 'z-index:9999;background:' + (data.ok ? '#198754' : '#dc3545') + ';color:#fff;font-size:.875rem;';
                t.textContent = data.msg || (data.ok ? '执行成功' : '执行失败');
                document.body.appendChild(t);
                setTimeout(function(){ t.remove(); }, 2500);
                // 执行成功后通过 PJAX 刷新当前页以更新任务状态
                if (data.ok) {
                    setTimeout(function(){
                        var link = document.createElement('a');
                        link.href = 'plugins.php?plugin=cron-manager';
                        link.style.display = 'none';
                        document.body.appendChild(link);
                        link.click();
                        link.remove();
                    }, 800);
                }
            })
            .catch(function(){
                var t = document.createElement('div');
                t.className = 'position-fixed top-0 start-50 translate-middle-x mt-3 p-2 rounded shadow-lg';
                t.style.cssText = 'z-index:9999;background:#dc3545;color:#fff;font-size:.875rem;';
                t.textContent = '网络错误，请重试';
                document.body.appendChild(t);
                setTimeout(function(){ t.remove(); }, 2500);
            })
            .finally(function(){
                btn.disabled = false;
                btn.innerHTML = orig;
            });
        });
    });
})();
</script>
