<?php
/**
 * Statuses Terms 路由
 * 命名空间: v1
 *
 * GET /v1/statuses/terms - 获取服务条款与隐私政策
 *
 * 参数:
 *   type - 可选，terms（服务条款）/ privacy（隐私政策），不传则返回全部
 */

register_rest_route('v1', '/statuses/terms', [
    'methods'  => 'GET',
    'callback' => 'nova_get_terms',
]);

function nova_get_terms($request) {
    $db = getDB();
    $config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
    $type = $request->get_param('type');

    // ── 服务条款 ──
    $terms = [];
    if (!empty($config['terms_content'])) {
        $terms[] = [
            'title'      => '服务条款',
            'content'    => '',
            'items'      => [],
            'extra_html' => $config['terms_content'],
        ];
    } else {
        $siteName = $config['website_name'] ?? '';
        $contactEmail = $config['contact_email'] ?? '';
        $terms = [
            ['title' => '1. 接受条款', 'content' => "通过访问和使用{$siteName}网站，您同意遵守这些服务条款。如果您不同意这些条款，请不要使用本网站。", 'items' => []],
            ['title' => '2. 网站描述', 'content' => "{$siteName}是一个展示个人作品、博客文章和相关服务的平台。我们致力于提供高质量的内容和良好的用户体验。", 'items' => []],
            ['title' => '3. 使用许可', 'content' => '我们授予您有限的、非独占的、不可转让的许可来使用本网站，但您必须遵守以下条件：', 'items' => [
                '不得将网站用于任何非法或未经授权的目的',
                '不得干扰或破坏网站的正常运行',
                '不得试图获取未经授权的访问权限',
                '不得复制或重复使用网站内容，除非获得明确许可',
            ]],
            ['title' => '4. 内容所有权', 'content' => '网站上的所有内容，包括但不限于文字、图片、代码、设计等，均受版权法和其他知识产权法保护。未经我们明确书面许可，您不得使用、复制或分发任何内容。', 'items' => []],
            ['title' => '5. 用户责任', 'content' => '作为用户，您同意：', 'items' => [
                '提供准确和真实的信息',
                '不发布虚假、误导性或违法内容',
                '尊重他人的知识产权和隐私权',
                '不从事任何可能损害网站声誉的活动',
            ]],
            ['title' => '6. 免责声明', 'content' => '本网站按"现状"提供，我们不对以下内容做任何保证：', 'items' => [
                '网站服务的连续性或无中断',
                '网站内容的准确性或完整性',
                '网站免受病毒或其他恶意组件的侵害',
                '因使用网站而导致的任何损失或损害',
            ]],
            ['title' => '7. 服务限制', 'content' => '我们保留以下权利：', 'items' => [
                '随时修改或终止网站服务',
                '拒绝向任何人提供服务',
                '删除违反服务条款的内容',
                '暂停或终止违规用户的访问权限',
            ]],
            ['title' => '8. 第三方链接', 'content' => '本网站可能包含指向第三方网站的链接。我们不对这些外部网站的内容、隐私政策或做法负责。访问第三方网站的风险由您自行承担。', 'items' => []],
            ['title' => '9. 争议解决', 'content' => '这些服务条款受中国法律管辖。如发生争议，双方应首先通过友好协商解决。协商不成的，任何一方均可向网站经营者所在地人民法院提起诉讼。', 'items' => []],
            ['title' => '10. 条款修改', 'content' => '我们保留随时修改这些服务条款的权利。修改后的条款将在网站上发布，并立即生效。继续使用本网站即表示您接受修改后的条款。', 'items' => []],
            ['title' => '11. 联系我们', 'content' => "如果您对这些服务条款有任何疑问，请通过以下方式联系我们：", 'items' => [], 'extra_html' => $contactEmail ? "<p>邮箱：<a href=\"mailto:{$contactEmail}\">{$contactEmail}</a></p>" : ''],
        ];
    }

    // ── 隐私政策 ──
    $privacy = [];
    if (!empty($config['privacy_content'])) {
        $privacy[] = [
            'title'      => '隐私政策',
            'content'    => '',
            'items'      => [],
            'extra_html' => $config['privacy_content'],
        ];
    } else {
        $privacy = [
            ['title' => '1. 信息收集', 'content' => '我们可能收集以下类型的信息：', 'items' => [
                '您通过联系表单提供的姓名、电子邮件地址等信息',
                '访问网站时的技术信息（IP地址、浏览器类型、访问时间等）',
                '通过Cookie收集的使用偏好信息',
            ]],
            ['title' => '2. 信息使用', 'content' => '收集的信息可能用于：', 'items' => [
                '回复您的咨询和请求',
                '改善网站内容和用户体验',
                '发送重要的通知和更新',
                '网站分析和安全监控',
            ]],
            ['title' => '3. 信息共享', 'content' => '我们不会向第三方出售、交易或转让您的个人信息，除非：', 'items' => [
                '获得您的明确同意',
                '法律要求或法律程序需要',
                '保护网站、用户或公众的权利、财产或安全',
            ]],
            ['title' => '4. 数据安全', 'content' => '我们采取适当的安全措施来保护您的个人信息，包括：', 'items' => [
                '使用安全的服务器和加密技术',
                '限制对个人信息的访问权限',
                '定期更新安全协议',
            ]],
            ['title' => '5. Cookie使用', 'content' => '本网站可能使用Cookie来：', 'items' => [
                '记住您的偏好设置',
                '分析网站流量和使用情况',
                '提供个性化的内容',
            ]],
            ['title' => '6. 您的权利', 'content' => '您有权：', 'items' => [
                '访问您的个人信息',
                '更正不准确的信息',
                '删除您的个人信息',
                '反对处理您的信息',
            ]],
            ['title' => '7. 政策更新', 'content' => '我们可能会不时更新此隐私政策。重大变更时，我们会通过网站通知您。建议您定期查看此页面以获取最新信息。', 'items' => []],
            ['title' => '8. 联系我们', 'content' => '如果您对此隐私政策有任何疑问或关注，请通过以下方式联系我们：', 'items' => [], 'extra_html' => $contactEmail ? "<p>邮箱：<a href=\"mailto:{$contactEmail}\">{$contactEmail}</a></p>" : ''],
        ];
    }

    $data = ['status' => 200];

    if ($type === 'terms') {
        $data['items'] = $terms;
    } elseif ($type === 'privacy') {
        $data['items'] = $privacy;
    } else {
        $data['terms']   = $terms;
        $data['privacy'] = $privacy;
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => $data,
    ];
}
