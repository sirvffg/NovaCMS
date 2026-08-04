<?php
session_start();

// 如果未登录，重定向到登录页
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 辅助函数
function getDirectorySize($path) {
    $bytestotal = 0;
    $path = realpath($path);
    if ($path !== false && $path != '' && file_exists($path)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $object) {
            $bytestotal += $object->getSize();
        }
    }
    return $bytestotal;
}

function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

function getFileIcon($extension, $isDir = false) {
    if ($isDir) return 'bi-folder-fill text-warning';
    
    $icons = [
        'pdf' => 'bi-file-earmark-pdf text-danger',
        'doc' => 'bi-file-earmark-word text-primary',
        'docx' => 'bi-file-earmark-word text-primary',
        'xls' => 'bi-file-earmark-excel text-success',
        'xlsx' => 'bi-file-earmark-excel text-success',
        'ppt' => 'bi-file-earmark-ppt text-warning',
        'pptx' => 'bi-file-earmark-ppt text-warning',
        'txt' => 'bi-file-earmark-text text-secondary',
        'zip' => 'bi-file-earmark-zip text-info',
        'rar' => 'bi-file-earmark-zip text-info',
        '7z' => 'bi-file-earmark-zip text-info',
        'jpg' => 'bi-file-image text-primary',
        'jpeg' => 'bi-file-image text-primary',
        'png' => 'bi-file-image text-primary',
        'gif' => 'bi-file-image text-primary',
        'svg' => 'bi-file-image text-primary',
        'mp4' => 'bi-file-play text-danger',
        'mp3' => 'bi-file-music text-info',
        'wav' => 'bi-file-music text-info',
    ];
    return $icons[$extension] ?? 'bi-file-earmark text-secondary';
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) return true;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    return rmdir($dir);
}

// 初始化路径
$uploadDir = realpath(dirname(__DIR__) . '/uploads');
if (!$uploadDir) {
    mkdir(dirname(__DIR__) . '/uploads', 0755, true);
    $uploadDir = realpath(dirname(__DIR__) . '/uploads');
}

// 修改为允许显示所有真实存在的目录
$rootDirectories = [];
$defaultDirectories = ['files', 'images', 'videos', 'audio', 'logo'];

if (is_dir($uploadDir)) {
    $scanItems = scandir($uploadDir);
    foreach ($scanItems as $item) {
        if ($item === '.' || $item === '..' || $item === '.htaccess') continue;
        if (is_dir($uploadDir . '/' . $item)) {
            $rootDirectories[] = $item;
        }
    }
}

// 确保基础目录存在
foreach ($defaultDirectories as $rd) {
    $rdPath = $uploadDir . '/' . $rd;
    if (!is_dir($rdPath)) {
        mkdir($rdPath, 0755, true);
    }
    if (!in_array($rd, $rootDirectories)) {
        $rootDirectories[] = $rd;
    }
}

$currentDirParam = $_GET['dir'] ?? '';
$targetPath = realpath($uploadDir . '/' . $currentDirParam);

// 安全检查：防止目录穿越
if ($targetPath === false || strpos($targetPath, $uploadDir) !== 0) {
    $targetPath = $uploadDir;
    $currentDirParam = '';
}

// 安全检查：强制只能访问允许的根目录
if ($currentDirParam !== '') {
    $valid = false;
    foreach ($rootDirectories as $rd) {
        if (strpos($currentDirParam, $rd) === 0) {
            $valid = true;
            break;
        }
    }
    // 如果不是在某个根目录下，但它本身就是一个根目录，也算作合法
    if (!$valid && is_dir($uploadDir . '/' . $currentDirParam) && in_array($currentDirParam, $rootDirectories)) {
         $valid = true;
    }

    if (!$valid) {
        $targetPath = $uploadDir;
        $currentDirParam = '';
    }
}

