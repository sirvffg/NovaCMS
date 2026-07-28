<?php
/**
 * Backup Class - 备份核心功能类
 */

defined('NOVA_API') or exit('禁止直接访问');

class Backup_Core {

    private $backupDir;
    private $maxBackups = 10;
    private $allowedUserAgent = 'BackupApp_lygalaxy.cn_2019_Galaxy';

    public function __construct() {
        $this->backupDir = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'Backup';
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * 验证 User-Agent
     */
    public function validateRequest() {
        $clientUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return strpos($clientUserAgent, $this->allowedUserAgent) !== false;
    }

    /**
     * 记录访问日志
     */
    public function logAccess($action) {
        $logFile = $this->backupDir . '/backup.log';
        $maxSize = 3 * 1024 * 1024;

        if (@file_exists($logFile) && @filesize($logFile) > $maxSize) {
            @file_put_contents($logFile, '');
        }

        $logEntry = sprintf("[%s] IP: %s | Action: %s | URI: %s\n",
            date('Y-m-d H:i:s'),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $action,
            $_SERVER['REQUEST_URI'] ?? 'unknown'
        );
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * 导出数据库为SQL文件
     */
    private function exportDatabaseToFile($filePath) {
        $pdo = getDB();
        if (!$pdo) return false;

        $fp = fopen($filePath, 'w');
        if (!$fp) return false;

        fwrite($fp, "-- Database Backup: " . DB_NAME . "\n");
        fwrite($fp, "-- Date: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fp, "-- Host: " . DB_HOST . "\n\n");
        fwrite($fp, "SET NAMES utf8mb4;\n");
        fwrite($fp, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

        try {
            $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
            $pdo->beginTransaction();

            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            $views  = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $createRow = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                $createSql = $createRow['Create Table'] ?? '';

                fwrite($fp, "-- ----------------------------\n");
                fwrite($fp, "-- Table structure for `$table`\n");
                fwrite($fp, "-- ----------------------------\n");
                fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
                fwrite($fp, $createSql . ";\n\n");

                $dataStmt = $pdo->query("SELECT * FROM `$table`");
                $batchSize = 500;
                $batch = [];

                fwrite($fp, "-- ----------------------------\n");
                fwrite($fp, "-- Records of `$table`\n");
                fwrite($fp, "-- ----------------------------\n");

                while ($row = $dataStmt->fetch(PDO::FETCH_NUM)) {
                    $values = array_map(function($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        if (!mb_check_encoding($v, 'UTF-8')) {
                            return '0x' . bin2hex($v);
                        }
                        return "'" . addslashes($v) . "'";
                    }, $row);

                    $batch[] = '(' . implode(', ', $values) . ')';

                    if (count($batch) >= $batchSize) {
                        fwrite($fp, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }

                if (!empty($batch)) {
                    fwrite($fp, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
                }

                fwrite($fp, "\n");
            }

            foreach ($views as $view) {
                $createRow = $pdo->query("SHOW CREATE VIEW `$view`")->fetch(PDO::FETCH_ASSOC);
                $createSql = $createRow['Create View'] ?? '';

                fwrite($fp, "-- ----------------------------\n");
                fwrite($fp, "-- View structure for `$view`\n");
                fwrite($fp, "-- ----------------------------\n");
                fwrite($fp, "DROP VIEW IF EXISTS `$view`;\n");
                fwrite($fp, $createSql . ";\n\n");
            }

            $pdo->rollBack();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fclose($fp);
            @unlink($filePath);
            return false;
        }

        fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fp);
        return true;
    }

    /**
     * 递归复制目录
     */
    private function copyDirectory($src, $dest, $excludeDirs = []) {
        if (!is_dir($src)) return;
        if (!is_dir($dest)) @mkdir($dest, 0755, true);

        foreach (scandir($src) as $file) {
            if ($file === '.' || $file === '..') continue;

            $srcPath = $src . DIRECTORY_SEPARATOR . $file;
            $destPath = $dest . DIRECTORY_SEPARATOR . $file;

            $isExcluded = false;
            foreach ($excludeDirs as $exclude) {
                if (basename($srcPath) === $exclude) {
                    $isExcluded = true;
                    break;
                }
            }
            if ($isExcluded) continue;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $destPath, $excludeDirs);
            } else {
                @copy($srcPath, $destPath);
            }
        }
    }

    /**
     * 递归删除目录
     */
    private function removeDirectory($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        return @rmdir($dir);
    }

    /**
     * 递归添加目录到 ZipArchive
     */
    private function addDirectoryToZip($zip, $dirPath, $zipPath) {
        $files = scandir($dirPath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;

            $filePath = $dirPath . DIRECTORY_SEPARATOR . $file;
            $localPath = $zipPath ? $zipPath . '/' . $file : $file;

            if (is_dir($filePath)) {
                $zip->addEmptyDir($localPath);
                $this->addDirectoryToZip($zip, $filePath, $localPath);
            } else {
                $zip->addFile($filePath, $localPath);
            }
        }
    }

    /**
     * 格式化大小
     */
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes >= 1024 && $i < 3; $i++) $bytes /= 1024;
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * 创建备份
     */
    public function createBackup() {
        $projectRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');

        $timestamp = date('Ymd_His');
        $tempFolderName = 'temp_' . $timestamp;
        $tempDirPath = $this->backupDir . DIRECTORY_SEPARATOR . $tempFolderName;
        $backupFilename = "backup_{$timestamp}.zip";
        $backupPath = $this->backupDir . DIRECTORY_SEPARATOR . $backupFilename;

        if (!@mkdir($tempDirPath, 0755, true)) {
            return ['success' => false, 'message' => '权限不足，无法创建临时目录'];
        }

        $directories = ['admin', 'assets', 'config', 'license', 'phpmailer', 'logs', 'uploads', 'vendor'];
        $excludeDirs = ['.git', 'node_modules', 'Backup'];

        foreach ($directories as $dir) {
            $src = $projectRoot . DIRECTORY_SEPARATOR . $dir;
            if (is_dir($src)) {
                $this->copyDirectory($src, $tempDirPath . DIRECTORY_SEPARATOR . $dir, $excludeDirs);
            }
        }

        foreach (scandir($projectRoot) as $item) {
            if ($item === '.' || $item === '..') continue;
            $src = $projectRoot . DIRECTORY_SEPARATOR . $item;
            if (is_file($src)) @copy($src, $tempDirPath . DIRECTORY_SEPARATOR . $item);
        }

        $this->exportDatabaseToFile($tempDirPath . DIRECTORY_SEPARATOR . 'database.sql');

        $zip = new ZipArchive();
        if ($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $this->addDirectoryToZip($zip, $tempDirPath, $tempFolderName);
            $zip->setArchiveComment("Backup created at " . date('Y-m-d H:i:s'));
            $zip->close();
        } else {
            $this->removeDirectory($tempDirPath);
            return ['success' => false, 'message' => 'ZIP 创建失败'];
        }

        $this->removeDirectory($tempDirPath);

        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $downloadUrl = $protocol . '://' . $host . '/vendor/Backup/' . $backupFilename;

        // 检查并删除旧备份
        $this->cleanupOldBackups();

        return [
            'success' => true,
            'message' => '备份创建成功',
            'file' => $backupFilename,
            'size' => $this->formatBytes(filesize($backupPath)),
            'download_url' => $downloadUrl,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * 获取备份列表
     */
    public function getBackupList() {
        $backups = [];

        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        foreach (glob($this->backupDir . DIRECTORY_SEPARATOR . 'backup_*.zip') as $file) {
            $filename = basename($file);
            $downloadUrl = $protocol . '://' . $host . '/vendor/Backup/' . $filename;
            $backups[] = [
                'filename' => $filename,
                'size' => $this->formatBytes(filesize($file)),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                'download_url' => $downloadUrl
            ];
        }

        usort($backups, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        return [
            'backups' => $backups,
            'count' => count($backups)
        ];
    }

    /**
     * 删除备份
     */
    public function deleteBackup($filename = 'all') {
        if ($filename === 'all') {
            $deletedCount = 0;
            $files = glob($this->backupDir . DIRECTORY_SEPARATOR . 'backup_*.zip');
            foreach ($files as $file) {
                if (is_file($file) && @unlink($file)) {
                    $deletedCount++;
                }
            }
            return [
                'success' => true,
                'message' => "已删除全部备份，共 {$deletedCount} 个文件"
            ];
        } else {
            $target = $this->backupDir . DIRECTORY_SEPARATOR . $filename;
            if (preg_match('/^backup_\d{8}_\d{6}\.zip$/', $filename) && file_exists($target)) {
                @unlink($target);
                return ['success' => true, 'message' => '删除成功'];
            } else {
                return ['success' => false, 'message' => '文件不存在'];
            }
        }
    }

    /**
     * 清理旧备份
     */
    private function cleanupOldBackups() {
        $files = glob($this->backupDir . DIRECTORY_SEPARATOR . 'backup_*.zip');
        if (count($files) > $this->maxBackups) {
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });

            $toDelete = count($files) - $this->maxBackups;
            for ($i = 0; $i < $toDelete; $i++) {
                @unlink($files[$i]);
            }
        }
    }

    /**
     * 设置最大备份数
     */
    public function setMaxBackups($max) {
        $this->maxBackups = intval($max);
    }
}