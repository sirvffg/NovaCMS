<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';
recordVisit($_SERVER['REQUEST_URI']);

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 处理友链申请提交（异步API）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_application') {
    header('Content-Type: application/json');

    // 蜜罐检查
    if (checkHoneypot()) {
        echo json_encode(['success' => true, 'message' => '申请提交成功，请等待审核']);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $logo = trim($_POST['logo'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $rss_url = trim($_POST['rss_url'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_name = trim($_POST['contact_name'] ?? '');

    // 验证
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => '请输入网站名称']);
        exit;
    } elseif (empty($url)) {
        echo json_encode(['success' => false, 'error' => '请输入网站链接']);
        exit;
    } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'error' => '请输入有效的网站链接']);
        exit;
    } elseif (empty($contact_email)) {
        echo json_encode(['success' => false, 'error' => '请输入联系邮箱']);
        exit;
    } elseif (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => '请输入有效的邮箱地址']);
        exit;
    } elseif (empty($contact_name)) {
        echo json_encode(['success' => false, 'error' => '请输入联系人姓名']);
        exit;
    } else {
        // 检查是否已经存在相同的链接
        $checkLink = $db->prepare("SELECT id FROM friend_links WHERE url=?");
        $checkLink->execute([$url]);
        if ($checkLink->fetch()) {
            echo json_encode(['success' => false, 'error' => '该链接已经存在，请联系管理员']);
            exit;
        } else {
            // 检查是否已经提交过申请
            $checkApp = $db->prepare("SELECT id FROM friend_link_applications WHERE url=? AND status=0");
            $checkApp->execute([$url]);
            if ($checkApp->fetch()) {
                echo json_encode(['success' => false, 'error' => '您已经提交过该链接的申请，请耐心等待审核']);
                exit;
            } else {
                // 插入申请记录
                $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
                $ip = $_SERVER['REMOTE_ADDR'];
                $stmt = $db->prepare("INSERT INTO friend_link_applications (name, url, logo, description, rss_url, category_id, contact_email, contact_name, status, user_id, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
                $stmt->execute([$name, $url, $logo, $description, $rss_url, $category_id, $contact_email, $contact_name, $userId, $ip]);
                $applicationId = $db->lastInsertId();

                // 获取分类名称
                $categoryName = '';
                if ($category_id) {
                    $catStmt = $db->prepare("SELECT name FROM friend_link_categories WHERE id=?");
                    $catStmt->execute([$category_id]);
                    $catResult = $catStmt->fetch();
                    $categoryName = $catResult ? $catResult['name'] : '';
                }

                // 发送邮件通知站长
                if (!empty($config['contact_email'])) {
                    $emailResult = sendNewApplicationNotice($config['contact_email'], $config['website_name'], [
                        'name' => $name,
                        'url' => $url,
                        'logo' => $logo,
                        'description' => $description,
                        'rss_url' => $rss_url,
                        'category_id' => $category_id,
                        'category_name' => $categoryName,
                        'contact_email' => $contact_email,
                        'contact_name' => $contact_name
                    ]);
                }

                echo json_encode(['success' => true, 'message' => '申请提交成功，我们会尽快审核您的申请！']);
                exit;
            }
        }
    }
}

