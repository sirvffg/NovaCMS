<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
// 定义静默失败，避免邮件配置错误导致页面崩溃
define('EMAIL_CONFIG_SILENT_FAILURE', true);
require_once '../config/email_config.php';
recordVisit($_SERVER['REQUEST_URI']);

// 获取网站配置
$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();

// 自动创建留言表（如果不存在）
try {
    $db->query("SELECT 1 FROM guestbook LIMIT 1");
    $columns = $db->query("SHOW COLUMNS FROM guestbook LIKE 'parent_id'")->fetchAll();
    if (empty($columns)) {
        $db->exec("ALTER TABLE guestbook ADD COLUMN parent_id INT DEFAULT 0 AFTER id");
    }
    $stmt = $db->query("SHOW COLUMNS FROM guestbook LIKE 'user_agent'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        $db->exec("ALTER TABLE guestbook ADD COLUMN user_agent TEXT DEFAULT NULL AFTER ip_address");
    } elseif (stripos($column['Type'], 'text') === false) {
        $db->exec("ALTER TABLE guestbook MODIFY COLUMN user_agent TEXT DEFAULT NULL");
    }
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

// 处理管理员操作
if (isset($_SESSION['admin_id']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'admin_reply') {
        $id = (int)$_POST['id'];
        $reply_content = trim($_POST['reply_content']);
        $stmt = $db->prepare("UPDATE guestbook SET reply_content = ?, reply_time = NOW() WHERE id = ?");
        $stmt->execute([$reply_content, $id]);
        try {
            if (defined('SMTP_HOST') && !empty(SMTP_HOST)) {
                $stmt = $db->prepare("SELECT nickname, email, content FROM guestbook WHERE id = ?");
                $stmt->execute([(int)$id]);
                $msg = $stmt->fetch();
                if ($msg && !empty($msg['email'])) {
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
                    $mail->Subject = '【留言板】您的留言收到了博主回复 - ' . ($config['website_name'] ?? '网站');
                    $mailContent = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                            <h3 style='color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px;'>👋 Hi, " . htmlspecialchars($msg['nickname']) . "</h3>
                            <div style='margin: 20px 0;'>
                                <p>您在 <strong>" . ($config['website_name'] ?? '网站') . "</strong> 的留言收到了博主回复：</p>
                            </div>
                            <div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #999; border-radius: 4px; margin: 20px 0; color: #666;'>
                                <strong>您的留言：</strong><br>
                                <div style='margin-top: 5px; white-space: pre-wrap;'>" . nl2br(htmlspecialchars($msg['content'])) . "</div>
                            </div>
                            <div style='background: #eef2ff; padding: 15px; border-left: 4px solid #667eea; border-radius: 4px; margin: 20px 0;'>
                                <strong>👨‍💻 博主回复：</strong><br>
                                <div style='margin-top: 5px; white-space: pre-wrap; color: #333;'>" . nl2br(htmlspecialchars($reply_content)) . "</div>
                            </div>
                            <div style='text-align: center; margin-top: 30px;'>
                                <a href='" . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>查看详情</a>
                            </div>
                            <div style='margin-top: 30px; font-size: 12px; color: #999; text-align: center; border-top: 1px solid #eee; padding-top: 10px;'>
                                此邮件由系统自动发送，请勿直接回复。
                            </div>
                        </div>";
                    $mail->Body = $mailContent;
                    $mail->AltBody = "您的留言收到了回复\n\n您的留言: {$msg['content']}\n博主回复: $reply_content\n\n请访问网站查看详情。";
                    $mail->send();
                }
            }
        } catch (Exception $e) {
            error_log("博主回复通知发送失败: " . $e->getMessage());
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?page=$page");
        exit;
    } elseif ($_POST['action'] === 'admin_delete') {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM guestbook WHERE id = ? OR parent_id = ?");
        $stmt->execute([$id, $id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?page=$page");
        exit;
    }
}

// 处理留言提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $errors[] = '安全验证失败，请刷新页面后重试';
    } elseif (checkHoneypot()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => '留言提交成功']);
        } else {
            header("Location: " . $_SERVER['PHP_SELF'] . "?page=$page");
        }
        exit;
    } else {
        $nickname = trim($_POST['nickname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
        $ip = $_SERVER['REMOTE_ADDR'];
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $errors = [];
        if (empty($nickname)) $errors[] = "昵称不能为空";
        if (empty($content)) $errors[] = "留言内容不能为空";

        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO guestbook (parent_id, nickname, email, website, content, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$parent_id, $nickname, $email, $website, $content, $ip, $ua]);
            
            try {
                if (defined('SMTP_HOST') && !empty(SMTP_HOST)) {
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
                        $mail->Subject = '【留言板】新留言通知 - ' . ($config['website_name'] ?? '网站');
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
                                <div style='text-align: center; margin-top: 30px;'>
                                    <a href='" . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>查看留言板</a>
                                </div>
                            </div>";
                        $mail->Body = $mailContent;
                        $mail->AltBody = "新留言通知\n\n昵称: $nickname\n内容: $content\n\n请访问留言板查看详情。";
                        $mail->send();
                    }

                    if ($parent_id > 0) {
                        $stmt = $db->prepare("SELECT nickname, email, content FROM guestbook WHERE id = ?");
                        $stmt->execute([(int)$parent_id]);
                        $parentMsg = $stmt->fetch();
                        if ($parentMsg && !empty($parentMsg['email']) && $parentMsg['email'] !== $email && ($admin && $parentMsg['email'] !== $admin['email'])) {
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
                            $mail->Subject = '【留言板】有人回复了您的留言 - ' . ($config['website_name'] ?? '网站');
                            $mailContent = "
                                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                                    <h3 style='color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px;'>👋 Hi, " . htmlspecialchars($parentMsg['nickname']) . "</h3>
                                    <p>您在 <strong>" . ($config['website_name'] ?? '网站') . "</strong> 的留言收到了新的回复：</p>
                                    <div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #999; border-radius: 4px; margin: 20px 0; color: #666;'>
                                        <strong>您的留言：</strong><br>
                                        <div style='margin-top: 5px; white-space: pre-wrap;'>" . nl2br(htmlspecialchars($parentMsg['content'])) . "</div>
                                    </div>
                                    <div style='background: #eef2ff; padding: 15px; border-left: 4px solid #667eea; border-radius: 4px; margin: 20px 0;'>
                                        <strong>💬 " . htmlspecialchars($nickname) . " 的回复：</strong><br>
                                        <div style='margin-top: 5px; white-space: pre-wrap; color: #333;'>" . nl2br(htmlspecialchars($content)) . "</div>
                                    </div>
                                    <div style='text-align: center; margin-top: 30px;'>
                                        <a href='" . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>去围观</a>
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

            ob_clean();
            if (!empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => '留言成功！']);
                exit;
            }
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            ob_clean();
            if (!empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => implode("\n", $errors)]);
                exit;
            }
        }
    }
}

