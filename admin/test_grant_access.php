<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
requireLogin();

// 如果直接访问此页面，执行授权操作
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $accessId = (int)$_GET['id'];
    
    // 检查记录是否存在
    $stmt = $db->prepare("SELECT * FROM blog_privacy_access WHERE id = ?");
    $stmt->execute([$accessId]);
    $record = $stmt->fetch();
    
    if (!$record) {
        die("记录不存在: " . $accessId);
    }
    
    // 执行更新
    $update = $db->prepare("UPDATE blog_privacy_access SET access_granted = 1, is_correct = 1 WHERE id = ?");
    $result = $update->execute([$accessId]);
    
    if ($result) {
        // 验证更新
        $check = $db->prepare("SELECT access_granted FROM blog_privacy_access WHERE id = ?");
        $check->execute([$accessId]);
        $updatedRecord = $check->fetch();
        
        if ($updatedRecord && $updatedRecord['access_granted'] == 1) {
            echo "<h3 style='color:green;'>授权成功！</h3>";
            echo "<p>记录ID: " . $accessId . "</p>";
            echo "<p>用户ID: " . $record['user_id'] . "</p>";
            echo "<p>文章ID: " . $record['post_id'] . "</p>";
            echo "<p><a href='privacy_access.php'>返回访问记录页面</a></p>";
        } else {
            die("更新验证失败");
        }
    } else {
        die("更新失败: " . print_r($update->errorInfo(), true));
    }
} else {
    die("请提供要授权的记录ID: ?id=数字");
}
?>