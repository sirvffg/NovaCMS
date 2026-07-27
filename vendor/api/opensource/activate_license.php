<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../../../config/database.php';
require_once '../../../config/functions.php';
require_once 'Crypto.php';

$db = getDB();
$crypto = null;

try {
    // 1. 初始化加密类
    $crypto = new Crypto();

    // 2. 获取加密参数
    $encryptedKey = $_POST['encrypted_key'] ?? '';
    $encryptedData = $_POST['encrypted_data'] ?? '';

    if (empty($encryptedKey) || empty($encryptedData)) {
        throw new Exception('Missing encrypted parameters.');
    }

    // 3. 解密请求数据
    $requestData = $crypto->decryptRequest($encryptedKey, $encryptedData);
    
    // 4. 获取业务参数
    $key = trim($requestData['key'] ?? '');
    $verificationId = trim($requestData['verification_id'] ?? '');
    $domain = trim($requestData['domain'] ?? '');
    $contactEmail = trim($requestData['contact_email'] ?? '');
    
    if (empty($key)) {
        throw new Exception('License Key is required');
    }

    // Encrypt the incoming key to match database record
    // We use encryptLicenseKey() which uses AES-128-ECB (deterministic)
    $encryptedDbKey = encryptLicenseKey($key);

    // 5. 业务逻辑
    // Try to find by encrypted key first
    $stmt = $db->prepare("SELECT * FROM license_keys WHERE key_code = ?");
    $stmt->execute([$encryptedDbKey]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Fallback: If not found, try finding by plain key (for legacy keys support)
    if (!$license) {
        $stmt = $db->prepare("SELECT * FROM license_keys WHERE key_code = ?");
        $stmt->execute([$key]);
        $license = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $response = [];

    if (!$license) {
        $response = ['code' => 404, 'msg' => 'Invalid License Key'];
        $logStatus = 'invalid';
    } elseif ($license['status'] === 'used') {
        // Check if it's the same device/domain (Optional, but user just asked to store it)
        $response = [
            'code' => 409, 
            'msg' => 'License Key already used',
            'data' => [
                'used_at' => $license['used_at']
            ]
        ];
        $logStatus = 'invalid';
    } else {
        // Activate the key and store verification info
        $updateStmt = $db->prepare("UPDATE license_keys SET status = 'used', used_at = NOW(), verification_id = ?, domain = ?, contact_email = ? WHERE id = ?");
        if ($updateStmt->execute([$verificationId, $domain, $contactEmail, $license['id']])) {
            $response = ['code' => 200, 'msg' => 'Activation Successful'];
            $logStatus = 'valid';
        } else {
            $response = ['code' => 500, 'msg' => 'Activation Failed'];
            $logStatus = 'invalid';
        }
    }

    // Log Activation Attempt
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $logStmt = $db->prepare("INSERT INTO license_verification_logs (license_key, verification_id, domain, ip_address, status) VALUES (?, ?, ?, ?, ?)");
        $logStmt->execute([$key, $verificationId, $domain, $ip, $logStatus]);
    } catch (Exception $logEx) {
    }

    // 6. 加密响应
    echo json_encode(['encrypted_response' => $crypto->encryptResponse($response)]);

} catch (Exception $e) {
    // 错误处理也要加密返回（如果可能），或者返回明文错误以便调试？
    // 通常为了安全，错误也应该加密，但如果握手失败（如解密失败），则无法加密返回。
    // 这里如果 $crypto 初始化成功且有 aesKey，则尝试加密返回，否则返回明文错误。
    
    $errorResponse = ['code' => 500, 'msg' => $e->getMessage()];
    
    // 简单判断：如果解密阶段就失败了，可能还没有 aesKey
    // 但客户端期待加密响应。为了兼容，如果无法加密，返回特定格式的明文错误供客户端识别
    // 或者在生产环境中，只返回 "Handshake Failed"
    
    echo json_encode(['code' => 500, 'msg' => $e->getMessage()]);
}
