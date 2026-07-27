<?php
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/email_config.php';
// 检查登录
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$db = getDB();
$config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
$message = '';
$error = '';

// 确保申诉表存在
try {
    $db->exec("CREATE TABLE IF NOT EXISTS appeals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        appeal_type ENUM('ip', 'user', 'ip_user') NOT NULL COMMENT '申诉类型',
        ip_address VARCHAR(45) NOT NULL COMMENT '申诉IP地址',
        user_id INT DEFAULT NULL COMMENT '关联用户ID',
        contact_name VARCHAR(50) NOT NULL COMMENT '联系人姓名',
        contact_email VARCHAR(100) NOT NULL COMMENT '联系邮箱',
        reason TEXT NOT NULL COMMENT '申诉理由',
        status ENUM('pending', 'approved', 'rejected', 'processing') DEFAULT 'pending' COMMENT '状态',
        admin_reply TEXT DEFAULT NULL COMMENT '管理员回复',
        reviewed_by INT DEFAULT NULL COMMENT '审核管理员ID',
        reviewed_at DATETIME DEFAULT NULL COMMENT '审核时间',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '提交时间',
        INDEX idx_type (appeal_type),
        INDEX idx_status (status),
        INDEX idx_user (user_id),
        INDEX idx_ip (ip_address),
        INDEX idx_email (contact_email),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='申诉记录表';");
} catch (Exception $e) {
    error_log('Appeal table error: ' . $e->getMessage());
}

// 处理添加管理员
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $response = ['success' => false, 'message' => ''];

    if ($_POST['action'] === 'add') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'user';
        
        if (empty($username) || empty($password)) {
            $response['message'] = '用户名和密码不能为空';
        } else {
            // 检查用户名是否已存在
            $stmt = $db->prepare("SELECT id FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $response['message'] = '用户名已存在';
            } else {
                $hashedPassword = hashPassword($password);
                $stmt = $db->prepare("INSERT INTO admins (username, password, email, role) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$username, $hashedPassword, $email, $role])) {
                    $newId = $db->lastInsertId();
                    $response['success'] = true;
                    $response['message'] = '管理员添加成功';
                    $response['data'] = [
                        'id' => $newId,
                        'username' => $username,
                        'email' => $email,
                        'role' => $role,
                        'is_banned' => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                        'last_login' => null
                    ];
                } else {
                    $response['message'] = '添加失败';
                }
            }
        }
    } elseif ($_POST['action'] === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $password = $_POST['password'] ?? '';
        
        if (empty($username)) {
            $response['message'] = '用户名不能为空';
        } else {
            // 检查用户名是否与其他用户重复
            $stmt = $db->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
            $stmt->execute([$username, $id]);
            if ($stmt->fetch()) {
                $response['message'] = '用户名已存在';
            } else {
                // 如果提供了新密码，则更新密码
                if (!empty($password)) {
                    $hashedPassword = hashPassword($password);
                    $stmt = $db->prepare("UPDATE admins SET username = ?, email = ?, role = ?, password = ? WHERE id = ?");
                    if ($stmt->execute([$username, $email, $role, $hashedPassword, $id])) {
                        $response['success'] = true;
                        $response['message'] = '用户信息更新成功';
                    } else {
                        $response['message'] = '更新失败';
                    }
                } else {
                    $stmt = $db->prepare("UPDATE admins SET username = ?, email = ?, role = ? WHERE id = ?");
                    if ($stmt->execute([$username, $email, $role, $id])) {
                        $response['success'] = true;
                        $response['message'] = '用户信息更新成功';
                    } else {
                        $response['message'] = '更新失败';
                    }
                }
                if ($response['success']) {
                    $response['data'] = [
                        'id' => $id,
                        'username' => $username,
                        'email' => $email,
                        'role' => $role
                    ];
                }
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id === $_SESSION['admin_id']) {
            $response['message'] = '不能删除当前登录的管理员';
        } else {
            $stmt = $db->prepare("DELETE FROM admins WHERE id = ?");
            if ($stmt->execute([$id])) {
                $response['success'] = true;
                $response['message'] = '管理员删除成功';
                $response['data'] = ['id' => $id];
            } else {
                $response['message'] = '删除失败';
            }
        }
    } elseif ($_POST['action'] === 'change_password') {
        $id = intval($_POST['id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        
        if (empty($newPassword)) {
            $response['message'] = '新密码不能为空';
        } else {
            $hashedPassword = hashPassword($newPassword);
            $stmt = $db->prepare("UPDATE admins SET password = ? WHERE id = ?");
            if ($stmt->execute([$hashedPassword, $id])) {
                $response['success'] = true;
                $response['message'] = '密码修改成功';
            } else {
                $response['message'] = '密码修改失败';
            }
        }
    } elseif ($_POST['action'] === 'get_user_ban_ips') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $response['message'] = '无效的用户ID';
        } else {
            $stmt = $db->prepare("SELECT DISTINCT ip_address, MAX(login_at) as last_login_at
                FROM user_sessions WHERE user_id = ? AND ip_address != 'unknown'
                GROUP BY ip_address ORDER BY last_login_at DESC");
            $stmt->execute([$userId]);
            $ips = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($ips as &$ip) {
                $shareStmt = $db->prepare("
                    SELECT DISTINCT u.id, u.username, u.is_banned
                    FROM user_sessions us
                    JOIN admins u ON us.user_id = u.id
                    WHERE us.ip_address = ? AND us.user_id != ?
                    ORDER BY u.username");
                $shareStmt->execute([$ip['ip_address'], $userId]);
                $ip['shared_users'] = $shareStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $response['success'] = true;
            $response['data'] = $ips;
        }
    } elseif ($_POST['action'] === 'toggle_ban') {
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0); // 0: 正常, 1: 封禁
        $banIp = intval($_POST['ban_ip'] ?? 0); // 0: 不封IP, 1: 同时封禁所有IP
        $banIpList = $_POST['ban_ip_list'] ?? ''; // 指定封禁的IP列表（逗号分隔）

        if ($id === $_SESSION['admin_id']) {
            $response['message'] = '不能封禁当前登录的管理员';
        } else {
            // Check if is_banned column exists
            $checkStmt = $db->query("SHOW COLUMNS FROM admins LIKE 'is_banned'");
            if (!$checkStmt->fetch()) {
                $db->exec("ALTER TABLE admins ADD COLUMN is_banned TINYINT(1) DEFAULT 0 COMMENT '是否封禁: 0-正常, 1-封禁'");
            }

            $stmt = $db->prepare("UPDATE admins SET is_banned = ? WHERE id = ?");
            if ($stmt->execute([$status, $id])) {
                $response['success'] = true;
                $extra = '';
                if ($status) {
                    adminRemoveAllDevices($id);
                    // 封禁 IP
                    if ($banIpList !== '') {
                        // 封禁指定 IP 列表
                        $ips = array_filter(array_map('trim', explode(',', $banIpList)));
                        $bannedCount = 0;
                        foreach ($ips as $ip) {
                            addBotBlacklist($ip, '用户封禁 - 管理员操作');
                            $bannedCount++;
                        }
                        if ($bannedCount > 0) {
                            $extra = "，已封禁 {$bannedCount} 个IP";
                        }
                    } elseif ($banIp) {
                        // 封禁该用户历史所有 IP
                        $ipStmt = $db->prepare("SELECT DISTINCT ip_address FROM user_sessions WHERE user_id = ? AND ip_address != 'unknown'");
                        $ipStmt->execute([$id]);
                        $ips = $ipStmt->fetchAll(PDO::FETCH_COLUMN);
                        $bannedCount = 0;
                        foreach ($ips as $ip) {
                            addBotBlacklist($ip, '用户封禁 - 管理员操作');
                            $bannedCount++;
                        }
                        if ($bannedCount > 0) {
                            $extra = "，已封禁 {$bannedCount} 个IP";
                        }
                    }
                    $response['message'] = '用户已封禁' . $extra;
                } else {
                    // 解封时同时解封该用户的所有 IP
                    if ($banIp) {
                        $ipStmt = $db->prepare("SELECT DISTINCT ip_address FROM user_sessions WHERE user_id = ? AND ip_address != 'unknown'");
                        $ipStmt->execute([$id]);
                        $ips = $ipStmt->fetchAll(PDO::FETCH_COLUMN);
                        $unbannedCount = 0;
                        foreach ($ips as $ip) {
                            $delStmt = $db->prepare("DELETE FROM bot_blacklist WHERE ip_address = ? AND reason LIKE '%用户封禁%'");
                            $delStmt->execute([$ip]);
                            if ($delStmt->rowCount() > 0) $unbannedCount++;
                        }
                        if ($unbannedCount > 0) {
                            $extra = "，已解封 {$unbannedCount} 个IP";
                        }
                    }
                    $response['message'] = '用户已解封' . $extra;
                }
                $response['data'] = ['id' => $id, 'is_banned' => $status];
            } else {
                $response['message'] = '操作失败';
            }
        }
    } elseif ($_POST['action'] === 'get_user_sessions') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $response['message'] = '无效的用户ID';
        } else {
            ensureSessionTables();
            $response['success'] = true;
            $response['data'] = [
                'active_devices' => getUserActiveDeviceCount($userId),
                'logs' => getUserLoginLogs($userId, 50)
            ];
        }
    } elseif ($_POST['action'] === 'admin_remove_device') {
        $sessionId = intval($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) {
            $response['message'] = '无效的会话ID';
        } else {
            $response['success'] = adminRemoveDevice($sessionId);
            $response['message'] = $response['success'] ? '设备已下线' : '操作失败';
        }
    } elseif ($_POST['action'] === 'admin_remove_all_devices') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $response['message'] = '无效的用户ID';
        } else {
            $count = adminRemoveAllDevices($userId);
            $response['success'] = true;
            $response['message'] = "已下线 {$count} 台设备";
        }
    } elseif ($_POST['action'] === 'get_user_records') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $response['message'] = '无效的用户ID';
        } else {
            // 查询当前用户的隐私表单记录
            $stmt = $db->prepare("
                SELECT p.*, b.title as post_title, b.privacy_type, a.username, a.email,
                       'current' as record_source
                FROM blog_privacy_access p
                LEFT JOIN blog_posts b ON p.post_id = b.id
                LEFT JOIN admins a ON p.user_id = a.id
                WHERE p.user_id = ?
                ORDER BY p.created_at DESC
            ");
            $stmt->execute([$userId]);
            $privacyRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 查询被撤回的记录（answer 字段中包含 userId:username:email 格式）
            $pattern = '%' . '    ' . $userId . ':%';
            $stmt = $db->prepare("
                SELECT p.*, b.title as post_title, b.privacy_type, a.username, a.email,
                       'revoked' as record_source
                FROM blog_privacy_access p
                LEFT JOIN blog_posts b ON p.post_id = b.id
                LEFT JOIN admins a ON p.user_id = a.id
                WHERE p.answer LIKE ? AND p.user_id != ?
                ORDER BY p.created_at DESC
            ");
            $stmt->execute([$pattern, $userId]);
            $revokedRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 查询支付记录
            $stmt = $db->prepare("
                SELECT p.*, b.title as post_title, b.post_price,
                       CASE WHEN p.status = 1 THEN 'paid' ELSE 'pending' END as pay_status
                FROM blog_paid_access p
                LEFT JOIN blog_posts b ON p.post_id = b.id
                WHERE p.user_id = ?
                ORDER BY p.created_at DESC
            ");
            $stmt->execute([$userId]);
            $paidRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response['success'] = true;
            $response['data'] = [
                'privacy_records' => $privacyRecords,
                'revoked_records' => $revokedRecords,
                'paid_records' => $paidRecords
            ];
        }
    } elseif ($_POST['action'] === 'get_banned_users') {
        $page = max(1, intval($_POST['page'] ?? 1));
        $perPage = max(1, min(50, intval($_POST['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;

        $total = (int)$db->query("SELECT COUNT(*) FROM admins WHERE is_banned = 1")->fetchColumn();
        $stmt = $db->prepare("SELECT id, username, email, role, last_login, created_at FROM admins WHERE is_banned = 1 ORDER BY id DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as &$u) {
            $ipStmt = $db->prepare("SELECT DISTINCT ip_address, login_at FROM user_sessions WHERE user_id = ? AND ip_address != 'unknown' ORDER BY login_at DESC LIMIT 10");
            $ipStmt->execute([$u['id']]);
            $u['ips'] = $ipStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $response['success'] = true;
        $response['data'] = [
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, ceil($total / $perPage))
        ];
    } elseif ($_POST['action'] === 'get_banned_ips') {
        $page = max(1, intval($_POST['page'] ?? 1));
        $perPage = max(1, min(50, intval($_POST['per_page'] ?? 10)));
        $search = trim($_POST['search'] ?? '');
        $offset = ($page - 1) * $perPage;

        if ($search) {
            $totalStmt = $db->prepare("SELECT COUNT(*) FROM bot_blacklist WHERE ip_address LIKE ?");
            $totalStmt->execute(['%' . $search . '%']);
            $total = (int)$totalStmt->fetchColumn();
            $stmt = $db->prepare("SELECT * FROM bot_blacklist WHERE ip_address LIKE ? ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
            $stmt->execute(['%' . $search . '%']);
        } else {
            $total = (int)$db->query("SELECT COUNT(*) FROM bot_blacklist")->fetchColumn();
            $stmt = $db->prepare("SELECT * FROM bot_blacklist ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
            $stmt->execute();
        }
        $ips = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($ips as &$ip) {
            $userStmt = $db->prepare("
                SELECT DISTINCT us.user_id, a.username, a.email, a.is_banned, MAX(us.login_at) as last_login_at
                FROM user_sessions us
                JOIN admins a ON us.user_id = a.id
                WHERE us.ip_address = ? AND us.ip_address != 'unknown'
                GROUP BY us.user_id
                ORDER BY last_login_at DESC
            ");
            $userStmt->execute([$ip['ip_address']]);
            $ip['users'] = $userStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $response['success'] = true;
        $response['data'] = [
            'ips' => $ips,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, ceil($total / $perPage))
        ];
    } elseif ($_POST['action'] === 'unban_ip') {
        $ipId = intval($_POST['ip_id'] ?? 0);
        if ($ipId <= 0) {
            $response['message'] = '无效的ID';
        } else {
            $stmt = $db->prepare("DELETE FROM bot_blacklist WHERE id = ?");
            $stmt->execute([$ipId]);
            $response['success'] = true;
            $response['message'] = 'IP已解封';
        }
    } elseif ($_POST['action'] === 'get_whitelist') {
        $page = max(1, intval($_POST['page'] ?? 1));
        $perPage = max(1, min(50, intval($_POST['per_page'] ?? 10)));
        $search = trim($_POST['search'] ?? '');
        $offset = ($page - 1) * $perPage;

        // 确保表存在
        $db->exec("CREATE TABLE IF NOT EXISTS bot_whitelist_ip (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL UNIQUE,
            reason VARCHAR(500),
            added_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        if ($search) {
            $totalStmt = $db->prepare("SELECT COUNT(*) FROM bot_whitelist_ip WHERE ip_address LIKE ?");
            $totalStmt->execute(['%' . $search . '%']);
            $total = (int)$totalStmt->fetchColumn();
            $stmt = $db->prepare("SELECT w.*, a.username as added_by_name FROM bot_whitelist_ip w LEFT JOIN admins a ON w.added_by = a.id WHERE w.ip_address LIKE ? ORDER BY w.created_at DESC LIMIT $perPage OFFSET $offset");
            $stmt->execute(['%' . $search . '%']);
        } else {
            $total = (int)$db->query("SELECT COUNT(*) FROM bot_whitelist_ip")->fetchColumn();
            $stmt = $db->prepare("SELECT w.*, a.username as added_by_name FROM bot_whitelist_ip w LEFT JOIN admins a ON w.added_by = a.id ORDER BY w.created_at DESC LIMIT $perPage OFFSET $offset");
            $stmt->execute();
        }
        $ips = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['data'] = [
            'ips' => $ips,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, ceil($total / $perPage))
        ];
    } elseif ($_POST['action'] === 'add_whitelist') {
        $ip = trim($_POST['ip'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (empty($ip)) {
            $response['message'] = 'IP地址不能为空';
        } elseif (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $response['message'] = 'IP地址格式不正确';
        } else {
            // 确保表存在
            $db->exec("CREATE TABLE IF NOT EXISTS bot_whitelist_ip (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL UNIQUE,
                reason VARCHAR(500),
                added_by INT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip (ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 检查是否已存在
            $check = $db->prepare("SELECT id FROM bot_whitelist_ip WHERE ip_address = ?");
            $check->execute([$ip]);
            if ($check->fetch()) {
                $response['message'] = '该IP已在白名单中';
            } else {
                $stmt = $db->prepare("INSERT INTO bot_whitelist_ip (ip_address, reason, added_by) VALUES (?, ?, ?)");
                $stmt->execute([$ip, $reason, $_SESSION['admin_id'] ?? null]);
                $response['success'] = true;
                $response['message'] = 'IP已添加到白名单';
            }
        }
    } elseif ($_POST['action'] === 'remove_whitelist') {
        $ipId = intval($_POST['ip_id'] ?? 0);
        if ($ipId <= 0) {
            $response['message'] = '无效的ID';
        } else {
            $stmt = $db->prepare("DELETE FROM bot_whitelist_ip WHERE id = ?");
            $stmt->execute([$ipId]);
            $response['success'] = true;
            $response['message'] = 'IP已从白名单移除';
        }
    } elseif ($_POST['action'] === 'get_bot_whitelist_ua') {
        $page = max(1, intval($_POST['page'] ?? 1));
        $perPage = max(1, min(50, intval($_POST['per_page'] ?? 10)));
        $search = trim($_POST['search'] ?? '');
        $offset = ($page - 1) * $perPage;

        $db->exec("CREATE TABLE IF NOT EXISTS bot_whitelist_ua (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ua_pattern VARCHAR(500) NOT NULL,
            reason VARCHAR(500),
            added_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ua (ua_pattern(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        if ($search) {
            $totalStmt = $db->prepare("SELECT COUNT(*) FROM bot_whitelist_ua WHERE ua_pattern LIKE ? OR reason LIKE ?");
            $totalStmt->execute(['%' . $search . '%', '%' . $search . '%']);
            $total = (int)$totalStmt->fetchColumn();
            $stmt = $db->prepare("SELECT u.*, a.username as added_by_name FROM bot_whitelist_ua u LEFT JOIN admins a ON u.added_by = a.id WHERE u.ua_pattern LIKE ? OR u.reason LIKE ? ORDER BY u.created_at DESC LIMIT $perPage OFFSET $offset");
            $stmt->execute(['%' . $search . '%', '%' . $search . '%']);
        } else {
            $total = (int)$db->query("SELECT COUNT(*) FROM bot_whitelist_ua")->fetchColumn();
            $stmt = $db->prepare("SELECT u.*, a.username as added_by_name FROM bot_whitelist_ua u LEFT JOIN admins a ON u.added_by = a.id ORDER BY u.created_at DESC LIMIT $perPage OFFSET $offset");
            $stmt->execute();
        }
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['data'] = [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, ceil($total / $perPage))
        ];
    } elseif ($_POST['action'] === 'add_bot_whitelist_ua') {
        $uaPattern = trim($_POST['ua_pattern'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (empty($uaPattern)) {
            $response['message'] = 'UA标识不能为空';
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS bot_whitelist_ua (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ua_pattern VARCHAR(500) NOT NULL,
                reason VARCHAR(500),
                added_by INT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ua (ua_pattern(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $check = $db->prepare("SELECT id FROM bot_whitelist_ua WHERE ua_pattern = ?");
            $check->execute([$uaPattern]);
            if ($check->fetch()) {
                $response['message'] = '该UA标识已存在';
            } else {
                $stmt = $db->prepare("INSERT INTO bot_whitelist_ua (ua_pattern, reason, added_by) VALUES (?, ?, ?)");
                $stmt->execute([$uaPattern, $reason, $_SESSION['admin_id'] ?? null]);
                $response['success'] = true;
                $response['message'] = 'UA白名单已添加';
            }
        }
    } elseif ($_POST['action'] === 'remove_bot_whitelist_ua') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $response['message'] = '无效的ID';
        } else {
            $stmt = $db->prepare("DELETE FROM bot_whitelist_ua WHERE id = ?");
            $stmt->execute([$id]);
            $response['success'] = true;
            $response['message'] = 'UA白名单已移除';
        }
    } elseif ($_POST['action'] === 'quick_unban_user') {
        $id = intval($_POST['id'] ?? 0);
        if ($id === $_SESSION['admin_id']) {
            $response['message'] = '不能解封当前登录的管理员';
        } else {
            $stmt = $db->prepare("UPDATE admins SET is_banned = 0 WHERE id = ?");
            if ($stmt->execute([$id])) {
                $response['success'] = true;
                $response['message'] = '用户已解封';
            } else {
                $response['message'] = '操作失败';
            }
        }
    } elseif ($_POST['action'] === 'get_appeals') {
        $page = max(1, intval($_POST['page'] ?? 1));
        $perPage = max(1, min(50, intval($_POST['per_page'] ?? 15)));
        $offset = ($page - 1) * $perPage;
        $filterStatus = $_POST['filter_status'] ?? '';
        $filterType = $_POST['filter_type'] ?? '';
        $search = trim($_POST['search'] ?? '');

        $where = [];
        $params = [];
        if ($filterStatus && in_array($filterStatus, ['pending','processing','approved','rejected'])) {
            $where[] = "a.status = ?";
            $params[] = $filterStatus;
        }
        if ($filterType && in_array($filterType, ['ip','user','ip_user'])) {
            $where[] = "a.appeal_type = ?";
            $params[] = $filterType;
        }
        if ($search) {
            $where[] = "(a.ip_address LIKE ? OR a.contact_name LIKE ? OR a.contact_email LIKE ? OR a.reason LIKE ?)";
            $like = "%{$search}%";
            $params = array_merge($params, [$like, $like, $like, $like]);
        }
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $db->prepare("SELECT COUNT(*) FROM appeals a {$whereSQL}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("SELECT a.*, u.username as user_username, r.username as reviewer_name 
            FROM appeals a 
            LEFT JOIN admins u ON a.user_id = u.id 
            LEFT JOIN admins r ON a.reviewed_by = r.id 
            {$whereSQL} ORDER BY 
            CASE a.status WHEN 'pending' THEN 0 WHEN 'processing' THEN 1 ELSE 2 END,
            a.created_at DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = [
            'pending' => (int)$db->query("SELECT COUNT(*) FROM appeals WHERE status = 'pending'")->fetchColumn(),
            'processing' => (int)$db->query("SELECT COUNT(*) FROM appeals WHERE status = 'processing'")->fetchColumn(),
            'approved' => (int)$db->query("SELECT COUNT(*) FROM appeals WHERE status = 'approved'")->fetchColumn(),
            'rejected' => (int)$db->query("SELECT COUNT(*) FROM appeals WHERE status = 'rejected'")->fetchColumn(),
        ];

        $response['success'] = true;
        $response['data'] = [
            'appeals' => $appeals,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, ceil($total / $perPage)),
            'stats' => $stats
        ];
    } elseif ($_POST['action'] === 'get_appeal_detail') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { $response['message'] = '参数错误'; } else {
            $stmt = $db->prepare("SELECT a.*, u.username as user_username, r.username as reviewer_name 
                FROM appeals a LEFT JOIN admins u ON a.user_id = u.id LEFT JOIN admins r ON a.reviewed_by = r.id WHERE a.id = ?");
            $stmt->execute([$id]);
            $appeal = $stmt->fetch();
            if (!$appeal) { $response['message'] = '记录不存在'; } else {
                $typeMap = ['ip' => 'IP申诉', 'user' => '账号申诉', 'ip_user' => 'IP+账号申诉'];
                $statusMap = ['pending'=>['text'=>'待审核','class'=>'warning'],'processing'=>['text'=>'处理中','class'=>'info'],'approved'=>['text'=>'已通过','class'=>'success'],'rejected'=>['text'=>'已拒绝','class'=>'danger']];
                $html = '<div class="row"><div class="col-md-6"><table class="table table-sm mb-0 small">';
                $html .= '<tr><td class="text-muted" style="width:80px">编号</td><td class="fw-bold">#'.$appeal['id'].'</td></tr>';
                $html .= '<tr><td class="text-muted">类型</td><td>'.$typeMap[$appeal['appeal_type']].'</td></tr>';
                $html .= '<tr><td class="text-muted">状态</td><td><span class="badge bg-'.$statusMap[$appeal['status']]['class'].'">'.$statusMap[$appeal['status']]['text'].'</span></td></tr>';
                $html .= '<tr><td class="text-muted">IP</td><td>'.htmlspecialchars($appeal['ip_address']).'</td></tr>';
                if ($appeal['user_id']) $html .= '<tr><td class="text-muted">用户</td><td>'.htmlspecialchars($appeal['user_username'] ?? 'ID:'.$appeal['user_id']).'</td></tr>';
                $html .= '<tr><td class="text-muted">联系人</td><td>'.htmlspecialchars($appeal['contact_name']).'</td></tr>';
                $html .= '<tr><td class="text-muted">邮箱</td><td>'.htmlspecialchars($appeal['contact_email']).'</td></tr>';
                $html .= '<tr><td class="text-muted">提交时间</td><td>'.$appeal['created_at'].'</td></tr>';
                if ($appeal['reviewed_at']) { $html .= '<tr><td class="text-muted">审核时间</td><td>'.$appeal['reviewed_at'].'</td></tr>'; $html .= '<tr><td class="text-muted">审核人</td><td>'.htmlspecialchars($appeal['reviewer_name'] ?? '-').'</td></tr>'; }
                $html .= '</table></div><div class="col-md-6">';
                $html .= '<strong>申诉理由：</strong><p class="small mt-1 mb-2" style="white-space:pre-wrap">'.nl2br(htmlspecialchars($appeal['reason'])).'</p>';
                if ($appeal['admin_reply']) $html .= '<div class="alert alert-info mt-2 mb-0 small"><strong>管理员回复：</strong><br>'.nl2br(htmlspecialchars($appeal['admin_reply'])).'</div>';
                $html .= '</div></div>';
                $response['success'] = true;
                $response['data'] = ['html' => $html];
            }
        }
    } elseif ($_POST['action'] === 'review_appeal') {
        $id = intval($_POST['id'] ?? 0);
        $reviewAction = $_POST['review_action'] ?? '';
        $reply = trim($_POST['reply'] ?? '');
        $unban = isset($_POST['unban']) && $_POST['unban'] === '1';
        if (!$id || !in_array($reviewAction, ['approve','reject'])) { $response['message'] = '参数错误'; } else {
            $stmt = $db->prepare("SELECT a.*, u.username as user_username FROM appeals a LEFT JOIN admins u ON a.user_id = u.id WHERE a.id = ?");
            $stmt->execute([$id]);
            $appeal = $stmt->fetch();
            if (!$appeal) { $response['message'] = '申诉不存在'; } else {
                try {
                    $db->beginTransaction();
                    $newStatus = $reviewAction === 'approve' ? 'approved' : 'rejected';
                    $stmt = $db->prepare("UPDATE appeals SET status = ?, admin_reply = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                    $stmt->execute([$newStatus, $reply, $_SESSION['admin_id'], $id]);

                    if ($reviewAction === 'approve' && $unban) {
                        if (in_array($appeal['appeal_type'], ['ip','ip_user'])) {
                            $db->prepare("DELETE FROM bot_blacklist WHERE ip_address = ?")->execute([$appeal['ip_address']]);
                        }
                        if (in_array($appeal['appeal_type'], ['user','ip_user']) && $appeal['user_id']) {
                            $db->prepare("UPDATE admins SET is_banned = 0 WHERE id = ? AND is_banned = 1")->execute([$appeal['user_id']]);
                            $db->prepare("DELETE FROM bot_blacklist WHERE reason LIKE '%用户封禁%' AND ip_address IN (SELECT DISTINCT ip_address FROM user_sessions WHERE user_id = ?)")->execute([$appeal['user_id']]);
                        }
                    }

                    if (!empty($appeal['contact_email'])) {
                        $emailBody = "您好 {$appeal['contact_name']}：\n\n您的申诉（编号：#{$id}）已".($reviewAction === 'approve' ? '通过审核' : '未通过审核')."。\n\n";
                        if ($reply) $emailBody .= "管理员回复：\n{$reply}\n\n";
                        $emailBody .= $reviewAction === 'approve' ? "感谢您的耐心等待。" : "如有疑问，请通过网站其他渠道联系管理员。";
                        try {
                            loadPHPMailerLibrary();
                            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                            $mail->isSMTP();
                            $mail->Host = SMTP_HOST;
                            $mail->SMTPAuth = true;
                            $mail->Username = SMTP_USERNAME;
                            $mail->Password = SMTP_PASSWORD;
                            $mail->SMTPSecure = SMTP_ENCRYPTION;
                            $mail->Port = SMTP_PORT;
                            $mail->CharSet = 'UTF-8';
                            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
                            $mail->setFrom(SMTP_USERNAME, ($config['website_name'] ?? 'LyGalaxy') . ' - 客服团队');
                            $mail->addAddress($appeal['contact_email'], $appeal['contact_name']);
                            $mail->Subject = '=?UTF-8?B?'.base64_encode('申诉审核结果 - '.($config['website_name'] ?? 'LyGalaxy')).'?=';
                            $mail->Body = $emailBody;
                            $mail->send();
                        } catch (Exception $e) { error_log('Appeal email error: '.$e->getMessage()); }
                    }

                    $db->commit();
                    $response['success'] = true;
                    $response['message'] = $reviewAction === 'approve' ? '已通过并处理' : '已拒绝';
                } catch (Exception $e) { $db->rollBack(); $response['message'] = '操作失败：'.$e->getMessage(); }
            }
        }
    } elseif ($_POST['action'] === 'set_appeal_status') {
        $id = intval($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (!$id || !in_array($status, ['processing'])) { $response['message'] = '参数错误'; } else {
            $stmt = $db->prepare("UPDATE appeals SET status = ?, reviewed_by = ? WHERE id = ?");
            $stmt->execute([$status, $_SESSION['admin_id'], $id]);
            $response['success'] = true;
            $response['message'] = '已标记为处理中';
        }
    } elseif ($_POST['action'] === 'delete_appeal') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { $response['message'] = '参数错误'; } else {
            $db->prepare("DELETE FROM appeals WHERE id = ?")->execute([$id]);
            $response['success'] = true;
            $response['message'] = '已删除';
        }
    } elseif ($_POST['action'] === 'ip_query') {
        $page = max(1, intval($_POST['page'] ?? 1));
        $perPage = max(1, min(50, intval($_POST['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $search = trim($_POST['search'] ?? '');
        $source = $_POST['source'] ?? '';

        $results = [];
        $totalCount = 0;

        // 1. 登录会话记录 (user_sessions)
        if (empty($source) || $source === 'session') {
            ensureSessionTables();
            $where = ["ip_address != 'unknown'", "ip_address != ''"];
            $params = [];
            if ($search) {
                $where[] = "(ip_address LIKE ? OR user_id IN (SELECT id FROM admins WHERE username LIKE ? OR email LIKE ?))";
                $like = "%{$search}%";
                $params = array_merge($params, [$like, $like, $like]);
            }
            $whereSQL = 'WHERE ' . implode(' AND ', $where);

            $countSql = "SELECT COUNT(DISTINCT ip_address) FROM user_sessions {$whereSQL}";
            $countStmt = $db->prepare($countSql);
            $countStmt->execute($params);
            $sessionCount = (int)$countStmt->fetchColumn();
            $totalCount += $sessionCount;

            if ($page <= ceil(max(1, $sessionCount) / $perPage) || empty($source)) {
                $sql = "SELECT ip_address, MAX(login_at) as last_login, COUNT(DISTINCT user_id) as user_count,
                        GROUP_CONCAT(DISTINCT user_id) as user_ids
                        FROM user_sessions {$whereSQL}
                        GROUP BY ip_address ORDER BY last_login DESC LIMIT $perPage OFFSET $offset";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($sessions as $s) {
                    $users = [];
                    if ($s['user_ids']) {
                        $userStmt = $db->prepare("SELECT id, username, email, is_banned FROM admins WHERE id IN (" . $s['user_ids'] . ")");
                        $userStmt->execute();
                        $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                    $results[] = [
                        'source' => 'session',
                        'ip' => $s['ip_address'],
                        'last_time' => $s['last_login'],
                        'user_count' => $s['user_count'],
                        'users' => $users,
                        'extra' => null
                    ];
                }
            }
        }

        // 2. 注册IP记录 (admins.register_ip)
        if ((empty($source) || $source === 'register') && empty($source)) {
            $checkRegCol = $db->query("SHOW COLUMNS FROM admins LIKE 'register_ip'")->fetch();
            if ($checkRegCol) {
                $where = ["register_ip != ''", "register_ip IS NOT NULL"];
                $params = [];
                if ($search) {
                    $where[] = "(register_ip LIKE ? OR username LIKE ? OR email LIKE ?)";
                    $like = "%{$search}%";
                    $params = array_merge($params, [$like, $like, $like]);
                }
                $whereSQL = 'WHERE ' . implode(' AND ', $where);

                $sql = "SELECT register_ip as ip, created_at as reg_time, id, username, email, is_banned
                        FROM admins {$whereSQL} ORDER BY created_at DESC LIMIT $perPage OFFSET $offset";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $registers = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($registers as $r) {
                    $results[] = [
                        'source' => 'register',
                        'ip' => $r['ip'],
                        'last_time' => $r['reg_time'],
                        'user_count' => 1,
                        'users' => [['id' => $r['id'], 'username' => $r['username'], 'email' => $r['email'], 'is_banned' => $r['is_banned']]],
                        'extra' => '注册IP'
                    ];
                }
                $totalCount += count($registers);
            }
        }

        // 3. 封禁IP记录 (bot_blacklist)
        if (empty($source) || $source === 'blacklist') {
            $where = ["1=1"];
            $params = [];
            if ($search) {
                $where[] = "(ip_address LIKE ? OR reason LIKE ?)";
                $like = "%{$search}%";
                $params = array_merge($params, [$like, $like]);
            }
            $whereSQL = 'WHERE ' . implode(' AND ', $where);

            $countSql = "SELECT COUNT(*) FROM bot_blacklist {$whereSQL}";
            $countStmt = $db->prepare($countSql);
            $countStmt->execute($params);
            $blacklistCount = (int)$countStmt->fetchColumn();
            $totalCount += $blacklistCount;

            if (empty($source)) {
                $sql = "SELECT * FROM bot_blacklist {$whereSQL} ORDER BY created_at DESC LIMIT $perPage OFFSET $offset";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $blacklist = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($blacklist as $b) {
                    // 查找使用该IP的用户
                    $userStmt = $db->prepare("
                        SELECT DISTINCT a.id, a.username, a.email, a.is_banned, MAX(us.login_at) as last_login
                        FROM user_sessions us
                        JOIN admins a ON us.user_id = a.id
                        WHERE us.ip_address = ? AND us.ip_address != 'unknown'
                        GROUP BY a.id
                    ");
                    $userStmt->execute([$b['ip_address']]);
                    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

                    $results[] = [
                        'source' => 'blacklist',
                        'ip' => $b['ip_address'],
                        'last_time' => $b['created_at'],
                        'user_count' => count($users),
                        'users' => $users,
                        'extra' => '封禁原因: ' . $b['reason']
                    ];
                }
            }
        }

        // 4. 申诉记录 (appeals)
        if (empty($source) || $source === 'appeal') {
            $where = ["1=1"];
            $params = [];
            if ($search) {
                $where[] = "(a.ip_address LIKE ? OR a.contact_name LIKE ? OR a.contact_email LIKE ? OR u.username LIKE ?)";
                $like = "%{$search}%";
                $params = array_merge($params, [$like, $like, $like, $like]);
            }
            $whereSQL = 'WHERE ' . implode(' AND ', $where);

            $countSql = "SELECT COUNT(*) FROM appeals a LEFT JOIN admins u ON a.user_id = u.id {$whereSQL}";
            $countStmt = $db->prepare($countSql);
            $countStmt->execute($params);
            $appealCount = (int)$countStmt->fetchColumn();
            $totalCount += $appealCount;

            if (empty($source)) {
                $sql = "SELECT a.*, u.username as user_username, u.email as user_email, u.is_banned as user_banned
                        FROM appeals a LEFT JOIN admins u ON a.user_id = u.id
                        {$whereSQL} ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($appeals as $a) {
                    $users = [];
                    if ($a['user_id'] && $a['user_username']) {
                        $users[] = [
                            'id' => $a['user_id'],
                            'username' => $a['user_username'],
                            'email' => $a['user_email'],
                            'is_banned' => $a['user_banned']
                        ];
                    }
                    $typeMap = ['ip' => 'IP申诉', 'user' => '账号申诉', 'ip_user' => 'IP+账号申诉'];
                    $results[] = [
                        'source' => 'appeal',
                        'ip' => $a['ip_address'],
                        'last_time' => $a['created_at'],
                        'user_count' => count($users),
                        'users' => $users,
                        'extra' => $typeMap[$a['appeal_type']] . ' - ' . $a['contact_name']
                    ];
                }
            }
        }

        // 5. 访问记录 (visit_stats)
        if (empty($source) || $source === 'visit') {
            $where = ["ip_address != ''", "ip_address IS NOT NULL"];
            $params = [];
            if ($search) {
                $where[] = "(ip_address LIKE ? OR page_url LIKE ? OR visitor_username LIKE ? OR visitor_email LIKE ?)";
                $like = "%{$search}%";
                $params = array_merge($params, [$like, $like, $like, $like]);
            }
            $whereSQL = 'WHERE ' . implode(' AND ', $where);

            $countSql = "SELECT COUNT(DISTINCT ip_address) FROM visit_stats {$whereSQL}";
            $countStmt = $db->prepare($countSql);
            $countStmt->execute($params);
            $visitCount = (int)$countStmt->fetchColumn();
            $totalCount += $visitCount;

            if (empty($source)) {
                $sql = "SELECT ip_address, MAX(visit_time) as last_visit, COUNT(*) as visit_count,
                        GROUP_CONCAT(DISTINCT visitor_username) as visitor_usernames,
                        GROUP_CONCAT(DISTINCT visitor_email) as visitor_emails
                        FROM visit_stats {$whereSQL}
                        GROUP BY ip_address ORDER BY last_visit DESC LIMIT $perPage OFFSET $offset";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($visits as $v) {
                    $users = [];
                    $usernames = array_filter(array_unique(explode(',', $v['visitor_usernames'] ?? '')));
                    $emails = array_filter(array_unique(explode(',', $v['visitor_emails'] ?? '')));
                    foreach ($usernames as $i => $username) {
                        if (!empty($username)) {
                            $userStmt = $db->prepare("SELECT id, username, email, is_banned FROM admins WHERE username = ?");
                            $userStmt->execute([$username]);
                            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                            if ($user) {
                                $users[] = $user;
                            }
                        }
                    }
                    $results[] = [
                        'source' => 'visit',
                        'ip' => $v['ip_address'],
                        'last_time' => $v['last_visit'],
                        'user_count' => count($users),
                        'users' => $users,
                        'extra' => '访问次数: ' . $v['visit_count']
                    ];
                }
            }
        }

        // 如果选择了特定来源，重新计算分页
        if ($source) {
            $totalCount = 0;
            if ($source === 'session') {
                $totalCount = (int)$db->query("SELECT COUNT(DISTINCT ip_address) FROM user_sessions WHERE ip_address != 'unknown' AND ip_address != ''")->fetchColumn();
            } elseif ($source === 'register') {
                $checkRegCol = $db->query("SHOW COLUMNS FROM admins LIKE 'register_ip'")->fetch();
                $totalCount = $checkRegCol ? (int)$db->query("SELECT COUNT(*) FROM admins WHERE register_ip != '' AND register_ip IS NOT NULL")->fetchColumn() : 0;
            } elseif ($source === 'blacklist') {
                $totalCount = (int)$db->query("SELECT COUNT(*) FROM bot_blacklist")->fetchColumn();
            } elseif ($source === 'appeal') {
                $totalCount = (int)$db->query("SELECT COUNT(*) FROM appeals")->fetchColumn();
            } elseif ($source === 'visit') {
                $totalCount = (int)$db->query("SELECT COUNT(DISTINCT ip_address) FROM visit_stats WHERE ip_address != '' AND ip_address IS NOT NULL")->fetchColumn();
            }
        }

        $response['success'] = true;
        $response['data'] = [
            'results' => $results,
            'total' => $totalCount,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, ceil($totalCount / $perPage))
        ];
    } elseif ($_POST['action'] === 'ip_detail') {
        $ip = trim($_POST['ip'] ?? '');
        if (empty($ip)) { $response['message'] = 'IP不能为空'; } else {
            $data = [];

            // 登录会话
            $stmt = $db->prepare("
                SELECT DISTINCT a.id, a.username, a.email, a.is_banned, MAX(us.login_at) as login_at
                FROM user_sessions us
                JOIN admins a ON us.user_id = a.id
                WHERE us.ip_address = ? AND us.ip_address != 'unknown'
                GROUP BY a.id ORDER BY login_at DESC
            ");
            $stmt->execute([$ip]);
            $data['sessions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 注册记录
            $checkRegCol = $db->query("SHOW COLUMNS FROM admins LIKE 'register_ip'")->fetch();
            if ($checkRegCol) {
                $stmt = $db->prepare("SELECT id, username, email, created_at FROM admins WHERE register_ip = ?");
                $stmt->execute([$ip]);
                $data['register'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $data['register'] = [];
            }

            // 封禁记录
            $stmt = $db->prepare("SELECT * FROM bot_blacklist WHERE ip_address = ?");
            $stmt->execute([$ip]);
            $data['blacklist'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 申诉记录
            $stmt = $db->prepare("SELECT * FROM appeals WHERE ip_address = ? ORDER BY created_at DESC");
            $stmt->execute([$ip]);
            $data['appeals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 访问记录
            $stmt = $db->prepare("SELECT * FROM visit_stats WHERE ip_address = ? ORDER BY visit_time DESC LIMIT 100");
            $stmt->execute([$ip]);
            $data['visits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response['success'] = true;
            $response['data'] = $data;
        }
    } elseif ($_POST['action'] === 'add_blacklist') {
        $ip = trim($_POST['ip'] ?? '');
        $reason = trim($_POST['reason'] ?? '管理员手动封禁');

        if (empty($ip)) { $response['message'] = 'IP不能为空'; } else {
            // 检查是否已存在
            $check = $db->prepare("SELECT id FROM bot_blacklist WHERE ip_address = ?");
            $check->execute([$ip]);
            if ($check->fetch()) {
                $response['message'] = '该IP已在封禁列表中';
            } else {
                addBotBlacklist($ip, $reason);
                $response['success'] = true;
                $response['message'] = 'IP已添加到封禁列表';
            }
        }
    }
    
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    } else {
        if ($response['success']) {
            $message = $response['message'];
        } else {
            $error = $response['message'];
        }
    }
}

// 检查并添加 is_banned 字段
$checkStmt = $db->query("SHOW COLUMNS FROM admins LIKE 'is_banned'");
if (!$checkStmt->fetch()) {
    $db->exec("ALTER TABLE admins ADD COLUMN is_banned TINYINT(1) DEFAULT 0 COMMENT '是否封禁: 0-正常, 1-封禁'");
}

// 获取所有管理员
$stmt = $db->query("SELECT id, username, email, role, last_login, created_at, is_banned FROM admins ORDER BY id ASC");
$admins = $stmt->fetchAll();

// 计算统计数据
$totalAdmins = count($admins);
$adminRoleCount = 0;
foreach ($admins as $admin) {
    if ($admin['role'] === 'admin') {
        $adminRoleCount++;
    }
}
$page_title = '用户管理';
$extra_css = <<<'CSS'
.avatar-circle {
    width: 40px;
    height: 40px;
    background-color: #e9ecef;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    color: #495057;
    font-size: 16px;
}
.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,.02);
}
.card {
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    transition: all 0.3s ease;
}
.stats-card {
    border-radius: 1rem;
    overflow: hidden;
}
.stats-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}
.role-badge {
    font-size: 0.8rem;
    padding: 0.35em 0.65em;
}
CSS;
require_once 'includes/header.php'; ?>

                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-1">用户管理</h1>
                        <p class="text-muted mb-0">管理系统后台管理员及权限</p>
                    </div>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                        <i class="bi bi-person-plus-fill me-1"></i> 添加管理员
                    </button>
                </div>

                <div id="alertContainer"></div>
                
                <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="bi bi-exclamation-circle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="card stats-card bg-primary text-white h-100">
                            <div class="card-body d-flex align-items-center">
                                <div class="stats-icon bg-white bg-opacity-25 me-3">
                                    <i class="bi bi-people-fill"></i>
                                </div>
                                <div>
                                    <h2 class="mb-0"><?= $totalAdmins ?></h2>
                                    <div class="small opacity-75">总用户数</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card stats-card bg-info text-white h-100">
                            <div class="card-body d-flex align-items-center">
                                <div class="stats-icon bg-white bg-opacity-25 me-3">
                                    <i class="bi bi-shield-lock-fill"></i>
                                </div>
                                <div>
                                    <h2 class="mb-0"><?= $adminRoleCount ?></h2>
                                    <div class="small opacity-75">管理员角色</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-white py-3">
                        <ul class="nav nav-tabs card-header-tabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="usersTab" data-bs-toggle="tab" data-bs-target="#usersPanel" type="button" role="tab">
                                    <i class="bi bi-people me-1"></i>全部用户
                                    <span class="badge bg-secondary ms-1" style="font-size:0.65rem"><?= $totalAdmins ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="bannedUsersTab" data-bs-toggle="tab" data-bs-target="#bannedUsersPanel" type="button" role="tab" onclick="loadBannedUsers(1)">
                                    <i class="bi bi-person-lock me-1"></i>封禁用户
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="bannedIpsTab" data-bs-toggle="tab" data-bs-target="#bannedIpsPanel" type="button" role="tab" onclick="loadBannedIps(1)">
                                    <i class="bi bi-shield-exclamation me-1"></i>封禁IP
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="whitelistTab" data-bs-toggle="tab" data-bs-target="#whitelistPanel" type="button" role="tab" onclick="loadWhitelist(1)">
                                    <i class="bi bi-shield-check me-1"></i>IP白名单
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="uaWhitelistTab" data-bs-toggle="tab" data-bs-target="#uaWhitelistPanel" type="button" role="tab" onclick="loadUaWhitelist(1)">
                                    <i class="bi bi-browser-chrome me-1"></i>UA白名单
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="appealsTab" data-bs-toggle="tab" data-bs-target="#appealsPanel" type="button" role="tab" onclick="loadAppeals(1)">
                                    <i class="bi bi-envelope-paper me-1"></i>申诉管理
                                    <span class="badge bg-warning ms-1" style="font-size:0.65rem" id="appealPendingBadge"></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="ipQueryTab" data-bs-toggle="tab" data-bs-target="#ipQueryPanel" type="button" role="tab" onclick="loadIpQuery(1)">
                                    <i class="bi bi-geo-alt me-1"></i>IP地址查询
                                </button>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body p-0 tab-content">
                        <div class="tab-pane fade show active" id="usersPanel" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-4">用户</th>
                                            <th>角色</th>
                                            <th>最后登录</th>
                                            <th>创建时间</th>
                                            <th class="text-end pe-4">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($admins as $admin): ?>
                                        <tr id="admin-row-<?= $admin['id'] ?>">
                                            <td class="ps-4">
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-circle me-3">
                                                        <?= strtoupper(substr($admin['username'], 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold text-dark">
                                                            <?= htmlspecialchars($admin['username']) ?>
                                                            <?php if ($admin['id'] === $_SESSION['admin_id']): ?>
                                                            <span class="badge bg-soft-primary text-primary border border-primary ms-1" style="font-size: 0.7rem;">YOU</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="text-muted small">
                                                            <i class="bi bi-envelope me-1"></i>
                                                            <?= htmlspecialchars($admin['email'] ?? '未设置邮箱') ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($admin['is_banned'])): ?>
                                                    <span class="badge bg-danger role-badge">
                                                        <i class="bi bi-slash-circle me-1"></i>已封禁
                                                    </span>
                                                <?php elseif ($admin['role'] === 'admin'): ?>
                                                    <span class="badge bg-primary role-badge">
                                                        <i class="bi bi-shield-fill me-1"></i>管理员
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary role-badge">
                                                        <i class="bi bi-person me-1"></i>普通用户
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($admin['last_login']): ?>
                                                    <div class="small"><?= date('Y-m-d', strtotime($admin['last_login'])) ?></div>
                                                    <div class="small text-muted"><?= date('H:i', strtotime($admin['last_login'])) ?></div>
                                                <?php else: ?>
                                                    <span class="text-muted small">从未登录</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small"><?= date('Y-m-d H:i', strtotime($admin['created_at'])) ?></div>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div class="btn-group">
                                                    <button class="btn btn-sm btn-light text-primary"
                                                            onclick="showEditModal(<?= $admin['id'] ?>, '<?= htmlspecialchars($admin['username']) ?>', '<?= htmlspecialchars($admin['email'] ?? '') ?>', '<?= htmlspecialchars($admin['role']) ?>')"
                                                            title="编辑用户">
                                                        <i class="bi bi-pencil-fill"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-light text-warning"
                                                            onclick="showUserRecords(<?= $admin['id'] ?>, '<?= htmlspecialchars($admin['username']) ?>')"
                                                            title="填写记录">
                                                        <i class="bi bi-file-earmark-text"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-light text-info"
                                                            onclick="showSessionLogs(<?= $admin['id'] ?>, '<?= htmlspecialchars($admin['username']) ?>')"
                                                            title="登录记录">
                                                        <i class="bi bi-clock-history"></i>
                                                </button>
                                                <?php if ($admin['id'] !== $_SESSION['admin_id']): ?>
                                                <?php if (!empty($admin['is_banned'])): ?>
                                                <button class="btn btn-sm btn-light text-success" 
                                                        onclick="toggleBan(<?= $admin['id'] ?>, '<?= htmlspecialchars($admin['username']) ?>', 0)"
                                                        title="解封用户">
                                                    <i class="bi bi-unlock-fill"></i>
                                                </button>
                                                <?php else: ?>
                                                <button class="btn btn-sm btn-light text-secondary" 
                                                        onclick="toggleBan(<?= $admin['id'] ?>, '<?= htmlspecialchars($admin['username']) ?>', 1)"
                                                        title="封禁用户">
                                                    <i class="bi bi-lock-fill"></i>
                                                </button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-light text-danger" 
                                                        onclick="deleteAdmin(<?= $admin['id'] ?>, '<?= htmlspecialchars($admin['username']) ?>')"
                                                        title="删除用户">
                                                    <i class="bi bi-trash-fill"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        </div>
                        <div class="tab-pane fade" id="bannedUsersPanel" role="tabpanel"></div>
                        <div class="tab-pane fade" id="bannedIpsPanel" role="tabpanel"></div>
                        <div class="tab-pane fade" id="whitelistPanel" role="tabpanel"></div>
                        <div class="tab-pane fade" id="uaWhitelistPanel" role="tabpanel"></div>
                        <div class="tab-pane fade" id="appealsPanel" role="tabpanel"></div>
                        <div class="tab-pane fade" id="ipQueryPanel" role="tabpanel">
                            <div class="p-3">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                                            <input type="text" class="form-control" id="ipSearchInput" placeholder="搜索IP地址、用户名、邮箱...">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select" id="ipSourceFilter">
                                            <option value="">全部来源</option>
                                            <option value="session">登录会话</option>
                                            <option value="register">注册IP</option>
                                            <option value="blacklist">封禁记录</option>
                                            <option value="appeal">申诉记录</option>
                                            <option value="visit">访问记录</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-primary w-100" onclick="loadIpQuery(1)">
                                            <i class="bi bi-search me-1"></i>查询
                                        </button>
                                    </div>
                                </div>
                                <div id="ipQueryResults"></div>
                            </div>
                        </div>
                    </div>
                </div>

    <div class="modal fade" id="addAdminModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="addAdminForm">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">添加新管理员</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">用户名 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" name="username" class="form-control" placeholder="请输入用户名" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">密码 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password" name="password" class="form-control" placeholder="请输入密码" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">邮箱</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" class="form-control" placeholder="example@domain.com">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">角色 <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required>
                                <option value="user">普通用户 (仅部分权限)</option>
                                <option value="admin">超级管理员 (所有权限)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> 确认添加
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="editAdminForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-header">
                        <h5 class="modal-title">编辑用户</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">用户名 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" name="username" id="edit_username" class="form-control" placeholder="请输入用户名" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">邮箱</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" id="edit_email" class="form-control" placeholder="example@domain.com">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">用户类型 <span class="text-danger">*</span></label>
                            <select name="role" id="edit_role" class="form-select" required>
                                <option value="user">普通用户 (仅部分权限)</option>
                                <option value="admin">超级管理员 (所有权限)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">新密码</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                                <input type="password" name="password" id="edit_password" class="form-control" placeholder="留空则不修改密码">
                            </div>
                            <div class="form-text text-muted">如果不需要修改密码，请留空</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> 保存修改
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="changePasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="changePwdForm">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="id" id="change_password_id">
                    <div class="modal-header">
                        <h5 class="modal-title">修改密码</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted mb-3">正在为用户 <strong id="change_password_username" class="text-dark"></strong> 修改密码</p>
                        <div class="mb-3">
                            <label class="form-label">新密码 <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                                <input type="password" name="new_password" class="form-control" placeholder="请输入新密码" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-save"></i> 保存修改
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="sessionLogsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-clock-history me-2 text-info"></i>
                        用户「<span id="sessionLogsUsername"></span>」的登录记录
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="sessionLogsContent" style="min-height:200px;">
                </div>
                <div class="modal-footer">
                    <input type="hidden" id="sessionLogsUserId">
                    <button type="button" class="btn btn-outline-danger" onclick="adminRemoveAllDevices()">
                        <i class="bi bi-box-arrow-right me-1"></i>强制下线所有设备
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="userRecordsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-file-earmark-text me-2 text-warning"></i>
                        用户「<span id="userRecordsUsername"></span>」的填写记录
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="userRecordsContent" style="min-height:200px;">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="banConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" id="banConfirmHeader">
                    <h5 class="modal-title" id="banConfirmTitle"><i class="bi bi-exclamation-triangle-fill me-2"></i>封禁确认</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="banConfirmBody" style="max-height:60vh;overflow-y:auto;"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-danger btn-sm" id="banConfirmBtn">
                        <i class="bi bi-lock-fill me-1"></i>确认封禁
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 申诉详情模态框 -->
    <div class="modal fade" id="appealModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-envelope-paper me-2"></i>申诉详情</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="appealDetail"></div>
            </div>
        </div>
    </div>

    <!-- 申诉审核模态框 -->
    <div class="modal fade" id="reviewModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="reviewModalTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="reviewId">
                    <input type="hidden" id="reviewAction">
                    <div class="form-check mb-3" id="unbanCheckWrapper">
                        <input class="form-check-input" type="checkbox" id="unbanCheck" checked>
                        <label class="form-check-label" for="unbanCheck"><i class="bi bi-unlock me-1"></i>同时执行解封操作</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">回复留言</label>
                        <textarea class="form-control" id="reviewReply" rows="3" placeholder="可选，将发送到用户邮箱"></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-success flex-fill" id="reviewSubmitBtn" onclick="submitReview()"><i class="bi bi-check-lg me-1"></i>确认</button>
                        <button class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= getResourceUrl('/assets/js/bootstrap.bundle.min.js', 'https://cdn.staticfile.net/bootstrap/5.3.0/js/bootstrap.bundle.min.js') ?>"></script>
    <script>
    let currentBanId = 0;

    function showAlert(message, type = 'success') {
        const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-${type === 'success' ? 'check' : 'exclamation'}-circle-fill me-2"></i> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>`;
        document.getElementById('alertContainer').innerHTML = alertHtml;
    }

    function reloadTableAndStats() {
        fetch(location.href)
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                // 更新表格
                const newTable = doc.querySelector('.table-responsive');
                const oldTable = document.querySelector('.table-responsive');
                if (newTable && oldTable) {
                    oldTable.innerHTML = newTable.innerHTML;
                }
                
                // 更新统计数据
                const newStats = doc.querySelectorAll('.stats-card');
                const oldStats = document.querySelectorAll('.stats-card');
                if (newStats.length === oldStats.length) {
                    for (let i = 0; i < newStats.length; i++) {
                        oldStats[i].innerHTML = newStats[i].innerHTML;
                    }
                }
            });
    }

    function handleAjaxForm(formId, modalId) {
        const form = document.getElementById(formId);
        if (!form) return;
        
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(form);
            
            fetch(location.pathname, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (modalId) {
                        const modalEl = document.getElementById(modalId);
                        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                        if (modal) modal.hide();
                    }
                    if (formId === 'addAdminForm') form.reset();
                    showAlert(data.message, 'success');
                    reloadTableAndStats();
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(err => {
                console.error(err);
                showAlert('网络错误或服务器异常', 'danger');
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        handleAjaxForm('addAdminForm', 'addAdminModal');
        handleAjaxForm('editAdminForm', 'editModal');
        handleAjaxForm('changePwdForm', 'changePasswordModal');
        loadBannedUsers(1);
    });

    function showEditModal(id, username, email, role) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_username').value = username;
        document.getElementById('edit_email').value = email;
        document.getElementById('edit_role').value = role;
        document.getElementById('edit_password').value = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
    }

    function showChangePasswordModal(id, username) {
        document.getElementById('change_password_id').value = id;
        document.getElementById('change_password_username').textContent = username;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('changePasswordModal')).show();
    }

    function deleteAdmin(id, username) {
        if (confirm('确定要删除管理员 "' + username + '" 吗？\n\n此操作不可恢复，请谨慎操作！')) {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            
            fetch(location.pathname, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    reloadTableAndStats();
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(err => showAlert('操作失败', 'danger'));
        }
    }

    function toggleBan(id, username, status) {
        currentBanId = id;
        const header = document.getElementById('banConfirmHeader');
        const title = document.getElementById('banConfirmTitle');
        const body = document.getElementById('banConfirmBody');
        const btn = document.getElementById('banConfirmBtn');

        if (status) {
            // 封禁 - 先加载 IP 列表
            header.className = 'modal-header bg-danger text-white';
            title.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>封禁用户 - ' + escHtml(username);
            body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-light" role="status"></div><p class="mt-2 text-muted small">加载IP记录...</p></div>';
            btn.className = 'btn btn-danger btn-sm';
            btn.innerHTML = '<i class="bi bi-lock-fill me-1"></i>确认封禁';
            btn.onclick = null;
            btn.disabled = true;

            bootstrap.Modal.getOrCreateInstance(document.getElementById('banConfirmModal')).show();

            // 获取用户历史 IP
            const formData = new FormData();
            formData.append('action', 'get_user_ban_ips');
            formData.append('user_id', id);
            fetch(location.pathname, { method:'POST', body:formData, headers:{'X-Requested-With':'XMLHttpRequest'} })
            .then(r => r.json())
            .then(data => {
                if (!data.success) { body.innerHTML = '<p class="text-danger">加载IP失败: ' + escHtml(data.message) + '</p>'; return; }
                const ips = data.data;
                let html = '<p class="mb-2">确定要封禁用户 <strong class="text-danger">' + escHtml(username) + '</strong> 吗？</p>';
                html += '<p class="text-muted small mb-3">封禁后该用户将无法登录，所有设备将强制下线。</p>';

                if (ips.length > 0) {
                    html += '<div class="mb-2 d-flex justify-content-between align-items-center">';
                    html += '<label class="form-label mb-0 fw-semibold"><i class="bi bi-shield-exclamation me-1"></i>选择要封禁的 IP 地址</label>';
                    html += '<div class="btn-group btn-group-sm">';
                    html += '<button type="button" class="btn btn-outline-primary btn-sm" onclick="toggleBanIpSelect(true, ' + id + ')">全选</button>';
                    html += '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleBanIpSelect(false, ' + id + ')">全不选</button>';
                    html += '</div></div>';
                    html += '<div class="list-group list-group-flush small" id="banIpList">';
                    ips.forEach(ip => {
                        const sharedCount = ip.shared_users ? ip.shared_users.length : 0;
                        let sharedInfo = '';
                        if (sharedCount > 0) {
                            const names = ip.shared_users.map(u => {
                                const badge = u.is_banned ? '<span class="badge bg-danger" style="font-size:0.6rem">封</span>' : '';
                                return badge + escHtml(u.username);
                            }).join(', ');
                            sharedInfo = ' <span class="text-warning"><i class="bi bi-people-fill me-1" title="其他用户也使用过此IP"></i>' + sharedCount + '个用户: ' + names + '</span>';
                        } else {
                            sharedInfo = ' <span class="text-success"><i class="bi bi-person-check me-1"></i>仅此用户</span>';
                        }
                        html += '<label class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3">';
                        html += '<input class="form-check-input me-3 ban-ip-check" type="checkbox" value="' + escHtml(ip.ip_address) + '" checked style="margin-top:2px;">';
                        html += '<div class="flex-grow-1"><code>' + escHtml(ip.ip_address) + '</code>' + sharedInfo;
                        html += '<br><span class="text-muted" style="font-size:0.7rem">最后登录: ' + escHtml(ip.last_login_at) + '</span></div>';
                        html += '</label>';
                    });
                    html += '</div>';
                } else {
                    html += '<p class="text-muted small"><i class="bi bi-info-circle me-1"></i>该用户没有历史登录 IP 记录</p>';
                }
                body.innerHTML = html;
                btn.disabled = false;
                btn.onclick = function() { doToggleBanWithIps(currentBanId); };
            })
            .catch(() => { body.innerHTML = '<p class="text-danger">网络错误</p>'; });
        } else {
            // 解封
            header.className = 'modal-header bg-success text-white';
            title.innerHTML = '<i class="bi bi-unlock-fill me-2"></i>解封用户 - ' + escHtml(username);
            body.innerHTML =
                '<p class="mb-2">确定要解封用户 <strong class="text-success">' + escHtml(username) + '</strong> 吗？</p>' +
                '<div class="form-check mb-0">' +
                '<input class="form-check-input" type="checkbox" id="banConfirmIp" checked>' +
                '<label class="form-check-label small" for="banConfirmIp">同时解封该用户关联的所有 IP 地址</label>' +
                '</div>';
            btn.className = 'btn btn-success btn-sm';
            btn.innerHTML = '<i class="bi bi-unlock-fill me-1"></i>确认解封';
            btn.disabled = false;
            btn.onclick = function() { doToggleBan(currentBanId, 0, document.getElementById('banConfirmIp').checked ? 1 : 0); };
            bootstrap.Modal.getOrCreateInstance(document.getElementById('banConfirmModal')).show();
        }
    }

    function toggleBanIpSelect(checked, userId) {
        document.querySelectorAll('.ban-ip-check').forEach(cb => { cb.checked = checked; });
    }

    function doToggleBanWithIps(id) {
        const checkboxes = document.querySelectorAll('.ban-ip-check:checked');
        const selectedIps = Array.from(checkboxes).map(cb => cb.value);
        const ipList = selectedIps.join(',');
        doToggleBan(id, 1, 0, ipList);
    }

    function doToggleBan(id, status, banIp, banIpList) {
        // 关闭模态框
        const modalEl = document.getElementById('banConfirmModal');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();

        const formData = new FormData();
        formData.append('action', 'toggle_ban');
        formData.append('id', id);
        formData.append('status', status);
        formData.append('ban_ip', banIp);
        if (banIpList) formData.append('ban_ip_list', banIpList);

        fetch(location.pathname, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                reloadTableAndStats();
            } else {
                showAlert(data.message, 'danger');
            }
        })
        .catch(err => showAlert('操作失败', 'danger'));
    }

    // ========== 登录记录相关函数 ==========
    function showSessionLogs(userId, username) {
        const modalEl = document.getElementById('sessionLogsModal');
        document.getElementById('sessionLogsUsername').textContent = username + '（ID: ' + userId + '）';
        document.getElementById('sessionLogsContent').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">加载中...</p></div>';
        document.getElementById('sessionLogsUserId').value = userId;

        const formData = new FormData();
        formData.append('action', 'get_user_sessions');
        formData.append('user_id', userId);

        fetch(location.pathname, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderSessionLogs(data.data);
            } else {
                document.getElementById('sessionLogsContent').innerHTML = '<p class="text-danger">' + data.message + '</p>';
            }
        })
        .catch(() => {
            document.getElementById('sessionLogsContent').innerHTML = '<p class="text-danger">加载失败</p>';
        });

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function renderSessionLogs(data) {
        const container = document.getElementById('sessionLogsContent');
        const logs = data.logs || [];
        const activeCount = data.active_devices || 0;

        let html = '';
        // 在线设备
        const activeLogs = logs.filter(l => l.is_active == 1 && l.status === 'success');
        html += '<div class="mb-3"><h6 class="fw-bold"><i class="bi bi-laptop me-2 text-success"></i>在线设备（' + activeCount + '台）</h6>';
        if (activeLogs.length === 0) {
            html += '<p class="text-muted small">暂无在线设备</p>';
        } else {
            html += '<div class="list-group">';
            activeLogs.forEach(l => {
                html += '<div class="list-group-item d-flex justify-content-between align-items-center px-3 py-2" id="device-' + l.id + '">';
                html += '<div><span class="fw-bold">' + escHtml(l.device_name) + '</span>';
                if (l.is_current == 1) html += ' <span class="badge bg-success">当前</span>';
                html += '<br><small class="text-muted">' + escHtml(l.ip_address) + ' · ' + escHtml(l.login_at) + '</small></div>';
                html += '<button class="btn btn-sm btn-outline-danger" onclick="adminRemoveDevice(' + l.id + ',' + l.user_id + ')"><i class="bi bi-box-arrow-right"></i> 下线</button>';
                html += '</div>';
            });
            html += '</div>';
        }
        html += '</div>';

        // 登录历史
        html += '<div><h6 class="fw-bold"><i class="bi bi-clock-history me-2 text-info"></i>登录历史（最近 50 条）</h6>';
        if (logs.length === 0) {
            html += '<p class="text-muted small">暂无登录记录</p>';
        } else {
            html += '<div class="table-responsive" style="max-height:300px;overflow-y:auto">';
            html += '<table class="table table-sm table-hover mb-0"><thead class="table-light sticky-top"><tr><th>时间</th><th>IP</th><th>方式</th><th>状态</th><th>备注</th></tr></thead><tbody>';
            logs.forEach(l => {
                const statusBadge = l.status === 'success'
                    ? '<span class="badge bg-success">成功</span>'
                    : '<span class="badge bg-danger">失败</span>';
                const methodText = l.login_method === 'auto' ? '记住我' : '密码';
                const note = l.deleted_by_user == 1 ? '<span class="text-muted">用户已下线</span>' : (l.is_active == 0 && l.status === 'success' ? '<span class="text-muted">已下线</span>' : (l.fail_reason || ''));
                html += '<tr><td class="small" style="white-space:nowrap">' + escHtml(l.login_at) + '</td>';
                html += '<td class="small">' + escHtml(l.ip_address) + '</td>';
                html += '<td class="small">' + methodText + '</td>';
                html += '<td>' + statusBadge + '</td>';
                html += '<td class="small">' + note + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
        html += '</div>';

        container.innerHTML = html;
    }

    function escHtml(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function escJs(str) {
        if (!str) return '';
        return str.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\r/g, '\\r');
    }

    function adminRemoveDevice(sessionId, userId) {
        if (!confirm('确定强制下线此设备？')) return;
        const formData = new FormData();
        formData.append('action', 'admin_remove_device');
        formData.append('session_id', sessionId);
        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                showSessionLogs(userId, document.getElementById('sessionLogsUsername').textContent);
            } else { showAlert(data.message, 'danger'); }
        });
    }

    function adminRemoveAllDevices() {
        const userId = document.getElementById('sessionLogsUserId').value;
        const username = document.getElementById('sessionLogsUsername').textContent;
        if (!confirm('确定强制下线用户 "' + username + '" 的所有设备？')) return;
        const formData = new FormData();
        formData.append('action', 'admin_remove_all_devices');
        formData.append('user_id', userId);
        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                showSessionLogs(userId, username);
            } else { showAlert(data.message, 'danger'); }
        });
    }

    // ========== 用户填写记录相关函数 ==========
    function showUserRecords(userId, username) {
        const modalEl = document.getElementById('userRecordsModal');
        document.getElementById('userRecordsUsername').textContent = username + '（ID: ' + userId + '）';
        document.getElementById('userRecordsContent').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-warning" role="status"></div><p class="mt-2 text-muted">加载中...</p></div>';

        const formData = new FormData();
        formData.append('action', 'get_user_records');
        formData.append('user_id', userId);

        fetch(location.pathname, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderUserRecords(data.data);
            } else {
                document.getElementById('userRecordsContent').innerHTML = '<p class="text-danger">' + data.message + '</p>';
            }
        })
        .catch(() => {
            document.getElementById('userRecordsContent').innerHTML = '<p class="text-danger">加载失败</p>';
        });

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    /**
     * 修复后的渲染逻辑
     */
    function renderUserRecords(data) {
        const container = document.getElementById('userRecordsContent'); // 修正：获取 DOM 元素
        if (!container) return;

        const privacyRecords = data.privacy_records || [];
        const revokedRecords = data.revoked_records || [];
        const paidRecords = data.paid_records || [];
        const totalPrivacy = privacyRecords.length;
        const totalRevoked = revokedRecords.length;
        const totalPaid = paidRecords.length;

        let html = '';

        // 统计卡片：修复 row/col 布局
        html += '<div class="row g-3 mb-4">';
        html += `<div class="col-md-4">
                    <div class="card border-start border-4 border-primary shadow-sm h-100">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center">
                                <div class="me-3"><i class="bi bi-file-earmark-check fs-4 text-primary"></i></div>
                                <div><div class="fs-5 fw-bold">${totalPrivacy}</div><div class="text-muted small">表单记录</div></div>
                            </div>
                        </div>
                    </div>
                </div>`;
        html += `<div class="col-md-4">
                    <div class="card border-start border-4 border-danger shadow-sm h-100">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center">
                                <div class="me-3"><i class="bi bi-arrow-counterclockwise fs-4 text-danger"></i></div>
                                <div><div class="fs-5 fw-bold">${totalRevoked}</div><div class="text-muted small">已撤回</div></div>
                            </div>
                        </div>
                    </div>
                </div>`;
        html += `<div class="col-md-4">
                    <div class="card border-start border-4 border-success shadow-sm h-100">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center">
                                <div class="me-3"><i class="bi bi-credit-card fs-4 text-success"></i></div>
                                <div><div class="fs-5 fw-bold">${totalPaid}</div><div class="text-muted small">支付记录</div></div>
                            </div>
                        </div>
                    </div>
                </div>`;
        html += '</div>';

        // ========== 表单记录 ==========
        html += '<div class="mb-4">';
        html += '<h6 class="fw-bold mb-3"><i class="bi bi-file-earmark-check me-2 text-primary"></i>填写历史（' + totalPrivacy + ' 条）</h6>';
        if (totalPrivacy === 0) {
            html += '<p class="text-muted small border rounded p-3 text-center">暂无记录</p>';
        } else {
            html += '<div class="table-responsive border rounded" style="max-height:400px;overflow-y:auto">';
            html += '<table class="table table-sm table-hover mb-0"><thead class="table-light sticky-top"><tr><th>ID</th><th>文章</th><th>答案</th><th>状态</th><th>类型</th><th>时间</th></tr></thead><tbody>';
            privacyRecords.forEach(r => {
                const statusBadge = r.access_granted == 1
                    ? '<span class="badge bg-success">已授权</span>'
                    : (r.is_correct == 1 ? '<span class="badge bg-warning text-dark">正确</span>' : '<span class="badge bg-secondary">待审</span>');
                const typeMap = {'fixed_answer':'固定答案','open_answer':'开放答案','manual_approval':'人工审核','login_only':'仅需登录'};
                const typeText = typeMap[r.privacy_type] || r.privacy_type || '-';
                const answerShort = r.answer ? (r.answer.length > 40 ? r.answer.substring(0, 40) + '...' : r.answer) : '-';
                html += '<tr>';
                html += '<td class="small text-muted">#' + r.id + '</td>';
                html += '<td class="small"><a href="/blog.php?id=' + r.post_id + '" target="_blank" class="text-decoration-none text-truncate d-inline-block" style="max-width:200px">' + escHtml(r.post_title || '未知文章') + '</a></td>';
                html += '<td class="small" title="' + escHtml(r.answer) + '">' + escHtml(answerShort) + '</td>';
                html += '<td>' + statusBadge + '</td>';
                html += '<td class="small text-muted">' + typeText + '</td>';
                html += '<td class="small text-muted" style="white-space:nowrap">' + escHtml(r.created_at) + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
        }
        html += '</div>';

        // ========== 已撤回记录 ==========
        html += '<div class="mb-4">';
        html += '<h6 class="fw-bold mb-3"><i class="bi bi-arrow-counterclockwise me-2 text-danger"></i>已撤回记录（' + totalRevoked + ' 条）</h6>';
        if (totalRevoked === 0) {
            html += '<p class="text-muted small border rounded p-3 text-center">暂无撤回记录</p>';
        } else {
            html += '<div class="table-responsive border rounded" style="max-height:400px;overflow-y:auto">';
            html += '<table class="table table-sm table-hover mb-0"><thead class="table-light sticky-top"><tr><th>ID</th><th>文章</th><th>答案</th><th>状态</th><th>类型</th><th>时间</th></tr></thead><tbody>';
            revokedRecords.forEach(r => {
                const revokeMatch = r.answer ? r.answer.match(/^(.+?)    (\d+):([^:]+):(.+)$/) : null;
                let answerDisplay = '';
                if (revokeMatch) {
                    answerDisplay = escHtml(revokeMatch[1]) + (revokeMatch[1].length > 30 ? '...' : '');
                } else {
                    answerDisplay = escHtml(r.answer ? (r.answer.length > 40 ? r.answer.substring(0, 40) + '...' : r.answer) : '-');
                }
                const typeMap = {'fixed_answer':'固定答案','open_answer':'开放答案','manual_approval':'人工审核','login_only':'仅需登录'};
                const typeText = typeMap[r.privacy_type] || r.privacy_type || '-';
                html += '<tr class="table-danger bg-opacity-10">';
                html += '<td class="small text-muted">#' + r.id + '</td>';
                html += '<td class="small"><a href="/blog.php?id=' + r.post_id + '" target="_blank" class="text-decoration-none text-truncate d-inline-block" style="max-width:200px">' + escHtml(r.post_title || '未知文章') + '</a></td>';
                html += '<td class="small">' + answerDisplay + '</td>';
                html += '<td><span class="badge bg-danger">已撤回</span></td>';
                html += '<td class="small text-muted">' + typeText + '</td>';
                html += '<td class="small text-muted" style="white-space:nowrap">' + escHtml(r.created_at) + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
        }
        html += '</div>';

        // ========== 支付记录 ==========
        html += '<div>';
        html += '<h6 class="fw-bold mb-3"><i class="bi bi-credit-card me-2 text-success"></i>支付历史（' + totalPaid + ' 条）</h6>';
        if (totalPaid === 0) {
            html += '<p class="text-muted small border rounded p-3 text-center">暂无支付记录</p>';
        } else {
            html += '<div class="table-responsive border rounded" style="max-height:400px;overflow-y:auto">';
            html += '<table class="table table-sm table-hover mb-0"><thead class="table-light sticky-top"><tr><th>ID</th><th>文章</th><th>金额</th><th>状态</th><th>订单号</th><th>时间</th></tr></thead><tbody>';
            paidRecords.forEach(r => {
                const statusBadge = r.status == 1
                    ? '<span class="badge bg-success">已支付</span>'
                    : '<span class="badge bg-warning text-dark">待支付</span>';
                html += '<tr>';
                html += '<td class="small text-muted">#' + r.id + '</td>';
                html += '<td class="small"><a href="/blog.php?id=' + r.post_id + '" target="_blank" class="text-decoration-none text-truncate d-inline-block" style="max-width:200px">' + escHtml(r.post_title || '未知文章') + '</a></td>';
                html += '<td class="small fw-bold text-success">&yen;' + parseFloat(r.amount).toFixed(2) + '</td>';
                html += '<td>' + statusBadge + '</td>';
                html += '<td class="small text-muted" style="font-family:monospace">' + escHtml(r.trade_no || '-') + '</td>';
                html += '<td class="small text-muted" style="white-space:nowrap">' + escHtml(r.created_at) + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
        }
        html += '</div>';

        container.innerHTML = html;
    }

    // ========== 封禁用户分页 ==========
    let bannedUsersPage = 1;
    function loadBannedUsers(page) {
        bannedUsersPage = page || 1;
        const panel = document.getElementById('bannedUsersPanel');
        panel.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-danger" role="status"></div><p class="mt-2 text-muted">加载中...</p></div>';

        const formData = new FormData();
        formData.append('action', 'get_banned_users');
        formData.append('page', bannedUsersPage);

        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) renderBannedUsers(data.data);
            else panel.innerHTML = '<p class="text-danger">' + data.message + '</p>';
        })
        .catch(() => panel.innerHTML = '<p class="text-danger">加载失败</p>');
    }

    function renderBannedUsers(data) {
        const users = data.users || [];
        const panel = document.getElementById('bannedUsersPanel');
        let html = '';

        if (users.length === 0) {
            html = '<div class="text-center py-5 text-muted"><i class="bi bi-check-circle fs-1 d-block mb-2 text-success"></i>暂无封禁用户</div>';
        } else {
            html += '<div class="table-responsive"><table class="table table-hover align-middle mb-0">';
            html += '<thead class="table-light"><tr><th>用户</th><th>角色</th><th>最后登录</th><th>封禁时间</th><th>历史IP</th><th class="text-end">操作</th></tr></thead><tbody>';
            users.forEach(u => {
                const roleText = u.role === 'admin' ? '<span class="badge bg-primary">管理员</span>' : '<span class="badge bg-secondary">用户</span>';
                const lastLogin = u.last_login ? escHtml(u.last_login) : '<span class="text-muted">-</span>';
                const ipBadges = (u.ips || []).map(ip =>
                    '<span class="badge bg-light text-dark border me-1 mb-1" style="font-family:monospace;font-size:0.75rem" title="' + escHtml(ip.login_at) + '">' + escHtml(ip.ip_address) + '</span>'
                ).join('');

                html += '<tr>';
                html += '<td><div class="fw-bold">' + escHtml(u.username) + '</div><div class="text-muted small">' + escHtml(u.email || '') + '</div></td>';
                html += '<td>' + roleText + '</td>';
                html += '<td class="small">' + lastLogin + '</td>';
                html += '<td class="small">' + escHtml(u.created_at) + '</td>';
                html += '<td>' + (ipBadges || '<span class="text-muted small">-</span>') + '</td>';
                html += '<td class="text-end"><button class="btn btn-sm btn-outline-success" onclick="quickUnbanUser(' + u.id + ',\'' + escHtml(u.username).replace(/'/g, "\\'") + '\')"><i class="bi bi-unlock me-1"></i>解封</button></td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            html += renderPagination(data, 'loadBannedUsers');
        }
        panel.innerHTML = html;
    }

    function quickUnbanUser(id, username) {
        if (!confirm('确定解封用户 "' + username + '" 吗？')) return;
        const formData = new FormData();
        formData.append('action', 'quick_unban_user');
        formData.append('id', id);
        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                loadBannedUsers(bannedUsersPage);
                reloadTableAndStats();
            } else { showAlert(data.message, 'danger'); }
        });
    }

    // ========== 封禁IP分页 ==========
    let bannedIpsPage = 1;
    let bannedIpsSearch = '';
    function loadBannedIps(page, search) {
        bannedIpsPage = page || 1;
        if (search !== undefined) bannedIpsSearch = search;
        const panel = document.getElementById('bannedIpsPanel');
        panel.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-warning" role="status"></div><p class="mt-2 text-muted">加载中...</p></div>';

        const formData = new FormData();
        formData.append('action', 'get_banned_ips');
        formData.append('page', bannedIpsPage);
        formData.append('search', bannedIpsSearch);

        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) renderBannedIps(data.data);
            else panel.innerHTML = '<p class="text-danger">' + data.message + '</p>';
        })
        .catch(() => panel.innerHTML = '<p class="text-danger">加载失败</p>');
    }

    function renderBannedIps(data) {
        const ips = data.ips || [];
        const panel = document.getElementById('bannedIpsPanel');
        let html = '';

        // 搜索框
        html += '<div class="mb-3"><div class="input-group"><input type="text" class="form-control" id="bannedIpSearchInput" placeholder="搜索 IP 地址..." value="' + escHtml(bannedIpsSearch) + '" onkeyup="if(event.keyCode===13)searchBannedIps()"><button class="btn btn-outline-primary" type="button" onclick="searchBannedIps()"><i class="bi bi-search"></i></button></div></div>';

        if (ips.length === 0) {
            html += '<div class="text-center py-5 text-muted"><i class="bi bi-check-circle fs-1 d-block mb-2 text-success"></i>' + (bannedIpsSearch ? '未找到匹配的IP' : '暂无封禁IP') + '</div>';
        } else {
            html += '<div class="table-responsive"><table class="table table-hover align-middle mb-0">';
            html += '<thead class="table-light"><tr><th>IP 地址</th><th>关联用户</th><th>封禁原因</th><th>封禁时间</th><th>过期时间</th><th class="text-end">操作</th></tr></thead><tbody>';
            ips.forEach(ip => {
                const expiresText = ip.expires_at
                    ? '<span class="text-warning">' + escHtml(ip.expires_at) + '</span>'
                    : '<span class="text-danger">永久</span>';

                const userBadges = (ip.users || []).map(u => {
                    const banned = u.is_banned == 1 ? ' <span class="badge bg-danger" style="font-size:0.6rem">封</span>' : '';
                    return '<a href="javascript:void(0)" onclick="showSessionLogs(' + u.user_id + ',\'' + escHtml(u.username).replace(/'/g, "\\'") + '\')" class="badge bg-light text-dark border me-1 mb-1 text-decoration-none" style="font-size:0.75rem" title="最后登录: ' + escHtml(u.last_login_at || '') + '">' + escHtml(u.username) + banned + '</a>';
                }).join('');

                html += '<tr>';
                html += '<td><code>' + escHtml(ip.ip_address) + '</code></td>';
                html += '<td>' + (userBadges || '<span class="text-muted small">无关联用户</span>') + '</td>';
                html += '<td class="small text-muted">' + escHtml(ip.reason || '-') + '</td>';
                html += '<td class="small">' + escHtml(ip.created_at) + '</td>';
                html += '<td class="small">' + expiresText + '</td>';
                html += '<td class="text-end"><button class="btn btn-sm btn-outline-success" onclick="unbanIp(' + ip.id + ',\'' + escHtml(ip.ip_address) + '\')"><i class="bi bi-unlock me-1"></i>解封</button></td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            html += renderPagination(data, 'loadBannedIps');
        }
        panel.innerHTML = html;
    }

    function searchBannedIps() {
        const searchInput = document.getElementById('bannedIpSearchInput');
        const searchVal = searchInput ? searchInput.value.trim() : '';
        loadBannedIps(1, searchVal);
    }

    function unbanIp(id, ip) {
        if (!confirm('确定解封 IP "' + ip + '" 吗？')) return;
        const formData = new FormData();
        formData.append('action', 'unban_ip');
        formData.append('ip_id', id);
        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                loadBannedIps(bannedIpsPage);
            } else { showAlert(data.message, 'danger'); }
        });
    }

    // ========== IP白名单 ==========
    let whitelistPage = 1;
    let whitelistSearch = '';
    function loadWhitelist(page, search) {
        whitelistPage = page || 1;
        if (search !== undefined) whitelistSearch = search;
        const panel = document.getElementById('whitelistPanel');
        panel.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-success" role="status"></div><p class="mt-2 text-muted">加载中...</p></div>';

        const formData = new FormData();
        formData.append('action', 'get_whitelist');
        formData.append('page', whitelistPage);
        formData.append('search', whitelistSearch);

        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) renderWhitelist(data.data);
            else panel.innerHTML = '<p class="text-danger">' + data.message + '</p>';
        })
        .catch(() => panel.innerHTML = '<p class="text-danger">加载失败</p>');
    }

    function renderWhitelist(data) {
        const ips = data.ips || [];
        const panel = document.getElementById('whitelistPanel');
        let html = '';

        // 搜索框和添加按钮
        html += '<div class="p-3 border-bottom"><div class="row g-2 align-items-center"><div class="col"><div class="input-group"><input type="text" class="form-control" id="whitelistSearchInput" placeholder="搜索 IP 地址..." value="' + escHtml(whitelistSearch) + '" onkeyup="if(event.keyCode===13)searchWhitelist()"><button class="btn btn-outline-primary" type="button" onclick="searchWhitelist()"><i class="bi bi-search"></i></button></div></div><div class="col-auto"><button class="btn btn-success" onclick="showAddWhitelistModal()"><i class="bi bi-plus-lg me-1"></i>添加白名单</button></div></div></div>';

        if (ips.length === 0) {
            html += '<div class="text-center py-5 text-muted"><i class="bi bi-shield-check fs-1 d-block mb-2"></i>' + (whitelistSearch ? '未找到匹配的IP' : '暂无白名单IP') + '</div>';
        } else {
            html += '<div class="table-responsive"><table class="table table-hover align-middle mb-0">';
            html += '<thead class="table-light"><tr><th>IP 地址</th><th>备注</th><th>添加者</th><th>添加时间</th><th class="text-end">操作</th></tr></thead><tbody>';
            ips.forEach(ip => {
                html += '<tr>';
                html += '<td><code>' + escHtml(ip.ip_address) + '</code></td>';
                html += '<td class="small text-muted">' + escHtml(ip.reason || '-') + '</td>';
                html += '<td class="small">' + escHtml(ip.added_by_name || '系统') + '</td>';
                html += '<td class="small">' + escHtml(ip.created_at) + '</td>';
                html += '<td class="text-end"><button class="btn btn-sm btn-outline-danger" onclick="removeWhitelist(' + ip.id + ',\'' + escHtml(ip.ip_address) + '\')"><i class="bi bi-trash me-1"></i>移除</button></td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            html += renderPagination(data, 'loadWhitelist');
        }
        panel.innerHTML = html;
    }

    function searchWhitelist() {
        const searchInput = document.getElementById('whitelistSearchInput');
        const searchVal = searchInput ? searchInput.value.trim() : '';
        loadWhitelist(1, searchVal);
    }

    function showAddWhitelistModal() {
        let modalHtml = '<div class="modal fade" id="addWhitelistModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-shield-check me-2"></i>添加IP白名单</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="addWhitelistForm"><div class="mb-3"><label class="form-label">IP 地址 <span class="text-danger">*</span></label><input type="text" class="form-control" name="ip" required placeholder="例如：192.168.1.1 或 2001:db8::1"></div><div class="mb-3"><label class="form-label">备注</label><input type="text" class="form-control" name="reason" placeholder="可选，添加备注"></div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="button" class="btn btn-success" onclick="submitAddWhitelist()"><i class="bi bi-check-lg me-1"></i>添加</button></div></div></div></div>';
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        const modal = new bootstrap.Modal(document.getElementById('addWhitelistModal'));
        modal.show();
        document.getElementById('addWhitelistModal').addEventListener('hidden.bs.modal', function() { this.remove(); });
    }

    function submitAddWhitelist() {
        const form = document.getElementById('addWhitelistForm');
        const ip = form.querySelector('[name="ip"]').value.trim();
        const reason = form.querySelector('[name="reason"]').value.trim();

        if (!ip) { showAlert('请输入IP地址', 'danger'); return; }

        const formData = new FormData();
        formData.append('action', 'add_whitelist');
        formData.append('ip', ip);
        formData.append('reason', reason);

        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('addWhitelistModal')).hide();
                loadWhitelist(1);
            } else {
                showAlert(data.message, 'danger');
            }
        });
    }

    function removeWhitelist(id, ip) {
        if (!confirm('确定将 IP "' + ip + '" 从白名单移除吗？')) return;
        const formData = new FormData();
        formData.append('action', 'remove_whitelist');
        formData.append('ip_id', id);
        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                loadWhitelist(whitelistPage);
            } else { showAlert(data.message, 'danger'); }
        });
    }

    // ========== UA白名单 ==========
    let uaWhitelistPage = 1;
    let uaWhitelistSearch = '';
    function loadUaWhitelist(page, search) {
        uaWhitelistPage = page || 1;
        if (search !== undefined) uaWhitelistSearch = search;
        const panel = document.getElementById('uaWhitelistPanel');
        panel.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-info" role="status"></div><p class="mt-2 text-muted">加载中...</p></div>';

        const formData = new FormData();
        formData.append('action', 'get_bot_whitelist_ua');
        formData.append('page', uaWhitelistPage);
        formData.append('search', uaWhitelistSearch);

        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) renderUaWhitelist(data.data);
            else panel.innerHTML = '<p class="text-danger">' + data.message + '</p>';
        })
        .catch(() => panel.innerHTML = '<p class="text-danger">加载失败</p>');
    }

    function renderUaWhitelist(data) {
        const list = data.list || [];
        const panel = document.getElementById('uaWhitelistPanel');
        let html = '';

        html += '<div class="p-3 border-bottom"><div class="row g-2 align-items-center"><div class="col"><div class="input-group"><input type="text" class="form-control" id="uaWhitelistSearchInput" placeholder="搜索 UA 标识..." value="' + escHtml(uaWhitelistSearch) + '" onkeyup="if(event.keyCode===13)searchUaWhitelist()"><button class="btn btn-outline-primary" type="button" onclick="searchUaWhitelist()"><i class="bi bi-search"></i></button></div></div><div class="col-auto"><button class="btn btn-info" onclick="showAddUaWhitelistModal()"><i class="bi bi-plus-lg me-1"></i>添加UA</button></div></div></div>';

        if (list.length === 0) {
            html += '<div class="text-center py-5 text-muted"><i class="bi bi-browser-chrome fs-1 d-block mb-2"></i>' + (uaWhitelistSearch ? '未找到匹配的记录' : '暂无UA白名单') + '</div>';
        } else {
            html += '<div class="table-responsive"><table class="table table-hover align-middle mb-0">';
            html += '<thead class="table-light"><tr><th>UA 标识</th><th>备注</th><th>添加者</th><th>添加时间</th><th class="text-end">操作</th></tr></thead><tbody>';
            list.forEach(item => {
                html += '<tr>';
                html += '<td><code style="font-size:0.85rem">' + escHtml(item.ua_pattern) + '</code></td>';
                html += '<td class="small text-muted">' + escHtml(item.reason || '-') + '</td>';
                html += '<td class="small">' + escHtml(item.added_by_name || '系统') + '</td>';
                html += '<td class="small">' + escHtml(item.created_at) + '</td>';
                html += '<td class="text-end"><button class="btn btn-sm btn-outline-danger" onclick="removeUaWhitelist(' + item.id + ')"><i class="bi bi-trash me-1"></i>移除</button></td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            html += renderPagination(data, 'loadUaWhitelist');
        }
        panel.innerHTML = html;
    }

    function searchUaWhitelist() {
        const searchInput = document.getElementById('uaWhitelistSearchInput');
        const searchVal = searchInput ? searchInput.value.trim() : '';
        loadUaWhitelist(1, searchVal);
    }

    function showAddUaWhitelistModal() {
        let modalHtml = '<div class="modal fade" id="addUaWhitelistModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-browser-chrome me-2"></i>添加UA白名单</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="addUaWhitelistForm"><div class="mb-3"><label class="form-label">UA 标识 <span class="text-danger">*</span></label><input type="text" class="form-control" name="ua_pattern" required placeholder="例如：Googlebot、Baiduspider"><div class="form-text">支持模糊匹配，包含此字符串的UA都将被放行</div></div><div class="mb-3"><label class="form-label">备注</label><input type="text" class="form-control" name="reason" placeholder="可选，添加备注"></div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="button" class="btn btn-info" onclick="submitAddUaWhitelist()"><i class="bi bi-check-lg me-1"></i>添加</button></div></div></div></div>';
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        const modal = new bootstrap.Modal(document.getElementById('addUaWhitelistModal'));
        modal.show();
        document.getElementById('addUaWhitelistModal').addEventListener('hidden.bs.modal', function() { this.remove(); });
    }

    function submitAddUaWhitelist() {
        const form = document.getElementById('addUaWhitelistForm');
        const uaPattern = form.querySelector('[name="ua_pattern"]').value.trim();
        const reason = form.querySelector('[name="reason"]').value.trim();

        if (!uaPattern) { showAlert('请输入UA标识', 'danger'); return; }

        const formData = new FormData();
        formData.append('action', 'add_bot_whitelist_ua');
        formData.append('ua_pattern', uaPattern);
        formData.append('reason', reason);

        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('addUaWhitelistModal')).hide();
                loadUaWhitelist(1);
            } else {
                showAlert(data.message, 'danger');
            }
        });
    }

    function removeUaWhitelist(id) {
        if (!confirm('确定移除该UA白名单吗？')) return;
        const formData = new FormData();
        formData.append('action', 'remove_bot_whitelist_ua');
        formData.append('id', id);
        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                loadUaWhitelist(uaWhitelistPage);
            } else { showAlert(data.message, 'danger'); }
        });
    }

    // ========== 通用分页渲染 ==========
    function renderPagination(data, loadFn) {
        const totalPages = data.total_pages || 1;
        const page = data.page || 1;
        const total = data.total || 0;
        if (totalPages <= 1) return '';

        let html = '<nav class="mt-3 d-flex justify-content-between align-items-center"><div class="small text-muted">共 ' + total + ' 条</div>';
        html += '<ul class="pagination pagination-sm mb-0">';
        html += '<li class="page-item ' + (page <= 1 ? 'disabled' : '') + '"><a class="page-link" href="javascript:void(0)" onclick="' + loadFn + '(' + (page - 1) + ')">上一页</a></li>';

        const maxVisible = 5;
        let startPage = Math.max(1, page - Math.floor(maxVisible / 2));
        let endPage = Math.min(totalPages, startPage + maxVisible - 1);
        if (endPage - startPage < maxVisible - 1) startPage = Math.max(1, endPage - maxVisible + 1);

        if (startPage > 1) {
            html += '<li class="page-item"><a class="page-link" href="javascript:void(0)" onclick="' + loadFn + '(1)">1</a></li>';
            if (startPage > 2) html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        for (let i = startPage; i <= endPage; i++) {
            html += '<li class="page-item ' + (i === page ? 'active' : '') + '"><a class="page-link" href="javascript:void(0)" onclick="' + loadFn + '(' + i + ')">' + i + '</a></li>';
        }
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            html += '<li class="page-item"><a class="page-link" href="javascript:void(0)" onclick="' + loadFn + '(' + totalPages + ')">' + totalPages + '</a></li>';
        }

        html += '<li class="page-item ' + (page >= totalPages ? 'disabled' : '') + '"><a class="page-link" href="javascript:void(0)" onclick="' + loadFn + '(' + (page + 1) + ')">下一页</a></li>';
        html += '</ul></nav>';
        return html;
    }

    // ========== 申诉管理 ==========
    let appealsPage = 1;
    let appealsFilterStatus = '';
    let appealsFilterType = '';
    let appealsSearch = '';

    function loadAppeals(page) {
        appealsPage = page || 1;
        const panel = document.getElementById('appealsPanel');
        panel.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">加载中...</p></div>';

        const formData = new FormData();
        formData.append('action', 'get_appeals');
        formData.append('page', appealsPage);
        formData.append('filter_status', appealsFilterStatus);
        formData.append('filter_type', appealsFilterType);
        formData.append('search', appealsSearch);

        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) renderAppeals(data.data);
            else panel.innerHTML = '<p class="text-danger">' + data.message + '</p>';
        })
        .catch(() => panel.innerHTML = '<p class="text-danger">加载失败</p>');
    }

    function renderAppeals(data) {
        const appeals = data.appeals || [];
        const stats = data.stats || {};
        const panel = document.getElementById('appealsPanel');

        // 更新待审核badge
        const badge = document.getElementById('appealPendingBadge');
        if (badge) badge.textContent = (stats.pending || 0) > 0 ? stats.pending : '';
        if (badge && stats.pending === 0) badge.style.display = 'none';
        else if (badge) badge.style.display = '';

        let html = '';

        // 统计卡片
        html += '<div class="p-3"><div class="row g-2 mb-3">';
        const statItems = [
            { label: '待审核', count: stats.pending || 0, color: 'warning', icon: 'clock' },
            { label: '处理中', count: stats.processing || 0, color: 'info', icon: 'hourglass-split' },
            { label: '已通过', count: stats.approved || 0, color: 'success', icon: 'check-circle' },
            { label: '已拒绝', count: stats.rejected || 0, color: 'danger', icon: 'x-circle' },
        ];
        statItems.forEach(s => {
            html += '<div class="col-6 col-md-3"><div class="card border-start border-' + s.color + ' border-3 h-100"><div class="card-body py-2 px-3"><div class="d-flex align-items-center"><i class="bi bi-' + s.icon + ' text-' + s.color + ' me-2"></i><div><div class="text-muted small">' + s.label + '</div><div class="fs-5 fw-bold">' + s.count + '</div></div></div></div></div></div>';
        });
        html += '</div>';

        // 筛选栏
        html += '<form onsubmit="return false;" class="row g-2 mb-3 align-items-end">';
        html += '<div class="col-md-3 col-6"><select id="appealFilterStatus" class="form-select form-select-sm" onchange="appealsFilterStatus=this.value;loadAppeals(1)"><option value="">全部状态</option><option value="pending"' + (appealsFilterStatus==='pending'?' selected':'') + '>待审核</option><option value="processing"' + (appealsFilterStatus==='processing'?' selected':'') + '>处理中</option><option value="approved"' + (appealsFilterStatus==='approved'?' selected':'') + '>已通过</option><option value="rejected"' + (appealsFilterStatus==='rejected'?' selected':'') + '>已拒绝</option></select></div>';
        html += '<div class="col-md-3 col-6"><select id="appealFilterType" class="form-select form-select-sm" onchange="appealsFilterType=this.value;loadAppeals(1)"><option value="">全部类型</option><option value="ip"' + (appealsFilterType==='ip'?' selected':'') + '>IP申诉</option><option value="user"' + (appealsFilterType==='user'?' selected':'') + '>账号申诉</option><option value="ip_user"' + (appealsFilterType==='ip_user'?' selected':'') + '>IP+账号</option></select></div>';
        html += '<div class="col-md-4"><input type="text" id="appealSearchInput" class="form-control form-control-sm" placeholder="IP/姓名/邮箱/理由" value="' + escHtml(appealsSearch) + '" onkeydown="if(event.key===\'Enter\'){appealsSearch=this.value;loadAppeals(1);}"></div>';
        html += '<div class="col-md-2"><button class="btn btn-sm btn-primary w-100" onclick="appealsSearch=document.getElementById(\'appealSearchInput\').value;loadAppeals(1)"><i class="bi bi-search me-1"></i>搜索</button></div>';
        html += '</form>';

        if (appeals.length === 0) {
            html += '<div class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i>暂无申诉记录</div>';
        } else {
            const statusMap = { pending: { text: '待审核', cls: 'warning', icon: 'clock' }, processing: { text: '处理中', cls: 'info', icon: 'hourglass-split' }, approved: { text: '已通过', cls: 'success', icon: 'check-circle' }, rejected: { text: '已拒绝', cls: 'danger', icon: 'x-circle' } };
            const typeMap = { ip: { text: 'IP申诉', badge: 'primary' }, user: { text: '账号申诉', badge: 'secondary' }, ip_user: { text: 'IP+账号', badge: 'dark' } };

            html += '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th class="ps-3">ID</th><th>类型</th><th>IP/用户</th><th>联系人</th><th>理由</th><th>状态</th><th>时间</th><th>操作</th></tr></thead><tbody>';
            appeals.forEach(a => {
                const st = statusMap[a.status] || statusMap.pending;
                const tp = typeMap[a.appeal_type] || typeMap.ip;
                html += '<tr>';
                html += '<td class="ps-3 fw-bold">#' + a.id + '</td>';
                html += '<td><span class="badge bg-' + tp.badge + '">' + tp.text + '</span></td>';
                html += '<td><div class="small">';
                if (a.appeal_type !== 'user') html += '<i class="bi bi-globe me-1"></i>' + escHtml(a.ip_address);
                if (a.user_id) html += '<br><i class="bi bi-person me-1"></i>' + escHtml(a.user_username || 'ID:' + a.user_id);
                html += '</div></td>';
                html += '<td><div class="fw-semibold small">' + escHtml(a.contact_name) + '</div><div class="text-muted" style="font-size:0.75rem">' + escHtml(a.contact_email) + '</div></td>';
                html += '<td><div class="small text-truncate" style="max-width:200px" title="' + escHtml(a.reason) + '">' + escHtml(a.reason.substring(0, 40)) + '...</div></td>';
                html += '<td><span class="badge bg-' + st.cls + '"><i class="bi bi-' + st.icon + ' me-1"></i>' + st.text + '</span>';
                if (a.reviewed_at) html += '<div class="text-muted" style="font-size:0.7rem">' + escHtml(a.reviewed_at.substring(5, 16)) + '</div>';
                html += '</td>';
                html += '<td class="small text-muted">' + escHtml(a.created_at.substring(5, 16)) + '</td>';
                html += '<td><div class="btn-group btn-group-sm">';
                if (a.status === 'pending' || a.status === 'processing') {
                    html += '<button class="btn btn-outline-success" onclick="reviewAppeal(' + a.id + ',\'approve\')" title="通过"><i class="bi bi-check-lg"></i></button>';
                    html += '<button class="btn btn-outline-danger" onclick="reviewAppeal(' + a.id + ',\'reject\')" title="拒绝"><i class="bi bi-x-lg"></i></button>';
                    if (a.status === 'pending') html += '<button class="btn btn-outline-info" onclick="setAppealStatus(' + a.id + ',\'processing\')" title="处理中"><i class="bi bi-hourglass-split"></i></button>';
                }
                html += '<button class="btn btn-outline-secondary" onclick="viewAppeal(' + a.id + ')" title="详情"><i class="bi bi-eye"></i></button>';
                html += '<button class="btn btn-outline-secondary" onclick="deleteAppeal(' + a.id + ')" title="删除" style="color:#dc3545"><i class="bi bi-trash"></i></button>';
                html += '</div></td></tr>';
            });
            html += '</tbody></table></div>';
            html += renderPagination(data, 'loadAppeals');
        }
        html += '</div>';
        panel.innerHTML = html;
    }

    let ipQueryPage = 1;
    let ipQuerySearch = '';
    let ipQuerySource = '';

    function loadIpQuery(page) {
        ipQueryPage = page || 1;
        ipQuerySearch = document.getElementById('ipSearchInput')?.value || '';
        ipQuerySource = document.getElementById('ipSourceFilter')?.value || '';
        const panel = document.getElementById('ipQueryResults');
        if (!panel) return;
        panel.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">加载中...</p></div>';

        const formData = new FormData();
        formData.append('action', 'ip_query');
        formData.append('page', ipQueryPage);
        formData.append('search', ipQuerySearch);
        formData.append('source', ipQuerySource);

        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) renderIpQuery(data.data);
            else panel.innerHTML = '<p class="text-danger">' + data.message + '</p>';
        })
        .catch(() => panel.innerHTML = '<p class="text-danger">加载失败</p>');
    }

    function renderIpQuery(data) {
        const results = data.results || [];
        const panel = document.getElementById('ipQueryResults');
        if (!panel) return;

        let html = '';

        if (results.length === 0) {
            html = '<div class="text-center py-5 text-muted"><i class="bi bi-search fs-1 d-block mb-2"></i>暂无相关记录</div>';
        } else {
            html += '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr>';
            html += '<th class="ps-3">IP地址</th>';
            html += '<th>来源</th>';
            html += '<th>关联用户</th>';
            html += '<th>最后时间</th>';
            html += '<th class="pe-3 text-end">操作</th>';
            html += '</tr></thead><tbody>';

            results.forEach(r => {
                const sourceMap = {
                    'session': { text: '登录会话', cls: 'primary', icon: 'clock' },
                    'register': { text: '注册IP', cls: 'info', icon: 'person-plus' },
                    'blacklist': { text: '封禁记录', cls: 'danger', icon: 'shield-exclamation' },
                    'appeal': { text: '申诉记录', cls: 'warning', icon: 'envelope-paper' },
                    'visit': { text: '访问记录', cls: 'success', icon: 'eye' }
                };
                const src = sourceMap[r.source] || { text: r.source, cls: 'secondary', icon: 'circle' };

                html += '<tr>';
                html += '<td class="ps-3"><code class="small">' + escHtml(r.ip) + '</code></td>';
                html += '<td><span class="badge bg-' + src.cls + '"><i class="bi bi-' + src.icon + ' me-1"></i>' + src.text + '</span></td>';
                html += '<td>';

                if (r.users && r.users.length > 0) {
                    r.users.forEach((u, idx) => {
                        const bannedClass = u.is_banned ? 'text-danger fw-bold' : 'text-dark';
                        const bannedIcon = u.is_banned ? '<i class="bi bi-slash-circle text-danger me-1" style="font-size:0.7rem"></i>' : '';
                        html += '<span class="badge bg-light text-dark border me-1 mb-1">' + bannedIcon + '<span class="' + bannedClass + '">' + escHtml(u.username) + '</span></span>';
                    });
                } else {
                    html += '<span class="text-muted">-</span>';
                }
                html += '<span class="badge bg-secondary ms-1">' + r.user_count + '个</span></td>';
                html += '<td class="small text-muted">' + (r.last_time ? escHtml(r.last_time.substring(0, 16)) : '-') + '</td>';
                html += '<td class="pe-3 text-end">';
                html += '<div class="btn-group btn-group-sm">';
                html += '<button class="btn btn-outline-primary" onclick="showIpDetail(\'' + escJs(r.ip) + '\')" title="查看详情"><i class="bi bi-info-circle"></i></button>';
                if (r.source !== 'blacklist') {
                    html += '<button class="btn btn-outline-danger" onclick="banIpByQuery(\'' + escJs(r.ip) + '\')" title="封禁IP"><i class="bi bi-shield-exclamation"></i></button>';
                } else {
                    html += '<button class="btn btn-outline-success" onclick="unbanIpByQuery(' + (r.extra ? 'null' : '\'' + escJs(r.ip) + '\'') + ')" title="解封"><i class="bi bi-unlock"></i></button>';
                }
                html += '</div></td></tr>';
            });

            html += '</tbody></table></div>';
            html += renderPagination(data, 'loadIpQuery');
        }

        panel.innerHTML = html;
    }

    function showIpDetail(ip) {
        const panel = document.getElementById('ipQueryResults');
        if (!panel) return;

        // 显示加载状态
        panel.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">加载IP详情...</p></div>';

        const formData = new FormData();
        formData.append('action', 'ip_detail');
        formData.append('ip', ip);

        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderIpDetail(data.data, ip);
            } else {
                panel.innerHTML = '<p class="text-danger">' + data.message + '</p>';
            }
        })
        .catch(() => panel.innerHTML = '<p class="text-danger">加载失败</p>');
    }

    function renderIpDetail(data, ip) {
        const panel = document.getElementById('ipQueryResults');
        if (!panel) return;

        let html = '<div class="card mb-3">';
        html += '<div class="card-header bg-light d-flex justify-content-between align-items-center">';
        html += '<h6 class="mb-0"><i class="bi bi-geo-alt me-2"></i>IP: ' + escHtml(ip) + '</h6>';
        html += '<button class="btn btn-sm btn-outline-secondary" onclick="loadIpQuery(1)"><i class="bi bi-arrow-left me-1"></i>返回</button>';
        html += '</div>';
        html += '<div class="card-body">';

        // 登录会话
        if (data.sessions && data.sessions.length > 0) {
            html += '<h6 class="text-primary mb-2"><i class="bi bi-clock me-1"></i>登录会话记录</h6>';
            html += '<div class="table-responsive mb-3"><table class="table table-sm table-hover mb-0"><thead><tr><th>用户</th><th>最后登录</th><th>操作</th></tr></thead><tbody>';
            data.sessions.forEach(s => {
                const bannedClass = s.is_banned ? 'text-danger fw-bold' : '';
                html += '<tr><td><span class="' + bannedClass + '">' + escHtml(s.username) + '</span></td><td class="small text-muted">' + escHtml(s.login_at ? s.login_at.substring(0, 16) : '-') + '</td>';
                html += '<td><button class="btn btn-sm btn-outline-primary" onclick="showSessionLogs(' + s.id + ',\'' + escJs(s.username) + '\')"><i class="bi bi-clock-history"></i></button></td></tr>';
            });
            html += '</tbody></table></div>';
        }

        // 注册记录
        if (data.register && data.register.length > 0) {
            html += '<h6 class="text-info mb-2"><i class="bi bi-person-plus me-1"></i>注册IP记录</h6>';
            html += '<div class="table-responsive mb-3"><table class="table table-sm table-hover mb-0"><thead><tr><th>用户</th><th>注册时间</th></tr></thead><tbody>';
            data.register.forEach(r => {
                html += '<tr><td>' + escHtml(r.username) + '</td><td class="small text-muted">' + escHtml(r.created_at ? r.created_at.substring(0, 16) : '-') + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }

        // 封禁记录
        if (data.blacklist && data.blacklist.length > 0) {
            html += '<h6 class="text-danger mb-2"><i class="bi bi-shield-exclamation me-1"></i>封禁记录</h6>';
            html += '<div class="table-responsive mb-3"><table class="table table-sm table-hover mb-0"><thead><tr><th>原因</th><th>封禁时间</th><th>操作</th></tr></thead><tbody>';
            data.blacklist.forEach(b => {
                html += '<tr><td class="small">' + escHtml(b.reason || '-') + '</td><td class="small text-muted">' + escHtml(b.created_at ? b.created_at.substring(0, 16) : '-') + '</td>';
                html += '<td><button class="btn btn-sm btn-success" onclick="unbanIpDirect(' + b.id + ')"><i class="bi bi-unlock"></i> 解封</button></td></tr>';
            });
            html += '</tbody></table></div>';
        }

        // 申诉记录
        if (data.appeals && data.appeals.length > 0) {
            html += '<h6 class="text-warning mb-2"><i class="bi bi-envelope-paper me-1"></i>申诉记录</h6>';
            html += '<div class="table-responsive mb-3"><table class="table table-sm table-hover mb-0"><thead><tr><th>类型</th><th>联系人</th><th>状态</th><th>提交时间</th></tr></thead><tbody>';
            const typeMap = { ip: 'IP申诉', user: '账号申诉', ip_user: 'IP+账号' };
            const statusMap = { pending: ['待审核', 'warning'], processing: ['处理中', 'info'], approved: ['已通过', 'success'], rejected: ['已拒绝', 'danger'] };
            data.appeals.forEach(a => {
                const st = statusMap[a.status] || ['未知', 'secondary'];
                html += '<tr><td>' + (typeMap[a.appeal_type] || a.appeal_type) + '</td>';
                html += '<td class="small">' + escHtml(a.contact_name) + '</td>';
                html += '<td><span class="badge bg-' + st[1] + '">' + st[0] + '</span></td>';
                html += '<td class="small text-muted">' + escHtml(a.created_at ? a.created_at.substring(0, 16) : '-') + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }

        // 访问记录
        if (data.visits && data.visits.length > 0) {
            html += '<h6 class="text-success mb-2"><i class="bi bi-eye me-1"></i>访问记录</h6>';
            html += '<div class="table-responsive mb-3"><table class="table table-sm table-hover mb-0"><thead><tr><th>页面</th><th>访客</th><th>访问时间</th></tr></thead><tbody>';
            data.visits.slice(0, 20).forEach(v => {
                const visitor = v.visitor_username ? escHtml(v.visitor_username) : (v.visitor_email ? escHtml(v.visitor_email) : '-');
                html += '<tr><td class="small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escHtml(v.page_url || '-') + '">' + escHtml(v.page_url || '-') + '</td>';
                html += '<td class="small">' + visitor + '</td>';
                html += '<td class="small text-muted">' + escHtml(v.visit_time ? v.visit_time.substring(0, 16) : '-') + '</td></tr>';
            });
            if (data.visits.length > 20) {
                html += '<tr><td colspan="3" class="text-center text-muted small">... 共 ' + data.visits.length + ' 条记录，显示前20条</td></tr>';
            }
            html += '</tbody></table></div>';
        }

        html += '<div class="mt-3"><button class="btn btn-danger btn-sm" onclick="banIpByQuery(\'' + escJs(ip) + '\')"><i class="bi bi-shield-exclamation me-1"></i>封禁此IP</button></div>';
        html += '</div></div>';

        panel.innerHTML = html;
    }

    function banIpByQuery(ip) {
        if (!confirm('确定要封禁IP地址 ' + ip + ' 吗？')) return;
        const formData = new FormData();
        formData.append('action', 'add_blacklist');
        formData.append('ip', ip);
        formData.append('reason', '管理员手动封禁');

        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            showAlert(data.message || (data.success ? 'IP已封禁' : '操作失败'), data.success ? 'success' : 'danger');
            if (data.success) showIpDetail(ip);
        })
        .catch(() => showAlert('操作失败', 'danger'));
    }

    function unbanIpByQuery(ip) {
        if (!ip || !confirm('确定要解封此IP吗？')) return;
        const formData = new FormData();
        formData.append('action', 'unban_ip');
        formData.append('ip_address', ip);

        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            showAlert(data.message || (data.success ? 'IP已解封' : '操作失败'), data.success ? 'success' : 'danger');
            if (data.success) loadIpQuery(1);
        })
        .catch(() => showAlert('操作失败', 'danger'));
    }

    function unbanIpDirect(id) {
        if (!confirm('确定要解封此IP吗？')) return;
        const formData = new FormData();
        formData.append('action', 'unban_ip');
        formData.append('ip_id', id);

        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            showAlert(data.message || (data.success ? 'IP已解封' : '操作失败'), data.success ? 'success' : 'danger');
            if (data.success) loadIpQuery(1);
        })
        .catch(() => showAlert('操作失败', 'danger'));
    }

    function viewAppeal(id) {
        const formData = new FormData();
        formData.append('action', 'get_appeal_detail');
        formData.append('id', id);
        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('appealDetail').innerHTML = data.data.html;
                new bootstrap.Modal(document.getElementById('appealModal')).show();
            } else { showAlert(data.message, 'danger'); }
        });
    }

    function reviewAppeal(id, action) {
        document.getElementById('reviewId').value = id;
        document.getElementById('reviewAction').value = action;
        const isApprove = action === 'approve';
        document.getElementById('reviewModalTitle').innerHTML = '<i class="bi bi-' + (isApprove ? 'check-circle text-success' : 'x-circle text-danger') + ' me-2"></i>' + (isApprove ? '通过申诉' : '拒绝申诉');
        document.getElementById('unbanCheckWrapper').style.display = isApprove ? 'block' : 'none';
        document.getElementById('reviewReply').value = '';
        const btn = document.getElementById('reviewSubmitBtn');
        if (isApprove) {
            btn.className = 'btn btn-success flex-fill';
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>确认通过';
        } else {
            btn.className = 'btn btn-danger flex-fill';
            btn.innerHTML = '<i class="bi bi-x-lg me-1"></i>确认拒绝';
        }
        new bootstrap.Modal(document.getElementById('reviewModal')).show();
    }

    function submitReview() {
        const id = document.getElementById('reviewId').value;
        const action = document.getElementById('reviewAction').value;
        const reply = document.getElementById('reviewReply').value;
        const unban = document.getElementById('unbanCheck').checked ? '1' : '0';
        const formData = new FormData();
        formData.append('action', 'review_appeal');
        formData.append('id', id);
        formData.append('review_action', action);
        formData.append('reply', reply);
        formData.append('unban', unban);
        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('reviewModal')).hide();
                showAlert(data.message, 'success');
                loadAppeals(appealsPage);
            } else { showAlert(data.message, 'danger'); }
        });
    }

    function setAppealStatus(id, status) {
        const formData = new FormData();
        formData.append('action', 'set_appeal_status');
        formData.append('id', id);
        formData.append('status', status);
        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.success) { showAlert(data.message, 'success'); loadAppeals(appealsPage); }
            else { showAlert(data.message, 'danger'); }
        });
    }

    function deleteAppeal(id) {
        if (!confirm('确定删除此申诉记录？')) return;
        const formData = new FormData();
        formData.append('action', 'delete_appeal');
        formData.append('id', id);
        fetch(location.pathname, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.success) { showAlert(data.message, 'success'); loadAppeals(appealsPage); }
            else { showAlert(data.message, 'danger'); }
        });
    }
    </script>
<?php require_once 'includes/footer.php'; ?>