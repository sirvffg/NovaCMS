<?php
$pageTitle = '留言板';
$pageKey = 'guestbook';
$pageDescription = '留下一句话，让一次访问变成真实的连接。';
$communityCssVersion = (string)(@filemtime($themePath . '/assets/css/community.css') ?: 1);
$communityJsVersion = (string)(@filemtime($themePath . '/assets/js/community.js') ?: 1);
$extraHead = '<link href="' . NOVA_THEME_URL . '/assets/css/community.css?v=' . e($communityCssVersion) . '" rel="stylesheet">';
$extraFooter = '<script src="' . NOVA_THEME_URL . '/assets/js/community.js?v=' . e($communityJsVersion) . '"></script>';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<main id="main-content" class="site-main community-page guestbook-page" data-community-page="guestbook">
    <section class="community-hero guestbook-hero">
        <div class="site-container community-hero-grid">
            <div data-reveal><span class="site-eyebrow"><i class="bi bi-chat-square-heart"></i> Say hello</span><h1>来都来了，留句话吧</h1><p>分享你的想法、建议或简单问候。每一条友善的留言都会被认真看到。</p></div>
            <div class="guestbook-doodle" aria-hidden="true" data-reveal><span><i class="bi bi-chat-dots"></i></span><span><i class="bi bi-emoji-smile"></i></span><span><i class="bi bi-send"></i></span></div>
        </div>
    </section>
    <section class="community-section guestbook-section" aria-labelledby="guestbook-title">
        <div class="site-container guestbook-layout">
            <aside class="guestbook-compose" data-reveal>
                <span class="site-eyebrow"><i class="bi bi-pencil-square"></i> New message</span>
                <h2>写一条留言</h2><p>邮箱不会公开，仅在收到回复时用于通知。</p>
                <form data-guestbook-form novalidate>
                    <div class="form-honeypot" aria-hidden="true"><label for="gb-company">公司</label><input id="gb-company" name="company" tabindex="-1" autocomplete="off"></div>
                    <div class="form-row"><label for="gb-nickname">昵称</label><input id="gb-nickname" name="nickname" maxlength="50" required placeholder="怎么称呼你"></div>
                    <div class="form-grid"><div class="form-row"><label for="gb-email">邮箱 <span>可选</span></label><input id="gb-email" name="email" type="email" maxlength="100" autocomplete="email" placeholder="name@example.com"></div><div class="form-row"><label for="gb-website">个人网站 <span>可选</span></label><input id="gb-website" name="website" type="url" maxlength="255" placeholder="https://"></div></div>
                    <div class="form-row"><label for="gb-content">留言内容</label><textarea id="gb-content" name="content" rows="5" maxlength="2000" required placeholder="想说点什么？"></textarea><small><span data-guestbook-length>0</span> / 2000</small></div>
                    <p class="form-feedback" data-guestbook-feedback aria-live="polite"></p>
                    <button class="site-button site-button-primary" type="submit">提交留言 <i class="bi bi-send"></i></button>
                </form>
            </aside>
            <div class="guestbook-feed">
                <header class="community-heading"><div><span class="site-eyebrow"><i class="bi bi-collection"></i> Messages</span><h2 id="guestbook-title">最近留言</h2></div><p data-guestbook-count>正在打开留言簿…</p></header>
                <div class="guestbook-list" data-guestbook-list aria-live="polite" aria-busy="true"><div class="community-skeleton skeleton-message"></div><div class="community-skeleton skeleton-message"></div></div>
                <nav class="community-pagination" data-guestbook-pagination aria-label="留言分页"></nav>
            </div>
        </div>
    </section>
</main>
<?php include $themePath . '/partials/footer.php'; ?>
