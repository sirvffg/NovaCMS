<?php
$adminShellJsVersion = (string)(@filemtime(__DIR__ . '/../../assets/js/admin-shell.js') ?: 1);
$adminPjaxJsVersion = (string)(@filemtime(__DIR__ . '/../../assets/js/admin-pjax.js') ?: 1);
?>
<?php
// Flush the pjax-container output buffer, converting all <script> tags to
// type="text/pjax-script" so the browser doesn't execute them synchronously.
// admin-pjax.js will execute them after the shell renders.
$pjaxContent = ob_get_clean();
echo preg_replace('/<script\b(?![^>]*type\s*=)/i', '<script type="text/pjax-script"', $pjaxContent);
?>
            </div><!-- /#pjax-container -->
        </main><!-- /.content-body -->
    </div><!-- /.main-content -->

    <?php if (empty($skip_bootstrap_js)): ?>
    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js') ?>"></script>
    <?php endif; ?>
    <script src="/assets/js/admin-shell.js?v=<?= e($adminShellJsVersion) ?>"></script>
    <script src="/assets/js/admin-pjax.js?v=<?= e($adminPjaxJsVersion) ?>"></script>

    <div id="page-scripts" data-page-scripts>
    <?php if (!empty($extra_scripts)): ?>
    <?= preg_replace('/<script\b(?![^>]*type\s*=)/i', '<script type="text/pjax-script"', $extra_scripts) ?>
    <?php endif; ?>
    </div>
</body>
</html>
