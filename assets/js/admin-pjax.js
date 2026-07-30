(function () {
    'use strict';

    var CONTAINER_SELECTOR = '#pjax-container';
    var PAGE_SCRIPTS_SELECTOR = '#page-scripts';
    var MANAGED_HEAD_SELECTOR = '[data-pjax-head-resource]';

    var container = document.querySelector(CONTAINER_SELECTOR);
    if (!container) return;

    var isLoading = false;
    var currentXhr = null;
    var loadedHeadScripts = Object.create(null);

    // Pages that must always do a full reload (login state changes, etc.)
    var skipPatterns = [
        /\/admin\/login\.php(\b|$|\?)/,
        /\/admin\/logout\.php(\b|$|\?)/
    ];

    function absUrl(url) {
        try {
            return new URL(url, window.location.href).href;
        } catch (e) {
            return null;
        }
    }

    function isSameOrigin(url) {
        var parsed = absUrl(url);
        if (!parsed) return false;
        return parsed.indexOf(window.location.origin) === 0;
    }

    function isInternalAdminLink(url) {
        if (!isSameOrigin(url)) return false;
        var parsed = absUrl(url);
        if (!parsed) return false;
        var path = new URL(parsed).pathname;
        return path.indexOf('/admin/') === 0 || path === '/admin';
    }

    function shouldSkipUrl(url) {
        var parsed = absUrl(url);
        if (!parsed) return true;
        for (var i = 0; i < skipPatterns.length; i++) {
            if (skipPatterns[i].test(parsed)) return true;
        }
        return false;
    }

    function shouldIntercept(link, event) {
        if (event.button !== 0) return false;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;

        var href = link.getAttribute('href');
        if (!href) return false;
        if (href.charAt(0) === '#') return false;
        if (href === 'javascript:void(0)' || href.indexOf('javascript:') === 0) return false;

        if (link.hasAttribute('data-no-pjax')) return false;
        if (link.getAttribute('target') === '_blank') return false;
        if (link.hasAttribute('download')) return false;
        if (link.classList.contains('submenu-toggle')) return false;

        if (!isInternalAdminLink(href)) return false;
        if (shouldSkipUrl(href)) return false;

        return true;
    }

    // Persistent shell dependencies are already available. Remember them so a
    // page cannot request and execute the same external head dependency again.
    document.querySelectorAll('script[src]:not([type="text/pjax-script"])').forEach(function (script) {
        var source = absUrl(script.getAttribute('src'));
        if (source) loadedHeadScripts[source] = true;
    });

    function showLoading() {
        var el = document.getElementById('loading-overlay');
        if (!el) return;
        var label = el.querySelector('[data-loading-text]');
        if (label) label.textContent = '正在加载页面…';
        el.classList.add('active');
        el.setAttribute('aria-hidden', 'false');
    }

    function hideLoading() {
        var el = document.getElementById('loading-overlay');
        if (!el) return;
        el.classList.remove('active');
        el.setAttribute('aria-hidden', 'true');
    }

    function closeCommandDialog() {
        var dialog = document.querySelector('[data-command-dialog]');
        if (dialog && typeof dialog.close === 'function' && dialog.open) dialog.close();
    }

    function findComment(parent, text) {
        for (var i = 0; i < parent.childNodes.length; i++) {
            var node = parent.childNodes[i];
            if (node.nodeType === 8 && node.nodeValue === text) return node;
        }
        return null;
    }

    function removeRange(start, end) {
        if (!start || !end) return;
        var node = start.nextSibling;
        while (node && node !== end) {
            var next = node.nextSibling;
            node.parentNode.removeChild(node);
            node = next;
        }
    }

    // Replace page-specific <style> tags marked with data-pjax-style
    function replacePjaxStyles(newDoc) {
        document.head.querySelectorAll('style[data-pjax-style]').forEach(function (el) {
            el.remove();
        });
        newDoc.head.querySelectorAll('style[data-pjax-style]').forEach(function (el) {
            document.head.appendChild(el.cloneNode(true));
        });
    }

    // Remove old head_scripts content. Initial-page resources live between the
    // comment markers; resources injected by a PJAX response are explicitly
    // marked because those markers may not exist on the first page.
    function clearHeadBlock() {
        var oldStart = findComment(document.head, 'nova-head-start');
        var oldEnd = findComment(document.head, 'nova-head-end');
        removeRange(oldStart, oldEnd);
        document.head.querySelectorAll(MANAGED_HEAD_SELECTOR).forEach(function (element) {
            element.remove();
        });
    }

    // Collect head_scripts nodes from the response. Returns {links, scripts}.
    function collectHeadNodes(newDoc) {
        var result = { links: [], scripts: [] };
        var newStart = findComment(newDoc.head, 'nova-head-start');
        var newEnd = findComment(newDoc.head, 'nova-head-end');
        if (!newStart || !newEnd) return result;
        var node = newStart.nextSibling;
        while (node && node !== newEnd) {
            if (node.nodeName === 'LINK') {
                result.links.push(node);
            } else if (node.nodeName === 'SCRIPT') {
                result.scripts.push(node);
            }
            node = node.nextSibling;
        }
        return result;
    }

    // Re-execute <script> tags. External scripts (with src) are loaded
    // sequentially so inline scripts that depend on them (e.g. jQuery) work.
    // Each queue item is {node, parent}: node is the source script element
    // (may be detached), parent is where to append if node is not in the DOM.
    var scriptGeneration = 0;

    function executeScriptsInOrder(queue, onComplete) {
        var generation = ++scriptGeneration;

        function done() {
            if (generation === scriptGeneration && onComplete) onComplete();
        }

        function loadNext(index) {
            if (generation !== scriptGeneration) return; // superseded by newer navigation
            if (index >= queue.length) { done(); return; }

            var item = queue[index];
            var oldScript = item.node;
            var source = oldScript.getAttribute('src');
            var cacheKey = item.loadOnce && source ? absUrl(source) : null;

            if (cacheKey && loadedHeadScripts[cacheKey]) {
                if (oldScript.ownerDocument === document && oldScript.parentNode) {
                    oldScript.remove();
                }
                loadNext(index + 1);
                return;
            }

            var newScript = document.createElement('script');
            for (var i = 0; i < oldScript.attributes.length; i++) {
                var attr = oldScript.attributes[i];
                // Convert deferred script type back to executable
                if (attr.name === 'type' && attr.value === 'text/pjax-script') continue;
                newScript.setAttribute(attr.name, attr.value);
            }
            // If no type was copied (was text/pjax-script), leave default (text/javascript)
            newScript.textContent = oldScript.textContent;

            // Attach load handlers BEFORE inserting to avoid any race condition
            var isExternal = newScript.hasAttribute('src');
            if (isExternal) {
                newScript.onload = function () {
                    if (cacheKey) loadedHeadScripts[cacheKey] = true;
                    loadNext(index + 1);
                };
                newScript.onerror = function () {
                    if (cacheKey) delete loadedHeadScripts[cacheKey];
                    loadNext(index + 1);
                };
            }

            // Only use replaceChild if the old script lives in the LIVE document.
            // Head scripts come from a DOMParser result (detached document) and
            // must be appended to the target parent instead.
            try {
                if (oldScript.ownerDocument === document && oldScript.parentNode) {
                    oldScript.parentNode.replaceChild(newScript, oldScript);
                } else if (item.parent) {
                    item.parent.appendChild(newScript);
                }
            } catch (e) {
                console.error('PJAX script insert error:', e);
            }

            if (!isExternal) {
                loadNext(index + 1);
            }
        }

        loadNext(0);
    }

    // Give page code a chance to release timers/listeners, then unmount any
    // Vue 3 app before its mount node is removed. This prevents repeated PJAX
    // visits to the dashboard from leaving refresh timers and charts running.
    function teardownCurrentPage(nextUrl) {
        window.dispatchEvent(new CustomEvent('pjax:before-replace', {
            detail: { url: window.location.href, nextUrl: nextUrl }
        }));

        container.querySelectorAll('[data-v-app]').forEach(function (mountPoint) {
            var app = mountPoint.__vue_app__;
            if (app && typeof app.unmount === 'function') {
                try {
                    app.unmount();
                } catch (error) {
                    console.error('PJAX Vue teardown error:', error);
                }
            }
        });
    }

    // Update the active state on the persistent sidebar
    function updateActiveMenu(url) {
        var sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        var targetPath;
        try {
            targetPath = new URL(url, window.location.href).pathname;
        } catch (e) {
            return;
        }
        sidebar.querySelectorAll('a[href]').forEach(function (link) {
            var href = link.getAttribute('href');
            if (!href || href.charAt(0) === '#') {
                link.classList.remove('active');
                link.removeAttribute('aria-current');
                return;
            }
            var linkPath;
            try {
                linkPath = new URL(href, window.location.href).pathname;
            } catch (e) {
                link.classList.remove('active');
                link.removeAttribute('aria-current');
                return;
            }
            var isActive = linkPath === targetPath;
            link.classList.toggle('active', isActive);
            if (isActive) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });

        // The sidebar shell is persistent, so submenu state must follow the
        // newly active child instead of keeping the state from the first page.
        sidebar.querySelectorAll('.submenu-toggle').forEach(function (toggle) {
            var submenuId = toggle.getAttribute('aria-controls');
            var submenu = submenuId ? document.getElementById(submenuId) : toggle.nextElementSibling;
            var isCurrent = Boolean(submenu && submenu.querySelector('a.active'));
            var isVisible = isCurrent && !document.body.classList.contains('collapsed');

            toggle.classList.toggle('is-current', isCurrent);
            toggle.classList.toggle('open', isCurrent);
            toggle.setAttribute('aria-expanded', isVisible ? 'true' : 'false');
            if (submenu) {
                submenu.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
                submenu.toggleAttribute('inert', !isVisible);
            }
        });
    }

    // Update the topbar heading and body data-admin-page from the new document
    function updateShell(newDoc) {
        var newHeading = newDoc.querySelector('.topbar-context strong');
        var currentHeading = document.querySelector('.topbar-context strong');
        if (newHeading && currentHeading) {
            currentHeading.textContent = newHeading.textContent;
        }
        var newBody = newDoc.body;
        if (newBody) {
            var page = newBody.getAttribute('data-admin-page');
            if (page) document.body.setAttribute('data-admin-page', page);
        }
    }

    function applyResponse(url, html, pushState) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');

        var newContainer = doc.querySelector(CONTAINER_SELECTOR);
        if (!newContainer) {
            hideLoading();
            window.location.href = url;
            return;
        }

        if (doc.title) document.title = doc.title;

        teardownCurrentPage(url);
        replacePjaxStyles(doc);
        updateShell(doc);

        // Head scripts: remove old block, inject CSS links immediately,
        // and queue script tags for sequential execution (before body scripts).
        clearHeadBlock();
        var headNodes = collectHeadNodes(doc);
        headNodes.links.forEach(function (link) {
            var importedLink = document.importNode(link, true);
            importedLink.setAttribute('data-pjax-head-resource', '');
            document.head.appendChild(importedLink);
        });

        // Swap container content
        container.innerHTML = newContainer.innerHTML;

        // Swap page scripts content
        var pageScriptsEl = document.querySelector(PAGE_SCRIPTS_SELECTOR);
        if (pageScriptsEl) {
            var newScripts = doc.querySelector(PAGE_SCRIPTS_SELECTOR);
            pageScriptsEl.innerHTML = newScripts ? newScripts.innerHTML : '';
        }

        // Build unified script queue: head scripts → container scripts → page-scripts.
        // External scripts load sequentially so dependencies (jQuery, Vue, etc.) are ready
        // before inline scripts that use them execute.
        var queue = [];
        headNodes.scripts.forEach(function (s) {
            s.setAttribute('data-pjax-head-resource', '');
            queue.push({ node: s, parent: document.head, loadOnce: true });
        });
        container.querySelectorAll('script').forEach(function (s) { queue.push({ node: s, parent: null }); });
        if (pageScriptsEl) {
            pageScriptsEl.querySelectorAll('script').forEach(function (s) { queue.push({ node: s, parent: null }); });
        }

        updateActiveMenu(url);

        if (pushState) {
            window.history.pushState({ pjax: true, url: url }, '', url);
        }

        window.scrollTo(0, 0);

        // Page scripts can read the new location while initialising. Announce
        // completion only after external dependencies and inline initialisers
        // have all run.
        executeScriptsInOrder(queue, function () {
            hideLoading();
            window.dispatchEvent(new CustomEvent('pjax:complete', { detail: { url: url } }));
        });
    }

    function navigate(url, pushState) {
        if (isLoading && currentXhr) {
            currentXhr.abort();
        }
        // Cancel any pending script loads from the previous page
        scriptGeneration++;
        closeCommandDialog();
        isLoading = true;
        showLoading();

        var xhr = new XMLHttpRequest();
        currentXhr = xhr;
        xhr.open('GET', url, true);
        xhr.setRequestHeader('X-PJAX', 'true');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.timeout = 15000;

        xhr.onload = function () {
            isLoading = false;
            currentXhr = null;
            // NOTE: do NOT hideLoading() here — the loading overlay stays visible
            // until all page scripts have finished loading/executing (see
            // applyResponse → executeScriptsInOrder callback).
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    applyResponse(url, xhr.responseText, pushState !== false);
                } catch (e) {
                    console.error('PJAX error:', e);
                    hideLoading();
                    window.location.href = url;
                }
            } else {
                hideLoading();
                window.location.href = url;
            }
        };

        xhr.onerror = function () {
            isLoading = false;
            currentXhr = null;
            hideLoading();
            window.location.href = url;
        };

        xhr.ontimeout = function () {
            isLoading = false;
            currentXhr = null;
            hideLoading();
            window.location.href = url;
        };

        xhr.send();
    }

    // Intercept link clicks within the document
    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a') : null;
        if (!link) return;
        if (!shouldIntercept(link, event)) return;

        var fullUrl = absUrl(link.getAttribute('href'));
        if (!fullUrl) return;

        // Same page (ignoring hash) — let the browser handle it
        try {
            var current = new URL(window.location.href);
            var target = new URL(fullUrl);
            if (current.pathname === target.pathname && current.search === target.search) return;
        } catch (e) {
            return;
        }

        event.preventDefault();
        navigate(fullUrl, true);
    });

    // Handle browser back/forward
    window.addEventListener('popstate', function (event) {
        var url = window.location.href;
        if (shouldSkipUrl(url)) {
            window.location.reload();
            return;
        }
        navigate(url, false);
    });

    // Mark the initial state so popstate works correctly
    window.history.replaceState({ pjax: true, url: window.location.href }, '', window.location.href);

    // Initial page load: execute deferred scripts (type="text/pjax-script")
    // after the shell (sidebar + header) is visible, then hide the loading overlay.
    // Must wait for DOMContentLoaded because #page-scripts comes after admin-pjax.js
    // in the HTML and hasn't been parsed yet when this script executes.
    document.addEventListener('DOMContentLoaded', function initPage() {
        var queue = [];

        // Collect head scripts (between nova-head-start / nova-head-end markers)
        var headStart = findComment(document.head, 'nova-head-start');
        var headEnd = findComment(document.head, 'nova-head-end');
        if (headStart && headEnd) {
            var node = headStart.nextSibling;
            while (node && node !== headEnd) {
                // Scripts with another explicit type were not deferred by PHP
                // and have already been handled by the browser.
                if (node.nodeName === 'SCRIPT' && node.getAttribute('type') === 'text/pjax-script') {
                    queue.push({ node: node, parent: document.head, loadOnce: true });
                }
                node = node.nextSibling;
            }
        }

        // Collect scripts from #pjax-container (converted to text/pjax-script by PHP)
        container.querySelectorAll('script[type="text/pjax-script"]').forEach(function (s) {
            queue.push({ node: s, parent: null });
        });

        // Collect scripts from #page-scripts
        var pageScriptsEl = document.querySelector(PAGE_SCRIPTS_SELECTOR);
        if (pageScriptsEl) {
            pageScriptsEl.querySelectorAll('script[type="text/pjax-script"]').forEach(function (s) {
                queue.push({ node: s, parent: null });
            });
        }

        if (queue.length > 0) {
            executeScriptsInOrder(queue, hideLoading);
        } else {
            // No page-specific scripts — hide loading immediately
            hideLoading();
        }
    });
})();
