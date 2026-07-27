<?php
/**
 * Statuses Gallery Photos 路由
 * 命名空间: v1
 *
 * GET    /v1/statuses/gallery/photos              - 获取照片列表（全局，分页）
 * GET    /v1/statuses/gallery/photos/{id}         - 获取单张照片详情
 * POST   /v1/statuses/gallery/photos              - 上传照片（管理员）
 * PUT    /v1/statuses/gallery/photos/{id}         - 更新照片信息（管理员）
 * DELETE /v1/statuses/gallery/photos/{id}         - 删除照片（管理员）
 */

register_rest_route('v1', '/statuses/gallery/photos', [
    'methods'  => 'GET',
    'callback' => 'nova_get_photos',
]);

register_rest_route('v1', '/statuses/gallery/photos', [
    'methods'  => 'POST',
    'callback' => 'nova_upload_photo',
]);

register_rest_route('v1', '/statuses/gallery/photos/{id}', [
    'methods'  => 'GET',
    'callback' => 'nova_get_photo',
]);

register_rest_route('v1', '/statuses/gallery/photos/{id}', [
    'methods'  => 'PUT',
    'callback' => 'nova_update_photo',
]);

register_rest_route('v1', '/statuses/gallery/photos/{id}', [
    'methods'  => 'DELETE',
    'callback' => 'nova_delete_photo',
]);

/**
 * 获取照片列表（全局，分页）
 */
