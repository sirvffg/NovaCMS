<?php
/**
 * 主页小站链接管理 API
 */
require_once '../../config/database.php';
require_once '../../config/functions.php';

// 检查登录状态 (这里假设有 session 验证，参照 admin/index.php 或其他文件)
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$db = getDB();

// 自动创建表
function ensureTableExists($db) {
    $sql = "CREATE TABLE IF NOT EXISTS `home_links` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(100) NOT NULL COMMENT '名称',
        `url` varchar(255) NOT NULL COMMENT '链接',
        `icon` varchar(255) DEFAULT NULL COMMENT '图标(类名或图片路径)',
        `description` varchar(255) DEFAULT NULL COMMENT '描述',
        `badge_text` varchar(50) DEFAULT NULL COMMENT '徽章文本',
        `badge_color` varchar(20) DEFAULT 'primary' COMMENT '徽章颜色(primary, success, etc)',
        `sort_order` int(11) DEFAULT 0 COMMENT '排序',
        `is_active` tinyint(1) DEFAULT 1 COMMENT '是否启用',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='主页小站链接表';";
    $db->exec($sql);
}

// 处理请求
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

try {
    ensureTableExists($db);

    if ($method === 'GET') {
        if ($action === 'list') {
            $stmt = $db->query("SELECT * FROM home_links ORDER BY sort_order ASC, id ASC");
            $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $links]);
        } else if ($action === 'get') {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM home_links WHERE id = ?");
            $stmt->execute([$id]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $link]);
        }
    } elseif ($method === 'POST') {
        if ($action === 'add') {
            $name = $_POST['name'] ?? '';
            $url = $_POST['url'] ?? '';
            $icon = $_POST['icon'] ?? '';
            $description = $_POST['description'] ?? '';
            $badge_text = $_POST['badge_text'] ?? '';
            $badge_color = $_POST['badge_color'] ?? 'primary';
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            $stmt = $db->prepare("INSERT INTO home_links (name, url, icon, description, badge_text, badge_color, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $url, $icon, $description, $badge_text, $badge_color, $sort_order, $is_active]);
            
            echo json_encode(['success' => true, 'message' => '添加成功']);
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $name = $_POST['name'] ?? '';
            $url = $_POST['url'] ?? '';
            $icon = $_POST['icon'] ?? '';
            $description = $_POST['description'] ?? '';
            $badge_text = $_POST['badge_text'] ?? '';
            $badge_color = $_POST['badge_color'] ?? 'primary';
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            $stmt = $db->prepare("UPDATE home_links SET name=?, url=?, icon=?, description=?, badge_text=?, badge_color=?, sort_order=?, is_active=? WHERE id=?");
            $stmt->execute([$name, $url, $icon, $description, $badge_text, $badge_color, $sort_order, $is_active, $id]);
            
            echo json_encode(['success' => true, 'message' => '更新成功']);
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare("DELETE FROM home_links WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => '删除成功']);
        }
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