// 获取友情链接（关联分类）
$friendLinks = $db->query("
    SELECT fl.*, flc.name as category_name
    FROM friend_links fl
    LEFT JOIN friend_link_categories flc ON fl.category_id = flc.id
    WHERE fl.is_active=1
    ORDER BY fl.sort_order ASC, fl.id DESC
")->fetchAll();


// 获取所有分类（用于筛选和申请）
$allCategories = $db->query("SELECT * FROM friend_link_categories ORDER BY sort_order ASC, id ASC")->fetchAll();

// 将单向友链和失联博客分类排在最后，失联博客排在单向友链后面
$specialCategories = ['单向友链', '失联博客'];
$sortedCategories = [];
$lastCategories = [];
foreach ($allCategories as $cat) {
    if (in_array($cat['name'], $specialCategories)) {
        $lastCategories[] = $cat;
    } else {
        $sortedCategories[] = $cat;
    }
}
// 对特殊分类排序：单向友链在前，失联博客在后
$oneWayLinks = array_filter($lastCategories, fn($c) => $c['name'] === '单向友链');
$lostBlogs = array_filter($lastCategories, fn($c) => $c['name'] === '失联博客');
$lastCategories = array_merge(array_values($oneWayLinks), array_values($lostBlogs));
$allCategories = array_merge($sortedCategories, $lastCategories);

// 按分类分组链接
$groupedLinks = [];
foreach ($friendLinks as $link) {
    $catId = $link['category_id'] ?: 0;
    if (!isset($groupedLinks[$catId])) {
        $groupedLinks[$catId] = [
            'name' => $link['category_name'] ?: '未分类',
            'description' => '',
            'links' => []
        ];
    }
    $groupedLinks[$catId]['links'][] = $link;
}

// 按分类排序重新排列链接分组，未分类在正常分类之后、特殊分类之前
$sortedGroupedLinks = [];
// 先添加正常分类
foreach ($sortedCategories as $cat) {
    if (isset($groupedLinks[$cat['id']])) {
        $sortedGroupedLinks[$cat['id']] = $groupedLinks[$cat['id']];
        $sortedGroupedLinks[$cat['id']]['name'] = $cat['name'];
        $sortedGroupedLinks[$cat['id']]['description'] = $cat['description'];
    }
}
// 然后添加未分类的链接
if (isset($groupedLinks[0])) {
    $sortedGroupedLinks[0] = $groupedLinks[0];
}
// 最后添加特殊分类（单向友链、失联博客）
foreach ($lastCategories as $cat) {
    if (isset($groupedLinks[$cat['id']])) {
        $sortedGroupedLinks[$cat['id']] = $groupedLinks[$cat['id']];
        $sortedGroupedLinks[$cat['id']]['name'] = $cat['name'];
        $sortedGroupedLinks[$cat['id']]['description'] = $cat['description'];
    }
}
$groupedLinks = $sortedGroupedLinks;

// 获取跳转白名单
$redirectWhitelist = !empty($config['redirect_whitelist']) ? trim($config['redirect_whitelist']) : '';
$whitelistDomains = [];
if (!empty($redirectWhitelist)) {
    foreach (explode("\n", $redirectWhitelist) as $item) {
        $item = strtolower(trim($item));
        if (empty($item)) continue;
        if (strpos($item, 'http://') === 0 || strpos($item, 'https://') === 0) {
            $parsed = parse_url($item);
            if (isset($parsed['host'])) {
                $whitelistDomains[] = strtolower($parsed['host']);
            }
        } else {
            $whitelistDomains[] = $item;
        }
    }
}

// 判断域名是否在白名单中
function isDomainWhitelisted($url, $whitelistDomains) {
    if (empty($whitelistDomains)) return false;
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['host'])) return false;
    $host = strtolower($parsed['host']);
    foreach ($whitelistDomains as $domain) {
        if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
            return true;
        }
    }
    return false;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>友情链接 - <?= e($config['website_name']) ?></title>
    <meta name="description" content="<?= e($config['website_name']) ?> 的友情链接页面">
    <meta property="og:title" content="友情链接 - <?= e($config['website_name']) ?>">
    <meta property="og:description" content="<?= e($config['website_name']) ?> 的友情链接页面">
    <meta property="og:url" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <?php if (!empty($config['logo'])): ?>
    <meta property="og:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['logo']) ?>">
    <?php endif; ?>
    <?php if (!empty($config['favicon'])): ?>
    <meta property="og:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['favicon']) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="友情链接 - <?= e($config['website_name']) ?>">
    <meta name="twitter:description" content="<?= e($config['website_name']) ?> 的友情链接页面">
    <?php if (!empty($config['favicon'])): ?>
    <meta name="twitter:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['favicon']) ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') ?>">
    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "<?= e($config['website_name']) ?> - 友情链接",
      "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ?>",
      "description": "<?= e($config['website_name']) ?> 的友情链接页面，展示了与我们交换链接的优秀网站。"
    }
    </script>
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
        .navbar-nav .nav-link {
            color: var(--text-secondary) !important;
            font-weight: 500;
            font-size: 0.9rem;
            transition: color var(--transition);
        }
        .navbar-nav .nav-link:hover { color: var(--primary) !important; }
        .navbar .btn {
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.4rem 0.9rem;
            border-radius: 8px;
            transition: all var(--transition);
        }
        .navbar .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }
        .navbar .btn-primary:hover {
            background: var(--primary-light);
            border-color: var(--primary-light);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79,70,229,0.3);
        }
        .navbar .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
        }
        .navbar .btn-outline-primary:hover {
            background: var(--primary);
            color: #fff;
        }

        @media (min-width: 992px) {
            .navbar-nav .dropdown:hover .dropdown-menu {
                display: block;
                margin-top: 0;
            }
        }

        /* Main Container */
        .page-container {
            flex: 1;
            padding-top: 72px;
        }

        .content-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px 16px 40px;
        }

        /* Category Section */
        .category-section {
            margin-bottom: 40px;
        }
        .category-section:last-child {
            margin-bottom: 0;
        }
        .category-heading {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary-bg);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .category-heading i {
            color: var(--primary);
            font-size: 1rem;
        }
        .category-desc {
            font-size: 0.85rem;
            font-weight: 400;
            color: var(--text-muted);
            margin-left: auto;
        }

        /* Link Cards Grid */
        .links-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 14px;
            max-width: 100%;
        }

        .link-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 20px;
            border: 1px solid var(--border);
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            transition: all var(--transition);
            position: relative;
            min-width: 0;
            overflow: hidden;
        }
        .link-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            border-color: #c7d2fe;
        }

        .link-card-top {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 14px;
        }

        .link-logo {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            flex-shrink: 0;
            background: var(--primary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .link-logo.has-img {
            background: transparent;
        }
        .link-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 12px;
            display: none;
        }
        .link-logo img.loaded {
            display: block;
        }
        .link-logo .logo-placeholder {
            font-size: 1.2rem;
            color: var(--primary);
            font-weight: 700;
        }
        .link-logo.failed {
            background: var(--primary-bg);
        }
        .link-logo.failed img { display: none; }

        .link-info {
            flex: 1;
            min-width: 0;
        }
        .link-info h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
            margin: 0 0 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .link-info .link-domain {
            font-size: 0.78rem;
            color: var(--text-muted);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .link-description {
            font-size: 0.85rem;
            color: var(--text-secondary);
            line-height: 1.5;
            margin: 0 0 16px;
            flex: 1;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-all;
        }

        .link-card-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }
        .link-tag {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 12px;
            background: var(--primary-bg);
            color: var(--primary);
        }
        .link-visit {
            font-size: 0.82rem;
            color: var(--primary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .link-card:hover .link-visit i {
            transform: translateX(3px);
            transition: transform var(--transition);
        }

        /* Apply Card */
        .apply-card {
            border: 2px dashed #c7d2fe;
            background: linear-gradient(135deg, var(--primary-bg) 0%, #f5f3ff 100%);
            cursor: pointer;
        }
        .apply-card:hover {
            border-color: var(--primary);
            background: linear-gradient(135deg, #eef2ff 0%, #ede9fe 100%);
        }
        .apply-card .apply-tag {
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            color: #fff;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 64px 20px;
        }
        .empty-state .empty-icon {
            font-size: 3.5rem;
            color: var(--text-muted);
            margin-bottom: 16px;
            display: block;
        }
        .empty-state h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }
        .empty-state p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 24px;
        }
        .empty-state .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 600;
        }

        /* Apply Form Section */
        .apply-section {
            display: none;
            max-width: 700px;
            margin: 0 auto;
        }
        .apply-section.show {
            display: block;
        }

        .form-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 28px;
            margin-bottom: 20px;
        }
        .form-card-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-card-title i { color: var(--primary); font-size: 1.2rem; }

        /* Site Info Card */
        .site-info-block {
            background: #fafbff;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            padding: 20px;
            margin-bottom: 20px;
        }
        .info-row {
            display: flex;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            align-items: flex-start;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label {
            font-weight: 600;
            color: var(--text);
            font-size: 0.85rem;
            min-width: 56px;
            flex-shrink: 0;
        }
        .info-value {
            color: var(--text-secondary);
            font-size: 0.85rem;
            word-break: break-all;
        }
        .info-value a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        .info-value a:hover { text-decoration: underline; }

        /* Notice */
        .notice-block {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: var(--radius-sm);
            padding: 16px 20px;
            margin-bottom: 20px;
            cursor: pointer;
        }
        .notice-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .notice-header-left {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            color: #92400e;
        }
        .notice-header-left i { font-size: 1rem; }
        .notice-toggle {
            font-size: 0.82rem;
            color: var(--primary);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .notice-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.35s ease, margin-top 0.35s ease;
            margin-top: 0;
            font-size: 0.85rem;
            color: var(--text-secondary);
            line-height: 1.7;
        }
        .notice-block.expanded .notice-content {
            max-height: 800px;
            margin-top: 14px;
        }
        .notice-block.expanded .notice-toggle i {
            transform: rotate(180deg);
            transition: transform 0.25s ease;
        }
        .notice-content .notice-intro {
            background: #fff;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 12px;
            color: var(--text-secondary);
            border-left: 3px solid var(--primary);
        }
        .notice-content .notice-item {
            padding: 6px 0;
            padding-left: 18px;
            position: relative;
        }
        .notice-content .notice-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 13px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--primary);
        }

        /* Form Controls */
        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text);
            margin-bottom: 6px;
        }
        .form-control, .form-select {
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.9rem;
            background: var(--card-bg);
            color: var(--text);
            transition: all var(--transition);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
            outline: none;
        }
        .form-control::placeholder { color: var(--text-muted); }
        input[readonly] {
            background: #f1f5f9 !important;
            cursor: not-allowed;
            color: var(--text-secondary);
        }
        textarea.form-control { resize: vertical; }
        .btn-submit {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 12px 28px;
            font-size: 0.95rem;
            font-weight: 700;
            transition: all var(--transition);
            width: 100%;
        }
        .btn-submit:hover {
            background: var(--primary-light);
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(79,70,229,0.3);
        }
        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 20px 0;
        }
        .section-subtitle {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 14px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 0.88rem;
            text-decoration: none;
            cursor: pointer;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 8px 16px;
            background: var(--card-bg);
            transition: all var(--transition);
            margin-bottom: 20px;
        }
        .btn-back:hover {
            color: var(--primary);
            border-color: var(--primary);
        }

        /* Toast */
        .toast-container {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 1060;
        }
        .custom-toast {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 14px 20px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.88rem;
            font-weight: 500;
            animation: slideIn 0.3s ease;
            max-width: 360px;
        }
        .custom-toast.success { border-left: 3px solid #10b981; }
        .custom-toast.error { border-left: 3px solid #ef4444; }
        .custom-toast.success i { color: #10b981; }
        .custom-toast.error i { color: #ef4444; }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .content-wrapper { padding: 20px 14px 32px; }
            .links-grid {
                grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
                gap: 12px;
            }
            .link-card { padding: 16px; }
            .form-card { padding: 20px; }
        }
        @media (max-width: 480px) {
            .links-grid { grid-template-columns: 1fr; }
            .info-row { flex-direction: column; gap: 4px; }
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
    </style>
</head>
<body>
    <h1 class="visually-hidden">友情链接 - <?= e($config['website_name']) ?></h1>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light fixed-top">
        <div class="container">
            <a class="navbar-brand" href="/">
                <span class="d-none d-lg-inline">友情链接 | <?= e($config['website_name']) ?></span>
                <span class="d-lg-none">友情链接</span>
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <button class="btn btn-primary btn-sm" id="applyToggleBtn">
                    <i class="bi bi-plus-circle me-1"></i>申请友链
                </button>
                <a class="btn btn-outline-primary btn-sm" id="backButton">返回</a>
            </div>
        </div>
    </nav>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Page Content -->
    <div class="page-container">
        <div class="content-wrapper">

            <!-- Links Area (grouped by category) -->
            <div class="links-view" id="linksView">
                <?php if (!empty($groupedLinks)): ?>
                    <?php foreach ($groupedLinks as $catId => $group): ?>
                    <div class="category-section">
                        <h2 class="category-heading">
                            <i class="bi bi-link-45deg"></i>
                            <?= e($group['name']) ?>
                            <?php if (!empty($group['description'])): ?><span class="category-desc"><?= e($group['description']) ?></span><?php endif; ?>
                        </h2>
                        <div class="links-grid">
                            <?php foreach ($group['links'] as $link):
                                $linkHref = isDomainWhitelisted($link['url'], $whitelistDomains)
                                    ? $link['url']
                                    : '/vendor/redirect.php?url=' . urlencode($link['url']) . '&title=' . urlencode($link['name']);
                                $linkRel = isDomainWhitelisted($link['url'], $whitelistDomains) ? 'noopener noreferrer' : '';
                            ?>
                            <a href="<?= e($linkHref) ?>"
                               target="_blank"
                               rel="<?= $linkRel ?>"
                               class="link-card"
                               data-name="<?= e(strtolower($link['name'])) ?>"
                               data-category="<?= $link['category_id'] ?: 'all' ?>">
                                <div class="link-card-top">
                                    <div class="link-logo <?= $link['logo'] ? 'has-img' : '' ?>" <?= $link['logo'] ? 'data-logo="' . e($link['logo']) . '"' : '' ?>>
                                        <?php if ($link['logo']): ?>
                                        <div class="logo-placeholder">
                                            <i class="bi bi-image"></i>
                                        </div>
                                        <?php else: ?>
                                        <div class="logo-placeholder"><?= mb_substr($link['name'], 0, 1) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="link-info">
                                        <h3><?= e($link['name']) ?></h3>
                                        <div class="link-domain"><?= e(parse_url($link['url'], PHP_URL_HOST) ?: $link['url']) ?></div>
                                    </div>
                                </div>
                                <?php if ($link['description']): ?>
                                <p class="link-description"><?= e($link['description']) ?></p>
                                <?php endif; ?>
                                <div class="link-card-bottom">
                                    <span class="link-tag"><?= e($link['category_name'] ?: '推荐') ?></span>
                                    <span class="link-visit">访问 <i class="bi bi-arrow-right"></i></span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state" id="emptyState">
                    <i class="bi bi-inbox empty-icon"></i>
                    <h3>暂无友情链接</h3>
                    <p>点击下方按钮申请成为第一个友链</p>
                    <button class="btn btn-primary" id="applyFromEmpty">
                        <i class="bi bi-plus-circle me-2"></i>申请友链
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Apply Form Section (hidden by default) -->
            <div class="apply-section" id="applySection">
                <a class="btn-back" id="backToList">
                    <i class="bi bi-arrow-left"></i>返回列表
                </a>

                <div class="form-card">
                    <div class="form-card-title">
                        <i class="bi bi-pencil-square"></i>申请友情链接
                    </div>

                    <!-- Site Info -->
                    <div class="site-info-block">
                        <div class="form-card-title" style="font-size:0.95rem;">
                            <i class="bi bi-info-circle"></i>本站信息（请添加到您的网站中）
                        </div>
                        <div class="info-row">
                            <span class="info-label">名称</span>
                            <span class="info-value"><?= e($config['website_name']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">地址</span>
                            <span class="info-value">
                                <a href="https://<?= e($_SERVER['HTTP_HOST']) ?>/" target="_blank">
                                    https://<?= e($_SERVER['HTTP_HOST']) ?>/
                                </a>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">描述</span>
                            <span class="info-value"><?= !empty($config['description']) ? e($config['description']) : '<span style="color:#ef4444;">请在后台 → 高级设置 → 友链网站描述 中填写</span>' ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">RSS</span>
                            <span class="info-value">
                                <a href="https://<?= e($_SERVER['HTTP_HOST']) ?>/license/rss.php" target="_blank">
                                    https://<?= e($_SERVER['HTTP_HOST']) ?>/license/rss.php
                                </a>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">图标</span>
                            <span class="info-value">
                                <?php if (!empty($config['logo'])): ?>
                                <a href="https://<?= e($_SERVER['HTTP_HOST']) ?><?= e($config['logo']) ?>" target="_blank">
                                    https://<?= e($_SERVER['HTTP_HOST']) ?><?= e($config['logo']) ?>
                                </a>
                                <?php else: ?>
                                暂无
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <!-- Notice -->
                    <div class="notice-block" id="friendLinksNotice">
                        <div class="notice-header">
                            <div class="notice-header-left">
                                <i class="bi bi-info-circle-fill"></i>
                                友情链接申请须知
                            </div>
                            <div class="notice-toggle">
                                <span>展开查看</span>
                                <i class="bi bi-chevron-down"></i>
                            </div>
                        </div>
                        <div class="notice-content">
                            <p class="notice-intro">
                                很高兴能和非常多的朋友们交流，如果你也想加入友链，可以在下方提交申请，我会在不忙的时候统一添加。（从历史经验上看，90%的友链在3个工作日内被添加）
                            </p>
                            <p style="font-weight:600;color:#92400e;margin-bottom:8px;">友链相关须知</p>
                            <p class="notice-item">你提交的信息有可能被修改</p>
                            <p class="notice-item">为了友链相关页面和组件的统一性和美观性，可能会对你的昵称进行缩短处理</p>
                            <p class="notice-item">为了图片加载速度和内容安全性考虑，头像实际展示图片均使用博客自己图床</p>
                            <p style="font-weight:600;color:#92400e;margin:12px 0 8px;">申请条件</p>
                            <p class="notice-item">请在您的网站中添加本站的友链链接</p>
                            <p class="notice-item">网站内容需健康、合法</p>
                            <p class="notice-item">如果您的网站信息需要修改，请联系管理员邮箱：<?= e($config['contact_email']) ?></p>
                        </div>
                    </div>

                    <!-- Form -->
                    <form method="POST" id="applyForm">
                        <input type="hidden" name="action" value="submit_application">
                        <?= honeypotField('website_hp') ?>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">网站名称 *</label>
                                <input type="text" name="name" class="form-control"
                                       value="<?= isset($_POST['name']) ? e($_POST['name']) : '' ?>"
                                       placeholder="请输入网站名称" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">网站链接 *</label>
                                <input type="url" name="url" class="form-control"
                                       value="<?= isset($_POST['url']) ? e($_POST['url']) : '' ?>"
                                       placeholder="https://example.com" required>
                            </div>
                        </div>

                        <div class="mb-3" style="margin-top:14px;">
                            <label class="form-label">网站 Logo</label>
                            <input type="text" name="logo" id="logoInput" class="form-control"
                                   value="<?= isset($_POST['logo']) ? e($_POST['logo']) : '' ?>"
                                   placeholder="https://example.com/logo.png">
                            <input type="file" id="logoFile" accept="image/*" style="display: none;">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">网站描述</label>
                            <textarea name="description" class="form-control" rows="2"
                                      placeholder="简单介绍一下您的网站..."><?= isset($_POST['description']) ? e($_POST['description']) : '' ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">RSS 地址 <span style="color:var(--text-muted);font-weight:400;font-size:0.8rem;">（选填）</span></label>
                            <input type="url" name="rss_url" class="form-control"
                                   value="<?= isset($_POST['rss_url']) ? e($_POST['rss_url']) : '' ?>"
                                   placeholder="https://example.com/feed.xml">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">分类</label>
                            <select name="category_id" class="form-select">
                                <option value="">不分类</option>
                                <?php foreach ($allCategories as $cat): ?>
                                <?php if ($cat['name'] !== '单向友链' && $cat['name'] !== '失联博客'): ?>
                                <option value="<?= $cat['id'] ?>" <?= (isset($_POST['category_id']) && $_POST['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?>
                                </option>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <hr class="divider">

                        <div class="section-subtitle">联系信息</div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">联系邮箱 *</label>
                                <input type="email" name="contact_email" class="form-control"
                                       value="<?= isset($_POST['contact_email']) ? e($_POST['contact_email']) : '' ?>"
                                       placeholder="example@email.com" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">联系人 *</label>
                                <input type="text" name="contact_name" class="form-control"
                                       value="<?= isset($_POST['contact_name']) ? e($_POST['contact_name']) : '' ?>"
                                       placeholder="请输入您的姓名" required>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn-submit">
                                <i class="bi bi-send me-2"></i>提交申请
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <?php require_once __DIR__ . '/footer.php'; ?>

    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toast helper
        function showToast(message, type) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'custom-toast ' + type;
            toast.innerHTML = '<i class="bi bi-' + (type === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill') + '"></i>' + message;
            container.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s';
                setTimeout(() => toast.remove(), 300);
            }, 3500);
        }

        // DOM refs
        const applyFromEmpty = document.getElementById('applyFromEmpty');
        const applyToggleBtn = document.getElementById('applyToggleBtn');
        const linksView = document.getElementById('linksView');
        const applySection = document.getElementById('applySection');
        const backToList = document.getElementById('backToList');
        const emptyState = document.getElementById('emptyState');

        function isApplyView() {
            return applySection.classList.contains('show');
        }

        // Switch to apply view
        function showApplyView() {
            linksView.style.display = 'none';
            applySection.classList.add('show');
            applyToggleBtn.innerHTML = '<i class="bi bi-list me-1"></i>返回列表';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Switch to list view
        function showListView() {
            linksView.style.display = 'block';
            applySection.classList.remove('show');
            applyToggleBtn.innerHTML = '<i class="bi bi-plus-circle me-1"></i>申请友链';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Apply button
        if (applyToggleBtn) {
            applyToggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (isApplyView()) {
                    showListView();
                } else {
                    showApplyView();
                }
            });
        }

        // Back to list button
        if (backToList) {
            backToList.addEventListener('click', function(e) {
                e.preventDefault();
                showListView();
            });
        }

        // Empty state apply button
        if (applyFromEmpty) {
            applyFromEmpty.addEventListener('click', function(e) {
                e.preventDefault();
                showApplyView();
            });
        }

        // Back button (navbar)
        const backButton = document.getElementById('backButton');
        if (backButton) {
            backButton.addEventListener('click', function(e) {
                e.preventDefault();
                const referrer = document.referrer;
                const currentHost = window.location.hostname;
                if (referrer && referrer.includes(currentHost) && window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.href = '/';
                }
            });
        }

        // Notice toggle
        const notice = document.getElementById('friendLinksNotice');
        if (notice) {
            notice.addEventListener('click', function(e) {
                if (e.target.closest('.notice-content')) return;
                this.classList.toggle('expanded');
                const toggleText = this.querySelector('.notice-toggle span');
                toggleText.textContent = this.classList.contains('expanded') ? '收起详情' : '展开查看';
            });
        }

        // Load friend link images
        function loadLinkImages() {
            const logoContainers = document.querySelectorAll('.link-logo[data-logo]');
            logoContainers.forEach(container => {
                const logoUrl = container.dataset.logo;
                const img = new Image();
                img.onload = function() {
                    img.classList.add('loaded');
                    const placeholder = container.querySelector('.logo-placeholder');
                    if (placeholder) placeholder.style.display = 'none';
                    container.insertBefore(img, container.firstChild);
                };
                img.onerror = function() {
                    container.classList.add('failed');
                };
                img.src = logoUrl;
            });
        }

        // Logo file upload
        const logoFile = document.getElementById('logoFile');
        if (logoFile) {
            logoFile.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;
                if (!file.type.startsWith('image/')) {
                    showToast('请选择图片文件', 'error');
                    return;
                }
                if (file.size > 2 * 1024 * 1024) {
                    showToast('图片大小不能超过 2MB', 'error');
                    return;
                }
                const formData = new FormData();
                formData.append('image', file);
                fetch('/admin/upload_image.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('logoInput').value = data.url;
                        showToast('Logo 上传成功！', 'success');
                    } else {
                        showToast(data.error || '上传失败', 'error');
                    }
                })
                .catch(() => showToast('上传失败，请重试', 'error'));
            });
        }

        // Form submit
        const applyForm = document.getElementById('applyForm');
        if (applyForm) {
            applyForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(applyForm);
                const submitBtn = applyForm.querySelector('.btn-submit');
                const originalHTML = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>提交中...';

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        applyForm.reset();
                        showListView();
                    } else {
                        showToast(data.error || '提交失败', 'error');
                    }
                })
                .catch(() => showToast('提交失败，请重试', 'error'))
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalHTML;
                });
            });
        }

        // Init
        document.addEventListener('DOMContentLoaded', function() {
            loadLinkImages();
        });
    </script>

