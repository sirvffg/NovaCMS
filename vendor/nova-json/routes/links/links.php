<?php
/**
 * Links 路由
 * 命名空间: v1
 *
 * GET /v1/links - 获取所有活跃友情链接（含分类分组）
 */

register_rest_route('v1', '/links', [
    'methods'  => 'GET',
    'callback' => 'nova_get_links',
]);

function nova_get_links($request) {
    $db = getDB();

    // 获取所有分类（按 sort_order 排序）
    $catStmt = $db->query(
        "SELECT * FROM friend_link_categories ORDER BY sort_order ASC, id ASC"
    );
    $allCategories = $catStmt->fetchAll();

    // 将"单向友链"和"失联博客"排在最后（与前台 friend-links.php 一致）
    $specialCategories = ['单向友链', '失联博客'];
    $normalCats = [];
    $lastCats  = [];
    foreach ($allCategories as $cat) {
        if (in_array($cat['name'], $specialCategories)) {
            $lastCats[] = $cat;
        } else {
            $normalCats[] = $cat;
        }
    }
    $sortedCategories = array_merge($normalCats, $lastCats);

    // 获取所有活跃友链（按 sort_order ASC, id DESC）
    $stmt = $db->query(
        "SELECT fl.*, flc.name AS category_name
         FROM friend_links fl
         LEFT JOIN friend_link_categories flc ON fl.category_id = flc.id
         WHERE fl.is_active = 1
         ORDER BY fl.sort_order ASC, fl.id DESC"
    );
    $links = $stmt->fetchAll();

    // 构建 links 分类索引
    $linksByCategoryId = [];
    $uncategorized = [];
    foreach ($links as $link) {
        $item = [
            'id'          => (int)$link['id'],
            'name'        => $link['name'],
            'url'         => $link['url'],
            'logo'        => $link['logo'],
            'description' => $link['description'],
            'rss_url'     => $link['rss_url'],
            'category'    => $link['category_id']
                ? ['id' => (int)$link['category_id'], 'name' => $link['category_name']]
                : null,
            'sort_order'  => (int)$link['sort_order'],
            'created_at'  => $link['created_at'],
        ];
        if ($link['category_id']) {
            $linksByCategoryId[(int)$link['category_id']][] = $item;
        } else {
            $uncategorized[] = $item;
        }
    }

    // 按前台分类顺序组装 grouped
    $grouped = [];
    foreach ($sortedCategories as $cat) {
        $catId = (int)$cat['id'];
        if (!empty($linksByCategoryId[$catId])) {
            $grouped[$cat['name']] = $linksByCategoryId[$catId];
        }
    }
    // 未分类的放到最后
    if (!empty($uncategorized)) {
        $grouped['未分类'] = $uncategorized;
    }

    // items 按 grouped 的分类顺序排列
    $items = [];
    foreach ($grouped as $catItems) {
        foreach ($catItems as $item) {
            $items[] = $item;
        }
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status'  => 200,
            'total'   => count($items),
            'items'   => $items,
            'grouped' => $grouped,
        ],
    ];
}