// 处理 POST 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'delete' && isset($_POST['path'])) {
        $itemToDelete = realpath($uploadDir . '/' . $_POST['path']);
        if ($itemToDelete !== false && strpos($itemToDelete, $uploadDir) === 0 && $itemToDelete !== $uploadDir) {
            // 不能删除基础目录
            $isRootDir = false;
            foreach ($defaultDirectories as $rd) {
                if ($itemToDelete === realpath($uploadDir . '/' . $rd)) {
                    $isRootDir = true;
                    break;
                }
            }
            
            if ($isRootDir) {
                $_SESSION['error'] = '系统基础目录不可删除';
            } else {
                if (is_dir($itemToDelete)) {
                    if (deleteDirectory($itemToDelete)) {
                        $_SESSION['success'] = '文件夹删除成功';
                    } else {
                        $_SESSION['error'] = '文件夹删除失败';
                    }
                } else {
                    if (unlink($itemToDelete)) {
                        $_SESSION['success'] = '文件删除成功';
                    } else {
                        $_SESSION['error'] = '文件删除失败';
                    }
                }
            }
        } else {
            $_SESSION['error'] = '无效的操作路径';
        }
    } elseif ($action === 'save_file' && isset($_POST['path']) && isset($_POST['content'])) {
        $filePath = realpath($uploadDir . '/' . $_POST['path']);
        if ($filePath !== false && strpos($filePath, $uploadDir) === 0 && is_file($filePath)) {
            $content = $_POST['content'];
            if (file_put_contents($filePath, $content) !== false) {
                $_SESSION['success'] = '文件保存成功';
            } else {
                $_SESSION['error'] = '文件保存失败，请检查权限';
            }
        } else {
            $_SESSION['error'] = '无效的文件路径';
        }
    } elseif ($action === 'create_folder' && !empty($_POST['folder_name'])) {
        $folderName = trim($_POST['folder_name']);
        if (preg_match('/^[a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]+$/u', $folderName)) {
            $newPath = $targetPath . '/' . $folderName;
            if (!file_exists($newPath)) {
                if (mkdir($newPath, 0755)) {
                    $_SESSION['success'] = '文件夹创建成功';
                } else {
                    $_SESSION['error'] = '文件夹创建失败，请检查权限';
                }
            } else {
                $_SESSION['error'] = '文件夹已存在';
            }
        } else {
            $_SESSION['error'] = '文件夹名称包含非法字符';
        }
    } elseif ($action === 'rename' && !empty($_POST['old_name']) && !empty($_POST['new_name'])) {
        $oldName = trim($_POST['old_name']);
        $newName = trim($_POST['new_name']);
        if (preg_match('/^[a-zA-Z0-9_\-\.\x{4e00}-\x{9fa5}]+$/u', $newName)) {
            $oldPath = realpath($targetPath . '/' . $oldName);
            $newPath = $targetPath . '/' . $newName;
            
            // 不能重命名基础目录
            $isRootDir = false;
            foreach ($defaultDirectories as $rd) {
                if ($oldPath === realpath($uploadDir . '/' . $rd)) {
                    $isRootDir = true;
                    break;
                }
            }
            
            if ($isRootDir) {
                $_SESSION['error'] = '系统基础目录不可重命名';
            } elseif ($oldPath !== false && strpos($oldPath, $targetPath) === 0 && !file_exists($newPath)) {
                if (rename($oldPath, $newPath)) {
                    $_SESSION['success'] = '重命名成功';
                } else {
                    $_SESSION['error'] = '重命名失败';
                }
            } else {
                $_SESSION['error'] = '文件不存在或新名称已存在';
            }
        } else {
            $_SESSION['error'] = '新名称包含非法字符';
        }
    }
    
    $redirectUrl = 'files.php' . ($currentDirParam ? '?dir=' . urlencode($currentDirParam) : '');
    header('Location: ' . $redirectUrl);
    exit;
}

// 读取目录内容
$items = [];
if (is_dir($targetPath)) {
    $scanItems = scandir($targetPath);
    foreach ($scanItems as $item) {
        if ($item === '.' || $item === '..' || $item === '.htaccess') continue;
        
        $itemPath = $targetPath . '/' . $item;
        $isDir = is_dir($itemPath);
        $relPath = ltrim($currentDirParam . '/' . $item, '/');
        $url = '/uploads/' . $relPath;
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));

        $items[] = [
            'name' => $item,
            'is_dir' => $isDir,
            'size' => $isDir ? getDirectorySize($itemPath) : filesize($itemPath),
            'modified' => filemtime($itemPath),
            'type' => $isDir ? 'directory' : $ext,
            'relative_path' => $relPath,
            'url' => $url,
            'is_editable' => !$isDir && in_array($ext, ['txt', 'md', 'html', 'css', 'js', 'json', 'xml', 'php', 'log', 'csv'])
        ];
    }
}

