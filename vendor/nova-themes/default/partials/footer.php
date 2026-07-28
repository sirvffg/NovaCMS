    <!-- 页脚 -->
    <footer class="footer mt-auto" style="background: var(--card-bg, #fff); border-top: 1px solid var(--border-color, #eee);">
        <div class="container py-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center">
                <div class="d-flex flex-wrap align-items-center gap-2 footer-links">
                    <?php if (!empty($config['contact_email'])): ?>
                    <a href="/license/terms.php" target="_blank" title="隐私声明" class="text-nowrap d-block d-md-inline">
                        <i class="bi bi-shield-lock me-1"></i>隐私声明
                    </a>
                    <?php endif; ?>
                </div>
                <div class="text-muted small">
                    &copy; <?= date('Y') ?> <?= e($config['website_name']) ?>. All rights reserved.
                </div>
            </div>
            <?php if (!empty($config['footer_extra'])): ?>
            <div class="footer-extra mt-1 text-center text-muted small">
                <?= $config['footer_extra'] ?>
            </div>
            <?php endif; ?>
        </div>
    </footer>

    <script src="https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="<?= NOVA_THEME_URL ?>/assets/js/theme.js"></script>
    <script>
    // 用户菜单
    fetch('/nova-json/v1/user/me')
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('user-menu-nav');
            if (data.code === 'rest_ok' && data.data.user) {
                el.innerHTML = `<div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="javascript:void(0);" role="button">
                        <i class="bi bi-person-circle me-1"></i>${data.data.user.username}
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        ${data.data.user.role === 'admin' ? '<li><a class="dropdown-item" href="/admin"><i class="bi bi-gear"></i> 管理后台</a></li>' : ''}
                        <li><a class="dropdown-item" href="/profile"><i class="bi bi-person"></i> 个人中心</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><form method="POST" action="/" style="display:inline;"><input type="hidden" name="action" value="logout"><button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right"></i> 退出登录</button></form></li>
                    </ul>
                </div>`;
            } else {
                el.innerHTML = `<div class="nav-item d-none d-lg-flex align-items-center">
                    <a href="/login" class="btn btn-outline-light btn-sm me-2">登录</a>
                    <a href="/register" class="btn btn-primary btn-sm">注册</a>
                </div>
                <div class="nav-item d-lg-none">
                    <a href="/login" class="nav-link"><i class="bi bi-box-arrow-in-right me-1"></i> 登录</a>
                    <a href="/register" class="nav-link"><i class="bi bi-person-plus me-1"></i> 注册</a>
                </div>`;
            }
        });
    </script>
    <?php if (!empty($extraFooter)) echo $extraFooter; ?>
</body>
</html>
