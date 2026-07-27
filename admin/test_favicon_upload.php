<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['favicon'])) {
    $file = $_FILES['favicon'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['ico', 'png', 'jpg', 'jpeg', 'gif'];
        
        if (in_array($extension, $allowedExtensions)) {
            $filename = 'favicon.' . $extension;
            $uploadDir = dirname(__DIR__) . '/assets/images/';
            $uploadPath = $uploadDir . $filename;
            
            // 确保目录存在
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // 尝试移动文件
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $message = "上传成功！文件保存到: $uploadPath";
                
                // 更新数据库
                try {
                    $db = getDB();
                    $url = '/assets/images/' . $filename;
                    $stmt = $db->prepare("UPDATE website_config SET favicon=? WHERE id=1");
                    $stmt->execute([$url]);
                    $message .= "<br>数据库已更新";
                } catch (Exception $e) {
                    $message .= "<br>数据库更新失败: " . $e->getMessage();
                }
            } else {
                $error = "移动文件失败<br>";
                $error .= "临时文件: " . $file['tmp_name'] . "<br>";
                $error .= "目标路径: " . $uploadPath . "<br>";
                $error .= "目录存在: " . (is_dir($uploadDir) ? '是' : '否') . "<br>";
                $error .= "目录可写: " . (is_writable($uploadDir) ? '是' : '否') . "<br>";
                $error .= "临时文件存在: " . (file_exists($file['tmp_name']) ? '是' : '否') . "<br>";
            }
        } else {
            $error = "不支持的文件格式";
        }
    } else {
        $error = "上传错误代码: " . $file['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>Favicon 上传测试</title>
    <link href="<?= getResourceUrl('/assets/css/bootstrap.min.css', 'https://cdn.staticfile.net/bootstrap/5.3.0/css/bootstrap.min.css') ?>" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container mt-5">
        <h2>Favicon 上传测试</h2>
        
        <?php if ($message): ?>
        <div class="alert alert-success"><?= $message ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">选择 Favicon 文件</label>
                        <input type="file" name="favicon" class="form-control" accept=".ico,.png,.jpg,.jpeg,.gif" required>
                        <small class="text-muted">支持 ICO, PNG, JPG, GIF 格式</small>
                    </div>
                    <button type="submit" class="btn btn-primary">上传</button>
                    <a href="/admin/config.php" class="btn btn-secondary">返回配置</a>
                </form>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">系统信息</div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <td>PHP 版本</td>
                        <td><?= phpversion() ?></td>
                    </tr>
                    <tr>
                        <td>upload_max_filesize</td>
                        <td><?= ini_get('upload_max_filesize') ?></td>
                    </tr>
                    <tr>
                        <td>post_max_size</td>
                        <td><?= ini_get('post_max_size') ?></td>
                    </tr>
                    <tr>
                        <td>临时目录</td>
                        <td><?= sys_get_temp_dir() ?></td>
                    </tr>
                    <tr>
                        <td>assets/images 目录</td>
                        <td><?= dirname(__DIR__) . '/assets/images/' ?></td>
                    </tr>
                    <tr>
                        <td>目录存在</td>
                        <td><?= is_dir(dirname(__DIR__) . '/assets/images/') ? '是' : '否' ?></td>
                    </tr>
                    <tr>
                        <td>目录可写</td>
                        <td><?= is_writable(dirname(__DIR__) . '/assets/images/') ? '是' : '否' ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
