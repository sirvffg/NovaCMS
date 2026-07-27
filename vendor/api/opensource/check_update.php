<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../../../config/database.php';
require_once '../../../config/functions.php';
require_once 'Crypto.php';

$db = getDB();

try {
    // 1. 初始化加密类
    $crypto = new Crypto();

    // 2. 获取加密参数 (检查更新也需要加密通信)
    // 即使是 GET 请求，为了安全也改为 POST 接收加密参数
    $encryptedKey = $_POST['encrypted_key'] ?? '';
    $encryptedData = $_POST['encrypted_data'] ?? '';

    if (empty($encryptedKey) || empty($encryptedData)) {
        throw new Exception('Missing encrypted parameters.');
    }

    // 3. 解密请求数据 (即使只需握手)
    $requestData = $crypto->decryptRequest($encryptedKey, $encryptedData);
    
    // 可以在 requestData 中包含当前版本号，用于判断是否有更新 (可选)
    // $currentVersion = $requestData['version'] ?? '';

    // 4. 业务逻辑
    // 优先尝试从数据库获取
    $latest = null;
    try {
        $stmt = $db->query("SELECT * FROM license_version_updates ORDER BY created_at DESC LIMIT 1");
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $dbEx) {
        // Ignore DB error
    }

    $response = [];

    if ($latest) {
        $response = [
            'code' => 200,
            'msg' => 'Success',
            'data' => [
                'version' => $latest['version'],
                'update_type' => $latest['update_type'],
                'is_mandatory' => (bool)$latest['is_mandatory'],
                'download_url' => $latest['download_url'],
                'changelog' => $latest['changelog'],
                'created_at' => $latest['created_at'],
                // Add license status for client sync
                'license_status' => 'valid' 
            ]
        ];
    } else {
        $response = ['code' => 404, 'msg' => 'No updates found'];
    }

    // 5. 加密响应
    echo json_encode(['encrypted_response' => $crypto->encryptResponse($response)]);

} catch (Exception $e) {
    echo json_encode(['code' => 500, 'msg' => $e->getMessage()]);
}
