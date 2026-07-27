<?php
/**
 * 我的项目管理 API
 */
require_once '../../config/database.php';
require_once '../../config/functions.php';

// 检查登录状态
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$db = getDB();

// 自动创建表
function ensureTableExists($db) {
    $sql = "CREATE TABLE IF NOT EXISTS `my_projects` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(100) NOT NULL COMMENT '项目名称',
        `description` text COMMENT '项目描述',
        `url` varchar(255) DEFAULT NULL COMMENT '项目链接',
        `icon` varchar(255) DEFAULT NULL COMMENT '图标(类名或图片路径)',
        `tags` varchar(255) DEFAULT NULL COMMENT '标签(逗号分隔)',
        `start_date` varchar(50) DEFAULT NULL COMMENT '开始时间',
        `sort_order` int(11) DEFAULT 0 COMMENT '排序',
        `is_active` tinyint(1) DEFAULT 1 COMMENT '是否启用',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='我的项目表';";
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
            $stmt = $db->query("SELECT * FROM my_projects ORDER BY sort_order ASC, id DESC");
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $projects]);
        } else if ($action === 'get') {
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM my_projects WHERE id = ?");
            $stmt->execute([$id]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $project]);
        }
    } elseif ($method === 'POST') {
        if ($action === 'add') {
            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';
            $url = $_POST['url'] ?? '';
            $icon = $_POST['icon'] ?? '';
            $tags = $_POST['tags'] ?? '';
            $start_date = $_POST['start_date'] ?? '';
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            $stmt = $db->prepare("INSERT INTO my_projects (name, description, url, icon, tags, start_date, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $description, $url, $icon, $tags, $start_date, $sort_order, $is_active]);
            
            echo json_encode(['success' => true, 'message' => '添加成功']);
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';
            $url = $_POST['url'] ?? '';
            $icon = $_POST['icon'] ?? '';
            $tags = $_POST['tags'] ?? '';
            $start_date = $_POST['start_date'] ?? '';
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            $stmt = $db->prepare("UPDATE my_projects SET name=?, description=?, url=?, icon=?, tags=?, start_date=?, sort_order=?, is_active=? WHERE id=?");
            $stmt->execute([$name, $description, $url, $icon, $tags, $start_date, $sort_order, $is_active, $id]);
            
            echo json_encode(['success' => true, 'message' => '更新成功']);
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare("DELETE FROM my_projects WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => '删除成功']);
        }
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
