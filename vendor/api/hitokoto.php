<?php
// api/hitokoto.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate'); // 禁止缓存
header('Pragma: no-cache');
header('Expires: 0');

require_once '../../config/database.php';

try {
    $db = getDB();
    
    // 获取总条数
    $count = $db->query("SELECT COUNT(*) FROM hitokoto")->fetchColumn();
    
    if ($count > 0) {
        // 生成随机偏移量
        // 使用 PHP 的 mt_rand 生成更好的随机数，配合 OFFSET 实现随机读取
        // 相比 ORDER BY RAND()，这种方式在大数据量下性能更好，且随机性由 PHP 控制
        $offset = mt_rand(0, $count - 1);
        
        $stmt = $db->prepare("SELECT * FROM hitokoto LIMIT 1 OFFSET :offset");
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $data = false;
    }
    
    if ($data) {
        echo json_encode([
            'id' => $data['id'],
            'hitokoto' => $data['hitokoto'],
            'from' => $data['from'],
            'from_who' => $data['from_who'],
            'creator' => $data['creator'],
            'created_at' => $data['created_at']
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Fallback if no data
        echo json_encode([
            'id' => 0,
            'hitokoto' => '这里空空如也，快去后台添加一条吧！',
            'from' => '系统',
            'from_who' => null,
            'creator' => 'System',
            'created_at' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    // If table doesn't exist or connection fails
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>