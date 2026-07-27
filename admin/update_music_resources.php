<?php
// 第一时间拦截所有的致命错误和异常，确保100%返回 JSON，不至于前端得到空结果
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR, E_RECOVERABLE_ERROR])) {
        // 清理掉任何已有的输出
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        $msg = $error['message'];
        if (function_exists('mb_convert_encoding')) {
            $msg = @mb_convert_encoding($msg, 'UTF-8', 'UTF-8, GBK, GB2312, BIG5');
        }
        echo json_encode(['success' => false, 'error' => '致命错误: ' . $msg]);
        exit;
    }
});

set_exception_handler(function($e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'error' => '异常: ' . $e->getMessage()]);
    exit;
});

error_reporting(0);
ini_set('display_errors', '0');

// 辅助函数：安全输出 JSON
function output_json($data) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo json_encode(['success' => false, 'error' => 'JSON编码失败: ' . json_last_error_msg()]);
    } else {
        echo $json;
    }
    exit;
}

// 开启输出缓冲以防止前面的 require_once 输出空白字符
ob_start();

session_start();

if (!isset($_SESSION['admin_id'])) {
    output_json(['success' => false, 'error' => '未登录']);
}

require_once '../config/database.php';
require_once '../config/functions.php';

// 定义资源URL
$resources = [
    'css' => [
        'url' => 'https://api.hypcvgm.top/NeteaseMiniPlayer/netease-mini-player-v2.css',
        'file' => '../config/music_local/netease-mini-player-v2.css',
        'name' => '样式文件 (CSS)'
    ],
    'js' => [
        'url' => 'https://api.hypcvgm.top/NeteaseMiniPlayer/netease-mini-player-v2.js',
        'file' => '../config/music_local/netease-mini-player-v2.js',
        'name' => '脚本文件 (JS)'
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success_count = 0;
    $log = [];
    $error_msg = '';

    foreach ($resources as $type => $info) {
        $content = fetchRemoteContent($info['url']);
        if ($content) {
            $dir = dirname($info['file']);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            // 清除之前的错误信息，便于准确捕获 file_put_contents 的错误
            if (function_exists('error_clear_last')) {
                @error_clear_last();
            }

            $write_result = @file_put_contents($info['file'], $content);
            if ($write_result !== false) {
                $success_count++;
                $log[] = "{$info['name']} 更新成功 (" . strlen($content) . " bytes)";
            } else {
                $err = error_get_last();
                $sys_err = $err ? strip_tags($err['message']) : '写入权限不足';
                $error_msg .= "{$info['name']} 写入失败 ($sys_err)\n";
            }
        } else {
            $error_msg .= "{$info['name']} 下载失败，可能是网络问题。\n";
        }
    }

    if ($success_count > 0) {
        $message = "更新完成！\n" . implode("\n", $log);
        if ($error_msg) {
            $message .= "\n部分失败:\n" . $error_msg;
        }
        output_json([
            'success' => true,
            'message' => $message,
            'details' => $log
        ]);
    } else {
        output_json([
            'success' => false,
            'error' => "更新失败：\n" . $error_msg
        ]);
    }
} else {
    output_json(['success' => false, 'error' => '无效的请求方法']);
}

// 获取远程内容辅助函数
function fetchRemoteContent($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ]);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($content)) {
            return $content;
        }
    }
    
    // 如果 curl 不可用或失败，尝试 file_get_contents
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n",
            'timeout' => 30
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    $content = @file_get_contents($url, false, $context);
    return $content !== false ? $content : false;
}