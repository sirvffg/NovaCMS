<?php

defined('NOVA_API') or exit('禁止直接访问');

class Nova_Plugin_Manager
{
    private $db;
    private $pluginsDir;

    public function __construct(PDO $db, $pluginsDir)
    {
        $this->db = $db;
        $this->pluginsDir = rtrim($pluginsDir, '/\\');
    }

    /**
     * 扫描磁盘上的所有插件
     */
    public function discover()
    {
        $plugins = [];

        if (!is_dir($this->pluginsDir)) {
            return $plugins;
        }

        foreach (scandir($this->pluginsDir) as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            // 只允许安全的 slug
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $dir)) {
                continue;
            }

            $pluginDir = $this->pluginsDir . DIRECTORY_SEPARATOR . $dir;

            if (!is_dir($pluginDir)) {
                continue;
            }

            $manifestFile = $pluginDir . DIRECTORY_SEPARATOR . 'plugin.json';

            if (!is_file($manifestFile)) {
                continue;
            }

            $manifest = json_decode(
                file_get_contents($manifestFile),
                true
            );

            if (!is_array($manifest)) {
                continue;
            }

            $slug = $manifest['slug'] ?? $dir;

            // plugin.json 的 slug 必须和目录名一致
            if ($slug !== $dir) {
                continue;
            }

            $entry = $manifest['entry'] ?? 'plugin.php';

            // 禁止 ../ 之类的路径穿越
            if (
                strpos($entry, '..') !== false ||
                strpos($entry, "\0") !== false
            ) {
                continue;
            }

            $entryFile = $pluginDir . DIRECTORY_SEPARATOR . $entry;

            if (!is_file($entryFile)) {
                continue;
            }

            $plugins[$slug] = [
                'slug'        => $slug,
                'name'        => $manifest['name'] ?? $slug,
                'version'     => $manifest['version'] ?? '1.0.0',
                'author'      => $manifest['author'] ?? '',
                'description' => $manifest['description'] ?? '',
                'entry'       => $entryFile,
                'manifest'    => $manifest,
            ];
        }

        return $plugins;
    }

    /**
     * 将磁盘插件同步进数据库
     */
    public function sync()
    {
        $plugins = $this->discover();

        $sql = "
            INSERT INTO nova_plugins
                (slug, name, version, author, description)
            VALUES
                (:slug, :name, :version, :author, :description)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                version = VALUES(version),
                author = VALUES(author),
                description = VALUES(description)
        ";

        $stmt = $this->db->prepare($sql);

        foreach ($plugins as $plugin) {
            $stmt->execute([
                ':slug'        => $plugin['slug'],
                ':name'        => $plugin['name'],
                ':version'     => $plugin['version'],
                ':author'      => $plugin['author'],
                ':description' => $plugin['description'],
            ]);
        }

        return $plugins;
    }

    /**
     * 是否启用
     */
    public function isEnabled($slug)
    {
        $stmt = $this->db->prepare(
            "SELECT enabled FROM nova_plugins WHERE slug = ? LIMIT 1"
        );

        $stmt->execute([$slug]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * 启用插件
     */
    public function enable($slug)
    {
        if (!$this->pluginExists($slug)) {
            throw new RuntimeException('插件不存在');
        }

        $stmt = $this->db->prepare("
            UPDATE nova_plugins
            SET enabled = 1,
                activated_at = NOW(),
                last_error = NULL
            WHERE slug = ?
        ");

        return $stmt->execute([$slug]);
    }

    /**
     * 禁用插件
     */
    public function disable($slug)
    {
        $stmt = $this->db->prepare("
            UPDATE nova_plugins
            SET enabled = 0
            WHERE slug = ?
        ");

        return $stmt->execute([$slug]);
    }

    /**
     * 加载全部已启用插件
     */
    public function loadEnabled()
    {
        $plugins = $this->sync();

        foreach ($plugins as $slug => $plugin) {
            if (!$this->isEnabled($slug)) {
                continue;
            }

            try {
                $this->safeRequire(
                    $plugin['entry'],
                    dirname($plugin['entry'])
                );

                $this->clearError($slug);
            } catch (Throwable $e) {
                $this->saveError($slug, $e->getMessage());

                error_log(
                    "[NovaCMS Plugin][$slug] " . $e->getMessage()
                );
            }
        }
    }

    public function getPlugins()
    {
        $plugins = $this->sync();

        $stmt = $this->db->query("
            SELECT *
            FROM nova_plugins
            ORDER BY enabled DESC, name ASC
        ");

        $states = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $states[$row['slug']] = $row;
        }

        foreach ($plugins as $slug => &$plugin) {
            $state = $states[$slug] ?? [];

            $plugin['enabled'] = !empty($state['enabled']);
            $plugin['last_error'] = $state['last_error'] ?? null;
        }

        unset($plugin);

        return $plugins;
    }

    private function pluginExists($slug)
    {
        $plugins = $this->discover();

        return isset($plugins[$slug]);
    }

    private function safeRequire($file, $pluginDir)
    {
        $realFile = realpath($file);
        $realDir = realpath($pluginDir);

        if (!$realFile || !$realDir) {
            throw new RuntimeException('插件入口文件不存在');
        }

        $prefix = $realDir . DIRECTORY_SEPARATOR;

        if (strpos($realFile, $prefix) !== 0) {
            throw new RuntimeException('非法插件入口路径');
        }

        require_once $realFile;
    }

    private function saveError($slug, $message)
    {
        $stmt = $this->db->prepare("
            UPDATE nova_plugins
            SET last_error = ?
            WHERE slug = ?
        ");

        $stmt->execute([
            mb_substr($message, 0, 2000),
            $slug
        ]);
    }

    private function clearError($slug)
    {
        $stmt = $this->db->prepare("
            UPDATE nova_plugins
            SET last_error = NULL
            WHERE slug = ?
        ");

        $stmt->execute([$slug]);
    }
    /**
 * 启用 / 禁用插件
 *
 * @param string $slug 插件标识
 * @param bool $enabled true=启用，false=禁用
 */
public function setEnabled($slug, $enabled)
{
    // 先同步插件，确认插件确实存在
    $plugins = $this->sync();

    if (!isset($plugins[$slug])) {
        throw new RuntimeException('插件不存在：' . $slug);
    }

    if ($enabled) {
        $stmt = $this->db->prepare("
            UPDATE nova_plugins
            SET enabled = 1,
                activated_at = NOW(),
                last_error = NULL
            WHERE slug = ?
        ");
    } else {
        $stmt = $this->db->prepare("
            UPDATE nova_plugins
            SET enabled = 0
            WHERE slug = ?
        ");
    }

    if (!$stmt->execute([$slug])) {
        throw new RuntimeException('修改插件状态失败');
    }

    return true;
}
}