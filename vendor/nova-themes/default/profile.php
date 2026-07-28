<?php
$pageTitle = '个人中心';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<div class="container my-5" style="padding-top: 80px;">
    <h1 class="mb-4 text-center">个人中心</h1>
    <div id="profile-content">
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">加载中...</p>
        </div>
    </div>
</div>
<script>
fetch('/nova-json/v1/user/me')
    .then(r => r.json())
    .then(data => {
        const container = document.getElementById('profile-content');
        if (data.code === 'rest_ok' && data.data?.user) {
            const user = data.data.user;
            container.innerHTML = `
                <div class="card shadow-sm mx-auto" style="max-width:500px;">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <div class="display-1">${user.avatar ? `<img src="${user.avatar}" class="rounded-circle" width="100" height="100">` : '<i class="bi bi-person-circle"></i>'}</div>
                            <h4 class="mt-2">${user.username}</h4>
                            <span class="badge ${user.role === 'admin' ? 'bg-danger' : 'bg-secondary'}">${user.role === 'admin' ? '管理员' : '普通用户'}</span>
                        </div>
                        <p><strong>邮箱：</strong>${user.email || '未设置'}</p>
                        <p><strong>注册时间：</strong>${user.created_at || '-'}</p>
                    </div>
                </div>
            `;
        } else {
            container.innerHTML = '<p class="text-center text-muted py-5">请先<a href="/login">登录</a></p>';
        }
    });
</script>
<?php include $themePath . '/partials/footer.php'; ?>
