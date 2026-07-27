<?php
/**
 * 生成静态 rss.xml 文件到网站根目录
 * 保存/删除文章时调用
 */
function generateRssXml() {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../vendor/public/Parsedown.php';

    $db = getDB();
    $config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
    $posts = $db->query("SELECT * FROM blog_posts WHERE is_published=1 ORDER BY created_at DESC LIMIT 20")->fetchAll();

    // 获取网站基础URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
    $currentDate = date(DATE_RSS);

    // 清理内容
    function cleanRSSContent($content, $baseUrl) {
        $content = preg_replace('/\{hide\}.*?\{\/hide\}/s', '', $content);
        $parsedown = new Parsedown();
        $content = $parsedown->text($content);
        // 将相对图片路径转为绝对路径
        $content = preg_replace('/<img\s+([^>]*?)src\s*=\s*"\/([^"]+)"/i', '<img $1src="' . $baseUrl . '/$2"', $content);
        // 将非标准 <color:xxx> 标签转为标准 <span style="color:xxx">
        $content = preg_replace('/<color:(\w+)>/i', '<span style="color:$1">', $content);
        $content = preg_replace('/<\/color:\w+>/i', '</span>', $content);
        return $content;
    }

    function replacePrivacyContent($content, $link) {
        $content = preg_replace('/\[Privacy\].*?\[\/Privacy\]/s',
            '<div style="background: #f0f0f0; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #667eea;"><strong>🔒 此内容需要访问网站查看</strong><br><br><a href="' . $link . '" style="color: #667eea; text-decoration: none; font-weight: 500;">点击前往查看 →</a></div>',
            $content);
        $content = preg_replace('/\[Paid\].*?\[\/Paid\]/s',
            '<div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #ffc107;"><strong>💰 此内容需要访问网站付费查看</strong><br><br><a href="' . $link . '" style="color: #856404; text-decoration: none; font-weight: 500;">点击前往查看 →</a></div>',
            $content);
        return $content;
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
    $xml .= '<channel>' . "\n";
    $xml .= '    <title>' . htmlspecialchars($config['website_name'] ?? '我的博客') . '</title>' . "\n";
    $xml .= '    <link>' . $baseUrl . '</link>' . "\n";
    $xml .= '    <description>' . htmlspecialchars($config['description'] ?? '最新文章订阅') . '</description>' . "\n";
    $xml .= '    <language>zh-cn</language>' . "\n";
    $xml .= '    <lastBuildDate>' . $currentDate . '</lastBuildDate>' . "\n";
    $xml .= '    <generator>Blog System</generator>' . "\n";
    $xml .= '    <atom:link href="' . $baseUrl . '/license/rss.xml" rel="self" type="application/rss+xml"></atom:link>' . "\n";

    foreach ($posts as $post) {
        $postUrl = $baseUrl . '/blog.php?id=' . $post['id'];
        $content = $post['content'];
        $content = replacePrivacyContent($content, $postUrl);
        $content = cleanRSSContent($content, $baseUrl);

        $xml .= '    <item>' . "\n";
        $xml .= '        <title>' . htmlspecialchars($post['title']) . '</title>' . "\n";
        $xml .= '        <link>' . $postUrl . '</link>' . "\n";
        $xml .= '        <guid>' . $postUrl . '</guid>' . "\n";
        $xml .= '        <pubDate>' . date(DATE_RSS, strtotime($post['created_at'])) . '</pubDate>' . "\n";
        $xml .= '        <description><![CDATA[' . "\n";
        $xml .= '            ' . $content . "\n";
        $xml .= '        ]]></description>' . "\n";
        if (!empty($post['author'])) {
            $xml .= '        <author>' . htmlspecialchars($config['contact_email'] ?? 'admin@example.com') . ' (' . htmlspecialchars($post['author']) . ')</author>' . "\n";
        }
        if (!empty($post['category'])) {
            $xml .= '        <category>' . htmlspecialchars($post['category']) . '</category>' . "\n";
        }
        $xml .= '    </item>' . "\n";
    }

    $xml .= '</channel>' . "\n";
    $xml .= '</rss>' . "\n";

    // 写入到 license 目录 rss.xml
    $rssPath = __DIR__ . '/../license/rss.xml';
    file_put_contents($rssPath, $xml, LOCK_EX);
}
