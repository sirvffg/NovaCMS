<?php
defined('NOVA_API') or exit('禁止直接访问');

require_once dirname(__DIR__, 4) . '/config/database.php';

$backup = new Backup_Core();
$backupList = $backup->getBackupList();
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1"><i class="bi bi-cloud-download me-2"></i>备份管理</h4>
                    <p class="text-muted mb-0">管理网站文件和数据库备份</p>
                </div>
                <button class="btn btn-primary" onclick="createBackup()">
                    <i class="bi bi-plus-circle me-1"></i>创建备份
                </button>
            </div>
        </div>
    </div>

    <!-- 统计卡片 -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-muted small">备份总数</div>
                            <div class="h3 mb-0"><?= $backupList['count'] ?></div>
                        </div>
                        <div class="text-primary fs-1">
                            <i class="bi bi-archive"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-muted small">最新备份</div>
                            <div class="h6 mb-0"><?= !empty($backupList['backups']) ? $backupList['backups'][0]['created_at'] : '暂无备份' ?></div>
                        </div>
                        <div class="text-success fs-1">
                            <i class="bi bi-clock"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-muted small">总大小</div>
                            <div class="h6 mb-0">
                                <?php
                                $totalSize = 0;
                                foreach ($backupList['backups'] as $b) {
                                    $size = floatval(explode(' ', $b['size'])[0]);
                                    $unit = explode(' ', $b['size'])[1];
                                    if ($unit === 'GB') $size *= 1024;
                                    if ($unit === 'MB') $size *= 1024;
                                    if ($unit === 'KB') $size *= 1024;
                                    $totalSize += $size;
                                }
                                echo round($totalSize / 1024 / 1024, 2) . ' MB';
                                ?>
                            </div>
                        </div>
                        <div class="text-info fs-1">
                            <i class="bi bi-hdd"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 备份列表 -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">备份列表</h6>
                <?php if (!empty($backupList['backups'])): ?>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteAllBackups()">
                    <i class="bi bi-trash me-1"></i>删除全部
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($backupList['backups'])): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1"></i>
                <p class="mt-3 mb-0">暂无备份，点击"创建备份"按钮开始</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>文件名</th>
                            <th>大小</th>
                            <th>创建时间</th>
                            <th width="150">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backupList['backups'] as $backup): ?>
                        <tr>
                            <td>
                                <i class="bi bi-file-zip text-primary me-2"></i>
                                <?= htmlspecialchars($backup['filename']) ?>
                            </td>
                            <td><span class="badge bg-secondary"><?= $backup['size'] ?></span></td>
                            <td><?= $backup['created_at'] ?></td>
                            <td>
                                <a href="<?= $backup['download_url'] ?>" class="btn btn-sm btn-outline-primary" download>
                                    <i class="bi bi-download"></i>
                                </a>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteBackup('<?= $backup['filename'] ?>')">
                                    <i class="bi bi-trash"></i>
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

<script>
function createBackup() {
    if (!confirm('确定要创建新备份吗？这可能需要几秒钟时间。')) return;

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>正在备份...';

    fetch('/nova-json/v1/backup/create', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'}
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plus-circle me-1"></i>创建备份';

        if (data.success) {
            alert('备份创建成功！\n文件: ' + data.file + '\n大小: ' + data.size);
            location.reload();
        } else {
            alert('备份失败: ' + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plus-circle me-1"></i>创建备份';
        alert('请求失败: ' + err);
    });
}

function deleteBackup(filename) {
    if (!confirm('确定要删除备份 ' + filename + ' 吗？')) return;

    fetch('/nova-json/v1/backup/delete?filename=' + encodeURIComponent(filename), {
        method: 'DELETE'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('删除成功');
            location.reload();
        } else {
            alert('删除失败: ' + data.message);
        }
    })
    .catch(err => alert('请求失败: ' + err));
}

function deleteAllBackups() {
    if (!confirm('确定要删除全部备份吗？此操作不可恢复！')) return;

    fetch('/nova-json/v1/backup/delete?filename=all', {
        method: 'DELETE'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('删除失败: ' + data.message);
        }
    })
    .catch(err => alert('请求失败: ' + err));
}
</script>