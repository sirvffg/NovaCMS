<?php
$adminShellJsVersion = (string)(@filemtime(__DIR__ . '/../../assets/js/admin-shell.js') ?: 1);
$adminPjaxJsVersion = (string)(@filemtime(__DIR__ . '/../../assets/js/admin-pjax.js') ?: 1);
?>
            </div><!-- /#pjax-container -->
        </main><!-- /.content-body -->
    </div><!-- /.main-content -->

    <?php if (empty($skip_bootstrap_js)): ?>
    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <?php endif; ?>
    <script src="/assets/js/admin-shell.js?v=<?= e($adminShellJsVersion) ?>"></script>
    <script src="/assets/js/admin-pjax.js?v=<?= e($adminPjaxJsVersion) ?>"></script>

    <div id="page-scripts" data-page-scripts>
    <?php if (!empty($extra_scripts)): ?>
    <?= $extra_scripts ?>
    <?php endif; ?>
    </div>
</body>
</html>
