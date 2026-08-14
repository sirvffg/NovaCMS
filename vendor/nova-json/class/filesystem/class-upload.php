<?php
/**
 * Nova JSON API: Nova_Upload
 *
 * 文件上传处理类，提供安全的上传验证、文件类型检测、存储等功能。
 *
 * 用法：
 *   $upload = new Nova_Upload($_FILES['image']);
 *   $upload->allowedTypes(['jpg', 'png', 'gif'])
 *          ->maxSize(2 * 1024 * 1024)
 *          ->toDir('/path/to/uploads');
 *
 *   if ($upload->validate()) {
 *       $result = $upload->save();
 *       // $result['path'] => 存储路径
 *       // $result['url']  => 访问 URL
 *   } else {
 *       $error = $upload->getError();
 *   }
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_Upload {

    protected $file          = null;
    protected $allowedTypes  = [];
    protected $allowedMimes  = [];
    protected $maxSize       = 2097152; // 2MB 默认
    protected $minSize       = 0;
    protected $uploadDir     = '';
    protected $uploadUrl     = '';
    protected $overwrite     = false;
    protected $prefix        = '';
    protected $error         = '';
    protected $savedPath     = '';
    protected $savedUrl      = '';
    protected $useOriginalName = false;

    // 常用 MIME 映射
    protected static $mimeMap = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'json' => 'application/json',
        'xml'  => 'application/xml',
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip'  => 'application/zip',
        'rar'  => 'application/vnd.rar',
        'tar'  => 'application/x-tar',
        'gz'   => 'application/gzip',
        'mp3'  => 'audio/mpeg',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'avi'  => 'video/x-msvideo',
    ];

    // 图片类型列表
    protected static $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

    /**
     * @param array $file $_FILES 数组中的单个文件项
     */
    public function __construct($file) {
        $this->file = $file;
        // 默认上传目录
        $this->uploadDir = dirname(__DIR__, 4) . '/uploads';
        $this->uploadUrl = '/uploads';
    }

    /**
     * 允许的文件扩展名
     * @param array $types 如 ['jpg', 'png', 'gif']
     * @return $this
     */
    public function allowedTypes(array $types) {
        $this->allowedTypes = array_map('strtolower', $types);
        return $this;
    }

    /**
     * 允许的 MIME 类型
     * @param array $mimes
     * @return $this
     */
    public function allowedMimes(array $mimes) {
        $this->allowedMimes = $mimes;
        return $this;
    }

    /**
     * 仅允许图片类型
     * @return $this
     */
    public function onlyImages() {
        $this->allowedTypes = self::$imageTypes;
        return $this;
    }

    /**
     * 设置最大文件大小（字节）
     * @param int $bytes
     * @return $this
     */
    public function maxSize($bytes) {
        $this->maxSize = (int)$bytes;
        return $this;
    }

    /**
     * 设置最小文件大小（字节）
     * @param int $bytes
     * @return $this
     */
    public function minSize($bytes) {
        $this->minSize = (int)$bytes;
        return $this;
    }

    /**
     * 设置上传目标目录
     * @param string $dir 绝对路径
     * @return $this
     */
    public function toDir($dir) {
        $this->uploadDir = rtrim($dir, '/\\');
        return $this;
    }

    /**
     * 设置上传 URL 前缀
     * @param string $url
     * @return $this
     */
    public function toUrl($url) {
        $this->uploadUrl = rtrim($url, '/');
        return $this;
    }

    /**
     * 设置子目录（自动创建）
     * @param string $subDir 如 'images/avatars'
     * @return $this
     */
    public function subDir($subDir) {
        $this->uploadDir .= '/' . ltrim($subDir, '/');
        $this->uploadUrl  .= '/' . ltrim($subDir, '/');
        return $this;
    }

    /**
     * 是否允许覆盖同名文件
     * @param bool $flag
     * @return $this
     */
    public function overwrite($flag = true) {
        $this->overwrite = $flag;
        return $this;
    }

    /**
     * 设置文件名前缀
     * @param string $prefix
     * @return $this
     */
    public function prefix($prefix) {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * 是否使用原始文件名
     * @param bool $flag
     * @return $this
     */
    public function useOriginalName($flag = true) {
        $this->useOriginalName = $flag;
        return $this;
    }

    /**
     * 验证上传文件
     * @return bool
     */
    public function validate() {
        // 检查上传是否成功
        if (!$this->file || !isset($this->file['tmp_name'])) {
            $this->error = '未选择文件';
            return false;
        }

        if ($this->file['error'] !== UPLOAD_ERR_OK) {
            $this->error = $this->uploadErrorMsg($this->file['error']);
            return false;
        }

        if (!is_uploaded_file($this->file['tmp_name'])) {
            $this->error = '非法的上传文件';
            return false;
        }

        // 检查文件大小
        $fileSize = $this->file['size'];
        if ($fileSize < $this->minSize) {
            $this->error = '文件太小，最小 ' . $this->formatBytes($this->minSize);
            return false;
        }
        if ($fileSize > $this->maxSize) {
            $this->error = '文件太大，最大 ' . $this->formatBytes($this->maxSize);
            return false;
        }

        // 检查扩展名
        $ext = strtolower(pathinfo($this->file['name'], PATHINFO_EXTENSION));
        if (!empty($this->allowedTypes) && !in_array($ext, $this->allowedTypes)) {
            $this->error = '不允许的文件类型: ' . $ext;
            return false;
        }

        // 检查 MIME 类型（真实 MIME 检测）
        if (!empty($this->allowedMimes)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = finfo_file($finfo, $this->file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($realMime, $this->allowedMimes)) {
                $this->error = '不允许的文件 MIME 类型';
                return false;
            }
        }

        return true;
    }

    /**
     * 保存上传文件
     * @return array|false ['path' => '...', 'url' => '...', 'name' => '...', 'size' => 123]
     */
    public function save() {
        if (!$this->validate()) {
            return false;
        }

        // 确保目标目录存在
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }

        // 生成文件名
        $ext = strtolower(pathinfo($this->file['name'], PATHINFO_EXTENSION));
        $fileName = $this->generateFileName($ext);

        // 检查是否覆盖
        $targetPath = $this->uploadDir . '/' . $fileName;
        if (!$this->overwrite && file_exists($targetPath)) {
            $this->error = '文件已存在';
            return false;
        }

        // 移动文件
        if (!move_uploaded_file($this->file['tmp_name'], $targetPath)) {
            $this->error = '文件保存失败';
            return false;
        }

        $this->savedPath = $targetPath;
        $this->savedUrl  = $this->uploadUrl . '/' . $fileName;

        return [
            'path' => $this->savedPath,
            'url'  => $this->savedUrl,
            'name' => $fileName,
            'size' => $this->file['size'],
            'ext'  => $ext,
        ];
    }

    /**
     * 保存并返回 URL（快捷方法）
     * @return string|false
     */
    public function saveGetUrl() {
        $result = $this->save();
        return $result ? $result['url'] : false;
    }

    /**
     * 获取错误信息
     * @return string
     */
    public function getError() {
        return $this->error;
    }

    /**
     * 获取已保存文件的路径
     * @return string
     */
    public function getSavedPath() {
        return $this->savedPath;
    }

    /**
     * 获取已保存文件的 URL
     * @return string
     */
    public function getSavedUrl() {
        return $this->savedUrl;
    }

    // ── 内部方法 ──

    /**
     * 生成安全的文件名
     */
    protected function generateFileName($ext) {
        if ($this->useOriginalName) {
            $name = pathinfo($this->file['name'], PATHINFO_FILENAME);
            $name = $this->sanitizeFileName($name);
        } else {
            $name = uniqid();
        }

        $name = $this->prefix . $name;

        // 确保文件名唯一
        $fileName = $name . '.' . $ext;
        $counter  = 1;
        while (!$this->overwrite && file_exists($this->uploadDir . '/' . $fileName)) {
            $fileName = $name . '_' . $counter . '.' . $ext;
            $counter++;
        }

        return $fileName;
    }

    /**
     * 清理文件名中的特殊字符
     */
    protected function sanitizeFileName($name) {
        // 移除路径分隔符和特殊字符
        $name = preg_replace('/[\\\\\/:*?"<>|]/', '_', $name);
        // 限制长度
        if (mb_strlen($name) > 100) {
            $name = mb_substr($name, 0, 100);
        }
        return $name ?: 'untitled';
    }

    /**
     * 格式化字节大小
     */
    protected function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . $units[$i];
    }

    /**
     * 上传错误码转文字
     */
    protected function uploadErrorMsg($code) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => '文件超过服务器限制',
            UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL    => '文件仅部分上传',
            UPLOAD_ERR_NO_FILE    => '没有选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '文件写入磁盘失败',
            UPLOAD_ERR_EXTENSION  => '文件上传被扩展阻止',
        ];
        return $errors[$code] ?? '未知上传错误';
    }

    // ── 静态工具方法 ──

    /**
     * 获取文件扩展名对应的 MIME 类型
     * @param string $ext
     * @return string
     */
    public static function getMime($ext) {
        $ext = strtolower($ext);
        return self::$mimeMap[$ext] ?? 'application/octet-stream';
    }

    /**
     * 检测文件是否为图片（通过文件头检测）
     * @param string $filePath
     * @return bool
     */
    public static function isImageFile($filePath) {
        if (!file_exists($filePath)) return false;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        return strpos($mime, 'image/') === 0;
    }

    /**
     * 安全删除上传文件
     * @param string $url 文件 URL（相对于 uploads 的路径）
     * @return bool
     */
    public static function deleteByUrl($url) {
        $baseDir = dirname(__DIR__, 4);
        // 移除 URL 前缀，转换为绝对路径
        $relPath = ltrim(parse_url($url, PHP_URL_PATH), '/');
        // 尝试匹配 uploads 目录
        $pos = strpos($relPath, 'uploads/');
        if ($pos === false) return false;
        $filePath = $baseDir . '/' . substr($relPath, $pos);
        if (file_exists($filePath) && is_file($filePath)) {
            return @unlink($filePath);
        }
        return false;
    }
}
