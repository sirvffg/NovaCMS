<?php
/**
 * 图片映射表管理 - 前台只读版本
 * 分散存储在 uploads 各子目录中
 * 
 * 此文件为前台专用，只包含读取功能
 * 后台写入功能请使用 admin/includes/image_mapper.php
 */

class ImageMapper {
    private static $currentDir = null;
    private static $map = [];

    /**
     * 初始化当前目录的映射表
     * @param string $localPath 本地文件路径
     */
    private static function init($localPath = '') {
        if (empty($localPath)) {
            self::$currentDir = dirname(__DIR__) . '/uploads';
        } else {
            // 获取文件所在目录
            $dir = dirname($localPath);
            // 确保目录在 uploads 下
            $uploadsDir = dirname(__DIR__) . '/uploads';
            if (strpos($dir, $uploadsDir) === 0) {
                self::$currentDir = $dir;
            } else {
                self::$currentDir = $uploadsDir;
            }
        }

        self::load();
    }

    /**
     * 获取映射文件路径
     */
    private static function getMapFilePath() {
        return self::$currentDir . '/.image_map.json';
    }

    /**
     * 加载映射表
     */
    private static function load() {
        $mapFile = self::getMapFilePath();
        if (file_exists($mapFile)) {
            $content = file_get_contents($mapFile);
            self::$map = json_decode($content, true) ?: [];
        } else {
            self::$map = [];
        }
    }

    /**
     * 获取文件在映射表中的唯一键
     */
    private static function getKey($localPath) {
        $normalizedPath = str_replace('\\', '/', $localPath);
        return md5($normalizedPath);
    }

    /**
     * 通过URL查找映射信息
     * @param string $url 图片URL（如 /uploads/posts/xxx.png）
     */
    public static function getByUrl($url) {
        $url = str_replace('\\', '/', $url);
        
        // 规范化 URL：统一处理有无 / 前缀的情况
        $normalizedUrl = ltrim($url, '/');
        
        // 获取 uploads 目录
        $uploadsDir = str_replace('\\', '/', dirname(__DIR__)) . '/uploads';
        
        // 提取相对路径和子目录
        $relativePath = ltrim(str_replace('/uploads/', '', $normalizedUrl), '/');
        $parts = explode('/', $relativePath);
        array_pop($parts); // 移除文件名，得到子目录
        $subDir = implode('/', $parts);
        
        // 构建目标目录
        $targetDir = $uploadsDir;
        if (!empty($subDir)) {
            $targetDir = $uploadsDir . '/' . $subDir;
        }
        $targetDir = str_replace('\\', '/', $targetDir);
        
        // 用规范化后的 URL 计算 key
        $key = self::getKey($uploadsDir . '/' . $normalizedUrl);
        
        // 初始化并查找
        self::init($targetDir);
        if (isset(self::$map[$key])) {
            return self::$map[$key];
        }
        
        // 尝试在 uploads 根目录查找
        self::init($uploadsDir);
        if (isset(self::$map[$key])) {
            return self::$map[$key];
        }
        
        // 遍历所有映射文件，用规范化后的 URL 进行比较
        $allMap = self::getAll();
        foreach ($allMap as $item) {
            if (!empty($item['local_url'])) {
                $itemNormalized = ltrim($item['local_url'], '/');
                if ($itemNormalized === $normalizedUrl) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * 获取最终URL（根据配置返回本地或图床URL）
     * @param string $localUrl 本地URL
     * @param bool $useImageBed 是否使用图床
     */
    public static function getFinalUrl($localUrl, $useImageBed = false) {
        $info = self::getByUrl($localUrl);

        if ($info && $useImageBed && !empty($info['image_bed_url'])) {
            return $info['image_bed_url'];
        }

        return $localUrl;
    }

    /**
     * 获取所有映射
     */
    public static function getAll() {
        $uploadsDir = dirname(__DIR__) . '/uploads';
        $all = [];

        // 递归遍历所有子目录
        if (is_dir($uploadsDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $dir) {
                if ($dir->isDir()) {
                    $mapFile = $dir->getPathname() . '/.image_map.json';
                    if (file_exists($mapFile)) {
                        $content = file_get_contents($mapFile);
                        $map = json_decode($content, true) ?: [];
                        $all = array_merge($all, $map);
                    }
                }
            }
        }

        return $all;
    }

    /**
     * 获取映射表统计
     */
    public static function getStats() {
        $uploadsDir = dirname(__DIR__) . '/uploads';
        $total = 0;
        $withImageBed = 0;
        $withoutImageBed = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $dir) {
            if ($dir->isDir()) {
                $mapFile = $dir->getPathname() . '/.image_map.json';
                if (file_exists($mapFile)) {
                    $content = file_get_contents($mapFile);
                    $map = json_decode($content, true) ?: [];
                    foreach ($map as $item) {
                        $total++;
                        if (!empty($item['image_bed_url'])) {
                            $withImageBed++;
                        } else {
                            $withoutImageBed++;
                        }
                    }
                }
            }
        }

        return [
            'total' => $total,
            'with_image_bed' => $withImageBed,
            'without_image_bed' => $withoutImageBed
        ];
    }
}