function nova_get_photos($request) {
    $db      = getDB();
    $albumId = (int)($request->get_param('album_id') ?? 0);

    // 分页：不传 per_page 则返回全部
    $raw_per_page = $request->get_param('per_page');
    $has_pagination = $raw_per_page !== null;

    if ($albumId) {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM photos WHERE album_id = ?");
        $countStmt->execute([$albumId]);
        $total = (int)$countStmt->fetchColumn();

        if ($has_pagination) {
            $page    = max(1, (int)($request->get_param('page') ?? 1));
            $per_page = min(100, max(1, (int)$raw_per_page));
            $offset  = ($page - 1) * $per_page;

            $stmt = $db->prepare(
                "SELECT p.*, a.name AS album_name
                 FROM photos p
                 LEFT JOIN photo_albums a ON p.album_id = a.id
                 WHERE p.album_id = ?
                 ORDER BY p.sort_order ASC, p.created_at DESC
                 LIMIT ?, ?"
            );
            $stmt->bindValue(1, $albumId, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->bindValue(3, $per_page, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $page     = 1;
            $per_page = $total;
            $stmt = $db->prepare(
                "SELECT p.*, a.name AS album_name
                 FROM photos p
                 LEFT JOIN photo_albums a ON p.album_id = a.id
                 WHERE p.album_id = ?
                 ORDER BY p.sort_order ASC, p.created_at DESC"
            );
            $stmt->bindValue(1, $albumId, PDO::PARAM_INT);
            $stmt->execute();
        }
    } else {
        $total = (int)$db->query("SELECT COUNT(*) FROM photos")->fetchColumn();

        if ($has_pagination) {
            $page    = max(1, (int)($request->get_param('page') ?? 1));
            $per_page = min(100, max(1, (int)$raw_per_page));
            $offset  = ($page - 1) * $per_page;

            $stmt = $db->prepare(
                "SELECT p.*, a.name AS album_name
                 FROM photos p
                 LEFT JOIN photo_albums a ON p.album_id = a.id
                 ORDER BY p.created_at DESC
                 LIMIT ?, ?"
            );
            $stmt->bindValue(1, $offset, PDO::PARAM_INT);
            $stmt->bindValue(2, $per_page, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $page     = 1;
            $per_page = $total;
            $stmt = $db->query(
                "SELECT p.*, a.name AS album_name
                 FROM photos p
                 LEFT JOIN photo_albums a ON p.album_id = a.id
                 ORDER BY p.created_at DESC"
            );
        }
    }

    $photos = $stmt->fetchAll();

    $items = array_map(function($p) {
        return [
            'id'          => (int)$p['id'],
            'album_id'    => (int)$p['album_id'],
            'album_name'  => $p['album_name'] ?? '',
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
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'items'    => $items,
        ],
    ];
}

/**
 * 获取单张照片详情
 */
function nova_get_photo($request) {
    $db = getDB();
    $id = (int)($request->get_param('id') ?? 0);

    if (!$id) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '未指定照片 ID',
            'data'    => ['status' => 400],
        ];
    }

    $stmt = $db->prepare(
        "SELECT p.*, a.name AS album_name
         FROM photos p
         LEFT JOIN photo_albums a ON p.album_id = a.id
         WHERE p.id = ?"
    );
    $stmt->execute([$id]);
    $photo = $stmt->fetch();

    if (!$photo) {
        return [
            'code'    => 'rest_error',
            'message' => '照片不存在',
            'data'    => ['status' => 404],
        ];
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status' => 200,
            'item'   => [
                'id'          => (int)$photo['id'],
                'album_id'    => (int)$photo['album_id'],
                'album_name'  => $photo['album_name'] ?? '',
                'url'         => $photo['url'],
                'title'       => $photo['title'] ?? '',
                'description' => $photo['description'] ?? '',
                'sort_order'  => (int)$photo['sort_order'],
                'created_at'  => $photo['created_at'],
            ],
        ],
    ];
}

/**
 * 上传照片（管理员）
 */
function nova_upload_photo($request) {
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可上传照片',
            'data'    => ['status' => 403],
        ];
    }

    $db      = getDB();
    $albumId = (int)($request->get_param('album_id') ?? 0);
    $title   = trim(strip_tags($request->get_param('title') ?? ''));
    $desc    = trim(strip_tags($request->get_param('description') ?? ''));

    if ($albumId > 0) {
        $checkStmt = $db->prepare("SELECT id FROM photo_albums WHERE id = ?");
        $checkStmt->execute([$albumId]);
        if (!$checkStmt->fetch()) {
            return [
                'code'    => 'rest_error',
                'message' => '相册不存在',
                'data'    => ['status' => 404],
            ];
        }
    } else {
        $defaultStmt = $db->query("SELECT id FROM photo_albums ORDER BY id ASC LIMIT 1");
        $defaultRow  = $defaultStmt->fetch();
        if ($defaultRow) {
            $albumId = (int)$defaultRow['id'];
        }
    }

    $uploadedUrls = [];

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $url = nova_handle_photo_upload($_FILES['image']);
        if (is_array($url)) {
            return $url;
        }
        $uploadedUrls[] = $url;
    }

    $urlInput = trim($request->get_param('url') ?? '');
    if ($urlInput !== '') {
        if (filter_var($urlInput, FILTER_VALIDATE_URL)) {
            $uploadedUrls[] = $urlInput;
        } else {
            return [
                'code'    => 'rest_error',
                'message' => '无效的图片链接',
                'data'    => ['status' => 400],
            ];
        }
    }

    if (empty($uploadedUrls)) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '请上传图片或提供图片链接',
            'data'    => ['status' => 400],
        ];
    }

    $inserted = [];
    $stmt = $db->prepare(
        "INSERT INTO photos (album_id, url, title, description) VALUES (?, ?, ?, ?)"
    );

    foreach ($uploadedUrls as $url) {
        $stmt->execute([$albumId, $url, $title, $desc]);
        $inserted[] = [
            'id'    => (int)$db->lastInsertId(),
            'url'   => $url,
            'title' => $title,
        ];

        $albumStmt = $db->prepare("SELECT cover_image FROM photo_albums WHERE id = ?");
        $albumStmt->execute([$albumId]);
        $albumRow = $albumStmt->fetch();
        if ($albumRow && empty($albumRow['cover_image'])) {
            $db->prepare("UPDATE photo_albums SET cover_image = ? WHERE id = ?")
               ->execute([$url, $albumId]);
        }
    }

    return [
        'code'    => 'rest_ok',
        'message' => '上传成功',
        'data'    => [
            'status'   => 201,
            'album_id' => $albumId,
            'photos'   => $inserted,
        ],
    ];
}

