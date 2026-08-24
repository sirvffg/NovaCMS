<?php
/**
 * Nova JSON API: Nova_DB_Cache
 *
 * 数据库查询缓存类，提供文件级查询结果缓存。
 * 适用于频繁读取但不常变更的数据，减少数据库压力。
 *
 * 用法：
 *   $cache = new Nova_DB_Cache();
 *   $result = $cache->get('my_key', function() use ($db) {
 *       return $db->get_results("SELECT * FROM instant");
 *   }, 3600);
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_DB_Cache {

    protected $cacheDir;
    protected $defaultTtl = 300; // 默认缓存 5 分钟

    public function __construct($cacheDir = null) {
        $this->cacheDir = $cacheDir ?: dirname(__DIR__, 4) . '/cache/db';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * 获取缓存
     * @param string   $key      缓存键名
     * @param callable $callback 缓存不存在时生成数据的回调函数
     * @param int      $ttl      缓存有效期（秒），默认 300
     * @return mixed
     */
    public function get($key, callable $callback = null, $ttl = null) {
        $data = $this->fetch($key);
        if ($data !== false) {
            return $data;
        }
        if ($callback !== null) {
            $data = call_user_func($callback);
            $this->set($key, $data, $ttl);
        }
        return $data;
    }

    /**
     * 写入缓存
     * @param string $key   缓存键名
     * @param mixed  $data  缓存数据
     * @param int    $ttl   缓存有效期（秒），默认 300
     * @return bool
     */
    public function set($key, $data, $ttl = null) {
        $ttl = $ttl !== null ? (int)$ttl : $this->defaultTtl;
        $cacheData = [
            'expires' => time() + $ttl,
            'data'    => $data,
        ];
        $file = $this->getFilePath($key);
        return (bool)@file_put_contents($file, serialize($cacheData), LOCK_EX);
    }

    /**
     * 删除指定缓存
     * @param string $key 缓存键名
     * @return bool
     */
    public function delete($key) {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return true;
    }

    /**
     * 清空所有数据库缓存
     * @return bool
     */
    public function flush() {
        $files = glob($this->cacheDir . '/*.cache');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        return true;
    }

    /**
     * 检查缓存是否存在且有效
     * @param string $key 缓存键名
     * @return bool
     */
    public function has($key) {
        return $this->fetch($key) !== false;
    }

    /**
     * 设置默认 TTL
     * @param int $ttl
     */
    public function setDefaultTtl($ttl) {
        $this->defaultTtl = (int)$ttl;
    }

    // ── 内部方法 ──

    /**
     * 读取缓存文件并检查有效期
     */
    protected function fetch($key) {
        $file = $this->getFilePath($key);
        if (!file_exists($file)) {
            return false;
        }
        $cacheData = @unserialize(@file_get_contents($file));
        if ($cacheData === false || !isset($cacheData['expires'])) {
            return false;
        }
        if (time() > $cacheData['expires']) {
            @unlink($file);
            return false;
        }
        return $cacheData['data'];
    }

    /**
     * 根据键名生成文件路径
     */
    protected function getFilePath($key) {
        $hash = md5($key);
        return $this->cacheDir . '/' . $hash . '.cache';
    }
}
