<?php
/**
 * Nova JSON API: Nova_DB_Migration
 *
 * 数据库迁移管理类，提供版本化的数据库结构变更能力。
 *
 * 用法：
 *   $mig = new Nova_DB_Migration();
 *   $mig->create('create_users_table', function($schema) {
 *       $schema->create('users', [
 *           'id INT AUTO_INCREMENT PRIMARY KEY',
 *           'username VARCHAR(50) NOT NULL',
 *           'created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
 *       ]);
 *   });
 *   $mig->run();        // 执行所有待执行的迁移
 *   $mig->rollback();   // 回滚最后一批迁移
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_DB_Migration {

    protected $pdo;
    protected $migrationTable = '_migrations';
    protected $migrationDir   = '';

    public function __construct($migrationDir = null) {
        $this->pdo = getDB();
        $this->migrationDir = $migrationDir ?: dirname(__DIR__, 3) . '/migrations';
        $this->ensureMigrationTable();
    }

    /**
     * 确保迁移记录表存在
     */
    protected function ensureMigrationTable() {
        $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$this->migrationTable]);
        if (!$stmt->fetch()) {
            $this->pdo->exec(
                "CREATE TABLE `{$this->migrationTable}` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `migration` VARCHAR(255) NOT NULL,
                    `batch` INT NOT NULL DEFAULT 1,
                    `executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    }

    /**
     * 获取已执行的迁移列表
     * @return array
     */
    public function getRan() {
        $stmt = $this->pdo->query("SELECT migration FROM `{$this->migrationTable}` ORDER BY id ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * 获取当前批次号
     * @return int
     */
    protected function getCurrentBatch() {
        $stmt = $this->pdo->query("SELECT COALESCE(MAX(batch), 0) FROM `{$this->migrationTable}`");
        return (int)$stmt->fetchColumn();
    }

    /**
     * 注册并执行一个迁移
     * @param string   $name     迁移名称
     * @param callable $callback 回调函数，接收 Nova_DB_Schema 实例
     * @return bool
     */
    public function create($name, callable $callback) {
        $ran = $this->getRan();
        if (in_array($name, $ran)) {
            return false;
        }

        $schema = new Nova_DB_Schema();
        call_user_func($callback, $schema);

        $batch = $this->getCurrentBatch() + 1;
        $stmt = $this->pdo->prepare("INSERT INTO `{$this->migrationTable}` (migration, batch) VALUES (?, ?)");
        $stmt->execute([$name, $batch]);

        return true;
    }

    /**
     * 从迁移目录加载并执行所有待执行的迁移文件
     * @return int 执行的迁移数量
     */
    public function run() {
        if (!is_dir($this->migrationDir)) {
            return 0;
        }

        $ran = $this->getRan();
        $files = glob($this->migrationDir . '/*.php');
        sort($files);

        $count = 0;
        $batch = $this->getCurrentBatch() + 1;

        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $ran)) {
                continue;
            }

            $migration = require $file;
            if (is_callable($migration)) {
                $schema = new Nova_DB_Schema();
                call_user_func($migration, $schema);
            }

            $stmt = $this->pdo->prepare("INSERT INTO `{$this->migrationTable}` (migration, batch) VALUES (?, ?)");
            $stmt->execute([$name, $batch]);
            $count++;
        }

        return $count;
    }

    /**
     * 回滚最后一批迁移
     * @param callable $downCallback 可选的下迁回调
     * @return int 回滚的迁移数量
     */
    public function rollback(callable $downCallback = null) {
        $batch = $this->getCurrentBatch();
        if ($batch <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare("SELECT migration FROM `{$this->migrationTable}` WHERE batch = ? ORDER BY id DESC");
        $stmt->execute([$batch]);
        $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($migrations as $migration) {
            if ($downCallback) {
                call_user_func($downCallback, $migration, new Nova_DB_Schema());
            }

            $del = $this->pdo->prepare("DELETE FROM `{$this->migrationTable}` WHERE migration = ? AND batch = ?");
            $del->execute([$migration, $batch]);
        }

        return count($migrations);
    }

    /**
     * 重置所有迁移（回滚全部）
     * @param callable $downCallback
     * @return int
     */
    public function reset(callable $downCallback = null) {
        $stmt = $this->pdo->query("SELECT migration FROM `{$this->migrationTable}` ORDER BY id DESC");
        $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($migrations as $migration) {
            if ($downCallback) {
                call_user_func($downCallback, $migration, new Nova_DB_Schema());
            }
        }

        return $this->pdo->exec("TRUNCATE TABLE `{$this->migrationTable}`");
    }

    /**
     * 获取迁移状态
     * @return array
     */
    public function status() {
        $stmt = $this->pdo->query(
            "SELECT migration, batch, executed_at FROM `{$this->migrationTable}` ORDER BY id ASC"
        );
        $ran = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pending = [];
        if (is_dir($this->migrationDir)) {
            $files = glob($this->migrationDir . '/*.php');
            sort($files);
            $ranNames = array_column($ran, 'migration');
            foreach ($files as $file) {
                $name = basename($file, '.php');
                if (!in_array($name, $ranNames)) {
                    $pending[] = $name;
                }
            }
        }

        return [
            'ran'     => $ran,
            'pending' => $pending,
        ];
    }

    /**
     * 生成迁移文件
     * @param string $name 迁移名称
     * @return string 文件路径
     */
    public function generate($name) {
        if (!is_dir($this->migrationDir)) {
            @mkdir($this->migrationDir, 0755, true);
        }

        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $filepath = $this->migrationDir . '/' . $filename;

        $content = <<<PHP
<?php
/**
 * Migration: {$name}
 */

return function(\$schema) {
    // \$schema->create('example', [
    //     'id INT AUTO_INCREMENT PRIMARY KEY',
    //     'name VARCHAR(100) NOT NULL',
    //     'created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
    // ]);
};

PHP;
        file_put_contents($filepath, $content);
        return $filepath;
    }

    /**
     * 删除迁移记录表中的指定记录（不执行 SQL 回滚）
     * @param string $migration 迁移名称
     * @return bool
     */
    public function removeRecord($migration) {
        $stmt = $this->pdo->prepare("DELETE FROM `{$this->migrationTable}` WHERE migration = ?");
        return $stmt->execute([$migration]);
    }
}
