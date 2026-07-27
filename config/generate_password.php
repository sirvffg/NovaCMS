<?php
session_start();
// 简单的密码生成工具
// 检查是否为管理员
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    die('无权访问：请先以管理员身份登录网站。');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    
    if (empty($password)) {
        $message = "请输入密码";
    } else {
        $md5Hash = md5($password);
        $content = "password=" . $md5Hash;
        
        if (file_put_contents(__DIR__ . '/markdown_copy_password.config', $content)) {
            $message = "配置已更新！<br>密码: " . htmlspecialchars($password) . "<br>MD5: " . $md5Hash;
        } else {
            $message = "无法写入配置文件，请检查权限。";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>生成 MD5 密码配置</title>
    <style>
        body { font-family: sans-serif; padding: 20px; max-width: 600px; margin: 0 auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input[type="text"] { width: 100%; padding: 8px; box-sizing: border-box; }
        button { padding: 10px 20px; background-color: #007bff; color: white; border: none; cursor: pointer; }
        button:hover { background-color: #0056b3; }
        .message { margin-top: 20px; padding: 10px; background-color: #f0f0f0; border-radius: 4px; word-break: break-all; }
    </style>
</head>
<body>
    <h2>生成 Markdown 复制/下载密码 (MD5)</h2>
    
    <?php if (isset($message)): ?>
    <div class="message"><?= $message ?></div>
    <?php endif; ?>
    
    <form method="post">
        <div class="form-group">
            <label for="password">输入新密码：</label>
            <input type="text" id="password" name="password" required placeholder="请输入要设置的密码">
        </div>
        <button type="submit">生成并保存配置</button>
    </form>
</body>
</html>