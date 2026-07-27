<?php
/**
 * 行为认证系统 PHP 后端核心模块
 * 适配 MySQL + PDO，替代原 Redis 方案
 */

require_once __DIR__ . '/../../../config/database.php';

class BehaviorAuth {
    private $db;
    private $sessionTTL = 45;
    private $bizTokenTTL = 3600;
    private $tokenSecret = 'your_secret_key_change_me';
    private $positionTolerance = 25;

    const SHAPE_CIRCLE = 'circle';
    const SHAPE_SQUARE = 'square';
    const SHAPE_STAR   = 'star';
    const SHAPE_TRIANGLE = 'triangle';

    private $shapePool = [self::SHAPE_CIRCLE, self::SHAPE_SQUARE, self::SHAPE_STAR, self::SHAPE_TRIANGLE];
    private $imageApiUrl = 'https://picsum.photos/300/150';

    // 拼图参数
    private $puzzleWidth = 300;
    private $puzzleHeight = 150;
    private $blockSize = 44;

    // 默认 POW 难度
    private $defaultDifficulty = 2;

    // CORS 允许来源
    private $corsOrigin = '*';

    public function __construct() {
        $this->db = getDB();

        // 尝试加载自定义配置
        $configFile = __DIR__ . '/config.php';
        if (file_exists($configFile)) {
            $cfg = include $configFile;
            if (is_array($cfg)) {
                foreach (['tokenSecret', 'sessionTTL', 'bizTokenTTL', 'positionTolerance',
                          'imageApiUrl', 'defaultDifficulty', 'puzzleWidth', 'puzzleHeight',
                          'blockSize', 'corsOrigin'] as $key) {
                    if (isset($cfg[$key])) $this->$key = $cfg[$key];
                }
            }
        }

        // 自动清理过期记录
        $this->cleanup();
    }

