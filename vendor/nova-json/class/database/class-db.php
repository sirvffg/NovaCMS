<?php
/**
 * Nova JSON API: Nova_DB
 *
 * 数据库读写封装类，提供 CRUD 及表管理操作。
 * 用法：$db = new Nova_DB();
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_DB {

    protected $pdo;

    public function __construct() {
        $this->pdo = getDB();
    }

    // ── 查询 ──

    /**
     * 获取单行单列的值
     * $db->get_var("SELECT COUNT(*) FROM shuoshuo");
     * $db->get_var("SELECT name FROM users WHERE id = ?", [1]);
     */
    public function get_var($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * 获取单行数据（关联数组）
     * $db->get_row("SELECT * FROM users WHERE id = ?", [1]);
     */
    public function get_row($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * 获取多行数据
     * $db->get_results("SELECT * FROM shuoshuo ORDER BY id DESC");
     */
    public function get_results($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── 写入 ──

    /**
     * 插入一行数据
     * $db->insert('shuoshuo', ['content' => '你好', 'created_at' => date('Y-m-d H:i:s')]);
     * 返回新插入的 ID（无自增时返回 0）
     */
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * 批量插入多行
     * $db->insert_batch('shuoshuo', [
     *     ['content' => 'a', 'created_at' => '...'],
     *     ['content' => 'b', 'created_at' => '...'],
     * ]);
     * 返回插入行数
     */
    public function insert_batch($table, $rows) {
        if (empty($rows)) return 0;
        $columns = implode(', ', array_keys($rows[0]));
        $rowCount = count($rows);
        $colCount = count($rows[0]);
        $placeholders = '(' . implode(', ', array_fill(0, $colCount, '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, $rowCount, $placeholders));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES {$allPlaceholders}";
        $params = [];
        foreach ($rows as $row) {
            foreach (array_values($row) as $val) {
                $params[] = $val;
            }
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * 更新数据
     * $db->update('shuoshuo', ['content' => '新内容'], ['id' => 1]);
     * $db->update('shuoshuo', ['content' => '新内容'], ['id' => 1, 'status' => 1]);
     * 返回受影响行数
     */
    public function update($table, $data, $where) {
        $setClauses = implode(', ', array_map(function($col) {
            return "{$col} = ?";
        }, array_keys($data)));
        $whereClauses = implode(' AND ', array_map(function($col) {
            return "{$col} = ?";
        }, array_keys($where)));
        $sql = "UPDATE {$table} SET {$setClauses} WHERE {$whereClauses}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(array_values($data), array_values($where)));
        return $stmt->rowCount();
    }

    /**
     * 删除数据
     * $db->delete('shuoshuo', ['id' => 1]);
     * $db->delete('shuoshuo', ['id' => 1, 'author_id' => 5]);
     * 返回受影响行数
     */
    public function delete($table, $where) {
        $whereClauses = implode(' AND ', array_map(function($col) {
            return "{$col} = ?";
        }, array_keys($where)));
        $sql = "DELETE FROM {$table} WHERE {$whereClauses}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($where));
        return $stmt->rowCount();
    }

    // ── 表管理 ──

    /**
     * 创建表
     * $db->create_table('my_data', [
     *     'id INT AUTO_INCREMENT PRIMARY KEY',
     *     'name VARCHAR(100) NOT NULL',
     *     'created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
     * ]);
     */
    public function create_table($table, $columns) {
        $cols = implode(', ', $columns);
        return $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$table} ({$cols}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * 删除表
     */
    public function drop_table($table) {
        return $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
    }

    /**
     * 检查表是否存在
     */
    public function table_exists($table) {
        $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetch();
    }

    /**
     * 清空表数据（重置自增 ID）
     */
    public function truncate($table) {
        return $this->pdo->exec("TRUNCATE TABLE {$table}");
    }

    /**
     * 添加字段（如果不存在）
     * $db->add_column('website_config', 'my_field', 'TEXT COMMENT '自定义' AFTER id');
     */
    public function add_column($table, $column, $definition) {
        $check = $this->pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
        if (!$check->fetch()) {
            return $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
        return false;
    }

    // ── 杂项 ──

    /**
     * 执行原始 SQL（无参数绑定）
     * 仅限 DDL 或无参数查询使用
     */
    public function query($sql) {
        return $this->pdo->exec($sql);
    }

    /**
     * 执行原始查询并返回 PDOStatement（高级用法）
     * 用于需要手动 fetch 的场景
     */
    public function raw_query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * 获取最后一次插入的 ID
     */
    public function insert_id() {
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * 获取原生 PDO 实例（高级用法）
     */
    public function get_pdo() {
        return $this->pdo;
    }

    /**
     * 事务支持
     */
    public function begin() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollback() {
        return $this->pdo->rollBack();
    }
}
