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
     * 扫描插件目录
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

            // 插件目录名只允许字母、数字、-、_
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $dir)) {
                continue;
            }

            $pluginDir = $this->pluginsDir . DIRECTORY_SEPARATOR . $dir;

            if (!is_dir($pluginDir)) {
                continue;
            }

            $jsonFile = $pluginDir . DIRECTORY_SEPARATOR . 'plugin.json';

            if (!is_file($jsonFile)) {
                continue;
            }

            $json = file_get_contents($jsonFile);
            $info = json_decode($json, true);

            if (!is_array($info)) {
                continue;
            }

            $slug = $info['slug'] ?? $dir;

            // plugin.json 里的 slug 必须和文件夹名字一样
            if ($slug !== $dir) {
                continue;
            }

            $entry = $info['entry'] ?? 'plugin.php';

            // 防止 ../ 路径穿越
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
                'slug' => $slug,
                'name' => $info['name'] ?? $slug,
                'version' => $info['version'] ?? '1.0.0',
                'author' => $info['author'] ?? '',
                'description' => $info['description'] ?? '',
                'entry' => $entryFile
            ];
        }

        return $plugins;
    }

    /**
     * 把硬盘上的插件同步到数据库
     */
    public function sync()
    {
        $plugins = $this->discover();

        foreach ($plugins as $plugin) {
            $check = $this->db->prepare(
                "SELECT slug FROM nova_plugins WHERE slug = ? LIMIT 1"
            );

            $check->execute([$plugin['slug']]);

            if ($check->fetch()) {
                $stmt = $this->db->prepare("
                    UPDATE nova_plugins
                    SET name = ?,
                        version = ?,
                        author = ?,
                        description = ?
                    WHERE slug = ?
                ");

                $stmt->execute([
                    $plugin['name'],
                    $plugin['version'],
                    $plugin['author'],
                    $plugin['description'],
                    $plugin['slug']
                ]);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO nova_plugins
                    (
                        slug,
                        name,
                        version,
                        author,
                        description,
                        enabled
                    )
                    VALUES (?, ?, ?, ?, ?, 0)
                ");

                $stmt->execute([
                    $plugin['slug'],
                    $plugin['name'],
                    $plugin['version'],
                    $plugin['author'],
                    $plugin['description']
                ]);
            }
        }

        return $plugins;
    }

    /**
     * 获取插件列表
     */
    public function getPlugins()
    {
        $plugins = $this->sync();

        $stmt = $this->db->query("
            SELECT slug, enabled, activated_at, last_error
            FROM nova_plugins
        ");

        $states = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $states[$row['slug']] = $row;
        }

        foreach ($plugins as $slug => &$plugin) {
            $state = $states[$slug] ?? [];

            $plugin['enabled'] =
                isset($state['enabled'])
                && (int)$state['enabled'] === 1;

            $plugin['activated_at'] =
                $state['activated_at'] ?? null;

            $plugin['last_error'] =
                $state['last_error'] ?? null;
        }

        unset($plugin);

        return $plugins;
    }

    /**
     * 插件是否启用
     */
    public function isEnabled($slug)
    {
        $stmt = $this->db->prepare("
            SELECT enabled
            FROM nova_plugins
            WHERE slug = ?
            LIMIT 1
        ");

        $stmt->execute([$slug]);

        return (int)$stmt->fetchColumn() === 1;
    }

    /**
     * 启用或禁用插件
     */
    public function setEnabled($slug, $enabled)
    {
        $plugins = $this->sync();

        if (!isset($plugins[$slug])) {
            throw new RuntimeException('插件不存在');
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

        $stmt->execute([$slug]);
    }

    /**
     * 加载所有已启用插件
     */
    public function loadEnabled()
    {
        $plugins = $this->sync();

        foreach ($plugins as $slug => $plugin) {
            if (!$this->isEnabled($slug)) {
                continue;
            }

            try {
                require_once $plugin['entry'];

                $this->clearError($slug);
            } catch (Throwable $e) {
                $this->saveError($slug, $e->getMessage());

                error_log(
                    '[NovaCMS Plugin][' .
                    $slug .
                    '] ' .
                    $e->getMessage()
                );
            }
        }
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
}