<?php
$pageTitle = $document['title'] ?? '文档';
$downloadUrl = contentModuleSafeUrl($document['file_url'] ?? '');
$extraHead = <<<'HTML'
<style>
.document-shell{padding-top:104px;padding-bottom:72px;min-height:72vh}
.document-layout{display:grid;grid-template-columns:minmax(0,1fr) 260px;gap:2rem;align-items:start}
.document-main{padding:clamp(1.3rem,4vw,3rem);border:1px solid var(--bs-border-color);border-radius:24px;background:var(--bs-body-bg);box-shadow:0 18px 52px rgba(15,23,42,.07)}
.document-breadcrumb{display:flex;flex-wrap:wrap;align-items:center;gap:.45rem;font-size:.88rem;color:var(--bs-secondary-color)}
.document-breadcrumb a{color:inherit;text-decoration:none}
.document-breadcrumb a:hover{color:var(--bs-primary)}
.document-title{font-size:clamp(2rem,5vw,3.25rem);line-height:1.18;margin:1rem 0}
.document-summary{color:var(--bs-secondary-color);font-size:1.08rem;line-height:1.75}
.document-meta{display:flex;flex-wrap:wrap;gap:.85rem 1.2rem;padding:1rem 0 1.4rem;border-bottom:1px solid var(--bs-border-color);color:var(--bs-secondary-color);font-size:.86rem}
.document-aside{position:sticky;top:96px;padding:1.2rem;border:1px solid var(--bs-border-color);border-radius:18px;background:var(--bs-body-bg)}
.document-aside .btn{min-height:44px}
.cms-markdown{margin-top:2rem;font-size:1.02rem;line-height:1.9;overflow-wrap:anywhere}
.cms-markdown h1,.cms-markdown h2,.cms-markdown h3{scroll-margin-top:100px;margin:1.8em 0 .7em;line-height:1.3}
.cms-markdown h2{padding-bottom:.55rem;border-bottom:1px solid var(--bs-border-color)}
.cms-markdown img{max-width:100%;height:auto;border-radius:14px}
.cms-markdown pre{padding:1rem 1.15rem;border-radius:14px;background:#111827;color:#e5e7eb;overflow:auto}
.cms-markdown code{padding:.15em .35em;border-radius:.35em;background:rgba(127,127,127,.12)}
.cms-markdown pre code{padding:0;background:transparent}
.cms-markdown blockquote{margin:1.4rem 0;padding:.35rem 1rem;border-left:4px solid var(--bs-primary);color:var(--bs-secondary-color)}
.cms-markdown table{display:block;max-width:100%;overflow:auto;border-collapse:collapse}
.cms-markdown th,.cms-markdown td{padding:.7rem;border:1px solid var(--bs-border-color)}
@media (max-width:991.98px){.document-layout{grid-template-columns:1fr}.document-aside{position:static;order:-1}}
@media print{nav,.document-aside,footer{display:none!important}.document-shell{padding:0}.document-main{border:0;box-shadow:none;padding:0}}
</style>
HTML;
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>

<main class="document-shell">
    <div class="container">
        <div class="document-layout">
            <article class="document-main">
                <nav class="document-breadcrumb" aria-label="面包屑">
                    <a href="/"><i class="bi bi-house-door me-1"></i>首页</a>
                    <i class="bi bi-chevron-right"></i>
                    <a href="/docs">文档中心</a>
                    <?php if (!empty($document['category'])): ?>
                        <i class="bi bi-chevron-right"></i>
                        <a href="/docs?category=<?= rawurlencode($document['category']) ?>"><?= e($document['category']) ?></a>
                    <?php endif; ?>
                </nav>

                <header>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-4">
                        <?php if (!empty($document['is_featured'])): ?>
                            <span class="badge rounded-pill text-bg-primary"><i class="bi bi-star-fill me-1"></i>推荐文档</span>
                        <?php endif; ?>
                        <?php if (!empty($document['category'])): ?>
                            <span class="badge rounded-pill text-bg-light"><?= e($document['category']) ?></span>
                        <?php endif; ?>
                    </div>
                    <h1 class="document-title"><?= e($document['title'] ?? '') ?></h1>
                    <?php if (!empty($document['summary'])): ?>
                        <p class="document-summary"><?= e($document['summary']) ?></p>
                    <?php endif; ?>
                    <div class="document-meta">
                        <?php if (!empty($document['updated_at'])): ?>
                            <span><i class="bi bi-clock-history me-1"></i>更新于 <?= e(date('Y-m-d', strtotime($document['updated_at']))) ?></span>
                        <?php endif; ?>
                        <span><i class="bi bi-download me-1"></i><?= (int)($document['download_count'] ?? 0) ?> 次下载</span>
                    </div>
                </header>

                <div id="document-body" class="cms-markdown" aria-live="polite"></div>
                <noscript>
                    <div class="cms-markdown"><?= nl2br(e($document['content'] ?? '')) ?></div>
                </noscript>
            </article>

            <aside class="document-aside" aria-label="文档操作">
                <div class="fw-semibold mb-1">文档操作</div>
                <p class="small text-body-secondary mb-3">阅读正文，或下载管理员提供的附件。</p>
                <div class="d-grid gap-2">
                    <?php if ($downloadUrl !== ''): ?>
                        <a class="btn btn-primary" href="/docs/<?= rawurlencode($document['slug']) ?>/download">
                            <i class="bi bi-download me-1"></i>下载附件
                        </a>
                    <?php endif; ?>
                    <button class="btn btn-outline-secondary" type="button" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i>打印文档
                    </button>
                    <a class="btn btn-outline-secondary" href="/docs">
                        <i class="bi bi-arrow-left me-1"></i>返回文档中心
                    </a>
                </div>
            </aside>
        </div>
    </div>
</main>

<script src="/assets/js/marked.min.js"></script>
<script>
(function () {
    const target = document.getElementById('document-body');
    const source = <?= js($document['content'] ?? '') ?>;
    if (!target) return;
    if (!window.marked) {
        target.textContent = source;
        target.style.whiteSpace = 'pre-wrap';
        return;
    }

    // Treat raw HTML as text before Markdown rendering. The DOM pass below is
    // a second boundary for URLs and attributes produced by Markdown syntax.
    const markdownSource = source.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const parsed = new DOMParser().parseFromString(window.marked.parse(markdownSource), 'text/html');
    parsed.querySelectorAll('script,style,iframe,object,embed,form,input,button,select,textarea,label,meta,link,base,svg,math,template').forEach(node => node.remove());
    parsed.body.querySelectorAll('*').forEach(node => {
        [...node.attributes].forEach(attribute => {
            const name = attribute.name.toLowerCase();
            if (name.startsWith('on') || ['style', 'srcdoc', 'xlink:href', 'action', 'formaction', 'ping'].includes(name)) {
                node.removeAttribute(attribute.name);
            }
        });
        ['href', 'src'].forEach(attribute => {
            if (!node.hasAttribute(attribute)) return;
            const value = node.getAttribute(attribute).trim();
            try {
                const url = new URL(value, window.location.origin);
                const allowed = attribute === 'href'
                    ? ['http:', 'https:', 'mailto:'].includes(url.protocol)
                    : ['http:', 'https:'].includes(url.protocol);
                if (!allowed) node.removeAttribute(attribute);
            } catch (error) {
                node.removeAttribute(attribute);
            }
        });
        if (node.tagName === 'A' && node.getAttribute('target') === '_blank') {
            node.setAttribute('rel', 'noopener noreferrer');
        }
    });
    target.replaceChildren(...parsed.body.childNodes);
})();
</script>

<?php include $themePath . '/partials/footer.php'; ?>
