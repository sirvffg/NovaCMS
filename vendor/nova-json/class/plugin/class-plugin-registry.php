<?php
/**
 * Nova JSON API: Nova_Plugin_Registry
 *
 * 插件注册表：扫描已安装插件、解析 plugin.json。
 * init.php 和 admin/plugins.php 共用此类。
 *
 * 插件 id 规范（自 NovaCMS 1.1 起）：
 *   - 由开发者在 plugin.json 中手动填写，必须为英文（字母、数字、下划线、连字符）
 *   - 排在 plugin.json 中 name 字段之前
 *   - 若未填写，系统自动以目录名（slug）作为 id 回退
 *   - 启用/禁用状态以此 id 为准
 *
 * 插件目录规范：
 *   vendor/nova-plugins/{slug}/
 *     ├── plugin/            插件代码目录
 *     │   └── plugin.php     入口文件（默认）
 *     ├── plugin.json        元数据（含 id）
 *     └── LICENSE            许可证
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_Plugin_Registry {

    /** @var array|null 已启用插件 id 列表缓存 */
    protected static $cached_active_ids = null;

    /** @var array|null 已扫描插件缓存 */
    protected static $cached_plugins = null;

    /**
     * 扫描所有已安装的插件
     *
     * @param bool $force 是否强制刷新缓存
     * @return array 插件信息数组，每项包含：
     *   - id:               插件唯一识别符（英文，由开发者填写或以 slug 回退）
     *   - slug:             目录名
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

            // id 处理：开发者应在 plugin.json 中填写英文 id
            // 若未填写，以目录名（slug）作为 id 回退并写回
            if (empty($info['id'])) {
                $info = array_merge(['id' => $slug], $info);
                self::write_json($jsonFile, $info);
            }

             // 检测重复 id：标记但不跳过，由后台管理页负责删除重复目录
            $isDuplicate = false;
            foreach ($plugins as $existing) {
                if ($existing['id'] === $info['id']) {
                    $isDuplicate = true;
                    break;
                }
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
                'duplicate'        => $isDuplicate,
                'page_routes'      => $info['page_routes'] ?? [],
                'sidebar'          => array_key_exists('sidebar', $info) ? (bool)$info['sidebar'] : true,
                'config_path'      => $info['config_path'] ?? '',
                'detail_tab'       => $info['detail_tab'] ?? '',
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
     * 解析插件配置文件路径
     *
     * 默认为 plugin_dir/config.json。若 plugin.json 声明了 config_path
     * （相对 plugin_dir 的路径），则解析到该路径——用于将配置存放于插件目录之外
     * （如 vendor/public/cron/），防止误删插件时丢失配置。
     * 解析结果必须位于项目根目录内，否则回退默认路径。
     *
     * @param array $plugin scan_all() 返回的插件信息
     * @return string 配置文件绝对路径
     */
    public static function resolve_config_file(array $plugin) {
        $default = $plugin['plugin_dir'] . '/config.json';
        if (empty($plugin['config_path'])) {
            return $default;
        }
        // 拼接相对路径并对父目录取 realpath（文件可能尚未创建）
        $target    = $plugin['plugin_dir'] . '/' . $plugin['config_path'];
        $realParent = realpath(dirname($target));
        if ($realParent === false) {
            return $default;
        }
        $resolved = $realParent . DIRECTORY_SEPARATOR . basename($target);

        // 安全校验：必须位于项目根目录内（带尾斜杠避免前缀绕过）
        $projectRoot = dirname(__DIR__, 4); // → 项目根
        $realRoot    = realpath($projectRoot);
        if ($realRoot === false) {
            return $default;
        }
        $resolvedNorm = str_replace('\\', '/', $resolved);
        $rootNorm     = str_replace('\\', '/', $realRoot);
        if (strpos($resolvedNorm . '/', $rootNorm . '/') !== 0) {
            return $default; // 路径逃逸项目根，回退默认
        }
        return $resolved;
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
                $pdo = (new Nova_DB())->get_pdo();
            } else {
                $baseDir = dirname(__DIR__, 4);
                require_once $baseDir . '/config/database.php';
                $pdo = getDB();
            }
            // 使用 PDO::query()（对 SELECT 返回 PDOStatement），避免误用 Nova_DB::query()（返回受影响行数 int）
            $stmt = $pdo->query("SELECT active_plugins FROM website_config LIMIT 1");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
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
     * 生成唯一插件 ID（已废弃）
     * 旧格式：p_ + 16 位十六进制随机字符串
     * 新版插件 id 由开发者手动填写英文标识符，此方法仅保留向后兼容
     *
     * @deprecated 不再默认调用，请开发者在 plugin.json 中手动填写英文 id
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
