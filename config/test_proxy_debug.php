<?php
// Simulate the checkProxyAvailable function from config/music_player.php
function checkProxyAvailableDebug() {
    $proxy_url = '/config/music_proxy.php?type=css';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost'; // Fallback if CLI
    $url = 'http://' . $host . $proxy_url;
    
    echo "Testing URL: " . $url . "\n";
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 3,
            'method' => 'HEAD'
        ]
    ]);
    
    $headers = @get_headers($url, 1, $context);
    
    if ($headers) {
        echo "Headers received:\n";
        print_r($headers);
        $is_available = strpos($headers[0], '200') !== false;
        echo "Status 200 found: " . ($is_available ? "Yes" : "No") . "\n";
    } else {
        echo "Failed to get headers.\n";
        $error = error_get_last();
        if ($error) {
            echo "Error: " . $error['message'] . "\n";
        }
    }
}

// Also simulate the domain check in music_proxy.php
class ProxyConfig {
    const ALLOWED_DOMAINS = [
        'lygalaxy.cn',
        'www.lygalaxy.cn',
        'localhost',
        '127.0.0.1'
    ];
}

function checkOriginDebug($origin, $referer) {
    echo "Checking Origin: '$origin', Referer: '$referer'\n";
    
    if (empty($origin) && empty($referer)) {
        echo "Empty origin/referer -> Allowed (Direct access)\n";
        return true;
    }
    
    $checkDomain = function($url) {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return false;
        
        foreach (ProxyConfig::ALLOWED_DOMAINS as $domain) {
            if (strpos($host, $domain) !== false) {
                return true;
            }
        }
        return false;
    };
    
    if ($origin && $checkDomain($origin)) {
        echo "Origin match -> Allowed\n";
        return true;
    }
    if ($referer && $checkDomain($referer)) {
        echo "Referer match -> Allowed\n";
        return true;
    }
    
    echo "No match -> Denied\n";
    return false;
}

// Run tests
echo "=== Connection Test ===\n";
// We need to know the actual domain the user is running on to test this properly, 
// but in CLI we can only test localhost or 127.0.0.1 unless we know the external IP/domain mapping.
// However, the user provided log shows 'ser468276538414.ceshi2.w21.net'.
// We can't access that from here probably, but we can check if localhost works.

// Mock $_SERVER for connection test
$_SERVER['HTTP_HOST'] = 'localhost';
checkProxyAvailableDebug();

echo "\n=== Domain Permission Test ===\n";
// Test with the domain from the user logs

?>