// 获取留言列表
$perPage = 10;
$offset = ($page - 1) * $perPage;
$totalMessages = $db->query("SELECT COUNT(*) FROM guestbook WHERE parent_id = 0")->fetchColumn();
$totalPages = ceil($totalMessages / $perPage);

$stmt = $db->prepare("SELECT * FROM guestbook WHERE parent_id = 0 ORDER BY created_at DESC LIMIT ?, ?");
$stmt->execute([(int)$offset, (int)$perPage]);
$messages = $stmt->fetchAll();

if (!empty($messages)) {
    $messageIds = array_column($messages, 'id');
    $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
    $stmt = $db->prepare("SELECT * FROM guestbook WHERE parent_id IN ($placeholders) ORDER BY created_at ASC");
    $stmt->execute($messageIds);
    $allReplies = $stmt->fetchAll();
    $replies = [];
    foreach ($allReplies as $reply) {
        $replies[$reply['parent_id']][] = $reply;
    }
} else {
    $replies = [];
}

function getQQAvatarUrl($email) {
    if (preg_match('/^(\d{5,11})@qq\.com$/i', $email, $matches)) {
        return "https://q1.qlogo.cn/g?b=qq&nk=" . $matches[1] . "&s=100";
    }
    return null;
}

function parseUserAgent($ua) {
    $os = '未知设备';
    $browser = '未知浏览器';
    if (preg_match('/Windows NT 10.0/i', $ua)) { $os = 'Windows 10/11'; }
    elseif (preg_match('/Windows NT 6.3/i', $ua)) { $os = 'Windows 8.1'; }
    elseif (preg_match('/Windows NT 6.2/i', $ua)) { $os = 'Windows 8'; }
    elseif (preg_match('/Windows NT 6.1/i', $ua)) { $os = 'Windows 7'; }
    elseif (preg_match('/Macintosh/i', $ua)) { 
        $os = 'macOS'; 
        if (preg_match('/Mac OS X ([0-9_]+)/i', $ua, $matches)) $os .= ' ' . str_replace('_', '.', $matches[1]);
    }
    elseif (preg_match('/Android/i', $ua)) { 
        $os = 'Android'; 
        if (preg_match('/Android ([0-9.]+)/i', $ua, $matches)) $os .= ' ' . $matches[1];
    }
    elseif (preg_match('/iPhone/i', $ua)) { 
        $os = 'iOS'; 
        if (preg_match('/OS ([0-9_]+) like Mac OS X/i', $ua, $matches)) $os .= ' ' . str_replace('_', '.', $matches[1]);
    }
    elseif (preg_match('/iPad/i', $ua)) { 
        $os = 'iPadOS'; 
        if (preg_match('/OS ([0-9_]+) like Mac OS X/i', $ua, $matches)) $os .= ' ' . str_replace('_', '.', $matches[1]);
    }
    elseif (preg_match('/Linux/i', $ua)) { $os = 'Linux'; }
    if (preg_match('/Edg/i', $ua) || preg_match('/Edge/i', $ua)) { $browser = 'Edge'; if (preg_match('/(Edg|Edge)\/([0-9.]+)/i', $ua, $matches)) $browser .= ' ' . $matches[2]; }
    elseif (preg_match('/OPR/i', $ua) || preg_match('/Opera/i', $ua)) { $browser = 'Opera'; } 
    elseif (preg_match('/Chrome/i', $ua)) { $browser = 'Chrome'; if (preg_match('/Chrome\/([0-9.]+)/i', $ua, $matches)) $browser .= ' ' . $matches[1]; }
    elseif (preg_match('/Firefox/i', $ua)) { $browser = 'Firefox'; if (preg_match('/Firefox\/([0-9.]+)/i', $ua, $matches)) $browser .= ' ' . $matches[1]; }
    elseif (preg_match('/Safari/i', $ua)) { $browser = 'Safari'; if (preg_match('/Version\/([0-9.]+)/i', $ua, $matches)) $browser .= ' ' . $matches[1]; }
    elseif (preg_match('/MSIE/i', $ua)) { $browser = 'Internet Explorer'; }
    return ['os' => $os, 'browser' => $browser];
}

