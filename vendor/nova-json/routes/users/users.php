<?php
/**
 * Users 路由
 * 命名空间: v1
 *
 * /v1/users          - 用户列表 (GET) / 创建用户 (POST)
 * /v1/users/me       - 当前用户 (GET / PUT)
 * /v1/users/{id}     - 指定用户 (GET / PUT / DELETE)
 *
 * 数据表: admins
 * 密码: bcrypt ($2y$12$...)
 */

// =============================================
// GET /v1/users - 用户列表
// =============================================
register_rest_route('v1', '/users', [
    'methods'  => 'GET',
    'callback' => 'v1_get_users',
]);

function v1_get_users($request) {
    // 仅管理员可查看用户列表
    if (!v1_is_admin(v1_get_current_user_id())) {
        return new Nova_REST_Response([
            'code'    => 'rest_forbidden',
            'message' => '权限不足，仅管理员可查看用户列表',
            'data'    => ['status' => 403],
        ], 403);
    }

    $db = getDB();

    $stmt = $db->query(
        "SELECT id, username, email, role, last_login, created_at, is_banned
         FROM admins ORDER BY id ASC"
    );
    $users = $stmt->fetchAll();

    $items = array_map(function($u) {
        return [
            'id'         => (int)$u['id'],
            'username'   => $u['username'],
            'email'      => $u['email'],
            'role'       => $u['role'],
            'last_login' => $u['last_login'],
            'created_at' => $u['created_at'],
            'is_banned'  => (bool)$u['is_banned'],
        ];
    }, $users);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => ['status' => 200, 'total' => count($items), 'items' => $items],
    ];
}

// =============================================
// POST /v1/users - 创建用户
// =============================================
register_rest_route('v1', '/users', [
    'methods'  => 'POST',
    'callback' => 'v1_create_user',
]);

function v1_create_user($request) {
    // 仅管理员可创建用户
    $userId = v1_get_current_user_id();
    if ($userId <= 0 || !v1_is_admin($userId)) {
        return new Nova_REST_Response([
            'code'    => 'rest_cannot_create',
            'message' => '仅管理员可创建用户',
            'data'    => ['status' => 403],
        ], 403);
    }

    $username = trim(strip_tags($request->get_param('username') ?: ''));
    $password = $request->get_param('password') ?: '';
    $email    = trim(strip_tags($request->get_param('email') ?: ''));
    $role     = in_array($request->get_param('role'), ['admin', 'user']) ? $request->get_param('role') : 'user';

    if (empty($username) || empty($password)) {
        return new Nova_REST_Response([
            'code'    => 'rest_missing_fields',
            'message' => '用户名和密码不能为空',
            'data'    => ['status' => 400],
        ], 400);
    }

    $db = getDB();

    // 检查用户名重复
    $stmt = $db->prepare("SELECT id FROM admins WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return new Nova_REST_Response([
            'code'    => 'rest_duplicate_username',
            'message' => '用户名已存在',
            'data'    => ['status' => 409],
        ], 409);
    }

    // 检查邮箱重复
    if (!empty($email)) {
        $stmt = $db->prepare("SELECT id FROM admins WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return new Nova_REST_Response([
                'code'    => 'rest_duplicate_email',
                'message' => '邮箱已被使用',
                'data'    => ['status' => 409],
            ], 409);
        }
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $insert = $db->prepare(
        "INSERT INTO admins (username, password, email, role, created_at) VALUES (?, ?, ?, ?, NOW())"
    );
    $insert->execute([$username, $hash, $email, $role]);
    $newId = (int)$db->lastInsertId();

    return [
        'code'    => 'rest_ok',
        'message' => '用户已创建',
        'data'    => ['status' => 201, 'id' => $newId, 'username' => $username, 'role' => $role],
    ];
}

// =============================================
// GET /v1/users/me - 当前用户
// =============================================
register_rest_route('v1', '/users/me', [
    'methods'  => 'GET',
    'callback' => 'v1_get_current_user',
]);

