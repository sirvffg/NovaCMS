<?php
/**
 * 网站配置控制器
 * 处理配置页面的逻辑
 */

function handleConfigPage($db) {
    $message = '';
    $error = '';
    $success = '';

    // 处理表单提交
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF验证
        if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
            $error = '安全验证失败，请刷新页面后重试';
        } else {
        $website_name = $_POST['website_name'] ?? '';
        $website_author = $_POST['website_author'] ?? '';
        $description = $_POST['description'] ?? '';
        $robot_description = $_POST['robot_description'] ?? '';
        $contact_email = $_POST['contact_email'] ?? '';
        $contact_qq = $_POST['contact_qq'] ?? '';
        $social_wechat = $_POST['social_wechat'] ?? '';
        $social_douyin = $_POST['social_douyin'] ?? '';
        $social_kuaishou = $_POST['social_kuaishou'] ?? '';
        $social_bilibili = $_POST['social_bilibili'] ?? '';
        $social_xiaohongshu = $_POST['social_xiaohongshu'] ?? '';
        $social_whatsapp = $_POST['social_whatsapp'] ?? '';
        $social_x = $_POST['social_x'] ?? '';
        $social_discord = $_POST['social_discord'] ?? '';
        $social_youtube = $_POST['social_youtube'] ?? '';
        $social_github = $_POST['social_github'] ?? '';
        $logo = $_POST['logo'] ?? '';

        // 邮件配置
        $email_mode = $_POST['email_mode'] ?? 'test';
        $smtp_host = $_POST['smtp_host'] ?? 'smtp.qq.com';
        $smtp_port = !empty($_POST['smtp_port']) ? (int)$_POST['smtp_port'] : 465;
        $smtp_username = $_POST['smtp_username'] ?? '';
        $smtp_password = $_POST['smtp_password'] ?? '';
        $smtp_encryption = $_POST['smtp_encryption'] ?? 'ssl';
        $smtp_from_name = $_POST['smtp_from_name'] ?? 'LyGalaxy';
        $allowed_email_domains = $_POST['allowed_email_domains'] ?? 'qq.com,vip.qq.com,foxmail.com,163.com,126.com,yeah.net,sina.com,sina.cn,sohu.com,139.com,aliyun.com,gmail.com,outlook.com,hotmail.com,live.com,yahoo.com,yahoo.co.jp,icloud.com,proton.me,protonmail.com,mail.com,gmx.com,gmx.de';
        
        // 网站开办时间
        $website_start_time = $_POST['website_start_time'] ?? null;
        if (!empty($website_start_time)) {
            $website_start_time = date('Y-m-d H:i:s', strtotime($website_start_time));
        }
        
        // 备案信息配置
        $icp_record = $_POST['icp_record'] ?? '';
        $public_security_record = $_POST['public_security_record'] ?? '';

        // 页脚配置
        $footer_extra = $_POST['footer_extra'] ?? '';

        // 跳转白名单配置
        $redirect_whitelist = $_POST['redirect_whitelist'] ?? '';
        
        // 支付网关配置
        $epay_url = $_POST['epay_url'] ?? '';
        $epay_pid = $_POST['epay_pid'] ?? '';
        $epay_key = $_POST['epay_key'] ?? '';

        // 登录安全配置
        $max_devices = max(1, min(10, intval($_POST['max_devices'] ?? 2)));
        $remember_duration = max(1, min(365, intval($_POST['remember_duration'] ?? 30)));

        // 评论设置
        $comment_login_required = isset($_POST['comment_login_required']) ? 1 : 0;
        $comment_private_enabled = isset($_POST['comment_private_enabled']) ? 1 : 0;
        $comment_avatar_api = trim($_POST['comment_avatar_api'] ?? '');
        if ($comment_avatar_api === '') {
            $comment_avatar_api = 'https://cravatar.cn/avatar/{hash}?s={size}&d=mm';
        }
        try {
            // Check and add social_whatsapp
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'social_whatsapp'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN social_whatsapp VARCHAR(255) DEFAULT '' AFTER social_xiaohongshu");
            }
            
            // Check and add social_x
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'social_x'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN social_x VARCHAR(255) DEFAULT '' AFTER social_whatsapp");
            }

            // Check and add social_discord
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'social_discord'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN social_discord VARCHAR(255) DEFAULT '' AFTER social_x");
            }

            // Check and add social_youtube
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'social_youtube'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN social_youtube VARCHAR(255) DEFAULT '' AFTER social_discord");
            }

            // Check and add footer_extra
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'footer_extra'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN footer_extra TEXT COMMENT '页脚附加信息' AFTER epay_key");
            }

            // Check and add redirect_whitelist
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'redirect_whitelist'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN redirect_whitelist TEXT COMMENT '跳转白名单域名' AFTER footer_extra");
            }
            
            // Check and add epay settings
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'epay_url'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN epay_url VARCHAR(255) DEFAULT '' COMMENT '易支付接口地址' AFTER redirect_whitelist");
                $db->exec("ALTER TABLE website_config ADD COLUMN epay_pid VARCHAR(50) DEFAULT '' COMMENT '易支付商户ID' AFTER epay_url");
                $db->exec("ALTER TABLE website_config ADD COLUMN epay_key VARCHAR(255) DEFAULT '' COMMENT '易支付商户密钥' AFTER epay_pid");
            }
            
            // Check and add allowed_email_domains
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'allowed_email_domains'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN allowed_email_domains TEXT COMMENT '允许注册的邮箱后缀' AFTER smtp_from_name");
            }

            // Check and add max_devices
            try {
                $db->exec("ALTER TABLE website_config ADD COLUMN max_devices INT DEFAULT 2 COMMENT '单用户最大同时在线设备数' AFTER epay_key");
            } catch (Exception $e) {}

            // Check and add remember_duration
            try {
                $db->exec("ALTER TABLE website_config ADD COLUMN remember_duration INT DEFAULT 30 COMMENT '记住我有效期（天）' AFTER max_devices");
            } catch (Exception $e) {}

            // 协议与政策配置
            $terms_content = $_POST['terms_content'] ?? '';
            $privacy_content = $_POST['privacy_content'] ?? '';

            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'terms_content'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN terms_content LONGTEXT COMMENT '服务条款内容(支持HTML)' AFTER music_auto_pause");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'privacy_content'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN privacy_content LONGTEXT COMMENT '隐私政策内容(支持HTML)' AFTER terms_content");
            }

            // 评论设置字段
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'comment_login_required'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN comment_login_required TINYINT(1) NOT NULL DEFAULT 0 COMMENT '评论是否需要登录' AFTER active_theme");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'comment_private_enabled'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN comment_private_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否开启私密评论' AFTER comment_login_required");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'comment_avatar_api'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN comment_avatar_api VARCHAR(255) NOT NULL DEFAULT 'https://cravatar.cn/avatar/{hash}?s={size}&d=mm' COMMENT '评论头像API' AFTER comment_private_enabled");
            }

            // 保存数据库配置
            $stmt = $db->prepare("UPDATE website_config SET website_name=?, website_author=?, description=?, robot_description=?, contact_email=?, contact_qq=?, social_wechat=?, social_douyin=?, social_kuaishou=?, social_bilibili=?, social_xiaohongshu=?, social_whatsapp=?, social_x=?, social_discord=?, social_youtube=?, social_github=?, logo=?, email_mode=?, smtp_host=?, smtp_port=?, smtp_username=?, smtp_password=?, smtp_encryption=?, smtp_from_name=?, allowed_email_domains=?, website_start_time=?, footer_extra=?, redirect_whitelist=?, epay_url=?, epay_pid=?, epay_key=?, max_devices=?, remember_duration=?, icp_record=?, public_security_record=?, terms_content=?, privacy_content=?, comment_login_required=?, comment_private_enabled=?, comment_avatar_api=? WHERE id=1");
            $stmt->execute([$website_name, $website_author, $description, $robot_description, $contact_email, $contact_qq, $social_wechat, $social_douyin, $social_kuaishou, $social_bilibili, $social_xiaohongshu, $social_whatsapp, $social_x, $social_discord, $social_youtube, $social_github, $logo, $email_mode, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $smtp_from_name, $allowed_email_domains, $website_start_time, $footer_extra, $redirect_whitelist, $epay_url, $epay_pid, $epay_key, $max_devices, $remember_duration, $icp_record, $public_security_record, $terms_content, $privacy_content, $comment_login_required, $comment_private_enabled, $comment_avatar_api]);

            $success = '配置已保存';
        } catch (Exception $e) {
            $error = '保存失败: ' . $e->getMessage();
        }
        } // 关闭 CSRF 验证的 else
    }

    $config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

    // 从数据库读取备案配置
    $beianConfig = [
        'ICP_RECORD' => $config['icp_record'] ?? '',
        'PUBLIC_SECURITY_RECORD' => $config['public_security_record'] ?? ''
    ];

    return [
        'config' => $config,
        'beianConfig' => $beianConfig,
        'success' => $success,
        'error' => $error
    ];
}
