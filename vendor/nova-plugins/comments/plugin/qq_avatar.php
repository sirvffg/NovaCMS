<?php
/**
 * QQ 头像代理 API（comments 插件）
 *
 * 服务端代理获取 QQ 头像图片，响应为图片二进制而非 JSON，
 * QQ 号经 AES-256-CBC 加密，不在 URL 或响应中暴露明文。
 *
 * 每次 IV 随机，同一 QQ 号每次生成不同密文，无法关联。
 *
 * 路由：/qq_avatar.php?t={AES加密的QQ号}
 * 返回：image/jpeg 图片流
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

// 防止直接访问时输出 PHP 警告
error_reporting(0);

// 加密密钥（必须与 config/comment_functions.php 中的 NOVA_AVATAR_KEY 一致）
if (!defined('NOVA_AVATAR_KEY')) {
    define('NOVA_AVATAR_KEY', 'NovaCMS-AvatarProxy-v3-7f9a2c');
}

// 从加密 token 解密 QQ 号
$qq = '';
if (isset($_GET['t']) && $_GET['t'] !== '') {
    $raw = base64_decode(strtr($_GET['t'], '-_', '+/'));
    if ($raw !== false && strlen($raw) >= 32) {
        $key = hash('sha256', NOVA_AVATAR_KEY, true);
        $iv = substr($raw, 0, 16);
        $encrypted = substr($raw, 16);
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted !== false) {
            $qq = trim($decrypted);
        }
    }
}

// 验证 QQ 号格式（5-11 位纯数字）
if ($qq === '' || !preg_match('/^\d{5,11}$/', $qq)) {
    outputPlaceholder();
    exit;
}

// QQ 头像接口仅支持 s=100
$avatarUrl = 'https://q1.qlogo.cn/g?b=qq&nk=' . rawurlencode($qq) . '&s=100';

// 服务端代理获取图片，QQ 号不会暴露给客户端
$imageData = fetchRemote($avatarUrl);

if ($imageData === false || strlen($imageData) < 100) {
    outputPlaceholder();
    exit;
}

// 缓存 24 小时，减轻 QQ 服务器压力
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . strlen($imageData));
echo $imageData;
exit;

/**
 * 使用 cURL 或 file_get_contents 获取远程图片
 */
function fetchRemote($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT     => 'NovaCMS/AvatarProxy',
        ]);
        $data = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($httpCode === 200 && $data !== false) ? $data : false;
    }

    if (!ini_get('allow_url_fopen')) {
        return false;
    }
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'method'  => 'GET',
            'header'  => "User-Agent: NovaCMS/AvatarProxy\r\n",
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    return $data !== false ? $data : false;
}

/**
 * 输出 SVG 占位头像（QQ 号无效或获取失败时退避）
 */
function outputPlaceholder() {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-cache');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">'
       . '<rect width="100" height="100" fill="#e9ecef"/>'
       . '<circle cx="50" cy="38" r="18" fill="#ced4da"/>'
       . '<path d="M20 85 Q50 58 80 85" fill="#ced4da"/>'
       . '</svg>';
}
