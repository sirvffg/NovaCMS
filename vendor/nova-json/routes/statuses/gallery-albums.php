<?php
/**
 * Statuses Gallery Albums 路由
 * 命名空间: v1
 *
 * GET    /v1/statuses/gallery/albums              - 获取相册列表
 * GET    /v1/statuses/gallery/albums/{id}         - 获取相册详情（含照片列表）
 * POST   /v1/statuses/gallery/albums              - 创建相册（管理员）
 * PUT    /v1/statuses/gallery/albums/{id}         - 更新相册（管理员）
 * DELETE /v1/statuses/gallery/albums/{id}         - 删除相册（管理员）
 */

register_rest_route('v1', '/statuses/gallery/albums', [
    'methods'  => 'GET',
    'callback' => 'nova_get_albums',
]);

register_rest_route('v1', '/statuses/gallery/albums', [
    'methods'  => 'POST',
    'callback' => 'nova_create_album',
]);

register_rest_route('v1', '/statuses/gallery/albums/{id}', [
    'methods'  => 'GET',
    'callback' => 'nova_get_album',
]);

register_rest_route('v1', '/statuses/gallery/albums/{id}', [
    'methods'  => 'PUT',
    'callback' => 'nova_update_album',
]);

register_rest_route('v1', '/statuses/gallery/albums/{id}', [
    'methods'  => 'DELETE',
    'callback' => 'nova_delete_album',
]);

/**
 * 获取相册列表
 */
function nova_get_albums($request) {
    $db = getDB();

    try {
        $albums = $db->query("
            SELECT a.*, COUNT(p.id) as photo_count
            FROM photo_albums a
            LEFT JOIN photos p ON a.id = p.album_id
            GROUP BY a.id
            ORDER BY a.sort_order ASC, a.created_at DESC
        ")->fetchAll();
    } catch (PDOException $e) {
        return [
            'code'    => 'rest_error',
            'message' => '相册表不存在',
            'data'    => ['status' => 500],
        ];
    }

    $items = array_map(function($item) {
        return [
            'id'          => (int)$item['id'],
            'name'        => $item['name'],
            'description' => $item['description'] ?? '',
            'cover_image' => $item['cover_image'] ?? '',
            'sort_order'  => (int)$item['sort_order'],
            'photo_count' => (int)$item['photo_count'],
            'created_at'  => $item['created_at'],
        ];
    }, $albums);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status' => 200,
            'total'  => count($items),
            'items'  => $items,
        ],
    ];
}

/**
 * 获取相册详情（含照片列表）
 */
function nova_get_album($request) {
    $db = getDB();
    $id = (int)($request->get_param('id') ?? 0);

    if (!$id) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '未指定相册 ID',
            'data'    => ['status' => 400],
        ];
    }

    $stmt = $db->prepare("SELECT * FROM photo_albums WHERE id = ?");
    $stmt->execute([$id]);
    $album = $stmt->fetch();

    if (!$album) {
        return [
            'code'    => 'rest_error',
            'message' => '相册不存在',
            'data'    => ['status' => 404],
        ];
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM photos WHERE album_id = ?");
    $countStmt->execute([$id]);
    $total = (int)$countStmt->fetchColumn();

    // 分页：不传 per_page 则返回全部
    $raw_per_page = $request->get_param('per_page');
    $has_pagination = $raw_per_page !== null;

    if ($has_pagination) {
        $page    = max(1, (int)($request->get_param('page') ?? 1));
        $per_page = min(100, max(1, (int)$raw_per_page));
        $offset  = ($page - 1) * $per_page;

        $photoStmt = $db->prepare(
            "SELECT * FROM photos WHERE album_id = ? ORDER BY sort_order ASC, created_at DESC LIMIT ?, ?"
        );
        $photoStmt->bindValue(1, $id, PDO::PARAM_INT);
        $photoStmt->bindValue(2, $offset, PDO::PARAM_INT);
        $photoStmt->bindValue(3, $per_page, PDO::PARAM_INT);
        $photoStmt->execute();
    } else {
        $page     = 1;
        $per_page = $total;
        $photoStmt = $db->prepare(
            "SELECT * FROM photos WHERE album_id = ? ORDER BY sort_order ASC, created_at DESC"
        );
        $photoStmt->bindValue(1, $id, PDO::PARAM_INT);
        $photoStmt->execute();
    }

    $photos = $photoStmt->fetchAll();

    $photoItems = array_map(function($p) {
        return [
            'id'          => (int)$p['id'],
            'album_id'    => (int)$p['album_id'],
            'url'         => $p['url'],
            'title'       => $p['title'] ?? '',
            'description' => $p['description'] ?? '',
            'sort_order'  => (int)$p['sort_order'],
            'created_at'  => $p['created_at'],
        ];
    }, $photos);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status'   => 200,
            'album'    => [
                'id'          => (int)$album['id'],
                'name'        => $album['name'],
                'description' => $album['description'] ?? '',
                'cover_image' => $album['cover_image'] ?? '',
                'sort_order'  => (int)$album['sort_order'],
                'created_at'  => $album['created_at'],
            ],
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'photos'   => $photoItems,
        ],
    ];
}