<?php
/**
 * 发送新友链申请通知给站长
 */
function sendNewApplicationNotice($adminEmail, $studioName, $applicationData) {
    if (EMAIL_MODE === 'test') {
        logEmailSending($adminEmail, '新友链申请通知', 'test_mode', '测试模式，未实际发送');
        return true;
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
        $mail->addAddress($adminEmail);

        $mail->isHTML(true);
        $mail->Subject = '【新友链申请】' . $applicationData['name'] . ' - ' . $studioName;
        $mail->Body = getNewApplicationEmailTemplate($studioName, $applicationData);
        $mail->AltBody = "收到新的友链申请！\n\n" .
            "网站名称: {$applicationData['name']}\n" .
            "网站链接: {$applicationData['url']}\n" .
            "RSS地址: " . (!empty($applicationData['rss_url']) ? $applicationData['rss_url'] : '未提供') . "\n" .
            "联系人: {$applicationData['contact_name']}\n" .
            "联系邮箱: {$applicationData['contact_email']}\n" .
            "申请时间: " . date('Y-m-d H:i:s') . "\n\n" .
            "请登录后台审核此申请。";

        if ($mail->send()) {
            logEmailSending($adminEmail, '新友链申请通知', 'success', '邮件发送成功');
            return true;
        } else {
            logEmailSending($adminEmail, '新友链申请通知', 'error', '发送失败但无异常');
            return false;
        }
    } catch (Exception $e) {
        $error_msg = "邮件发送失败: " . ($mail->ErrorInfo ?? $e->getMessage());
        logEmailSending($adminEmail, '新友链申请通知', 'error', $error_msg);
        error_log($error_msg);
        return false;
    }
}

