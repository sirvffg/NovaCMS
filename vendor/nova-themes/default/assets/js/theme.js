/**
 * NovaCMS Default Theme - JavaScript
 *
 * 主题核心功能：
 * - 主题切换（深色/浅色）
 * - 回到顶部按钮
 * - 代码块复制功能
 * - 移动端菜单优化
 */

(function() {
    'use strict';

    // ========================================
    // 主题切换功能
    // ========================================
    const ThemeToggle = {
        init() {
            this.bindEvents();
            this.applyStoredTheme();
        },

        bindEvents() {
            // 监听主题切换按钮（如果存在）
            const toggleBtn = document.querySelector('[data-theme-toggle]');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', () => this.toggle());
            }
        },

        applyStoredTheme() {
            const storedTheme = localStorage.getItem('theme');
            if (storedTheme) {
                document.documentElement.setAttribute('data-bs-theme', storedTheme);
            }
        },

        toggle() {
            const currentTheme = document.documentElement.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

            document.documentElement.setAttribute('data-bs-theme', newTheme);
            localStorage.setItem('theme', newTheme);
        }
    };

    // ========================================
    // 回到顶部按钮
    // ========================================
    const BackToTop = {
        init() {
            this.createButton();
            this.bindEvents();
        },

        createButton() {
            if (document.querySelector('.back-to-top')) return;

            const btn = document.createElement('button');
            btn.className = 'back-to-top';
            btn.setAttribute('aria-label', '回到顶部');
            btn.innerHTML = '<i class="bi bi-arrow-up"></i>';
            document.body.appendChild(btn);

            this.button = btn;
        },

        bindEvents() {
            window.addEventListener('scroll', () => this.toggleVisibility());
            if (this.button) {
                this.button.addEventListener('click', () => this.scrollToTop());
            }
        },

        toggleVisibility() {
            if (window.scrollY > 300) {
                this.button?.classList.add('show');
            } else {
                this.button?.classList.remove('show');
            }
        },

        scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }
    };

    // ========================================
    // 代码块复制功能
    // ========================================
    const CodeBlockCopy = {
        init() {
            this.addCopyButtons();
            this.bindEvents();
        },

        addCopyButtons() {
            const codeBlocks = document.querySelectorAll('.code-block-wrapper');

            codeBlocks.forEach(wrapper => {
                // 如果已有复制按钮，跳过
                if (wrapper.querySelector('.code-copy-btn')) return;

                const pre = wrapper.querySelector('pre');
                if (!pre) return;

                const button = document.createElement('button');
                button.className = 'code-copy-btn';
                button.innerHTML = '<i class="bi bi-clipboard"></i> 复制';
                button.setAttribute('aria-label', '复制代码');

                // 将按钮添加到 header 或直接添加到 wrapper
                const header = wrapper.querySelector('.code-block-header');
                if (header) {
                    header.appendChild(button);
                } else {
                    wrapper.appendChild(button);
                }
            });
        },

        bindEvents() {
            document.querySelectorAll('.code-copy-btn').forEach(btn => {
                btn.addEventListener('click', (e) => this.copyCode(e));
            });
        },

        async copyCode(e) {
            const button = e.currentTarget;
            const wrapper = button.closest('.code-block-wrapper');
            const code = wrapper?.querySelector('code') || wrapper?.querySelector('pre');

            if (!code) return;

            try {
                await navigator.clipboard.writeText(code.textContent);

                // 更新按钮状态
                const originalHTML = button.innerHTML;
                button.innerHTML = '<i class="bi bi-check2"></i> 已复制';
                button.classList.add('copied');

                setTimeout(() => {
                    button.innerHTML = originalHTML;
                    button.classList.remove('copied');
                }, 2000);
            } catch (err) {
                console.error('复制失败:', err);
                button.innerHTML = '<i class="bi bi-x-circle"></i> 复制失败';
            }
        }
    };

    // ========================================
    // 移动端菜单优化
    // ========================================
    const MobileMenu = {
        init() {
            this.bindEvents();
        },

        bindEvents() {
            // 点击空白处关闭移动端菜单
            document.addEventListener('click', (e) => {
                const navbar = document.querySelector('.navbar-collapse');
                const toggle = document.querySelector('.navbar-toggler');

                if (navbar?.classList.contains('show') &&
                    !navbar.contains(e.target) &&
                    !toggle?.contains(e.target)) {
                    navbar.classList.remove('show');
                }
            });
        }
    };

    // ========================================
    // 图片懒加载
    // ========================================
    const LazyLoad = {
        init() {
            if ('IntersectionObserver' in window) {
                const lazyImages = document.querySelectorAll('img[data-src]');

                const imageObserver = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                            imageObserver.unobserve(img);
                        }
                    });
                });

                lazyImages.forEach(img => imageObserver.observe(img));
            }
        }
    };

    // ========================================
    // 平滑滚动
    // ========================================
    const SmoothScroll = {
        init() {
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', (e) => {
                    const targetId = anchor.getAttribute('href');
                    if (targetId === '#') return;

                    const target = document.querySelector(targetId);
                    if (target) {
                        e.preventDefault();
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });
        }
    };

    // ========================================
    // 初始化所有功能
    // ========================================
    function initTheme() {
        ThemeToggle.init();
        BackToTop.init();
        CodeBlockCopy.init();
        MobileMenu.init();
        LazyLoad.init();
        SmoothScroll.init();
    }

    // DOM 加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTheme);
    } else {
        initTheme();
    }

    // 暴露到全局，方便调试
    window.NovaTheme = {
        toggleTheme: () => ThemeToggle.toggle(),
        init: initTheme
    };

})();