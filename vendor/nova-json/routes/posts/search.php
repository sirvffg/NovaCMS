<?php
/**
 * Search 路由
 * 命名空间: v1
 *
 * /v1/search - 跨内容搜索
 *
 * 参数:
 *   q         - 搜索关键词（必填）
 *   type      - 搜索类型: post（默认）
 *   field     - 搜索字段: all（默认/全部）, title, tags, content
 *   page      - 页码
 *   per_page  - 每页条数（不传则返回全部）
 */

register_rest_route('v1', '/search', [
    'methods'  => 'GET',
    'callback' => 'v1_search',
]);

function v1_search($request) {
    $q    = $request->get_param('q') ?: '';
    $type = $request->get_param('type') ?: 'post';

    if (empty($q)) {
        return new Nova_REST_Response([
            'code'    => 'search_missing_query',
            'message' => '缺少搜索关键词: q',
            'data'    => ['status' => 400],
        ], 400);
    }

    $db = getDB();

    if ($type === 'post') {
        return v1_search_posts($db, $request);
    }

    return new Nova_REST_Response([
        'code'    => 'rest_invalid_search_type',
        'message' => '不支持的搜索类型: ' . $type,
        'data'    => ['status' => 400],
    ], 400);
}

function v1_search_posts($db, $request) {
    $q      = $request->get_param('q') ?: '';
    $field  = $request->get_param('field') ?: 'all';
    $likeQ  = "%{$q}%";

    // 分页：不传 per_page 则返回全部
    $raw_per_page = $request->get_param('per_page');
    $has_pagination = $raw_per_page !== null;
    if ($has_pagination) {
        $per_page = min(50, max(1, (int)$raw_per_page));
        $page     = max(1, (int)$request->get_param('page') ?: 1);
        $offset   = ($page - 1) * $per_page;
    } else {
        $per_page = 0;
        $page     = 1;
        $offset   = 0;
    }

    // 根据 field 参数构建搜索条件
    $whereClause = '';
    $params = [$likeQ];
    switch ($field) {
        case 'title':
            $whereClause = 'title LIKE ?';
            break;
        case 'tags':
            $whereClause = 'FIND_IN_SET(?, tags)';
            $params[0] = $q; // tags 用精确匹配，不加 %%
            break;
        case 'content':
            $whereClause = 'content LIKE ?';
            break;
        default: // all
            $whereClause = '(title LIKE ? OR content LIKE ?)';
            $params[] = $likeQ;
            break;
    }

    $count_stmt = $db->prepare(
        "SELECT COUNT(*) FROM blog_posts WHERE is_published = 1 AND {$whereClause}"
    );
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    $sql = "SELECT id, title, author, cover_image, category, tags,
                   published_at, created_at,
                   has_privacy_content, has_paid_content
            FROM blog_posts
            WHERE is_published = 1 AND {$whereClause}
            ORDER BY is_pinned DESC, id ASC";

    if ($has_pagination) {
        $sql .= " LIMIT {$per_page} OFFSET {$offset}";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    $items = array_map(function($p) {
        return [
            'id'                  => (int)$p['id'],
            'title'               => $p['title'],
            'author'              => $p['author'],
            'cover_image'         => $p['cover_image'],
            'category'            => $p['category'],
            'tags'                => $p['tags'] ? explode(',', $p['tags']) : [],
            'published_at'        => $p['published_at'] ?: $p['created_at'],
            'has_privacy_content' => (bool)($p['has_privacy_content'] ?? false),
            'has_paid_content'    => (bool)($p['has_paid_content'] ?? false),
            'type'                => 'post',
        ];
    }, $posts);

    return [
        'code'    => 'rest_ok',
        'message' => '搜索成功',
        'data'    => [
            'status'      => 200,
            'total'       => $total,
            'page'        => $has_pagination ? $page : 1,
            'per_page'    => $has_pagination ? $per_page : $total,
            'total_pages' => $has_pagination ? (int)ceil($total / $per_page) : 1,
            'query'       => $q,
            'field'       => $field,
            'type'        => 'post',
            'items'       => $items,
        ],
    ];
}
