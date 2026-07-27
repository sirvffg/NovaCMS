<?php
/**
 * 图片映射表 API
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../includes/image_mapper.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// 错误处理
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误: ' . $errstr]);
    exit;
});

set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '异常: ' . $e->getMessage()]);
    exit;
});

// 检查登录
requireLogin();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'add':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['local_path']) || empty($data['local_url'])) {
            echo json_encode(['success' => false, 'error' => '缺少必要参数']);
            exit;
        }
        $key = ImageMapper::add(
            $data['local_path'],
            $data['local_url'],
            $data['image_bed_url'] ?? '',
            $data['filename'] ?? ''
        );
        echo json_encode(['success' => true, 'key' => $key]);
        break;

    case 'update':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['local_path'])) {
            echo json_encode(['success' => false, 'error' => '缺少本地路径']);
            exit;
        }
        $result = ImageMapper::updateImageBedUrl(
            $data['local_path'],
            $data['image_bed_url'] ?? '',
            $data['image_bed_id'] ?? 0
        );
        echo json_encode(['success' => $result]);
        break;

    case 'get':
        $localUrl = $_GET['local_url'] ?? '';
        $info = ImageMapper::get($localUrl);
        echo json_encode(['success' => true, 'data' => $info]);
        break;

    case 'get_final_url':
        $localUrl = $_GET['local_url'] ?? '';
        $useImageBed = isset($_GET['use_image_bed']) && $_GET['use_image_bed'];
        $finalUrl = ImageMapper::getFinalUrl($localUrl, $useImageBed);
        echo json_encode(['success' => true, 'url' => $finalUrl]);
        break;

    case 'convert_content':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['content'])) {
            echo json_encode(['success' => false, 'error' => '缺少内容']);
            exit;
        }
        $useImageBed = $data['use_image_bed'] ?? false;
        $converted = ImageMapper::convertContent($data['content'], $useImageBed);
        echo json_encode(['success' => true, 'content' => $converted]);
        break;

    case 'stats':
        $stats = ImageMapper::getStats();
        echo json_encode(['success' => true, 'stats' => $stats]);
        break;

    case 'pending':
        $pending = ImageMapper::getPendingUploads();
        echo json_encode(['success' => true, 'data' => array_values($pending)]);
        break;

    case 'batch_upload':
        require_once __DIR__ . '/../../config/database.php';
        $db = getDB();
        $config = [];
        $stmt = $db->query("SELECT * FROM website_config WHERE id = 1");
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $config = $row;
        }

        $apiUrl = $config['image_bed_api_url'] ?? '';
        $apiKey = $config['image_bed_api_key'] ?? '';

        if (empty($apiUrl) || empty($apiKey)) {
            echo json_encode(['success' => false, 'error' => '图床配置不完整']);
            exit;
        }

        // 设置超时
        set_time_limit(300);

        $result = ImageMapper::batchUploadToImageBed($apiUrl, $apiKey, function($current, $total, $filename) {
            echo json_encode(['progress' => true, 'current' => $current, 'total' => $total, 'filename' => $filename]) . "\n";
            ob_flush();
            flush();
        });

        echo json_encode(['success' => true, 'result' => $result]);
        break;

    case 'scan_local':
        // 扫描本地uploads目录识别历史图片
        $result = ImageMapper::scanLocalImages();
        echo json_encode(['success' => true, 'result' => $result]);
        break;

    case 'scan_posts':
        // 从文章内容中扫描图片URL（不带进度输出，避免JSON格式问题）
        require_once __DIR__ . '/../../config/database.php';
        $db = getDB();
        $result = ImageMapper::scanPostsImages($db);
        echo json_encode(['success' => true, 'result' => $result]);
        break;

    case 'get_all':
        $all = ImageMapper::getAll();
        echo json_encode(['success' => true, 'data' => array_values($all)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => '未知操作']);
        break;
}
