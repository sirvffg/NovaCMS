/** NovaCMS default theme — community, gallery and profile pages. */
(function () {
    'use strict';

    var root = document.querySelector('[data-community-page]');
    if (!root) return;

    function qs(selector, scope) { return (scope || document).querySelector(selector); }
    function qsa(selector, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(selector)); }
    function node(tag, className, value) {
        var item = document.createElement(tag);
        if (className) item.className = className;
        if (value !== undefined && value !== null) item.textContent = String(value);
        return item;
    }
    function icon(name) {
        var item = node('i', 'bi ' + name);
        item.setAttribute('aria-hidden', 'true');
        return item;
    }
    function append(parent) {
        for (var i = 1; i < arguments.length; i += 1) if (arguments[i]) parent.appendChild(arguments[i]);
        return parent;
    }
    function clean(value, fallback) {
        var output = value === undefined || value === null ? '' : String(value).trim();
        return output || (fallback || '');
    }
    function safeUrl(value, mail) {
        var raw = clean(value);
        if (!raw || /[\u0000-\u001f\u007f\\]/.test(raw)) return '';
        try {
            var parsed = new URL(raw, window.location.origin);
            if ((mail ? ['http:', 'https:', 'mailto:'] : ['http:', 'https:']).indexOf(parsed.protocol) === -1) return '';
            if ((parsed.protocol === 'http:' || parsed.protocol === 'https:') && (parsed.username || parsed.password)) return '';
            return parsed.href;
        } catch (error) { return ''; }
    }
    function formatDate(value, withTime) {
        if (!value) return '时间未记录';
        var parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return clean(value, '时间未记录');
        try {
            return new Intl.DateTimeFormat('zh-CN', withTime ? {
                year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false
            } : { year: 'numeric', month: 'short', day: 'numeric' }).format(parsed);
        } catch (error) { return parsed.toLocaleDateString(); }
    }
    function number(value) {
        try { return new Intl.NumberFormat('zh-CN').format(Number(value) || 0); }
        catch (error) { return String(Number(value) || 0); }
    }
    function error(message, status, payload) {
        var item = new Error(message || '请求没有完成');
        item.status = status || 0;
        item.payload = payload || null;
        return item;
    }
    function effectiveResponseStatus(response, payload) {
        var headerStatus = Number.parseInt(response.headers.get('X-Response-Status') || '', 10);
        var payloadStatus = payload && payload.data && Number.parseInt(payload.data.status, 10);
        if (Number.isFinite(headerStatus) && headerStatus > 0) return headerStatus;
        if (Number.isFinite(payloadStatus) && payloadStatus > 0) return payloadStatus;
        return response.status;
    }
    async function requestJson(url, options) {
        var response = await fetch(url, Object.assign({ credentials: 'same-origin', headers: { 'Accept': 'application/json' } }, options || {}));
        var payload;
        try { payload = await response.json(); }
        catch (parseError) { throw error('服务器返回了无法识别的内容', response.status); }
        var status = effectiveResponseStatus(response, payload);
        var applicationError = !payload || (payload.code && payload.code !== 'rest_ok');
        if (!response.ok || status >= 400 || applicationError) {
            throw error(payload && payload.message ? payload.message : '请求没有完成', status >= 400 ? status : 400, payload);
        }
        return payload.data || {};
    }
    function jsonOptions(method, body) {
        return { method: method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) };
    }
    function clear(target) { if (target) target.replaceChildren(); }
    function busy(target, state) { if (target) target.setAttribute('aria-busy', state ? 'true' : 'false'); }
    function emptyState(title, description, actionText, actionHref) {
        var box = node('div', 'community-empty');
        var mark = node('span'); mark.appendChild(icon('bi-inbox'));
        append(box, mark, node('h3', '', title), node('p', '', description));
        if (actionText && actionHref) {
            var action = node('a', 'site-button site-button-quiet', actionText); action.href = actionHref; box.appendChild(action);
        }
        return box;
    }
    function errorState(message, retry) {
        var box = emptyState('内容暂时没有到达', message || '请稍后再试。');
        var button = node('button', 'site-button site-button-quiet', '重新加载');
        button.type = 'button'; button.addEventListener('click', retry); box.appendChild(button);
        return box;
    }
    function initial(value) {
        var name = clean(value, 'N');
        return Array.from(name)[0].toUpperCase();
    }
    function imageWithFallback(source, alt, fallbackIcon) {
        var wrapper = node('span', 'media-fallback');
        var url = safeUrl(source);
        if (url) {
            var image = node('img'); image.src = url; image.alt = alt || ''; image.loading = 'lazy'; image.decoding = 'async';
            image.addEventListener('error', function () { image.remove(); if (!wrapper.firstChild) wrapper.appendChild(icon(fallbackIcon || 'bi-image')); });
            wrapper.appendChild(image);
        } else {
            wrapper.appendChild(icon(fallbackIcon || 'bi-image'));
        }
        return wrapper;
    }

    /* Friend links */
    function renderLinkCard(item) {
        var href = safeUrl(item.url);
        var card = node(href ? 'a' : 'article', 'friend-card');
        if (href) { card.href = href; card.target = '_blank'; card.rel = 'noopener noreferrer'; }
        var logo = imageWithFallback(item.logo, '', 'bi-globe2'); logo.classList.add('friend-logo');
        var copy = node('div', 'friend-copy');
        append(copy, node('strong', '', clean(item.name, '未命名站点')), node('p', '', clean(item.description, '一位仍在认真记录的互联网邻居。')));
        var host = '';
        if (href) { try { host = new URL(href).hostname; } catch (error) { host = ''; } }
        var foot = node('div', 'friend-meta');
        append(foot, node('span', '', host || '访问站点'), icon('bi-arrow-up-right'));
        append(card, logo, copy, foot);
        return card;
    }

    async function loadLinks() {
        var container = qs('[data-link-groups]', root);
        var count = qs('[data-link-count]', root);
        if (!container) return;
        busy(container, true);
        try {
            var data = await requestJson('/nova-json/v1/links');
            var grouped = data.grouped && typeof data.grouped === 'object' ? data.grouped : {};
            clear(container);
            if (count) count.textContent = '共 ' + number(data.total) + ' 个站点';
            var names = Object.keys(grouped);
            if (!names.length) {
                container.appendChild(emptyState('还没有友情链接', '第一位邻居会从这里出现。', '申请成为邻居', '/vendor/appeal.php'));
            } else {
                names.forEach(function (name) {
                    var section = node('section', 'link-group');
                    var heading = node('header');
                    append(heading, node('h3', '', name), node('span', '', number(grouped[name].length) + ' 个站点'));
                    var grid = node('div', 'friend-grid');
                    grouped[name].forEach(function (item) { grid.appendChild(renderLinkCard(item)); });
                    append(section, heading, grid); container.appendChild(section);
                });
            }
        } catch (loadError) {
            clear(container); container.appendChild(errorState(loadError.message, loadLinks));
            if (count) count.textContent = '友链加载未完成';
        } finally { busy(container, false); }
    }

    /* Gallery */
    var lightboxPhotos = [];
    var lightboxIndex = 0;
    var galleryPage = 1;
    var galleryTotalPages = 1;

    function openLightbox(index) {
        var dialog = qs('[data-gallery-lightbox]', root);
        if (!dialog || !lightboxPhotos.length) return;
        lightboxIndex = (index + lightboxPhotos.length) % lightboxPhotos.length;
        var photo = lightboxPhotos[lightboxIndex];
        var image = qs('figure img', dialog);
        var url = safeUrl(photo.url);
        if (!url) return;
        image.src = url; image.alt = clean(photo.title, '照片预览');
        qs('[data-lightbox-title]', dialog).textContent = clean(photo.title, '未命名照片');
        qs('[data-lightbox-description]', dialog).textContent = clean(photo.description, formatDate(photo.created_at));
        if (typeof dialog.showModal === 'function' && !dialog.open) dialog.showModal();
        else dialog.setAttribute('open', '');
    }

    function bindLightbox() {
        var dialog = qs('[data-gallery-lightbox]', root);
        if (!dialog) return;
        var previous = qs('[data-lightbox-prev]', dialog);
        var next = qs('[data-lightbox-next]', dialog);
        previous.addEventListener('click', function () { openLightbox(lightboxIndex - 1); });
        next.addEventListener('click', function () { openLightbox(lightboxIndex + 1); });
        dialog.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') openLightbox(lightboxIndex - 1);
            if (event.key === 'ArrowRight') openLightbox(lightboxIndex + 1);
        });
        dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
    }

    function renderAlbum(album) {
        var link = node('a', 'album-card'); link.href = '/gallery?album=' + encodeURIComponent(Number(album.id) || 0);
        var media = imageWithFallback(album.cover_image, '', 'bi-images'); media.classList.add('album-cover');
        var overlay = node('span', 'album-overlay');
        var copy = node('span', 'album-copy');
        append(copy, node('strong', '', clean(album.name, '未命名相册')), node('small', '', number(album.photo_count) + ' 张照片'));
        append(overlay, copy, icon('bi-arrow-up-right'));
        append(link, media, overlay); return link;
    }

    function renderPhoto(photo, index) {
        var button = node('button', 'photo-card'); button.type = 'button'; button.setAttribute('aria-label', '预览' + clean(photo.title, '照片'));
        var media = imageWithFallback(photo.url, clean(photo.title, ''), 'bi-image'); media.classList.add('photo-media');
        var caption = node('span', 'photo-caption');
        append(caption, node('strong', '', clean(photo.title, '未命名照片')), node('small', '', clean(photo.album_name, formatDate(photo.created_at))));
        append(button, media, caption); button.addEventListener('click', function () { openLightbox(index); }); return button;
    }

    async function loadGallery(appendItems) {
        var container = qs('[data-gallery-grid]', root);
        var title = qs('[data-gallery-title]', root);
        var count = qs('[data-gallery-count]', root);
        var more = qs('[data-gallery-more]', root);
        var albumId = Math.max(0, Number(root.dataset.albumId) || 0);
        if (!container) return;
        var requestedPage = appendItems ? Math.min(galleryTotalPages, galleryPage + 1) : 1;
        busy(container, true);
        if (more) more.disabled = true;
        try {
            if (albumId > 0) {
                var detail = await requestJson('/nova-json/v1/statuses/gallery/albums/' + encodeURIComponent(albumId) + '?per_page=24&page=' + requestedPage);
                var photos = Array.isArray(detail.photos) ? detail.photos : [];
                var perPage = Math.max(1, Number(detail.per_page) || 24);
                galleryTotalPages = Math.max(1, Math.ceil((Number(detail.total) || 0) / perPage));
                if (!appendItems) {
                    clear(container);
                    lightboxPhotos = [];
                }
                container.classList.add('is-photos');
                if (title) title.textContent = clean(detail.album && detail.album.name, '相册');
                if (count) count.textContent = number(detail.total) + ' 张照片';
                if (!photos.length && requestedPage === 1) {
                    container.appendChild(emptyState('这个相册还是空的', '照片上传后会出现在这里。', '返回全部相册', '/gallery'));
                } else {
                    var firstIndex = lightboxPhotos.length;
                    lightboxPhotos = lightboxPhotos.concat(photos);
                    photos.forEach(function (photo, index) { container.appendChild(renderPhoto(photo, firstIndex + index)); });
                }
                galleryPage = requestedPage;
                if (more) more.hidden = galleryPage >= galleryTotalPages;
            } else {
                var data = await requestJson('/nova-json/v1/statuses/gallery/albums');
                var albums = Array.isArray(data.items) ? data.items : [];
                clear(container); container.classList.remove('is-photos');
                galleryPage = 1;
                galleryTotalPages = 1;
                lightboxPhotos = [];
                if (more) more.hidden = true;
                if (count) count.textContent = number(data.total) + ' 个相册';
                if (!albums.length) container.appendChild(emptyState('还没有相册', '第一组照片创建后，会在这里出现。'));
                else albums.forEach(function (album) { container.appendChild(renderAlbum(album)); });
            }
        } catch (loadError) {
            if (!appendItems) {
                clear(container);
                container.appendChild(errorState(loadError.message, function () { loadGallery(false); }));
                if (count) count.textContent = '相册加载未完成';
            } else if (window.NovaTheme && window.NovaTheme.toast) {
                window.NovaTheme.toast('下一页照片加载失败，请稍后重试。', 'error');
            }
        } finally {
            busy(container, false);
            if (more) more.disabled = false;
        }
    }

    function initGallery() {
        var more = qs('[data-gallery-more]', root);
        bindLightbox();
        if (more) {
            more.addEventListener('click', function () {
                if (galleryPage < galleryTotalPages) loadGallery(true);
            });
        }
        loadGallery(false);
    }

    /* Moments */
    var momentPage = 1;
    var momentTotalPages = 1;

    function renderMoment(item, index) {
        var article = node('article', 'moment-card');
        var rail = node('div', 'moment-rail');
        append(rail, node('span', 'moment-dot'), node('span', 'moment-line'));
        var body = node('div', 'moment-body');
        var header = node('header');
        append(header, node('time', '', formatDate(item.created_at, true)), node('span', '', '#' + String(item.id || index + 1).padStart(2, '0')));
        body.appendChild(header);
        body.appendChild(node('p', '', clean(item.content, '这一刻没有留下文字。')));
        var imageUrl = safeUrl(item.image_path);
        if (imageUrl) {
            var figure = node('figure'); var image = node('img'); image.src = imageUrl; image.alt = ''; image.loading = 'lazy'; image.decoding = 'async'; figure.appendChild(image); body.appendChild(figure);
        }
        append(article, rail, body); return article;
    }

    async function loadMoments(appendItems) {
        var container = qs('[data-moment-list]', root);
        var count = qs('[data-moment-count]', root);
        var more = qs('[data-moment-more]', root);
        if (!container) return;
        var requestedPage = appendItems ? Math.min(momentTotalPages, momentPage + 1) : 1;
        busy(container, true); if (more) more.disabled = true;
        try {
            var data = await requestJson('/nova-json/v1/statuses/instant?page=' + requestedPage + '&per_page=12');
            var items = Array.isArray(data.items) ? data.items : [];
            momentTotalPages = Math.max(1, Math.ceil((Number(data.total) || 0) / Math.max(1, Number(data.per_page) || 12)));
            if (!appendItems) clear(container);
            if (count) count.textContent = '共 ' + number(data.total) + ' 条动态';
            if (!items.length && requestedPage === 1) container.appendChild(emptyState('还没有片刻', '下一次随手记录会出现在这里。'));
            else items.forEach(function (item, index) { container.appendChild(renderMoment(item, index)); });
            momentPage = requestedPage;
            if (more) { more.hidden = momentPage >= momentTotalPages; more.disabled = false; }
        } catch (loadError) {
            if (!appendItems) { clear(container); container.appendChild(errorState(loadError.message, function () { loadMoments(false); })); }
            if (count) count.textContent = '动态加载未完成';
            if (more) more.disabled = false;
        } finally { busy(container, false); }
    }

    function initMoments() {
        var more = qs('[data-moment-more]', root);
        if (more) more.addEventListener('click', function () { if (momentPage < momentTotalPages) loadMoments(true); });
        loadMoments(false);
    }

    /* Guestbook */
    var guestbookPage = 1;

    function renderReply(reply, adminReply) {
        var item = node('div', adminReply ? 'message-reply is-admin' : 'message-reply');
        var title = node('strong', '', adminReply ? '站点回复' : clean(reply.nickname, '访客回复'));
        var copy = node('p', '', clean(adminReply ? reply : reply.content));
        append(item, title, copy);
        if (!adminReply && reply.created_at) item.appendChild(node('time', '', formatDate(reply.created_at, true)));
        return item;
    }

    function renderMessage(item) {
        var article = node('article', 'message-card');
        var header = node('header');
        var avatar = node('span', 'message-avatar', initial(item.nickname));
        var identity = node('div');
        var name = node(item.website && safeUrl(item.website) ? 'a' : 'strong', '', clean(item.nickname, '访客'));
        if (name.tagName === 'A') { name.href = safeUrl(item.website); name.target = '_blank'; name.rel = 'noopener noreferrer'; }
        append(identity, name, node('time', '', formatDate(item.created_at, true)));
        append(header, avatar, identity, node('span', 'message-number', '#' + number(item.id)));
        var content = node('p', 'message-content', clean(item.content));
        append(article, header, content);
        if (clean(item.reply_content)) article.appendChild(renderReply(item.reply_content, true));
        (Array.isArray(item.replies) ? item.replies : []).forEach(function (reply) { article.appendChild(renderReply(reply, false)); });
        return article;
    }

    function renderGuestbookPagination(total, perPage) {
        var container = qs('[data-guestbook-pagination]', root);
        if (!container) return;
        clear(container);
        var totalPages = Math.max(1, Math.ceil(total / Math.max(1, perPage)));
        if (totalPages <= 1) return;
        var previous = node('button', '', '上一页'); previous.type = 'button'; previous.disabled = guestbookPage <= 1;
        previous.addEventListener('click', function () { guestbookPage -= 1; loadGuestbook(); });
        var label = node('span', '', guestbookPage + ' / ' + totalPages);
        var next = node('button', '', '下一页'); next.type = 'button'; next.disabled = guestbookPage >= totalPages;
        next.addEventListener('click', function () { guestbookPage += 1; loadGuestbook(); });
        append(container, previous, label, next);
    }

    async function loadGuestbook() {
        var container = qs('[data-guestbook-list]', root);
        var count = qs('[data-guestbook-count]', root);
        if (!container) return;
        busy(container, true);
        try {
            var data = await requestJson('/nova-json/v1/statuses/guestbook?page=' + guestbookPage + '&per_page=12');
            var items = Array.isArray(data.items) ? data.items : [];
            clear(container);
            if (count) count.textContent = '共 ' + number(data.total) + ' 条留言';
            if (!items.length) container.appendChild(emptyState('留言簿还是空的', '在左侧写下第一句话吧。'));
            else items.forEach(function (item) { container.appendChild(renderMessage(item)); });
            renderGuestbookPagination(Number(data.total) || 0, Number(data.per_page) || 12);
        } catch (loadError) {
            clear(container); container.appendChild(errorState(loadError.message, loadGuestbook));
            if (count) count.textContent = '留言加载未完成';
        } finally { busy(container, false); }
    }

    function initGuestbookForm() {
        var form = qs('[data-guestbook-form]', root);
        if (!form) return;
        var content = form.elements.content;
        var length = qs('[data-guestbook-length]', form);
        var feedback = qs('[data-guestbook-feedback]', form);
        if (content && length) content.addEventListener('input', function () { length.textContent = String(content.value.length); });
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var values = {
                nickname: clean(form.elements.nickname.value),
                email: clean(form.elements.email.value),
                website: clean(form.elements.website.value),
                content: clean(form.elements.content.value),
                company: clean(form.elements.company && form.elements.company.value)
            };
            if (!values.nickname || !values.content) {
                feedback.textContent = '请填写昵称和留言内容。'; feedback.className = 'form-feedback is-error'; return;
            }
            if (values.email && !/^\S+@\S+\.\S+$/.test(values.email)) {
                feedback.textContent = '邮箱格式需要再检查一下。'; feedback.className = 'form-feedback is-error'; return;
            }
            if (values.website && !safeUrl(values.website)) {
                feedback.textContent = '个人网站需使用完整的 http(s) 地址。'; feedback.className = 'form-feedback is-error'; return;
            }
            var button = qs('button[type="submit"]', form); button.disabled = true;
            feedback.textContent = '正在提交…'; feedback.className = 'form-feedback';
            try {
                await requestJson('/nova-json/v1/statuses/guestbook', jsonOptions('POST', values));
                form.reset(); if (length) length.textContent = '0'; guestbookPage = 1;
                feedback.textContent = '留言已经写进留言簿。'; feedback.className = 'form-feedback is-success';
                loadGuestbook();
            } catch (submitError) {
                feedback.textContent = submitError.message; feedback.className = 'form-feedback is-error';
            } finally { button.disabled = false; }
        });
    }

    /* Profile */
    function detailRow(iconName, label, value) {
        var row = node('div', 'profile-detail-row'); var mark = node('span'); mark.appendChild(icon(iconName));
        var copy = node('div'); append(copy, node('small', '', label), node('strong', '', clean(value, '未设置')));
        append(row, mark, copy); return row;
    }

    function renderSignedOutProfile(container) {
        clear(container);
        var panel = node('section', 'profile-signed-out'); var mark = node('span'); mark.appendChild(icon('bi-person-lock'));
        append(panel, mark, node('h1', '', '登录后查看个人中心'), node('p', '', '在这里查看账户资料、最近登录与设备状态。'));
        var actions = node('div', 'profile-actions');
        var login = node('a', 'site-button site-button-primary', '登录账户'); login.href = '/vendor/login.php?redirect_url=%2Fprofile';
        var register = node('a', 'site-button site-button-quiet', '创建账户'); register.href = '/vendor/register.php?redirect_url=%2Fprofile';
        append(actions, login, register); panel.appendChild(actions); container.appendChild(panel);
    }

    function renderProfile(container, user, devices) {
        clear(container);
        var layout = node('div', 'profile-layout');
        var summary = node('aside', 'profile-summary');
        var avatar = node('span', 'profile-avatar', initial(user.username));
        var role = node('span', 'profile-role', user.role === 'admin' ? '管理员' : '成员');
        append(summary, avatar, role, node('h1', '', clean(user.username, 'Nova 用户')), node('p', '', clean(user.email, '尚未绑定邮箱')));
        var actions = node('div', 'profile-actions');
        var security = node('a', 'site-button site-button-quiet', '账户安全'); security.href = '/vendor/forgot_password.php';
        var home = node('a', 'site-button site-button-quiet', '返回首页'); home.href = '/';
        append(actions, security, home);
        if (user.role === 'admin') { var admin = node('a', 'site-button site-button-primary', '管理后台'); admin.href = '/admin/index.php'; actions.prepend(admin); }
        summary.appendChild(actions);

        var content = node('div', 'profile-content');
        var overview = node('section', 'profile-panel');
        var heading = node('header'); append(heading, node('div', '', ''), node('span', 'profile-status', '账户正常'));
        var headingCopy = heading.firstChild; append(headingCopy, node('small', '', 'Account overview'), node('h2', '', '账户概览'));
        var details = node('div', 'profile-detail-grid');
        append(details,
            detailRow('bi-envelope', '邮箱地址', user.email),
            detailRow('bi-calendar-check', '加入时间', formatDate(user.created_at)),
            detailRow('bi-clock-history', '最近登录', formatDate(user.last_login, true)),
            detailRow('bi-shield-check', '账户角色', user.role === 'admin' ? '管理员' : '普通成员')
        );
        append(overview, heading, details);

        var devicePanel = node('section', 'profile-panel');
        var deviceHeader = node('header'); var deviceCopy = node('div'); append(deviceCopy, node('small', '', 'Active sessions'), node('h2', '', '登录设备'));
        append(deviceHeader, deviceCopy, node('span', '', number(devices.length) + ' 台'));
        var list = node('div', 'device-list');
        if (!devices.length) list.appendChild(node('p', 'profile-muted', '没有可显示的活跃设备。'));
        devices.forEach(function (device) {
            var row = node('article', 'device-row'); var mark = node('span', 'device-icon'); mark.appendChild(icon(/mobile|iphone|android/i.test(clean(device.device_name)) ? 'bi-phone' : 'bi-laptop'));
            var copy = node('div');
            var title = node('div'); append(title, node('strong', '', clean(device.device_name, '未知设备')), device.is_current ? node('span', 'current-device', '当前设备') : null);
            append(copy, title, node('p', '', [clean(device.ip_address, '未知 IP'), '活跃于 ' + formatDate(device.last_active_at, true)].join(' · ')));
            append(row, mark, copy); list.appendChild(row);
        });
        append(devicePanel, deviceHeader, list);

        if (Array.isArray(user.recent_ips) && user.recent_ips.length) {
            var securityPanel = node('section', 'profile-panel profile-security-note'); var securityMark = node('span'); securityMark.appendChild(icon('bi-shield-lock'));
            var securityCopy = node('div'); append(securityCopy, node('strong', '', '最近登录位置'), node('p', '', user.recent_ips.join(' · ')));
            append(securityPanel, securityMark, securityCopy); content.appendChild(securityPanel);
        }
        content.prepend(overview); content.appendChild(devicePanel);
        append(layout, summary, content); container.appendChild(layout);
    }

    async function loadProfile() {
        var container = qs('[data-profile-root]', root);
        if (!container) return;
        busy(container, true);
        try {
            var userData = await requestJson('/nova-json/v1/auth/me');
            var deviceData;
            try { deviceData = await requestJson('/nova-json/v1/auth/devices'); }
            catch (deviceError) { deviceData = { items: [] }; }
            renderProfile(container, userData.user || {}, Array.isArray(deviceData.items) ? deviceData.items : []);
        } catch (loadError) {
            if (loadError.status === 401) renderSignedOutProfile(container);
            else { clear(container); container.appendChild(errorState(loadError.message, loadProfile)); }
        } finally { busy(container, false); }
    }

    function init() {
        var page = root.dataset.communityPage;
        if (page === 'links') loadLinks();
        else if (page === 'gallery') initGallery();
        else if (page === 'moments') initMoments();
        else if (page === 'guestbook') { initGuestbookForm(); loadGuestbook(); }
        else if (page === 'profile') loadProfile();
    }

    init();
}());
