<?php
/**
 * 评论功能相关函数 - 邮件模板重构版
 */

// 加载邮件配置
require_once __DIR__ . '/email_config.php';

/**
 * 发送评论通知邮件
 */
function sendCommentNotificationEmail($toEmail, $toName, $type, $data) {
    $siteName = getSiteName();
    
    // 测试模式下只记录日志
    if (EMAIL_MODE === 'test') {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $type,
            'to_email' => $toEmail,
            'to_name' => $toName,
            'data' => $data,
            'mode' => 'test',
            'status' => 'test_mode'
        ];
        error_log("评论通知邮件 [测试模式]: " . json_encode($log_entry, JSON_UNESCAPED_UNICODE));
        return true;
    }
    
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 10;
        
        $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        
        $mail->isHTML(true);
        
        if ($type === 'new_comment') {
            // 新评论通知站长
            $mail->Subject = '📝 您收到一条新评论 - ' . $siteName;
            $mail->Body = getNewCommentEmailTemplate($siteName, $data);
            $mail->AltBody = "您收到一条新评论\n\n文章：{$data['post_title']}\n评论者：{$data['commenter_name']}\n内容：{$data['comment_content']}";
        } else {
            // 回复通知评论者
            $mail->Subject = '🔔 您的评论收到回复 - ' . $siteName;
            $mail->Body = getReplyNotificationEmailTemplate($siteName, $data);
            $mail->AltBody = "您的评论收到回复\n\n文章：{$data['post_title']}\n回复者：{$data['replier_name']}\n回复内容：{$data['reply_content']}";
        }
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("评论通知邮件发送失败: " . $e->getMessage());
        return false;
    }
}

/**
 * [重构] 新评论通知站长的邮件模板
 */