function v1_get_current_user($request) {
    $userId = v1_get_current_user_id();
    if ($userId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_not_logged_in',
            'message' => '未登录',
            'data'    => ['status' => 401],
        ], 401);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, email, role, last_login, created_at FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return new Nova_REST_Response([
            'code'    => 'rest_user_not_found',
            'message' => '用户不存在',
            'data'    => ['status' => 404],
        ], 404);
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => ['status' => 200, 'item' => [
            'id'         => (int)$user['id'],
            'username'   => $user['username'],
            'email'      => $user['email'],
            'role'       => $user['role'],
            'last_login' => $user['last_login'],
            'created_at' => $user['created_at'],
        ]],
    ];
}

// =============================================
// PUT /v1/users/me - 更新当前用户
// =============================================
register_rest_route('v1', '/users/me', [
    'methods'  => 'PUT',
    'callback' => 'v1_update_current_user',
]);

function v1_update_current_user($request) {
    $userId = v1_get_current_user_id();
    if ($userId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_not_logged_in',
            'message' => '未登录',
            'data'    => ['status' => 401],
        ], 401);
    }

    return v1_update_user_by_id($userId, $request);
}

// =============================================
// GET /v1/users/{id} - 指定用户
// =============================================
register_rest_route('v1', '/users/{id}', [
    'methods'  => 'GET',
    'callback' => 'v1_get_user',
]);

function v1_get_user($request) {
    $db  = getDB();
    $id  = (int)$request->get_param('id');
    $currentUserId = v1_get_current_user_id();

    // 仅自己和管理员可查看用户信息，不公开
    if ($currentUserId <= 0 || ($currentUserId !== $id && !v1_is_admin($currentUserId))) {
        return new Nova_REST_Response([
            'code'    => 'rest_forbidden',
            'message' => '无权查看该用户信息',
            'data'    => ['status' => 403],
        ], 403);
    }

    $stmt = $db->prepare("SELECT id, username, email, role, last_login, created_at, is_banned FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) {
        return new Nova_REST_Response([
            'code'    => 'rest_user_not_found',
            'message' => '用户不存在',
            'data'    => ['status' => 404],
        ], 404);
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => ['status' => 200, 'item' => [
            'id'         => (int)$user['id'],
            'username'   => $user['username'],
            'email'      => $user['email'],
            'role'       => $user['role'],
            'last_login' => $user['last_login'],
            'created_at' => $user['created_at'],
            'is_banned'  => (bool)$user['is_banned'],
        ]],
    ];
}

// =============================================
// PUT /v1/users/{id} - 更新用户
// =============================================
register_rest_route('v1', '/users/{id}', [
    'methods'  => 'PUT',
    'callback' => 'v1_update_user',
]);

function v1_update_user($request) {
    $currentUserId = v1_get_current_user_id();
    if ($currentUserId <= 0) {
        return new Nova_REST_Response([
            'code'    => 'rest_not_logged_in',
            'message' => '未登录',
            'data'    => ['status' => 401],
        ], 401);
    }

    $id = (int)$request->get_param('id');

    // 只能更新自己，或管理员更新任何人
    if ($currentUserId !== $id && !v1_is_admin($currentUserId)) {
        return new Nova_REST_Response([
            'code'    => 'rest_cannot_edit',
            'message' => '无权修改其他用户信息',
            'data'    => ['status' => 403],
        ], 403);
    }

    return v1_update_user_by_id($id, $request);
}

// =============================================
// DELETE /v1/users/{id} - 删除用户
// =============================================
register_rest_route('v1', '/users/{id}', [
    'methods'  => 'DELETE',
    'callback' => 'v1_delete_user',
]);

