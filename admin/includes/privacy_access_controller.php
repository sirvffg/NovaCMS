<?php
/**
 * 隐私内容访问记录控制器
 * 处理记录展示、授权和邮件发送逻辑
 */

function handlePrivacyAccessPage($db) {
    $message = '';
    $error = '';
    $success = '';

    // 处理AJAX请求
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json');
        
        if ($_POST['action'] === 'grant_access') {
            $accessId = (int)($_POST['access_id'] ?? 0);
            
            if ($accessId <= 0) {
                echo json_encode(['success' => false, 'message' => '参数错误，请重试']);
                exit;
            }
            
            // 执行更新
            $stmt = $db->prepare("UPDATE blog_privacy_access SET access_granted = 1, is_correct = 1 WHERE id = ?");
            $result = $stmt->execute([$accessId]);
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => '已成功授权访问权限']);
            } else {
                $errorInfo = $stmt->errorInfo();
                echo json_encode(['success' => false, 'message' => '授权失败: ' . ($errorInfo[2] ?? '未知错误')]);
            }
            exit;
        }
        
        if ($_POST['action'] === 'revoke_with_email') {
            $accessId = (int)($_POST['access_id'] ?? 0);
            $emailSubject = trim($_POST['email_subject'] ?? '');
            $emailBody = trim($_POST['email_body'] ?? '');

            if ($accessId <= 0) {
                echo json_encode(['success' => false, 'message' => '参数错误，请重试']);
                exit;
            }

            // 获取该记录及原用户信息
            $stmt = $db->prepare("
                SELECT p.id, p.user_id, p.answer, p.post_id, a.username, a.email
                FROM blog_privacy_access p
                LEFT JOIN admins a ON p.user_id = a.id
                WHERE p.id = ?
            ");
            $stmt->execute([$accessId]);
            $record = $stmt->fetch();

            if (!$record) {
                echo json_encode(['success' => false, 'message' => '记录不存在']);
                exit;
            }

            $originalUsername = $record['username'];
            $originalEmail = $record['email'];
            $originalAnswer = $record['answer'];

            // 发送封禁通知邮件给原用户
            if (!empty($originalEmail)) {
                try {
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    $config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
                    $siteName = $config['website_name'] ?? 'LyGalaxy';

                    $mail->SMTPDebug = 0;
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USERNAME;
                    $mail->Password   = SMTP_PASSWORD;
                    $mail->SMTPSecure = SMTP_ENCRYPTION;
                    $mail->Port       = SMTP_PORT;
                    $mail->CharSet    = 'UTF-8';
                    $mail->Timeout    = 10;

                    $mail->setFrom(SMTP_USERNAME, $siteName . ' - 客服团队');
                    $mail->addAddress($originalEmail, $originalUsername);
                    $mail->addReplyTo(SMTP_USERNAME, $siteName . ' - 客服团队');

                    $mail->isHTML(true);
                    $mail->Subject = !empty($emailSubject) ? $emailSubject : '隐私内容填写提醒 - ' . $siteName;
                    $mail->Body = getRevokeEmailTemplate($originalUsername, $siteName, $emailBody, $record['post_id']);

                    $mail->send();
                } catch (Exception $e) {
                    error_log("撤回通知邮件发送失败: " . $e->getMessage());
                }
            }

            // 执行撤回：查找或创建匿名用户
            $anonymousEmail = 'X#######@qq.com';
            $stmt = $db->prepare("SELECT id FROM admins WHERE username = '隐私表单重新填写' LIMIT 1");
            $stmt->execute([]);
            $existingUser = $stmt->fetch();

            if ($existingUser) {
                $anonymousUserId = $existingUser['id'];
            } else {
                $randomPassword = bin2hex(random_bytes(8));
                $hashedPassword = hashPassword($randomPassword);
                $stmt = $db->prepare("INSERT INTO admins (username, password, email, role, register_ip) VALUES (?, ?, ?, 'user', 'revoke')");
                $stmt->execute(['隐私表单重新填写', $hashedPassword, $anonymousEmail]);
                $anonymousUserId = $db->lastInsertId();
            }

            // 更新答案并转移记录
            $newAnswer = $originalAnswer . '    ' . $record['user_id'] . ':' . $originalUsername . ':' . $originalEmail;
            $stmt = $db->prepare("UPDATE blog_privacy_access SET user_id = ?, answer = ? WHERE id = ?");
            $stmt->execute([$anonymousUserId, $newAnswer, $accessId]);

            echo json_encode([
                'success' => true,
                'message' => '通知邮件已发送，回答已撤回，记录已转移到匿名用户'
            ]);
            exit;
        }

        // 单独撤回（不发邮件）
        if ($_POST['action'] === 'revoke_no_email') {
            $accessId = (int)($_POST['access_id'] ?? 0);

            if ($accessId <= 0) {
                echo json_encode(['success' => false, 'message' => '参数错误，请重试']);
                exit;
            }

            // 获取该记录信息
            $stmt = $db->prepare("
                SELECT p.id, p.user_id, p.answer, a.username, a.email
                FROM blog_privacy_access p
                LEFT JOIN admins a ON p.user_id = a.id
                WHERE p.id = ?
            ");
            $stmt->execute([$accessId]);
            $record = $stmt->fetch();

            if (!$record) {
                echo json_encode(['success' => false, 'message' => '记录不存在']);
                exit;
            }

            // 检查是否已撤回
            $isRevoked = ($record['username'] === '隐私表单重新填写' && $record['answer'] && preg_match('/\s{4}\d+:\S+:\S+@\S+$/', $record['answer']));
            if ($isRevoked) {
                echo json_encode(['success' => false, 'message' => '该记录已被撤回']);
                exit;
            }

            $originalAnswer = $record['answer'];

            // 获取或创建匿名用户
            $stmt = $db->prepare("SELECT id FROM admins WHERE username = '隐私表单重新填写' LIMIT 1");
            $stmt->execute([]);
            $existingUser = $stmt->fetch();

            if ($existingUser) {
                $anonymousUserId = $existingUser['id'];
            } else {
                $randomPassword = bin2hex(random_bytes(8));
                $hashedPassword = hashPassword($randomPassword);
                $stmt = $db->prepare("INSERT INTO admins (username, password, email, role, register_ip) VALUES (?, ?, ?, 'user', 'revoke')");
                $stmt->execute(['隐私表单重新填写', $hashedPassword, 'X#######@qq.com']);
                $anonymousUserId = $db->lastInsertId();
            }

            // 执行撤回
            $newAnswer = $originalAnswer . '    ' . $record['user_id'] . ':' . $record['username'] . ':' . $record['email'];
            $stmt = $db->prepare("UPDATE blog_privacy_access SET user_id = ?, answer = ? WHERE id = ?");
            $stmt->execute([$anonymousUserId, $newAnswer, $accessId]);

            echo json_encode([
                'success' => true,
                'message' => '回答已撤回，记录已转移到匿名用户'
            ]);
            exit;
        }
        
        if ($_POST['action'] === 'send_followup_email') {
            $accessId = (int)($_POST['access_id'] ?? 0);
            $customSubject = trim($_POST['custom_subject'] ?? '');
            $customMessage = trim($_POST['custom_message'] ?? '');
            $includeContactInfo = ($_POST['include_contact_info'] ?? '0') === '1';
            
            if ($accessId <= 0) {
                echo json_encode(['success' => false, 'message' => '参数错误，请重试']);
                exit;
            }
            
            // 获取用户和文章信息
            $stmt = $db->prepare("
                SELECT p.*, b.title as post_title, a.username, a.email
                FROM blog_privacy_access p
                LEFT JOIN blog_posts b ON p.post_id = b.id
                LEFT JOIN admins a ON p.user_id = a.id
                WHERE p.id = ?
            ");
            $stmt->execute([$accessId]);
            $record = $stmt->fetch();
            
            if (!$record) {
                echo json_encode(['success' => false, 'message' => '记录不存在']);
                exit;
            }
            
            if (empty($record['email'])) {
                echo json_encode(['success' => false, 'message' => '该用户未设置邮箱地址']);
                exit;
            }
            
            // 发送回访邮件
            $result = sendFollowupEmail($db, $record, $customSubject, $customMessage, $includeContactInfo);
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => '回访邮件已发送']);
            } else {
                echo json_encode(['success' => false, 'message' => '邮件发送失败，请重试']);
            }
            exit;
        }
        
        // 批量撤回
        if ($_POST['action'] === 'batch_revoke_with_email') {
            $idsJson = trim($_POST['ids'] ?? '');
            $emailSubject = trim($_POST['email_subject'] ?? '');
            $emailBody = trim($_POST['email_body'] ?? '');
            
            if (empty($idsJson)) {
                echo json_encode(['success' => false, 'message' => '请选择要撤回的记录']);
                exit;
            }
            
            $ids = json_decode($idsJson, true);
            if (!is_array($ids) || count($ids) === 0) {
                echo json_encode(['success' => false, 'message' => '无效的记录ID']);
                exit;
            }
            
            $successCount = 0;
            $failCount = 0;
            $emailSuccessCount = 0;
            $emailFailCount = 0;
            
            // 获取或创建匿名用户
            $stmt = $db->prepare("SELECT id FROM admins WHERE username = '隐私表单重新填写' LIMIT 1");
            $stmt->execute([]);
            $existingUser = $stmt->fetch();
            
            if ($existingUser) {
                $anonymousUserId = $existingUser['id'];
            } else {
                $randomPassword = bin2hex(random_bytes(8));
                $hashedPassword = hashPassword($randomPassword);
                $stmt = $db->prepare("INSERT INTO admins (username, password, email, role, register_ip) VALUES (?, ?, ?, 'user', 'revoke')");
                $stmt->execute(['隐私表单重新填写', $hashedPassword, 'X#######@qq.com']);
                $anonymousUserId = $db->lastInsertId();
            }
            
            $config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
            $siteName = $config['website_name'] ?? 'LyGalaxy';
            
            foreach ($ids as $accessId) {
                $accessId = (int)$accessId;
                if ($accessId <= 0) continue;
                
                // 获取该记录及原用户信息
                $stmt = $db->prepare("
                    SELECT p.id, p.user_id, p.answer, p.post_id, a.username, a.email
                    FROM blog_privacy_access p
                    LEFT JOIN admins a ON p.user_id = a.id
                    WHERE p.id = ?
                ");
                $stmt->execute([$accessId]);
                $record = $stmt->fetch();
                
                if (!$record) {
                    $failCount++;
                    continue;
                }
                
                // 检查是否已撤回
                $isRevoked = ($record['username'] === '隐私表单重新填写' && $record['answer'] && preg_match('/\s{4}\d+:\S+:\S+@\S+$/', $record['answer']));
                if ($isRevoked) {
                    $failCount++;
                    continue;
                }
                
                $originalUsername = $record['username'];
                $originalEmail = $record['email'];
                $originalAnswer = $record['answer'];
                
                // 发送封禁通知邮件
                if (!empty($originalEmail)) {
                    try {
                        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                        $mail->SMTPDebug = 0;
                        $mail->isSMTP();
                        $mail->Host       = SMTP_HOST;
                        $mail->SMTPAuth   = true;
                        $mail->Username   = SMTP_USERNAME;
                        $mail->Password   = SMTP_PASSWORD;
                        $mail->SMTPSecure = SMTP_ENCRYPTION;
                        $mail->Port       = SMTP_PORT;
                        $mail->CharSet    = 'UTF-8';
                        $mail->Timeout    = 10;

                        $mail->setFrom(SMTP_USERNAME, $siteName . ' - 客服团队');
                        $mail->addAddress($originalEmail, $originalUsername);
                        $mail->addReplyTo(SMTP_USERNAME, $siteName . ' - 客服团队');

                        $mail->isHTML(true);
                        $mail->Subject = !empty($emailSubject) ? $emailSubject : '隐私内容填写提醒 - ' . $siteName;
                        $mail->Body = getRevokeEmailTemplate($originalUsername, $siteName, $emailBody, $record['post_id']);

                        $mail->send();
                        $emailSuccessCount++;
                    } catch (Exception $e) {
                        error_log("批量撤回通知邮件发送失败: " . $e->getMessage());
                        $emailFailCount++;
                    }
                }
                
                // 执行撤回
                $newAnswer = $originalAnswer . '    ' . $record['user_id'] . ':' . $originalUsername . ':' . $originalEmail;
                $stmt = $db->prepare("UPDATE blog_privacy_access SET user_id = ?, answer = ? WHERE id = ?");
                $result = $stmt->execute([$anonymousUserId, $newAnswer, $accessId]);
                
                if ($result) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }
            
            $message = "批量撤回完成：成功 {$successCount} 条，失败 {$failCount} 条";
            if ($emailSuccessCount > 0 || $emailFailCount > 0) {
                $message .= "；邮件发送：成功 {$emailSuccessCount} 封，失败 {$emailFailCount} 封";
            }

            echo json_encode([
                'success' => $successCount > 0,
                'message' => $message
            ]);
            exit;
        }

        // 批量撤回（不发邮件）
        if ($_POST['action'] === 'batch_revoke_no_email') {
            $idsJson = trim($_POST['ids'] ?? '');

            if (empty($idsJson)) {
                echo json_encode(['success' => false, 'message' => '请选择要撤回的记录']);
                exit;
            }

            $ids = json_decode($idsJson, true);
            if (!is_array($ids) || count($ids) === 0) {
                echo json_encode(['success' => false, 'message' => '无效的记录ID']);
                exit;
            }

            $successCount = 0;
            $failCount = 0;

            // 获取或创建匿名用户
            $stmt = $db->prepare("SELECT id FROM admins WHERE username = '隐私表单重新填写' LIMIT 1");
            $stmt->execute([]);
            $existingUser = $stmt->fetch();

            if ($existingUser) {
                $anonymousUserId = $existingUser['id'];
            } else {
                $randomPassword = bin2hex(random_bytes(8));
                $hashedPassword = hashPassword($randomPassword);
                $stmt = $db->prepare("INSERT INTO admins (username, password, email, role, register_ip) VALUES (?, ?, ?, 'user', 'revoke')");
                $stmt->execute(['隐私表单重新填写', $hashedPassword, 'X#######@qq.com']);
                $anonymousUserId = $db->lastInsertId();
            }

            foreach ($ids as $accessId) {
                $accessId = (int)$accessId;
                if ($accessId <= 0) continue;

                // 获取该记录信息
                $stmt = $db->prepare("
                    SELECT p.id, p.user_id, p.answer, a.username, a.email
                    FROM blog_privacy_access p
                    LEFT JOIN admins a ON p.user_id = a.id
                    WHERE p.id = ?
                ");
                $stmt->execute([$accessId]);
                $record = $stmt->fetch();

                if (!$record) {
                    $failCount++;
                    continue;
                }

                // 检查是否已撤回
                $isRevoked = ($record['username'] === '隐私表单重新填写' && $record['answer'] && preg_match('/\s{4}\d+:\S+:\S+@\S+$/', $record['answer']));
                if ($isRevoked) {
                    $failCount++;
                    continue;
                }

                $originalUsername = $record['username'];
                $originalEmail = $record['email'];
                $originalAnswer = $record['answer'];

                // 执行撤回
                $newAnswer = $originalAnswer . '    ' . $record['user_id'] . ':' . $originalUsername . ':' . $originalEmail;
                $stmt = $db->prepare("UPDATE blog_privacy_access SET user_id = ?, answer = ? WHERE id = ?");
                $result = $stmt->execute([$anonymousUserId, $newAnswer, $accessId]);

                if ($result) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }

            echo json_encode([
                'success' => $successCount > 0,
                'message' => "批量撤回完成：成功 {$successCount} 条，失败 {$failCount} 条"
            ]);
            exit;
        }
    }

    // 获取所有隐私访问记录（过滤用户ID 1）
    $stmt = $db->prepare("
        SELECT p.*, b.title as post_title, b.privacy_type, b.category, a.username, a.role, a.email
        FROM blog_privacy_access p
        LEFT JOIN blog_posts b ON p.post_id = b.id
        LEFT JOIN admins a ON p.user_id = a.id
        WHERE p.user_id != 1
        ORDER BY p.created_at DESC
    ");
    $stmt->execute();
    $accessRecords = $stmt->fetchAll();

    // 获取所有付费访问记录（过滤用户ID 1）
    $stmt = $db->prepare("
        SELECT p.*, b.title as post_title, b.category, a.username, a.email
        FROM blog_paid_access p
        LEFT JOIN blog_posts b ON p.post_id = b.id
        LEFT JOIN admins a ON p.user_id = a.id
        WHERE p.user_id != 1
        ORDER BY p.created_at DESC
    ");
    $stmt->execute();
    $paidRecords = $stmt->fetchAll();

    // 统计数据
    $stats = [
        'total' => count($accessRecords),
        'granted' => 0,
        'pending' => 0,
        'revoked' => 0,
        'rejected' => 0,
        'paid_total' => count($paidRecords),
        'paid_success' => 0
    ];

    foreach ($accessRecords as $record) {
        // 判断是否已撤回：用户名为"隐私表单重新填写"且答案中包含原用户信息
        $isRevoked = ($record['username'] === '隐私表单重新填写' && $record['answer'] && preg_match('/\s{4}\d+:\S+:\S+@\S+$/', $record['answer']));
        if ($isRevoked) {
            $stats['revoked']++;
        } elseif ($record['access_granted']) {
            $stats['granted']++;
        } elseif ($record['privacy_type'] === 'open_answer' || $record['privacy_type'] === 'manual_approval') {
            $stats['pending']++;
        } else {
            $stats['rejected']++;
        }
    }

    foreach ($paidRecords as $record) {
        if ($record['status'] == 1) {
            $stats['paid_success']++;
        }
    }

    return [
        'records' => $accessRecords,
        'paidRecords' => $paidRecords,
        'stats' => $stats
    ];
}

/**
 * 发送回访邮件
 */
function sendFollowupEmail($db, $record, $customSubject = '', $customMessage = '', $includeContactInfo = true) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
        $siteName = $config['website_name'] ?? 'LyGalaxy';
        
        // 服务器设置
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 10;
        
        $mail->setFrom(SMTP_USERNAME, $siteName . ' - 客服团队');
        $mail->addAddress($record['email'], $record['username']);
        
        $subject = !empty($customSubject) ? $customSubject : '感谢您的访问 - ' . $siteName;
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = getFollowupEmailTemplate($record, $siteName, $customMessage, $includeContactInfo);
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("回访邮件发送失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 回访邮件模板
 */
function getFollowupEmailTemplate($record, $siteName, $customMessage = '', $includeContactInfo = true) {
    $statusText = $record['access_granted'] ? '已获得访问权限' : '正在审核中';
    $statusColor = $record['access_granted'] ? '#28a745' : '#ffc107';
    
    $customMessageHtml = '';
    if (!empty($customMessage)) {
        $customMessageHtml = '
            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 4px;">
                <h4 style="color: #856404; margin-top: 0;">💬 来自管理员的消息</h4>
                <div style="color: #856404; line-height: 1.6;">' . nl2br(htmlspecialchars($customMessage)) . '</div>
            </div>';
    }
    
    $contactInfoHtml = '';
    if ($includeContactInfo) {
        $contactInfoHtml = '
            <div style="text-align: center; margin: 30px 0;">
                <a href="mailto:' . SMTP_USERNAME . '?subject=反馈" style="display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 5px;">
                    📧 发送反馈
                </a>
            </div>';
    }
    
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; padding: 30px; }
        .header { text-align: center; margin-bottom: 30px; }
        .status-box { background: #f8f9fa; border-left: 4px solid ' . $statusColor . '; padding: 20px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 感谢您的访问</h1>
            <p>' . htmlspecialchars($siteName) . '</p>
        </div>
        
        <p>您好 <strong>' . htmlspecialchars($record['username']) . '</strong>，</p>
        
        ' . $customMessageHtml . '
        
        <div class="status-box">
            <h3>📄 ' . htmlspecialchars($record['post_title']) . '</h3>
            <p><strong>状态：</strong><span style="color: ' . $statusColor . '">' . $statusText . '</span></p>
        </div>
        
        ' . $contactInfoHtml . '
        
        <p>祝好，<br>' . htmlspecialchars($siteName) . '</p>
    </div>
</body>
</html>';
}

/**
 * 撤回通知邮件模板
 */
function getRevokeEmailTemplate($username, $siteName, $customBody = '', $postId = 0) {
    $contentHtml = '';
    if (!empty($customBody)) {
        $contentHtml = '<div style="background: #f8f9fa; border-left: 4px solid #dc3545; padding: 20px; margin: 20px 0; border-radius: 4px; color: #333; line-height: 1.8;">' . nl2br(htmlspecialchars($customBody)) . '</div>';
    }

    $siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $contactEmail = '';
    try {
        $db = getDB();
        $cfg = $db->query("SELECT contact_email FROM website_config LIMIT 1")->fetch();
        if (!empty($cfg['contact_email'])) {
            $contactEmail = $cfg['contact_email'];
        }
    } catch (Exception $e) {}

    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; padding: 30px; }
        .header { text-align: center; margin-bottom: 30px; }
        .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 4px; }
        .btn-row { text-align: center; margin: 30px 0; }
        .btn-link { display: inline-block; padding: 12px 24px; color: white; text-decoration: none; border-radius: 5px; font-weight: 500; margin: 0 6px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="color: #dc3545;">⚠️ 隐私内容填写提醒</h1>
            <p>' . htmlspecialchars($siteName) . '</p>
        </div>
        
        <p>您好 <strong>' . htmlspecialchars($username) . '</strong>，</p>
        
        ' . $contentHtml . '
        
        <div class="warning-box">
            <p style="margin: 0;"><strong>请注意：</strong>如需重新说明情况，请点击下方按钮前往网站重新填写，或联系站长沟通。</p>
        </div>
        
        <div class="btn-row">
            <a href="' . $siteUrl . '/blog.php' . ($postId ? '?id=' . (int)$postId : '') . '" style="background: #667eea;" class="btn-link">
                📝 前往网站重新填写
            </a>
            ' . ($contactEmail ? '<a href="mailto:' . htmlspecialchars($contactEmail) . '?subject=隐私内容说明-' . urlencode($username) . '" style="background: #6c757d;" class="btn-link">📧 联系站长</a>' : '') . '
        </div>
        
        <p>祝好，<br>' . htmlspecialchars($siteName) . ' - 客服团队</p>
    </div>
</body>
</html>';
}