function getNewCommentEmailTemplate($siteName, $data) {
    $postUrl = isset($_SERVER['HTTP_HOST']) ? 
        ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/blog.php?id=' . $data['post_id']) 
        : $data['post_url'];
    
    return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        .wrapper { background-color: #f4f7f9; padding: 30px 15px; font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .header { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); padding: 30px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 22px; font-weight: 600; }
        .body { padding: 30px; line-height: 1.6; color: #334155; }
        .label { font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
        .post-title { font-size: 16px; font-weight: bold; color: #1e293b; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }
        .comment-box { background: #f8fafc; border-radius: 8px; padding: 20px; border-left: 4px solid #4f46e5; margin: 20px 0; }
        .author { font-weight: bold; color: #4f46e5; display: block; margin-bottom: 8px; }
        .content { color: #475569; font-size: 15px; white-space: pre-wrap; }
        .meta { font-size: 12px; color: #94a3b8; margin-top: 12px; }
        .btn { display: inline-block; background: #4f46e5; color: #ffffff !important; padding: 12px 25px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 14px; margin-top: 10px; }
        .footer { text-align: center; padding: 20px; color: #94a3b8; font-size: 12px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header"><h1>📝 收到新评论通知</h1></div>
            <div class="body">
                <p>Hi 站长，您的文章收到了一封新评论：</p>
                <div class="label">所属文章</div>
                <div class="post-title">' . htmlspecialchars($data['post_title']) . '</div>
                
                <div class="comment-box">
                    <span class="author">👤 ' . htmlspecialchars($data['commenter_name']) . ' 说：</span>
                    <div class="content">' . nl2br(htmlspecialchars($data['comment_content'])) . '</div>
                    <div class="meta">📍 IP: ' . htmlspecialchars($data['ip_address'] ?? '未知') . ' | ' . date('Y-m-d H:i') . '</div>
                </div>
                
                <div style="text-align: center;">
                    <a href="' . htmlspecialchars($postUrl) . '" class="btn">立即查看并回复</a>
                </div>
            </div>
            <div class="footer">
                此邮件由 ' . htmlspecialchars($siteName) . ' 自动发送
            </div>
        </div>
    </div>
</body>
</html>';
}

/**
 * [重构] 回复通知评论者的邮件模板
 */
function getReplyNotificationEmailTemplate($siteName, $data) {
    $postUrl = isset($_SERVER['HTTP_HOST']) ? 
        ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/blog.php?id=' . $data['post_id']) 
        : $data['post_url'];
    
    return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        .wrapper { background-color: #f1f5f9; padding: 30px 15px; font-family: "Inter", system-ui, sans-serif; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%); padding: 35px 20px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; }
        .body { padding: 30px; line-height: 1.7; color: #334155; }
        .greeting { font-size: 18px; font-weight: bold; margin-bottom: 15px; color: #1e293b; }
        .reply-card { background: #f0f9ff; border-radius: 12px; padding: 20px; margin: 20px 0; border: 1px solid #e0f2fe; }
        .replier { font-weight: bold; color: #0284c7; margin-bottom: 10px; display: block; }
        .reply-content { font-size: 16px; color: #1e293b; }
        .original-quote { border-left: 3px solid #e2e8f0; padding-left: 15px; margin-top: 15px; color: #64748b; font-size: 14px; font-style: italic; }
        .btn { display: inline-block; background: #2563eb; color: #ffffff !important; padding: 12px 30px; border-radius: 30px; text-decoration: none; font-weight: bold; margin-top: 20px; }
        .footer { text-align: center; padding: 20px; color: #94a3b8; font-size: 12px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header"><h1>🔔 您的评论有新回复</h1></div>
            <div class="body">
                <div class="greeting">您好，' . htmlspecialchars($data['commenter_name']) . '！</div>
                <p>您在文章<strong>《' . htmlspecialchars($data['post_title']) . '》</strong>下的评论有了新动态：</p>
                
                <div class="reply-card">
                    <span class="replier">👤 ' . htmlspecialchars($data['replier_name']) . ' 回复道：</span>
                    <div class="reply-content">' . nl2br(htmlspecialchars($data['reply_content'])) . '</div>
                    <div class="original-quote">
                        “' . mb_substr(htmlspecialchars($data['original_comment']), 0, 80) . '...”
                    </div>
                </div>
                
                <div style="text-align: center;">
                    <a href="' . htmlspecialchars($postUrl) . '" class="btn">点击查看对话</a>
                </div>
            </div>
            <div class="footer">
                来自 ' . htmlspecialchars($siteName) . ' 的自动通知
            </div>
        </div>
    </div>
</body>
</html>';
}

/**
 * 获取文章评论列表
 */
function getPostComments($post_id, $status = 'approved') {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT c.*, a.username as admin_username, a.role as user_role,
               (SELECT COUNT(*) FROM blog_comments WHERE parent_id = c.id AND status = 'approved') as reply_count
        FROM blog_comments c
        LEFT JOIN admins a ON c.user_id = a.id
        WHERE c.post_id = ? AND c.status = ? AND c.parent_id IS NULL
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$post_id, $status]);
    $comments = $stmt->fetchAll();
    
    foreach ($comments as &$comment) {
        if (empty($comment['username'])) {
            $comment['username'] = '匿名用户';
        }
        $comment['replies'] = ($comment['reply_count'] > 0) ? getCommentReplies($comment['id']) : [];
    }
    return $comments;
}

/**
 * 获取评论回复（递归获取所有层级的回复）
 */
function getCommentReplies($parent_id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT c.*, a.username as admin_username, a.role as user_role
        FROM blog_comments c
        LEFT JOIN admins a ON c.user_id = a.id
        WHERE c.parent_id = ? AND c.status = 'approved'
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$parent_id]);
    $replies = $stmt->fetchAll();
    
    foreach ($replies as &$reply) {
        if (empty($reply['username'])) {
            $reply['username'] = '匿名用户';
        }
        $reply['replies'] = getCommentReplies($reply['id']);
    }
    return $replies;
}

/**
 * 添加评论
 */
function addComment($post_id, $content, $parent_id = null) {
    $db = getDB();
    if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
        return ['success' => false, 'message' => '请先登录后再评论'];
    }
    
    $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $rateLimit = checkRateLimit('comment', 10, 300);
    if (!$rateLimit['allowed']) return ['success' => false, 'message' => $rateLimit['message']];
    
    if ($user_id) {
        $stmt = $db->prepare("SELECT username, email FROM admins WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_info = $stmt->fetch();
        if ($user_info) {
            $username = $user_info['username'];
            $email = $user_info['email'];
        } else {
            return ['success' => false, 'message' => '用户信息获取失败'];
        }
    }
    
    $content = trim($content);
    if (empty($content)) return ['success' => false, 'message' => '评论内容不能为空'];
    if (strlen($content) > 1000) return ['success' => false, 'message' => '评论内容不能超过1000个字符'];
    
    try {
        $stmt = $db->prepare("
            INSERT INTO blog_comments (post_id, user_id, username, email, content, parent_id, status, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, ?)
        ");
        $parent_id = $parent_id ? intval($parent_id) : null;
        $result = $stmt->execute([$post_id, $user_id, $username, $email, $content, $parent_id, $ip_address, $user_agent]);
        
        if ($result) {
            $comment_id = $db->lastInsertId();
            $stmt = $db->prepare("
                SELECT c.*, a.username as admin_username, a.role as user_role, p.title as post_title
                FROM blog_comments c
                LEFT JOIN admins a ON c.user_id = a.id
                LEFT JOIN blog_posts p ON c.post_id = p.id
                WHERE c.id = ?
            ");
            $stmt->execute([$comment_id]);
            $comment = $stmt->fetch();

            if ($comment) {
                $comment['username'] = htmlspecialchars($comment['username'], ENT_QUOTES, 'UTF-8');
                $comment['content'] = htmlspecialchars($comment['content'], ENT_QUOTES, 'UTF-8');
            }

            createCommentNotification($post_id, $comment_id, $content, $parent_id);
            return ['success' => true, 'comment_id' => $comment_id, 'comment' => $comment, 'message' => '评论成功'];
        }
    } catch (Exception $e) {
        error_log('Create comment error: ' . $e->getMessage());
        return ['success' => false, 'message' => '评论提交失败，请稍后重试'];
    }
}

/**
 * 创建评论通知（同时发送邮件）
 */
function createCommentNotification($post_id, $comment_id, $content, $parent_id = null) {
    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT title FROM blog_posts WHERE id = ?");
        $stmt->execute([$post_id]);
        $post = $stmt->fetch();
        if (!$post) return false;
        
        $stmt = $db->prepare("SELECT * FROM blog_comments WHERE id = ?");
        $stmt->execute([$comment_id]);
        $current_comment = $stmt->fetch();
        if (!$current_comment) return false;
        
        $stmt = $db->prepare("SELECT * FROM admins WHERE role = 'admin'");
        $stmt->execute();
        $admins = $stmt->fetchAll();
        
        if ($parent_id) {
            // 回复逻辑
            $stmt = $db->prepare("SELECT c.*, a.username as admin_username FROM blog_comments c LEFT JOIN admins a ON c.user_id = a.id WHERE c.id = ?");
            $stmt->execute([$parent_id]);
            $parent_comment = $stmt->fetch();
            
            if ($parent_comment) {
                $type = 'reply';
                $title = '收到新回复';
                $notif_content = "有人回复了你的评论：" . mb_substr($content, 0, 50) . "...";
                
                if ($parent_comment['user_id']) {
                    $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, content, related_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$parent_comment['user_id'], $type, $title, $notif_content, $comment_id]);
                }

                $reply_email = $parent_comment['email'] ?? null;
                $is_self = (strval($current_comment['user_id'] ?? '') === strval($parent_comment['user_id'] ?? ''));
                
                if ($reply_email && !$is_self) {
                    $emailData = [
                        'post_id' => $post_id,
                        'post_title' => $post['title'],
                        'post_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/blog.php?id=' . $post_id,
                        'commenter_name' => $parent_comment['username'] ?? '用户',
                        'replier_name' => $current_comment['username'] ?? '管理员',
                        'reply_content' => $content,
                        'original_comment' => $parent_comment['content'] ?? ''
                    ];
                    sendCommentNotificationEmail($reply_email, $parent_comment['username'], 'reply', $emailData);
                }
            }
        } else {
            // 新评论通知管理员
            foreach ($admins as $admin) {
                $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, content, related_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$admin['id'], 'comment', '收到新评论', "文章收到新评论：" . mb_substr($content, 0, 50), $comment_id]);
                
                if ($admin['email']) {
                    $emailData = [
                        'post_id' => $post_id,
                        'post_title' => $post['title'],
                        'post_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/blog.php?id=' . $post_id,
                        'commenter_name' => $current_comment['username'] ?? '访客',
                        'comment_content' => $content,
                        'ip_address' => $current_comment['ip_address'] ?? ''
                    ];
                    sendCommentNotificationEmail($admin['email'], $admin['username'], 'new_comment', $emailData);
                }
            }
        }
        return true;
    } catch (Exception $e) {
        error_log("创建评论通知失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 删除评论
 */
function deleteComment($comment_id, $user_id = null) {
    $db = getDB();
    $current_user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
    
    if (!$current_user_id) return ['success' => false, 'message' => '没有权限'];
    
    // 直接从 admins 表查询用户角色
    $user_role = 'user';
    $stmt = $db->prepare("SELECT role FROM admins WHERE id = ?");
    $stmt->execute([$current_user_id]);
    $adminRow = $stmt->fetch();
    if ($adminRow) {
        $user_role = $adminRow['role'];
    }
    
    try {
        $stmt = $db->prepare("SELECT user_id FROM blog_comments WHERE id = ?");
        $stmt->execute([$comment_id]);
        $comment = $stmt->fetch();
        
        if (!$comment) return ['success' => false, 'message' => '评论不存在'];
        if ($user_role !== 'admin' && $comment['user_id'] != $current_user_id) return ['success' => false, 'message' => '无权操作'];
        
        $db->prepare("DELETE FROM blog_comments WHERE parent_id = ?")->execute([$comment_id]);
        $db->prepare("DELETE FROM blog_comments WHERE id = ?")->execute([$comment_id]);
        return ['success' => true, 'message' => '已删除'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => '删除失败'];
    }
}

/**
 * 获取评论数量
 */
function getCommentCount($post_id, $status = 'approved') {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM blog_comments WHERE post_id = ? AND status = ?");
    $stmt->execute([$post_id, $status]);
    return $stmt->fetch()['count'];
}

/**
 * 格式化评论时间
 */
function formatCommentTime($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    if ($diff < 60) return '刚刚';
    if ($diff < 3600) return floor($diff / 60) . '分钟前';
    if ($diff < 86400) return floor($diff / 3600) . '小时前';
    if ($diff < 2592000) return floor($diff / 86400) . '天前';
    return date('Y-m-d', $timestamp);
}

/**
 * 清理过期的评论通知
 */
function cleanupOldNotifications() {
    $db = getDB();
    $db->prepare("DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->execute();
    $db->prepare("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)")->execute();
}
?>
