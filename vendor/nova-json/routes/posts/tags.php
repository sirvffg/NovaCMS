<?php
/**
 * Tags 路由
 * 命名空间: v1
 *
 * /v1/tags - 标签列表（含文章数，按使用量降序）
 */

register_rest_route('v1', '/tags', [
    'methods'  => 'GET',
    'callback' => 'v1_get_tags',
]);

function v1_get_tags($request) {
    $db = getDB();

    $stmt = $db->query(
        "SELECT tags FROM blog_posts
         WHERE is_published = 1 AND tags IS NOT NULL AND tags != ''"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $tag_map = [];
    foreach ($rows as $tags_str) {
        foreach (explode(',', $tags_str) as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $tag_map[$tag] = ($tag_map[$tag] ?? 0) + 1;
            }
        }
    }

    $items = [];
    foreach ($tag_map as $name => $count) {
        $items[] = [
            'id'    => md5($name),
            'name'  => $name,
            'count' => $count,
        ];
    }
    usort($items, fn($a, $b) => $b['count'] - $a['count']);

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => ['status' => 200, 'items' => $items],
    ];
}
