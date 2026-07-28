<nav class="navbar navbar-expand-lg fixed-top navbar-custom">
    <div class="container-fluid px-4">
        <a class="navbar-brand d-flex align-items-center artistic-font" href="/" style="font-size: 1.5rem;">
            <?= e($config['website_name']) ?>
        </a>

        <div class="theme-switch-wrapper d-lg-none ms-auto me-2">
            <label class="theme-switch" for="theme-checkbox-mobile">
                <input type="checkbox" id="theme-checkbox-mobile" />
                <div class="slider">
                    <i class="bi bi-sun-fill icon sun"></i>
                    <i class="bi bi-moon-fill icon moon"></i>
                </div>
            </label>
        </div>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" style="border: none; padding: 4px 8px; background: rgba(255,255,255,0.3); border-radius: 6px;">
            <i class="bi bi-list text-white fs-3"></i>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="/blog">博客</a></li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="javascript:void(0);" id="spaceDropdown" role="button" aria-expanded="false">
                        <i class="bi bi-stars me-1"></i>空间
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="spaceDropdown">
                        <li><a class="dropdown-item" href="/friend-links"><i class="bi bi-link-45deg me-2"></i>友链</a></li>
                        <li><a class="dropdown-item" href="/shuoshuo"><i class="bi bi-chat-quote me-2"></i>说说</a></li>
                        <li><a class="dropdown-item" href="/gallery"><i class="bi bi-images me-2"></i>相册</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="javascript:void(0);" id="otherDropdown" role="button" aria-expanded="false">
                        <i class="bi bi-send me-1"></i>其他
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="otherDropdown">
                        <li><a class="dropdown-item" href="/license/terms.php"><i class="bi bi-file-earmark-text me-2"></i>协议</a></li>
                        <li><a class="dropdown-item" href="/license/rss.php"><i class="bi bi-rss me-2"></i>RSS</a></li>
                        <li><a class="dropdown-item" href="/license/sitemap.php"><i class="bi bi-map me-2"></i>Sitemap</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/guestbook">
                        <i class="bi bi-chat-text me-1"></i>留言板
                    </a>
                </li>

                <!-- 桌面端主题切换 -->
                <li class="nav-item d-none d-lg-flex align-items-center">
                    <div class="theme-switch-wrapper ms-2">
                        <label class="theme-switch" for="theme-checkbox">
                            <input type="checkbox" id="theme-checkbox" />
                            <div class="slider">
                                <i class="bi bi-sun-fill icon sun"></i>
                                <i class="bi bi-moon-fill icon moon"></i>
                            </div>
                        </label>
                    </div>
                </li>

                <!-- 用户菜单 (JS 动态渲染) -->
                <li id="user-menu-nav" class="nav-item"></li>
            </ul>
        </div>
    </div>
</nav>
