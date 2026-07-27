<?php
// 生成 RSS Feed
header('Content-Type: application/rss+xml; charset=utf-8');

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../vendor/public/Parsedown.php';
recordVisit($_SERVER['REQUEST_URI']);

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$posts = $db->query("SELECT * FROM blog_posts WHERE is_published=1 ORDER BY created_at DESC LIMIT 20")->fetchAll();

// 获取网站基础URL
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
$currentDate = date(DATE_RSS);

// 清理内容的辅助函数 - 保留完整内容但移除保护内容
function cleanRSSContent($content, $baseUrl) {
    // 移除保护内容标签
    $content = preg_replace('/\{hide\}.*?\{\/hide\}/s', '', $content);

    // 使用 Parsedown 解析 Markdown
    $parsedown = new Parsedown();
    $content = $parsedown->text($content);

    // 将相对图片路径转为绝对路径
    $content = preg_replace('/<img\s+([^>]*?)src\s*=\s*"\/([^"]+)"/i', '<img $1src="' . $baseUrl . '/$2"', $content);

    // 将非标准 <color:xxx> 标签转为标准 <span style="color:xxx">
    $content = preg_replace('/<color:(\w+)>/i', '<span style="color:$1">', $content);
    $content = preg_replace('/<\/color:\w+>/i', '</span>', $content);

    return $content;
}

// 替换隐私内容的辅助函数
function replacePrivacyContent($content, $link) {
    // 将 [Privacy]...[/Privacy] 替换为提示文字
    $content = preg_replace('/\[Privacy\].*?\[\/Privacy\]/s', '<div style="background: #f0f0f0; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #667eea;"><strong>🔒 此内容需要访问网站查看</strong><br><br><a href="' . $link . '" style="color: #667eea; text-decoration: none; font-weight: 500;">点击前往查看 →</a></div>', $content);

    // 将 [Paid]...[/Paid] 替换为提示文字
    $content = preg_replace('/\[Paid\].*?\[\/Paid\]/s', '<div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #ffc107;"><strong>💰 此内容需要访问网站付费查看</strong><br><br><a href="' . $link . '" style="color: #856404; text-decoration: none; font-weight: 500;">点击前往查看 →</a></div>', $content);

    return $content;
}

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title><?= htmlspecialchars($config['website_name'] ?? '我的博客') ?></title>
    <link><?= $baseUrl ?></link>
    <description><?= htmlspecialchars($config['description'] ?? '最新文章订阅') ?></description>
    <language>zh-cn</language>
    <lastBuildDate><?= $currentDate ?></lastBuildDate>
    <generator>Blog System</generator>
    <atom:link href="<?= $baseUrl ?>/license/rss.php" rel="self" type="application/rss+xml"></atom:link>

    <?php foreach ($posts as $post): ?>
    <item>
        <title><?= htmlspecialchars($post['title']) ?></title>
        <link><?= $baseUrl ?>/blog.php?id=<?= $post['id'] ?></link>
        <guid><?= $baseUrl ?>/blog.php?id=<?= $post['id'] ?></guid>
        <pubDate><?= date(DATE_RSS, strtotime($post['created_at'])) ?></pubDate>
        <description><![CDATA[
            <?php
            $content = $post['content'];
            $postUrl = $baseUrl . '/blog.php?id=' . $post['id'];
            $content = replacePrivacyContent($content, $postUrl);
            $content = cleanRSSContent($content, $baseUrl);
            echo $content;
            ?>
        ]]></description>
        <?php if (!empty($post['author'])): ?>
        <author><?= htmlspecialchars($config['contact_email'] ?? 'admin@example.com') ?> (<?= htmlspecialchars($post['author']) ?>)</author>
        <?php endif; ?>
        <?php if (!empty($post['category'])): ?>
        <category><?= htmlspecialchars($post['category']) ?></category>
        <?php endif; ?>
    </item>
    <?php endforeach; ?>
</channel>
</rss>
