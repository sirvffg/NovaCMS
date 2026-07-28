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
        $website_intro = $_POST['website_intro'] ?? '';
        $use_local_hitokoto = isset($_POST['use_local_hitokoto']) ? 1 : 0;
        $website_announcement = $_POST['website_announcement'] ?? '';
        $website_announcement_date = !empty($_POST['website_announcement_date']) ? date('Y-m-d H:i:s', strtotime($_POST['website_announcement_date'])) : null;
        $website_announcement_popup = isset($_POST['website_announcement_popup']) ? 1 : 0;
        $website_announcement_enable = isset($_POST['website_announcement_enable']) ? 1 : 0;
        $website_description = $_POST['website_description'] ?? '';
        $website_detail = $_POST['website_detail'] ?? '';
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
        $home_bg_image = $_POST['home_bg_image'] ?? '';
        $home_bg_video = $_POST['home_bg_video'] ?? '';
        $use_bing_bg = isset($_POST['use_bing_bg']) ? 1 : 0;
        $bing_api = $_POST['bing_api'] ?? '';
        
        // 音乐播放器配置
        $music_enabled = isset($_POST['music_enabled']) ? 1 : 0;
        $music_playlist_id = $_POST['music_playlist_id'] ?? '';
        $music_song_id = $_POST['music_song_id'] ?? '';
        $music_position = $_POST['music_position'] ?? 'bottom-left';
        $music_embed = isset($_POST['music_embed']) ? 1 : 0;
        $music_theme = $_POST['music_theme'] ?? 'auto';
        $music_default_minimized = isset($_POST['music_default_minimized']) ? 1 : 0;
        $music_lyric = isset($_POST['music_lyric']) ? 1 : 0;
        $music_autoplay = isset($_POST['music_autoplay']) ? 1 : 0;
        $music_auto_pause = isset($_POST['music_auto_pause']) ? 1 : 0;
        
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

        // 新年祝福配置
        $newyear_enable = isset($_POST['newyear_enable']) ? 1 : 0;
        $newyear_message = $_POST['newyear_message'] ?? '';
        $newyear_video = '';
        $newyear_start_time = !empty($_POST['newyear_start_time']) ? date('Y-m-d H:i:s', strtotime($_POST['newyear_start_time'])) : null;
        $newyear_end_time = !empty($_POST['newyear_end_time']) ? date('Y-m-d H:i:s', strtotime($_POST['newyear_end_time'])) : null;

        // 登录安全配置
        $max_devices = max(1, min(10, intval($_POST['max_devices'] ?? 2)));
        $remember_duration = max(1, min(365, intval($_POST['remember_duration'] ?? 30)));
        try {
            // Check and add newyear_video column if not exists
            // This must be done BEFORE trying to SELECT or UPDATE it
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'newyear_video'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN newyear_video VARCHAR(255) DEFAULT '' COMMENT '新年祝福视频路径'");
            }
            
            // Check and add newyear_enable
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'newyear_enable'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN newyear_enable TINYINT(1) DEFAULT 0 COMMENT '新年祝福开关'");
            }

            // Check and add newyear_message
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'newyear_message'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN newyear_message TEXT COMMENT '新年祝福语'");
            }

            // Check and add newyear_start_time
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'newyear_start_time'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN newyear_start_time DATETIME DEFAULT NULL COMMENT '新年祝福开始时间'");
            }

            // Check and add newyear_end_time
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'newyear_end_time'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN newyear_end_time DATETIME DEFAULT NULL COMMENT '新年祝福结束时间'");
            }

            // 处理新年祝福视频上传
            $currentConfig = $db->query("SELECT newyear_video FROM website_config WHERE id=1")->fetch();
            $newyear_video = $currentConfig['newyear_video'] ?? '';
            
            // 如果前端传来了 newyear_video 字段（隐藏域），说明是通过异步上传的，或者没有变化
            if (isset($_POST['newyear_video'])) {
                // 如果不是删除操作，且前端传来了新的视频路径，则更新
                // 注意：如果 newyear_video 为空，可能是删除了，也可能是本来就没有
                if (!isset($_POST['delete_newyear_video']) || $_POST['delete_newyear_video'] != '1') {
                    $newyear_video = $_POST['newyear_video'];
                }
            }

            if (isset($_POST['delete_newyear_video']) && $_POST['delete_newyear_video'] == '1') {
                if (!empty($newyear_video)) {
                    // 如果是删除，可能需要清理文件，但如果是异步上传替换的，旧文件可能已经被替换了
                    // 这里为了安全，如果是异步上传模式，文件清理最好在上传接口做，或者这里只清空数据库字段
                    $newyear_video = '';
                }
            }

            // 兼容旧的同步上传方式 (虽然前端改了，但保留后端逻辑也没坏处，或者可以直接移除)
            if (isset($_FILES['newyear_video_file']) && $_FILES['newyear_video_file']['error'] === UPLOAD_ERR_OK) {
                // ... (旧的上传逻辑，如果前端不再使用 input type="file" 提交，这部分不会执行)
            }

            // Check and add website_announcement
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'website_announcement'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN website_announcement TEXT COMMENT '网站公告(支持Markdown)' AFTER website_intro");
            }

            // Check and add website_announcement_date
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'website_announcement_date'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN website_announcement_date DATETIME DEFAULT NULL COMMENT '公告发布日期' AFTER website_announcement");
            }

            // Check and add website_announcement_popup
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'website_announcement_popup'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN website_announcement_popup TINYINT(1) DEFAULT 0 COMMENT '是否弹窗展示公告' AFTER website_announcement_date");
            }

            // Check and add website_announcement_enable
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'website_announcement_enable'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN website_announcement_enable TINYINT(1) DEFAULT 1 COMMENT '是否开启公告展示' AFTER website_announcement_popup");
            }

            // Check and add use_local_hitokoto
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'use_local_hitokoto'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN use_local_hitokoto TINYINT(1) DEFAULT 0 COMMENT '是否使用本站一言' AFTER website_intro");
            }

            // Check and add column if not exists
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'website_detail'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN website_detail LONGTEXT COMMENT '个人详细介绍(支持Markdown)' AFTER website_description");
            }

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

            // Check and add music_default_minimized
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'music_default_minimized'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN music_default_minimized TINYINT(1) DEFAULT 0 COMMENT '默认最小化' AFTER music_theme");
            }

            // Check and add music_auto_pause
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'music_auto_pause'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN music_auto_pause TINYINT(1) DEFAULT 0 COMMENT '自动暂停' AFTER music_autoplay");
            }

            // Check and add footer_extra
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'footer_extra'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN footer_extra TEXT COMMENT '页脚附加信息' AFTER newyear_end_time");
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
                $db->exec("ALTER TABLE website_config ADD COLUMN terms_content LONGTEXT COMMENT '服务条款内容(支持HTML)' AFTER ai_summary_section_title");
            }
            $checkStmt = $db->query("SHOW COLUMNS FROM website_config LIKE 'privacy_content'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE website_config ADD COLUMN privacy_content LONGTEXT COMMENT '隐私政策内容(支持HTML)' AFTER terms_content");
            }

            // 保存数据库配置
            $stmt = $db->prepare("UPDATE website_config SET website_name=?, website_author=?, website_intro=?, use_local_hitokoto=?, website_announcement=?, website_announcement_date=?, website_announcement_popup=?, website_announcement_enable=?, website_description=?, website_detail=?, description=?, robot_description=?, contact_email=?, contact_qq=?, social_wechat=?, social_douyin=?, social_kuaishou=?, social_bilibili=?, social_xiaohongshu=?, social_whatsapp=?, social_x=?, social_discord=?, social_youtube=?, social_github=?, logo=?, home_bg_image=?, home_bg_video=?, use_bing_bg=?, bing_api=?, music_enabled=?, music_playlist_id=?, music_song_id=?, music_position=?, music_embed=?, music_theme=?, music_default_minimized=?, music_lyric=?, music_autoplay=?, music_auto_pause=?, email_mode=?, smtp_host=?, smtp_port=?, smtp_username=?, smtp_password=?, smtp_encryption=?, smtp_from_name=?, allowed_email_domains=?, website_start_time=?, newyear_enable=?, newyear_message=?, newyear_video=?, newyear_start_time=?, newyear_end_time=?, footer_extra=?, redirect_whitelist=?, epay_url=?, epay_pid=?, epay_key=?, max_devices=?, remember_duration=?, terms_content=?, privacy_content=? WHERE id=1");
            $stmt->execute([$website_name, $website_author, $website_intro, $use_local_hitokoto, $website_announcement, $website_announcement_date, $website_announcement_popup, $website_announcement_enable, $website_description, $website_detail, $description, $robot_description, $contact_email, $contact_qq, $social_wechat, $social_douyin, $social_kuaishou, $social_bilibili, $social_xiaohongshu, $social_whatsapp, $social_x, $social_discord, $social_youtube, $social_github, $logo, $home_bg_image, $home_bg_video, $use_bing_bg, $bing_api, $music_enabled, $music_playlist_id, $music_song_id, $music_position, $music_embed, $music_theme, $music_default_minimized, $music_lyric, $music_autoplay, $music_auto_pause, $email_mode, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $smtp_from_name, $allowed_email_domains, $website_start_time, $newyear_enable, $newyear_message, $newyear_video, $newyear_start_time, $newyear_end_time, $footer_extra, $redirect_whitelist, $epay_url, $epay_pid, $epay_key, $max_devices, $remember_duration, $terms_content, $privacy_content]);
            
            // 保存备案信息到配置文件
            $recordConfigPath = __DIR__ . '/../../config/RecordNumber.config';
            $recordConfig = "; /config/RecordNumber.config\n";
            $recordConfig .= "ICP_RECORD=" . trim($icp_record) . "\n";
            $recordConfig .= "PUBLIC_SECURITY_RECORD=" . trim($public_security_record) . "\n";
            
            // 确保目录存在
            if (!is_dir(dirname($recordConfigPath))) {
                mkdir(dirname($recordConfigPath), 0755, true);
            }
            
            file_put_contents($recordConfigPath, $recordConfig);
            
            $success = '配置已保存';
        } catch (Exception $e) {
            $error = '保存失败: ' . $e->getMessage();
        }
        } // 关闭 CSRF 验证的 else
    }

    $config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

    // 读取备案配置
    $beianConfig = [];
    $recordConfigPath = __DIR__ . '/../../config/RecordNumber.config';
    if (file_exists($recordConfigPath)) {
        $beianConfig = parse_ini_file($recordConfigPath);
    }

    return [
        'config' => $config,
        'beianConfig' => $beianConfig,
        'success' => $success,
        'error' => $error
    ];
}
