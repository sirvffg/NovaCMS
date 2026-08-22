<?php
/**
 * 文章联合查询路由
 * 命名空间: v1
 *
 * /v1/posts/with-stats  - 返回文章列表并附带评论数
 *
 * 分页参数: ?page=1&per_page=10
 * 筛选:     ?category=xxx&tag=xxx&search=xxx
 * 排序:     ?orderby=views|created|comments（默认 created）
 */

register_rest_route('v1', '/posts/with-stats', [
    'methods'  => 'GET',
    'callback' => 'v1_get_posts_with_stats',
]);

function v1_get_posts_with_stats($request) {
    $page    = max(1, (int)($request->get_param('page') ?? 1));
    $perPage = max(1, min(50, (int)($request->get_param('per_page') ?? 10)));
    $offset  = ($page - 1) * $perPage;

    $orderby = $request->get_param('orderby') ?? 'created';
    $orderbyMap = [
        'views'   => 'p.views DESC',
        'created' => 'p.created_at DESC',
        'comments'=> 'comment_count DESC',
    ];
    $orderClause = $orderbyMap[$orderby] ?? $orderbyMap['created'];

    $db = getDB();

    $where  = "WHERE p.is_published = 1";
    $params = [];

    $category = trim((string)($request->get_param('category') ?? ''));
    if ($category !== '') {
        $where .= " AND p.category = ?";
        $params[] = $category;
    }

    $tag = trim((string)($request->get_param('tag') ?? ''));
    if ($tag !== '') {
        $where .= " AND FIND_IN_SET(?, p.tags)";
        $params[] = $tag;
    }

    $search = trim((string)($request->get_param('search') ?? ''));
    if ($search !== '') {
        $where .= " AND (p.title LIKE ? OR p.summary LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    // 总数
    $countSql = "SELECT COUNT(*) FROM blog_posts p {$where}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // 列表（LEFT JOIN blog_comments 统计评论数）
    $sql = "SELECT p.id, p.title, p.author, p.cover_image, p.summary,
                   p.category, p.tags, p.views, p.is_pinned, p.is_featured,
                   p.published_at, p.created_at, p.updated_at,
                   p.has_privacy_content, p.privacy_type,
                   p.has_paid_content, p.post_price, p.license,
                   COUNT(c.id) AS comment_count
            FROM blog_posts p
            LEFT JOIN blog_comments c ON c.post_id = p.id AND c.status = 'approved'
            {$where}
            GROUP BY p.id
            ORDER BY p.is_pinned DESC, {$orderClause}, p.id DESC
            LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    $items = array_map(function($post) {
        $item = v1_format_post_item($post);
        $item['comment_count'] = (int)$post['comment_count'];
        return $item;
    }, $posts);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status'      => 200,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 1,
            'items'       => $items,
        ],
    ];
}
