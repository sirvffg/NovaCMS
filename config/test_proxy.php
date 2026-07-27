<?php
// 测试代理是否工作
header('Content-Type: text/html; charset=utf-8');
echo '<h2>代理测试</h2>';

// 测试CSS代理
$cssUrl = '/config/music_proxy.php?type=css';
echo '<p><a href="' . $cssUrl . '" target="_blank">测试CSS代理</a></p>';

// 测试JS代理
$jsUrl = '/config/music_proxy.php?type=js';
echo '<p><a href="' . $jsUrl . '" target="_blank">测试JS代理</a></p>';

// 检查目录权限
$cacheDir = __DIR__ . '/cache/music_proxy';
if (!file_exists($cacheDir)) {
    echo '<p style="color: red;">缓存目录不存在</p>';
    if (@mkdir($cacheDir, 0755, true)) {
        echo '<p>已创建缓存目录</p>';
    }
} else {
    echo '<p style="color: green;">缓存目录存在</p>';
    if (is_writable($cacheDir)) {
        echo '<p style="color: green;">缓存目录可写</p>';
    } else {
        echo '<p style="color: red;">缓存目录不可写</p>';
    }
}
?>