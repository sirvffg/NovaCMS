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

    // 2. 获取加密参数
    $encryptedKey = $_POST['encrypted_key'] ?? '';
    $encryptedData = $_POST['encrypted_data'] ?? '';

    if (empty($encryptedKey) || empty($encryptedData)) {
        throw new Exception('Missing encrypted parameters.');
    }

    // 3. 解密请求数据 (建立会话)
    $crypto->decryptRequest($encryptedKey, $encryptedData);

    // 4. 业务逻辑
    $stmt = $db->query("SELECT * FROM license_announcements ORDER BY created_at DESC LIMIT 1");
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);

    $response = [];

    if ($announcement) {
        $response = [
            'code' => 200,
            'msg' => 'Success',
            'data' => [
                'title' => $announcement['title'],
                'content' => $announcement['content'],
                'created_at' => $announcement['created_at']
            ]
        ];
    } else {
        $response = ['code' => 404, 'msg' => 'No announcements found'];
    }

    // 5. 加密响应
    echo json_encode(['encrypted_response' => $crypto->encryptResponse($response)]);

} catch (Exception $e) {
    echo json_encode(['code' => 500, 'msg' => $e->getMessage()]);
}
