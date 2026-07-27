<?php
/**
 * Links Categories 路由
 * 命名空间: v1
 *
 * GET /v1/links/categories - 获取所有友链分类
 */

register_rest_route('v1', '/links/categories', [
    'methods'  => 'GET',
    'callback' => 'nova_get_link_categories',
]);

function nova_get_link_categories($request) {
    $db = getDB();

    $stmt = $db->query(
        "SELECT flc.*,
                (SELECT COUNT(*) FROM friend_links fl WHERE fl.category_id = flc.id AND fl.is_active = 1) AS link_count
         FROM friend_link_categories flc
         ORDER BY flc.sort_order ASC, flc.id ASC"
    );
    $categories = $stmt->fetchAll();

    $items = array_map(function($cat) {
        return [
            'id'          => (int)$cat['id'],
            'name'        => $cat['name'],
            'description' => $cat['description'],
            'sort_order'  => (int)$cat['sort_order'],
            'link_count'  => (int)$cat['link_count'],
            'created_at'  => $cat['created_at'],
        ];
    }, $categories);

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
