(function () {
    'use strict';

    var body = document.body;
    if (!body) return;

    var desktopBreakpoint = 820;
    var sidebar = document.getElementById('sidebar');
    var overlay = document.querySelector('[data-sidebar-overlay]');
    var toggleButtons = document.querySelectorAll('[data-sidebar-toggle]');
    var closeButtons = document.querySelectorAll('[data-sidebar-close]');
    var themeButtons = document.querySelectorAll('[data-theme-toggle]');
    var commandDialog = document.querySelector('[data-command-dialog]');
    var commandInput = document.querySelector('[data-command-input]');
    var commandResults = document.querySelector('[data-command-results]');
    var commandOpenButtons = document.querySelectorAll('[data-command-open]');
    var commandCloseButtons = document.querySelectorAll('[data-command-close]');
    var commandItems = [];
    var commandFiltered = [];
    var commandSelectedIndex = 0;
    var lastSidebarLayout = null;

    function isMobile() {
        return window.innerWidth <= desktopBreakpoint;
    }

    function updateSidebarControls() {
        var mobile = isMobile();
        var mobileOpen = body.classList.contains('mobile-open');
        var collapsed = !mobile && body.classList.contains('collapsed');

        toggleButtons.forEach(function (button) {
            button.setAttribute('aria-expanded', mobile ? (mobileOpen ? 'true' : 'false') : (collapsed ? 'false' : 'true'));
        });

        if (overlay) {
            overlay.setAttribute('aria-hidden', mobileOpen ? 'false' : 'true');
        }
        if (sidebar) {
            if (mobile && !mobileOpen) {
                sidebar.setAttribute('aria-hidden', 'true');
                sidebar.setAttribute('inert', '');
            } else {
                sidebar.removeAttribute('aria-hidden');
                sidebar.removeAttribute('inert');
            }

            sidebar.querySelectorAll('.menu-item > [data-menu-label]').forEach(function (control) {
                if (collapsed) {
                    control.setAttribute('title', control.getAttribute('data-menu-label') || '');
                    control.setAttribute('data-collapse-title', 'true');
                } else if (control.getAttribute('data-collapse-title') === 'true') {
                    control.removeAttribute('title');
                    control.removeAttribute('data-collapse-title');
                }
            });

            sidebar.querySelectorAll('.submenu-toggle').forEach(function (control) {
                var submenuId = control.getAttribute('aria-controls');
                var submenu = submenuId ? document.getElementById(submenuId) : control.nextElementSibling;
                var isOpen = control.classList.contains('open') && !collapsed;
                control.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (submenu) {
                    submenu.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
                    submenu.toggleAttribute('inert', !isOpen);
                }
            });
        }
    }

    function syncSidebarState() {
        var mobile = isMobile();
        if (mobile) {
            body.classList.remove('collapsed');
            if (lastSidebarLayout !== 'mobile') body.classList.remove('mobile-open');
            lastSidebarLayout = 'mobile';
            updateSidebarControls();
            return;
        }

        body.classList.remove('mobile-open');
        try {
            body.classList.toggle('collapsed', localStorage.getItem('admin_sidebar_collapsed') === 'true');
        } catch (error) {
            body.classList.remove('collapsed');
        }
        lastSidebarLayout = 'desktop';
        updateSidebarControls();
    }

    function toggleSidebar(forceOpen) {
        if (isMobile()) {
            var shouldOpen = typeof forceOpen === 'boolean' ? forceOpen : !body.classList.contains('mobile-open');
            body.classList.toggle('mobile-open', shouldOpen);
            updateSidebarControls();
            return;
        }

        body.classList.toggle('collapsed');
        var collapsed = body.classList.contains('collapsed');
        try {
            localStorage.setItem('admin_sidebar_collapsed', collapsed ? 'true' : 'false');
        } catch (error) {
            // Storage can be unavailable in privacy modes; visual state still works.
        }
        updateSidebarControls();
    }

    function setSubmenuState(element, isOpen) {
        if (!element) return;
        element.classList.toggle('open', isOpen);
        updateSidebarControls();
    }

    function toggleSubmenu(element) {
        if (!element) return;

        var expandedFromCollapsed = !isMobile() && body.classList.contains('collapsed');
        if (expandedFromCollapsed) {
            body.classList.remove('collapsed');
            try {
                localStorage.setItem('admin_sidebar_collapsed', 'false');
            } catch (error) {
                // The expanded state remains valid for the current page.
            }
            updateSidebarControls();
        }

        setSubmenuState(element, expandedFromCollapsed || !element.classList.contains('open'));
    }

    function getTheme() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    }

    function updateThemeControls() {
        var theme = getTheme();
        themeButtons.forEach(function (button) {
            var icon = button.querySelector('[data-theme-icon]');
            button.setAttribute('aria-label', theme === 'dark' ? '切换到浅色主题' : '切换到深色主题');
            button.setAttribute('title', theme === 'dark' ? '浅色主题' : '深色主题');
            if (icon) {
                icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
                icon.setAttribute('data-theme-icon', '');
                icon.setAttribute('aria-hidden', 'true');
            }
        });
    }

    function setTheme(theme, persist) {
        var nextTheme = theme === 'dark' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-bs-theme', nextTheme);
        if (persist !== false) {
            try {
                localStorage.setItem('theme', nextTheme);
            } catch (error) {
                // Ignore unavailable storage.
            }
        }
        updateThemeControls();
        window.dispatchEvent(new CustomEvent('admin:theme-change', { detail: { theme: nextTheme } }));
    }

    function showToast(message, type, duration) {
        var region = document.querySelector('[data-toast-region]');
        if (!region) return null;

        var normalizedType = type === 'danger' ? 'error' : (type || 'success');
        var variants = {
            success: { icon: 'bi-check-lg', color: 'var(--admin-success)' },
            error: { icon: 'bi-exclamation-lg', color: 'var(--admin-danger)' },
            warning: { icon: 'bi-exclamation-triangle', color: 'var(--admin-warning)' },
            info: { icon: 'bi-info-lg', color: 'var(--admin-info)' }
        };
        var variant = variants[normalizedType] || variants.info;
        var toast = document.createElement('div');
        toast.className = 'admin-toast';
        toast.setAttribute('role', normalizedType === 'error' ? 'alert' : 'status');
        toast.style.setProperty('--toast-color', variant.color);

        var icon = document.createElement('span');
        icon.className = 'admin-toast-icon';
        icon.setAttribute('aria-hidden', 'true');
        var iconGlyph = document.createElement('i');
        iconGlyph.className = 'bi ' + variant.icon;
        icon.appendChild(iconGlyph);

        var copy = document.createElement('span');
        copy.className = 'admin-toast-message';
        copy.textContent = String(message || '操作已完成');

        var close = document.createElement('button');
        close.className = 'admin-toast-close';
        close.type = 'button';
        close.setAttribute('aria-label', '关闭通知');
        close.innerHTML = '<i class="bi bi-x" aria-hidden="true"></i>';

        function dismiss() {
            if (!toast.isConnected) return;
            toast.classList.add('is-leaving');
            window.setTimeout(function () { toast.remove(); }, 180);
        }

        close.addEventListener('click', dismiss);
        toast.appendChild(icon);
        toast.appendChild(copy);
        toast.appendChild(close);
        region.appendChild(toast);
        window.setTimeout(dismiss, Number(duration) > 0 ? Number(duration) : 3600);
        return toast;
    }

    function showLoading(text) {
        var loading = document.getElementById('loading-overlay');
        if (!loading) return;
        var label = loading.querySelector('[data-loading-text]');
        if (label) label.textContent = text || '正在处理…';
        loading.classList.add('active');
        loading.setAttribute('aria-hidden', 'false');
    }

    function hideLoading() {
        var loading = document.getElementById('loading-overlay');
        if (!loading) return;
        loading.classList.remove('active');
        loading.setAttribute('aria-hidden', 'true');
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function formatFileSize(bytes) {
        var size = Number(bytes) || 0;
        if (size < 1024) return size + ' B';
        if (size < 1024 * 1024) return (size / 1024).toFixed(2) + ' KB';
        if (size < 1024 * 1024 * 1024) return (size / (1024 * 1024)).toFixed(2) + ' MB';
        return (size / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    }

    function collectCommandItems() {
        if (!sidebar) return [];
        return Array.prototype.map.call(sidebar.querySelectorAll('.sidebar-menu a[href]'), function (link) {
            var labelElement = link.querySelector('.menu-text');
            var iconElement = link.querySelector('.menu-icon i');
            return {
                label: (labelElement ? labelElement.textContent : link.textContent).trim(),
                href: link.getAttribute('href'),
                target: link.getAttribute('target') || '',
                icon: iconElement ? Array.prototype.find.call(iconElement.classList, function (name) { return name.indexOf('bi-') === 0; }) : 'bi-arrow-right-circle',
                keywords: ((labelElement ? labelElement.textContent : link.textContent) + ' ' + link.getAttribute('href')).toLowerCase()
            };
        }).filter(function (item) {
            return item.href && item.href !== '#';
        });
    }

    function renderCommandResults(query) {
        if (!commandResults) return;
        var normalizedQuery = String(query || '').trim().toLowerCase();
        commandFiltered = commandItems.filter(function (item) {
            return !normalizedQuery || item.keywords.indexOf(normalizedQuery) !== -1;
        }).slice(0, 12);
        commandSelectedIndex = Math.min(commandSelectedIndex, Math.max(commandFiltered.length - 1, 0));
        commandResults.replaceChildren();

        if (!commandFiltered.length) {
            var empty = document.createElement('div');
            empty.className = 'command-empty';
            empty.textContent = '没有找到相关功能，换个关键词试试';
            commandResults.appendChild(empty);
            return;
        }

        commandFiltered.forEach(function (item, index) {
            var result = document.createElement('a');
            result.className = 'command-result' + (index === commandSelectedIndex ? ' is-selected' : '');
            result.href = item.href;
            if (item.target) result.target = item.target;
            if (item.target === '_blank') result.rel = 'noopener';
            result.setAttribute('data-command-index', String(index));

            var resultIcon = document.createElement('span');
            resultIcon.className = 'command-result-icon';
            resultIcon.innerHTML = '<i class="bi ' + (item.icon || 'bi-arrow-right-circle') + '" aria-hidden="true"></i>';

            var resultCopy = document.createElement('span');
            resultCopy.className = 'command-result-copy';
            var resultTitle = document.createElement('strong');
            resultTitle.textContent = item.label;
            var resultPath = document.createElement('small');
            resultPath.textContent = item.href;
            resultCopy.appendChild(resultTitle);
            resultCopy.appendChild(resultPath);

            var arrow = document.createElement('i');
            arrow.className = 'bi bi-arrow-up-right';
            arrow.setAttribute('aria-hidden', 'true');

            result.appendChild(resultIcon);
            result.appendChild(resultCopy);
            result.appendChild(arrow);
            result.addEventListener('mouseenter', function () {
                commandSelectedIndex = index;
                updateCommandSelection();
            });
            commandResults.appendChild(result);
        });
    }

    function updateCommandSelection() {
        if (!commandResults) return;
        commandResults.querySelectorAll('.command-result').forEach(function (element, index) {
            element.classList.toggle('is-selected', index === commandSelectedIndex);
        });
        var selected = commandResults.querySelector('.command-result.is-selected');
        if (selected) selected.scrollIntoView({ block: 'nearest' });
    }

    function openCommandDialog() {
        if (!commandDialog) return;
        commandSelectedIndex = 0;
        renderCommandResults(commandInput ? commandInput.value : '');
        if (typeof commandDialog.showModal === 'function') {
            if (!commandDialog.open) commandDialog.showModal();
        } else {
            commandDialog.setAttribute('open', '');
        }
        window.setTimeout(function () {
            if (commandInput) {
                commandInput.focus();
                commandInput.select();
            }
        }, 30);
    }

    function closeCommandDialog() {
        if (!commandDialog) return;
        if (typeof commandDialog.close === 'function' && commandDialog.open) commandDialog.close();
        else commandDialog.removeAttribute('open');
    }

    toggleButtons.forEach(function (button) {
        button.addEventListener('click', function () { toggleSidebar(); });
    });
    closeButtons.forEach(function (button) {
        button.addEventListener('click', function () { toggleSidebar(false); });
    });
    if (overlay) overlay.addEventListener('click', function () { toggleSidebar(false); });

    themeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            setTheme(getTheme() === 'dark' ? 'light' : 'dark');
        });
    });

    document.querySelectorAll('.submenu-toggle').forEach(function (element) {
        setSubmenuState(element, element.classList.contains('open'));
        element.addEventListener('click', function () {
            toggleSubmenu(element);
        });
        element.addEventListener('keydown', function (event) {
            if (element.tagName !== 'BUTTON' && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                toggleSubmenu(element);
            }
        });
    });

    if (sidebar) {
        sidebar.querySelectorAll('a[href]').forEach(function (link) {
            link.addEventListener('click', function () {
                if (isMobile()) toggleSidebar(false);
            });
        });
        var activeLink = sidebar.querySelector('a.active');
        if (activeLink) window.setTimeout(function () { activeLink.scrollIntoView({ block: 'nearest' }); }, 0);
    }

    commandItems = collectCommandItems();
    commandOpenButtons.forEach(function (button) { button.addEventListener('click', openCommandDialog); });
    commandCloseButtons.forEach(function (button) { button.addEventListener('click', closeCommandDialog); });
    if (commandInput) {
        commandInput.addEventListener('input', function () {
            commandSelectedIndex = 0;
            renderCommandResults(commandInput.value);
        });
        commandInput.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (commandFiltered.length) commandSelectedIndex = (commandSelectedIndex + 1) % commandFiltered.length;
                updateCommandSelection();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (commandFiltered.length) commandSelectedIndex = (commandSelectedIndex - 1 + commandFiltered.length) % commandFiltered.length;
                updateCommandSelection();
            } else if (event.key === 'Enter' && commandFiltered[commandSelectedIndex]) {
                event.preventDefault();
                var selected = commandFiltered[commandSelectedIndex];
                if (selected.target === '_blank') window.open(selected.href, '_blank', 'noopener');
                else window.location.href = selected.href;
            }
        });
    }
    if (commandDialog) {
        commandDialog.addEventListener('click', function (event) {
            if (event.target === commandDialog) closeCommandDialog();
        });
    }

    document.addEventListener('keydown', function (event) {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            openCommandDialog();
        } else if (event.key === 'Escape' && body.classList.contains('mobile-open')) {
            toggleSidebar(false);
        }
    });

    document.addEventListener('click', function (event) {
        document.querySelectorAll('.admin-user-menu[open]').forEach(function (details) {
            if (!details.contains(event.target)) details.removeAttribute('open');
        });
    });

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(syncSidebarState, 100);
    });

    window.toggleSidebar = toggleSidebar;
    window.toggleSubmenu = toggleSubmenu;
    window.showToast = showToast;
    window.showLoading = showLoading;
    window.hideLoading = hideLoading;
    window.escapeHtml = escapeHtml;
    window.formatFileSize = formatFileSize;
    window.NovaAdmin = {
        getTheme: getTheme,
        setTheme: setTheme,
        showToast: showToast,
        showLoading: showLoading,
        hideLoading: hideLoading
    };

    syncSidebarState();
    updateThemeControls();
}());