function v1_delete_user($request) {
    $currentUserId = v1_get_current_user_id();
    if ($currentUserId <= 0 || !v1_is_admin($currentUserId)) {
        return new Nova_REST_Response([
            'code'    => 'rest_cannot_delete',
            'message' => '仅管理员可删除用户',
            'data'    => ['status' => 403],
        ], 403);
    }

    $db = getDB();
    $id = (int)$request->get_param('id');

    if ($id === $currentUserId) {
        return new Nova_REST_Response([
            'code'    => 'rest_cannot_delete_self',
            'message' => '不能删除自己',
            'data'    => ['status' => 400],
        ], 400);
    }

    $stmt = $db->prepare("SELECT id FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        return new Nova_REST_Response([
            'code'    => 'rest_user_not_found',
            'message' => '用户不存在',
            'data'    => ['status' => 404],
        ], 404);
    }

    // 清除该用户的会话记录
    $db->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$id]);
    // 删除用户
    $db->prepare("DELETE FROM admins WHERE id = ?")->execute([$id]);

    return [
        'code'    => 'rest_ok',
        'message' => '用户已删除',
        'data'    => ['status' => 200, 'id' => $id],
    ];
}

// =============================================
// 辅助函数
// =============================================

/**
 * 更新用户通用逻辑（供 /me 和 /{id} 共用）
 */
function v1_update_user_by_id($id, $request) {
    $db = getDB();

    $stmt = $db->prepare("SELECT id, password FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) {
        return new Nova_REST_Response([
            'code'    => 'rest_user_not_found',
            'message' => '用户不存在',
            'data'    => ['status' => 404],
        ], 404);
    }

    $fields = [];
    $params = [];

    $email = trim(strip_tags($request->get_param('email') ?: ''));
    if ($request->get_param('email') !== null) {
        // LY-004: email 字段 XSS 防护 — 验证邮箱格式并过滤 HTML
        if (!empty($email)) {
            // 过滤掉所有 HTML 标签
            $email = strip_tags($email);
            // 验证邮箱格式
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return new Nova_REST_Response([
                    'code'    => 'rest_invalid_email',
                    'message' => '邮箱格式无效',
                    'data'    => ['status' => 400],
                ], 400);
            }
            $stmt = $db->prepare("SELECT id FROM admins WHERE email = ? AND id != ? LIMIT 1");
            $stmt->execute([$email, $id]);
            if ($stmt->fetch()) {
                return new Nova_REST_Response([
                    'code'    => 'rest_duplicate_email',
                    'message' => '邮箱已被其他用户使用',
                    'data'    => ['status' => 409],
                ], 409);
            }
        }
        $fields[] = 'email = ?';
        $params[] = $email;
    }

    $password = $request->get_param('password');
    if (!empty($password)) {
        // LY-003: 修改密码必须验证旧密码
        $oldPassword = $request->get_param('old_password');
        if (empty($oldPassword)) {
            return new Nova_REST_Response([
                'code'    => 'rest_missing_old_password',
                'message' => '修改密码必须提供旧密码',
                'data'    => ['status' => 400],
            ], 400);
        }
        if (!password_verify($oldPassword, $user['password'])) {
            return new Nova_REST_Response([
                'code'    => 'rest_invalid_old_password',
                'message' => '旧密码错误',
                'data'    => ['status' => 403],
            ], 403);
        }
        $fields[] = 'password = ?';
        $params[] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    $role = $request->get_param('role');
    if ($role !== null && in_array($role, ['admin', 'user'])) {
        // 仅管理员可修改角色
        $currentUserId = v1_get_current_user_id();
        if (v1_is_admin($currentUserId)) {
            $fields[] = 'role = ?';
            $params[] = $role;
        }
    }

    if (empty($fields)) {
        return [
            'code'    => 'rest_ok',
            'message' => '无需更新',
            'data'    => ['status' => 200, 'id' => $id],
        ];
    }

    $params[] = $id;
    $db->prepare("UPDATE admins SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    return [
        'code'    => 'rest_ok',
        'message' => '用户已更新',
        'data'    => ['status' => 200, 'id' => $id],
    ];
}
