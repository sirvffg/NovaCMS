<?php
/**
 * Nova JSON API: Nova_Plugin_Registry
 *
 * 插件注册表：扫描已安装插件、解析 plugin.json、自动生成唯一 id。
 * init.php 和 admin/plugins.php 共用此类，确保 id 生成逻辑一致。
 *
 * 插件目录规范：
 *   vendor/nova-plugins/{slug}/
 *     ├── plugin/            插件代码目录
 *     │   └── plugin.php     入口文件（默认）
 *     ├── plugin.json        元数据 + 系统 id
 *     └── LICENSE            许可证
 */

defined('NOVA_API') or exit('禁止直接访问');

class Nova_Plugin_Registry {

    /** @var array|null 已启用插件 id 列表缓存 */
    protected static $cached_active_ids = null;

    /** @var array|null 已扫描插件缓存 */
    protected static $cached_plugins = null;

    /**
     * 扫描所有已安装的插件，自动为缺失 id 的插件生成并写入 id
     *
     * @param bool $force 是否强制刷新缓存
     * @return array 插件信息数组，每项包含：
     *   - slug:             目录名
     *   - id:               唯一识别符（p_ + 16位十六进制）
     *   - name, version, description, author, author_uri, uri
     *   - entry:            入口文件相对路径
     *   - entry_path:       入口文件绝对路径
     *   - plugin_dir:       插件根目录绝对路径
     *   - min_nova_version: 最低 NovaCMS 版本要求
     */
    public static function scan_all($force = false) {
        if (!$force && self::$cached_plugins !== null) {
            return self::$cached_plugins;
        }

        $pluginsDir = dirname(__DIR__, 3) . '/nova-plugins';
        $plugins = [];

        if (!is_dir($pluginsDir)) {
            self::$cached_plugins = $plugins;
            return $plugins;
        }

        foreach (glob($pluginsDir . '/*/plugin.json') as $jsonFile) {
            $pluginDir = dirname($jsonFile);
            $slug = basename($pluginDir);
            $info = self::read_json($jsonFile);
            if ($info === null) {
                continue;
            }

            // 系统安装识别：自动生成唯一 id 并写回 plugin.json
            if (empty($info['id'])) {
                $info['id'] = self::generate_id();
                self::write_json($jsonFile, $info);
            }

            $entry = !empty($info['entry']) ? $info['entry'] : 'plugin/plugin.php';
            $entryPath = $pluginDir . '/' . $entry;

            $plugins[] = [
                'slug'             => $slug,
                'id'               => $info['id'],
                'name'             => isset($info['name']) && $info['name'] !== '' ? $info['name'] : $slug,
                'version'          => $info['version'] ?? '1.0.0',
                'description'      => $info['description'] ?? '',
                'author'           => $info['author'] ?? '',
                'author_uri'       => $info['author_uri'] ?? '',
                'uri'              => $info['uri'] ?? '',
                'entry'            => $entry,
                'entry_path'       => $entryPath,
                'plugin_dir'       => $pluginDir,
                'min_nova_version' => $info['min_nova_version'] ?? '',
            ];
        }

        self::$cached_plugins = $plugins;
        return $plugins;
    }

    /**
     * 根据 id 或 slug 查找插件信息
     *
     * @param string $key 插件 id 或 slug
     * @return array|null
     */
    public static function find_plugin($key) {
        $plugins = self::scan_all();
        foreach ($plugins as $p) {
            if ($p['id'] === $key || $p['slug'] === $key) {
                return $p;
            }
        }
        return null;
    }

    /**
     * 获取已启用的插件 id 列表
     *
     * @param bool $force 是否强制刷新缓存
     * @return array|null 返回数组；若返回 null 表示未配置（全部启用）
     */
    public static function get_active_plugin_ids($force = false) {
        if (!$force && self::$cached_active_ids !== null) {
            return self::$cached_active_ids;
        }

        $result = null;
        try {
            if (class_exists('Nova_DB')) {
                $db = new Nova_DB();
            } else {
                $baseDir = dirname(__DIR__, 4);
                require_once $baseDir . '/config/database.php';
                $db = getDB();
            }
            $stmt = $db->query("SELECT active_plugins FROM website_config LIMIT 1");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['active_plugins'])) {
                $decoded = json_decode($row['active_plugins'], true);
                if (is_array($decoded)) {
                    $result = $decoded;
                }
            }
        } catch (Exception $e) {
            // 字段不存在或查询失败时，保持全部启用
        }

        self::$cached_active_ids = $result;
        return $result;
    }

    /**
     * 检查插件是否已启用
     *
     * @param string $pluginId 插件 id
     * @return bool
     */
    public static function is_plugin_active($pluginId) {
        $activeIds = self::get_active_plugin_ids();
        // 若未配置（null），表示全部启用
        if ($activeIds === null) {
            return true;
        }
        return in_array($pluginId, $activeIds, true);
    }

    /**
     * 清除缓存（例如在启用/禁用插件后调用）
     */
    public static function clear_cache() {
        self::$cached_active_ids = null;
        self::$cached_plugins = null;
    }

    /**
     * 生成唯一插件 ID
     * 格式：p_ + 16 位十六进制随机字符串
     *
     * @return string
     */
    public static function generate_id() {
        return 'p_' . bin2hex(random_bytes(8));
    }

    /**
     * 读取 plugin.json 并解析为关联数组
     *
     * @param string $file plugin.json 绝对路径
     * @return array|null
     */
    protected static function read_json($file) {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }
        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    /**
     * 将数据写回 plugin.json（带文件锁，保留可读格式）
     *
     * @param string $file
     * @param array  $data
     * @return bool
     */
    protected static function write_json($file, array $data) {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $result = @file_put_contents($file, $json, LOCK_EX);
        return $result !== false;
    }
}
