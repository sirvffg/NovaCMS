<?php
/**
 * Nova JSON API: Nova_Cron
 *
 * 定时任务管理类，提供插件注册定时任务、调度执行的统一机制。
 *
 * 工作模式：
 *   1. CLI / 面板定时任务：由宝塔/1Panel 后台定时调用 vendor/public/cron/cron.php
 *      - CLI：  php /www/wwwroot/站点/vendor/public/cron/cron.php
 *      - HTTP： https://你的域名/vendor/public/cron/cron.php
 *   2. 虚拟主机（无 cron）：由前台 index.php 在每次访问时调用 maybe_run_on_visit()，
 *      异步触发 cron.php 在独立进程执行（非阻塞），限频 60s 避免频繁触发。
 *
 * 插件注册任务（在插件 init() 中调用，与 Nova_Hooks 一致）：
 *   Nova_Cron::register('backup_cleanup', 3600, [$this, 'cleanup'], '清理过期备份');
 *   参数：任务ID / 间隔秒数（最小60） / 回调 / 描述
 *
 * 任务状态存储于 cms_cron_tasks 表（首次调用时自动建表）：
 *   task_id VARCHAR(64) PRIMARY KEY
 *   last_run_at  DATETIME  上次执行时间
 *   last_status  VARCHAR  success / failed
 *   last_error   TEXT     失败时的异常信息
 *   locked_until DATETIME 锁定截止时间，防止并发执行
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_Cron {

    /** @var array 已注册任务 [id => ['id','interval','callback','description']] */
    protected static $tasks = [];

    /** @var string|null 状态目录（限频标记） */
    private static $stateDir = null;

    /** @var Nova_DB|null */
    private static $db = null;

    /** @var bool 表是否已确保创建 */
    private static $tableReady = false;

    /** @var bool 访问触发是否启用（虚拟主机模式开关，默认启用） */
    private static $visitTriggerEnabled = true;

    /**
     * 注册定时任务
     *
     * @param string   $id          任务唯一ID（字母开头，仅含字母/数字/下划线/连字符）
     * @param int      $interval   执行间隔（秒），最小 60
     * @param callable $callback   回调（无参数），失败抛异常即可
     * @param string   $description 任务描述
     * @return bool
     */
    public static function register($id, $interval, $callback, $description = '') {
        $id = (string)$id;
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]*$/', $id)) {
            return false;
        }
        $interval = max(60, (int)$interval);
        if (!is_callable($callback)) {
            return false;
        }
        self::$tasks[$id] = [
            'id'          => $id,
            'interval'    => $interval,
            'callback'    => $callback,
            'description' => (string)$description,
        ];
        return true;
    }

    /**
     * 注销任务
     */
    public static function unregister($id) {
        unset(self::$tasks[$id]);
    }

    /**
     * 获取所有已注册任务（仅运行期注册的任务元数据，不含执行状态）
     */
    public static function get_tasks() {
        return array_values(self::$tasks);
    }

    /**
     * 执行所有到期任务
     *
     * @param bool $force 是否强制执行（忽略间隔与锁，慎用）
     * @return array 每个任务的执行结果
     */
    public static function run_due($force = false) {
        self::ensure_table();
        $results = [];
        foreach (array_keys(self::$tasks) as $id) {
            $results[] = self::run_one($id, $force);
        }
        return $results;
    }

    /**
     * 执行单个任务
     *
     * @param string $id
     * @param bool   $force
     * @return array ['id','status','reason'?,,'error'?]
     *               status: success | failed | skipped
     */
    public static function run_one($id, $force = false) {
        self::ensure_table();
        if (!isset(self::$tasks[$id])) {
            return ['id' => $id, 'status' => 'skipped', 'reason' => '未注册'];
        }
        $task    = self::$tasks[$id];
        $now     = time();
        $nowSql  = date('Y-m-d H:i:s', $now);
        $db      = self::db();
        $pdo     = $db->get_pdo();

        // 确保行存在（INSERT IGNORE，并发安全）
        $ins = $pdo->prepare("INSERT IGNORE INTO cms_cron_tasks (task_id) VALUES (?)");
        $ins->execute([$id]);

        // 读取当前状态
        $row = $db->get_row("SELECT last_run_at, locked_until FROM cms_cron_tasks WHERE task_id = ?", [$id]);

        // 锁定检查：被其他进程锁定且未过期
        if (!$force && $row && !empty($row['locked_until'])) {
            $lockTs = strtotime((string)$row['locked_until']);
            if ($lockTs !== false && $lockTs > $now) {
                return ['id' => $id, 'status' => 'skipped', 'reason' => '已锁定'];
            }
        }

        // 到期检查：未到间隔则跳过
        if (!$force && $row && !empty($row['last_run_at'])) {
            $lastTs = strtotime((string)$row['last_run_at']);
            if ($lastTs !== false && ($now - $lastTs) < $task['interval']) {
                return ['id' => $id, 'status' => 'skipped', 'reason' => '未到期'];
            }
        }

        // 原子获取锁：UPDATE 仅当锁为空或已过期时成功
        $lockTtl    = max(60, min(1800, $task['interval']));
        $lockUntil  = date('Y-m-d H:i:s', $now + $lockTtl);
        $lockStmt   = $pdo->prepare(
            "UPDATE cms_cron_tasks SET locked_until = ? WHERE task_id = ? AND (locked_until IS NULL OR locked_until < ?)"
        );
        $lockStmt->execute([$lockUntil, $id, $nowSql]);
        if ($lockStmt->rowCount() === 0) {
            // 并发竞争中失败
            return ['id' => $id, 'status' => 'skipped', 'reason' => '并发竞争失败'];
        }

        // 执行回调
        $status = 'success';
        $error  = null;
        try {
            call_user_func($task['callback']);
        } catch (Throwable $e) {
            $status = 'failed';
            $error  = $e->getMessage();
            error_log("[Nova_Cron][{$id}] failed: {$error}");
        }

        // 写入结果并释放锁
        $db->update('cms_cron_tasks', [
            'last_run_at' => $nowSql,
            'last_status' => $status,
            'last_error'  => $error,
            'locked_until'=> null,
        ], ['task_id' => $id]);

        $result = ['id' => $id, 'status' => $status];
        if ($error !== null) $result['error'] = $error;
        return $result;
    }

    /**
     * 访问触发执行（虚拟主机模式，非阻塞）
     *
     * 由 index.php 在每次访问时调用。不直接执行任务，而是异步触发
     * vendor/public/cron/cron.php 在独立进程中运行，当前请求立即返回不阻塞访客。
     * 任务并发由 run_one() 的 DB 原子锁兜底，触发端无需再加文件锁。
     *
     * 触发方式（按优先级尝试，任一成功即返回）：
     *   1. 非阻塞 HTTP 自调用（fsockopen，fire-and-forget）—— 适用于绝大多数环境
     *   2. 后台 CLI exec（PHP_BINARY + &）—— 适用于允许 exec 的 Linux
     *   3. 兜底：注册到 shutdown，借助 fastcgi_finish_request 先返回响应再执行
     */
    public static function maybe_run_on_visit() {
        // 访问触发被禁用（服务器模式下由面板 cron 接管，跳过）
        if (!self::$visitTriggerEnabled) return;

        // 无任务注册时直接返回，避免任何开销
        if (empty(self::$tasks)) return;

        // 限频：60s 内只触发一次（基于文件 stat，零 DB 开销）
        $dir      = self::state_dir();
        $throttle = $dir . '/visit-throttle';
        if (is_file($throttle) && (time() - filemtime($throttle) < 60)) {
            return;
        }
        @touch($throttle);

        // 1) 异步 HTTP 自调用 cron.php（独立进程，完全不阻塞）
        if (self::async_fire_http()) return;

        // 2) 后台 CLI exec cron.php（独立进程，Linux + 允许 exec 时可用）
        if (self::async_fire_cli()) return;

        // 3) 兜底：注册到 shutdown，尽可能先返回响应再执行（PHP-FPM 不阻塞）
        register_shutdown_function(static function () {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                self::run_due();
            } catch (Throwable $e) {
                error_log('[Nova_Cron][visit-fallback] ' . $e->getMessage());
            }
        });
    }

    /**
     * 启用访问触发（虚拟主机模式）：由访客请求异步触发 cron.php
     */
    public static function enable_visit_trigger() {
        self::$visitTriggerEnabled = true;
    }

    /**
     * 禁用访问触发（服务器模式）：改由面板定时任务调用 cron.php
     */
    public static function disable_visit_trigger() {
        self::$visitTriggerEnabled = false;
    }

    /**
     * 查询访问触发是否启用
     */
    public static function is_visit_trigger_enabled() {
        return self::$visitTriggerEnabled;
    }

    /**
     * 异步触发：发起对 cron.php 的非阻塞 HTTP 自调用
     * 不等待响应，写完请求即关闭连接。服务端 PHP 仍会继续执行完任务。
     * 成功返回 true，失败返回 false。
     */
    private static function async_fire_http() {
        if (PHP_SAPI === 'cli') return false;
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') return false;

        $url      = '/vendor/public/cron/cron.php';
        $isHttps  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
        $scheme   = $isHttps ? 'ssl://' : '';
        $port     = $isHttps ? 443 : (int)($_SERVER['SERVER_PORT'] ?? 80);
        if ($port <= 0 || $port > 65535) return false;

        $errno  = 0;
        $errstr = '';
        $fp = @fsockopen($scheme . $host, $port, $errno, $errstr, 1);
        if (!$fp) return false;

        stream_set_timeout($fp, 0, 5000); // 5s 写超时，写完即关
        $out = "GET {$url} HTTP/1.1\r\n"
             . "Host: {$host}\r\n"
             . "Connection: close\r\n"
             . "User-Agent: NovaCMS-Cron/1.0\r\n"
             . "\r\n";
        @fwrite($fp, $out);
        @fclose($fp);
        return true;
    }

    /**
     * 异步触发：后台 CLI 执行 cron.php（Linux，需允许 exec）
     * 通过 shell & 后台运行，当前进程立即返回。
     */
    private static function async_fire_cli() {
        if (PHP_SAPI === 'cli') return false;
        if (PHP_OS_FAMILY === 'Windows') return false; // 后台语法不兼容
        if (!function_exists('exec')) return false;

        $phpBin = PHP_BINARY ?: '';
        if ($phpBin === '' || !@is_executable($phpBin)) {
            $phpBin = 'php'; // 回退到 PATH
        }
        // cron.php 位于 vendor/public/cron/（项目根 → vendor → public → cron）
        $script = escapeshellarg(dirname(__DIR__, 4) . '/vendor/public/cron/cron.php');
        $cmd = escapeshellarg($phpBin) . ' ' . $script . ' > /dev/null 2>&1 &';
        @exec($cmd);
        return true;
    }

    /**
     * 获取任务上次执行信息
     */
    public static function get_last_run($id) {
        self::ensure_table();
        return self::db()->get_row("SELECT * FROM cms_cron_tasks WHERE task_id = ?", [$id]);
    }

    /**
     * 检查任务是否到期
     */
    public static function is_due($id) {
        if (!isset(self::$tasks[$id])) return false;
        self::ensure_table();
        $row = self::db()->get_row("SELECT last_run_at FROM cms_cron_tasks WHERE task_id = ?", [$id]);
        if (!$row || empty($row['last_run_at'])) return true;
        $lastTs = strtotime((string)$row['last_run_at']);
        if ($lastTs === false) return true;
        return (time() - $lastTs) >= self::$tasks[$id]['interval'];
    }

    // ── 内部 ──

    private static function db() {
        if (self::$db === null) {
            self::$db = new Nova_DB();
        }
        return self::$db;
    }

    private static function ensure_table() {
        if (self::$tableReady) return;
        self::$tableReady = true;
        try {
            self::db()->query(
                "CREATE TABLE IF NOT EXISTS cms_cron_tasks (
                  task_id VARCHAR(64) NOT NULL PRIMARY KEY,
                  last_run_at DATETIME NULL,
                  last_status VARCHAR(20) NULL,
                  last_error TEXT NULL,
                  locked_until DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            error_log('[Nova_Cron] ensure_table failed: ' . $e->getMessage());
        }
    }

    private static function state_dir() {
        if (self::$stateDir !== null) return self::$stateDir;
        // 跟随 Nova_DB_Cache 约定：运行期状态文件存放于项目根 cache/ 目录
        $dir = dirname(__DIR__, 4) . '/cache/cron';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            // 写入 .htaccess 禁止 HTTP 访问状态文件（限频标记）
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        self::$stateDir = $dir;
        return $dir;
    }
}
