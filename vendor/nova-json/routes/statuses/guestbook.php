<?php
/**
 * Statuses Guestbook 路由
 * 命名空间: v1
 *
 * GET    /v1/statuses/guestbook                   - 获取留言列表（含回复）
 * POST   /v1/statuses/guestbook                   - 提交留言或回复（公开）
 * PUT    /v1/statuses/guestbook/{id}/reply        - 管理员回复（管理员）
 * DELETE /v1/statuses/guestbook/{id}              - 删除留言及回复（管理员）
 *
 * 参数（GET）:
 *   page     - 页码（默认 1）
 *   per_page - 每页条数（默认 10，不传则返回全部）
 *
 * 参数（POST）:
 *   nickname  - 昵称（必填）
 *   content   - 内容（必填）
 *   email     - 邮箱（可选）
 *   website   - 网站（可选）
 *   parent_id - 回复目标 ID（可选，0=顶级留言）
 *
 * 参数（PUT /{id}/reply）:
 *   reply_content - 回复内容（必填）
 */

register_rest_route('v1', '/statuses/guestbook', [
    'methods'  => 'GET',
    'callback' => 'nova_get_guestbook',
]);

register_rest_route('v1', '/statuses/guestbook', [
    'methods'  => 'POST',
    'callback' => 'nova_create_guestbook',
]);

register_rest_route('v1', '/statuses/guestbook/{id}/reply', [
    'methods'  => 'PUT',
    'callback' => 'nova_reply_guestbook',
]);

register_rest_route('v1', '/statuses/guestbook/{id}', [
    'methods'  => 'DELETE',
    'callback' => 'nova_delete_guestbook',
]);

