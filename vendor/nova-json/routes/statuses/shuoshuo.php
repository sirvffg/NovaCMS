<?php
/**
 * Statuses Shuoshuo 路由
 * 命名空间: v1
 *
 * GET  /v1/statuses/shuoshuo              - 获取说说列表
 * POST /v1/statuses/shuoshuo              - 发布说说（管理员）
 * DELETE /v1/statuses/shuoshuo/{id}       - 删除说说并重排 ID（管理员）
 *
 * 参数（GET）:
 *   page     - 页码（默认 1）
 *   per_page - 每页条数（默认 20）
 *
 * 参数（POST）:
 *   content   - 说说内容（必填）
 *   image     - 图片文件（可选，multipart/form-data，支持 jpg/png/gif/webp，最大 5MB）
 */

register_rest_route('v1', '/statuses/shuoshuo', [
    'methods'  => 'GET',
    'callback' => 'nova_get_shuoshuo',
]);

register_rest_route('v1', '/statuses/shuoshuo', [
    'methods'  => 'POST',
    'callback' => 'nova_add_shuoshuo',
]);

register_rest_route('v1', '/statuses/shuoshuo/{id}', [
    'methods'  => 'DELETE',
    'callback' => 'nova_delete_shuoshuo',
]);

/**
 * 获取说说列表
 */
function nova_get_shuoshuo($request) {
    $db       = getDB();
    $total    = (int)$db->query("SELECT COUNT(*) FROM shuoshuo")->fetchColumn();

    // 分页：不传 per_page 则返回全部
    $raw_per_page = $request->get_param('per_page');
    $has_pagination = $raw_per_page !== null;

    if ($has_pagination) {
        $page    = max(1, (int)($request->get_param('page') ?? 1));
        $per_page = min(50, max(1, (int)$raw_per_page));
        $offset  = ($page - 1) * $per_page;

        $stmt = $db->prepare("SELECT * FROM shuoshuo ORDER BY created_at DESC LIMIT ?, ?");
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $per_page, PDO::PARAM_INT);
        $stmt->execute();
        $shuoshuos = $stmt->fetchAll();
    } else {
        $page     = 1;
        $per_page = $total;
        $shuoshuos = $db->query("SELECT * FROM shuoshuo ORDER BY created_at DESC")->fetchAll();
    }

    $items = array_map(function($item) {
        return [
            'id'         => (int)$item['id'],
            'content'    => $item['content'],
            'image_path' => $item['image_path'] ?? '',
            'created_at' => $item['created_at'],
        ];
    }, $shuoshuos);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status'   => 200,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'items'    => $items,
        ],
    ];
}

/**
 * 发布说说（管理员）
 */
function nova_add_shuoshuo($request) {
    // ── 权限检查 ──
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可发布说说',
            'data'    => ['status' => 403],
        ];
    }

    $db      = getDB();
    $content = trim(strip_tags($request->get_param('content') ?? ''));

    if (empty($content)) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '内容不能为空',
            'data'    => ['status' => 400],
        ];
    }

    // ── 处理图片上传 ──
    $imagePath = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = dirname(__DIR__, 4) . '/uploads/shuoshuo/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowedMimes      = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize           = 5 * 1024 * 1024; // 5MB
        $tmpPath           = $_FILES['image']['tmp_name'];

        // 检查文件扩展名和 MIME 类型
        $fileExtension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $mimeType      = '';

        if (class_exists('finfo')) {
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($tmpPath);
        } elseif (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($tmpPath);
        }

        if ($_FILES['image']['size'] > $maxSize) {
            return [
                'code'    => 'rest_error',
                'message' => '图片大小不能超过 5MB',
                'data'    => ['status' => 400],
            ];
        }

        // 支持 finfo 时做完整 MIME 校验，不支持时仅校验扩展名
        if (!in_array($fileExtension, $allowedExtensions)) {
            return [
                'code'    => 'rest_error',
                'message' => '只允许上传 JPG、PNG、GIF、WebP 格式的图片',
                'data'    => ['status' => 400],
            ];
        }

        if ($mimeType !== '' && !in_array($mimeType, $allowedMimes)) {
            return [
                'code'    => 'rest_error',
                'message' => '图片文件类型不合法',
                'data'    => ['status' => 400],
            ];
        }

        // 用 getimagesize 验证图片头部合法性（检测伪造图片、恶意代码嵌入）
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            return [
                'code'    => 'rest_error',
                'message' => '无效的图片文件',
                'data'    => ['status' => 400],
            ];
        }

        // 检查文件头部是否包含 PHP 标签等恶意代码
        $header = file_get_contents($tmpPath, false, null, 0, 512);
        if (preg_match('/<\?(php|=|xml)?/i', $header)) {
            return [
                'code'    => 'rest_error',
                'message' => '图片包含非法内容',
                'data'    => ['status' => 400],
            ];
        }

        // 保存文件
        $newFileName = uniqid() . '.' . $fileExtension;
        $targetFile  = $uploadDir . $newFileName;

        if (move_uploaded_file($tmpPath, $targetFile)) {
            $imagePath = '/uploads/shuoshuo/' . $newFileName;
        }
    }

    // ── 插入数据库 ──
    $stmt = $db->prepare("INSERT INTO shuoshuo (content, image_path) VALUES (?, ?)");
    $stmt->execute([$content, $imagePath]);
    $newId = (int)$db->lastInsertId();

    return [
        'code'    => 'rest_ok',
        'message' => '发布成功',
        'data'    => [
            'status'     => 200,
            'id'         => $newId,
            'content'    => $content,
            'image_path' => $imagePath,
        ],
    ];
}

/**
 * 删除说说并重排 ID
 */
function nova_delete_shuoshuo($request) {
    // ── 权限检查 ──
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可删除说说',
            'data'    => ['status' => 403],
        ];
    }

    $db    = getDB();
    $id    = (int)($request->get_param('id') ?? 0);

    if (!$id) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '未指定说说 ID',
            'data'    => ['status' => 400],
        ];
    }

    // 检查记录是否存在
    $checkStmt = $db->prepare("SELECT id FROM shuoshuo WHERE id = ?");
    $checkStmt->execute([$id]);
    if (!$checkStmt->fetch()) {
        return [
            'code'    => 'rest_error',
            'message' => '说说不存在',
            'data'    => ['status' => 404],
        ];
    }

    // 删除记录
    $deleteStmt = $db->prepare("DELETE FROM shuoshuo WHERE id = ?");
    $deleteStmt->execute([$id]);

    // 获取剩余条数
    $count = (int)$db->query("SELECT COUNT(*) FROM shuoshuo")->fetchColumn();

    return [
        'code'    => 'rest_ok',
        'message' => '删除成功，ID 已重排',
        'data'    => [
            'status'     => 200,
            'deleted_id' => $id,
            'remaining'  => $count,
        ],
    ];
}
