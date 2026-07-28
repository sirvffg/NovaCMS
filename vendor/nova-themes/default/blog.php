<?php
$pageTitle = '博客';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>

<div class="container my-5" style="padding-top: 80px;">
    <h1 class="mb-4 text-center">博客文章</h1>
    <div id="blog-posts" class="row g-4">
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">加载中...</p>
        </div>
    </div>
</div>

<script>
function loadPosts(page = 1) {
    fetch(`/nova-json/v1/posts?page=${page}&per_page=10`)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('blog-posts');
            if (data.code === 'rest_ok' && data.data?.posts?.length) {
                container.innerHTML = data.data.posts.map(post => `
                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title">${post.title}</h5>
                                <p class="text-muted small mb-2">
                                    <i class="bi bi-person"></i> ${post.author} &nbsp;
                                    <i class="bi bi-calendar"></i> ${post.created_at} &nbsp;
                                    <i class="bi bi-eye"></i> ${post.views || 0}
                                </p>
                                <p class="card-text">${post.excerpt || (post.content ? post.content.substring(0, 200) : '')}</p>
                                <a href="/blog?id=${post.id}" class="btn btn-outline-primary btn-sm">阅读全文</a>
                            </div>
                        </div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<div class="col-12 text-center py-5"><p class="text-muted">暂无文章</p></div>';
            }
        });
}
loadPosts();
</script>

<?php
include $themePath . '/partials/footer.php';
?>
