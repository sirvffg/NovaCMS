<?php
global $requestPath;
$currentPath = isset($requestPath) && is_string($requestPath)
    ? $requestPath
    : (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$tabQuery = $_GET['tab'] ?? '';
if ($tabQuery === 'privacy') {
    $activeTab = 'privacy';
} elseif ($tabQuery === 'terms') {
    $activeTab = 'terms';
} elseif ($currentPath === '/privacy' || $currentPath === '/privacy.php') {
    $activeTab = 'privacy';
} else {
    $activeTab = 'terms';
}

$pageTitle = ($activeTab === 'privacy' ? '隐私政策' : '服务条款') . ' - ' . e($config['website_name'] ?? '');
$pageKey = 'terms';
$pageDescription = '阅读 ' . e($config['website_name'] ?? '') . ' 的服务条款与隐私政策。';

// 定义：服务条款与隐私政策数据
$useCustomTerms = !empty($config['terms_content']);
$useCustomPrivacy = !empty($config['privacy_content']);
$contactEmail = isset($config['contact_email']) ? trim((string)$config['contact_email']) : '';
$siteName = $config['website_name'] ?? 'NovaCMS';

$termsList = [];
if ($useCustomTerms) {
    $termsList[] = [
        'title' => '服务条款',
        'extra_html' => $config['terms_content'],
    ];
} else {
    $termsList = [
        [
            'title' => '1. 接受条款',
            'content' => '通过访问和使用' . e($siteName) . '网站，您同意遵守这些服务条款。如果您不同意这些条款，请不要使用本网站。'
        ],
        [
            'title' => '2. 网站描述',
            'content' => e($siteName) . '是一个展示个人作品、博客文章和相关服务的平台。我们致力于提供高质量的内容和良好的用户体验。'
        ],
        [
            'title' => '3. 使用许可',
            'content' => '我们授予您有限的、非独占的、不可转让的许可来使用本网站，但您必须遵守以下条件：',
            'items' => [
                '不得将网站用于任何非法或未经授权的目的',
                '不得干扰或破坏网站的正常运行',
                '不得试图获取未经授权的访问权限',
                '不得复制或重复使用网站内容，除非获得明确许可'
            ]
        ],
        [
            'title' => '4. 内容所有权',
            'content' => '网站上的所有内容，包括但不限于文字、图片、代码、设计等，均受版权法和其他知识产权法保护。未经我们明确书面许可，您不得使用、复制或分发任何内容。'
        ],
        [
            'title' => '5. 用户责任',
            'content' => '作为用户，您同意：',
            'items' => [
                '提供准确和真实的信息',
                '不发布虚假、误导性或违法内容',
                '尊重他人的知识产权和隐私权',
                '不从事任何可能损害网站声誉的活动'
            ]
        ],
        [
            'title' => '6. 免责声明',
            'content' => '本网站按"现状"提供，我们不对以下内容做任何保证：',
            'items' => [
                '网站服务的连续性或无中断',
                '网站内容的准确性或完整性',
                '网站免受病毒或其他恶意组件的侵害',
                '因使用网站而导致的任何损失或损害'
            ]
        ],
        [
            'title' => '7. 服务限制',
            'content' => '我们保留以下权利：',
            'items' => [
                '随时修改或终止网站服务',
                '拒绝向任何人提供服务',
                '删除违反服务条款的内容',
                '暂停或终止违规用户的访问权限'
            ]
        ],
        [
            'title' => '8. 第三方链接',
            'content' => '本网站可能包含指向第三方网站的链接。我们不对这些外部网站的内容、隐私政策或做法负责。访问第三方网站的风险由您自行承担。'
        ],
        [
            'title' => '9. 争议解决',
            'content' => '这些服务条款受中国法律管辖。如发生争议，双方应首先通过友好协商解决。协商不成的，任何一方均可向网站经营者所在地人民法院提起诉讼。'
        ],
        [
            'title' => '10. 条款修改',
            'content' => '我们保留随时修改这些服务条款的权利。修改后的条款将在网站上发布，并立即生效。继续使用本网站即表示您接受修改后的条款。'
        ],
        [
            'title' => '11. 联系我们',
            'content' => '如果您对这些服务条款有任何疑问，请通过以下方式联系我们：',
            'extra_html' => $contactEmail !== '' ? '<p>邮箱：<a href="mailto:' . e($contactEmail) . '">' . e($contactEmail) . '</a></p>' : ''
        ]
    ];
}

$privacyList = [];
if ($useCustomPrivacy) {
    $privacyList[] = [
        'title' => '隐私政策',
        'extra_html' => $config['privacy_content'],
    ];
} else {
    $privacyList = [
        [
            'title' => '1. 信息收集',
            'content' => '我们可能收集以下类型的信息：',
            'items' => [
                '您通过联系表单提供的姓名、电子邮件地址等信息',
                '访问网站时的技术信息（IP地址、浏览器类型、访问时间等）',
                '通过Cookie收集的使用偏好信息'
            ]
        ],
        [
            'title' => '2. 信息使用',
            'content' => '收集的信息可能用于：',
            'items' => [
                '回复您的咨询和请求',
                '改善网站内容和用户体验',
                '发送重要的通知和更新',
                '网站分析和安全监控'
            ]
        ],
        [
            'title' => '3. 信息共享',
            'content' => '我们不会向第三方出售、交易或转让您的个人信息，除非：',
            'items' => [
                '获得您的明确同意',
                '法律要求或法律程序需要',
                '保护网站、用户或公众的权利、财产或安全'
            ]
        ],
        [
            'title' => '4. 数据安全',
            'content' => '我们采取适当的安全措施来保护您的个人信息，包括：',
            'items' => [
                '使用安全的服务器和加密技术',
                '限制对个人信息的访问权限',
                '定期更新安全协议'
            ]
        ],
        [
            'title' => '5. Cookie使用',
            'content' => '本网站可能使用Cookie来：',
            'items' => [
                '记住您的偏好设置',
                '分析网站流量和使用情况',
                '提供个性化的内容'
            ],
            'extra_text' => '您可以通过浏览器设置控制Cookie的使用。'
        ],
        [
            'title' => '6. 您的权利',
            'content' => '您有权：',
            'items' => [
                '访问您的个人信息',
                '更正不准确的信息',
                '删除您的个人信息',
                '反对处理您的信息'
            ]
        ],
        [
            'title' => '7. 政策更新',
            'content' => '我们可能会不时更新此隐私政策。重大变更时，我们会通过网站通知您。建议您定期查看此页面以获取最新信息。'
        ],
        [
            'title' => '8. 联系我们',
            'content' => '如果您对此隐私政策有任何疑问或关注，请通过以下方式联系我们：',
            'extra_html' => $contactEmail !== '' ? '<p>邮箱：<a href="mailto:' . e($contactEmail) . '">' . e($contactEmail) . '</a></p>' : ''
        ]
    ];
}

$communityCssVersion = (string)(@filemtime($themePath . '/assets/css/community.css') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/community.css?v=' . e($communityCssVersion) . '" rel="stylesheet">';

$extraFooter = '<script>
(function(){
    const tabButtons = document.querySelectorAll(\'[data-terms-tab]\');
    const tabPanes = document.querySelectorAll(\'[data-terms-pane]\');
    function activate(target, push) {
        tabButtons.forEach(b => {
            const isActive = b.dataset.termsTab === target;
            b.classList.toggle(\'active\', isActive);
            if (isActive) b.setAttribute(\'aria-selected\', \'true\');
            else b.setAttribute(\'aria-selected\', \'false\');
        });
        tabPanes.forEach(p => {
            const show = p.dataset.termsPane === target;
            p.classList.toggle(\'show\', show);
            if (show) { void p.offsetWidth; }
        });
        if (push) {
            const url = new URL(window.location);
            url.searchParams.set(\'tab\', target);
            if (target === \'privacy\') {
                history.replaceState({}, \'\', \'/privacy\' + (url.searchParams.toString() ? \'?\' + url.searchParams.toString().replace(/^tab=privacy&?|(&?)tab=privacy$/, \'\').replace(/&$/, \'\') : \'\'));
            } else {
                history.replaceState({}, \'\', \'/terms\' + (url.searchParams.toString() ? \'?\' + url.searchParams.toString().replace(/^tab=terms&?|(&?)tab=terms$/, \'\').replace(/&$/, \'\') : \'\'));
            }
        }
    }
    tabButtons.forEach(btn => {
        btn.addEventListener(\'click\', function() { activate(this.dataset.termsTab, true); });
    });
})();
</script>';

include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<main id="main-content" class="site-main community-page terms-page" data-community-page="terms">
    <section class="community-hero links-hero">
        <div class="site-container community-hero-grid">
            <div data-reveal>
                <span class="site-eyebrow"><i class="bi bi-file-earmark-text"></i> Policy</span>
                <h1>协议与政策</h1>
                <p>最后更新：<?= date('Y年m月d日') ?></p>
            </div>
            <div class="community-hero-mark" aria-hidden="true" data-reveal>
                <i class="bi bi-shield-lock"></i><span></span><i class="bi bi-stars"></i>
            </div>
        </div>
    </section>

    <section class="community-section" aria-label="协议内容">
        <div class="site-container">
            <nav class="community-heading" aria-label="协议 Tab 切换" style="margin-bottom:24px;">
                <div class="nav-pills" style="display:inline-flex;gap:6px;background:var(--community-surface);border:1px solid var(--community-border);border-radius:14px;padding:5px;">
                    <button class="nav-link site-button <?= $activeTab === 'terms' ? 'active site-button-primary' : '' ?>" data-terms-tab="terms" type="button" role="tab" aria-selected="<?= $activeTab === 'terms' ? 'true' : 'false' ?>" style="padding:9px 22px;border-radius:10px;font-size:.9rem;font-weight:600;border:none;">
                        <i class="bi bi-file-text me-1"></i>服务条款
                    </button>
                    <button class="nav-link site-button <?= $activeTab === 'privacy' ? 'active site-button-primary' : '' ?>" data-terms-tab="privacy" type="button" role="tab" aria-selected="<?= $activeTab === 'privacy' ? 'true' : 'false' ?>" style="padding:9px 22px;border-radius:10px;font-size:.9rem;font-weight:600;border:none;">
                        <i class="bi bi-shield-lock me-1"></i>隐私政策
                    </button>
                </div>
            </header>

            <div class="main-card" style="background:var(--community-surface);border:1px solid var(--community-border);border-radius:26px;padding:clamp(1.4rem, 4vw, 2.2rem);box-shadow:0 18px 54px rgba(35, 40, 70, .07);">

                <div class="tab-pane <?= $activeTab === 'terms' ? 'show' : '' ?>" data-terms-pane="terms" role="tabpanel" style="display:none;">
                    <?php foreach ($termsList as $section): ?>
                    <div class="content-section" style="margin-bottom:24px;">
                        <h3 style="font-size:1.05rem;font-weight:700;color:var(--community-ink);margin-bottom:10px;padding-left:14px;border-left:3px solid var(--community-primary);"><?= $section['title'] ?></h3>
                        <?php if (!empty($section['content'])): ?>
                        <p style="font-size:.92rem;color:var(--community-muted);line-height:1.8;margin-bottom:8px;"><?= $section['content'] ?></p>
                        <?php endif; ?>
                        <?php if (!empty($section['items'])): ?>
                        <ul style="margin:6px 0 10px 18px;padding:0;">
                            <?php foreach ($section['items'] as $item): ?>
                            <li style="font-size:.92rem;color:var(--community-muted);line-height:1.8;padding:2px 0;list-style:disc;"><?= $item ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                        <?php if (!empty($section['extra_text'])): ?>
                        <p style="font-size:.92rem;color:var(--community-muted);line-height:1.8;"><?= $section['extra_text'] ?></p>
                        <?php endif; ?>
                        <?php if (!empty($section['extra_html'])): ?>
                        <div style="font-size:.92rem;color:var(--community-muted);line-height:1.8;"><?= $section['extra_html'] ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="tab-pane <?= $activeTab === 'privacy' ? 'show' : '' ?>" data-terms-pane="privacy" role="tabpanel" style="display:none;">
                    <?php foreach ($privacyList as $section): ?>
                    <div class="content-section" style="margin-bottom:24px;">
                        <h3 style="font-size:1.05rem;font-weight:700;color:var(--community-ink);margin-bottom:10px;padding-left:14px;border-left:3px solid var(--community-primary);"><?= $section['title'] ?></h3>
                        <?php if (!empty($section['content'])): ?>
                        <p style="font-size:.92rem;color:var(--community-muted);line-height:1.8;margin-bottom:8px;"><?= $section['content'] ?></p>
                        <?php endif; ?>
                        <?php if (!empty($section['items'])): ?>
                        <ul style="margin:6px 0 10px 18px;padding:0;">
                            <?php foreach ($section['items'] as $item): ?>
                            <li style="font-size:.92rem;color:var(--community-muted);line-height:1.8;padding:2px 0;list-style:disc;"><?= $item ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                        <?php if (!empty($section['extra_text'])): ?>
                        <p style="font-size:.92rem;color:var(--community-muted);line-height:1.8;"><?= $section['extra_text'] ?></p>
                        <?php endif; ?>
                        <?php if (!empty($section['extra_html'])): ?>
                        <div style="font-size:.92rem;color:var(--community-muted);line-height:1.8;"><?= $section['extra_html'] ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>
    </section>
</main>
<?php include $themePath . '/partials/footer.php'; ?>
