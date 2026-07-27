<?php
session_start();
require_once '../config/database.php';

// 检查登录
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$uploadDir = dirname(__DIR__) . '/uploads/';
$uploadDirWeb = '../uploads/';

// 测试信息
$tests = [
    'uploads 目录存在' => is_dir($uploadDir),
    'uploads 目录可写' => is_writable($uploadDir),
    'uploads 目录路径' => $uploadDir,
    'PHP 上传限制 (upload_max_filesize)' => ini_get('upload_max_filesize'),
    'PHP POST 限制 (post_max_size)' => ini_get('post_max_size'),
    'PHP 临时目录' => sys_get_temp_dir(),
    '临时目录可写' => is_writable(sys_get_temp_dir()),
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>上传测试</title>
    <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container mt-5">
        <h2>上传功能测试</h2>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5>系统信息</h5>
            </div>
            <div class="card-body">
                <table class="table">
                    <?php foreach ($tests as $name => $value): ?>
                    <tr>
                        <td><strong><?= $name ?></strong></td>
                        <td>
                            <?php if (is_bool($value)): ?>
                                <span class="badge bg-<?= $value ? 'success' : 'danger' ?>">
                                    <?= $value ? '是' : '否' ?>
                                </span>
                            <?php else: ?>
                                <?= htmlspecialchars($value) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5>测试图片上传</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">选择图片</label>
                    <input type="file" class="form-control" id="imageFile" accept="image/*">
                </div>
                <button type="button" class="btn btn-primary" onclick="testUpload('image', 'imageFile', '/admin/upload_image.php')">测试上传</button>
                <div id="imageResult" class="mt-3"></div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5>测试视频上传</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">选择视频</label>
                    <input type="file" class="form-control" id="videoFile" accept="video/*">
                </div>
                <button type="button" class="btn btn-success" onclick="testUpload('video', 'videoFile', '/admin/upload_video.php')">测试上传</button>
                <div id="videoResult" class="mt-3"></div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5>测试文件上传</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">选择文件</label>
                    <input type="file" class="form-control" id="otherFile" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar,.7z">
                </div>
                <button type="button" class="btn btn-info" onclick="testUpload('file', 'otherFile', '/admin/upload_file.php')">测试上传</button>
                <div id="fileResult" class="mt-3"></div>
            </div>
        </div>
        
        <a href="/admin/index.php" class="btn btn-secondary">返回后台</a>
    </div>
    
    <script>
    function testUpload(fieldName, inputId, uploadUrl) {
        const fileInput = document.getElementById(inputId);
        const resultDiv = document.getElementById(inputId.replace('File', 'Result'));
        
        if (!fileInput.files.length) {
            resultDiv.innerHTML = '<div class="alert alert-warning">请选择文件</div>';
            return;
        }
        
        const file = fileInput.files[0];
        const formData = new FormData();
        formData.append(fieldName, file);
        
        resultDiv.innerHTML = '<div class="alert alert-info">正在上传...</div>';
        
        fetch(uploadUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            if (data.success) {
                let preview = '';
                if (fieldName === 'image') {
                    preview = `<img src="${data.url}" class="img-thumbnail mt-2" style="max-width: 300px;">`;
                } else if (fieldName === 'video') {
                    preview = `<video controls class="mt-2" style="max-width: 500px;"><source src="${data.url}"></video>`;
                } else {
                    preview = `<a href="${data.url}" target="_blank" class="btn btn-sm btn-primary mt-2">下载文件</a>`;
                }
                
                resultDiv.innerHTML = `
                    <div class="alert alert-success">
                        <strong>上传成功！</strong><br>
                        原文件名: ${data.originalName || file.name}<br>
                        保存文件名: ${data.filename}<br>
                        文件大小: ${(data.size / 1024).toFixed(2)} KB<br>
                        URL: ${data.url}<br>
                        ${preview}
                    </div>
                `;
            } else {
                resultDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <strong>上传失败：</strong>${data.error}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Upload error:', error);
            resultDiv.innerHTML = `
                <div class="alert alert-danger">
                    <strong>上传错误：</strong>${error.message}
                </div>
            `;
        });
    }
    </script>
</body>
</html>