/**
 * 创建相册（管理员）
 */
function nova_create_album($request) {
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可创建相册',
            'data'    => ['status' => 403],
        ];
    }

    $db          = getDB();
    $name        = trim(strip_tags($request->get_param('name') ?? ''));
    $description = trim(strip_tags($request->get_param('description') ?? ''));
    $sortOrder   = (int)($request->get_param('sort_order') ?? 0);

    if (empty($name)) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '相册名称不能为空',
            'data'    => ['status' => 400],
        ];
    }

    $coverImage = '';
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $coverImage = nova_handle_cover_upload($_FILES['cover_image']);
        if (is_array($coverImage)) {
            return $coverImage;
        }
    }

    $stmt = $db->prepare(
        "INSERT INTO photo_albums (name, description, cover_image, sort_order) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$name, $description, $coverImage, $sortOrder]);
    $newId = (int)$db->lastInsertId();

    return [
        'code'    => 'rest_ok',
        'message' => '相册已创建',
        'data'    => [
            'status'      => 201,
            'id'          => $newId,
            'name'        => $name,
            'description' => $description,
            'sort_order'  => $sortOrder,
        ],
    ];
}

/**
 * 更新相册（管理员）
 */
function nova_update_album($request) {
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可更新相册',
            'data'    => ['status' => 403],
        ];
    }

    $db = getDB();
    $id = (int)($request->get_param('id') ?? 0);

    if (!$id) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '未指定相册 ID',
            'data'    => ['status' => 400],
        ];
    }

    $checkStmt = $db->prepare("SELECT id, cover_image FROM photo_albums WHERE id = ?");
    $checkStmt->execute([$id]);
    $existing = $checkStmt->fetch();

    if (!$existing) {
        return [
            'code'    => 'rest_error',
            'message' => '相册不存在',
            'data'    => ['status' => 404],
        ];
    }

    $name        = trim(strip_tags($request->get_param('name') ?? ''));
    $description = trim(strip_tags($request->get_param('description') ?? ''));
    $sortOrder   = (int)($request->get_param('sort_order') ?? 0);

    if (empty($name)) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '相册名称不能为空',
            'data'    => ['status' => 400],
        ];
    }

    $coverImage = $existing['cover_image'] ?? '';
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = nova_handle_cover_upload($_FILES['cover_image']);
        if (is_array($uploadResult)) {
            return $uploadResult;
        }
        $coverImage = $uploadResult;
    }

    $stmt = $db->prepare(
        "UPDATE photo_albums SET name = ?, description = ?, cover_image = ?, sort_order = ? WHERE id = ?"
    );
    $stmt->execute([$name, $description, $coverImage, $sortOrder, $id]);

    return [
        'code'    => 'rest_ok',
        'message' => '相册已更新',
        'data'    => [
            'status'      => 200,
            'id'          => $id,
            'name'        => $name,
            'description' => $description,
            'sort_order'  => $sortOrder,
        ],
    ];
}

