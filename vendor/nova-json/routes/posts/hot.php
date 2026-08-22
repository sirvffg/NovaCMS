<?php
/**
 * 热门文章排行路由
 * 命名空间: v1
 *
 * /v1/posts/hot  - 按 views 字段倒序返回热门文章
 *
 * 参数:
 *   ?limit=10      返回条数（1-50）
 *   ?days=0        限定时间范围（0=不限，按 published_at/created_at 过滤）
 */

register_rest_route('v1', '/posts/hot', [
    'methods'  => 'GET',
    'callback' => 'v1_get_hot_posts',
]);

function v1_get_hot_posts($request) {
    $limit = max(1, min(50, (int)($request->get_param('limit') ?? 10)));
    $days  = (int)($request->get_param('days') ?? 0);

    $db = getDB();

    $where  = "WHERE p.is_published = 1";
    $params = [];

    if ($days > 0) {
        $where .= " AND COALESCE(NULLIF(p.published_at, ''), p.created_at) >= DATE_SUB(NOW(), INTERVAL {$days} DAY)";
    }

    $sql = "SELECT p.id, p.title, p.author, p.cover_image, p.summary,
                   p.category, p.tags, p.views, p.is_pinned, p.is_featured,
                   p.published_at, p.created_at, p.updated_at,
                   p.has_privacy_content, p.privacy_type,
                   p.has_paid_content, p.post_price, p.license
            FROM blog_posts p
            {$where}
            ORDER BY p.views DESC, p.is_pinned DESC, p.id DESC
            LIMIT {$limit}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    $items = array_map('v1_format_post_item', $posts);

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
