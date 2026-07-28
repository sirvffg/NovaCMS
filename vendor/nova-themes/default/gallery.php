<?php
$pageTitle = '相册';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<div class="container my-5" style="padding-top: 80px;">
    <h1 class="mb-4 text-center">相册</h1>
    <div id="gallery-list" class="row g-4">
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">加载中...</p>
        </div>
    </div>
</div>
<script>
fetch('/nova-json/v1/statuses/gallery')
    .then(r => r.json())
    .then(data => {
        const container = document.getElementById('gallery-list');
        if (data.code === 'rest_ok' && data.data?.length) {
            container.innerHTML = data.data.map(item => `
                <div class="col-md-4 col-sm-6">
                    <div class="card h-100 shadow-sm">
                        <img src="${item.image_url}" class="card-img-top" alt="${item.title || ''}" style="height:200px;object-fit:cover;">
                        <div class="card-body">
                            <p class="card-text text-center">${item.title || ''}</p>
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = '<p class="text-center text-muted py-5">暂无照片</p>';
        }
    });
</script>
<?php include $themePath . '/partials/footer.php'; ?>
