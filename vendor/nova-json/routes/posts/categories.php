<?php
/**
 * Categories 路由
 * 命名空间: v1
 *
 * /v1/categories       - 分类列表 (GET) / 创建分类 (POST)
 * /v1/categories/{id}  - 更新分类 (PUT) / 删除分类 (DELETE)
 *
 * 数据表: blog_categories
 * blog_posts.category 存储分类名称（字符串），通过名称关联
 */

register_rest_route('v1', '/categories', [
    'methods'  => 'GET',
    'callback' => 'v1_get_categories',
]);

register_rest_route('v1', '/categories', [
    'methods'  => 'POST',
    'callback' => 'v1_create_category',
]);

register_rest_route('v1', '/categories/{id}', [
    'methods'  => 'PUT',
    'callback' => 'v1_update_category',
]);

register_rest_route('v1', '/categories/{id}', [
    'methods'  => 'DELETE',
    'callback' => 'v1_delete_category',
]);

// =============================================
// GET /v1/categories - 分类列表
// =============================================
function v1_get_categories($request) {
    $db = getDB();

    $stmt = $db->query(
        "SELECT c.*,
                (SELECT COUNT(*) FROM blog_posts p WHERE p.is_published = 1 AND p.category = c.name) AS post_count
         FROM blog_categories c
         ORDER BY c.sort_order ASC, c.id ASC"
    );
    $rows = $stmt->fetchAll();

    $items = array_map(function($cat) {
        return [
            'id'          => (int)$cat['id'],
            'name'        => $cat['name'],
            'slug'        => $cat['slug'],
            'description' => $cat['description'],
            'color'       => $cat['color'],
            'sort_order'  => (int)$cat['sort_order'],
            'post_count'  => (int)$cat['post_count'],
            'created_at'  => $cat['created_at'],
        ];
    }, $rows);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => ['status' => 200, 'items' => $items],
    ];
}

// =============================================
// POST /v1/categories - 创建分类
// =============================================
function v1_create_category($request) {
    // 仅管理员可操作
    $userId = v1_get_current_user_id();
    if ($userId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_not_logged_in',
            'message' => '请先登录',
            'data'    => ['status' => 401],
        ], 401);
    }

    $name   = trim(strip_tags($request->get_param('name') ?: ''));
    $slug   = trim(strip_tags($request->get_param('slug') ?: ''));
    $desc   = trim(strip_tags($request->get_param('description') ?: ''));
    $order  = (int)($request->get_param('sort_order') ?: 0);
    $color  = trim(strip_tags($request->get_param('color') ?: '#007bff'));

    if (empty($name)) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_name',
            'message' => '分类名称不能为空',
            'data'    => ['status' => 400],
        ], 400);
    }

    // 自动生成 slug
    if (empty($slug)) {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    }

    // 检查 slug 重复
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM blog_categories WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
        return new Nova_REST_Response([
            'code'    => 'rest_duplicate_slug',
            'message' => '分类别名已存在',
            'data'    => ['status' => 409],
        ], 409);
    }

    $insert = $db->prepare(
        "INSERT INTO blog_categories (name, slug, description, sort_order, color) VALUES (?, ?, ?, ?, ?)"
    );
    $insert->execute([$name, $slug, $desc, $order, $color]);
    $newId = (int)$db->lastInsertId();

    return [
        'code'    => 'rest_ok',
        'message' => '分类已创建',
        'data'    => ['status' => 201, 'id' => $newId],
    ];
}

// =============================================
// PUT /v1/categories/{id} - 更新分类
// =============================================
function v1_update_category($request) {
    $userId = v1_get_current_user_id();
    if ($userId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_not_logged_in',
            'message' => '请先登录',
            'data'    => ['status' => 401],
        ], 401);
    }

    $db   = getDB();
    $id   = (int)$request->get_param('id');
    $name = trim(strip_tags($request->get_param('name') ?: ''));
    // 检查存在
    $stmt = $db->prepare("SELECT id FROM blog_categories WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        return new Nova_REST_Response([
            'code'    => 'rest_category_not_found',
            'message' => '分类不存在',
            'data'    => ['status' => 404],
        ], 404);
    }

    if (empty($name)) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_name',
            'message' => '分类名称不能为空',
            'data'    => ['status' => 400],
        ], 400);
    }

    $slug  = trim(strip_tags($request->get_param('slug') ?: ''));
    $desc  = trim(strip_tags($request->get_param('description') ?: ''));
    $order = (int)($request->get_param('sort_order') ?: 0);
    $color = trim(strip_tags($request->get_param('color') ?: '#007bff'));

    if (empty($slug)) {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    }

    // 检查 slug 重复（排除自身）
    $check = $db->prepare("SELECT id FROM blog_categories WHERE slug = ? AND id != ? LIMIT 1");
    $check->execute([$slug, $id]);
    if ($check->fetch()) {
        return new Nova_REST_Response([
            'code'    => 'rest_duplicate_slug',
            'message' => '分类别名已被其他分类使用',
            'data'    => ['status' => 409],
        ], 409);
    }

    $update = $db->prepare(
        "UPDATE blog_categories SET name = ?, slug = ?, description = ?, sort_order = ?, color = ? WHERE id = ?"
    );
    $update->execute([$name, $slug, $desc, $order, $color, $id]);

    return [
        'code'    => 'rest_ok',
        'message' => '分类已更新',
        'data'    => ['status' => 200, 'id' => $id],
    ];
}

// =============================================
// DELETE /v1/categories/{id} - 删除分类
// =============================================
function v1_delete_category($request) {
    $userId = v1_get_current_user_id();
    if ($userId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_not_logged_in',
            'message' => '请先登录',
            'data'    => ['status' => 401],
        ], 401);
    }

    $db = getDB();
    $id = (int)$request->get_param('id');

    $stmt = $db->prepare("SELECT id, name FROM blog_categories WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $cat = $stmt->fetch();
    if (!$cat) {
        return new Nova_REST_Response([
            'code'    => 'rest_category_not_found',
            'message' => '分类不存在',
            'data'    => ['status' => 404],
        ], 404);
    }

    // 级联清除：将该分类下所有文章的 category 置空
    $catName = $cat['name'];
    $clear = $db->prepare("UPDATE blog_posts SET category = '' WHERE category = ?");
    $clear->execute([$catName]);
    $affected = $clear->rowCount();

    // 删除分类
    $delete = $db->prepare("DELETE FROM blog_categories WHERE id = ?");
    $delete->execute([$id]);

    return [
        'code'    => 'rest_ok',
        'message' => '分类已删除，已清空 ' . $affected . ' 篇文章的分类',
        'data'    => ['status' => 200, 'id' => $id, 'cleared_post_count' => $affected],
    ];
}
