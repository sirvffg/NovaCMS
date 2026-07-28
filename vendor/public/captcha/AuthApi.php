<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

class BehaviorAuth {
    private $redis;
    private $redisPrefix = 'auth:';
    private $sessionTTL = 45;
    private $tokenSecret = 'B3havi0r_Auth_S3cr3t_K3y!@#';

    const SHAPE_CIRCLE = 'circle';
    const SHAPE_SQUARE = 'square';
    const SHAPE_STAR   = 'star';
    const SHAPE_TRIANGLE = 'triangle';

    private $shapePool = [self::SHAPE_CIRCLE, self::SHAPE_SQUARE, self::SHAPE_STAR, self::SHAPE_TRIANGLE];
    private $imageApiUrl = 'https://picsum.photos/300/150';

    public function __construct() {
        $this->redis = new Redis();
        try {
            $this->redis->connect('127.0.0.1', 16379);
        } catch (Exception $e) {

        }
    }

    public function initAuth() {
        $token = $this->generateSecureToken();
        $salt = bin2hex(random_bytes(8));
        $difficulty = $this->getDynamicDifficulty();
        $segmentKey = bin2hex(random_bytes(16));

        $sessionData = [
            'salt' => $salt,
            'difficulty' => $difficulty,
            'status' => 'init',
            'valid_x' => 0,
            'valid_y' => 0,
            'valid_shape' => '',
            'segment_key' => $segmentKey
        ];
        $this->redis->hMSet($this->redisPrefix . $token, $sessionData);
        $this->redis->expire($this->redisPrefix . $token, $this->sessionTTL);
        $this->redis->incr('auth:pool:requests');

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
            return ['code' => 403, 'msg' => ' 不合法的令牌 '];
        }
        $key = $this->redisPrefix . $token;
        if (!$this->redis->exists($key)) return ['code' => 403, 'msg' => ' 令牌已失效 '];

        $session = $this->redis->hGetAll($key);
        $hash = hash('sha256', $session['salt'] . $nonce);
        $prefix = str_repeat('0', (int)$session['difficulty']);

