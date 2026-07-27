<?php
/**
 * Statuses Hitokoto 路由
 * 命名空间: v1
 *
 * GET /v1/public/hitokoto - 获取一言
 *
 * 参数:
 *   source - auto（默认，按配置自动选择）/ local（强制本站一言）/ intro（强制简介）
 *
 * 逻辑与 vendor/api/get_intro.php 一致
 */

register_rest_route('v1', '/public/hitokoto', [
    'methods'  => 'GET',
    'callback' => 'nova_get_statuses_hitokoto',
]);

function nova_get_statuses_hitokoto($request) {
    $db     = getDB();
    $source = $request->get_param('source') ?? 'auto';

    $configStmt = $db->query("SELECT website_intro, use_local_hitokoto FROM website_config LIMIT 1");
    $config     = $configStmt->fetch();

    $useLocalHitokoto = !empty($config['use_local_hitokoto']);
    $websiteIntro     = $config['website_intro'] ?? '';

    $wantLocal = false;
    $wantIntro = false;

    switch ($source) {
        case 'local':
            $wantLocal = true;
            break;
        case 'intro':
            $wantIntro = true;
            break;
        case 'auto':
        default:
            $wantLocal = $useLocalHitokoto;
            $wantIntro = !$useLocalHitokoto;
            break;
    }

    if ($wantLocal) {
        $count = $db->query("SELECT COUNT(*) FROM hitokoto")->fetchColumn();

        if ($count > 0) {
            $offset = mt_rand(0, $count - 1);
            $stmt   = $db->prepare("SELECT * FROM hitokoto LIMIT 1 OFFSET :offset");
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data) {
                return [
                    'code'    => 'rest_ok',
                    'message' => '获取成功',
                    'data'    => [
                        'status' => 200,
                        'source' => 'local',
                        'item'   => [
                            'id'         => (int)$data['id'],
                            'hitokoto'   => $data['hitokoto'],
                            'from'       => $data['from'],
                            'from_who'   => $data['from_who'],
                            'creator'    => $data['creator'],
                            'created_at' => $data['created_at'],
                        ],
                    ],
                ];
            }
        }

        $wantLocal = false;
        $wantIntro = true;
    }

    if ($wantIntro) {
        if (filter_var($websiteIntro, FILTER_VALIDATE_URL) || preg_match('/^https?:\/\//i', $websiteIntro)) {
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $websiteIntro);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($response !== false && $httpCode === 200) {
                    return [
                        'code'    => 'rest_ok',
                        'message' => '获取成功',
                        'data'    => [
                            'status' => 200,
                            'source' => 'api',
                            'text'   => trim($response),
                        ],
                    ];
                }
            } catch (Exception $e) {
                // fallthrough
            }
        }

        if (!empty($websiteIntro)) {
            return [
                'code'    => 'rest_ok',
                'message' => '获取成功',
                'data'    => [
                    'status' => 200,
                    'source' => 'text',
                    'text'   => $websiteIntro,
                ],
            ];
        }
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status' => 200,
            'source' => 'text',
            'text'   => '这里空空如也，快去后台添加一条吧！',
        ],
    ];
}
