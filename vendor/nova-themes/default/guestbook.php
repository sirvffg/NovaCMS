<?php
$pageTitle = '留言板';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<div class="container my-5" style="padding-top: 80px;">
    <h1 class="mb-4 text-center">留言板</h1>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form id="guestbook-form">
                <div class="mb-3">
                    <input type="text" class="form-control" id="gb-nickname" placeholder="昵称" required>
                </div>
                <div class="mb-3">
                    <textarea class="form-control" id="gb-content" rows="3" placeholder="说点什么..." required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">提交留言</button>
            </form>
        </div>
    </div>
    <div id="guestbook-list">
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">加载中...</p>
        </div>
    </div>
</div>
<script>
function loadGuestbook() {
    fetch('/nova-json/v1/statuses/guestbook')
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('guestbook-list');
            if (data.code === 'rest_ok' && data.data?.length) {
                container.innerHTML = data.data.map(item => `
                    <div class="card mb-3 shadow-sm">
                        <div class="card-body">
                            <h6 class="card-subtitle mb-2 text-primary">${item.nickname}</h6>
                            <p class="card-text">${item.content}</p>
                            <small class="text-muted">${item.created_at}</small>
                            ${item.reply ? `<div class="mt-2 p-2 bg-light rounded"><small class="text-muted">回复：${item.reply}</small></div>` : ''}
                        </div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<p class="text-center text-muted py-5">暂无留言</p>';
            }
        });
}
document.getElementById('guestbook-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const nickname = document.getElementById('gb-nickname').value;
    const content = document.getElementById('gb-content').value;
    fetch('/nova-json/v1/statuses/guestbook', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({nickname, content})
    }).then(r => r.json()).then(data => {
        if (data.code === 'rest_ok') {
            document.getElementById('gb-nickname').value = '';
            document.getElementById('gb-content').value = '';
            loadGuestbook();
        }
    });
});
loadGuestbook();
</script>
<?php include $themePath . '/partials/footer.php'; ?>
