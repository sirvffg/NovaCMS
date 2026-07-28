<?php
/**
 * Backup Plugin Routes
 */

defined('NOVA_API') or exit('禁止直接访问');

require_once dirname(__DIR__) . '/class-backup.php';

$backup = new Backup_Core();

// 验证请求（可选，保留原User-Agent验证）
// $backup->validateRequest();

$route = $_GET['route'] ?? '';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 路由处理
switch (true) {
    // 创建备份
    case $route === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST':
        $backup->logAccess('create');
        $result = $backup->createBackup();
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;

    // 获取备份列表
    case $route === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET':
        $backup->logAccess('list');
        $result = $backup->getBackupList();
        echo json_encode([
            'success' => true,
            'message' => '获取备份列表成功',
            'data' => $result
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;

    // 删除备份
    case strpos($route, 'delete') === 0 && $_SERVER['REQUEST_METHOD'] === 'DELETE':
        $filename = $_GET['filename'] ?? '';
        $backup->logAccess('delete:' . $filename);
        $result = $backup->deleteBackup($filename);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;

    // 未知路由
    default:
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => '路由不存在'
        ], JSON_UNESCAPED_UNICODE);
}