/**
 * 新友链申请通知邮件模板
 */
function getNewApplicationEmailTemplate($studioName, $applicationData) {
    $nameEscaped = htmlspecialchars($applicationData['name']);
    $urlEscaped = htmlspecialchars($applicationData['url']);
    $logoEscaped = htmlspecialchars($applicationData['logo']);
    $descriptionEscaped = htmlspecialchars($applicationData['description'] ?: '暂无描述');
    $rssUrlEscaped = htmlspecialchars($applicationData['rss_url'] ?? '');
    $categoryNameEscaped = htmlspecialchars($applicationData['category_name'] ?: '未分类');
    $contactNameEscaped = htmlspecialchars($applicationData['contact_name']);
    $contactEmailEscaped = htmlspecialchars($applicationData['contact_email']);
    $studioNameEscaped = htmlspecialchars($studioName);

    $logoHtml = $logoEscaped ? '<img src="' . $logoEscaped . '" style="width: 80px; height: 80px; border-radius: 10px; object-fit: cover; border: 2px solid #e0e0e0;" alt="Logo">' :
        '<div style="width: 80px; height: 80px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 32px; font-weight: bold;">' . mb_substr($nameEscaped, 0, 1) . '</div>';

    return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新友链申请通知 - ' . $studioNameEscaped . '</title>
    <style>
        body { font-family: "Microsoft YaHei", Arial, sans-serif; line-height: 1.6; color: #333; background: #f5f5f5; margin: 0; padding: 20px; }
        .email-container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .email-header { background: linear-gradient(135deg, #ff9800 0%, #ff5722 100%); padding: 30px 20px; text-align: center; }
        .email-header h1 { color: white; margin: 0; font-size: 24px; }
        .email-header p { color: rgba(255,255,255,0.9); margin: 10px 0 0 0; }
        .email-content { padding: 30px; }
        .badge-new { background: #ff5722; color: white; padding: 5px 15px; border-radius: 15px; font-size: 0.85rem; font-weight: bold; margin-bottom: 20px; display: inline-block; }
        .site-info { background: #fff3e0; border-left: 4px solid #ff9800; padding: 25px; margin: 20px 0; border-radius: 4px; }
        .site-header { display: flex; align-items: center; gap: 20px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #e0e0e0; }
        .site-details p { margin: 8px 0; font-size: 15px; }
        .site-details strong { color: #e65100; min-width: 90px; display: inline-block; }
        .category-badge { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 3px 12px; border-radius: 10px; font-size: 12px; font-weight: 500; margin-left: 8px; }
        .info-box { background: #e3f2fd; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .action-box { background: #e8f5e9; border-left: 4px solid #4CAF50; padding: 20px; margin: 25px 0; border-radius: 4px; }
        .action-box a { background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%); color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold; margin-top: 10px; transition: transform 0.2s; }
        .action-box a:hover { transform: translateY(-2px); }
        .email-footer { text-align: center; padding: 20px; background: #f8f9fa; border-top: 1px solid #eee; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>新友链申请通知</h1>
            <p>' . $studioNameEscaped . ' - 管理后台提醒</p>
        </div>
        
        <div class="email-content">
            <div class="badge-new">NEW</div>
            
            <h3 style="color: #333; margin-bottom: 25px;">收到新的友情链接申请，请及时审核</h3>
            
            <div class="site-info">
                <div class="site-header">
                    ' . $logoHtml . '
                    <div>
                        <h4 style="margin: 0 0 5px 0; color: #333; font-size: 20px;">' . $nameEscaped . '</h4>
                        <p style="margin: 0; color: #666; font-size: 14px;">申请时间：' . date('Y-m-d H:i:s') . '</p>
                    </div>
                </div>
                
                <div class="site-details">
                    <p><strong>网站链接：</strong><a href="' . $urlEscaped . '" target="_blank" style="color: #667eea; text-decoration: none;">' . $urlEscaped . '</a></p>
                    <p><strong>分类：</strong><span>' . $categoryNameEscaped . '</span></p>
                    <p><strong>网站描述：</strong>' . $descriptionEscaped . '</p>
                    <p><strong>RSS 地址：</strong>' . ($rssUrlEscaped ? '<a href="' . $rssUrlEscaped . '" target="_blank" style="color: #667eea; text-decoration: none;">' . $rssUrlEscaped . '</a>' : '未提供') . '</p>
                    <p><strong>联系人：</strong>' . $contactNameEscaped . '</p>
                    <p><strong>联系邮箱：</strong><a href="mailto:' . $contactEmailEscaped . '" style="color: #667eea; text-decoration: none;">' . $contactEmailEscaped . '</a></p>
                </div>
            </div>
            
            <div class="info-box">
                <strong>温馨提示：</strong>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>请在审核前访问申请网站，检查网站内容是否合规</li>
                    <li>确认申请网站已添加本站友链后再通过审核</li>
                    <li>如需联系申请人，可使用上述邮箱地址</li>
                </ul>
            </div>
            
            <div class="action-box">
                <h4 style="margin: 0 0 15px 0; color: #2e7d32;">立即处理此申请</h4>
                <p style="margin: 0; color: #666;">点击下方按钮前往管理后台审核此申请</p>
                <a href="http://' . $_SERVER['HTTP_HOST'] . '/admin/links.php?tab=applications" target="_blank">前往管理后台</a>
            </div>
        </div>
        
        <div class="email-footer">
            <p>此邮件由 <strong>' . $studioNameEscaped . '</strong> 系统自动发送</p>
            <p>收到此邮件说明有新的友链申请等待审核</p>
        </div>
    </div>
</body>
</html>';
}
?>
</body>
</html>
