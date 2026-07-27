<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
recordVisit($_SERVER['REQUEST_URI']);

// 处理退出登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    if (isset($_SESSION['user_id'])) {
        logoutCurrentDevice($_SESSION['user_id']);
    }
    session_destroy();
    setcookie('device_token', '', time() - 3600, '/');
    setcookie('nova_token', '', time() - 3600, '/');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$pageTitle = '协议与政策 - ' . e($config['website_name']);
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'privacy' ? 'privacy' : 'terms';

// 数据定义：服务条款
$useCustomTerms = !empty($config['terms_content']);
$useCustomPrivacy = !empty($config['privacy_content']);

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
            'content' => '通过访问和使用' . e($config['website_name']) . '网站，您同意遵守这些服务条款。如果您不同意这些条款，请不要使用本网站。'
        ],
        [
            'title' => '2. 网站描述',
            'content' => e($config['website_name']) . '是一个展示个人作品、博客文章和相关服务的平台。我们致力于提供高质量的内容和良好的用户体验。'
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
        'extra_html' => '<p>邮箱：<a href="mailto:' . e($config['contact_email']) . '">' . e($config['contact_email']) . '</a></p>'
    ]
    ];
    }  // end if-else
    // $termsList is now set either from DB or defaults

// 数据定义：隐私政策
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
        'extra_html' => '<p>邮箱：<a href="mailto:' . e($config['contact_email']) . '">' . e($config['contact_email']) . '</a></p>'
    ]
    ];
    }  // end else for privacyList
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?></title>
<meta name="description" content="阅读 <?= e($config['website_name']) ?> 的服务条款和隐私政策。">
<?php if (!empty($config['favicon'])): 
$faviconUrl = $config['favicon']; 
if (!preg_match('/^https?:\/\//', $faviconUrl) && strpos($faviconUrl,'/')!==0) $faviconUrl = '/' . $faviconUrl;
?>
<link rel="icon" href="<?= e($faviconUrl) ?>">
<link rel="shortcut icon" href="<?= e($faviconUrl) ?>">
<link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
<?php endif; ?>

<link href="/assets/css/bootstrap.min.css" rel="stylesheet">
<link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
<link href="/assets/css/style.css" rel="stylesheet">

<style>
:root {
    --primary: #4f46e5;
    --primary-light: #6366f1;
    --primary-bg: #eef2ff;
    --text: #1e293b;
    --text-secondary: #64748b;
    --text-muted: #94a3b8;
    --border: #e2e8f0;
    --bg: #f8fafc;
    --card-bg: #ffffff;
    --radius: 14px;
    --radius-sm: 10px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.06);
    --shadow: 0 4px 12px rgba(0,0,0,0.04), 0 1px 3px rgba(0,0,0,0.05);
    --shadow-lg: 0 12px 32px rgba(0,0,0,0.06), 0 2px 8px rgba(0,0,0,0.04);
    --transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
    -webkit-font-smoothing: antialiased;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* Navbar */
.navbar.fixed-top {
    background: rgba(255,255,255,0.85) !important;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 8px 0 !important;
    box-shadow: var(--shadow-sm);
}
.navbar-brand {
    color: var(--text) !important;
    font-weight: 700;
    font-size: 1.05rem !important;
    letter-spacing: -0.3px;
}
.navbar .nav-link {
    color: var(--text-secondary) !important;
    font-weight: 500;
    font-size: 0.9rem;
    transition: color var(--transition);
}
.navbar .nav-link:hover { color: var(--primary) !important; }
.navbar .btn {
    font-weight: 600;
    font-size: 0.85rem;
    padding: 0.4rem 0.9rem;
    border-radius: 8px;
    transition: all var(--transition);
}
.navbar .btn-outline-primary {
    color: var(--primary);
    border-color: var(--primary);
}
.navbar .btn-outline-primary:hover {
    background: var(--primary);
    color: #fff;
}

/* Page Container */
.page-container {
    flex: 1;
    padding-top: 72px;
}
.content-wrapper {
    max-width: 1200px;
    margin: 0 auto;
    padding: 24px 20px 40px;
}

/* Section Header */
.section-header {
    text-align: center;
    padding: 20px 0 32px;
}
.section-icon {
    width: 56px;
    height: 56px;
    background: var(--primary-bg);
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
}
.section-icon i {
    font-size: 1.6rem;
    color: var(--primary);
}
.section-header h1 {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--text);
    margin-bottom: 8px;
    letter-spacing: -0.5px;
}
.section-header p {
    color: var(--text-secondary);
    font-size: 0.9rem;
    margin: 0 auto;
}

/* Tab Pills */
.nav-pills-wrap {
    display: flex;
    justify-content: center;
    margin-bottom: 28px;
}
.nav-pills {
    display: inline-flex;
    gap: 6px;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 5px;
}
.nav-pills .nav-link {
    color: var(--text-secondary);
    border-radius: 10px;
    padding: 9px 22px;
    font-size: 0.9rem;
    font-weight: 600;
    transition: all var(--transition);
    border: none;
    background: transparent;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
}
.nav-pills .nav-link:hover {
    color: var(--primary);
    background: var(--primary-bg);
}
.nav-pills .nav-link.active {
    background: var(--primary);
    color: #fff;
    box-shadow: 0 2px 8px rgba(79,70,229,0.25);
}

