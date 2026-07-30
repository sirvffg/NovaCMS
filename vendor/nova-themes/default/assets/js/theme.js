/**
 * NovaCMS default theme runtime.
 * ES5-style syntax with modern browser APIs and progressive fallbacks.
 */
(function (window, document) {
    'use strict';

    var STORAGE_KEY = 'nova-theme';
    var LEGACY_STORAGE_KEY = 'theme';
    var LIGHT_COLOR = '#faf9f6';
    var DARK_COLOR = '#0b1220';
    var initialized = false;
    var currentUser = null;
    var lastSearchTrigger = null;

    function toArray(collection) {
        return Array.prototype.slice.call(collection || []);
    }

    function select(selector, root) {
        return (root || document).querySelector(selector);
    }

    function selectAll(selector, root) {
        return toArray((root || document).querySelectorAll(selector));
    }

    function matches(element, selector) {
        if (!element || element.nodeType !== 1) {
            return false;
        }
        var matcher = element.matches || element.msMatchesSelector || element.webkitMatchesSelector;
        return matcher ? matcher.call(element, selector) : false;
    }

    function closest(element, selector) {
        var current = element && element.nodeType === 1 ? element : element && element.parentElement;
        while (current) {
            if (matches(current, selector)) {
                return current;
            }
            current = current.parentElement;
        }
        return null;
    }

    function createElement(tagName, className, text) {
        var element = document.createElement(tagName);
        if (className) {
            element.className = className;
        }
        if (text !== undefined && text !== null) {
            element.textContent = String(text);
        }
        return element;
    }

    function clearElement(element) {
        while (element && element.firstChild) {
            element.removeChild(element.firstChild);
        }
        return element;
    }

    function setText(element, value) {
        if (element) {
            element.textContent = value === undefined || value === null ? '' : String(value);
        }
        return element;
    }

    function isReducedMotion() {
        return Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function readStoredTheme() {
        try {
            var stored = window.localStorage.getItem(STORAGE_KEY) || window.localStorage.getItem(LEGACY_STORAGE_KEY);
            return stored === 'dark' || stored === 'light' ? stored : null;
        } catch (error) {
            return null;
        }
    }

    function writeStoredTheme(theme) {
        try {
            window.localStorage.setItem(STORAGE_KEY, theme);
            window.localStorage.setItem(LEGACY_STORAGE_KEY, theme);
        } catch (error) {
            /* Storage may be unavailable in private or locked-down contexts. */
        }
    }

    function preferredTheme() {
        var stored = readStoredTheme();
        if (stored) {
            return stored;
        }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function currentTheme() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    }

    function dispatch(name, detail) {
        var event;
        if (typeof window.CustomEvent === 'function') {
            event = new window.CustomEvent(name, { detail: detail });
        } else {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent(name, false, false, detail);
        }
        window.dispatchEvent(event);
    }

    function updateThemeControls(theme) {
        var dark = theme === 'dark';
        var actionLabel = dark ? '切换为浅色主题' : '切换为深色主题';
        selectAll('[data-theme-toggle]').forEach(function (button) {
            button.setAttribute('aria-pressed', dark ? 'true' : 'false');
            if (button.hasAttribute('aria-label')) {
                button.setAttribute('aria-label', actionLabel);
            }
        });
        selectAll('[data-theme-icon]').forEach(function (icon) {
            icon.className = dark ? 'bi bi-sun' : 'bi bi-moon-stars';
        });
        selectAll('[data-theme-label]').forEach(function (label) {
            label.textContent = actionLabel;
        });

        var meta = select('#nova-theme-color') || select('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute('content', dark ? DARK_COLOR : LIGHT_COLOR);
        }
    }

    function setTheme(theme, persist) {
        var resolved = theme === 'dark' ? 'dark' : 'light';
        var previous = currentTheme();
        document.documentElement.setAttribute('data-bs-theme', resolved);
        document.documentElement.setAttribute('data-theme', resolved);
        document.documentElement.style.colorScheme = resolved;
        updateThemeControls(resolved);
        if (persist !== false) {
            writeStoredTheme(resolved);
        }
        if (previous !== resolved) {
            dispatch('nova:themechange', { theme: resolved });
        }
        return resolved;
    }

    function toggleTheme() {
        return setTheme(currentTheme() === 'dark' ? 'light' : 'dark', true);
    }

    function bindTheme() {
        setTheme(preferredTheme(), false);
        document.addEventListener('click', function (event) {
            var button = closest(event.target, '[data-theme-toggle]');
            if (!button) {
                return;
            }
            event.preventDefault();
            toggleTheme();
        });

        if (window.matchMedia) {
            var media = window.matchMedia('(prefers-color-scheme: dark)');
            var syncSystemTheme = function (event) {
                if (!readStoredTheme()) {
                    setTheme(event.matches ? 'dark' : 'light', false);
                }
            };
            if (typeof media.addEventListener === 'function') {
                media.addEventListener('change', syncSystemTheme);
            } else if (typeof media.addListener === 'function') {
                media.addListener(syncSystemTheme);
            }
        }
    }

    function safeUrl(value, options) {
        var settings = options || {};
        if (typeof value !== 'string') {
            return null;
        }
        var trimmed = value.trim();
        if (!trimmed || /[\u0000-\u001F\u007F\\]/.test(trimmed)) {
            return null;
        }

        var parsed;
        try {
            parsed = new window.URL(trimmed, window.location.href);
        } catch (error) {
            return null;
        }

        var protocols = settings.protocols || ['http:', 'https:'];
        if (settings.allowMailto && protocols.indexOf('mailto:') === -1) {
            protocols = protocols.concat(['mailto:']);
        }
        if (settings.allowTel && protocols.indexOf('tel:') === -1) {
            protocols = protocols.concat(['tel:']);
        }
        if (protocols.indexOf(parsed.protocol) === -1) {
            return null;
        }
        if ((parsed.protocol === 'http:' || parsed.protocol === 'https:') && (parsed.username || parsed.password)) {
            return null;
        }
        if (settings.sameOrigin && parsed.origin !== window.location.origin) {
            return null;
        }
        if (settings.relative && parsed.origin === window.location.origin) {
            return parsed.pathname + parsed.search + parsed.hash;
        }
        return parsed.href;
    }

    function request(url, options) {
        var settings = options || {};
        if (typeof window.fetch !== 'function' || typeof window.Promise !== 'function') {
            return window.Promise.reject(new Error('当前浏览器不支持网络请求'));
        }

        var requestUrl = safeUrl(url, { sameOrigin: settings.allowExternal !== true });
        if (!requestUrl) {
            return window.Promise.reject(new Error('请求地址无效'));
        }

        var method = String(settings.method || 'GET').toUpperCase();
        var headers = new window.Headers(settings.headers || {});
        if (!headers.has('Accept')) {
            headers.set('Accept', 'application/json');
        }

        var body = settings.body;
        var isFormData = typeof window.FormData !== 'undefined' && body instanceof window.FormData;
        var isSearchParams = typeof window.URLSearchParams !== 'undefined' && body instanceof window.URLSearchParams;
        var isBlob = typeof window.Blob !== 'undefined' && body instanceof window.Blob;
        if (body && typeof body === 'object' && !isFormData && !isSearchParams && !isBlob) {
            body = JSON.stringify(body);
            if (!headers.has('Content-Type')) {
                headers.set('Content-Type', 'application/json;charset=UTF-8');
            }
        }

        var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        var timeout = Math.max(0, Number(settings.timeout || 12000));
        var timeoutId = controller && timeout ? window.setTimeout(function () {
            controller.abort();
        }, timeout) : null;

        var fetchOptions = {
            method: method,
            headers: headers,
            credentials: settings.credentials || 'same-origin',
            redirect: settings.redirect || 'follow'
        };
        if (method !== 'GET' && method !== 'HEAD' && body !== undefined) {
            fetchOptions.body = body;
        }
        if (controller) {
            fetchOptions.signal = controller.signal;
        } else if (settings.signal) {
            fetchOptions.signal = settings.signal;
        }

        var clearTimer = function () {
            if (timeoutId) {
                window.clearTimeout(timeoutId);
            }
        };

        return window.fetch(requestUrl, fetchOptions).then(function (response) {
            return response.text().then(function (text) {
                var payload = null;
                if (text) {
                    try {
                        payload = JSON.parse(text);
                    } catch (error) {
                        payload = text;
                    }
                }
                if (!response.ok) {
                    var message = payload && payload.message ? String(payload.message) : '请求失败（' + response.status + '）';
                    var requestError = new Error(message);
                    requestError.status = response.status;
                    requestError.data = payload;
                    throw requestError;
                }
                return payload;
            });
        }).then(function (payload) {
            clearTimer();
            return payload;
        }, function (error) {
            clearTimer();
            if (error && error.name === 'AbortError') {
                var timeoutError = new Error('请求超时，请稍后重试');
                timeoutError.code = 'REQUEST_TIMEOUT';
                throw timeoutError;
            }
            throw error;
        });
    }

    function normalizeDate(value) {
        if (value instanceof Date) {
            return new Date(value.getTime());
        }
        if (typeof value === 'string') {
            var normalized = value.trim();
            if (/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}/.test(normalized)) {
                normalized = normalized.replace(' ', 'T');
            }
            return new Date(normalized);
        }
        return new Date(value);
    }

    function formatDate(value, options) {
        var date = normalizeDate(value);
        if (isNaN(date.getTime())) {
            return '—';
        }
        var settings = options || { year: 'numeric', month: 'short', day: 'numeric' };
        try {
            return new window.Intl.DateTimeFormat(settings.locale || 'zh-CN', settings).format(date);
        } catch (error) {
            return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
        }
    }

    function formatRelativeDate(value, now) {
        var date = normalizeDate(value);
        var reference = now ? normalizeDate(now) : new Date();
        if (isNaN(date.getTime()) || isNaN(reference.getTime())) {
            return '—';
        }
        var seconds = Math.round((date.getTime() - reference.getTime()) / 1000);
        var absolute = Math.abs(seconds);
        var amount = seconds;
        var unit = 'second';
        if (absolute >= 31536000) {
            amount = Math.round(seconds / 31536000);
            unit = 'year';
        } else if (absolute >= 2592000) {
            amount = Math.round(seconds / 2592000);
            unit = 'month';
        } else if (absolute >= 86400) {
            amount = Math.round(seconds / 86400);
            unit = 'day';
        } else if (absolute >= 3600) {
            amount = Math.round(seconds / 3600);
            unit = 'hour';
        } else if (absolute >= 60) {
            amount = Math.round(seconds / 60);
            unit = 'minute';
        }
        try {
            return new window.Intl.RelativeTimeFormat('zh-CN', { numeric: 'auto' }).format(amount, unit);
        } catch (error) {
            return formatDate(date);
        }
    }

    function toast(message, type, options) {
        var settings = options || {};
        var region = select('#nova-toast-region');
        if (!region) {
            region = createElement('div', 'nova-toast-region');
            region.id = 'nova-toast-region';
            region.setAttribute('aria-live', 'polite');
            region.setAttribute('aria-atomic', 'false');
            document.body.appendChild(region);
        }

        var kind = ['success', 'warning', 'error', 'info'].indexOf(type) >= 0 ? type : 'info';
        var titles = { success: '操作成功', warning: '请注意', error: '出现问题', info: '提示' };
        var icons = { success: 'bi-check2', warning: 'bi-exclamation', error: 'bi-x-lg', info: 'bi-info-lg' };
        var item = createElement('div', 'nova-toast nova-toast--' + kind);
        item.setAttribute('role', kind === 'error' ? 'alert' : 'status');

        var icon = createElement('span', 'nova-toast-icon');
        icon.setAttribute('aria-hidden', 'true');
        icon.appendChild(createElement('i', 'bi ' + icons[kind]));

        var copy = createElement('div', 'nova-toast-copy');
        copy.appendChild(createElement('strong', '', settings.title || titles[kind]));
        copy.appendChild(createElement('p', '', message));

        var closeButton = createElement('button', 'nova-toast-close');
        closeButton.type = 'button';
        closeButton.setAttribute('aria-label', '关闭提示');
        closeButton.appendChild(createElement('i', 'bi bi-x', ''));

        item.appendChild(icon);
        item.appendChild(copy);
        item.appendChild(closeButton);
        region.appendChild(item);

        while (region.children.length > 4) {
            region.removeChild(region.firstElementChild);
        }

        var timer = null;
        var remove = function () {
            if (!item.parentNode) {
                return;
            }
            item.classList.remove('is-visible');
            window.setTimeout(function () {
                if (item.parentNode) {
                    item.parentNode.removeChild(item);
                }
            }, isReducedMotion() ? 0 : 220);
        };
        var schedule = function () {
            if (settings.duration === 0) {
                return;
            }
            timer = window.setTimeout(remove, Math.max(1200, Number(settings.duration || 4600)));
        };

        closeButton.addEventListener('click', remove);
        item.addEventListener('mouseenter', function () {
            if (timer) {
                window.clearTimeout(timer);
            }
        });
        item.addEventListener('mouseleave', schedule);
        window.requestAnimationFrame(function () {
            item.classList.add('is-visible');
        });
        schedule();
        return { element: item, close: remove };
    }

    function appendIconText(parent, iconClass, text) {
        var icon = createElement('i', 'bi ' + iconClass);
        icon.setAttribute('aria-hidden', 'true');
        parent.appendChild(icon);
        parent.appendChild(createElement('span', '', text));
        return parent;
    }

    function appendMenuLink(list, href, iconClass, label) {
        var item = createElement('li');
        var link = createElement('a', 'dropdown-item');
        link.href = href;
        appendIconText(link, iconClass, label);
        item.appendChild(link);
        list.appendChild(item);
    }

    function renderGuestMenu(container) {
        clearElement(container);
        currentUser = null;
        var actions = createElement('div', 'nova-auth-actions');
        var login = createElement('a', 'nova-auth-link', '登录');
        login.href = '/vendor/login.php';
        var register = createElement('a', 'nova-auth-link is-primary', '注册');
        register.href = '/vendor/register.php';
        actions.appendChild(login);
        actions.appendChild(register);
        container.appendChild(actions);
    }

    function renderUserMenu(container, user) {
        clearElement(container);
        currentUser = user;

        var dropdown = createElement('div', 'dropdown');
        var trigger = createElement('button', 'nova-user-trigger dropdown-toggle');
        trigger.type = 'button';
        trigger.setAttribute('data-bs-toggle', 'dropdown');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-label', '打开用户菜单');

        var avatar = createElement('span', 'nova-user-avatar');
        avatar.setAttribute('aria-hidden', 'true');
        avatar.appendChild(createElement('i', 'bi bi-person'));
        var name = createElement('span', 'nova-user-name', String(user.username || '用户'));
        trigger.appendChild(avatar);
        trigger.appendChild(name);

        var menu = createElement('ul', 'dropdown-menu dropdown-menu-end');
        if (String(user.role || '').toLowerCase() === 'admin') {
            appendMenuLink(menu, '/admin', 'bi-grid-1x2', '管理后台');
        }
        appendMenuLink(menu, '/profile', 'bi-person', '个人中心');

        var dividerItem = createElement('li');
        dividerItem.appendChild(createElement('hr', 'dropdown-divider'));
        menu.appendChild(dividerItem);

        var logoutItem = createElement('li');
        var form = createElement('form');
        form.method = 'post';
        form.action = '/';
        var action = createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'logout';
        var logout = createElement('button', 'dropdown-item');
        logout.type = 'submit';
        appendIconText(logout, 'bi-box-arrow-right', '退出登录');
        form.appendChild(action);
        form.appendChild(logout);
        logoutItem.appendChild(form);
        menu.appendChild(logoutItem);

        dropdown.appendChild(trigger);
        dropdown.appendChild(menu);
        container.appendChild(dropdown);
    }

    function loadUserMenu(force) {
        var container = select('#user-menu-nav');
        if (!container) {
            return window.Promise.resolve(null);
        }
        if (!force && container.getAttribute('data-auth-loaded') === 'true') {
            return window.Promise.resolve(currentUser);
        }
        container.setAttribute('data-auth-loaded', 'true');
        return request('/nova-json/v1/auth/me', { timeout: 8000 }).then(function (payload) {
            var user = payload && payload.code === 'rest_ok' && payload.data ? payload.data.user : null;
            if (user && typeof user === 'object') {
                renderUserMenu(container, user);
                dispatch('nova:authchange', { user: user });
                return user;
            }
            renderGuestMenu(container);
            return null;
        }, function () {
            renderGuestMenu(container);
            return null;
        });
    }

    function bindSearch() {
        var dialog = select('#nova-search-dialog');
        var input = select('#nova-site-search');
        if (!dialog) {
            return;
        }

        var openDialog = function (trigger) {
            lastSearchTrigger = trigger || document.activeElement;
            if (typeof dialog.showModal === 'function') {
                if (!dialog.open) {
                    dialog.showModal();
                }
            } else {
                dialog.setAttribute('open', '');
                dialog.classList.add('is-open');
                dialog.setAttribute('role', 'dialog');
                dialog.setAttribute('aria-modal', 'true');
            }
            document.body.classList.add('nova-dialog-open');
            window.setTimeout(function () {
                if (input) {
                    input.focus();
                    input.select();
                }
            }, isReducedMotion() ? 0 : 80);
        };

        var closeDialog = function () {
            if (typeof dialog.close === 'function' && dialog.open) {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
                dialog.classList.remove('is-open');
            }
            document.body.classList.remove('nova-dialog-open');
            if (lastSearchTrigger && typeof lastSearchTrigger.focus === 'function') {
                lastSearchTrigger.focus();
            }
        };

        document.addEventListener('click', function (event) {
            var opener = closest(event.target, '[data-search-open]');
            if (opener) {
                event.preventDefault();
                openDialog(opener);
                return;
            }
            if (closest(event.target, '[data-search-close]')) {
                event.preventDefault();
                closeDialog();
            }
        });
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                closeDialog();
            }
        });
        dialog.addEventListener('close', function () {
            document.body.classList.remove('nova-dialog-open');
        });
        document.addEventListener('keydown', function (event) {
            var target = event.target;
            var isEditable = target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
            if ((event.metaKey || event.ctrlKey) && String(event.key).toLowerCase() === 'k' && !isEditable) {
                event.preventDefault();
                openDialog(document.activeElement);
            }
        });

        var form = select('form', dialog);
        if (form && input) {
            form.addEventListener('submit', function (event) {
                if (!input.value.trim()) {
                    event.preventDefault();
                    input.focus();
                }
            });
        }
    }

    function bindScrollShell() {
        var navbar = select('#nova-navbar');
        var backToTop = select('#nova-back-to-top');
        var scheduled = false;
        var update = function () {
            var offset = window.pageYOffset || document.documentElement.scrollTop || 0;
            if (navbar) {
                navbar.classList.toggle('is-scrolled', offset > 16);
            }
            if (backToTop) {
                backToTop.classList.toggle('is-visible', offset > 520);
            }
            scheduled = false;
        };
        var scheduleUpdate = function () {
            if (!scheduled) {
                scheduled = true;
                window.requestAnimationFrame(update);
            }
        };
        window.addEventListener('scroll', scheduleUpdate, { passive: true });
        update();
        if (backToTop) {
            backToTop.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: isReducedMotion() ? 'auto' : 'smooth' });
            });
        }
    }

    function bindMobileNavigation() {
        var collapse = select('#nova-primary-nav');
        if (!collapse) {
            return;
        }
        var menuToggle = select('[data-bs-target="#nova-primary-nav"]');
        var syncMenuToggle = function (expanded) {
            if (!menuToggle) return;
            menuToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            menuToggle.setAttribute('aria-label', expanded ? '收起导航' : '展开导航');
        };
        collapse.addEventListener('show.bs.collapse', function () { syncMenuToggle(true); });
        collapse.addEventListener('hide.bs.collapse', function () { syncMenuToggle(false); });
        syncMenuToggle(collapse.classList.contains('show'));
        collapse.addEventListener('click', function (event) {
            var link = closest(event.target, 'a[href]');
            if (!link || window.innerWidth >= 992) {
                return;
            }
            if (window.bootstrap && window.bootstrap.Collapse) {
                window.bootstrap.Collapse.getOrCreateInstance(collapse, { toggle: false }).hide();
            } else {
                collapse.classList.remove('show');
                syncMenuToggle(false);
            }
        });
    }

    function copyText(value) {
        if (window.navigator.clipboard && window.isSecureContext) {
            return window.navigator.clipboard.writeText(value);
        }
        return new window.Promise(function (resolve, reject) {
            var field = createElement('textarea');
            field.value = value;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            try {
                if (document.execCommand('copy')) {
                    resolve();
                } else {
                    reject(new Error('copy failed'));
                }
            } catch (error) {
                reject(error);
            }
            document.body.removeChild(field);
        });
    }

    function enhanceCodeBlocks(root) {
        selectAll('.code-block-wrapper', root).forEach(function (wrapper) {
            if (wrapper.getAttribute('data-nova-copy') === 'true') {
                return;
            }
            var pre = select('pre', wrapper);
            if (!pre) {
                return;
            }
            wrapper.setAttribute('data-nova-copy', 'true');
            var header = select('.code-block-header', wrapper);
            if (!header) {
                header = createElement('div', 'code-block-header');
                wrapper.insertBefore(header, pre);
            }
            var button = select('.code-copy-btn', header);
            if (!button) {
                button = createElement('button', 'code-copy-btn');
                button.type = 'button';
                button.setAttribute('aria-label', '复制代码');
                appendIconText(button, 'bi-clipboard', '复制');
                header.appendChild(button);
            }
            button.addEventListener('click', function () {
                var code = select('code', pre) || pre;
                var label = select('span', button);
                copyText(code.textContent || '').then(function () {
                    button.classList.add('is-copied');
                    setText(label, '已复制');
                    window.setTimeout(function () {
                        button.classList.remove('is-copied');
                        setText(label, '复制');
                    }, 1800);
                }, function () {
                    toast('请手动选择并复制这段代码。', 'error', { title: '复制失败' });
                });
            });
        });
    }

    function lazyLoadImages(root) {
        var images = selectAll('img[data-src]', root).filter(function (image) {
            return image.getAttribute('data-nova-lazy') !== 'true';
        });
        if (!images.length) {
            return;
        }
        var load = function (image) {
            var source = safeUrl(image.getAttribute('data-src'));
            if (source) {
                image.src = source;
            }
            image.removeAttribute('data-src');
            image.setAttribute('data-nova-lazy', 'true');
        };
        if (typeof window.IntersectionObserver !== 'function') {
            images.forEach(load);
            return;
        }
        var observer = new window.IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    load(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '240px 0px' });
        images.forEach(function (image) {
            observer.observe(image);
        });
    }

    function secureLinks(root) {
        selectAll('a[href]', root).forEach(function (link) {
            var raw = link.getAttribute('href');
            var href = safeUrl(raw, { allowMailto: true, allowTel: true });
            if (!href) {
                link.removeAttribute('href');
                link.setAttribute('aria-disabled', 'true');
                return;
            }
            if (link.target === '_blank') {
                var rel = (link.getAttribute('rel') || '').split(/\s+/).filter(Boolean);
                ['noopener', 'noreferrer'].forEach(function (token) {
                    if (rel.indexOf(token) === -1) {
                        rel.push(token);
                    }
                });
                link.setAttribute('rel', rel.join(' '));
            }
        });
    }

    function enhanceDates(root) {
        selectAll('time[data-format-date]', root).forEach(function (time) {
            if (time.getAttribute('data-nova-date') === 'true') {
                return;
            }
            var value = time.getAttribute('datetime') || time.textContent;
            time.textContent = time.hasAttribute('data-relative-date') ? formatRelativeDate(value) : formatDate(value);
            time.setAttribute('data-nova-date', 'true');
        });
    }

    function ensureMainTarget() {
        var target = document.getElementById('main-content') || select('main') || select('[role="main"]');
        if (!target) {
            var navbar = select('#nova-navbar');
            target = navbar ? navbar.nextElementSibling : null;
        }
        if (!target) {
            return;
        }
        if (!target.id) {
            target.id = 'main-content';
        }
        if (!target.hasAttribute('tabindex')) {
            target.setAttribute('tabindex', '-1');
        }
    }

    function init(scope) {
        var root = scope && scope.querySelectorAll ? scope : document;
        ensureMainTarget();
        enhanceCodeBlocks(root);
        lazyLoadImages(root);
        secureLinks(root);
        enhanceDates(root);

        if (initialized) {
            return NovaTheme;
        }
        initialized = true;
        bindTheme();
        bindSearch();
        bindScrollShell();
        bindMobileNavigation();
        loadUserMenu(false);
        document.documentElement.classList.add('nova-ready');
        dispatch('nova:ready', { theme: currentTheme() });
        return NovaTheme;
    }

    function debounce(callback, delay) {
        var timer = null;
        return function () {
            var context = this;
            var args = arguments;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                callback.apply(context, args);
            }, Number(delay || 180));
        };
    }

    var NovaTheme = {
        version: '2.0.0',
        init: init,
        getTheme: currentTheme,
        setTheme: setTheme,
        toggleTheme: toggleTheme,
        toast: toast,
        request: request,
        requestJSON: request,
        safeUrl: safeUrl,
        formatDate: formatDate,
        formatRelativeDate: formatRelativeDate,
        createElement: createElement,
        setText: setText,
        clearElement: clearElement,
        debounce: debounce,
        isReducedMotion: isReducedMotion,
        refreshUser: function () { return loadUserMenu(true); },
        getCurrentUser: function () { return currentUser; }
    };

    window.NovaTheme = NovaTheme;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        }, { once: true });
    } else {
        init(document);
    }
}(window, document));