/**
 * 更新照片信息（管理员）
 */
function nova_update_photo($request) {
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可更新照片',
            'data'    => ['status' => 403],
        ];
    }

    $db = getDB();
    $id = (int)($request->get_param('id') ?? 0);

    if (!$id) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '未指定照片 ID',
            'data'    => ['status' => 400],
        ];
    }

    $checkStmt = $db->prepare("SELECT id FROM photos WHERE id = ?");
    $checkStmt->execute([$id]);
    if (!$checkStmt->fetch()) {
        return [
            'code'    => 'rest_error',
            'message' => '照片不存在',
            'data'    => ['status' => 404],
        ];
    }

    $fields = [];
    $params = [];

    $title = $request->get_param('title');
    if ($title !== null) {
        $fields[] = 'title = ?';
        $params[] = trim(strip_tags($title));
    }

    $desc = $request->get_param('description');
    if ($desc !== null) {
        $fields[] = 'description = ?';
        $params[] = trim(strip_tags($desc));
    }

    $albumId = $request->get_param('album_id');
    if ($albumId !== null) {
        $fields[] = 'album_id = ?';
        $params[] = (int)$albumId;
    }

    $sortOrder = $request->get_param('sort_order');
    if ($sortOrder !== null) {
        $fields[] = 'sort_order = ?';
        $params[] = (int)$sortOrder;
    }

    if (empty($fields)) {
        return [
            'code'    => 'rest_error',
            'message' => '未提供要更新的字段',
            'data'    => ['status' => 400],
        ];
    }

    $params[] = $id;
    $db->prepare("UPDATE photos SET " . implode(', ', $fields) . " WHERE id = ?")
       ->execute($params);

    return [
        'code'    => 'rest_ok',
        'message' => '照片已更新',
        'data'    => ['status' => 200, 'id' => $id],
    ];
}

/**
 * 删除照片（管理员）
 */
function nova_delete_photo($request) {
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可删除照片',
            'data'    => ['status' => 403],
        ];
    }

    $db = getDB();
    $id = (int)($request->get_param('id') ?? 0);

    if (!$id) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '未指定照片 ID',
            'data'    => ['status' => 400],
        ];
    }

    $stmt = $db->prepare("SELECT url, album_id FROM photos WHERE id = ?");
    $stmt->execute([$id]);
    $photo = $stmt->fetch();

    if (!$photo) {
        return [
            'code'    => 'rest_error',
            'message' => '照片不存在',
            'data'    => ['status' => 404],
        ];
    }

    if (strpos($photo['url'], '/uploads/gallery/') === 0) {
        $filePath = dirname(__DIR__, 4) . $photo['url'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    $db->prepare("DELETE FROM photos WHERE id = ?")->execute([$id]);

    $albumStmt = $db->prepare("SELECT cover_image FROM photo_albums WHERE id = ?");
    $albumStmt->execute([$photo['album_id']]);
    $album = $albumStmt->fetch();
    if ($album && $album['cover_image'] === $photo['url']) {
        $db->prepare("UPDATE photo_albums SET cover_image = '' WHERE id = ?")
           ->execute([$photo['album_id']]);
    }

    return [
        'code'    => 'rest_ok',
        'message' => '照片已删除',
        'data'    => ['status' => 200, 'deleted_id' => $id],
    ];
}

/**
 * 处理照片上传
 */
function nova_handle_photo_upload($file) {
    $uploadDir = dirname(__DIR__, 4) . '/uploads/gallery/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedMimes      = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize           = 10 * 1024 * 1024;
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
            'message' => '照片大小不能超过 10MB',
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

    $newFileName = date('Ymd_His_') . uniqid() . '.' . $fileExtension;
    $targetFile  = $uploadDir . $newFileName;

    if (!move_uploaded_file($tmpPath, $targetFile)) {
        return [
            'code'    => 'rest_error',
            'message' => '照片上传失败',
            'data'    => ['status' => 500],
        ];
    }

    return '/uploads/gallery/' . $newFileName;
}