/* Card */
.main-card {
    background: var(--card-bg);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 32px;
    box-shadow: var(--shadow);
}

/* Content Section */
.content-section {
    margin-bottom: 24px;
}
.content-section:last-child { margin-bottom: 4px; }
.content-section h3 {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 10px;
    padding-left: 14px;
    border-left: 3px solid var(--primary);
}
.content-section p {
    font-size: 0.9rem;
    color: var(--text-secondary);
    line-height: 1.75;
    margin-bottom: 8px;
}
.content-section ul {
    margin: 6px 0 10px 18px;
    padding: 0;
}
.content-section ul li {
    font-size: 0.9rem;
    color: var(--text-secondary);
    line-height: 1.75;
    padding: 2px 0;
}
.content-section ul li::marker {
    color: var(--primary);
}
.content-section a {
    color: var(--primary);
    text-decoration: none;
    font-weight: 500;
}
.content-section a:hover {
    text-decoration: underline;
}

/* Fade animation */
.tab-pane {
    display: none;
}
.tab-pane.show {
    display: block;
    animation: fadeIn 0.35s ease;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Footer */
footer {
    background: var(--card-bg);
    border-top: 1px solid var(--border);
    padding: 12px 0;
    font-size: 0.8rem;
    color: var(--text-muted);
}
footer a { color: var(--text-muted); text-decoration: none; }
footer a:hover { color: var(--primary); }

/* Responsive */
@media (max-width: 768px) {
    .content-wrapper { padding: 20px 14px 32px; }
    .main-card { padding: 20px; }
    .section-header { padding: 12px 0 24px; }
    .section-header h1 { font-size: 1.4rem; }
    .nav-pills .nav-link { padding: 8px 16px; font-size: 0.85rem; }
}
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light fixed-top">
    <div class="container">
        <a class="navbar-brand" href="/"><?= e($config['website_name']) ?></a>
        <div class="ms-auto">
            <a class="btn btn-outline-primary btn-sm" href="/">
                <i class="bi bi-house me-1"></i>返回首页
            </a>
        </div>
    </div>
</nav>

<div class="page-container">
    <div class="content-wrapper">

        <!-- Section Header -->
        <div class="section-header">
            <div class="section-icon">
                <i class="bi bi-file-earmark-text"></i>
            </div>
            <h1>协议与政策</h1>
            <p>最后更新：<?= date('Y年m月d日') ?></p>
        </div>

        <!-- Tab Pills -->
        <div class="nav-pills-wrap">
            <div class="nav-pills" id="licenseTabs">
                <button class="nav-link <?= $activeTab === 'terms' ? 'active' : '' ?>" data-tab="terms">
                    <i class="bi bi-file-text"></i>服务条款
                </button>
                <button class="nav-link <?= $activeTab === 'privacy' ? 'active' : '' ?>" data-tab="privacy">
                    <i class="bi bi-shield-lock"></i>隐私政策
                </button>
            </div>
        </div>

        <!-- Main Card -->
        <div class="main-card">

            <!-- 服务条款 -->
            <div class="tab-pane <?= $activeTab === 'terms' ? 'show' : '' ?>" id="tab-terms">
                <?php foreach ($termsList as $section): ?>
                <div class="content-section">
                    <h3><?= $section['title'] ?></h3>
                    <?php if (!empty($section['content'])): ?>
                    <p><?= $section['content'] ?></p>
                    <?php endif; ?>
                    <?php if (isset($section['items'])): ?>
                    <ul>
                        <?php foreach ($section['items'] as $item): ?>
                        <li><?= $item ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php if (isset($section['extra_html'])) echo nl2br($section['extra_html']); ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- 隐私政策 -->
            <div class="tab-pane <?= $activeTab === 'privacy' ? 'show' : '' ?>" id="tab-privacy">
                <?php foreach ($privacyList as $section): ?>
                <div class="content-section">
                    <h3><?= $section['title'] ?></h3>
                    <?php if (!empty($section['content'])): ?>
                    <p><?= $section['content'] ?></p>
                    <?php endif; ?>
                    <?php if (isset($section['items'])): ?>
                    <ul>
                        <?php foreach ($section['items'] as $item): ?>
                        <li><?= $item ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php if (isset($section['extra_text'])) echo "<p>" . $section['extra_text'] . "</p>"; ?>
                    <?php if (isset($section['extra_html'])) echo nl2br($section['extra_html']); ?>
                </div>
                <?php endforeach; ?>
            </div>

        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../vendor/footer.php'; ?>

<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script>
// Tab switching
const tabButtons = document.querySelectorAll('.nav-pills .nav-link');
const tabPanes = document.querySelectorAll('.tab-pane');

tabButtons.forEach(btn => {
    btn.addEventListener('click', function() {
        const targetTab = this.dataset.tab;

        // Update active button
        tabButtons.forEach(b => b.classList.remove('active'));
        this.classList.add('active');

        // Show target pane
        tabPanes.forEach(p => {
            p.classList.remove('show');
            if (p.id === 'tab-' + targetTab) {
                // Trigger reflow for animation restart
                void p.offsetWidth;
                p.classList.add('show');
            }
        });

        // Update URL without reload
        const url = new URL(window.location);
        url.searchParams.set('tab', targetTab);
        window.history.replaceState({}, '', url);
    });
});
</script>
</body>
</html>
