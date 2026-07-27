<?php
/**
 * 获取最新的公钥接口
 */

header('Content-Type: text/plain; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 指向项目根目录下的 config/keys/public.pem
// 假设 vendor/api/opensource/ 距离根目录 3 层: vendor/api/opensource/ -> vendor/api/ -> vendor/ -> root
$publicKeyFile = dirname(dirname(dirname(__DIR__))) . '/config/keys/public.pem';

if (file_exists($publicKeyFile)) {
    readfile($publicKeyFile);
} else {
    http_response_code(404);
    echo "Public Key Not Found";
}
