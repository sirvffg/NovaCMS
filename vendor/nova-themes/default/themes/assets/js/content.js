/**
 * NovaCMS default theme — content experiences.
 * API data is always written through DOM properties rather than interpolated HTML.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-content-page]');
    if (!root) return;

    function qs(selector, scope) {
        return (scope || document).querySelector(selector);
    }

    function qsa(selector, scope) {
        return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
    }

    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function icon(name) {
        var node = element('i', 'bi ' + name);
        node.setAttribute('aria-hidden', 'true');
        return node;
    }

    function append(parent) {
        for (var index = 1; index < arguments.length; index += 1) {
            var child = arguments[index];
            if (child) parent.appendChild(child);
        }
        return parent;
    }

    function text(value, fallback) {
        var normalized = value === undefined || value === null ? '' : String(value).trim();
        return normalized || (fallback || '');
    }

    function safeUrl(value, options) {
        var raw = text(value);
        if (!raw || /[\u0000-\u001f\u007f\\]/.test(raw)) return '';
        try {
            var parsed = new URL(raw, window.location.origin);
            var protocols = options && options.mail ? ['http:', 'https:', 'mailto:'] : ['http:', 'https:'];
            if (protocols.indexOf(parsed.protocol) === -1) return '';
            if ((parsed.protocol === 'http:' || parsed.protocol === 'https:') && (parsed.username || parsed.password)) return '';
            if (options && options.sameOrigin && parsed.origin !== window.location.origin) return '';
            return parsed.href;
        } catch (error) {
            return '';
        }
    }

    function formatDate(value, includeYear) {
        if (!value) return '日期未定';
        var parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return text(value, '日期未定');
        try {
            return new Intl.DateTimeFormat('zh-CN', {
                year: includeYear === false ? undefined : 'numeric',
                month: 'short',
                day: 'numeric'
            }).format(parsed);
        } catch (error) {
            return parsed.toLocaleDateString();
        }
    }

    function number(value) {
        try {
            return new Intl.NumberFormat('zh-CN').format(Number(value) || 0);
        } catch (error) {
            return String(Number(value) || 0);
        }
    }

    function apiError(message, status, payload) {
        var error = new Error(message || '请求没有完成');
        error.status = status || 0;
        error.payload = payload || null;
        return error;
    }

    function effectiveResponseStatus(response, payload) {
        var headerStatus = Number.parseInt(response.headers.get('X-Response-Status') || '', 10);
        var payloadStatus = payload && payload.data && Number.parseInt(payload.data.status, 10);
        if (Number.isFinite(headerStatus) && headerStatus > 0) return headerStatus;
        if (Number.isFinite(payloadStatus) && payloadStatus > 0) return payloadStatus;
        return response.status;
    }

    async function requestJson(url, options) {
        var response = await fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }, options || {}));
        var payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw apiError('服务器返回了无法识别的内容', response.status);
        }
        var status = effectiveResponseStatus(response, payload);
        var applicationError = !payload || (payload.code && payload.code !== 'rest_ok');
        if (!response.ok || status >= 400 || applicationError) {
            throw apiError(payload && payload.message ? payload.message : '请求没有完成', status >= 400 ? status : 400, payload);
        }
        return payload.data || {};
    }

    function jsonOptions(method, body) {
        return {
            method: method,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {})
        };
    }

    function setBusy(container, busy) {
        if (container) container.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    function clear(container) {
        if (container) container.replaceChildren();
    }

    function emptyState(title, description, actionText, actionHref) {
        var wrapper = element('div', 'site-empty-state');
        var symbol = element('span');
        symbol.appendChild(icon('bi-inbox'));
        append(wrapper, symbol, element('h3', '', title), element('p', '', description));
        if (actionText && actionHref) {
            var action = element('a', 'site-button site-button-quiet', actionText);
            action.href = actionHref;
            wrapper.appendChild(action);
        }
        return wrapper;
    }

    function errorState(message, retry) {
        var wrapper = emptyState('内容暂时没有到达', message || '请稍后再试。');
        var button = element('button', 'site-button site-button-quiet', '重新加载');
        button.type = 'button';
        if (typeof retry === 'function') button.addEventListener('click', retry);
        wrapper.appendChild(button);
        return wrapper;
    }

    function badge(label, tone, iconName) {
        var node = element('span', 'site-badge' + (tone ? ' ' + tone : ''));
        if (iconName) node.appendChild(icon(iconName));
        node.appendChild(document.createTextNode(label));
        return node;
    }

    function postHref(post) {
        return '/blog?id=' + encodeURIComponent(Number(post.id) || 0);
    }

    function renderPostCard(post) {
        var article = element('article', 'post-card');
        var cover = element('a', 'post-card-cover');
        cover.href = postHref(post);
        cover.setAttribute('aria-label', '阅读' + text(post.title, '文章'));
        var coverUrl = safeUrl(post.cover_image);
        if (coverUrl) {
            var image = element('img');
            image.src = coverUrl;
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            cover.appendChild(image);
        } else {
            var fallback = element('span', 'post-cover-fallback');
            fallback.appendChild(icon('bi-journal-text'));
            cover.appendChild(fallback);
        }

        var body = element('div', 'post-card-body');
        var labels = element('div', 'post-card-labels');
        if (post.is_pinned) labels.appendChild(badge('置顶', 'is-pinned', 'bi-pin-angle-fill'));
        if (text(post.category)) labels.appendChild(badge(post.category, '', null));
        if (post.has_privacy_content) labels.appendChild(badge('私密片段', 'is-locked', 'bi-lock'));
        if (post.has_paid_content) labels.appendChild(badge('付费内容', 'is-paid', 'bi-gem'));

        var title = element('h3');
        var link = element('a', '', text(post.title, '未命名文章'));
        link.href = postHref(post);
        title.appendChild(link);
        var summary = element('p', 'post-card-summary', text(post.summary, '打开文章，查看完整内容与作者的进一步思考。'));
        var meta = element('div', 'post-card-meta');
        var date = element('span');
        append(date, icon('bi-calendar3'), document.createTextNode(formatDate(post.published_at || post.created_at)));
        var views = element('span');
        append(views, icon('bi-eye'), document.createTextNode(number(post.views) + ' 次阅读'));
        append(meta, date, views);

        var footer = element('div', 'post-card-footer');
        var tags = element('div', 'post-card-tags');
        (Array.isArray(post.tags) ? post.tags.slice(0, 3) : []).forEach(function (tag) {
            tags.appendChild(element('span', '', '#' + text(tag)));
        });
        var arrow = element('a', 'post-card-arrow');
        arrow.href = postHref(post);
        arrow.setAttribute('aria-label', '阅读全文');
        arrow.appendChild(icon('bi-arrow-up-right'));
        append(footer, tags, arrow);
        append(body, labels, title, summary, meta, footer);
        append(article, cover, body);
        return article;
    }

    function renderHomePost(post, index) {
        var article = element('article', 'home-post-entry' + (index === 0 ? ' is-featured' : ''));
        var cover = element('a', 'home-post-cover');
        cover.href = postHref(post);
        cover.setAttribute('aria-label', '阅读' + text(post.title, '文章'));
        var coverUrl = safeUrl(post.cover_image);
        if (coverUrl) {
            var image = element('img');
            image.src = coverUrl;
            image.alt = '';
            image.loading = index === 0 ? 'eager' : 'lazy';
            image.decoding = 'async';
            cover.appendChild(image);
        } else {
            var fallback = element('span', 'home-post-cover-fallback');
            fallback.appendChild(icon(index === 0 ? 'bi-journal-richtext' : 'bi-file-earmark-text'));
            cover.appendChild(fallback);
        }

        var body = element('div', 'home-post-body');
        var labels = element('div', 'home-post-labels');
        if (post.is_pinned) labels.appendChild(badge('置顶', 'is-pinned', 'bi-pin-angle-fill'));
        if (text(post.category)) labels.appendChild(badge(post.category));
        if (post.has_privacy_content) labels.appendChild(badge('私密片段', 'is-locked', 'bi-lock'));
        if (post.has_paid_content) labels.appendChild(badge('付费内容', 'is-paid', 'bi-gem'));

        var title = element('h3');
        var titleLink = element('a', '', text(post.title, '未命名文章'));
        titleLink.href = postHref(post);
        title.appendChild(titleLink);
        var summary = element('p', 'home-post-summary', text(post.summary, '打开文章，继续阅读完整内容。'));
        var meta = element('div', 'home-post-meta');
        var date = element('span');
        append(date, icon('bi-calendar3'), document.createTextNode(formatDate(post.published_at || post.created_at)));
        var views = element('span');
        append(views, icon('bi-eye'), document.createTextNode(number(post.views) + ' 次阅读'));
        append(meta, date, views);

        var footer = element('footer', 'home-post-footer');
        var tags = element('div', 'home-post-tags');
        (Array.isArray(post.tags) ? post.tags.slice(0, 3) : []).forEach(function (tag) {
            tags.appendChild(element('span', '', '#' + text(tag)));
        });
        var readMore = element('a', 'home-post-read', '阅读全文');
        readMore.href = postHref(post);
        readMore.appendChild(icon('bi-arrow-right'));
        append(footer, tags, readMore);
        append(body, labels, title, summary, meta, footer);
        append(article, cover, body);
        return article;
    }

    async function loadHomePosts() {
        var container = qs('[data-home-posts]', root);
        if (!container) return;
        setBusy(container, true);
        try {
            var data = await requestJson('/nova-json/v1/posts?per_page=8&page=1');
            clear(container);
            var posts = Array.isArray(data.items) ? data.items : [];
            if (!posts.length) {
                container.appendChild(emptyState('还没有公开文章', '第一篇内容发布后，会在这里出现。', '前往博客', '/blog'));
            } else {
                posts.forEach(function (post, index) {
                    container.appendChild(renderHomePost(post, index));
                });
            }
        } catch (error) {
            clear(container);
            container.appendChild(errorState(error.message, loadHomePosts));
        } finally {
            setBusy(container, false);
        }
    }

    async function loadHomeCategories() {
        var container = qs('[data-home-categories]', root);
        if (!container) return;
        setBusy(container, true);
        try {
            var data = await requestJson('/nova-json/v1/categories');
            var categories = (Array.isArray(data.items) ? data.items : []).filter(function (item) {
                return Number(item.post_count) > 0;
            }).slice(0, 8);
            clear(container);
            if (!categories.length) {
                container.appendChild(element('p', 'widget-muted', '发布文章并设置分类后会显示在这里。'));
            } else {
                categories.forEach(function (item) {
                    var link = element('a');
                    link.href = blogUrl(1, '', text(item.name));
                    append(link, element('span', '', text(item.name)), element('strong', '', number(item.post_count)));
                    container.appendChild(link);
                });
            }
        } catch (error) {
            clear(container);
            container.appendChild(element('p', 'widget-muted', '分类暂时没有加载完成。'));
        } finally {
            setBusy(container, false);
        }
    }

    function initSubscribe() {
        var form = qs('[data-subscribe-form]', root);
        if (!form) return;
        var feedback = qs('[data-subscribe-feedback]', form);
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var input = form.elements.email;
            var button = qs('button[type="submit"]', form);
            var email = text(input && input.value);
            if (!email || !/^\S+@\S+\.\S+$/.test(email)) {
                feedback.textContent = '请填写有效的邮箱地址。';
                feedback.className = 'form-feedback is-error';
                if (input) input.focus();
                return;
            }
            button.disabled = true;
            feedback.textContent = '正在提交…';
            feedback.className = 'form-feedback';
            try {
                await requestJson('/nova-json/v1/content/subscribe', jsonOptions('POST', { email: email, source: 'homepage' }));
                feedback.textContent = '订阅成功，之后有新内容会通知你。';
                feedback.className = 'form-feedback is-success';
                form.reset();
            } catch (error) {
                feedback.textContent = error.message;
                feedback.className = 'form-feedback is-error';
            } finally {
                button.disabled = false;
            }
        });
    }

    function blogUrl(page, search, category) {
        var params = new URLSearchParams();
        if (search) params.set('q', search);
        if (category) params.set('category', category);
        if (page > 1) params.set('page', String(page));
        return '/blog' + (params.toString() ? '?' + params.toString() : '');
    }

    function renderPagination(container, currentPage, totalPages, search, category) {
        clear(container);
        if (totalPages <= 1) return;

        var previous = element('a', 'pagination-direction');
        previous.href = blogUrl(Math.max(1, currentPage - 1), search, category);
        append(previous, icon('bi-arrow-left'), document.createTextNode('上一页'));
        if (currentPage <= 1) {
            previous.classList.add('is-disabled');
            previous.setAttribute('aria-disabled', 'true');
        }
        container.appendChild(previous);

        var numbers = element('div');
        var start = Math.max(1, currentPage - 2);
        var end = Math.min(totalPages, currentPage + 2);
        for (var page = start; page <= end; page += 1) {
            var link = element('a', page === currentPage ? 'is-active' : '', String(page));
            link.href = blogUrl(page, search, category);
            if (page === currentPage) link.setAttribute('aria-current', 'page');
            numbers.appendChild(link);
        }
        container.appendChild(numbers);

        var next = element('a', 'pagination-direction');
        next.href = blogUrl(Math.min(totalPages, currentPage + 1), search, category);
        append(next, document.createTextNode('下一页'), icon('bi-arrow-right'));
        if (currentPage >= totalPages) {
            next.classList.add('is-disabled');
            next.setAttribute('aria-disabled', 'true');
        }
        container.appendChild(next);
    }

    async function loadBlogCategories(search, currentCategory) {
        var container = qs('[data-blog-categories]', root);
        if (!container) return;
        qsa('[data-blog-category-dynamic]', container).forEach(function (link) {
            link.remove();
        });
        try {
            var data = await requestJson('/nova-json/v1/categories');
            qsa('[data-blog-category-dynamic]', container).forEach(function (link) {
                link.remove();
            });
            (Array.isArray(data.items) ? data.items : []).filter(function (item) {
                return Number(item.post_count) > 0;
            }).forEach(function (item) {
                var link = element('a', text(item.name) === currentCategory ? 'is-active' : '');
                link.setAttribute('data-blog-category-dynamic', 'true');
                link.href = blogUrl(1, search, text(item.name));
                append(link, document.createTextNode(text(item.name)), element('span', '', number(item.post_count)));
                container.appendChild(link);
            });
        } catch (error) {
            // Category filters are enhancement-only; the article list remains usable.
        }
    }

    async function loadBlogList() {
        var container = qs('[data-blog-posts]', root);
        var countLabel = qs('[data-blog-count]', root);
        var pagination = qs('[data-blog-pagination]', root);
        if (!container) return;
        var page = Math.max(1, Number(root.dataset.page) || 1);
        var search = text(root.dataset.search);
        var category = text(root.dataset.category);
        var params = new URLSearchParams({ page: String(page), per_page: '9' });
        if (search) params.set('search', search);
        if (category) params.set('category', category);
        setBusy(container, true);
        try {
            var data = await requestJson('/nova-json/v1/posts?' + params.toString());
            var posts = Array.isArray(data.items) ? data.items : [];
            clear(container);
            if (countLabel) countLabel.textContent = '共 ' + number(data.total) + ' 篇，当前第 ' + number(data.page) + ' 页';
            if (!posts.length) {
                container.appendChild(emptyState('没有匹配的文章', '换一个关键词或分类再试一次。', '查看全部文章', '/blog'));
            } else {
                posts.forEach(function (post) { container.appendChild(renderPostCard(post)); });
            }
            renderPagination(pagination, Number(data.page) || page, Math.max(1, Number(data.total_pages) || 1), search, category);
        } catch (error) {
            clear(container);
            container.appendChild(errorState(error.message, loadBlogList));
            if (countLabel) countLabel.textContent = '文章列表加载未完成';
        } finally {
            setBusy(container, false);
        }
        loadBlogCategories(search, category);
    }

    function sanitizeRenderedHtml(html) {
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        parsed.querySelectorAll('script,style,iframe,object,embed,form,input,textarea,select,option,meta,link,base,svg,math,template').forEach(function (node) {
            node.remove();
        });
        parsed.body.querySelectorAll('*').forEach(function (node) {
            Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
                var name = attribute.name.toLowerCase();
                if (name.indexOf('on') === 0 || ['style', 'srcdoc', 'xlink:href', 'action', 'formaction', 'ping'].indexOf(name) !== -1) {
                    node.removeAttribute(attribute.name);
                }
            });
            ['href', 'src'].forEach(function (attribute) {
                if (!node.hasAttribute(attribute)) return;
                var normalized = safeUrl(node.getAttribute(attribute), { mail: attribute === 'href' });
                if (!normalized) node.removeAttribute(attribute);
                else node.setAttribute(attribute, normalized);
            });
            if (node.tagName === 'A') {
                var href = node.getAttribute('href');
                if (href && new URL(href, window.location.origin).origin !== window.location.origin) {
                    node.setAttribute('target', '_blank');
                    node.setAttribute('rel', 'noopener noreferrer');
                }
            }
        });
        return parsed.body;
    }

    function enhanceCodeBlocks(target) {
        qsa('pre', target).forEach(function (pre) {
            if (pre.parentElement && pre.parentElement.classList.contains('code-block-wrapper')) return;
            var wrapper = element('div', 'code-block-wrapper');
            wrapper.setAttribute('data-nova-copy', 'true');
            var toolbar = element('div', 'code-block-toolbar');
            toolbar.appendChild(element('span', '', 'Code'));
            var copy = element('button', '', '复制');
            copy.type = 'button';
            copy.addEventListener('click', async function () {
                try {
                    await navigator.clipboard.writeText(pre.textContent || '');
                    copy.textContent = '已复制';
                    window.setTimeout(function () { copy.textContent = '复制'; }, 1600);
                } catch (error) {
                    copy.textContent = '请手动复制';
                }
            });
            toolbar.appendChild(copy);
            pre.parentNode.insertBefore(wrapper, pre);
            append(wrapper, toolbar, pre);
        });
    }

    function renderMarkdown(target, source) {
        if (!target) return;
        var markdown = text(source);
        if (!window.marked || typeof window.marked.parse !== 'function') {
            target.textContent = markdown;
            target.style.whiteSpace = 'pre-wrap';
            target.setAttribute('aria-busy', 'false');
            return;
        }
        try {
            var body = sanitizeRenderedHtml(window.marked.parse(markdown));
            target.replaceChildren.apply(target, Array.prototype.slice.call(body.childNodes));
            enhanceCodeBlocks(target);
        } catch (error) {
            target.textContent = markdown;
            target.style.whiteSpace = 'pre-wrap';
        }
        target.setAttribute('aria-busy', 'false');
    }

    function decodeBase64Utf8(value) {
        try {
            var binary = window.atob(value || '');
            var bytes = Uint8Array.from(binary, function (character) { return character.charCodeAt(0); });
            if (window.TextDecoder) return new TextDecoder('utf-8', { fatal: false }).decode(bytes);
            var escaped = Array.prototype.map.call(bytes, function (byte) {
                return '%' + byte.toString(16).padStart(2, '0');
            }).join('');
            return decodeURIComponent(escaped);
        } catch (error) {
            return '';
        }
    }

    function bindCopyLink(scope) {
        qsa('[data-copy-link]', scope || root).forEach(function (button) {
            if (button.getAttribute('data-copy-link-bound') === 'true') return;
            button.setAttribute('data-copy-link-bound', 'true');
            button.addEventListener('click', async function () {
                try {
                    await navigator.clipboard.writeText(window.location.href);
                    if (window.NovaTheme && window.NovaTheme.toast) window.NovaTheme.toast('链接已复制', 'success');
                    else button.textContent = '链接已复制';
                } catch (error) {
                    window.prompt('复制当前链接', window.location.href);
                }
            });
        });
    }

    function initStaticMarkdown() {
        var targetId = root.dataset.markdownTarget;
        var target = targetId ? document.getElementById(targetId) : null;
        if (!target) return;
        renderMarkdown(target, decodeBase64Utf8(target.dataset.markdownSource || ''));
        bindCopyLink(root);
    }

    function renderPostHeader(post) {
        var titleNode = qs('[data-post-title]', root);
        var summaryNode = qs('[data-post-summary]', root);
        var labelsNode = qs('[data-post-labels]', root);
        var metaNode = qs('[data-post-meta]', root);
        if (titleNode) titleNode.textContent = text(post.title, '未命名文章');
        if (summaryNode) summaryNode.textContent = text(post.summary, '这篇文章暂未填写摘要，正文从下方开始。');
        if (labelsNode) {
            clear(labelsNode);
            if (post.is_pinned) labelsNode.appendChild(badge('置顶文章', 'is-pinned', 'bi-pin-angle-fill'));
            if (text(post.category)) labelsNode.appendChild(badge(post.category));
            if (post.has_privacy_content) labelsNode.appendChild(badge('包含私密片段', 'is-locked', 'bi-lock'));
            if (post.has_paid_content) labelsNode.appendChild(badge('包含付费内容', 'is-paid', 'bi-gem'));
        }
        if (metaNode) {
            clear(metaNode);
            var values = [
                ['bi-person', text(post.author, '站点作者')],
                ['bi-calendar3', formatDate(post.published_at || post.created_at)],
                ['bi-eye', number(post.views) + ' 次阅读']
            ];
            values.forEach(function (item) {
                var span = element('span');
                append(span, icon(item[0]), document.createTextNode(item[1]));
                metaNode.appendChild(span);
            });
        }
        var cover = qs('[data-post-cover]', root);
        var coverUrl = safeUrl(post.cover_image);
        if (cover && coverUrl) {
            var image = qs('img', cover);
            image.src = coverUrl;
            image.alt = text(post.title, '文章封面');
            cover.hidden = false;
        }
        document.title = text(post.title, '文章') + ' · ' + (document.documentElement.dataset.siteName || 'NovaCMS');
    }

    function sortCommentsByDate(items) {
        return (items || []).slice().sort(function (left, right) {
            var leftDate = new Date(String(left.created_at || '').replace(' ', 'T')).getTime();
            var rightDate = new Date(String(right.created_at || '').replace(' ', 'T')).getTime();
            if (Number.isNaN(leftDate) || Number.isNaN(rightDate)) return Number(left.id) - Number(right.id);
            return leftDate - rightDate;
        });
    }

    function activateCommentReply(comment) {
        var form = qs('[data-comment-form]', root);
        if (!form) return;
        var parentId = Math.max(0, Number(comment && comment.id) || 0);
        if (!parentId) return;
        var replyName = text(comment.username, '访客');
        var label = qs('[data-comment-label]', form);
        var feedback = qs('[data-comment-feedback]', form);
        var cancel = qs('[data-comment-cancel-reply]', form);
        var textarea = form.elements.content;
        form.dataset.replyParentId = String(parentId);
        form.dataset.replyName = replyName;
        if (label) label.textContent = '回复 @' + replyName;
        if (feedback) feedback.textContent = '正在回复 @' + replyName + '。';
        if (cancel) cancel.hidden = false;
        if (textarea) {
            try {
                textarea.focus({ preventScroll: true });
            } catch (error) {
                textarea.focus();
            }
            textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function clearCommentReply(form, message) {
        if (!form) return;
        delete form.dataset.replyParentId;
        delete form.dataset.replyName;
        var label = qs('[data-comment-label]', form);
        var feedback = qs('[data-comment-feedback]', form);
        var cancel = qs('[data-comment-cancel-reply]', form);
        if (label) label.textContent = '写下你的想法';
        if (cancel) cancel.hidden = true;
        if (message && feedback) feedback.textContent = message;
    }

    function commentReplyAction(comment) {
        var button = element('button', 'comment-reply-action');
        button.type = 'button';
        button.setAttribute('aria-label', '回复 ' + text(comment.username, '访客'));
        append(button, icon('bi-reply'), document.createTextNode('回复'));
        button.addEventListener('click', function () { activateCommentReply(comment); });
        return button;
    }

    function commentAvatar(comment) {
        var avatarUrl = text(comment.avatar_url);
        var name = text(comment.username, '访客');
        var node = element('span', 'comment-avatar');
        if (avatarUrl) {
            var img = element('img');
            img.src = avatarUrl;
            img.alt = name;
            img.loading = 'lazy';
            img.decoding = 'async';
            node.appendChild(img);
        } else {
            node.textContent = name.slice(0, 1).toUpperCase() || '?';
        }
        return node;
    }

    function commentAuthor(comment) {
        var name = text(comment.username, '访客');
        var website = safeUrl(comment.website);
        if (website) {
            var link = element('a', '', name);
            link.href = website;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            return link;
        }
        return element('strong', '', name);
    }

    function commentMeta(comment) {
        var meta = element('div', 'comment-meta');
        var dev = text(comment.device_info);
        if (dev) {
            var span = element('span');
            append(span, icon('bi-pc-display'), document.createTextNode(dev));
            meta.appendChild(span);
        }
        if (comment.is_private) {
            meta.appendChild(badge('私密', 'is-locked', 'bi-lock'));
        }
        return meta.childNodes.length ? meta : null;
    }

    function buildCommentBody(comment, isReply) {
        var body = element('div', 'comment-body');
        var header = element('header', isReply ? 'comment-reply-header' : '');
        var masked = comment.masked === true;
        append(header, commentAuthor(comment), element('time', '', formatDate(comment.created_at)));
        if (!masked) append(header, commentReplyAction(comment));
        append(body, header, element('p', '', text(comment.content)));
        if (!masked) {
            var meta = commentMeta(comment);
            if (meta) body.appendChild(meta);
        }
        return body;
    }

    function buildCommentArticle(comment, containerClass, extraChild) {
        var article = element('article', containerClass);
        var body = buildCommentBody(comment, containerClass === 'comment-reply');
        if (extraChild) body.appendChild(extraChild);
        append(article, commentAvatar(comment), body);
        return article;
    }

    function renderCommentReplies(parentId, grouped, ancestorIds) {
        var replies = sortCommentsByDate(grouped[String(parentId)] || []);
        if (!replies.length) return null;
        var container = element('div', 'comment-replies');
        replies.forEach(function (reply) {
            var replyId = String(reply.id || '');
            if (!replyId || ancestorIds[replyId]) return;
            var nextAncestors = Object.assign({}, ancestorIds);
            nextAncestors[replyId] = true;
            var nested = renderCommentReplies(reply.id, grouped, nextAncestors);
            var child = buildCommentArticle(reply, 'comment-reply', nested);
            container.appendChild(child);
        });
        return container;
    }

    async function loadComments(postId) {
        var container = qs('[data-comment-list]', root);
        var count = qs('[data-comment-count]', root);
        if (!container) return;
        setBusy(container, true);
        try {
            var data = await requestJson('/nova-json/v1/comments?post_id=' + encodeURIComponent(postId));
            var comments = Array.isArray(data.items) ? data.items : [];
            clear(container);
            if (count) count.textContent = number(data.total) + ' 条';
            if (!comments.length) {
                container.appendChild(emptyState('还没有评论', '成为第一个留下想法的人。'));
                return;
            }
            var grouped = {};
            comments.forEach(function (comment) {
                var parent = comment.parent_id ? String(comment.parent_id) : 'root';
                if (!grouped[parent]) grouped[parent] = [];
                grouped[parent].push(comment);
            });
            sortCommentsByDate(grouped.root || []).forEach(function (comment) {
                var ancestors = (function () { var a = {}; a[String(comment.id)] = true; return a; }());
                var replies = renderCommentReplies(comment.id, grouped, ancestors);
                var article = buildCommentArticle(comment, 'comment-card', replies);
                container.appendChild(article);
            });
        } catch (error) {
            clear(container);
            container.appendChild(errorState(error.message, function () { loadComments(postId); }));
        } finally {
            setBusy(container, false);
        }
    }

    function initCommentForm(postId) {
        var form = qs('[data-comment-form]', root);
        if (!form || form.getAttribute('data-comment-form-bound') === 'true') return;
        form.setAttribute('data-comment-form-bound', 'true');
        var panel = form.closest('.comments-panel') || root;
        var loginRequired = panel.getAttribute('data-comment-login-required') === '1';
        var privateEnabled = panel.getAttribute('data-comment-private-enabled') === '1';
        var loggedIn = panel.getAttribute('data-comment-logged-in') === '1';
        var feedback = qs('[data-comment-feedback]', form);
        var cancelReply = qs('[data-comment-cancel-reply]', form);
        var identityWrap = qs('[data-comment-identity]', form);
        var privateWrap = qs('[data-comment-private-wrap]', form);

        function applyIdentityVisibility() {
            if (identityWrap) identityWrap.hidden = (!loginRequired && !loggedIn) ? false : true;
            if (privateWrap) privateWrap.hidden = privateEnabled ? false : true;
        }
        applyIdentityVisibility();

        if (cancelReply) {
            cancelReply.addEventListener('click', function () {
                clearCommentReply(form, '已取消回复，现在可以发表新评论。');
            });
        }
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var textarea = form.elements.content;
            var content = text(textarea && textarea.value);
            var button = qs('button[type="submit"]', form);
            if (!content) {
                feedback.textContent = '先写下一点内容吧。';
                if (textarea) textarea.focus();
                return;
            }
            var payload = { post_id: postId, content: content };
            var parentId = Math.max(0, Number(form.dataset.replyParentId) || 0);
            if (parentId) payload.parent_id = parentId;
            // 匿名评论者信息（未登录且未强制登录时必填）
            if (!loginRequired && !loggedIn) {
                var name = form.elements.username ? text(form.elements.username.value) : '';
                var email = form.elements.email ? text(form.elements.email.value) : '';
                if (!name) {
                    feedback.textContent = '请填写昵称。';
                    if (form.elements.username) form.elements.username.focus();
                    return;
                }
                if (!email) {
                    feedback.textContent = '请填写邮箱（或 QQ 号）。';
                    if (form.elements.email) form.elements.email.focus();
                    return;
                }
                payload.username = name;
                payload.email = email;
                var website = form.elements.website ? text(form.elements.website.value) : '';
                if (website) payload.website = website;
            }
            if (privateEnabled) {
                var privateBox = form.elements.is_private;
                payload.is_private = !!(privateBox && privateBox.checked);
            }
            button.disabled = true;
            feedback.textContent = '正在发布…';
            try {
                var res = await requestJson('/nova-json/v1/comments', jsonOptions('POST', payload));
                form.reset();
                if (res.pending_approval) {
                    clearCommentReply(form, parentId ? '回复已提交，等待管理员审核。' : '评论已提交，等待管理员审核。');
                } else {
                    clearCommentReply(form, parentId ? '回复已发布。' : '评论已发布。');
                    loadComments(postId);
                }
                applyIdentityVisibility();
            } catch (error) {
                feedback.textContent = error.status === 401 ? '请先登录，再参与讨论。' : error.message;
            } finally {
                button.disabled = false;
            }
        });
    }

    async function loadPrivacyQuestion(postId, dialog) {
        var question = qs('[data-privacy-question]', dialog);
        try {
            var response = await fetch('/nova-json/v1/posts/privacy', jsonOptions('POST', { post_id: postId, answer: '' }));
            var payload = await response.json();
            var data = payload && payload.data ? payload.data : {};
            question.textContent = text(data.question, payload.message || '请回答作者设置的问题。');
            if (effectiveResponseStatus(response, payload) === 401) {
                var feedback = qs('[data-privacy-feedback]', dialog);
                feedback.textContent = '请先登录后再申请访问。';
            }
        } catch (error) {
            question.textContent = '请回答作者设置的问题。';
        }
    }

    function bindPrivacyControls(postId) {
        var dialog = qs('[data-privacy-dialog]', root);
        if (!dialog) return;
        qsa('.privacy-notice button, [data-open-privacy]', root).forEach(function (button) {
            if (button.getAttribute('data-privacy-bound') === 'true') return;
            button.setAttribute('data-privacy-bound', 'true');
            button.removeAttribute('data-bs-toggle');
            button.removeAttribute('data-bs-target');
            button.addEventListener('click', function () {
                loadPrivacyQuestion(postId, dialog);
                if (typeof dialog.showModal === 'function') dialog.showModal();
                else dialog.setAttribute('open', '');
            });
        });
        var form = qs('[data-privacy-form]', dialog);
        if (!form || form.getAttribute('data-privacy-form-bound') === 'true') return;
        form.setAttribute('data-privacy-form-bound', 'true');
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var answer = text(form.elements.answer && form.elements.answer.value);
            var feedback = qs('[data-privacy-feedback]', form);
            var button = qs('button[type="submit"]', form);
            if (!answer) {
                feedback.textContent = '请先填写回答。';
                return;
            }
            button.disabled = true;
            feedback.textContent = '正在提交…';
            try {
                var data = await requestJson('/nova-json/v1/posts/privacy', jsonOptions('POST', { post_id: postId, answer: answer }));
                if (data.pending_approval) {
                    feedback.textContent = '申请已提交，请等待审核。';
                } else if (data.access_granted) {
                    feedback.textContent = '验证通过，正在刷新文章。';
                    window.setTimeout(function () { window.location.reload(); }, 600);
                } else {
                    feedback.textContent = '本次申请尚未获得访问权限。';
                }
            } catch (error) {
                feedback.textContent = error.message;
            } finally {
                button.disabled = false;
            }
        });
    }

    function initReadingProgress() {
        var bar = qs('[data-reading-progress]', root);
        if (!bar || bar.getAttribute('data-reading-progress-bound') === 'true') return;
        bar.setAttribute('data-reading-progress-bound', 'true');
        function update() {
            var content = qs('[data-post-body]', root);
            if (!content) return;
            var rect = content.getBoundingClientRect();
            var total = Math.max(1, content.offsetHeight - window.innerHeight * 0.55);
            var travelled = Math.min(total, Math.max(0, -rect.top + window.innerHeight * 0.3));
            bar.style.height = (travelled / total * 100).toFixed(2) + '%';
        }
        window.addEventListener('scroll', update, { passive: true });
        update();
    }

    async function loadPostDetail() {
        var postId = Math.max(0, Number(root.dataset.postId) || 0);
        var body = qs('[data-post-body]', root);
        if (!postId || !body) return;
        try {
            var data = await requestJson('/nova-json/v1/posts/' + encodeURIComponent(postId));
            var post = data.item || {};
            renderPostHeader(post);
            renderMarkdown(body, post.content || '');
            bindPrivacyControls(postId);
        } catch (error) {
            clear(body);
            body.appendChild(errorState(error.status === 404 ? '这篇文章不存在或尚未公开。' : error.message, loadPostDetail));
            return;
        }
        bindCopyLink(root);
        initReadingProgress();
        initCommentForm(postId);
        loadComments(postId);
    }

    function init() {
        var page = root.dataset.contentPage;
        if (page === 'home') {
            loadHomePosts();
            loadHomeCategories();
            initSubscribe();
        } else if (page === 'blog-list') {
            loadBlogList();
        } else if (page === 'blog-detail') {
            loadPostDetail();
        } else if (page === 'markdown') {
            initStaticMarkdown();
        }
    }

    init();
}());
