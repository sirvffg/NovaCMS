<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';

$db = getDB();

// Get Website Config
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$message = '';
$error = '';
$success = '';

// Handle Flash Messages
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Ensure tables exist
try {
    // License Keys Table
    $db->exec("CREATE TABLE IF NOT EXISTS license_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        key_code VARCHAR(128) NOT NULL UNIQUE,
        status ENUM('unused', 'used') DEFAULT 'unused',
        generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME NULL,
        description VARCHAR(255) DEFAULT '',
        verification_id VARCHAR(255) DEFAULT NULL,
        domain VARCHAR(255) DEFAULT NULL,
        contact_email VARCHAR(255) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add columns if they don't exist (for existing installations)
    $columns = $db->query("SHOW COLUMNS FROM license_keys")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('verification_id', $columns)) {
        $db->exec("ALTER TABLE license_keys ADD COLUMN verification_id VARCHAR(255) DEFAULT NULL");
    }
    if (!in_array('domain', $columns)) {
        $db->exec("ALTER TABLE license_keys ADD COLUMN domain VARCHAR(255) DEFAULT NULL");
    }
    if (!in_array('contact_email', $columns)) {
        $db->exec("ALTER TABLE license_keys ADD COLUMN contact_email VARCHAR(255) DEFAULT NULL");
    }

    // Announcements Table
    $db->exec("CREATE TABLE IF NOT EXISTS license_announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Version Updates Table
    $db->exec("CREATE TABLE IF NOT EXISTS license_version_updates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(50) NOT NULL,
        update_type ENUM('patch', 'minor', 'major') DEFAULT 'patch',
        is_mandatory TINYINT(1) DEFAULT 0,
        download_url VARCHAR(255) NOT NULL,
        changelog TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // License Verification Logs Table
    $db->exec("CREATE TABLE IF NOT EXISTS license_verification_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        license_key VARCHAR(255) NOT NULL,
        verification_id VARCHAR(255) NOT NULL,
        domain VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        system_version VARCHAR(50) DEFAULT NULL,
        status ENUM('valid', 'invalid', 'expired') DEFAULT 'valid',
        check_time DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add system_version column if it doesn't exist
    $logColumns = $db->query("SHOW COLUMNS FROM license_verification_logs")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('system_version', $logColumns)) {
        $db->exec("ALTER TABLE license_verification_logs ADD COLUMN system_version VARCHAR(50) DEFAULT NULL AFTER ip_address");
    }

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_site') {
    $url = $_GET['url'] ?? '';
    if (!empty($url)) {
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "http://" . $url;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
        
        $data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        header('Content-Type: application/json');
        if ($http_code >= 200 && $http_code < 400 && $data) {
            echo json_encode(['status' => 'online', 'content' => $data]);
        } else {
            echo json_encode(['status' => 'offline', 'error' => $error ?: "HTTP Status: $http_code"]);
        }
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Generate License Keys
        if ($_POST['action'] === 'generate') {
            $amount = (int)($_POST['amount'] ?? 1);
            $amount = max(1, min(100, $amount)); // Limit 1-100
            
            $generatedCount = 0;
            for ($i = 0; $i < $amount; $i++) {
                $plainKey = 'KEY-' . strtoupper(bin2hex(random_bytes(8)));
                $encryptedKey = encryptLicenseKey($plainKey);
                
                $stmt = $db->prepare("INSERT INTO license_keys (key_code, description) VALUES (?, ?)");
                if ($stmt->execute([$encryptedKey, 'Generated by Admin'])) {
                    $generatedCount++;
                }
            }
            $_SESSION['flash_success'] = "成功生成 {$generatedCount} 个卡密。";
        }

        // Reset License Key
        elseif ($_POST['action'] === 'reset_license') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE license_keys SET status = 'unused', used_at = NULL WHERE id = ?");
                if ($stmt->execute([$id])) {
                    $_SESSION['flash_success'] = "卡密状态已重置为未使用。";
                } else {
                    $_SESSION['flash_error'] = "重置失败。";
                }
            }
        }

        // Delete License Key
        elseif ($_POST['action'] === 'delete_license') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("DELETE FROM license_keys WHERE id = ?");
                if ($stmt->execute([$id])) {
                    // Reset IDs
                    try {
                        $db->exec("SET @count = 0");
                        $db->exec("UPDATE license_keys SET id = @count:= @count + 1");
                        $db->exec("ALTER TABLE license_keys AUTO_INCREMENT = 1");
                        $_SESSION['flash_success'] = "卡密已删除，ID已重置。";
                    } catch (PDOException $e) {
                         $_SESSION['flash_success'] = "卡密已删除，但ID重置失败: " . $e->getMessage();
                    }
                } else {
                    $_SESSION['flash_error'] = "删除失败。";
                }
            }
        }
        
        // Update License Note
        elseif ($_POST['action'] === 'update_note') {
            $id = (int)($_POST['id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE license_keys SET description = ? WHERE id = ?");
                if ($stmt->execute([$note, $id])) {
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Database error']);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            }
            exit;
        }

        // Update Announcement (Single Record)
        elseif ($_POST['action'] === 'update_announcement') {
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            
            if (!empty($title) && !empty($content)) {
                // Check if record exists
                $count = $db->query("SELECT COUNT(*) FROM license_announcements")->fetchColumn();
                
                if ($count > 0) {
                    // Update existing
                    $stmt = $db->prepare("UPDATE license_announcements SET title = ?, content = ?, created_at = NOW() WHERE id = (SELECT id FROM (SELECT id FROM license_announcements LIMIT 1) AS t)");
                    if ($stmt->execute([$title, $content])) {
                        $_SESSION['flash_success'] = "公告已更新。";
                    } else {
                        $_SESSION['flash_error'] = "公告更新失败。";
                    }
                } else {
                    // Insert new
                    $stmt = $db->prepare("INSERT INTO license_announcements (title, content) VALUES (?, ?)");
                    if ($stmt->execute([$title, $content])) {
                        $_SESSION['flash_success'] = "公告发布成功。";
                    } else {
                        $_SESSION['flash_error'] = "公告发布失败。";
                    }
                }
            } else {
                $_SESSION['flash_error'] = "标题和内容不能为空。";
            }
        }

        // Publish Version
        elseif ($_POST['action'] === 'publish_version') {
            $version = trim($_POST['version'] ?? '');
            $update_type = $_POST['update_type'] ?? 'patch';
            $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;
            $download_url = trim($_POST['download_url'] ?? '');
            $changelog = trim($_POST['changelog'] ?? '');
            
            if (!empty($version) && !empty($download_url)) {
                $stmt = $db->prepare("INSERT INTO license_version_updates (version, update_type, is_mandatory, download_url, changelog) VALUES (?, ?, ?, ?, ?)");
                if ($stmt->execute([$version, $update_type, $is_mandatory, $download_url, $changelog])) {
                    $msg = "新版本 {$version} 发布成功。";

                    // Send Email Notifications
                    try {
                        set_time_limit(300); // 5 minutes limit
                        
                        // Get active users emails
                        $emails = $db->query("SELECT DISTINCT contact_email FROM license_keys WHERE status = 'used' AND contact_email IS NOT NULL AND contact_email != ''")->fetchAll(PDO::FETCH_COLUMN);
                        
                        if (!empty($emails)) {
                            // Ensure PHPMailer is loaded
                            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                                loadPHPMailerLibrary();
                            }
                            
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
                            $mail->isHTML(true);
                            $mail->Subject = "系统更新通知 - {$version}";
                            
                            // Email Body
                            $siteName = getSiteName();
                            $body = "
                            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 5px; overflow: hidden;'>
                                <div style='background: #0d6efd; color: #fff; padding: 20px; text-align: center;'>
                                    <h2 style='margin:0;'>系统更新通知</h2>
                                    <p style='margin:5px 0 0;'>{$siteName}</p>
                                </div>
                                <div style='padding: 20px;'>
                                    <p>尊敬的站长，您好：</p>
                                    <p>我们发布了新的系统更新 <strong>{$version}</strong>，包含以下改进：</p>
                                    
                                    <div style='background: #f8f9fa; border-left: 4px solid #0d6efd; padding: 15px; margin: 20px 0;'>
                                        <ul style='margin: 0; padding-left: 20px;'>
                                            <li><strong>版本号：</strong> {$version}</li>
                                            <li><strong>更新类型：</strong> {$update_type}</li>
                                            <li><strong>强制更新：</strong> " . ($is_mandatory ? '是' : '否') . "</li>
                                        </ul>
                                    </div>
                                    
                                    <h3>更新日志：</h3>
                                    <pre style='background: #f1f1f1; padding: 10px; border-radius: 5px; white-space: pre-wrap; font-family: monospace;'>{$changelog}</pre>
                                    
                                    <div style='text-align: center; margin-top: 30px;'>
                                        <a href='{$download_url}' style='background: #0d6efd; color: #fff; text-decoration: none; padding: 10px 25px; border-radius: 5px; display: inline-block;'>下载更新</a>
                                    </div>
                                </div>
                                <div style='background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #666;'>
                                    <p>此邮件由系统自动发送，请勿直接回复。</p>
                                </div>
                            </div>";
                            
                            $mail->Body = $body;
                            $mail->AltBody = strip_tags($body);
                            
                            $sentCount = 0;
                            $mail->SMTPKeepAlive = true; 
                            
                            foreach ($emails as $email) {
                                try {
                                    $mail->addAddress($email);
                                    if ($mail->send()) {
                                        $sentCount++;
                                    }
                                } catch (Exception $e) {
                                }
                                $mail->clearAddresses();
                            }
                            
                            $mail->smtpClose();
                            $msg .= " (已通知 {$sentCount} 位站长)";
                        }
                    } catch (Exception $e) {
                        error_log("Email notification failed: " . $e->getMessage());
                    }

                    $_SESSION['flash_success'] = $msg;
                } else {
                    $_SESSION['flash_error'] = "版本发布失败。";
                }
            } else {
                $_SESSION['flash_error'] = "版本号和下载地址不能为空。";
            }
        }

        // Delete Version
        elseif ($_POST['action'] === 'delete_version') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("DELETE FROM license_version_updates WHERE id = ?");
                if ($stmt->execute([$id])) {
                    $_SESSION['flash_success'] = "版本记录删除成功。";
                } else {
                    $_SESSION['flash_error'] = "删除失败。";
                }
            }
        }

        // Update Version (Single Record)
        elseif ($_POST['action'] === 'update_version') {
            $id = (int)($_POST['id'] ?? 0);
            $version = trim($_POST['version'] ?? '');
            $update_type = $_POST['update_type'] ?? 'patch';
            $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;
            $download_url = trim($_POST['download_url'] ?? '');
            $changelog = trim($_POST['changelog'] ?? '');
            
            if ($id > 0 && !empty($version) && !empty($download_url)) {
                $stmt = $db->prepare("UPDATE license_version_updates SET version=?, update_type=?, is_mandatory=?, download_url=?, changelog=? WHERE id=?");
                if ($stmt->execute([$version, $update_type, $is_mandatory, $download_url, $changelog, $id])) {
                    $_SESSION['flash_success'] = "版本记录已更新。";
                } else {
                    $_SESSION['flash_error'] = "更新失败。";
                }
            } else {
                $_SESSION['flash_error'] = "无效的数据。";
            }
        }

        // Generate Version Code
        elseif ($_POST['action'] === 'generate_version_code') {
            $version = trim($_POST['version'] ?? '');
            $key = trim($_POST['key'] ?? 'asdfghjkl');
            
            if (empty($version)) {
                echo json_encode(['status' => 'error', 'message' => '版本号不能为空']);
                exit;
            }

            // Encryption Logic (AES-128-ECB)
            $keyPad = str_pad($key, 16, "\0"); 
            $encrypted = openssl_encrypt($version, 'AES-128-ECB', $keyPad, OPENSSL_RAW_DATA);
            $encrypted_version = base64_encode($encrypted);

            // Generate Code Snippet
            $code  = "// Encrypted Version Definition\n";
            $code .= "\$sys_conf = require __DIR__ . '/keys/system_core.php';\n";
            $code .= "\$v_key = str_pad(\$sys_conf['security_token'], 16, \"\\0\");\n";
            $code .= "\$v_data = base64_decode('" . $encrypted_version . "'); // Encrypted " . $version . "\n";
            $code .= "define('SYSTEM_VERSION', openssl_decrypt(\$v_data, 'AES-128-ECB', \$v_key, OPENSSL_RAW_DATA));";

            echo json_encode([
                'status' => 'success',
                'encrypted_version' => $encrypted_version,
                'code' => $code
            ]);
            exit;
        }

        // Generate RSA Keys
        elseif ($_POST['action'] === 'generate_keys') {
            $config = [
                "digest_alg" => "sha256",
                "private_key_bits" => 2048,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            ];

            // 1. 生成密钥对
            $res = openssl_pkey_new($config);

            // 2. 生成随机密码 (用于加密私钥)
            $passphrase = bin2hex(random_bytes(16)); // 32字符的随机密码

            // 3. 提取私钥 (使用密码加密)
            openssl_pkey_export($res, $privateKey, $passphrase);

            // 4. 提取公钥
            $publicKey = openssl_pkey_get_details($res);
            $publicKey = $publicKey["key"];

            // 5. 保存路径
            $keyDir = __DIR__ . '/../config/keys/';
            if (!is_dir($keyDir)) {
                mkdir($keyDir, 0755, true);
            }

            // 6. 保存文件
            $res1 = file_put_contents($keyDir . 'private.pem', $privateKey);
            $res2 = file_put_contents($keyDir . 'public.pem', $publicKey);

            // 7. 保存密码到 PHP 文件
            $passContent = "<?php\nreturn '" . $passphrase . "';\n";
            $res3 = file_put_contents($keyDir . 'secret.php', $passContent);
            
            // 8. 创建 .htaccess 保护文件 (如果不存在)
            $htaccessFile = $keyDir . '.htaccess';
            if (!file_exists($htaccessFile)) {
                $htaccessContent = "# 禁止访问除 public.pem 外的所有文件\n<Files \"*\">\n    Order Deny,Allow\n    Deny from all\n</Files>\n\n<Files \"public.pem\">\n    Order Allow,Deny\n    Allow from all\n</Files>";
                file_put_contents($htaccessFile, $htaccessContent);
            }

            if ($res1 && $res2 && $res3) {
                $_SESSION['flash_success'] = "RSA 密钥对已成功重新生成并更新。";
            } else {
                $_SESSION['flash_error'] = "密钥保存失败，请检查目录权限。";
            }
        }
        
        // Redirect to prevent form resubmission
        $tab = 'license';
        if ($_POST['action'] === 'update_announcement' || $_POST['action'] === 'delete_announcement' || $_POST['action'] === 'toggle_announcement') {
            $tab = 'announcement';
        } elseif ($_POST['action'] === 'update_version' || $_POST['action'] === 'delete_version') {
            $tab = 'updates';
        } elseif ($_POST['action'] === 'clear_logs') {
            $tab = 'logs';
        } elseif ($_POST['action'] === 'generate_keys') {
            $tab = 'keys';
        }
        
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=' . $tab);
        exit;
    }
}

