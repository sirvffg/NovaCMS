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

    /**
     * 扫描所有已安装的插件，自动为缺失 id 的插件生成并写入 id
     *
     * @return array 插件信息数组，每项包含：
     *   - slug:             目录名
     *   - id:               唯一识别符（p_ + 16位十六进制）
     *   - name, version, description, author, author_uri, uri
     *   - entry:            入口文件相对路径
     *   - entry_path:       入口文件绝对路径
     *   - plugin_dir:       插件根目录绝对路径
     *   - min_nova_version: 最低 NovaCMS 版本要求
     */
    public static function scan_all() {
        $pluginsDir = dirname(__DIR__, 3) . '/nova-plugins';
        $plugins = [];

        if (!is_dir($pluginsDir)) {
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

        return $plugins;
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