        if (strpos($hash, $prefix) === 0) {
            $this->redis->hSet($key, 'status', 'pow_verified');
            return ['code' => 200, 'msg' => 'POW verified'];
        }
        $this->redis->del($key);
        return ['code' => 400, 'msg' => 'POW 验证失败 '];
    }

    public function getPuzzle($token) {
        if (!$this->validateSecureToken($token)) {
            return ['code' => 403, 'msg' => 'Invalid token format'];
        }
        $key = $this->redisPrefix . $token;
        if (!$this->redis->exists($key) || $this->redis->hGet($key, 'status') !== 'pow_verified') {
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
        $interferenceY = $validY;
        do {
            $interferenceX = rand($blockSize + 20, $width - $blockSize - 20);
        } while (abs($interferenceX - $validX) < $blockSize + 20);

        $this->redis->hMSet($key, ['valid_x' => $validX, 'valid_y' => $validY, 'valid_shape' => $validShape]);

        $this->drawPuzzleHole($bgImg, $validX, $validY, $blockSize, $validShape);
        $this->drawPuzzleHole($bgImg, $interferenceX, $interferenceY, $blockSize, $interferenceShape);
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
        $key = $this->redisPrefix . $token;
        if (!$this->redis->exists($key)) return ['code' => 403, 'msg' => ' 令牌已失效 '];

        $session = $this->redis->hGetAll($key);
        $payload = $this->decryptBehaviorData($encryptedBehaviorData, $token);
        if (!$payload) return ['code' => 400, 'msg' => ' 不合法的请求格式 '];

        $behaviorData = $payload['behavior'] ?? [];
        $envData = $payload['env'] ?? [];

        if (abs((int)$offsetX - (int)$session['valid_x']) > 5) {
            $this->redis->del($key); return ['code' => 400, 'msg' => ' 请拖动至缺口位置 '];
        }
        if (!$this->analyzeBehavior($behaviorData)) {
            $this->redis->del($key); return ['code' => 400, 'msg' => ' 行为验证失败，请重试 '];
        }

        if (!$this->analyzeEnvironment($envData)) {
            $this->redis->del($key); return ['code' => 400, 'msg' => ' 环境校验失败 '];
        }

        $this->redis->del($key);
        $bizToken = $this->generateBizToken();
        $captchaKey = 'captcha:' . $bizToken;
        $this->redis->set($captchaKey, time());
        $this->redis->expire($captchaKey, 3600);
        setcookie("auth_token", $bizToken, time() + 3600, "/", "", false, true);

        return ['code' => 200, 'msg' => 'Success', 'token' => $bizToken];
    }

    public function sendSegment ($token, $seq, $index, $data) {
        if (!$this->validateSecureToken($token)) {
            return $this->jsonResponse (['code' => 403, 'msg' => ' 令牌不合法 '], 403);
        }
        $key = $this->redisPrefix . $token;
        if (!$this->redis->exists($key)) {
            return $this->jsonResponse (['code' => 403, 'msg' => ' 令牌已失效 '], 403);
        }
        $reqKey = $this->redisPrefix . "req:{$token}:{$seq}:{$index}";
        $this->redis->set($reqKey, $data, 30);
        return $this->jsonResponse(['code' => 200, 'msg' => 'Segment received']);
    }

    public function execute($token, $seq, $action) {
        if (!$this->validateSecureToken($token)) {
            return $this->jsonResponse(['code' => 403, 'msg' => 'Invalid token format'], 403);
        }
        $key = $this->redisPrefix . $token;
        if (!$this->redis->exists ($key)) {
            return $this->jsonResponse (['code' => 403, 'msg' => ' 令牌已失效 '], 403);
        }
        $session = $this->redis->hGetAll($key);
        $segmentKey = $session['segment_key'];

        $encryptedReq = '';
        for ($i = 0; $i < 5; $i++) {
            $reqKey = $this->redisPrefix . "req:{$token}:{$seq}:{$i}";
            $segment = $this->redis->get($reqKey);
            if ($segment === false) {
                return $this->jsonResponse(['code' => 400, 'msg' => 'Missing request segment'], 400);
            }
            $encryptedReq .= $segment;
            $this->redis->del($reqKey);
        }
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
        for ($i = 0; $i < 5; $i++) {
            $chunk = substr($encryptedRes, $i * $chunkSize, $chunkSize);
            $resKey = $this->redisPrefix . "res:{$token}:{$seq}:{$i}";
            $this->redis->set($resKey, $chunk, 30);
        }
        return $this->jsonResponse(['code' => 200, 'msg' => 'Executed']);
    }

    public function fetchSegment($token, $seq, $index) {
        $resKey = $this->redisPrefix . "res:{$token}:{$seq}:{$index}";
        $chunk = $this->redis->get($resKey);
        if ($chunk === false) {
            return $this->jsonResponse(['code' => 404, 'msg' => 'Segment not found'], 404);
        }
        $this->redis->del($resKey);
        return $this->jsonResponse(['code' => 200, 'data' => $chunk]);
    }

    private function fetchImageFromApi () {
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

        if (!empty ($env['webdriver']) || !empty($env['auto_phantom']) || !empty($env['auto_nightmare']) || !empty($env['auto_cdc']) || !empty($env['auto_selenium']) || !empty($env['auto_puppeteer'])) {
            return false;
        }

        if (!empty ($env['proto_tampered']) || !empty($env['perm_inconsistency'])) {
            return false;
        }

        if (isset ($env ['win_w']) && $env ['win_w'] <= 0) return false;
        if (isset ($env ['scr_w']) && $env ['scr_w'] <= 0) return false;
        if (isset ($env ['scr_ah']) && $env ['scr_ah'] > $env ['scr_h']) return false;

        if (isset ($env['color_depth']) && !in_array($env['color_depth'], [24, 32, 48])) {
            return false;
        }

        if (isset ($env ['cpu_cores']) && $env ['cpu_cores'] <= 0) return false;

        if (isset ($env['canvas_hash']) && (int)$env['canvas_hash'] === 0) return false;

        if (isset ($env['webgl_data']) && is_array($env['webgl_data'])) {
            if (empty($env['webgl_data']['vendor']) || empty($env['webgl_data']['renderer'])) {
                return false;
            }
        }

        if (isset ($env ['ls']) && $env ['ls'] === false) return false;
        if (isset ($env ['idb']) && $env ['idb'] === false) return false;

        if (isset ($env['logic_check']) && (int)$env['logic_check'] >= 2) {
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
        $key = substr(hash('sha256', $token), 0, 32);
        $data = base64_decode($encryptedData);
        if (!$data) return null;
        $iv = substr($data, 0, 16); $ciphertext = substr($data, 16);
        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return json_decode($decrypted, true);
    }

    private function getEncryptionKey($keyStr) {
        return substr(hash('sha256', $keyStr, true), 0, 32);
    }

    private function segmentEncrypt($data, $keyStr) {
        $key = $this->getEncryptionKey($keyStr);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    private function segmentDecrypt($data, $keyStr) {
        $key = $this->getEncryptionKey($keyStr);
        $decoded = base64_decode($data);
        if (!$decoded) return null;
        $iv = substr($decoded, 0, 16);
        $ciphertext = substr($decoded, 16);
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

$auth = new BehaviorAuth();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'init':
        $auth->initAuth();
        break;
    case 'send-segment':
        $d = json_decode(file_get_contents('php://input'), true);
        $auth->sendSegment($d['token'] ?? '', $d['seq'] ?? '', $d['index'] ?? 0, $d['data'] ?? '');
        break;
    case 'execute':
        $d = json_decode(file_get_contents('php://input'), true);
        $auth->execute($d['token'] ?? '', $d['seq'] ?? '', $d['action'] ?? '');
        break;
    case 'fetch-segment':
        $auth->fetchSegment($_GET['token'] ?? '', $_GET['seq'] ?? '', $_GET['index'] ?? 0);
        break;
    default:
        $auth->jsonResponse(['code' => 404, 'msg' => 'Invalid action'], 404);
}
