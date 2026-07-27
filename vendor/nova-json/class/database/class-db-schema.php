<?php
/**
 * Nova JSON API: Nova_DB_Schema
 *
 * 数据库表结构管理类，用于创建、修改、删除表和字段。
 *
 * 用法：
 *   $schema = new Nova_DB_Schema();
 *   $schema->create('my_table', [
 *       'id INT AUTO_INCREMENT PRIMARY KEY',
 *       'name VARCHAR(100) NOT NULL',
 *       'created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
 *   ]);
 *   $schema->addColumn('my_table', 'status', "TINYINT(1) DEFAULT 1 AFTER name");
 *   $schema->hasTable('my_table');
 */

defined('NOVA_API') or exit('禁止直接访问');

class Nova_DB_Schema {

    protected $pdo;

    public function __construct() {
        $this->pdo = getDB();
    }

    /**
     * 创建表
     * @param string $table      表名
     * @param array  $columns    字段定义数组
     * @param array  $options    附加选项（如 engine, charset 等）
     * @return bool
     */
    public function create($table, array $columns, array $options = []) {
        $engine  = !empty($options['engine'])  ? $options['engine']  : 'InnoDB';
        $charset = !empty($options['charset']) ? $options['charset'] : 'utf8mb4';
        $collate = !empty($options['collate']) ? $options['collate'] : 'utf8mb4_unicode_ci';
        $comment = !empty($options['comment']) ? " COMMENT='" . $options['comment'] . "'" : '';

        $cols = implode(', ', $columns);
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` ({$cols}) ENGINE={$engine} DEFAULT CHARSET={$charset} COLLATE={$collate}{$comment}";
        return $this->pdo->exec($sql) !== false;
    }

    /**
     * 删除表
     * @param string $table 表名
     * @return bool
     */
    public function drop($table) {
        return $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`") !== false;
    }

    /**
     * 清空表
     * @param string $table 表名
     * @return bool
     */
    public function truncate($table) {
        return $this->pdo->exec("TRUNCATE TABLE `{$table}`") !== false;
    }

    /**
     * 检查表是否存在
     * @param string $table 表名
     * @return bool
     */
    public function hasTable($table) {
        $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetch();
    }

    /**
     * 重命名表
     * @param string $oldName 原表名
     * @param string $newName 新表名
     * @return bool
     */
    public function rename($oldName, $newName) {
        return $this->pdo->exec("RENAME TABLE `{$oldName}` TO `{$newName}`") !== false;
    }

    /**
     * 添加字段
     * @param string $table      表名
     * @param string $column     字段名
     * @param string $definition 字段定义（含类型和属性）
     * @return bool
     */
    public function addColumn($table, $column, $definition) {
        if (!$this->hasColumn($table, $column)) {
            return $this->pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}") !== false;
        }
        return false;
    }

    /**
     * 修改字段
     * @param string $table      表名
     * @param string $column     字段名
     * @param string $definition 新的字段定义
     * @return bool
     */
    public function modifyColumn($table, $column, $definition) {
        return $this->pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}") !== false;
    }

    /**
     * 删除字段
     * @param string $table  表名
     * @param string $column 字段名
     * @return bool
     */
    public function dropColumn($table, $column) {
        if ($this->hasColumn($table, $column)) {
            return $this->pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`") !== false;
        }
        return false;
    }

    /**
     * 检查字段是否存在
     * @param string $table  表名
     * @param string $column 字段名
     * @return bool
     */
    public function hasColumn($table, $column) {
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 添加索引
     * @param string $table    表名
     * @param string $name     索引名
     * @param array  $columns  索引字段列表
     * @param string $type     索引类型（INDEX, UNIQUE, PRIMARY, FULLTEXT）
     * @return bool
     */
    public function addIndex($table, $name, array $columns, $type = 'INDEX') {
        $cols = implode('`, `', $columns);
        $sql = "ALTER TABLE `{$table}` ADD {$type} `{$name}` (`{$cols}`)";
        return $this->pdo->exec($sql) !== false;
    }

    /**
     * 删除索引
     * @param string $table 表名
     * @param string $name  索引名
     * @return bool
     */
    public function dropIndex($table, $name) {
        $sql = "ALTER TABLE `{$table}` DROP INDEX `{$name}`";
        return $this->pdo->exec($sql) !== false;
    }

    /**
     * 获取表的所有字段信息
     * @param string $table 表名
     * @return array
     */
    public function getColumns($table) {
        $stmt = $this->pdo->prepare("SHOW FULL COLUMNS FROM `{$table}`");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 获取表的所有索引信息
     * @param string $table 表名
     * @return array
     */
    public function getIndexes($table) {
        $stmt = $this->pdo->prepare("SHOW INDEX FROM `{$table}`");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 获取建表 SQL
     * @param string $table 表名
     * @return string|null
     */
    public function getCreateSql($table) {
        $stmt = $this->pdo->prepare("SHOW CREATE TABLE `{$table}`");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['Create Table'] : null;
    }

    /**
     * 获取所有表名
     * @param string $prefix 可选，按前缀过滤
     * @return array
     */
    public function getTables($prefix = '') {
        $sql = "SHOW TABLES";
        if ($prefix) {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$prefix . '%']);
        } else {
            $stmt = $this->pdo->query($sql);
        }
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
