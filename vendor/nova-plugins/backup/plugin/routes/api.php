<?php
/**
 * Backup Plugin Routes - 注册到 Nova JSON API 路由系统
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

$backup = new Backup_Core();

// 创建备份
register_rest_route('v1', '/backup/create', [
    'methods'  => 'POST',
    'callback' => function($request) use ($backup) {
        $backup->logAccess('create');
        $result = $backup->createBackup();
        return [
            'code'    => $result['success'] ? 'rest_ok' : 'rest_error',
            'message' => $result['message'],
            'data'    => $result,
        ];
    },
]);

// 获取备份列表
register_rest_route('v1', '/backup/list', [
    'methods'  => 'GET',
    'callback' => function($request) use ($backup) {
        $backup->logAccess('list');
        $result = $backup->getBackupList();
        return [
            'code'    => 'rest_ok',
            'message' => '获取备份列表成功',
            'data'    => $result,
        ];
    },
]);

// 删除备份
register_rest_route('v1', '/backup/delete', [
    'methods'  => 'DELETE',
    'callback' => function($request) use ($backup) {
        $filename = $request->get_param('filename') ?: 'all';
        $backup->logAccess('delete:' . $filename);
        $result = $backup->deleteBackup($filename);
        return [
            'code'    => $result['success'] ? 'rest_ok' : 'rest_error',
            'message' => $result['message'],
            'data'    => $result,
        ];
    },
]);
