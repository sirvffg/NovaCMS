<?php
/**
 * Nova JSON API: Nova_DB_Seeder
 *
 * 数据库数据填充器，用于向表中插入测试数据或默认数据。
 *
 * 用法：
 *   $seeder = new Nova_DB_Seeder();
 *   $seeder->table('instant')->columns(['content', 'created_at'])->seed([
 *       ['第一条片刻', '2025-01-01 12:00:00'],
 *       ['第二条片刻', '2025-01-02 12:00:00'],
 *   ]);
 *
 *   // 使用 Fakert 生成指定数量的随机数据
 *   $seeder->table('users')->columns(['username', 'email'])->count(50)->generate();
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_DB_Seeder {

    protected $pdo;
    protected $tableName   = '';
    protected $columns     = [];
    protected $insertCount = 10;
    protected $batchSize   = 100;

    public function __construct() {
        $this->pdo = getDB();
    }

    /**
     * 设置要填充的表
     * @param string $table 表名
     * @return $this
     */
    public function table($table) {
        $this->tableName = $table;
        return $this;
    }

    /**
     * 设置字段列表
     * @param array $columns 字段名数组
     * @return $this
     */
    public function columns(array $columns) {
        $this->columns = $columns;
        return $this;
    }

    /**
     * 设置生成数量（用于 generate 方法）
     * @param int $count
     * @return $this
     */
    public function count($count) {
        $this->insertCount = max(1, (int)$count);
        return $this;
    }

    /**
     * 设置每批插入的行数
     * @param int $size
     * @return $this
     */
    public function batchSize($size) {
        $this->batchSize = max(1, (int)$size);
        return $this;
    }

    /**
     * 插入指定数据
     * @param array $rows 数据行列表，每行为与 columns 对应的值数组
     * @return int 插入行数
     */
    public function seed(array $rows) {
        if (empty($this->tableName) || empty($this->columns) || empty($rows)) {
            return 0;
        }

        $columns = implode(', ', array_map(function($col) {
            return "`{$col}`";
        }, $this->columns));

        $colCount = count($this->columns);
        $placeholders = '(' . implode(', ', array_fill(0, $colCount, '?')) . ')';

        $inserted = 0;
        $chunks = array_chunk($rows, $this->batchSize);

        foreach ($chunks as $chunk) {
            $allPlaceholders = implode(', ', array_fill(0, count($chunk), $placeholders));
            $sql = "INSERT INTO `{$this->tableName}` ({$columns}) VALUES {$allPlaceholders}";

            $params = [];
            foreach ($chunk as $row) {
                foreach ($row as $val) {
                    $params[] = $val;
                }
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $inserted += $stmt->rowCount();
        }

        return $inserted;
    }

    /**
     * 插入单行数据
     * @param array $data 关联数组 ['column' => 'value']
     * @return int 插入的 ID
     */
    public function insert(array $data) {
        $this->columns = array_keys($data);
        $rows = [array_values($data)];
        $this->seed($rows);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * 使用生成器填充数据（需子类实现 generateRow 方法或传入回调）
     * @param callable|null $generator 接收行号返回关联数组的回调
     * @return int 插入行数
     */
    public function generate(callable $generator = null) {
        $rows = [];
        for ($i = 0; $i < $this->insertCount; $i++) {
            if ($generator) {
                $rowData = call_user_func($generator, $i);
                if (empty($this->columns)) {
                    $this->columns = array_keys($rowData);
                }
                $rows[] = array_values($rowData);
            } elseif (method_exists($this, 'generateRow')) {
                $rowData = $this->generateRow($i);
                if (empty($this->columns)) {
                    $this->columns = array_keys($rowData);
                }
                $rows[] = array_values($rowData);
            } else {
                break;
            }
        }
        return $this->seed($rows);
    }

    /**
     * 清空表并重置自增 ID
     * @return bool
     */
    public function truncate() {
        if (empty($this->tableName)) {
            return false;
        }
        return $this->pdo->exec("TRUNCATE TABLE `{$this->tableName}`") !== false;
    }

    /**
     * 先清空再填充
     * @param array $rows 数据行列表
     * @return int 插入行数
     */
    public function fresh(array $rows) {
        $this->truncate();
        return $this->seed($rows);
    }

    /**
     * 填充并返回摘要信息
     * @param array $rows
     * @return array
     */
    public function seedWithSummary(array $rows) {
        $count = $this->seed($rows);
        return [
            'table'   => $this->tableName,
            'columns' => $this->columns,
            'seeded'  => $count,
            'time'    => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 获取表的所有字段名
     * @return array
     */
    public function getTableColumns() {
        if (empty($this->tableName)) {
            return [];
        }
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `{$this->tableName}`");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function($col) {
            return $col['Field'];
        }, $columns);
    }

    /**
     * 从 JSON 文件导入数据
     * @param string $filepath JSON 文件路径
     * @return int 插入行数
     */
    public function fromJson($filepath) {
        if (!file_exists($filepath)) {
            return 0;
        }
        $json = file_get_contents($filepath);
        $data = json_decode($json, true);
        if (empty($data) || !is_array($data)) {
            return 0;
        }
        // 如果数据是关联数组（单行），转为多行
        if (isset($data[0]) === false) {
            $data = [$data];
        }
        $this->columns = array_keys($data[0]);
        $rows = [];
        foreach ($data as $row) {
            $rows[] = array_values($row);
        }
        return $this->seed($rows);
    }

    /**
     * 重置状态
     * @return $this
     */
    public function reset() {
        $this->tableName   = '';
        $this->columns     = [];
        $this->insertCount = 10;
        return $this;
    }
}
