<?php

class Crypto {
    private $privateKey;
    private $aesKey;

    public function __construct() {
        $keyDir = __DIR__ . '/../../../config/keys/';
        $keyFile = $keyDir . 'private.pem';
        $secretFile = $keyDir . 'secret.php';

        if (!file_exists($keyFile)) {
            throw new Exception('Server Private Key not found.');
        }

        // 读取私钥密码
        $passphrase = '';
        if (file_exists($secretFile)) {
            $passphrase = require $secretFile;
        }

        // 加载私钥 (带密码)
        $privateKeyContent = file_get_contents($keyFile);
        $this->privateKey = openssl_pkey_get_private($privateKeyContent, $passphrase);

        if (!$this->privateKey) {
             throw new Exception('Failed to load Private Key. Check passphrase.');
        }
    }

    /**
     * 解密客户端请求 (双重加密支持)
     * @param string $encryptedKey RSA加密的AES密钥 (Base64)
     * @param string $encryptedData AES加密的数据 (Base64)
     * @return array 解密后的数据数组
     */
    public function decryptRequest($encryptedKey, $encryptedData) {
        // 1. 使用私钥解密 AES Key
        $aesKey = '';
        if (!openssl_private_decrypt(base64_decode($encryptedKey), $aesKey, $this->privateKey)) {
            throw new Exception('Failed to decrypt AES key.');
        }
        $this->aesKey = $aesKey;

        // 2. 解密第一层 AES
        $data = base64_decode($encryptedData);
        $ivLength = openssl_cipher_iv_length('AES-256-CBC');
        
        if (strlen($data) < $ivLength) {
             throw new Exception('Invalid encrypted data length.');
        }
        
        $iv = substr($data, 0, $ivLength);
        $cipherText = substr($data, $ivLength);
        
        $firstLayerDecrypted = openssl_decrypt($cipherText, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA, $iv);
        
        if ($firstLayerDecrypted === false) {
            throw new Exception('Failed to decrypt layer 1.');
        }

        // 3. 解密第二层 AES (Inner Layer)
        // 约定：内层加密使用相同的 Key，或者可以派生 Key。这里为了简化，假设使用相同的 Key 和 IV 机制
        // 第一层解密出来的数据应该是：Base64(IV2 + CipherText2) 或者是 Raw(IV2 + CipherText2)
        // 假设客户端做了两次标准的加密流程： Encrypt(Encrypt(Data))
        
        // 检查是否是双重加密数据
        // 尝试解析第二层
        $innerData = $firstLayerDecrypted;
        
        // 如果是 JSON 字符串，说明可能只加密了一层（兼容旧版客户端）
        // 但这里我们强制要求双重加密，或者尝试探测
        $json = json_decode($innerData, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            // 只有一层加密
            return $json; 
        }

        // 尝试解密第二层
        // 注意：内层数据通常是二进制数据或Base64字符串。如果客户端外层加密的是Base64字符串，这里需要解码。
        // 假设客户端逻辑：
        // Layer1 = AES(JsonData) -> Base64
        // Layer2 = AES(Layer1) -> Base64
        // 所以 $firstLayerDecrypted 是 Layer1 的 Base64 字符串
        
        $innerEncryptedData = base64_decode($innerData); 
        if ($innerEncryptedData === false) {
             // 也许不是 Base64，直接尝试作为二进制
             $innerEncryptedData = $innerData;
        }

        if (strlen($innerEncryptedData) < $ivLength) {
             // 只有一层加密且非 JSON？或者数据损坏
             throw new Exception('Invalid inner layer data.');
        }

        $iv2 = substr($innerEncryptedData, 0, $ivLength);
        $cipherText2 = substr($innerEncryptedData, $ivLength);
        
        $secondLayerDecrypted = openssl_decrypt($cipherText2, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA, $iv2);
        
        if ($secondLayerDecrypted === false) {
             throw new Exception('Failed to decrypt layer 2.');
        }

        return json_decode($secondLayerDecrypted, true);
    }

    /**
     * 加密响应数据
     * @param mixed $data 要返回的数据
     * @return string 加密后的 Base64 字符串
     */
    public function encryptResponse($data) {
        if (empty($this->aesKey)) {
            throw new Exception('AES Key not initialized.');
        }

        $json = json_encode($data);
        $ivLength = openssl_cipher_iv_length('AES-256-CBC');
        $iv = openssl_random_pseudo_bytes($ivLength);
        
        $cipherText = openssl_encrypt($json, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA, $iv);
        
        // 返回 Base64(IV + CipherText)
        return base64_encode($iv . $cipherText);
    }
}