// Fetch Data
$currentTab = $_GET['tab'] ?? 'license';

$licenses = $db->query("SELECT * FROM license_keys ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$currentAnnouncement = $db->query("SELECT * FROM license_announcements LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$versions = $db->query("SELECT * FROM license_version_updates ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Check Keys Status
$keyDir = __DIR__ . '/../config/keys/';
$hasKeys = file_exists($keyDir . 'private.pem') && file_exists($keyDir . 'public.pem') && file_exists($keyDir . 'secret.php');
$publicKeyContent = $hasKeys ? file_get_contents($keyDir . 'public.pem') : '';

$page_title = '开源版本管理';
$extra_css = <<<'CSS'
.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.05);
    margin-bottom: 1.5rem;
}
.card-header {
    background-color: #fff;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    padding: 1.25rem 1.5rem;
    border-radius: 12px 12px 0 0 !important;
}
.nav-tabs .nav-link {
    color: #6c757d;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 1rem 1.5rem;
    font-weight: 500;
    transition: all 0.2s;
}
.nav-tabs .nav-link.active {
    color: #0d6efd;
    border-bottom: 2px solid #0d6efd;
    background: none;
}
.nav-tabs .nav-link:hover:not(.active) {
    color: #0d6efd;
    border-color: transparent;
    background-color: #f8f9fa;
}
.version-badge {
    font-size: 0.9em;
    padding: 0.4em 0.8em;
    border-radius: 20px;
}
CSS;
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 text-gray-800">开源版本管理</h1>
                        <p class="text-muted">检查更新、版本授权与维护</p>
                    </div>
                </div>
                
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header bg-white p-0 border-bottom-0">
                        <ul class="nav nav-tabs card-header-tabs m-0 px-3" id="versionTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $currentTab === 'license' ? 'active' : '' ?>" id="tab-license" data-bs-toggle="tab" data-bs-target="#content-license" type="button" role="tab">
                                    <i class="bi bi-shield-lock me-2"></i> 授权管理
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $currentTab === 'announcement' ? 'active' : '' ?>" id="tab-announcement" data-bs-toggle="tab" data-bs-target="#content-announcement" type="button" role="tab">
                                    <i class="bi bi-megaphone me-2"></i> 公告管理
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $currentTab === 'updates' ? 'active' : '' ?>" id="tab-updates" data-bs-toggle="tab" data-bs-target="#content-updates" type="button" role="tab">
                                    <i class="bi bi-rocket-takeoff me-2"></i> 版本发布
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $currentTab === 'logs' ? 'active' : '' ?>" id="tab-logs" data-bs-toggle="tab" data-bs-target="#content-logs" type="button" role="tab">
                                    <i class="bi bi-clock-history me-2"></i> 验证日志
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $currentTab === 'keys' ? 'active' : '' ?>" id="tab-keys" data-bs-toggle="tab" data-bs-target="#content-keys" type="button" role="tab">
                                    <i class="bi bi-key me-2"></i> 密钥管理
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="tab-content" id="versionTabContent">
                    <!-- 授权管理 -->
                    <div class="tab-pane fade <?= $currentTab === 'license' ? 'show active' : '' ?>" id="content-license" role="tabpanel">
                        <div class="card">
                            <div class="card-body">
                                <form method="POST" class="row g-3 align-items-end mb-4">
                                    <input type="hidden" name="action" value="generate">
                                    <div class="col-auto">
                                        <label class="form-label">生成数量</label>
                                        <input type="number" name="amount" class="form-control" value="1" min="1" max="100">
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-plus-lg me-1"></i> 生成卡密
                                        </button>
                                        <button type="button" class="btn btn-info text-white ms-2" id="btnCheckAll">
                                            <i class="bi bi-activity me-1"></i> 一键检测在线
                                        </button>
                                    </div>
                                </form>

                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>ID</th>
                                                <th>卡密</th>
                                                <th>状态</th>
                                                <th>授权信息</th>
                                                <th>使用时间</th>
                                                <th>备注</th>
                                                <th class="text-end">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($licenses as $lic): ?>
                                            <?php $displayKey = decryptLicenseKey($lic['key_code']); ?>
                                            <tr>
                                                <td><?= $lic['id'] ?></td>
                                                <td><code><?= htmlspecialchars($displayKey) ?></code></td>
                                                <td>
                                                    <?php if ($lic['status'] === 'used'): ?>
                                                        <span class="badge bg-success">已使用</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">未使用</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($lic['status'] === 'used'): ?>
                                                        <?php if (!empty($lic['domain'])): ?>
                                                            <div class="server-link text-primary" style="cursor:pointer;" data-domain="<?= htmlspecialchars($lic['domain']) ?>" title="点击检测服务器状态">
                                                                <i class="bi bi-globe me-1"></i> <?= htmlspecialchars($lic['domain']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($lic['contact_email'])): ?>
                                                            <div><i class="bi bi-envelope me-1"></i> <a href="mailto:<?= htmlspecialchars($lic['contact_email']) ?>" class="text-decoration-none"><?= htmlspecialchars($lic['contact_email']) ?></a></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($lic['verification_id'])): ?>
                                                            <div class="text-muted small" title="验证ID: <?= htmlspecialchars($lic['verification_id']) ?>" style="cursor:help;">
                                                                <i class="bi bi-hdd-network me-1"></i> ID: <?= substr($lic['verification_id'], 0, 8) ?>...
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (empty($lic['domain']) && empty($lic['verification_id'])): ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $lic['used_at'] ?? '-' ?></td>
                                                <td>
                                                    <div class="input-group input-group-sm" style="max-width: 150px;">
                                                        <input type="text" class="form-control form-control-sm border-0 bg-transparent note-input" 
                                                               value="<?= htmlspecialchars($lic['description']) ?>" 
                                                               data-id="<?= $lic['id'] ?>" 
                                                               placeholder="点击添加备注..."
                                                               onchange="updateNote(this)">
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($lic['status'] === 'used'): ?>
                                                    <form method="POST" onsubmit="return confirm('确定要重置此卡密的状态为未使用吗？');" style="display: inline;">
                                                        <input type="hidden" name="action" value="reset_license">
                                                        <input type="hidden" name="id" value="<?= $lic['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="重置激活状态">
                                                            <i class="bi bi-arrow-counterclockwise"></i> 重置
                                                        </button>
                                                    </form>
                                                    <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-secondary" disabled>
                                                        <i class="bi bi-arrow-counterclockwise"></i> 重置
                                                    </button>
                                                    <?php endif; ?>

                                                    <form method="POST" onsubmit="return confirm('确定要删除此卡密吗？删除后ID将重新排序。');" style="display: inline;">
                                                        <input type="hidden" name="action" value="delete_license">
                                                        <input type="hidden" name="id" value="<?= $lic['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger ms-1" title="删除">
                                                            <i class="bi bi-trash"></i> 删除
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($licenses)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-3">暂无卡密记录</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 公告管理 -->
                    <div class="tab-pane fade <?= $currentTab === 'announcement' ? 'show active' : '' ?>" id="content-announcement" role="tabpanel">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h5 class="m-0"><i class="bi bi-pencil-square me-2"></i>编辑当前公告</h5>
                                </div>
                                
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_announcement">
                                    <div class="mb-3">
                                        <label class="form-label">公告标题</label>
                                        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($currentAnnouncement['title'] ?? '') ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">公告内容</label>
                                        <textarea name="content" class="form-control" rows="8" required><?= htmlspecialchars($currentAnnouncement['content'] ?? '') ?></textarea>
                                    </div>
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-primary px-4">
                                            <i class="bi bi-save me-1"></i> 保存公告
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- 版本发布 -->
                    <div class="tab-pane fade <?= $currentTab === 'updates' ? 'show active' : '' ?>" id="content-updates" role="tabpanel">
                        <!-- Version Encryption Tool -->
                        <div class="card mb-4">
                            <div class="card-header bg-white">
                                <h5 class="m-0"><i class="bi bi-tools me-2"></i>版本号加密工具</h5>
                            </div>
                            <div class="card-body">
                                <form id="encryptionForm" class="row g-3 align-items-end">
                                    <div class="col-md-5">
                                        <label class="form-label">版本号</label>
                                        <input type="text" class="form-control" id="toolVersion" placeholder="例如: 1.0.0">
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">加密密钥 (对应 security_token)</label>
                                        <input type="text" class="form-control" id="toolKey" value="asdfghjkl">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-secondary w-100" onclick="generateVersionCode(this)">
                                            <i class="bi bi-code-slash me-1"></i> 生成代码
                                        </button>
                                    </div>
                                </form>
                                <div id="encryptionResult" class="mt-3 d-none">
                                    <label class="form-label text-muted small">生成的 PHP 代码 (请复制到 config/functions.php):</label>
                                    <div class="position-relative">
                                        <textarea class="form-control font-monospace bg-light" rows="5" readonly id="generatedCode" style="font-size: 0.85rem;"></textarea>
                                        <button class="btn btn-sm btn-outline-primary position-absolute top-0 end-0 m-2" onclick="copyCode()">
                                            <i class="bi bi-clipboard"></i> 复制
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h5 class="m-0"><i class="bi bi-rocket-takeoff me-2"></i>发布新版本</h5>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="action" value="publish_version">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">版本号</label>
                                            <input type="text" name="version" class="form-control" placeholder="例如: v1.0.1" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">更新类型</label>
                                            <div class="input-group">
                                                <select name="update_type" class="form-select">
                                                    <option value="patch">补丁更新 (Patch)</option>
                                                    <option value="minor">功能更新 (Minor)</option>
                                                    <option value="major">重大更新 (Major)</option>
                                                </select>
                                                <div class="input-group-text bg-white">
                                                    <div class="form-check m-0">
                                                        <input class="form-check-input" type="checkbox" name="is_mandatory" value="1" id="isMandatory">
                                                        <label class="form-check-label" for="isMandatory">强制更新</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">下载/更新包地址</label>
                                        <input type="text" name="download_url" class="form-control" placeholder="请输入更新包的下载链接" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">更新日志</label>
                                        <textarea name="changelog" class="form-control" rows="6" placeholder="1. 修复了...&#10;2. 新增了..." required></textarea>
                                    </div>
                                    <div class="text-end">
                                        <button type="submit" class="btn btn-primary px-4">
                                            <i class="bi bi-send me-1"></i> 立即发布
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- 历史版本列表 -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="m-0">历史版本记录</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead>
                                            <tr>
                                                <th>版本号</th>
                                                <th>类型</th>
                                                <th>发布时间</th>
                                                <th>下载地址</th>
                                                <th class="text-end">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($versions as $ver): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-primary"><?= htmlspecialchars($ver['version']) ?></span>
                                                    <?php if(!empty($ver['is_mandatory'])): ?>
                                                        <span class="badge bg-warning text-dark ms-1">强制</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if($ver['update_type']=='major'): ?>
                                                        <span class="badge bg-danger">重大更新</span>
                                                    <?php elseif($ver['update_type']=='minor'): ?>
                                                        <span class="badge bg-info text-dark">功能更新</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">补丁更新</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $ver['created_at'] ?></td>
                                                <td>
                                                    <a href="<?= htmlspecialchars($ver['download_url']) ?>" target="_blank" class="text-truncate d-inline-block" style="max-width: 200px;">
                                                        <?= htmlspecialchars($ver['download_url']) ?>
                                                    </a>
                                                </td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                                            data-version="<?= htmlspecialchars(json_encode($ver), ENT_QUOTES, 'UTF-8') ?>"
                                                            onclick="editVersion(this)">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form method="POST" onsubmit="return confirm('确定删除此版本记录？');" style="display: inline;">
                                                        <input type="hidden" name="action" value="delete_version">
                                                        <input type="hidden" name="id" value="<?= $ver['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if(empty($versions)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-3">暂无发布记录</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 验证日志 -->
                    <div class="tab-pane fade <?= $currentTab === 'logs' ? 'show active' : '' ?>" id="content-logs" role="tabpanel">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="m-0"><i class="bi bi-clock-history me-2"></i>授权验证日志</h5>
                                <form method="POST" onsubmit="return confirm('确定清空所有验证日志？');">
                                    <input type="hidden" name="action" value="clear_logs">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash me-1"></i> 清空日志
                                    </button>
                                </form>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>ID</th>
                                                <th>卡密 (Partial)</th>
                                                <th>Verification ID</th>
                                                <th>系统版本</th>
                                                <th>域名</th>
                                                <th>IP地址</th>
                                                <th>状态</th>
                                                <th>验证时间</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $page = isset($_GET['log_page']) ? (int)$_GET['log_page'] : 1;
                                            $perPage = 15;
                                            $offset = ($page - 1) * $perPage;
                                            
                                            $totalLogs = $db->query("SELECT COUNT(*) FROM license_verification_logs")->fetchColumn();
                                            $totalPages = ceil($totalLogs / $perPage);
                                            
                                            $logs = $db->query("SELECT * FROM license_verification_logs ORDER BY id DESC LIMIT $offset, $perPage")->fetchAll(PDO::FETCH_ASSOC);
                                            
                                            foreach ($logs as $log): 
                                            ?>
                                            <tr>
                                                <td><?= $log['id'] ?></td>
                                                <td><code class="text-muted"><?= htmlspecialchars(substr($log['license_key'], 0, 10)) ?>...</code></td>
                                                <td><small><?= htmlspecialchars($log['verification_id']) ?></small></td>
                                                <td>
                                                    <?php if (!empty($log['system_version'])): ?>
                                                        <span class="badge bg-info text-dark"><?= htmlspecialchars($log['system_version']) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($log['domain']): ?>
                                                        <a href="http://<?= htmlspecialchars($log['domain']) ?>" target="_blank" class="text-decoration-none">
                                                            <?= htmlspecialchars($log['domain']) ?> <i class="bi bi-box-arrow-up-right small"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($log['ip_address']) ?></td>
                                                <td>
                                                    <?php if ($log['status'] === 'valid'): ?>
                                                        <span class="badge bg-success">验证通过</span>
                                                    <?php elseif ($log['status'] === 'expired'): ?>
                                                        <span class="badge bg-warning text-dark">已过期</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">验证失败</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $log['check_time'] ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            
                                            <?php if (empty($logs)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-4">暂无验证记录</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php if ($totalPages > 1): ?>
                                <div class="card-footer clearfix">
                                    <ul class="pagination pagination-sm m-0 justify-content-end flex-wrap">
                                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?log_page=<?= $page - 1 ?>&tab=logs">&laquo;</a>
                                        </li>
                                        <?php
                                        // Show limited pagination range with ellipsis
                                        $maxButtons = 7;
                                        if ($totalPages <= $maxButtons) {
                                            $start = 1;
                                            $end = $totalPages;
                                        } else {
                                            $sidebar = floor(($maxButtons - 3) / 2);
                                            $start = $page - $sidebar;
                                            $end = $page + $sidebar;
                                            if ($start < 2) { $start = 2; $end = $maxButtons - 1; }
                                            if ($end > $totalPages - 1) { $end = $totalPages - 1; $start = $totalPages - $maxButtons + 2; }
                                        }
                                        // Always show first page
                                        if ($totalPages > $maxButtons && $start > 2): ?>
                                        <li class="page-item <?= $page == 1 ? 'active' : '' ?>">
                                            <a class="page-link" href="?log_page=1&tab=logs">1</a>
                                        </li>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                        <?php elseif ($totalPages > $maxButtons && $start == 2): ?>
                                        <li class="page-item <?= $page == 1 ? 'active' : '' ?>">
                                            <a class="page-link" href="?log_page=1&tab=logs">1</a>
                                        </li>
                                        <?php endif; ?>
                                        <?php for ($i = $start; $i <= $end; $i++): ?>
                                        <li class="page-item <?= $page == $i ? 'active' : '' ?>">
                                            <a class="page-link" href="?log_page=<?= $i ?>&tab=logs"><?= $i ?></a>
                                        </li>
                                        <?php endfor; ?>
                                        <?php if ($totalPages > $maxButtons && $end < $totalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                        <li class="page-item <?= $page == $totalPages ? 'active' : '' ?>">
                                            <a class="page-link" href="?log_page=<?= $totalPages ?>&tab=logs"><?= $totalPages ?></a>
                                        </li>
                                        <?php elseif ($totalPages > $maxButtons && $end == $totalPages - 1): ?>
                                        <li class="page-item <?= $page == $totalPages ? 'active' : '' ?>">
                                            <a class="page-link" href="?log_page=<?= $totalPages ?>&tab=logs"><?= $totalPages ?></a>
                                        </li>
                                        <?php endif; ?>
                                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?log_page=<?= $page + 1 ?>&tab=logs">&raquo;</a>
                                        </li>
                                    </ul>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 密钥管理 -->
                    <div class="tab-pane fade <?= $currentTab === 'keys' ? 'show active' : '' ?>" id="content-keys" role="tabpanel">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="m-0"><i class="bi bi-shield-lock-fill me-2"></i>RSA 密钥对管理</h5>
                                <?php if ($hasKeys): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>已配置</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>未配置</span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i> 
                                    <strong>警告：</strong> 重新生成密钥会导致所有旧版客户端无法连接（除非它们更新为新的公钥）。请谨慎操作！
                                </div>

                                <div class="mb-4">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label fw-bold m-0">当前公钥 (Public Key)</label>
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="copyToClipboard()">
                                                <i class="bi bi-clipboard"></i> 复制
                                            </button>
                                            <a href="/config/keys/public.pem" download="public.pem" class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="bi bi-download"></i> 下载
                                            </a>
                                        </div>
                                    </div>
                                    <textarea id="publicKeyContent" class="form-control font-monospace bg-light" rows="6" readonly><?= htmlspecialchars($publicKeyContent) ?></textarea>
                                    <div class="form-text">此公钥需要分发给客户端软件用于加密通信。</div>
                                </div>

                                <script>
                                function copyToClipboard() {
                                    var copyText = document.getElementById("publicKeyContent");
                                    copyText.select();
                                    copyText.setSelectionRange(0, 99999); 
                                    navigator.clipboard.writeText(copyText.value).then(function() {
                                        alert("公钥已复制到剪贴板！");
                                    }, function(err) {
                                        console.error('Could not copy text: ', err);
                                    });
                                }
                                </script>

                                <div class="mb-4">
                                    <label class="form-label fw-bold">私钥状态</label>
                                    <div>
                                        <?php if ($hasKeys): ?>
                                            <div class="text-success"><i class="bi bi-lock-fill"></i> 私钥文件 (private.pem) 已存在且受密码保护。</div>
                                            <div class="text-success"><i class="bi bi-key-fill"></i> 密码文件 (secret.php) 已存在。</div>
                                        <?php else: ?>
                                            <div class="text-danger">相关文件缺失，请点击下方按钮生成。</div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <hr>

                                <form method="POST" onsubmit="return confirm('⚠️ 高危操作确认：\n\n重新生成密钥将使所有现有的客户端连接失效！\n\n确定要继续吗？');">
                                    <input type="hidden" name="action" value="generate_keys">
                                    <button type="submit" class="btn btn-danger">
                                        <i class="bi bi-arrow-repeat me-1"></i> 
                                        <?= $hasKeys ? '重新生成密钥对' : '生成密钥对' ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>


    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    
    <!-- Version Edit Modal -->
    <div class="modal fade" id="versionEditModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">编辑版本</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="versionEditForm">
                        <input type="hidden" name="action" value="update_version">
                        <input type="hidden" name="id" id="editVersionId">
                        
                        <div class="mb-3">
                            <label class="form-label">版本号</label>
                            <input type="text" name="version" id="editVersionName" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">更新类型</label>
                            <div class="input-group">
                                <select name="update_type" id="editUpdateType" class="form-select">
                                    <option value="patch">补丁更新 (Patch)</option>
                                    <option value="minor">功能更新 (Minor)</option>
                                    <option value="major">重大更新 (Major)</option>
                                </select>
                                <div class="input-group-text bg-white">
                                    <div class="form-check m-0">
                                        <input class="form-check-input" type="checkbox" name="is_mandatory" value="1" id="editIsMandatory">
                                        <label class="form-check-label" for="editIsMandatory">强制更新</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">下载地址</label>
                            <input type="text" name="download_url" id="editDownloadUrl" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">更新日志</label>
                            <textarea name="changelog" id="editChangelog" class="form-control" rows="5" required></textarea>
                        </div>

                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">保存更改</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- 服务器状态检测 Modal -->
    <div class="modal fade" id="serverCheckModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-hdd-network me-2"></i>服务器状态检测: <span id="checkDomainTitle" class="text-primary font-monospace"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="checkLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">正在连接服务器并获取前台内容...</p>
                    </div>
                    <div id="checkResult" class="d-none h-100">
                        <iframe id="previewFrame" style="width:100%; height:100%; min-height:600px; border:none;"></iframe>
                    </div>
                    <div id="checkError" class="d-none text-center py-5">
                        <i class="bi bi-x-circle text-danger" style="font-size: 3rem;"></i>
                        <h5 class="mt-3 text-danger">连接失败</h5>
                        <p class="text-muted" id="errorMessage"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function generateVersionCode(btn) {
        var version = document.getElementById('toolVersion').value;
        var key = document.getElementById('toolKey').value;
        
        if(!version) {
            alert('请输入版本号');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'generate_version_code');
        formData.append('version', version);
        formData.append('key', key);

        var originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 生成中...';

        fetch('opensource.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            
            if (data.status === 'success') {
                document.getElementById('generatedCode').value = data.code;
                document.getElementById('encryptionResult').classList.remove('d-none');
            } else {
                alert(data.message || '生成失败');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            btn.disabled = false;
            btn.innerHTML = originalText;
            alert('请求失败');
        });
    }

    function copyCode() {
        var copyText = document.getElementById("generatedCode");
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value).then(function() {
            alert("代码已复制！");
        }, function(err) {
             document.execCommand('copy');
             alert("代码已复制！");
        });
    }

    function editVersion(btn) {
        var ver = JSON.parse(btn.getAttribute('data-version'));
        
        document.getElementById('editVersionId').value = ver.id;
        document.getElementById('editVersionName').value = ver.version;
        document.getElementById('editUpdateType').value = ver.update_type;
        document.getElementById('editIsMandatory').checked = ver.is_mandatory == 1;
        document.getElementById('editDownloadUrl').value = ver.download_url;
        document.getElementById('editChangelog').value = ver.changelog;
        
        new bootstrap.Modal(document.getElementById('versionEditModal')).show();
    }

    function updateNote(input) {
        var id = input.getAttribute('data-id');
        var note = input.value;
        var originalBg = input.style.backgroundColor;
        
        // Visual feedback - saving
        input.style.backgroundColor = '#fff3cd'; // Yellowish for saving
        
        var formData = new FormData();
        formData.append('action', 'update_note');
        formData.append('id', id);
        formData.append('note', note);
        
        fetch('opensource.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                // Success feedback
                input.style.backgroundColor = '#d1e7dd'; // Greenish
                setTimeout(() => {
                    input.style.backgroundColor = 'transparent';
                }, 1000);
            } else {
                alert('保存失败: ' + (data.message || 'Unknown error'));
                input.style.backgroundColor = '#f8d7da'; // Reddish
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('请求失败，请检查网络');
            input.style.backgroundColor = '#f8d7da';
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        var serverCheckModal = new bootstrap.Modal(document.getElementById('serverCheckModal'));
        var checkLinks = document.querySelectorAll('.server-link');
        
        // 单个检测点击事件
        checkLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                var domain = this.getAttribute('data-domain');
                document.getElementById('checkDomainTitle').textContent = domain;
                
                // Reset UI
                document.getElementById('checkLoading').classList.remove('d-none');
                document.getElementById('checkResult').classList.add('d-none');
                document.getElementById('checkError').classList.add('d-none');
                document.getElementById('previewFrame').srcdoc = '';
                
                serverCheckModal.show();
                
                // Fetch status
                fetch('?action=check_site&url=' + encodeURIComponent(domain))
                    .then(response => response.json())
                    .then(data => {
                        document.getElementById('checkLoading').classList.add('d-none');
                        
                        if (data.status === 'online') {
                            document.getElementById('checkResult').classList.remove('d-none');
                            document.getElementById('previewFrame').srcdoc = data.content;
                        } else {
                            document.getElementById('checkError').classList.remove('d-none');
                            document.getElementById('errorMessage').textContent = data.error || '无法连接到服务器';
                        }
                    })
                    .catch(error => {
                        document.getElementById('checkLoading').classList.add('d-none');
                        document.getElementById('checkError').classList.remove('d-none');
                        document.getElementById('errorMessage').textContent = '请求发生错误: ' + error.message;
                    });
            });
        });

        // 一键批量检测
        document.getElementById('btnCheckAll').addEventListener('click', function() {
            var btn = this;
            var originalText = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> 检测中...';
            
            var promises = [];
            var links = document.querySelectorAll('.server-link');
            
            if (links.length === 0) {
                alert('当前页面没有可检测的域名。');
                btn.disabled = false;
                btn.innerHTML = originalText;
                return;
            }

            links.forEach(function(link) {
                var domain = link.getAttribute('data-domain');
                // Add a status indicator if not exists
                var statusSpan = link.querySelector('.status-indicator');
                if (!statusSpan) {
                    statusSpan = document.createElement('span');
                    statusSpan.className = 'status-indicator ms-2';
                    link.appendChild(statusSpan);
                }
                statusSpan.innerHTML = '<div class="spinner-border spinner-border-sm text-secondary" style="width:0.8rem;height:0.8rem;border-width:0.15em;"></div>';
                
                // 使用 Promise 处理并发请求
                var p = fetch('?action=check_site&url=' + encodeURIComponent(domain))
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'online') {
                            statusSpan.innerHTML = '<i class="bi bi-check-circle-fill text-success" title="在线"></i>';
                        } else {
                            statusSpan.innerHTML = '<i class="bi bi-x-circle-fill text-danger" title="离线/无法访问: ' + (data.error || 'Unknown') + '"></i>';
                        }
                    })
                    .catch(error => {
                        statusSpan.innerHTML = '<i class="bi bi-question-circle-fill text-warning" title="检测出错"></i>';
                    });
                promises.push(p);
            });
            
            // 等待所有请求完成
            Promise.allSettled(promises).then(() => {
                btn.disabled = false;
                btn.innerHTML = originalText;
            });
        });
    });
    </script>
<?php require_once 'includes/footer.php'; ?>