function nova_guestbook_text_length($value) {
    $value = (string)$value;
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

function nova_guestbook_error($code, $message, $status = 400) {
    return new Nova_REST_Response([
        'code'    => $code,
        'message' => $message,
        'data'    => ['status' => (int)$status],
    ], (int)$status);
}

/**
 * 获取留言列表
 */
function nova_get_guestbook($request) {
    $db = getDB();
    $isAdmin = v1_is_admin(v1_get_current_user_id());

    // 确保表存在
    try {
        $db->query("SELECT 1 FROM guestbook LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("CREATE TABLE IF NOT EXISTS guestbook (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT DEFAULT 0,
            nickname VARCHAR(50) NOT NULL,
            email VARCHAR(100),
            website VARCHAR(255),
            content TEXT NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reply_content TEXT,
            reply_time DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $total = (int)$db->query("SELECT COUNT(*) FROM guestbook WHERE parent_id = 0")->fetchColumn();

    // 条件分页
    $raw_per_page = $request->get_param('per_page');
    $has_pagination = $raw_per_page !== null;

    if ($has_pagination) {
        $page     = max(1, (int)($request->get_param('page') ?? 1));
        $per_page = min(50, max(1, (int)$raw_per_page));
        $offset   = ($page - 1) * $per_page;

        $stmt = $db->prepare("SELECT * FROM guestbook WHERE parent_id = 0 ORDER BY created_at DESC LIMIT ?, ?");
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $per_page, PDO::PARAM_INT);
        $stmt->execute();
        $messages = $stmt->fetchAll();
    } else {
        $page     = 1;
        $per_page = $total;
        $messages = $db->query("SELECT * FROM guestbook WHERE parent_id = 0 ORDER BY created_at DESC")->fetchAll();
    }

    // 获取回复
    if (!empty($messages)) {
        $msgIds = array_column($messages, 'id');
        $placeholders = str_repeat('?,', count($msgIds) - 1) . '?';
        $stmt = $db->prepare("SELECT * FROM guestbook WHERE parent_id IN ($placeholders) ORDER BY created_at ASC");
        $stmt->execute($msgIds);
        $allReplies = $stmt->fetchAll();
        $repliesGrouped = [];
        foreach ($allReplies as $reply) {
            $repliesGrouped[$reply['parent_id']][] = $reply;
        }
    } else {
        $repliesGrouped = [];
    }

    // 组装返回数据（不暴露 ip_address / user_agent）
    $items = [];
    foreach ($messages as $msg) {
        $item = [
            'id'            => (int)$msg['id'],
            'nickname'      => $msg['nickname'],
            'website'       => $msg['website'] ?? '',
            'content'       => $msg['content'],
            'reply_content' => $msg['reply_content'] ?? '',
            'reply_time'    => $msg['reply_time'] ?? '',
            'created_at'    => $msg['created_at'],
            'replies'       => [],
        ];
        if ($isAdmin) {
            $item['email'] = $msg['email'] ?? '';
        }

        if (isset($repliesGrouped[$msg['id']])) {
            foreach ($repliesGrouped[$msg['id']] as $reply) {
                $replyItem = [
                    'id'         => (int)$reply['id'],
                    'nickname'   => $reply['nickname'],
                    'website'    => $reply['website'] ?? '',
                    'content'    => $reply['content'],
                    'created_at' => $reply['created_at'],
                ];
                if ($isAdmin) {
                    $replyItem['email'] = $reply['email'] ?? '';
                }
                $item['replies'][] = $replyItem;
            }
        }

        $items[] = $item;
    }

    return [
        'code'    => 'rest_ok',
        'message' => '获取成功',
        'data'    => [
            'status'   => 200,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'items'    => $items,
        ],
    ];
}

/**
 * 提交留言或回复（公开）
 */
function nova_create_guestbook($request) {
    $db = getDB();

    $nickname  = trim(strip_tags($request->get_param('nickname') ?? ''));
    $content   = trim(strip_tags($request->get_param('content') ?? ''));
    $email     = trim(strip_tags($request->get_param('email') ?? ''));
    $website   = trim(strip_tags($request->get_param('website') ?? ''));
    $parentId  = (int)($request->get_param('parent_id') ?? 0);
    $honeypot  = trim((string)($request->get_param('company') ?? ''));

    if ($honeypot !== '') {
        return [
            'code' => 'rest_ok',
            'message' => '留言成功',
            'data' => ['status' => 201],
        ];
    }

    if ($nickname === '') {
        return nova_guestbook_error('rest_missing_fields', '昵称不能为空');
    }

    if ($content === '') {
        return nova_guestbook_error('rest_missing_fields', '留言内容不能为空');
    }

    if (nova_guestbook_text_length($nickname) > 50) {
        return nova_guestbook_error('rest_invalid_nickname', '昵称不能超过 50 个字符');
    }
    if (nova_guestbook_text_length($content) > 2000) {
        return nova_guestbook_error('rest_invalid_content', '留言内容不能超过 2000 个字符');
    }
    if ($email !== '' && (nova_guestbook_text_length($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        return nova_guestbook_error('rest_invalid_email', '邮箱格式不正确');
    }
    if ($website !== '') {
        $scheme = strtolower((string)parse_url($website, PHP_URL_SCHEME));
        if (
            nova_guestbook_text_length($website) > 255
            || !filter_var($website, FILTER_VALIDATE_URL)
            || !in_array($scheme, ['http', 'https'], true)
            || parse_url($website, PHP_URL_USER) !== null
            || parse_url($website, PHP_URL_PASS) !== null
        ) {
            return nova_guestbook_error('rest_invalid_website', '个人网站需使用有效的 http(s) 地址');
        }
    }
    if ($parentId < 0) {
        return nova_guestbook_error('rest_invalid_parent', '回复目标无效');
    }

    $rateLimit = checkRateLimit('guestbook_create', 5, 600);
    if (empty($rateLimit['allowed'])) {
        return nova_guestbook_error('rest_rate_limited', $rateLimit['message'] ?? '提交过于频繁，请稍后再试', 429);
    }

    // 如果 parent_id > 0，检查父留言是否存在
    if ($parentId > 0) {
        $stmt = $db->prepare("SELECT id FROM guestbook WHERE id = ?");
        $stmt->execute([$parentId]);
        if (!$stmt->fetch()) {
            return nova_guestbook_error('rest_error', '要回复的留言不存在', 404);
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $stmt = $db->prepare(
        "INSERT INTO guestbook (parent_id, nickname, email, website, content, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$parentId, $nickname, $email, $website, $content, $ip, $ua]);
    $newId = (int)$db->lastInsertId();

    // ── 邮件通知 ──
    try {
        if (defined('SMTP_HOST') && !empty(SMTP_HOST)) {
            $siteName = getSiteName();

            // 通知管理员（新留言）
            if ($parentId == 0) {
                $admin = $db->query("SELECT email FROM admins ORDER BY id ASC LIMIT 1")->fetch();
                if ($admin && !empty($admin['email'])) {
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USERNAME;
                    $mail->Password   = SMTP_PASSWORD;
                    $mail->SMTPSecure = SMTP_ENCRYPTION;
                    $mail->Port       = SMTP_PORT;
                    $mail->CharSet    = 'UTF-8';
                    $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
                    $mail->addAddress($admin['email']);
                    $mail->isHTML(true);
                    $mail->Subject = '【留言板】新留言通知 - ' . $siteName;
                    $mailContent = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                            <h3 style='color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px;'>📝 收到一条新留言</h3>
                            <div style='margin: 20px 0;'>
                                <p><strong>👤 昵称：</strong> " . htmlspecialchars($nickname) . "</p>
                                <p><strong>📧 邮箱：</strong> " . htmlspecialchars($email ?: '未提供') . "</p>
                                <p><strong>🌐 网站：</strong> " . htmlspecialchars($website ?: '未提供') . "</p>
                                <p><strong>📅 时间：</strong> " . date('Y-m-d H:i:s') . "</p>
                            </div>
                            <div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #667eea; border-radius: 4px; margin: 20px 0;'>
                                <strong>💬 内容：</strong><br>
                                <div style='margin-top: 10px; white-space: pre-wrap;'>" . nl2br(htmlspecialchars($content)) . "</div>
                            </div>
                        </div>";
                    $mail->Body = $mailContent;
                    $mail->AltBody = "新留言通知\n\n昵称: $nickname\n内容: $content\n\n请访问留言板查看详情。";
                    $mail->send();
                }
            }

            // 通知父留言作者（回复通知）
            if ($parentId > 0) {
                $stmtP = $db->prepare("SELECT nickname, email, content FROM guestbook WHERE id = ?");
                $stmtP->execute([$parentId]);
                $parentMsg = $stmtP->fetch();
                if ($parentMsg && !empty($parentMsg['email']) && $parentMsg['email'] !== $email) {
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USERNAME;
                    $mail->Password   = SMTP_PASSWORD;
                    $mail->SMTPSecure = SMTP_ENCRYPTION;
                    $mail->Port       = SMTP_PORT;
                    $mail->CharSet    = 'UTF-8';
                    $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
                    $mail->addAddress($parentMsg['email']);
                    $mail->isHTML(true);
                    $mail->Subject = '【留言板】有人回复了您的留言 - ' . $siteName;
                    $mailContent = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                            <h3 style='color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px;'>👋 Hi, " . htmlspecialchars($parentMsg['nickname']) . "</h3>
                            <p>您在 <strong>" . $siteName . "</strong> 的留言收到了新的回复：</p>
                            <div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #999; border-radius: 4px; margin: 20px 0; color: #666;'>
                                <strong>您的留言：</strong><br>
                                <div style='margin-top: 5px; white-space: pre-wrap;'>" . nl2br(htmlspecialchars($parentMsg['content'])) . "</div>
                            </div>
                            <div style='background: #eef2ff; padding: 15px; border-left: 4px solid #667eea; border-radius: 4px; margin: 20px 0;'>
                                <strong>💬 " . htmlspecialchars($nickname) . " 的回复：</strong><br>
                                <div style='margin-top: 5px; white-space: pre-wrap; color: #333;'>" . nl2br(htmlspecialchars($content)) . "</div>
                            </div>
                        </div>";
                    $mail->Body = $mailContent;
                    $mail->AltBody = "您的留言收到了回复\n\n您的留言: {$parentMsg['content']}\n{$nickname} 的回复: $content\n\n请访问网站查看详情。";
                    $mail->send();
                }
            }
        }
    } catch (Exception $e) {
        error_log("留言通知发送失败: " . $e->getMessage());
    }
    // ── 结束邮件通知 ──

    return [
        'code'    => 'rest_ok',
        'message' => $parentId > 0 ? '回复成功' : '留言成功',
        'data'    => [
            'status'     => 201,
            'id'         => $newId,
            'nickname'   => $nickname,
            'website'    => $website,
            'content'    => $content,
            'parent_id'  => $parentId,
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ];
}

/**
 * 管理员回复留言（更新 reply_content）
 */
function nova_reply_guestbook($request) {
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可回复',
            'data'    => ['status' => 403],
        ];
    }

    $db = getDB();
    $id = (int)($request->get_param('id') ?? 0);
    $replyContent = trim(strip_tags($request->get_param('reply_content') ?? ''));

    if (!$id) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '未指定留言 ID',
            'data'    => ['status' => 400],
        ];
    }

    if (empty($replyContent)) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '回复内容不能为空',
            'data'    => ['status' => 400],
        ];
    }

    // 检查留言是否存在且为顶级留言
    $stmt = $db->prepare("SELECT id, nickname, email, content FROM guestbook WHERE id = ? AND parent_id = 0");
    $stmt->execute([$id]);
    $msg = $stmt->fetch();
    if (!$msg) {
        return [
            'code'    => 'rest_error',
            'message' => '留言不存在',
            'data'    => ['status' => 404],
        ];
    }

    $stmt = $db->prepare("UPDATE guestbook SET reply_content = ?, reply_time = NOW() WHERE id = ?");
    $stmt->execute([$replyContent, $id]);

    // ── 邮件通知：通知留言作者有博主回复 ──
    try {
        if (defined('SMTP_HOST') && !empty(SMTP_HOST) && !empty($msg['email'])) {
            $siteName = getSiteName();
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
            $mail->addAddress($msg['email']);
            $mail->isHTML(true);
            $mail->Subject = '【留言板】您的留言收到了博主回复 - ' . $siteName;
            $mailContent = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                    <h3 style='color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px;'>👋 Hi, " . htmlspecialchars($msg['nickname']) . "</h3>
                    <div style='margin: 20px 0;'>
                        <p>您在 <strong>" . $siteName . "</strong> 的留言收到了博主回复：</p>
                    </div>
                    <div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #999; border-radius: 4px; margin: 20px 0; color: #666;'>
                        <strong>您的留言：</strong><br>
                        <div style='margin-top: 5px; white-space: pre-wrap;'>" . nl2br(htmlspecialchars($msg['content'])) . "</div>
                    </div>
                    <div style='background: #eef2ff; padding: 15px; border-left: 4px solid #667eea; border-radius: 4px; margin: 20px 0;'>
                        <strong>👨‍💻 博主回复：</strong><br>
                        <div style='margin-top: 5px; white-space: pre-wrap; color: #333;'>" . nl2br(htmlspecialchars($replyContent)) . "</div>
                    </div>
                </div>";
            $mail->Body = $mailContent;
            $mail->AltBody = "您的留言收到了回复\n\n您的留言: {$msg['content']}\n博主回复: $replyContent\n\n请访问网站查看详情。";
            $mail->send();
        }
    } catch (Exception $e) {
        error_log("博主回复通知发送失败: " . $e->getMessage());
    }
    // ── 结束邮件通知 ──

    return [
        'code'    => 'rest_ok',
        'message' => '回复成功',
        'data'    => [
            'status'        => 200,
            'id'            => $id,
            'reply_content' => $replyContent,
        ],
    ];
}

/**
 * 删除留言及回复（管理员）
 */
function nova_delete_guestbook($request) {
    if (!v1_is_admin(v1_get_current_user_id())) {
        return [
            'code'    => 'rest_forbidden',
            'message' => '无权操作，仅管理员可删除留言',
            'data'    => ['status' => 403],
        ];
    }

    $db = getDB();
    $id = (int)($request->get_param('id') ?? 0);

    if (!$id) {
        return [
            'code'    => 'rest_missing_fields',
            'message' => '未指定留言 ID',
            'data'    => ['status' => 400],
        ];
    }

    // 检查是否存在
    $stmt = $db->prepare("SELECT id FROM guestbook WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        return [
            'code'    => 'rest_error',
            'message' => '留言不存在',
            'data'    => ['status' => 404],
        ];
    }

    // 删除留言及其回复
    $db->prepare("DELETE FROM guestbook WHERE id = ? OR parent_id = ?")->execute([$id, $id]);

    return [
        'code'    => 'rest_ok',
        'message' => '留言及回复已删除',
        'data'    => [
            'status'    => 200,
            'deleted_id' => $id,
        ],
    ];
}