$adminEmail = '';
$adminInfo = $db->query("SELECT email FROM admins ORDER BY id ASC LIMIT 1")->fetch();
if ($adminInfo) $adminEmail = $adminInfo['email'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>留言板 - <?= e($config['website_name']) ?></title>
    <meta name="description" content="<?= e($config['website_name']) ?> 的留言板，留下你的足迹">
    <meta property="og:title" content="留言板 - <?= e($config['website_name']) ?>">
    <meta property="og:description" content="<?= e($config['website_name']) ?> 的留言板，留下你的足迹">
    <meta property="og:url" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <?php if (!empty($config['logo'])): ?>
    <meta property="og:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['logo']) ?>">
    <?php endif; ?>
    <?php if (!empty($config['favicon'])): ?>
    <meta property="og:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['favicon']) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="留言板 - <?= e($config['website_name']) ?>">
    <meta name="twitter:description" content="<?= e($config['website_name']) ?> 的留言板，留下你的足迹">
    <?php if (!empty($config['favicon'])): ?>
    <meta name="twitter:image" content="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . e($config['favicon']) ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') ?>">
    <?php if (!empty($config['favicon'])): ?>
    <link rel="icon" type="image/x-icon" href="<?= e($config['favicon']) ?>">
    <link rel="shortcut icon" href="<?= e($config['favicon']) ?>">
    <?php endif; ?>
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap-icons.css" rel="stylesheet">
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "<?= e($config['website_name']) ?> - 留言板",
      "url": "<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ?>",
      "description": "<?= e($config['website_name']) ?> 的留言板，留下你的足迹。"
    }
    </script>
    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #6366f1;
            --primary-bg: #eef2ff;
            --text: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --radius: 14px;
            --radius-sm: 10px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.06);
            --shadow: 0 4px 12px rgba(0,0,0,0.04), 0 1px 3px rgba(0,0,0,0.05);
            --shadow-lg: 0 12px 32px rgba(0,0,0,0.06), 0 2px 8px rgba(0,0,0,0.04);
            --transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navbar */
        .navbar.fixed-top {
            background: rgba(255,255,255,0.85) !important;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 8px 0 !important;
            box-shadow: var(--shadow-sm);
        }
        .navbar-brand {
            color: var(--text) !important;
            font-weight: 700;
            font-size: 1.05rem !important;
            letter-spacing: -0.3px;
        }
        .navbar-nav .nav-link {
            color: var(--text-secondary) !important;
            font-weight: 500;
            font-size: 0.9rem;
            transition: color var(--transition);
        }
        .navbar-nav .nav-link:hover { color: var(--primary) !important; }
        .navbar .btn {
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.4rem 0.9rem;
            border-radius: 8px;
            transition: all var(--transition);
        }
        .navbar .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
        }
        .navbar .btn-outline-primary:hover {
            background: var(--primary);
            color: #fff;
        }

        @media (min-width: 992px) {
            .navbar-nav .dropdown:hover .dropdown-menu {
                display: block;
                margin-top: 0;
            }
        }

        .page-container {
            flex: 1;
            padding-top: 72px;
        }

        .content-wrapper {
            max-width: 960px;
            margin: 0 auto;
            padding: 24px 32px 40px;
        }

        /* Hero */
        .page-hero {
            text-align: center;
            margin-bottom: 32px;
        }
        .page-hero .hero-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            border-radius: 18px;
            background: var(--primary-bg);
            color: var(--primary);
            font-size: 1.8rem;
            margin-bottom: 14px;
        }
        .page-hero h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text);
            margin: 0 0 6px;
        }
        .page-hero p {
            color: var(--text-secondary);
            font-size: 0.92rem;
            margin: 0;
        }

        /* Form Card */
        .form-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }
        .form-label {
            font-weight: 600;
            font-size: 0.84rem;
            color: var(--text);
            margin-bottom: 5px;
            display: block;
        }
        .form-control {
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            font-size: 0.9rem;
            background: var(--card-bg);
            color: var(--text);
            width: 100%;
            transition: all var(--transition);
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
            outline: none;
        }
        .form-control::placeholder { color: var(--text-muted); }
        textarea.form-control { resize: vertical; }

        .btn-primary-sm {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            padding: 10px 22px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
        }
        .btn-primary-sm:hover {
            background: var(--primary-light);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79,70,229,0.3);
        }

        .alert-danger {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            color: #dc2626;
            font-size: 0.88rem;
            margin-bottom: 16px;
        }

        /* Message Card */
        .message-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            transition: all var(--transition);
            animation: fadeIn 0.4s ease-out both;
        }
        .message-card:hover {
            box-shadow: var(--shadow);
        }

        .reply-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            margin-right: 14px;
            flex-shrink: 0;
            overflow: hidden;
            background: var(--primary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--primary);
        }
        .reply-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .reply-nickname {
            font-weight: 600;
            color: var(--primary);
            font-size: 1rem;
            text-decoration: none;
            margin-right: 8px;
        }
        .reply-time {
            color: var(--text-muted);
            font-size: 0.8rem;
            margin-right: 8px;
        }

        .blogger-badge {
            background: var(--primary);
            color: #fff;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 4px;
            margin-right: 8px;
            font-weight: 500;
        }

        .device-tag {
            background: var(--bg);
            color: var(--text-muted);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            margin-right: 6px;
        }

        .reply-content {
            color: var(--text);
            line-height: 1.65;
            font-size: 0.95rem;
            word-break: break-word;
        }

        .reply-box {
            background: #f8fafc;
            border-radius: var(--radius-sm);
            padding: 16px;
            margin-top: 14px;
            border-left: 3px solid var(--primary);
        }
        .reply-header {
            font-size: 0.85rem;
            color: var(--primary);
            margin-bottom: 6px;
            font-weight: 600;
        }

        /* Reply item */
        .reply-item {
            display: flex;
            padding: 14px 0;
            border-top: 1px dashed var(--border);
            margin-top: 14px;
        }
        .reply-body { flex: 1; min-width: 0; }
        .reply-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 6px;
            font-size: 0.9rem;
        }
        .reply-action {
            opacity: 0.5;
            transition: opacity var(--transition);
        }
        .reply-item:hover .reply-action { opacity: 1; }

        .text-link {
            color: var(--text-muted);
            text-decoration: none;
            padding: 2px 4px;
            font-size: 0.88rem;
        }
        .text-link:hover { color: var(--primary); }

        /* Pagination */
        .pagination-wrap {
            display: flex;
            justify-content: center;
            margin-top: 32px;
            gap: 6px;
        }
        .page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            height: 38px;
            padding: 0 12px;
            border-radius: var(--radius-sm);
            font-size: 0.88rem;
            font-weight: 500;
            border: 1.5px solid var(--border);
            background: var(--card-bg);
            color: var(--text-secondary);
            text-decoration: none;
            transition: all var(--transition);
        }
        .page-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-bg);
        }
        .page-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        /* Empty */
        .empty-state {
            text-align: center;
            padding: 64px 20px;
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }
        .empty-state .empty-icon {
            font-size: 3rem;
            color: var(--text-muted);
            margin-bottom: 14px;
            display: block;
        }
        .empty-state h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 6px;
        }
        .empty-state p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin: 0;
        }

        /* Modal */
        .modal-content {
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }
        .dropdown-menu {
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
        }
        .dropdown-item { font-size: 0.88rem; }

        /* Toast */
        .toast {
            border-radius: var(--radius-sm);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Footer */
        footer {
            background: var(--card-bg);
            border-top: 1px solid var(--border);
            padding: 12px 0;
            font-size: 0.8rem;
            color: var(--text-muted);
            flex-shrink: 0;
        }
        footer a { color: var(--text-muted); text-decoration: none; }
        footer a:hover { color: var(--primary); }

        @media (max-width: 576px) {
            .content-wrapper { padding: 20px 16px 32px; }
            .message-card { padding: 18px; }
            .form-card { padding: 18px; }
        }
    </style>
</head>
<body>
    <h1 class="visually-hidden">留言板 - <?= e($config['website_name']) ?></h1>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light fixed-top">
        <div class="container">
            <a class="navbar-brand" href="/">
                <span class="d-none d-lg-inline">留言板 | <?= e($config['website_name']) ?></span>
                <span class="d-lg-none">留言板</span>
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <a class="btn btn-outline-primary btn-sm" id="backButton">返回</a>
            </div>
        </div>
    </nav>

    <div class="page-container">
        <div class="content-wrapper">
            <div class="page-hero">
                <div class="hero-icon">
                    <i class="bi bi-chat-text"></i>
                </div>
                <h1>留言板</h1>
                <p>想说点什么？留下你的足迹吧</p>
            </div>

            <!-- 留言表单 -->
            <div class="form-card">
                <?php if (!empty($errors)): ?>
                    <div class="alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div><?= e($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="ajax-form">
                    <?= csrfField() ?>
                    <?= honeypotField('website_hp') ?>
                    <input type="hidden" name="parent_id" value="0">
                    <div class="row mb-3" style="display:flex; gap:16px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:180px;">
                            <label class="form-label">昵称 *</label>
                            <input type="text" class="form-control" name="nickname" required placeholder="怎么称呼？" value="<?= isset($_SESSION['user_username']) ? e($_SESSION['user_username']) : '' ?>">
                        </div>
                        <div style="flex:1; min-width:180px;">
                            <label class="form-label">邮箱</label>
                            <input type="email" class="form-control" name="email" placeholder="保密，仅用于头像显示" value="<?= isset($_SESSION['user_email']) ? e($_SESSION['user_email']) : '' ?>">
                        </div>
                        <div style="flex:1; min-width:180px;">
                            <label class="form-label">网站</label>
                            <input type="url" class="form-control" name="website" placeholder="http://...">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">留言内容 *</label>
                        <textarea class="form-control" name="content" rows="4" required placeholder="写点什么吧..."></textarea>
                    </div>
                    <div style="text-align: right;">
                        <button type="submit" class="btn-primary-sm"><i class="bi bi-send me-1"></i>发表留言</button>
                    </div>
                </form>
            </div>

            <!-- 留言列表 -->
            <?php if (empty($messages)): ?>
                <div class="empty-state">
                    <span class="empty-icon"><i class="bi bi-chat-square-dots"></i></span>
                    <h3>还没有人留言</h3>
                    <p>来抢个沙发吧！</p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $index => $msg): ?>
                    <div class="message-card" style="animation-delay: <?= $index * 0.08 ?>s">
                        <div style="display:flex;">
                            <div class="reply-avatar" style="width:44px; height:44px;">
                                <?php $qqAvatar = getQQAvatarUrl($msg['email']); ?>
                                <?php if ($qqAvatar): ?>
                                    <img src="<?= $qqAvatar ?>" alt="Avatar">
                                <?php else: ?>
                                    <?= mb_substr($msg['nickname'], 0, 1) ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="reply-body" style="flex:1;">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                    <div>
                                        <div class="reply-meta">
                                            <span class="reply-nickname"><?= e($msg['nickname']) ?></span>
                                            <?php if (!empty($adminEmail) && $msg['email'] === $adminEmail): ?>
                                                <span class="blogger-badge"><i class="bi bi-check-circle-fill me-1"></i>博主</span>
                                            <?php endif; ?>
                                            <span class="reply-time"><?= date('Y-m-d', strtotime($msg['created_at'])) ?></span>
                                            <?php if (!empty($msg['website'])): ?>
                                                <a href="<?= e($msg['website']) ?>" target="_blank" style="font-size:0.85rem; color: var(--text-muted);"><i class="bi bi-link-45deg"></i></a>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if (!empty($msg['user_agent'])): ?>
                                            <div style="margin-bottom:4px;">
                                                <?php $device = parseUserAgent($msg['user_agent']); ?>
                                                <span class="device-tag"><?= $device['browser'] ?></span>
                                                <span class="device-tag"><?= $device['os'] ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <button class="text-link border-0 bg-transparent" 
                                                onclick="openReplyModal(<?= $msg['id'] ?>, '<?= e($msg['nickname']) ?>')">
                                            <i class="bi bi-chat-dots"></i>
                                        </button>
                                        
                                        <?php if (isset($_SESSION['admin_id'])): ?>
                                        <div class="dropdown d-inline-block">
                                            <button class="text-link border-0 bg-transparent" type="button" data-bs-toggle="dropdown">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <button class="dropdown-item" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#adminReplyModal" 
                                                            data-id="<?= $msg['id'] ?>" 
                                                            data-reply="<?= e($msg['reply_content'] ?? '') ?>">
                                                        <i class="bi bi-reply me-2"></i>博主回复
                                                    </button>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="POST" onsubmit="return confirm('确定要删除这条留言及其所有回复吗？')">
                                                        <input type="hidden" name="action" value="admin_delete">
                                                        <input type="hidden" name="id" value="<?= $msg['id'] ?>">
                                                        <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>删除</button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="reply-content mb-3"><?= nl2br(e($msg['content'])) ?></div>
                                
                                <?php if (!empty($msg['reply_content'])): ?>
                                    <div class="reply-box">
                                        <div class="reply-header"><i class="bi bi-reply-fill me-1"></i>博主回复：</div>
                                        <?= nl2br(e($msg['reply_content'])) ?>
                                        <div style="text-align:right; color:var(--text-muted); font-size:0.82rem; margin-top:8px;">
                                            <?= date('Y-m-d H:i', strtotime($msg['reply_time'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- 用户回复列表 -->
                        <?php if (isset($replies[$msg['id']])): ?>
                            <div style="padding-left: 60px; margin-top: 4px;">
                                <?php foreach ($replies[$msg['id']] as $reply): ?>
                                <div class="reply-item">
                                    <div class="reply-avatar" style="width:36px; height:36px;">
                                        <?php $replyAvatar = getQQAvatarUrl($reply['email']); ?>
                                        <?php if ($replyAvatar): ?>
                                            <img src="<?= $replyAvatar ?>" alt="Avatar">
                                        <?php else: ?>
                                            <?= mb_substr($reply['nickname'], 0, 1) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="reply-body">
                                        <div class="reply-meta">
                                            <span class="reply-nickname" style="font-size:0.9rem;"><?= e($reply['nickname']) ?></span>
                                            <?php if (!empty($adminEmail) && $reply['email'] === $adminEmail): ?>
                                                <span class="blogger-badge"><i class="bi bi-check-circle-fill me-1"></i>博主</span>
                                            <?php endif; ?>
                                            <span class="reply-time"><?= date('Y-m-d', strtotime($reply['created_at'])) ?></span>
                                        </div>
                                        <?php if (!empty($reply['user_agent'])): ?>
                                            <div style="margin-bottom:4px;">
                                                <?php $device = parseUserAgent($reply['user_agent']); ?>
                                                <span class="device-tag"><?= $device['browser'] ?></span>
                                                <span class="device-tag"><?= $device['os'] ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="reply-content"><?= nl2br(e($reply['content'])) ?></div>
                                        <div class="reply-action">
                                            <button class="text-link border-0 bg-transparent" 
                                                    onclick="openReplyModal(<?= $msg['id'] ?>, '<?= e($reply['nickname']) ?>', true)">
                                                <i class="bi bi-chat-dots"></i> 回复
                                            </button>
                                            <?php if (isset($_SESSION['admin_id'])): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('确定要删除这条回复吗？')">
                                                <input type="hidden" name="action" value="admin_delete">
                                                <input type="hidden" name="id" value="<?= $reply['id'] ?>">
                                                <button class="text-link border-0 bg-transparent text-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination-wrap">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a class="page-btn <?= $i === $page ? 'active' : '' ?>" href="?page=<?= $i ?>"><?= $i ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 用户回复模态框 -->
    <div class="modal fade" id="userReplyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" class="ajax-form">
                    <?= honeypotField('website_hp') ?>
                    <div class="modal-header">
                        <h5 class="modal-title">回复 <span id="replyToName"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="parent_id" id="parentId">
                        <div class="row mb-3" style="display:flex; gap:12px;">
                            <div style="flex:1;">
                                <label class="form-label">昵称 *</label>
                                <input type="text" class="form-control" name="nickname" required placeholder="怎么称呼？" value="<?= isset($_SESSION['user_username']) ? e($_SESSION['user_username']) : '' ?>">
                            </div>
                            <div style="flex:1;">
                                <label class="form-label">邮箱</label>
                                <input type="email" class="form-control" name="email" placeholder="保密" value="<?= isset($_SESSION['user_email']) ? e($_SESSION['user_email']) : '' ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">回复内容 *</label>
                            <textarea class="form-control" name="content" rows="4" required placeholder="写点什么..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn-primary-sm">提交回复</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['admin_id'])): ?>
    <div class="modal fade" id="adminReplyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">管理员回复 (官方置顶)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="admin_reply">
                        <input type="hidden" name="id" id="replyId">
                        <div class="mb-3">
                            <label class="form-label">回复内容：</label>
                            <textarea class="form-control" name="reply_content" id="replyContent" rows="5" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn-primary-sm">提交回复</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Toast -->
    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1100">
        <div id="successToast" class="toast align-items-center text-white bg-success border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body" id="toastMessage">
                    <i class="bi bi-check-circle me-2"></i>留言成功！
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/footer.php'; ?>

    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script>
        // Back button
        var backButton = document.getElementById('backButton');
        if (backButton) {
            backButton.addEventListener('click', function(e) {
                e.preventDefault();
                var referrer = document.referrer;
                var currentHost = window.location.hostname;
                if (referrer && referrer.includes(currentHost) && window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.href = '/';
                }
            });
        }

        var userReplyModal = new bootstrap.Modal(document.getElementById('userReplyModal'));
        
        function openReplyModal(parentId, nickname, isReplyToReply) {
            if (isReplyToReply === undefined) isReplyToReply = false;
            document.getElementById('parentId').value = parentId;
            document.getElementById('replyToName').textContent = nickname;
            var textarea = document.querySelector('#userReplyModal textarea[name="content"]');
            textarea.value = isReplyToReply ? '@' + nickname + ' ' : '';
            userReplyModal.show();
        }

        document.querySelectorAll('.ajax-form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var submitBtn = this.querySelector('button[type="submit"]');
                var originalContent = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 发送中...';
                
                var formData = new FormData(this);
                formData.append('ajax', '1');
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        if (form.closest('.modal')) {
                            var modal = bootstrap.Modal.getInstance(form.closest('.modal'));
                            if (modal) modal.hide();
                        }
                        var contentTextarea = form.querySelector('textarea[name="content"]');
                        if (contentTextarea) contentTextarea.value = '';
                        var toastEl = document.getElementById('successToast');
                        document.getElementById('toastMessage').innerHTML = '<i class="bi bi-check-circle me-2"></i>' + data.message;
                        var toast = new bootstrap.Toast(toastEl);
                        toast.show();
                        submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> 已发送';
                        setTimeout(function() { window.location.reload(); }, 1000);
                    } else {
                        alert(data.message || '发送失败');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalContent;
                    }
                })
                .catch(function() {
                    alert('网络请求失败，请稍后重试');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalContent;
                });
            });
        });

        <?php if (isset($_SESSION['admin_id'])): ?>
        document.getElementById('adminReplyModal').addEventListener('show.bs.modal', function(e) {
            var btn = e.relatedTarget;
            document.getElementById('replyId').value = btn.getAttribute('data-id');
            document.getElementById('replyContent').value = btn.getAttribute('data-reply') || '';
        });
        <?php endif; ?>
    </script>
</body>
</html>
