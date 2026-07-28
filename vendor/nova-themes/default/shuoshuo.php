<?php
$pageTitle = '说说';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<div class="container my-5" style="padding-top: 80px;">
    <h1 class="mb-4 text-center">说说</h1>
    <div id="shuoshuo-list">
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">加载中...</p>
        </div>
    </div>
</div>
<script>
fetch('/nova-json/v1/statuses/shuoshuo')
    .then(r => r.json())
    .then(data => {
        const container = document.getElementById('shuoshuo-list');
        if (data.code === 'rest_ok' && data.data?.length) {
            container.innerHTML = data.data.map(item => `
                <div class="card mb-3 shadow-sm">
                    <div class="card-body">
                        <p class="card-text">${item.content}</p>
                        <small class="text-muted">${item.created_at}</small>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = '<p class="text-center text-muted py-5">暂无说说</p>';
        }
    });
</script>
<?php include $themePath . '/partials/footer.php'; ?>
