<?php
$pageTitle = '友情链接';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<div class="container my-5" style="padding-top: 80px;">
    <h1 class="mb-4 text-center">友情链接</h1>
    <div id="friend-links" class="row g-4">
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">加载中...</p>
        </div>
    </div>
</div>
<script>
fetch('/nova-json/v1/statuses/friend-links')
    .then(r => r.json())
    .then(data => {
        const container = document.getElementById('friend-links');
        if (data.code === 'rest_ok' && data.data?.length) {
            container.innerHTML = data.data.map(link => `
                <div class="col-md-4 col-sm-6">
                    <div class="card h-100 shadow-sm text-center">
                        <div class="card-body">
                            <h5 class="card-title">${link.name}</h5>
                            <p class="card-text small text-muted">${link.description || ''}</p>
                            <a href="${link.url}" target="_blank" class="btn btn-outline-primary btn-sm">访问</a>
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = '<p class="text-center text-muted py-5">暂无友链</p>';
        }
    });
</script>
<?php include $themePath . '/partials/footer.php'; ?>
