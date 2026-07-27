<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDB();
    $config = $db->query("SELECT * FROM website_config LIMIT 1")->fetch();
    
    // 检查是否启用本站一言
    if (!empty($config['use_local_hitokoto'])) {
        // 获取本站一言
        $count = $db->query("SELECT COUNT(*) FROM hitokoto")->fetchColumn();
        if ($count > 0) {
            $offset = mt_rand(0, $count - 1);
            $stmt = $db->prepare("SELECT * FROM hitokoto LIMIT 1 OFFSET :offset");
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($data) {
                echo $data['hitokoto'];
                exit;
            }
        }
    }
    
    // 使用配置的API或静态文字
    if ($config && isset($config['website_intro'])) {
        echo getIntroText($config['website_intro']);
    }
} catch (Exception $e) {
    echo '';
}
