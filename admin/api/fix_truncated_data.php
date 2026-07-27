<?php
// 清除所有输出缓冲
if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

session_start();

// 如果未登录，返回错误
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once '../../config/database.php';

class DataTruncationFixer {
    private $db;
    private $maxLengths = [
        'country' => 50,
        'province' => 50,
        'city' => 50,
        'isp' => 100
    ];
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * 修复截断的数据
     */
    public function fixTruncatedData() {
        try {
            // 1. 首先更新数据库表结构
            $this->updateTableStructure();
            
            // 2. 修复现有数据
            $result = $this->fixExistingData();
            
            // 3. 检查是否还有问题数据
            $problemCount = $this->checkProblemData();
            
            return [
                'success' => true,
                'table_structure_updated' => true,
                'records_fixed' => $result['fixed'],
                'records_checked' => $result['checked'],
                'problem_records_remaining' => $problemCount,
                'message' => "数据截断问题修复完成。修复了 {$result['fixed']} 条记录。"
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 更新表结构
     */
    private function updateTableStructure() {
        $sql = "
            ALTER TABLE `visit_stats` 
            MODIFY COLUMN `country` VARCHAR(50) DEFAULT NULL COMMENT '国家',
            MODIFY COLUMN `province` VARCHAR(50) DEFAULT NULL COMMENT '省份',
            MODIFY COLUMN `city` VARCHAR(50) DEFAULT NULL COMMENT '城市',
            MODIFY COLUMN `isp` VARCHAR(100) DEFAULT NULL COMMENT '运营商'
        ";
        
        $this->db->exec($sql);
    }
    
    /**
     * 修复现有数据
     */
    private function fixExistingData() {
        // 获取所有需要检查的记录
        $stmt = $this->db->query("
            SELECT ip_address, country, province, city, isp 
            FROM visit_stats 
            WHERE ip_address IS NOT NULL
        ");
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $fixedCount = 0;
        
        foreach ($records as $record) {
            $needsUpdate = false;
            $updates = [];
            
            // 检查每个字段
            foreach (['country', 'province', 'city', 'isp'] as $field) {
                $value = $record[$field];
                if ($value !== null && mb_strlen($value, 'UTF-8') > $this->maxLengths[$field]) {
                    $updates[$field] = mb_substr($value, 0, $this->maxLengths[$field], 'UTF-8');
                    $needsUpdate = true;
                }
            }
            
            // 如果需要更新
            if ($needsUpdate) {
                $setClause = [];
                $values = [];
                
                foreach ($updates as $field => $value) {
                    $setClause[] = "$field = ?";
                    $values[] = $value;
                }
                
                $values[] = $record['ip_address']; // WHERE条件
                
                $sql = "UPDATE visit_stats SET " . implode(', ', $setClause) . " WHERE ip_address = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($values);
                
                $fixedCount++;
            }
        }
        
        return [
            'fixed' => $fixedCount,
            'checked' => count($records)
        ];
    }
    
    /**
     * 检查是否还有问题数据
     */
    private function checkProblemData() {
        $conditions = [];

        foreach ($this->maxLengths as $field => $maxLength) {
            $conditions[] = "CHAR_LENGTH(COALESCE($field, '')) > " . (int)$maxLength;
        }

        $sql = "SELECT COUNT(*) as count FROM visit_stats WHERE " . implode(' OR ', $conditions);
        $stmt = $this->db->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)$result['count'];
    }
    
    /**
     * 获取字段长度统计
     */
    public function getFieldLengthStats() {
        $stats = [];
        
        foreach ($this->maxLengths as $field => $maxLength) {
            $sql = "
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN $field IS NOT NULL THEN 1 END) as not_null,
                    MAX(CHAR_LENGTH(COALESCE($field, ''))) as max_length,
                    AVG(CHAR_LENGTH(COALESCE($field, ''))) as avg_length
                FROM visit_stats
            ";
            
            $stmt = $this->db->query($sql);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stats[$field] = [
                'max_length' => (int)$result['max_length'],
                'avg_length' => round((float)$result['avg_length'], 2),
                'limit' => $maxLength,
                'exceeds_limit' => (int)$result['max_length'] > $maxLength,
                'fill_rate' => round(((int)$result['not_null'] / (int)$result['total']) * 100, 2) . '%'
            ];
        }
        
        return $stats;
    }
}

// 执行修复
try {
    $action = $_GET['action'] ?? 'fix';
    $db = getDB();
    $fixer = new DataTruncationFixer($db);
    
    if ($action === 'stats') {
        $stats = $fixer->getFieldLengthStats();
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
    } else {
        $result = $fixer->fixTruncatedData();
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>