<style>
    footer {
        background-color: #ffffff !important; /* 使用白色背景 */
        color: #6c757d !important; /* 使用灰色文字 */
        border-top: 1px solid #dee2e6 !important; /* 添加顶部边框 */
        padding: 0.5rem 0; /* 减小上下内边距 */
        margin-top: 0 !important;
        position: relative;
        z-index: 10;
        font-size: 0.85rem;
        flex-shrink: 0;
    }
    
    footer .footer-links a,
    footer .footer-extra a {
        color: #6c757d; /* 链接默认为灰色 */
        text-decoration: none;
        transition: color 0.2s;
    }
    
    footer .footer-links a:hover,
    footer .footer-extra a:hover {
        color: #0d6efd; /* 悬停时变为蓝色 */
    }
    
    footer .footer-info {
        color: #6c757d; /* 信息文字为灰色 */
        margin-bottom: 0.25rem !important;
    }
</style>
<footer class="position-relative z-1">
    <div class="container">
        <div class="row align-items-center">
            <!-- 左侧所有信息 -->
            <div class="col-12">
                <p class="mb-1 footer-info">
                    &copy; 2019-<?= date('Y') ?> <?= e($config['website_name']) ?>. All rights reserved.
                </p>
                <div class="d-flex flex-wrap align-items-center gap-2 footer-links">
                    <!-- ICP备案号 -->
                    <?php if (!empty($config['icp_record'])): ?>
                    <a href="https://beian.miit.gov.cn/" target="_blank" title="工业和信息化部ICP/IP地址/域名信息备案管理系统">
                        <i class="bi bi-file-earmark-text me-1"></i><?= e($config['icp_record']) ?>
                    </a>
                    <?php endif; ?>

                    <!-- 公安网备案号 -->
                    <?php if (!empty($config['public_security_record'])): ?>
                    <a href="https://www.beian.gov.cn/portal/registerSystemInfo?recordcode=<?= urlencode(explode('备', $config['public_security_record'])[1] ?? '') ?>" target="_blank" title="公安部互联网站安全服务平台">
                        <i class="bi bi-shield-check me-1"></i><?= e($config['public_security_record']) ?>
                    </a>
                    <?php endif; ?>

                    <!-- 邮箱 -->
                    <?php if (!empty($config['contact_email'])): ?>
                    <a href="mailto:<?= e($config['contact_email']) ?>">
                        <i class="bi bi-envelope me-1"></i><?= e($config['contact_email']) ?>
                    </a>
                    <?php endif; ?>

                    <!-- 隐私声明 -->
                    <?php if (!empty($config['contact_email'])): ?>
                    <a href="/license/terms.php" target="_blank" title="隐私声明" class="text-nowrap d-block d-md-inline">
                        <i class="bi bi-shield-lock me-1"></i>隐私声明
                    </a>
                    <?php endif; ?>
                    
                </div>

                <!-- 页脚附加信息 -->
                <?php if (!empty($config['footer_extra'])): ?>
                <div class="footer-extra mt-1">
                    <?= $config['footer_extra'] ?>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</footer>

<!-- 链接蜜罐：真实用户不可见，机器人会自动抓取此链接 -->
<div style="display:none!important;visibility:hidden;height:0;overflow:hidden;position:absolute;left:-9999px;" aria-hidden="true">
    <a href="/wp-login.php" rel="nofollow">login</a>
    <a href="/admin/config.php" rel="nofollow">admin</a>
    <a href="/xmlrpc.php" rel="nofollow">api</a>
</div>

<!-- 静态资源调试信息 -->
<?php if (function_exists('getResourceMode')): ?>
<script>
    console.log('🔧 调试信息 - 静态资源加载模式: <?= getResourceMode() ?>');
</script>
<!-- [调试] 当前系统静态资源采用: <?= getResourceMode() ?> -->
<?php endif; ?>
