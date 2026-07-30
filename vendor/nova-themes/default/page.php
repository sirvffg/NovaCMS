<?php
$pageTitle = $contentPage['title'] ?? '页面';
$pageTemplate = in_array($contentPage['template'] ?? '', ['wide', 'landing'], true)
    ? $contentPage['template']
    : 'default';
$pageContainerClass = $pageTemplate === 'wide' ? 'container-fluid px-lg-5' : 'container';
$extraHead = <<<'HTML'
<style>
.content-page-shell{padding-top:112px;padding-bottom:72px;min-height:72vh}
.content-page-card{max-width:920px;margin:0 auto;padding:clamp(1.4rem,4vw,3.5rem);border:1px solid var(--bs-border-color);border-radius:24px;background:var(--bs-body-bg);box-shadow:0 20px 60px rgba(15,23,42,.08)}
.content-page-card.is-wide{max-width:1240px}
.content-page-card.is-landing{max-width:1080px;border:0;box-shadow:none;background:transparent}
.content-page-eyebrow{display:inline-flex;align-items:center;gap:.45rem;color:var(--bs-primary);font-size:.82rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.content-page-title{font-size:clamp(2rem,5vw,3.8rem);line-height:1.12;margin:.85rem 0 1rem}
.content-page-summary{max-width:760px;color:var(--bs-secondary-color);font-size:1.08rem;line-height:1.8}
.content-page-meta{display:flex;flex-wrap:wrap;gap:1rem;margin-top:1.1rem;color:var(--bs-secondary-color);font-size:.86rem}
.cms-markdown{margin-top:2.25rem;font-size:1.02rem;line-height:1.9;overflow-wrap:anywhere}
.cms-markdown h1,.cms-markdown h2,.cms-markdown h3{scroll-margin-top:100px;margin:1.8em 0 .7em;line-height:1.3}
.cms-markdown h2{padding-bottom:.55rem;border-bottom:1px solid var(--bs-border-color)}
.cms-markdown img{max-width:100%;height:auto;border-radius:14px}
.cms-markdown pre{padding:1rem 1.15rem;border-radius:14px;background:#111827;color:#e5e7eb;overflow:auto}
.cms-markdown code{padding:.15em .35em;border-radius:.35em;background:rgba(127,127,127,.12)}
.cms-markdown pre code{padding:0;background:transparent}
.cms-markdown blockquote{margin:1.4rem 0;padding:.35rem 1rem;border-left:4px solid var(--bs-primary);color:var(--bs-secondary-color)}
.cms-markdown table{display:block;max-width:100%;overflow:auto;border-collapse:collapse}
.cms-markdown th,.cms-markdown td{padding:.7rem;border:1px solid var(--bs-border-color)}
</style>
HTML;
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>

<main class="content-page-shell <?= e($pageContainerClass) ?>">
    <article class="content-page-card <?= $pageTemplate === 'wide' ? 'is-wide' : '' ?> <?= $pageTemplate === 'landing' ? 'is-landing' : '' ?>">
        <header>
            <span class="content-page-eyebrow"><i class="bi bi-file-earmark-text"></i>独立页面</span>
            <h1 class="content-page-title"><?= e($contentPage['title'] ?? '') ?></h1>
            <?php if (!empty($contentPage['summary'])): ?>
                <p class="content-page-summary"><?= e($contentPage['summary']) ?></p>
            <?php endif; ?>
            <div class="content-page-meta">
                <?php if (!empty($contentPage['updated_at'])): ?>
                    <span><i class="bi bi-clock-history me-1"></i>更新于 <?= e(date('Y-m-d', strtotime($contentPage['updated_at']))) ?></span>
                <?php endif; ?>
            </div>
        </header>

        <div id="content-page-body" class="cms-markdown" aria-live="polite"></div>
        <noscript>
            <div class="cms-markdown"><?= nl2br(e($contentPage['content'] ?? '')) ?></div>
        </noscript>
    </article>
</main>

<script src="/assets/js/marked.min.js"></script>
<script>
(function () {
    const target = document.getElementById('content-page-body');
    const source = <?= js($contentPage['content'] ?? '') ?>;
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