// 排序：文件夹优先，然后按名称升序
usort($items, function($a, $b) {
    if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
});

$totalSize = 0;
// 面包屑导航
$breadcrumb = [['name' => '根目录', 'url' => 'files.php']];
if ($currentDirParam) {
    $parts = explode('/', trim($currentDirParam, '/'));
    $buildPath = '';
    foreach ($parts as $part) {
        $buildPath .= ($buildPath ? '/' : '') . $part;
        $breadcrumb[] = ['name' => $part, 'url' => 'files.php?dir=' . urlencode($buildPath)];
    }
}
$totalSize = getDirectorySize($targetPath);
$page_title = '文件管理';
$extra_css = <<<'CSS'
.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,.02);
}
.card {
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    transition: all 0.3s ease;
}
.file-icon {
    font-size: 1.5rem;
    width: 40px;
    text-align: center;
}
.action-btn {
    opacity: 0;
    transition: opacity 0.2s;
}
tr:hover .action-btn {
    opacity: 1;
}
#dropZone {
    border: 2px dashed #dee2e6;
    transition: all 0.3s ease;
    cursor: pointer;
}
#dropZone:hover, #dropZone.drag-over {
    background-color: #f8f9fa;
    border-color: #0d6efd;
}
a.folder-link {
    text-decoration: none;
    color: #212529;
    font-weight: 600;
}
a.folder-link:hover {
    color: #0d6efd;
    text-decoration: underline;
}
CSS;
require_once 'includes/header.php'; ?>

                
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-1">文件管理</h1>
                        <p class="text-muted mb-2">
                            当前目录总大小：<strong><?= formatFileSize($totalSize) ?></strong>
                        </p>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-0">
                                <?php foreach ($breadcrumb as $index => $crumb): ?>
                                    <?php if ($index === count($breadcrumb) - 1): ?>
                                        <li class="breadcrumb-item active" aria-current="page"><?= e($crumb['name']) ?></li>
                                    <?php else: ?>
                                        <li class="breadcrumb-item"><a href="<?= $crumb['url'] ?>"><?= e($crumb['name']) ?></a></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ol>
                        </nav>
                    </div>
                    <div class="btn-group">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                            <i class="bi bi-cloud-upload me-1"></i> 上传文件
                        </button>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createFolderModal" <?= $currentDirParam === '' ? 'disabled title="请先进入具体分类目录再创建文件夹"' : '' ?>>
                            <i class="bi bi-folder-plus me-1"></i> 新建文件夹
                        </button>
                        <?php if ($currentDirParam): ?>
                        <a href="files.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-return-left me-1"></i> 返回根目录
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm">
                    <i class="bi bi-check-circle-fill me-2"></i> <?= $_SESSION['success'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm">
                    <i class="bi bi-exclamation-circle-fill me-2"></i> <?= $_SESSION['error'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>

                <?php if ($currentDirParam !== ''): ?>
                <div class="card mb-4">
                    <div class="card-body p-0">
                        <div id="dropZone" class="p-5 text-center rounded-3">
                            <div class="mb-3">
                                <i class="bi bi-cloud-arrow-up text-primary" style="font-size: 3rem;"></i>
                            </div>
                            <h5 class="mb-2">点击或拖拽文件到此处上传</h5>
                            <p class="text-muted small mb-0">将上传至当前目录：<strong><?= e($currentDirParam) ?></strong>，最大支持 50MB</p>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle-fill me-2"></i> 请先点击进入下方对应的分类文件夹（如 images、videos），然后再进行文件上传或创建子文件夹。
                </div>
                <?php endif; ?>
                
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">文件列表</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($items)): ?>
                        <div class="text-center py-5">
                            <div class="text-muted">
                                <i class="bi bi-folder2-open display-4 d-block mb-3"></i>
                                此文件夹为空
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">名称</th>
                                        <th>大小</th>
                                        <th>修改时间</th>
                                        <th class="text-end pe-4">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // 预先声明并合并所有的根目录，避免在根目录显示不全的问题
                                    if ($currentDirParam === '') {
                                        $displayItems = [];
                                        // 确保所有的根目录都被显示，即使有的文件夹在某些情况下没被扫描到
                                        foreach ($defaultDirectories as $rd) {
                                            $found = false;
                                            foreach ($items as $item) {
                                                if ($item['name'] === $rd && $item['is_dir']) {
                                                    $found = true;
                                                    break;
                                                }
                                            }
                                            if (!$found) {
                                                $rdPath = $uploadDir . '/' . $rd;
                                                $displayItems[] = [
                                                    'name' => $rd,
                                                    'is_dir' => true,
                                                    'size' => is_dir($rdPath) ? getDirectorySize($rdPath) : 0,
                                                    'modified' => is_dir($rdPath) ? filemtime($rdPath) : time(),
                                                    'type' => 'directory',
                                                    'relative_path' => $rd,
                                                    'url' => '/uploads/' . $rd,
                                                    'is_editable' => false
                                                ];
                                            }
                                        }
                                        
                                        // 把其他非默认文件夹或文件也加入进来
                                        foreach ($items as $item) {
                                            $displayItems[] = $item;
                                        }
                                        
                                        // 去重并重新排序
                                        $uniqueNames = [];
                                        $finalItems = [];
                                        foreach ($displayItems as $item) {
                                            if (!in_array($item['name'], $uniqueNames)) {
                                                $uniqueNames[] = $item['name'];
                                                $finalItems[] = $item;
                                            }
                                        }
                                        
                                        usort($finalItems, function($a, $b) {
                                            if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
                                            return strcasecmp($a['name'], $b['name']);
                                        });
                                        
                                        $items = $finalItems;
                                    }
                                    
                                    foreach ($items as $item): 
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <div class="file-icon me-3">
                                                    <i class="bi <?= getFileIcon($item['type'], $item['is_dir']) ?>"></i>
                                                </div>
                                                <div>
                                                    <?php if ($item['is_dir']): ?>
                                                        <a href="files.php?dir=<?= urlencode($item['relative_path']) ?>" class="folder-link">
                                                            <?= e($item['name']) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <div class="fw-bold text-dark"><?= e($item['name']) ?></div>
                                                    <?php endif; ?>
                                                    <div class="small text-muted">
                                                        <?= $item['is_dir'] ? '文件夹' : strtoupper($item['type']) . ' 文件' ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <?= formatFileSize($item['size']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="small text-muted">
                                                <i class="bi bi-clock me-1"></i>
                                                <?= date('Y-m-d H:i', $item['modified']) ?>
                                            </div>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-group action-btn">
                                                <?php if (!$item['is_dir']): ?>
                                                    <?php if (isset($item['is_editable']) && $item['is_editable']): ?>
                                                    <button class="btn btn-sm btn-light text-success" onclick="editFile('<?= e($item['relative_path']) ?>', '<?= e($item['name']) ?>')" title="编辑文件">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if (in_array($item['type'], ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm', 'ogg', 'mp3', 'wav'])): ?>
                                                    <button class="btn btn-sm btn-light text-primary" onclick="previewMedia('<?= e($item['url']) ?>', '<?= e($item['type']) ?>', '<?= e($item['name']) ?>')" title="预览">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php else: ?>
                                                    <a href="<?= e($item['url']) ?>" target="_blank" class="btn btn-sm btn-light text-primary" title="查看/下载">
                                                        <i class="bi bi-box-arrow-up-right"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                    <button class="btn btn-sm btn-light text-secondary" onclick="copyLink('<?= e($item['url']) ?>')" title="复制链接">
                                                        <i class="bi bi-link-45deg"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (!$item['is_dir'] || ($item['is_dir'] && !in_array($item['name'], $defaultDirectories))): ?>
                                                <button class="btn btn-sm btn-light text-warning" onclick="renameItem('<?= e($item['name']) ?>')" title="重命名">
                                                    <i class="bi bi-input-cursor-text"></i>
                                                </button>
                                                <button class="btn btn-sm btn-light text-danger" onclick="deleteItem('<?= e($item['relative_path']) ?>', <?= $item['is_dir'] ? 'true' : 'false' ?>)" title="删除">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

    <!-- 上传模态框 -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">上传文件</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="uploadForm">
                        <div class="mb-3">
                            <label class="form-label">选择文件</label>
                            <input type="file" class="form-control" id="fileInput" name="file" required>
                            <div class="form-text">支持常见格式，单文件最大 50MB</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">上传目录</label>
                            <select class="form-select" id="directorySelect" name="directory">
                                <?php if ($currentDirParam): ?>
                                    <option value="<?= e($currentDirParam) ?>"><?= e($currentDirParam) ?> (当前目录)</option>
                                <?php endif; ?>
                                <?php foreach ($rootDirectories as $rd): ?>
                                    <?php if ($rd !== $currentDirParam): ?>
                                    <option value="<?= e($rd) ?>"><?= e(ucfirst($rd)) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="progress" id="uploadProgress" style="display: none; height: 20px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%">0%</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="uploadFile()">
                        <i class="bi bi-cloud-upload me-1"></i> 开始上传
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 创建文件夹模态框 -->
    <div class="modal fade" id="createFolderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">新建文件夹</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_folder">
                        <div class="mb-3">
                            <label class="form-label">文件夹名称</label>
                            <input type="text" class="form-control" name="folder_name" required maxlength="50" placeholder="支持中文、英文、数字、下划线">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i> 创建</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- 重命名模态框 -->
    <div class="modal fade" id="renameModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">重命名</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="rename">
                        <input type="hidden" id="oldName" name="old_name">
                        <div class="mb-3">
                            <label class="form-label">新名称</label>
                            <input type="text" class="form-control" id="newName" name="new_name" required maxlength="100">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> 保存</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- 编辑文件模态框 -->
    <div class="modal fade" id="editFileModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content" style="height: 90vh;">
                <form method="POST" id="editFileForm" class="h-100 d-flex flex-column">
                    <div class="modal-header py-2">
                        <h5 class="modal-title mb-0">
                            编辑文件 - <span id="editFileName" class="text-primary"></span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0 flex-grow-1 position-relative">
                        <input type="hidden" name="action" value="save_file">
                        <input type="hidden" id="editFilePath" name="path">
                        <div id="editorLoading" class="position-absolute w-100 h-100 d-flex justify-content-center align-items-center bg-white" style="z-index: 10;">
                             <div class="spinner-border text-primary" role="status"></div>
                         </div>
                         <div id="monacoEditorContainer" class="w-100 h-100" style="display:none; min-height: 500px;"></div>
                         <textarea name="content" id="editFileContent" class="d-none"></textarea>
                     </div>
                    <div class="modal-footer py-2 bg-light">
                        <span id="saveStatus" class="text-success me-auto" style="display:none;">
                            <i class="bi bi-check-circle"></i> 保存成功
                        </span>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-primary" onclick="submitEditFile()">
                            <i class="bi bi-save me-1"></i> 保存更改
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- 媒体预览模态框 -->
    <div class="modal fade" id="mediaPreviewModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title mb-0 text-truncate" id="mediaPreviewTitle" style="max-width: 90%;">预览</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0 text-center bg-dark" id="mediaPreviewContainer" style="min-height: 200px;">
                    <!-- 内容由 JS 动态注入 -->
                </div>
                <div class="modal-footer py-2">
                    <a href="#" id="mediaDownloadLink" class="btn btn-primary btn-sm" download>
                        <i class="bi bi-download me-1"></i> 下载
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">关闭</button>
                </div>
            </div>
        </div>
    </div>
    
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="path" id="deletePath">
    </form>
    
    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js') ?>"></script>
    
    <!-- 引入国内的 Monaco Editor CDN，用于提供强大的代码编辑体验 -->
    <link rel="stylesheet" href="https://lf26-cdn-tos.bytecdntp.com/cdn/expire-1-M/monaco-editor/0.33.0/min/vs/editor/editor.main.min.css">
    <script>
        // 设置 Monaco Editor 的路径配置
        var require = { paths: { 'vs': 'https://lf26-cdn-tos.bytecdntp.com/cdn/expire-1-M/monaco-editor/0.33.0/min/vs' } };
    </script>
    <script src="https://lf26-cdn-tos.bytecdntp.com/cdn/expire-1-M/monaco-editor/0.33.0/min/vs/loader.min.js"></script>
    <script>
        // 确保 Monaco Editor 加载完成后再执行后续代码
        let monacoReady = false;
        let pendingEditParams = null;
        
        // 等待 require 被定义后再执行
        const checkRequire = setInterval(() => {
            // loader.js 中的 require 是全局函数，使用 window.require 防止命名冲突
            if (window.require && typeof window.require === 'function' && !window.require.paths) {
                // 如果发现 require 被覆盖或未正确初始化，重新配置
                window.require.config({ paths: { 'vs': 'https://lf26-cdn-tos.bytecdntp.com/cdn/expire-1-M/monaco-editor/0.33.0/min/vs' } });
            }
            if (window.require && typeof window.require === 'function') {
                clearInterval(checkRequire);
                window.require(['vs/editor/editor.main'], function () {
                    monacoReady = true;
                    if (pendingEditParams) {
                        editFile(pendingEditParams.path, pendingEditParams.name);
                        pendingEditParams = null;
                    }
                });
            }
        }, 100);
    </script>
    
    <script>
        let fileEditor = null;
        // 拖拽上传逻辑
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        
        if (dropZone) {
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('drag-over');
            });
            
            dropZone.addEventListener('dragleave', () => {
                dropZone.classList.remove('drag-over');
            });
            
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('drag-over');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    new bootstrap.Modal(document.getElementById('uploadModal')).show();
                }
            });

            dropZone.addEventListener('click', () => {
                fileInput.click();
            });
            
            // 监听 fileInput 变化自动打开模态框
            fileInput.addEventListener('change', () => {
                if (fileInput.files.length > 0) {
                    new bootstrap.Modal(document.getElementById('uploadModal')).show();
                }
            });
        }
        
        function uploadFile() {
            const form = document.getElementById('uploadForm');
            const file = fileInput.files[0];
            
            if (!file) {
                alert('请先选择一个文件！');
                return;
            }
            
            const formData = new FormData(form);
            const progressContainer = document.getElementById('uploadProgress');
            const progressBar = progressContainer.querySelector('.progress-bar');
            const submitBtn = document.querySelector('#uploadModal .btn-primary');
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> 上传中...';
            progressContainer.style.display = 'flex';
            progressBar.style.width = '0%';
            progressBar.textContent = '0%';
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/admin/upload_file.php', true);
            
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    const percentComplete = Math.round((e.loaded / e.total) * 100);
                    progressBar.style.width = percentComplete + '%';
                    progressBar.textContent = percentComplete + '%';
                }
            };
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        progressBar.classList.remove('progress-bar-animated');
                        progressBar.classList.add('bg-success');
                        setTimeout(() => window.location.reload(), 500);
                    } else {
                        showError(response.error || '上传失败');
                    }
                } else {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        showError(response.error || '服务器错误');
                    } catch (e) {
                        showError('服务器错误');
                    }
                }
            };
            
            xhr.onerror = function() {
                showError('网络错误，上传失败');
            };
            
            function showError(msg) {
                alert(msg);
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i> 开始上传';
                progressContainer.style.display = 'none';
            }
            
            xhr.send(formData);
        }
        
        function deleteItem(path, isDir) {
            const msg = isDir ? '确定要删除此文件夹及其全部内容吗？此操作不可恢复！' : '确定要删除此文件吗？此操作不可恢复！';
            if (confirm(msg)) {
                document.getElementById('deletePath').value = path;
                document.getElementById('deleteForm').submit();
            }
        }
        
        function renameItem(currentName) {
            document.getElementById('oldName').value = currentName;
            document.getElementById('newName').value = currentName;
            
            const modal = new bootstrap.Modal(document.getElementById('renameModal'));
            modal.show();
            
            setTimeout(() => {
                const input = document.getElementById('newName');
                input.focus();
                const dotIndex = currentName.lastIndexOf('.');
                if (dotIndex > 0) {
                    input.setSelectionRange(0, dotIndex);
                } else {
                    input.select();
                }
            }, 200);
        }
        
        function copyLink(url) {
            const fullUrl = window.location.origin + url;
            navigator.clipboard.writeText(fullUrl).then(() => {
                alert('链接已成功复制到剪贴板！');
            }).catch(err => {
                prompt('请手动复制链接:', fullUrl);
            });
        }
        
        function previewMedia(url, type, name) {
            const container = document.getElementById('mediaPreviewContainer');
            const title = document.getElementById('mediaPreviewTitle');
            const downloadLink = document.getElementById('mediaDownloadLink');
            
            title.textContent = name;
            downloadLink.href = url;
            container.innerHTML = ''; // 清空之前的内容
            
            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(type);
            const isVideo = ['mp4', 'webm', 'ogg'].includes(type);
            const isAudio = ['mp3', 'wav'].includes(type);
            
            if (isImage) {
                container.innerHTML = `<img src="${url}" class="img-fluid" style="max-height: 70vh; object-fit: contain;">`;
            } else if (isVideo) {
                container.innerHTML = `
                    <video controls autoplay style="max-width: 100%; max-height: 70vh; outline: none;">
                        <source src="${url}" type="video/${type}">
                        您的浏览器不支持视频播放。
                    </video>`;
            } else if (isAudio) {
                container.innerHTML = `
                    <div class="d-flex align-items-center justify-content-center p-5">
                        <audio controls autoplay style="width: 80%;">
                            <source src="${url}" type="audio/${type}">
                            您的浏览器不支持音频播放。
                        </audio>
                    </div>`;
            }
            
            const modalEl = document.getElementById('mediaPreviewModal');
            const modal = new bootstrap.Modal(modalEl);
            
            // 模态框关闭时停止音视频播放
            modalEl.addEventListener('hidden.bs.modal', function () {
                container.innerHTML = '';
            }, { once: true });
            
            modal.show();
        }
        
        function getLanguageByExt(name) {
            const ext = name.split('.').pop().toLowerCase();
            const map = {
                'js': 'javascript',
                'ts': 'typescript',
                'html': 'html',
                'htm': 'html',
                'css': 'css',
                'json': 'json',
                'php': 'php',
                'md': 'markdown',
                'xml': 'xml',
                'txt': 'plaintext',
                'log': 'plaintext',
                'csv': 'plaintext',
                'sql': 'sql',
                'yaml': 'yaml',
                'yml': 'yaml'
            };
            return map[ext] || 'plaintext';
        }
        
        function editFile(path, name) {
            // 如果 Monaco Editor 还没加载完，记录参数并等待
            if (!monacoReady) {
                pendingEditParams = { path, name };
                // 可以加个小提示让用户知道正在加载编辑器
                const btn = event ? event.currentTarget : null;
                if (btn) {
                    const originalHtml = btn.innerHTML;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
                    btn.disabled = true;
                    setTimeout(() => {
                        if(btn) {
                            btn.innerHTML = originalHtml;
                            btn.disabled = false;
                        }
                    }, 2000);
                }
                return;
            }

            const modal = new bootstrap.Modal(document.getElementById('editFileModal'));
            document.getElementById('editFileName').textContent = name;
            document.getElementById('editFilePath').value = path;
            const container = document.getElementById('monacoEditorContainer');
            const loadingArea = document.getElementById('editorLoading');
            
            container.style.display = 'none';
            loadingArea.style.display = 'flex';
            
            modal.show();
            
            // 获取文件内容
            fetch('/uploads/' + path + '?t=' + new Date().getTime())
                .then(response => {
                    if (!response.ok) throw new Error('无法读取文件');
                    return response.text();
                })
                .then(text => {
                    loadingArea.style.display = 'none';
                    container.style.display = 'block';
                    
                    const lang = getLanguageByExt(name);
                    
                    if (fileEditor) {
                        fileEditor.setValue(text);
                        monaco.editor.setModelLanguage(fileEditor.getModel(), lang);
                    } else {
                        // 初始化 Monaco Editor
                        fileEditor = monaco.editor.create(container, {
                            value: text,
                            language: lang,
                            theme: 'vs-dark',
                            automaticLayout: true,
                            minimap: { enabled: false },
                            fontSize: 14,
                            wordWrap: 'on'
                        });
                    }
                })
                .catch(err => {
                    alert('读取文件内容失败: ' + err.message);
                    bootstrap.Modal.getInstance(document.getElementById('editFileModal')).hide();
                });
        }
        
        function submitEditFile() {
            if (fileEditor) {
                document.getElementById('editFileContent').value = fileEditor.getValue();
            }
            
            const form = document.getElementById('editFileForm');
            const formData = new FormData(form);
            const btn = document.querySelector('#editFileModal .btn-primary');
            const status = document.getElementById('saveStatus');
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> 保存中...';
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-save me-1"></i> 保存更改';
                
                status.style.display = 'inline-block';
                setTimeout(() => { status.style.display = 'none'; }, 3000);
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-save me-1"></i> 保存更改';
                alert('保存出错: ' + err.message);
            });
        }
    </script>
<?php require_once 'includes/footer.php'; ?>