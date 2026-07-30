<?php
/**
 * Posts 路由
 * 命名空间: v1
 *
 * /v1/posts                   - 文章列表（不带分页参数则返回全部）
 * /v1/posts/{id}              - 文章详情
 * /v1/posts/slug/{slug}       - 按标识查文章
 *
 * 分页参数: ?page=1&per_page=10
 * 全文:     ?content=1
 * 筛选:     ?category=xxx&tag=xxx&search=xxx
 */

register_rest_route('v1', '/posts', [
    'methods'  => 'GET',
    'callback' => 'v1_get_post_list',
]);

register_rest_route('v1', '/posts/{id}', [
    'methods'  => 'GET',
    'callback' => 'v1_get_single_post',
]);

register_rest_route('v1', '/posts/slug/{slug}', [
    'methods'  => 'GET',
    'callback' => 'v1_get_post_by_slug',
]);

function v1_get_post_list($request) {
    $db = getDB();

    // 分页：不传 per_page 则返回全部
    $raw_per_page = $request->get_param('per_page');
    $has_pagination = $raw_per_page !== null;
    if ($has_pagination) {
        $per_page = min(100, max(1, (int)$raw_per_page));
        $page     = max(1, (int)$request->get_param('page') ?: 1);
        $offset   = ($page - 1) * $per_page;
    } else {
        $per_page = 0;
        $page     = 1;
        $offset   = 0;
    }

    $category    = $request->get_param('category') ?: '';
    $tag         = $request->get_param('tag') ?: '';
    $search      = $request->get_param('search') ?: '';
    $showContent = (bool)$request->get_param('content');

    $where  = "WHERE p.is_published = 1";
    $params = [];

    if (!empty($category)) {
        $where .= " AND p.category = ?";
        $params[] = $category;
    }
    if (!empty($tag)) {
        $where .= " AND FIND_IN_SET(?, p.tags)";
        $params[] = $tag;
    }
    if (!empty($search)) {
        $where .= " AND (p.title LIKE ? OR p.summary LIKE ? OR p.content LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    $count_stmt = $db->prepare("SELECT COUNT(*) FROM blog_posts p {$where}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    $selectFields = "p.id, p.title, p.author, p.cover_image, p.summary,
                     p.category, p.tags, p.views, p.is_pinned, p.is_featured,
                     p.published_at, p.created_at, p.updated_at,
                     p.has_privacy_content, p.privacy_type,
                     p.has_paid_content, p.post_price,
                     p.license";
    if ($showContent) {
        $selectFields .= ", p.content";
    }

    $sql = "SELECT {$selectFields}
            FROM blog_posts p {$where}
            ORDER BY p.is_pinned DESC, COALESCE(p.published_at, p.created_at) DESC, p.id DESC";

    if ($has_pagination) {
        $sql .= " LIMIT {$per_page} OFFSET {$offset}";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    $items = array_map(function($post) use ($showContent) {
        if ($showContent) {
            return v1_format_post_detail($post);
        }
        return v1_format_post_item($post);
    }, $posts);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status'      => 200,
            'total'       => $total,
            'page'        => $has_pagination ? $page : 1,
            'per_page'    => $has_pagination ? $per_page : $total,
            'total_pages' => $has_pagination ? (int)ceil($total / $per_page) : 1,
            'items'       => $items,
        ],
    ];
}

function v1_get_single_post($request) {
    $db = getDB();
    $id = (int)$request->get_param('id');

    $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ? AND is_published = 1 LIMIT 1");
    $stmt->execute([$id]);
    $post = $stmt->fetch();

    if (!$post) {
        return new Nova_REST_Response([
            'code'    => 'post_not_found',
            'message' => '文章不存在',
            'data'    => ['status' => 404],
        ], 404);
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => ['status' => 200, 'item' => v1_format_post_detail($post)],
    ];
}

function v1_get_post_by_slug($request) {
    $db   = getDB();
    $slug = $request->get_param('slug');

    $stmt = $db->prepare("SELECT * FROM blog_posts WHERE title = ? AND is_published = 1 LIMIT 1");
    $stmt->execute([urldecode($slug)]);
    $post = $stmt->fetch();

    if (!$post && is_numeric($slug)) {
        $idStmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ? AND is_published = 1 LIMIT 1");
        $idStmt->execute([(int)$slug]);
        $post = $idStmt->fetch();
    }

    if (!$post) {
        return new Nova_REST_Response([
            'code'    => 'post_not_found',
            'message' => '文章不存在',
            'data'    => ['status' => 404],
        ], 404);
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => ['status' => 200, 'item' => v1_format_post_detail($post)],
    ];
}

function v1_format_post_item($post) {
    $db     = getDB();
    $userId = v1_get_current_user_id();
    $postId = (int)$post['id'];

    // 检查当前用户对隐私/付费内容的访问权限
    $hasPrivacyAccess = false;
    $hasPaidAccess    = false;
    if ($userId > 0) {
        if (!empty($post['has_privacy_content'])) {
            $hasPrivacyAccess = hasPrivacyAccess($db, $userId, $postId);
        }
        if (!empty($post['has_paid_content'])) {
            $hasPaidAccess = hasPaidAccess($db, $userId, $postId);
        }
    }

    $item = [
        'id'           => (int)$post['id'],
        'title'        => $post['title'],
        'author'       => $post['author'],
        'cover_image'  => $post['cover_image'],
        'summary'      => (string)($post['summary'] ?? ''),
        'category'     => $post['category'],
        'tags'         => $post['tags'] ? explode(',', $post['tags']) : [],
        'views'        => (int)$post['views'],
        'is_pinned'    => (bool)$post['is_pinned'],
        'is_featured'  => (bool)$post['is_featured'],
        'published_at' => $post['published_at'] ?: $post['created_at'],
        'created_at'   => $post['created_at'],
        'updated_at'   => $post['updated_at'],
        'license'      => $post['license'],
        'has_privacy_content' => (bool)($post['has_privacy_content'] ?? false),
        'has_paid_content'    => (bool)($post['has_paid_content'] ?? false),
        'current_user_id'                 => $userId,
        'current_user_has_privacy_access' => $hasPrivacyAccess,
        'current_user_has_paid_access'    => $hasPaidAccess,
    ];

    // 存在隐私内容时返回隐私类型
    if (!empty($post['has_privacy_content'])) {
        $item['privacy_type'] = $post['privacy_type'] ?? 'login_only';
    }

    // 存在付费内容时返回价格
    if (!empty($post['has_paid_content'])) {
        $item['post_price'] = (float)($post['post_price'] ?? 0);
    }

    return $item;
}

function v1_format_post_detail($post) {
    $item = v1_format_post_item($post);
    $db     = getDB();
    $userId = v1_get_current_user_id();
    $postId = (int)$post['id'];

    $content = $post['content'] ?? '';

    // 根据权限过滤隐私内容标记
    if (!empty($post['has_privacy_content'])) {
        $content = processBlogContent($db, $userId, $postId, $content);
    }

    // 根据权限过滤付费内容标记
    if (!empty($post['has_paid_content'])) {
        $content = processPaidContent($db, $userId, $postId, $content);
    }

    $item['content'] = $content;
    return $item;
}