    public function initAuth() {
        $token = $this->generateSecureToken();
        $salt = bin2hex(random_bytes(8));
        $difficulty = $this->defaultDifficulty;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $stmt = $this->db->prepare("
            INSERT INTO captcha_sessions (token, salt, difficulty, status, ip, expire_at)
            VALUES (:token, :salt, :difficulty, 'init', :ip, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
        ");
        $stmt->execute([
            ':token' => $token,
            ':salt' => $salt,
            ':difficulty' => $difficulty,
            ':ip' => $ip,
            ':ttl' => $this->sessionTTL,
        ]);

        return $this->jsonResponse([
            'code' => 200, 'token' => $token, 'salt' => $salt,
            'difficulty' => $difficulty, 'expire_time' => $this->sessionTTL
        ]);
    }

    public function verifyPOW($token, $nonce) {
        if (!$this->validateSecureToken($token)) {
            return $this->jsonResponse(['code' => 403, 'msg' => '不合法的令牌'], 403);
        }

        $session = $this->getSession($token);
        if (!$session || $session['status'] !== 'init') {
            return $this->jsonResponse(['code' => 403, 'msg' => '令牌已失效'], 403);
        }

        $hash = hash('sha256', $session['salt'] . $nonce);
        $prefix = str_repeat('0', (int)$session['difficulty']);

        if (strpos($hash, $prefix) === 0) {
            $this->updateSessionStatus($token, 'pow_verified');
            return $this->jsonResponse(['code' => 200, 'msg' => 'POW verified']);
        }

        $this->deleteSession($token);
        return $this->jsonResponse(['code' => 400, 'msg' => 'POW验证失败'], 400);
    }

    public function getPuzzle($token) {
        if (!$this->validateSecureToken($token)) {
            return $this->jsonResponse(['code' => 403, 'msg' => 'Invalid token format'], 403);
        }

        $session = $this->getSession($token);
        if (!$session || $session['status'] !== 'pow_verified') {
            return $this->jsonResponse(['code' => 403, 'msg' => 'Invalid access'], 403);
        }

        $width = $this->puzzleWidth;
        $height = $this->puzzleHeight;
        $blockSize = $this->blockSize;

        $imageData = $this->fetchImageFromApi();
        if (!$imageData) return $this->jsonResponse(['code' => 500, 'msg' => 'Image fetch failed'], 500);

        $srcImg = imagecreatefromstring($imageData);
        if (!$srcImg) return $this->jsonResponse(['code' => 500, 'msg' => 'Invalid image'], 500);

        $resizedSrc = imagecreatetruecolor($width, $height);
        imagecopyresampled($resizedSrc, $srcImg, 0, 0, 0, 0, $width, $height, imagesx($srcImg), imagesy($srcImg));
        imagedestroy($srcImg);

        $bgImg = imagecreatetruecolor($width, $height);
        imagecopy($bgImg, $resizedSrc, 0, 0, 0, 0, $width, $height);

        $validX = rand($blockSize + 20, $width - $blockSize - 20);
        $validY = rand(10, $height - $blockSize - 10);
        $validShape = $this->shapePool[array_rand($this->shapePool)];

        // 干扰项：同形状同Y坐标
        $interferenceShape = $validShape;
        $interferenceY = $validY;
        do {
            $interferenceX = rand($blockSize + 20, $width - $blockSize - 20);
        } while (abs($interferenceX - $validX) < $blockSize + 20);

        // 保存正确坐标到数据库
        $stmt = $this->db->prepare("
            UPDATE captcha_sessions
            SET valid_x = :x, valid_y = :y, valid_shape = :shape
            WHERE token = :token
        ");
        $stmt->execute([':x' => $validX, ':y' => $validY, ':shape' => $validShape, ':token' => $token]);

        $this->drawPuzzleHole($bgImg, $validX, $validY, $blockSize, $validShape);
        $this->drawPuzzleHole($bgImg, $interferenceX, $interferenceY, $blockSize, $interferenceShape);

        // 噪点 + 干扰线
        $this->addNoise($bgImg, 300);
        $this->addInterferenceLines($bgImg, 6);

        // 生成拼图块
        $blockImg = $this->createPuzzleBlock($resizedSrc, $validX, $validY, $blockSize, $validShape);
        $this->addNoise($blockImg, 40);
        imagedestroy($resizedSrc);

        ob_start(); imagepng($bgImg); $bgBase64 = base64_encode(ob_get_clean()); imagedestroy($bgImg);
        ob_start(); imagepng($blockImg); $blockBase64 = base64_encode(ob_get_clean()); imagedestroy($blockImg);

        return $this->jsonResponse([
            'code' => 200, 'bg_base64' => $bgBase64, 'block_base64' => $blockBase64, 'block_y' => $validY
        ]);
    }

    public function verifyFinal($token, $offsetX, $encryptedBehaviorData) {
        if (!$this->validateSecureToken($token)) {
            return $this->jsonResponse(['code' => 403, 'msg' => 'Invalid token format'], 403);
        }

        $session = $this->getSession($token);
        if (!$session) {
            return $this->jsonResponse(['code' => 403, 'msg' => '令牌已失效'], 403);
        }

        if ($session['status'] !== 'pow_verified') {
            return $this->jsonResponse(['code' => 400, 'msg' => '会话状态异常'], 400);
        }

        $behaviorData = $this->decryptBehaviorData($encryptedBehaviorData, $token);

        if (!$behaviorData) {
            $this->deleteSession($token);
            return $this->jsonResponse(['code' => 400, 'msg' => '不合法的请求格式', 'debug' => 'decrypt_failed'], 400);
        }

        $diff = abs((int)$offsetX - (int)$session['valid_x']);
        if ($diff > $this->positionTolerance) {
            $this->deleteSession($token);
            return $this->jsonResponse(['code' => 400, 'msg' => '请拖动至缺口位置', 'debug' => 'position_mismatch', 'offset_x' => (int)$offsetX, 'valid_x' => (int)$session['valid_x'], 'diff' => $diff, 'tolerance' => $this->positionTolerance], 400);
        }

        if (!$this->analyzeBehavior($behaviorData)) {
            $this->deleteSession($token);
            return $this->jsonResponse(['code' => 400, 'msg' => '验证失败，请重试', 'debug' => 'behavior_check_failed', 'behavior' => $behaviorData], 400);
        }

        // 验证通过
        $this->deleteSession($token);

        $bizToken = $this->generateBizToken();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $stmt = $this->db->prepare("
            INSERT INTO captcha_tokens (token, ip, expire_at)
            VALUES (:token, :ip, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
        ");
        $stmt->execute([':token' => $bizToken, ':ip' => $ip, ':ttl' => $this->bizTokenTTL]);

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
        setcookie("auth_token", $bizToken, time() + $this->bizTokenTTL, "/", "", $isHttps, true);
        return $this->jsonResponse(['code' => 200, 'msg' => 'Success', 'token' => $bizToken]);
    }

    /**
     * 校验业务令牌是否有效（供其他模块调用）
     */
    public function verifyBizToken($bizToken) {
        if (empty($bizToken)) return false;

        $stmt = $this->db->prepare("
            SELECT * FROM captcha_tokens
            WHERE token = :token AND expire_at > NOW()
        ");
        $stmt->execute([':token' => $bizToken]);
        $result = $stmt->fetch();

        if ($result) {
            // 一次性使用，验证后删除
            $this->db->prepare("DELETE FROM captcha_tokens WHERE token = :token")->execute([':token' => $bizToken]);
            return true;
        }
        return false;
    }

    // ==================== 数据库操作 ====================

    private function getSession($token) {
        $stmt = $this->db->prepare("
            SELECT * FROM captcha_sessions
            WHERE token = :token AND expire_at > NOW()
        ");
        $stmt->execute([':token' => $token]);
        return $stmt->fetch();
    }

    private function updateSessionStatus($token, $status) {
        $stmt = $this->db->prepare("
            UPDATE captcha_sessions SET status = :status WHERE token = :token
        ");
        $stmt->execute([':status' => $status, ':token' => $token]);
    }

    private function deleteSession($token) {
        $stmt = $this->db->prepare("DELETE FROM captcha_sessions WHERE token = :token");
        $stmt->execute([':token' => $token]);
    }

    private function cleanup() {
        // 清理过期会话和令牌
        $this->db->exec("DELETE FROM captcha_sessions WHERE expire_at <= NOW()");
        $this->db->exec("DELETE FROM captcha_tokens WHERE expire_at <= NOW()");
    }

    // ==================== 图片处理 ====================

    private function fetchImageFromApi() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->imageApiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; Captcha/1.0)');
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200 && $data) return $data;

        // 兜底：生成纯色图
        $fallbackImg = imagecreatetruecolor(300, 150);
        $bgColor = imagecolorallocate($fallbackImg, rand(100, 200), rand(100, 200), rand(100, 200));
        imagefill($fallbackImg, 0, 0, $bgColor);
        ob_start(); imagepng($fallbackImg); $data = ob_get_clean(); imagedestroy($fallbackImg);
        return $data;
    }

    private function drawPuzzleHole(&$img, $x, $y, $size, $shape) {
        $mask = imagecreatetruecolor($size, $size);
        imagesavealpha($mask, true);
        imagefill($mask, 0, 0, imagecolorallocatealpha($mask, 0, 0, 0, 127));
        $black = imagecolorallocatealpha($mask, 0, 0, 0, 0);
        $this->drawShapeOnCanvas($mask, 0, 0, $size, $shape, $black);
        imagecopy($img, $mask, $x, $y, 0, 0, $size, $size);
        imagedestroy($mask);

        $borderMask = imagecreatetruecolor($size, $size);
        imagesavealpha($borderMask, true);
        imagefill($borderMask, 0, 0, imagecolorallocatealpha($borderMask, 0, 0, 0, 127));
        $borderColor = imagecolorallocatealpha($borderMask, 255, 255, 255, 80);
        $this->drawShapeOnCanvas($borderMask, 0, 0, $size, $shape, $borderColor);
        imagecopy($img, $borderMask, $x, $y, 0, 0, $size, $size);
        imagedestroy($borderMask);
    }

    private function createPuzzleBlock(&$srcImg, $x, $y, $size, $shape) {
        $block = imagecreatetruecolor($size, $size);
        imagesavealpha($block, true);
        imagefill($block, 0, 0, imagecolorallocatealpha($block, 0, 0, 0, 127));

        $mask = imagecreatetruecolor($size, $size);
        imagesavealpha($mask, true);
        imagefill($mask, 0, 0, imagecolorallocatealpha($mask, 0, 0, 0, 127));
        $white = imagecolorallocate($mask, 255, 255, 255);
        $this->drawShapeOnCanvas($mask, 0, 0, $size, $shape, $white);

        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                $maskAlpha = (imagecolorat($mask, $i, $j) >> 24) & 0x7F;
                if ($maskAlpha < 64) {
                    $srcX = $x + $i;
                    $srcY = $y + $j;
                    if ($srcX < imagesx($srcImg) && $srcY < imagesy($srcImg)) {
                        $srcRgb = imagecolorat($srcImg, $srcX, $srcY);
                        $pixelColor = imagecolorallocatealpha($block, ($srcRgb >> 16) & 0xFF, ($srcRgb >> 8) & 0xFF, $srcRgb & 0xFF, ($srcRgb >> 24) & 0x7F);
                        imagesetpixel($block, $i, $j, $pixelColor);
                    }
                }
            }
        }
        imagedestroy($mask);
        return $block;
    }

    private function drawShapeOnCanvas(&$canvas, $x, $y, $size, $shape, $color) {
        switch ($shape) {
            case self::SHAPE_CIRCLE:
                imagefilledellipse($canvas, $x + $size / 2, $y + $size / 2, $size, $size, $color);
                break;
            case self::SHAPE_SQUARE:
                imagefilledrectangle($canvas, $x + 4, $y + 4, $x + $size - 4, $y + $size - 4, $color);
                break;
            case self::SHAPE_TRIANGLE:
                imagefilledpolygon($canvas, [$x + $size / 2, $y + 2, $x + 2, $y + $size - 2, $x + $size - 2, $y + $size - 2], $color);
                break;
            case self::SHAPE_STAR:
                $cx = $x + $size / 2;
                $cy = $y + $size / 2;
                $pts = [];
                for ($i = 0; $i < 5; $i++) {
                    $a1 = deg2rad(90 + 72 * $i);
                    $pts[] = $cx + ($size / 2 - 2) * cos($a1);
                    $pts[] = $cy - ($size / 2 - 2) * sin($a1);
                    $a2 = deg2rad(126 + 72 * $i);
                    $pts[] = $cx + ($size / 4) * cos($a2);
                    $pts[] = $cy - ($size / 4) * sin($a2);
                }
                imagefilledpolygon($canvas, $pts, $color);
                break;
        }
    }

    private function addNoise(&$img, $count = 200) {
        $width = imagesx($img);
        $height = imagesy($img);
        for ($i = 0; $i < $count; $i++) {
            $x = rand(0, $width - 1);
            $y = rand(0, $height - 1);
            $color = imagecolorallocatealpha($img, rand(0, 255), rand(0, 255), rand(0, 255), rand(0, 80));
            imagesetpixel($img, $x, $y, $color);
        }
    }

    private function addInterferenceLines(&$img, $count = 5) {
        $width = imagesx($img);
        $height = imagesy($img);
        for ($i = 0; $i < $count; $i++) {
            $x1 = rand(0, $width - 1);
            $y1 = rand(0, $height - 1);
            $x2 = rand(0, $width - 1);
            $y2 = rand(0, $height - 1);
            $color = imagecolorallocatealpha($img, rand(0, 255), rand(0, 255), rand(0, 255), rand(40, 80));
            imageline($img, $x1, $y1, $x2, $y2, $color);
        }
    }

    // ==================== 安全与加密 ====================

    private function analyzeBehavior($data) {
        $duration = $data['duration'] ?? 0;
        $pauseCount = $data['pause_count'] ?? 0;
        $totalPauseTime = $data['total_pause_time'] ?? 0;
        $speedVariance = $data['speed_variance'] ?? 0;

        if ($duration < 100) return false;
        if ($pauseCount === 0 && $duration < 300) return false;
        if ($speedVariance < 0.05 && $pauseCount === 0) return false;

        return true;
    }

    private function decryptBehaviorData($encryptedData, $token) {
        $key = substr(hash('sha256', $token), 0, 32);
        $data = base64_decode($encryptedData);
        if (!$data) return null;
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        return json_decode(openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv), true);
    }

    private function generateSecureToken($length = 16) {
        $raw = random_bytes($length);
        $sig = hash_hmac('sha256', $raw, $this->tokenSecret, true);
        return rtrim(strtr(base64_encode($raw . $sig), '+/', '-_'), '=');
    }

    private function validateSecureToken($token) {
        $remainder = strlen($token) % 4;
        if ($remainder !== 0) {
            $token .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) !== 48) return false;

        $raw = substr($decoded, 0, 16);
        $sig = substr($decoded, 16);
        $expectedSig = hash_hmac('sha256', $raw, $this->tokenSecret, true);

        return hash_equals($expectedSig, $sig);
    }

    private function generateBizToken() {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function jsonResponse($data, $code = 200) {
        http_response_code($code);
        header('Access-Control-Allow-Origin: ' . $this->corsOrigin);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') exit;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ==================== 路由分发 ====================
// 仅在直接访问本文件时执行路由（被 require 时不执行）
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {

// LY-009: 生产环境禁止显示错误，避免泄露服务器路径
ini_set('display_errors', '0');
error_reporting(0);

try {
    $auth = new BehaviorAuth();
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'init':
            $auth->initAuth();
            break;
        case 'verify-pow':
            $d = json_decode(file_get_contents('php://input'), true);
            $auth->verifyPOW($d['token'] ?? '', $d['nonce'] ?? '');
            break;
        case 'get-puzzle':
            $auth->getPuzzle($_GET['token'] ?? '');
            break;
        case 'verify-final':
            $d = json_decode(file_get_contents('php://input'), true);
            $auth->verifyFinal($d['token'] ?? '', $d['offset_x'] ?? 0, $d['behavior_data'] ?? '');
            break;
        case 'verify-token':
            // 供其他模块校验业务令牌
            $d = json_decode(file_get_contents('php://input'), true);
            $bizToken = $d['token'] ?? $_COOKIE['auth_token'] ?? '';
            $valid = $auth->verifyBizToken($bizToken);
            $auth->jsonResponse(['code' => $valid ? 200 : 403, 'valid' => $valid], $valid ? 200 : 403);
            break;
        default:
            $auth->jsonResponse(['code' => 404, 'msg' => 'Invalid action'], 404);
    }
} catch (Exception $e) {
    // 生产环境返回通用错误，不泄露路径信息
    $auth->jsonResponse(['code' => 500, 'msg' => '服务器内部错误'], 500);
}

} // end route guard
