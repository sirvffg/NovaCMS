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
    $version = isset($requestData['version']) ? trim($requestData['version']) : null; // New: Get Version (Optional)
    
    if (empty($key)) {
        throw new Exception('License Key is required');
    }

    // Encrypt the incoming key to match database record
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
    } elseif ($license['status'] !== 'used') {
        $response = ['code' => 403, 'msg' => 'License Not Activated'];
        $logStatus = 'invalid';
    } else {
        // Verify verification_id and domain
        if ($license['verification_id'] === $verificationId) {
             // Domain check is optional, depending on strictness requirements
             // Ideally, we should also check if domain matches or is a subdomain
             $response = [
                'code' => 200, 
                'msg' => 'License Valid',
                'data' => [
                    'type' => $license['type'] ?? 'standard',
                    'expires_at' => $license['expires_at'] ?? null,
                    'activated_at' => $license['used_at']
                ]
            ];
            $logStatus = 'valid';
        } else {
            $response = ['code' => 401, 'msg' => 'License Verification Failed: Device Mismatch'];
            $logStatus = 'invalid';
        }
    }
    
    // Log Verification Attempt
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        // 尝试插入带版本号的记录（兼容新数据库结构）
        // 如果 system_version 列不存在，这里会抛出异常，然后降级到 catch 块执行旧插入语句
        $logStmt = $db->prepare("INSERT INTO license_verification_logs (license_key, verification_id, domain, ip_address, status, system_version) VALUES (?, ?, ?, ?, ?, ?)");
        $logStmt->execute([$key, $verificationId, $domain, $ip, $logStatus, $version]);
    } catch (Exception $logEx) {
        // Fallback for old schema (兼容旧数据库结构)
        try {
            $logStmt = $db->prepare("INSERT INTO license_verification_logs (license_key, verification_id, domain, ip_address, status) VALUES (?, ?, ?, ?, ?)");
            $logStmt->execute([$key, $verificationId, $domain, $ip, $logStatus]);
        } catch (Exception $e) {
            // 忽略日志写入错误，不影响主流程
        }
    }

    // 6. 加密响应
    echo json_encode(['encrypted_response' => $crypto->encryptResponse($response)]);

} catch (Exception $e) {
    echo json_encode(['code' => 500, 'msg' => $e->getMessage()]);
}
