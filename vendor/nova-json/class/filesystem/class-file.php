<?php
/**
 * Nova JSON API: Nova_File
 *
 * 基础文件操作封装类，提供文件的读写、复制、移动、删除等操作。
 *
 * 用法：
 *   $file = new Nova_File('path/to/file.txt');
 *   $content = $file->read();
 *   $file->write('新内容');
 *   $file->delete();
 *
 *   Nova_File::exists('path/to/file.txt');
 *   Nova_File::copy('src.txt', 'dst.txt');
 */

defined('NOVA_API') or exit('禁止直接访问');

class Nova_File {

    protected $path = '';

    /**
     * @param string $path 文件路径
     */
    public function __construct($path) {
        $this->path = $path;
    }

    /**
     * 获取文件路径
     * @return string
     */
    public function path() {
        return $this->path;
    }

    /**
     * 获取文件名（带扩展名）
     * @return string
     */
    public function name() {
        return basename($this->path);
    }

    /**
     * 获取文件名（不含扩展名）
     * @return string
     */
    public function stem() {
        $info = pathinfo($this->path);
        return $info['filename'] ?? '';
    }

    /**
     * 获取扩展名（小写）
     * @return string
     */
    public function extension() {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    /**
     * 获取文件所在目录
     * @return string
     */
    public function dirname() {
        return dirname($this->path);
    }

    /**
     * 读取文件全部内容
     * @return string|false
     */
    public function read() {
        if (!$this->exists()) {
            return false;
        }
        return file_get_contents($this->path);
    }

    /**
     * 写入文件内容
     * @param string $content 要写入的内容
     * @param bool   $append  是否追加模式
     * @return bool
     */
    public function write($content, $append = false) {
        $flags = $append ? FILE_APPEND | LOCK_EX : LOCK_EX;
        return file_put_contents($this->path, $content, $flags) !== false;
    }

    /**
     * 追加内容到文件末尾
     * @param string $content
     * @return bool
     */
    public function append($content) {
        return $this->write($content, true);
    }

    /**
     * 删除文件
     * @return bool
     */
    public function delete() {
        if ($this->exists()) {
            return @unlink($this->path);
        }
        return true;
    }

    /**
     * 复制文件
     * @param string $destination 目标路径
     * @return bool
     */
    public function copy($destination) {
        $destDir = dirname($destination);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }
        return copy($this->path, $destination);
    }

    /**
     * 移动/重命名文件
     * @param string $destination 目标路径
     * @return bool
     */
    public function move($destination) {
        $destDir = dirname($destination);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }
        return rename($this->path, $destination);
    }

    /**
     * 获取文件大小（字节）
     * @return int|false
     */
    public function size() {
        return $this->exists() ? filesize($this->path) : false;
    }

    /**
     * 获取文件大小（格式化后）
     * @param int $decimals 小数位数
     * @return string
     */
    public function sizeForHumans($decimals = 2) {
        $bytes = $this->size();
        if ($bytes === false) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 4) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, $decimals) . ' ' . $units[$i];
    }

    /**
     * 获取文件 MIME 类型
     * @return string|false
     */
    public function mimeType() {
        if (!$this->exists()) return false;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $this->path);
        finfo_close($finfo);
        return $mime;
    }

    /**
     * 获取文件最后修改时间
     * @return int|false
     */
    public function lastModified() {
        return $this->exists() ? filemtime($this->path) : false;
    }

    /**
     * 判断文件是否存在
     * @return bool
     */
    public function exists() {
        return file_exists($this->path) && is_file($this->path);
    }

    /**
     * 判断文件是否可读
     * @return bool
     */
    public function isReadable() {
        return is_readable($this->path);
    }

    /**
     * 判断文件是否可写
     * @return bool
     */
    public function isWritable() {
        return is_writable($this->path);
    }

    /**
     * 判断是否为图片文件
     * @return bool
     */
    public function isImage() {
        return in_array($this->extension(), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']);
    }

    /**
     * 读取文件前 N 字节
     * @param int $length 读取长度
     * @return string|false
     */
    public function readHeader($length = 512) {
        if (!$this->exists()) return false;
        $fp = @fopen($this->path, 'rb');
        if (!$fp) return false;
        $data = fread($fp, $length);
        fclose($fp);
        return $data;
    }

    /**
     * 逐行读取文件
     * @return array|false
     */
    public function lines() {
        if (!$this->exists()) return false;
        return file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }

    /**
     * 获取文件的权限（八进制字符串）
     * @return string
     */
    public function permissions() {
        if (!$this->exists()) return '0000';
        return substr(sprintf('%o', fileperms($this->path)), -4);
    }

    /**
     * 设置文件权限
     * @param int $mode 权限模式，如 0644
     * @return bool
     */
    public function chmod($mode) {
        return @chmod($this->path, $mode);
    }

    // ── 静态方法 ──

    /**
     * 写入文件（静态）
     * @param string $path
     * @param string $content
     * @return bool
     */
    public static function put($path, $content) {
        $file = new self($path);
        return $file->write($content);
    }
}
