<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

require_once __DIR__ . '/../../../config/database.php';

class BehaviorAuth {
    private $db;
    private $sessionTTL = 45;
    private $tokenSecret = 'B3havi0r_Auth_S3cr3t_K3y!@#';

    const SHAPE_CIRCLE = 'circle';
    const SHAPE_SQUARE = 'square';
    const SHAPE_STAR   = 'star';
    const SHAPE_TRIANGLE = 'triangle';

    private $shapePool = [self::SHAPE_CIRCLE, self::SHAPE_SQUARE, self::SHAPE_STAR, self::SHAPE_TRIANGLE];
    private $imageApiUrl = 'https://wallpaper.lygalaxy.cn/api/public/random_redirect_300x150.php';

    public function __construct() {
        $this->db = getDB();
        $this->ensureSchema();
        $this->cleanup();
    }

    private function ensureSchema() {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `captcha_sessions` (
                `token` varchar(128) NOT NULL COMMENT '验证令牌',
                `salt` varchar(32) NOT NULL COMMENT 'POW盐值',
                `difficulty` tinyint NOT NULL DEFAULT 5 COMMENT 'POW难度',
                `status` enum('init','pow_verified','completed') NOT NULL DEFAULT 'init' COMMENT '会话状态',
                `valid_x` smallint NOT NULL DEFAULT 0 COMMENT '正确X坐标',
                `valid_y` smallint NOT NULL DEFAULT 0 COMMENT '正确Y坐标',
                `valid_shape` varchar(20) NOT NULL DEFAULT '' COMMENT '正确形状',
                `segment_key` varchar(64) NOT NULL DEFAULT '' COMMENT '分段加密密钥',
                `ip` varchar(45) NOT NULL DEFAULT '' COMMENT '请求IP',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
                `expire_at` timestamp NOT NULL COMMENT '过期时间',
                PRIMARY KEY (`token`),
                KEY `idx_expire` (`expire_at`),
                KEY `idx_ip` (`ip`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `captcha_tokens` (
                `token` varchar(128) NOT NULL COMMENT '业务令牌',
                `ip` varchar(45) NOT NULL DEFAULT '' COMMENT '验证时IP',
                `verified_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '验证通过时间',
                `expire_at` timestamp NOT NULL COMMENT '过期时间',
                PRIMARY KEY (`token`),
                KEY `idx_expire` (`expire_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->db->exec("CREATE TABLE IF NOT EXISTS `captcha_segments` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `token` varchar(128) NOT NULL COMMENT '验证令牌',
                `seq` varchar(32) NOT NULL DEFAULT '' COMMENT '序列号',
                `seg_index` tinyint NOT NULL DEFAULT 0 COMMENT '分段索引',
                `seg_type` enum('req','res') NOT NULL DEFAULT 'req' COMMENT '分段类型',
                `data` longtext NOT NULL COMMENT '分段数据',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
                `expire_at` timestamp NOT NULL COMMENT '过期时间',
                PRIMARY KEY (`id`),
                KEY `idx_lookup` (`token`, `seq`, `seg_index`, `seg_type`),
                KEY `idx_expire` (`expire_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $col = $this->db->query("SHOW COLUMNS FROM `captcha_sessions` LIKE 'segment_key'")->fetch();
            if (!$col) {
                $this->db->exec("ALTER TABLE `captcha_sessions` ADD COLUMN `segment_key` varchar(64) NOT NULL DEFAULT '' COMMENT '分段加密密钥' AFTER `valid_shape`");
            }
        } catch (Exception $e) {}
    }

    private function cleanup() {
        try {
            $this->db->exec("DELETE FROM captcha_segments WHERE expire_at < NOW()");
            $this->db->exec("DELETE FROM captcha_sessions WHERE expire_at < NOW()");
            $this->db->exec("DELETE FROM captcha_tokens WHERE expire_at < NOW()");
        } catch (Exception $e) {}
    }

    public function initAuth() {
        $token = $this->generateSecureToken();
        $salt = bin2hex(random_bytes(8));
        $difficulty = $this->getDynamicDifficulty();
        $segmentKey = bin2hex(random_bytes(16));

        $stmt = $this->db->prepare("INSERT INTO captcha_sessions (token, salt, difficulty, status, segment_key, ip, expire_at) VALUES (?, ?, ?, 'init', ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))");
        $stmt->execute([$token, $salt, $difficulty, $segmentKey, $_SERVER['REMOTE_ADDR'] ?? '', $this->sessionTTL]);

        return $this->jsonResponse([
            'code' => 200,
            'token' => $token,
            'salt' => $salt,
            'difficulty' => $difficulty,
            'expire_time' => $this->sessionTTL,
            'segment_key' => $segmentKey
        ]);
    }

    public function verifyPOW($token, $nonce) {
        if (!$this->validateSecureToken($token)) {
            return ['code' => 403, 'msg' => '不合法的令牌'];
        }

        $stmt = $this->db->prepare("SELECT salt, difficulty, status FROM captcha_sessions WHERE token = ? AND expire_at > NOW()");
        $stmt->execute([$token]);
        $session = $stmt->fetch();

        if (!$session) return ['code' => 403, 'msg' => '令牌已失效'];

        $hash = hash('sha256', $session['salt'] . $nonce);
        $prefix = str_repeat('0', (int)$session['difficulty']);

        if (strpos($hash, $prefix) === 0) {
            $stmt = $this->db->prepare("UPDATE captcha_sessions SET status = 'pow_verified' WHERE token = ?");
            $stmt->execute([$token]);
            return ['code' => 200, 'msg' => 'POW verified'];
        }

        $this->deleteSession($token);
        return ['code' => 400, 'msg' => 'POW 验证失败'];
    }

    public function getPuzzle($token) {
        if (!$this->validateSecureToken($token)) {
            return ['code' => 403, 'msg' => 'Invalid token format'];
        }

        $stmt = $this->db->prepare("SELECT * FROM captcha_sessions WHERE token = ? AND status = 'pow_verified' AND expire_at > NOW()");
        $stmt->execute([$token]);
        if (!$stmt->fetch()) {
            return ['code' => 403, 'msg' => 'Invalid access'];
        }

        $width = 300; $height = 150;
        $blockSize = 44;

        $imageData = $this->fetchImageFromApi();
        if (!$imageData) return ['code' => 500, 'msg' => 'Image fetch failed'];

        $srcImg = imagecreatefromstring($imageData);
        if (!$srcImg) return ['code' => 500, 'msg' => 'Invalid image'];

        $resizedSrc = imagecreatetruecolor($width, $height);
        imagecopyresampled($resizedSrc, $srcImg, 0, 0, 0, 0, $width, $height, imagesx($srcImg), imagesy($srcImg));
        imagedestroy($srcImg);

        $bgImg = imagecreatetruecolor($width, $height);
        imagecopy($bgImg, $resizedSrc, 0, 0, 0, 0, $width, $height);

        $validX = rand($blockSize + 20, $width - $blockSize - 20);
        $validY = rand(10, $height - $blockSize - 10);
        $validShape = $this->shapePool[array_rand($this->shapePool)];

        $interferenceShape = $validShape;
        do {
            $interferenceX = rand($blockSize + 20, $width - $blockSize - 20);
        } while (abs($interferenceX - $validX) < $blockSize + 20);

        $stmt = $this->db->prepare("UPDATE captcha_sessions SET valid_x = ?, valid_y = ?, valid_shape = ? WHERE token = ?");
        $stmt->execute([$validX, $validY, $validShape, $token]);

        $this->drawPuzzleHole($bgImg, $validX, $validY, $blockSize, $validShape);
        $this->drawPuzzleHole($bgImg, $interferenceX, $interferenceY ?? $validY, $blockSize, $interferenceShape);
        $this->addNoise($bgImg, 300);
        $this->addInterferenceLines($bgImg, 6);

        $blockImg = $this->createPuzzleBlock($resizedSrc, $validX, $validY, $blockSize, $validShape);
        $this->addNoise($blockImg, 40);
        imagedestroy($resizedSrc);

        $gridCols   = 6;
        $gridRows   = 3;
        $pieceW     = intval($width / $gridCols);
        $pieceH     = intval($height / $gridRows);
        $totalPieces = $gridCols * $gridRows;

        $perm = range(0, $totalPieces - 1);
        shuffle($perm);

        $shuffledImg = imagecreatetruecolor($width, $height);
        for ($i = 0; $i < $totalPieces; $i++) {
            $origIndex = $perm[$i];
            $origCol = $origIndex % $gridCols;
            $origRow = intdiv($origIndex, $gridCols);
            $newCol  = $i % $gridCols;
            $newRow  = intdiv($i, $gridCols);
            imagecopy($shuffledImg, $bgImg,
                $newCol * $pieceW, $newRow * $pieceH,
                $origCol * $pieceW, $origRow * $pieceH,
                $pieceW, $pieceH
            );
        }
        imagedestroy($bgImg);

        $permEncrypted = $this->encryptPermutation($perm, $token);

        ob_start(); imagepng($shuffledImg); $bgBase64 = base64_encode(ob_get_clean()); imagedestroy($shuffledImg);
        ob_start(); imagepng($blockImg); $blockBase64 = base64_encode(ob_get_clean()); imagedestroy($blockImg);

        return [
            'code'           => 200,
            'bg_base64'      => $bgBase64,
            'block_base64'   => $blockBase64,
            'block_y'        => $validY,
            'perm_encrypted' => $permEncrypted,
            'grid_cols'      => $gridCols,
            'grid_rows'      => $gridRows,
            'piece_w'        => $pieceW,
            'piece_h'        => $pieceH
        ];
    }

    public function verifyFinal($token, $offsetX, $encryptedBehaviorData) {
        if (!$this->validateSecureToken($token)) {
            return ['code' => 403, 'msg' => 'Invalid token format'];
        }

        $stmt = $this->db->prepare("SELECT valid_x, valid_y, valid_shape FROM captcha_sessions WHERE token = ? AND expire_at > NOW()");
        $stmt->execute([$token]);
        $session = $stmt->fetch();

        if (!$session) return ['code' => 403, 'msg' => '令牌已失效'];

        $payload = $this->decryptBehaviorData($encryptedBehaviorData, $token);
        if (!$payload) return ['code' => 400, 'msg' => '不合法的请求格式'];

        $behaviorData = $payload['behavior'] ?? [];
        $envData = $payload['env'] ?? [];

        if (abs((int)$offsetX - (int)$session['valid_x']) > 5) {
            $this->deleteSession($token);
            return ['code' => 400, 'msg' => '请拖动至缺口位置'];
        }
        if (!$this->analyzeBehavior($behaviorData)) {
            $this->deleteSession($token);
            return ['code' => 400, 'msg' => '行为验证失败，请重试'];
        }

        if (!$this->analyzeEnvironment($envData)) {
            $this->deleteSession($token);
            return ['code' => 400, 'msg' => '环境校验失败'];
        }

        $this->deleteSession($token);
        $bizToken = $this->generateBizToken();

        $stmt = $this->db->prepare("INSERT INTO captcha_tokens (token, ip, expire_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 3600 SECOND))");
        $stmt->execute([$bizToken, $_SERVER['REMOTE_ADDR'] ?? '']);
        setcookie("auth_token", $bizToken, time() + 3600, "/", "", false, true);

        return ['code' => 200, 'msg' => 'Success', 'token' => $bizToken];
    }

    public function sendSegment($token, $seq, $index, $data) {
        if (!$this->validateSecureToken($token)) {
            return $this->jsonResponse(['code' => 403, 'msg' => '令牌不合法'], 403);
        }

        $stmt = $this->db->prepare("SELECT token FROM captcha_sessions WHERE token = ? AND expire_at > NOW()");
        $stmt->execute([$token]);
        if (!$stmt->fetch()) {
            return $this->jsonResponse(['code' => 403, 'msg' => '令牌已失效'], 403);
        }

        $stmt = $this->db->prepare("INSERT INTO captcha_segments (token, seq, seg_index, seg_type, data, expire_at) VALUES (?, ?, ?, 'req', ?, DATE_ADD(NOW(), INTERVAL 30 SECOND)) ON DUPLICATE KEY UPDATE data = VALUES(data), expire_at = VALUES(expire_at)");
        $stmt->execute([$token, $seq, $index, $data]);

        return $this->jsonResponse(['code' => 200, 'msg' => 'Segment received']);
    }

    public function execute($token, $seq, $action) {
        if (!$this->validateSecureToken($token)) {
            return $this->jsonResponse(['code' => 403, 'msg' => 'Invalid token format'], 403);
        }

        $stmt = $this->db->prepare("SELECT * FROM captcha_sessions WHERE token = ? AND expire_at > NOW()");
        $stmt->execute([$token]);
        $session = $stmt->fetch();
        if (!$session) {
            return $this->jsonResponse(['code' => 403, 'msg' => '令牌已失效'], 403);
        }

        $segmentKey = $session['segment_key'];

        $encryptedReq = '';
        for ($i = 0; $i < 5; $i++) {
            $stmt = $this->db->prepare("SELECT data FROM captcha_segments WHERE token = ? AND seq = ? AND seg_index = ? AND seg_type = 'req' AND expire_at > NOW()");
            $stmt->execute([$token, $seq, $i]);
            $row = $stmt->fetch();
            if (!$row) {
                return $this->jsonResponse(['code' => 400, 'msg' => 'Missing request segment'], 400);
            }
            $encryptedReq .= $row['data'];
        }

        $stmt = $this->db->prepare("DELETE FROM captcha_segments WHERE token = ? AND seq = ? AND seg_type = 'req'");
        $stmt->execute([$token, $seq]);

        $reqJson = $this->segmentDecrypt($encryptedReq, $segmentKey);
        if ($reqJson === null || $reqJson === false) {
            return $this->jsonResponse(['code' => 400, 'msg' => 'Request decryption failed'], 400);
        }
        $reqData = json_decode($reqJson, true);
        if ($reqData === null) {
            return $this->jsonResponse(['code' => 400, 'msg' => 'Invalid request format'], 400);
        }

        $result = [];
        switch ($action) {
            case 'verify-pow':
                $result = $this->verifyPOW($token, $reqData['nonce'] ?? '');
                break;
            case 'get-puzzle':
                $result = $this->getPuzzle($token);
                break;
            case 'verify-final':
                $result = $this->verifyFinal($token, $reqData['offset_x'] ?? 0, $reqData['behavior_data'] ?? '');
                break;
            default:
                $result = ['code' => 404, 'msg' => 'Invalid action'];
        }

        $resJson = json_encode($result, JSON_UNESCAPED_UNICODE);
        $encryptedRes = $this->segmentEncrypt($resJson, $segmentKey);
        $len = strlen($encryptedRes);
        $chunkSize = ceil($len / 5);

        $stmt = $this->db->prepare("INSERT INTO captcha_segments (token, seq, seg_index, seg_type, data, expire_at) VALUES (?, ?, ?, 'res', ?, DATE_ADD(NOW(), INTERVAL 30 SECOND))");
        for ($i = 0; $i < 5; $i++) {
            $chunk = substr($encryptedRes, $i * $chunkSize, $chunkSize);
            $stmt->execute([$token, $seq, $i, $chunk]);
        }

        return $this->jsonResponse(['code' => 200, 'msg' => 'Executed']);
    }

    public function fetchSegment($token, $seq, $index) {
        $stmt = $this->db->prepare("SELECT data FROM captcha_segments WHERE token = ? AND seq = ? AND seg_index = ? AND seg_type = 'res' AND expire_at > NOW()");
        $stmt->execute([$token, $seq, $index]);
        $row = $stmt->fetch();

        if (!$row) {
            return $this->jsonResponse(['code' => 404, 'msg' => 'Segment not found'], 404);
        }

        $stmt = $this->db->prepare("DELETE FROM captcha_segments WHERE token = ? AND seq = ? AND seg_index = ? AND seg_type = 'res'");
        $stmt->execute([$token, $seq, $index]);

        return $this->jsonResponse(['code' => 200, 'data' => $row['data']]);
    }

    public function verifyBizToken($bizToken) {
        if (empty($bizToken)) return false;

        $stmt = $this->db->prepare("SELECT token FROM captcha_tokens WHERE token = ? AND expire_at > NOW()");
        $stmt->execute([$bizToken]);
        $row = $stmt->fetch();

        if (!$row) return false;

        $stmt = $this->db->prepare("DELETE FROM captcha_tokens WHERE token = ?");
        $stmt->execute([$bizToken]);

        return true;
    }

    private function deleteSession($token) {
        $stmt = $this->db->prepare("DELETE FROM captcha_sessions WHERE token = ?");
        $stmt->execute([$token]);
    }

    private function fetchImageFromApi() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->imageApiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode == 200 && $data) return $data;

        $fallbackImg = imagecreatetruecolor(300, 150);
        $bgColor = imagecolorallocate($fallbackImg, rand(100,200), rand(100,200), rand(100,200));
        imagefill($fallbackImg, 0, 0, $bgColor);
        ob_start(); imagepng($fallbackImg); $data = ob_get_clean(); imagedestroy($fallbackImg);
        return $data;
    }

    private function getDynamicDifficulty() {
        return 3;
    }

    private function analyzeBehavior($data) {
        $duration = $data['duration'] ?? 0;
        $pauseCount = $data['pause_count'] ?? 0;
        $speedVariance = $data['speed_variance'] ?? 0;
        if ($duration < 100) return false;
        if ($pauseCount === 0 && $duration < 300) return false;
        if ($speedVariance < 0.05 && $pauseCount === 0) return false;
        return true;
    }

    private function analyzeEnvironment($env) {
        if (empty($env)) return false;

        if (!empty($env['webdriver']) || !empty($env['auto_phantom']) || !empty($env['auto_nightmare']) || !empty($env['auto_cdc']) || !empty($env['auto_selenium']) || !empty($env['auto_puppeteer'])) {
            return false;
        }

        if (!empty($env['proto_tampered']) || !empty($env['perm_inconsistency'])) {
            return false;
        }

        if (isset($env['win_w']) && $env['win_w'] <= 0) return false;
        if (isset($env['scr_w']) && $env['scr_w'] <= 0) return false;
        if (isset($env['scr_ah']) && $env['scr_ah'] > $env['scr_h']) return false;

        if (isset($env['color_depth']) && !in_array($env['color_depth'], [24, 32, 48])) {
            return false;
        }

        if (isset($env['cpu_cores']) && $env['cpu_cores'] <= 0) return false;

        if (isset($env['canvas_hash']) && (int)$env['canvas_hash'] === 0) return false;

        if (isset($env['webgl_data']) && is_array($env['webgl_data'])) {
            if (empty($env['webgl_data']['vendor']) || empty($env['webgl_data']['renderer'])) {
                return false;
            }
        }

        if (isset($env['ls']) && $env['ls'] === false) return false;
        if (isset($env['idb']) && $env['idb'] === false) return false;

        if (isset($env['logic_check']) && (int)$env['logic_check'] >= 2) {
            return false;
        }
        return true;
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
                    $srcX = $x + $i; $srcY = $y + $j;
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
                imagefilledellipse($canvas, $x + $size/2, $y + $size/2, $size, $size, $color);
                break;
            case self::SHAPE_SQUARE:
                imagefilledrectangle($canvas, $x + 4, $y + 4, $x + $size - 4, $y + $size - 4, $color);
                break;
            case self::SHAPE_TRIANGLE:
                imagefilledpolygon($canvas, [$x + $size/2, $y + 2, $x + 2, $y + $size - 2, $x + $size - 2, $y + $size - 2], $color);
                break;
            case self::SHAPE_STAR:
                $cx = $x + $size/2; $cy = $y + $size/2; $pts = [];
                for ($i = 0; $i < 5; $i++) {
                    $a1 = deg2rad(90 + 72 * $i);
                    $pts[] = $cx + ($size/2 - 2) * cos($a1);
                    $pts[] = $cy - ($size/2 - 2) * sin($a1);
                    $a2 = deg2rad(126 + 72 * $i);
                    $pts[] = $cx + ($size/4) * cos($a2);
                    $pts[] = $cy - ($size/4) * sin($a2);
                }
                imagefilledpolygon($canvas, $pts, $color);
                break;
        }
    }

    private function addNoise(&$img, $count = 200) {
        $width = imagesx($img); $height = imagesy($img);
        for ($i = 0; $i < $count; $i++) {
            $x = rand(0, $width - 1); $y = rand(0, $height - 1);
            $color = imagecolorallocatealpha($img, rand(0, 255), rand(0, 255), rand(0, 255), rand(0, 80));
            imagesetpixel($img, $x, $y, $color);
        }
    }

    private function addInterferenceLines(&$img, $count = 5) {
        $width = imagesx($img); $height = imagesy($img);
        for ($i = 0; $i < $count; $i++) {
            $x1 = rand(0, $width - 1); $y1 = rand(0, $height - 1);
            $x2 = rand(0, $width - 1); $y2 = rand(0, $height - 1);
            $color = imagecolorallocatealpha($img, rand(0, 255), rand(0, 255), rand(0, 255), rand(40, 80));
            imageline($img, $x1, $y1, $x2, $y2, $color);
        }
    }

    private function encryptPermutation($perm, $token) {
        $key = substr(hash('sha256', $token), 0, 32);
        $iv  = random_bytes(16);
        $data = json_encode($perm);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    private function decryptBehaviorData($encryptedData, $token) {
        $key = hash('sha256', $token, true);
        $ivHex = substr($encryptedData, 0, 32);
        $ciphertextBase64 = substr($encryptedData, 32);
        $iv = hex2bin($ivHex);
        $ciphertext = base64_decode($ciphertextBase64);
        if (!$iv || !$ciphertext) return null;
        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return json_decode($decrypted, true);
    }

    private function getEncryptionKey($keyStr) {
        return hash('sha256', $keyStr, true);
    }

    private function segmentEncrypt($data, $keyStr) {
        $key = $this->getEncryptionKey($keyStr);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return bin2hex($iv) . base64_encode($encrypted);
    }

    private function segmentDecrypt($data, $keyStr) {
        $key = $this->getEncryptionKey($keyStr);
        $ivHex = substr($data, 0, 32);
        $ciphertextBase64 = substr($data, 32);
        $iv = hex2bin($ivHex);
        $ciphertext = base64_decode($ciphertextBase64);
        if (!$iv || !$ciphertext) return null;
        return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }

    private function generateSecureToken($length = 16) {
        $raw = random_bytes($length);
        $sig = hash_hmac('sha256', $raw, $this->tokenSecret, true);
        return rtrim(strtr(base64_encode($raw . $sig), '+/', '-_'), '=');
    }

    private function validateSecureToken($token) {
        $remainder = strlen($token) % 4;
        if ($remainder !== 0) { $token .= str_repeat('=', 4 - $remainder); }
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
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') exit;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE); exit;
    }
}

//w
/**
 * 仅当此文件被直接访问时，才执行 API 请求分发。
 *
 * login.php 会引入此文件来使用 BehaviorAuth 类。
 * 因此，当本文件被其他页面引入时不能执行 API 分发，
 * 否则登录页面的内容会被 JSON 响应覆盖。
 */
function novaCaptchaIsDirectRequest(): bool
{
    $scriptFilename = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($scriptFilename === '') {
        return false;
    }

    $currentPath = realpath(__FILE__);
    $requestedPath = realpath($scriptFilename);

    if ($currentPath !== false && $requestedPath !== false) {
        $currentPath = str_replace('\\', '/', $currentPath);
        $requestedPath = str_replace('\\', '/', $requestedPath);
        return strcasecmp($currentPath, $requestedPath) === 0;
    }

    // 针对 Windows 或 Apache 路径格式异常情况的备用判断。
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return strcasecmp(basename(__FILE__), basename($scriptFilename)) === 0
        && str_ends_with(strtolower($scriptName), '/vendor/public/captcha/authapi.php');
}

if (novaCaptchaIsDirectRequest()) {
    try {
        $auth = new BehaviorAuth();
        $action = (string)($_GET['action'] ?? '');

        switch ($action) {
            case 'init':
                $auth->initAuth();
                break;

            case 'send-segment':
                $data = json_decode(file_get_contents('php://input'), true);
                if (!is_array($data)) {
                    $data = [];
                }

                $auth->sendSegment(
                    (string)($data['token'] ?? ''),
                    (string)($data['seq'] ?? ''),
                    (int)($data['index'] ?? 0),
                    (string)($data['data'] ?? '')
                );
                break;

            case 'execute':
                $data = json_decode(file_get_contents('php://input'), true);
                if (!is_array($data)) {
                    $data = [];
                }

                $auth->execute(
                    (string)($data['token'] ?? ''),
                    (string)($data['seq'] ?? ''),
                    (string)($data['action'] ?? '')
                );
                break;

            case 'fetch-segment':
                $auth->fetchSegment(
                    (string)($_GET['token'] ?? ''),
                    (string)($_GET['seq'] ?? ''),
                    (int)($_GET['index'] ?? 0)
                );
                break;

            case 'verify-token':
                $data = json_decode(file_get_contents('php://input'), true);
                if (!is_array($data)) {
                    $data = [];
                }

                $valid = $auth->verifyBizToken((string)($data['token'] ?? ''));
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode(['valid' => $valid], JSON_UNESCAPED_UNICODE);
                exit;

            default:
                http_response_code(404);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode([
                    'code' => 404,
                    'msg' => 'Invalid action',
                    'received_action' => $action
                ], JSON_UNESCAPED_UNICODE);
                exit;
        }
    } catch (Throwable $error) {
        error_log('[NovaCMS Captcha] ' . $error);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        echo json_encode([
            'code' => 500,
            'msg' => $isLocal
                ? get_class($error) . ': ' . $error->getMessage() . ' at line ' . $error->getLine()
                : 'Captcha service error'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}