/**
 * 删除相册（管理员）
 */
function nova_delete_album($request) {
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可删除相册',
            'data'    => ['status' => 403],
        ];
    }

    $db = getDB();
    $id = (int)($request->get_param('id') ?? 0);

    if (!$id) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '未指定相册 ID',
            'data'    => ['status' => 400],
        ];
    }

    $checkStmt = $db->prepare("SELECT id, cover_image FROM photo_albums WHERE id = ?");
    $checkStmt->execute([$id]);
    $album = $checkStmt->fetch();

    if (!$album) {
        return [
            'code'    => 'rest_error',
            'message' => '相册不存在',
            'data'    => ['status' => 404],
        ];
    }

    $photoStmt = $db->prepare("SELECT url FROM photos WHERE album_id = ?");
    $photoStmt->execute([$id]);
    $photos = $photoStmt->fetchAll();

    foreach ($photos as $p) {
        if (strpos($p['url'], '/uploads/gallery/') === 0) {
            $filePath = dirname(__DIR__, 4) . $p['url'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
    }

    if (!empty($album['cover_image']) && strpos($album['cover_image'], '/uploads/gallery/') === 0) {
        $coverPath = dirname(__DIR__, 4) . $album['cover_image'];
        if (file_exists($coverPath)) {
            @unlink($coverPath);
        }
    }

    $db->prepare("DELETE FROM photos WHERE album_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM photo_albums WHERE id = ?")->execute([$id]);

    return [
        'code'    => 'rest_ok',
        'message' => '相册已删除',
        'data'    => [
            'status'       => 200,
            'deleted_id'   => $id,
            'deleted_photos' => count($photos),
        ],
    ];
}

/**
 * 处理封面图片上传
 */
function nova_handle_cover_upload($file) {
    $uploadDir = dirname(__DIR__, 4) . '/uploads/gallery/covers/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedMimes      = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize           = 5 * 1024 * 1024;
    $tmpPath           = $file['tmp_name'];
    $fileExtension     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // MIME 类型检测（兼容无 fileinfo 扩展的环境）
    $mimeType = '';
    if (class_exists('finfo')) {
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);
    } elseif (function_exists('mime_content_type')) {
        $mimeType = mime_content_type($tmpPath);
    }

    if ($file['size'] > $maxSize) {
        return [
            'code'    => 'rest_error',
            'message' => '封面图大小不能超过 5MB',
            'data'    => ['status' => 400],
        ];
    }

    if (!in_array($fileExtension, $allowedExtensions) || !in_array($mimeType, $allowedMimes)) {
        return [
            'code'    => 'rest_error',
            'message' => '只允许上传 JPG、PNG、GIF、WebP 格式的图片',
            'data'    => ['status' => 400],
        ];
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        return [
            'code'    => 'rest_error',
            'message' => '无效的图片文件',
            'data'    => ['status' => 400],
        ];
    }

    $header = @file_get_contents($tmpPath, false, null, 0, 512);
    if ($header !== false && preg_match('/<\?(php|=|xml)?/i', $header)) {
        return [
            'code'    => 'rest_error',
            'message' => '图片包含非法内容',
            'data'    => ['status' => 400],
        ];
    }

    $newFileName = 'cover_' . date('Ymd_His_') . uniqid() . '.' . $fileExtension;
    $targetFile  = $uploadDir . $newFileName;

    if (!move_uploaded_file($tmpPath, $targetFile)) {
        return [
            'code'    => 'rest_error',
            'message' => '封面上传失败',
            'data'    => ['status' => 500],
        ];
    }

    return '/uploads/gallery/covers/' . $newFileName;
}
