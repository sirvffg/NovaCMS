<?php
$pageTitle = '首页';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>

<!-- 首页横幅 -->
<section id="home" class="hero-section d-flex position-relative" style="padding-top: 120px;">
    <div id="bgLoading" class="loading-overlay" style="display: none;">
        <div class="loading-content">
            <div class="modern-loader"></div>
            <div class="loading-text">Loading</div>
        </div>
    </div>
    <div id="bgImage" class="hero-bg-media hero-bg-image" 
         data-custom-image="<?= !empty($config['home_bg_image']) ? e($config['home_bg_image']) : '' ?>"
         data-use-bing="<?= !empty($config['use_bing_bg']) && $config['use_bing_bg'] == 1 ? '1' : '0' ?>"
         style="background-image: url('<?= !empty($config['home_bg_image']) ? e($config['home_bg_image']) : 'https://bing.img.run/rand.php' ?>');">
    </div>
    <div class="hero-overlay"></div>
    <div class="container hero-content text-center text-white position-relative z-2 align-self-center">
        <h1 class="display-4 fw-bold artistic-font"><?= e($config['website_name']) ?></h1>
        <p class="lead mt-3"><?= e($config['website_slogan'] ?? '') ?></p>
    </div>
</section>

<!-- 最新博客 -->
<section class="container my-5">
    <h2 class="mb-4 text-center">最新文章</h2>
    <div id="latest-posts" class="row g-4">
        <!-- JS loads posts -->
    </div>
</section>

<script>
fetch('/nova-json/v1/posts?per_page=3')
    .then(r => r.json())
    .then(data => {
        const container = document.getElementById('latest-posts');
        if (data.code === 'rest_ok' && data.data?.posts) {
            container.innerHTML = data.data.posts.map(post => `
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">${post.title}</h5>
                            <p class="card-text text-muted small">${post.created_at}</p>
                            <p class="card-text">${post.excerpt || post.content.substring(0, 100)}</p>
                            <a href="/blog?id=${post.id}" class="btn btn-outline-primary btn-sm">阅读更多</a>
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = '<p class="text-center text-muted">暂无文章</p>';
        }
    });
</script>

<?php
include $themePath . '/partials/footer.php';
?>
