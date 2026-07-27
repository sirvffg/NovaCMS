        </div><!-- /.content-body -->
    </div><!-- /.main-content -->

    <?php if (empty($skip_bootstrap_js)): ?>
    <!-- Bootstrap JS -->
    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <?php endif; ?>

    <?php if (!empty($extra_scripts)): ?>
    <?= $extra_scripts ?>
    <?php endif; ?>

    <script>
        // Toggle sidebar (collapsed on desktop, slide on mobile)
        function toggleSidebar() {
            if (window.innerWidth <= 768) {
                document.body.classList.toggle('mobile-open');
            } else {
                document.body.classList.toggle('collapsed');
                var isCollapsed = document.body.classList.contains('collapsed');
                localStorage.setItem('sidebar_collapsed', isCollapsed);
            }
        }

        // Restore collapsed state from localStorage (desktop only)
        if (window.innerWidth > 768 && localStorage.getItem('sidebar_collapsed') === 'true') {
            document.body.classList.add('collapsed');
        }

        // Toggle submenu in sidebar
        function toggleSubmenu(el) {
            el.classList.toggle('open');
        }

        // Toast notification
        function showToast(message, type) {
            type = type || 'success';
            var toast = document.createElement('div');
            var bgColor = type === 'success' ? '#f6ffed' : (type === 'error' ? '#fff1f0' : '#e6f7ff');
            var borderColor = type === 'success' ? '#b7eb8f' : (type === 'error' ? '#ffa39e' : '#91d5ff');
            var textColor = type === 'success' ? '#52c41a' : (type === 'error' ? '#f5222d' : '#1890ff');
            toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;padding:10px 24px;border-radius:6px;' +
                'box-shadow:0 4px 12px rgba(0,0,0,0.12);transition:opacity 0.3s;font-size:14px;' +
                'background:' + bgColor + ';border:1px solid ' + borderColor + ';color:' + textColor + ';';
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(function () { toast.style.opacity = '0'; setTimeout(function () { toast.remove(); }, 300); }, 3000);
        }

        // Loading overlay
        function showLoading() {
            document.getElementById('loading-overlay').classList.add('active');
        }
        function hideLoading() {
            document.getElementById('loading-overlay').classList.remove('active');
        }

        // HTML escape
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Format file size
        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
        }
    </script>
</body>
</html>
