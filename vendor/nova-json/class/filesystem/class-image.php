<?php
/**
 * Nova JSON API: Nova_Image
 *
 * 图片处理类，提供缩略图生成、尺寸调整、水印、格式转换等功能。
 * 依赖 GD 库。
 *
 * 用法：
 *   $img = new Nova_Image('path/to/image.jpg');
 *
 *   // 生成缩略图
 *   $img->resize(300, 200)->save('path/to/thumb.jpg');
 *
 *   // 等比例缩放
 *   $img->scale(800)->save('path/to/large.jpg');
 *
 *   // 添加水印
 *   $img->watermark('path/to/watermark.png', 'bottom-right')->save('path/to/wm.jpg');
 */

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Nova_Image {

    protected $sourcePath  = '';
    protected $image       = null;
    protected $width       = 0;
    protected $height      = 0;
    protected $type        = IMAGETYPE_JPEG;
    protected $mime        = '';
    protected $quality     = 85;
    protected $keepAlpha   = true;

    /**
     * @param string $path 图片文件路径
     */
    public function __construct($path) {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD 库未安装，无法处理图片');
        }
        if (!file_exists($path)) {
            throw new InvalidArgumentException('图片文件不存在: ' . $path);
        }
        $this->sourcePath = $path;
        $this->load();
    }

    /**
     * 加载图片
     */
    protected function load() {
        $info = @getimagesize($this->sourcePath);
        if (!$info) {
            throw new RuntimeException('无法读取图片信息');
        }

        $this->width  = $info[0];
        $this->height = $info[1];
        $this->type   = $info[2];
        $this->mime   = $info['mime'];

        switch ($this->type) {
            case IMAGETYPE_JPEG:
                $this->image = @imagecreatefromjpeg($this->sourcePath);
                break;
            case IMAGETYPE_PNG:
                $this->image = @imagecreatefrompng($this->sourcePath);
                $this->keepAlpha = true;
                break;
            case IMAGETYPE_GIF:
                $this->image = @imagecreatefromgif($this->sourcePath);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $this->image = @imagecreatefromwebp($this->sourcePath);
                } else {
                    throw new RuntimeException('当前 PHP 版本不支持 WebP 格式');
                }
                break;
            case IMAGETYPE_BMP:
                if (function_exists('imagecreatefrombmp')) {
                    $this->image = @imagecreatefrombmp($this->sourcePath);
                } else {
                    throw new RuntimeException('当前 PHP 版本不支持 BMP 格式');
                }
                break;
            default:
                throw new RuntimeException('不支持的图片类型: ' . $this->mime);
        }

        if (!$this->image) {
            throw new RuntimeException('图片加载失败');
        }
    }

    /**
     * 获取图片宽度
     * @return int
     */
    public function width() {
        return $this->width;
    }

    /**
     * 获取图片高度
     * @return int
     */
    public function height() {
        return $this->height;
    }

    /**
     * 获取图片 MIME 类型
     * @return string
     */
    public function mime() {
        return $this->mime;
    }

    /**
     * 设置输出质量（1-100）
     * @param int $quality
     * @return $this
     */
    public function quality($quality) {
        $this->quality = max(1, min(100, (int)$quality));
        return $this;
    }

    /**
     * 等比例缩放
     * @param int    $maxWidth  最大宽度
     * @param int    $maxHeight 最大高度（可选）
     * @return $this
     */
    public function scale($maxWidth, $maxHeight = null) {
        if ($maxHeight === null) {
            $maxHeight = $maxWidth;
        }

        $ratio = min($maxWidth / $this->width, $maxHeight / $this->height);
        if ($ratio >= 1) {
            return $this; // 不需要缩放
        }

        $newW = (int)round($this->width * $ratio);
        $newH = (int)round($this->height * $ratio);

        return $this->resize($newW, $newH);
    }

    /**
     * 缩放到精确尺寸（裁剪或拉伸）
     * @param int  $width  目标宽度
     * @param int  $height 目标高度
     * @param bool $crop   是否裁剪以适应（true=居中裁剪，false=拉伸）
     * @return $this
     */
    public function resize($width, $height, $crop = false) {
        $width  = (int)$width;
        $height = (int)$height;

        if ($crop) {
            // 居中裁剪后再缩放到目标尺寸
            $srcRatio = $this->width / $this->height;
            $dstRatio = $width / $height;

            if ($srcRatio > $dstRatio) {
                // 源图更宽：裁剪左右
                $srcW = (int)round($this->height * $dstRatio);
                $srcH = $this->height;
                $srcX = (int)round(($this->width - $srcW) / 2);
                $srcY = 0;
            } else {
                // 源图更高：裁剪上下
                $srcW = $this->width;
                $srcH = (int)round($this->width / $dstRatio);
                $srcX = 0;
                $srcY = (int)round(($this->height - $srcH) / 2);
            }

            $newImg = imagecreatetruecolor($width, $height);
            $this->preserveAlpha($newImg);
            imagecopyresampled($newImg, $this->image, 0, 0, $srcX, $srcY, $width, $height, $srcW, $srcH);
        } else {
            // 直接缩放
            $newImg = imagecreatetruecolor($width, $height);
            $this->preserveAlpha($newImg);
            imagecopyresampled($newImg, $this->image, 0, 0, 0, 0, $width, $height, $this->width, $this->height);
        }

        imagedestroy($this->image);
        $this->image  = $newImg;
        $this->width  = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * 生成固定尺寸缩略图（居中裁剪）
     * @param int $width
     * @param int $height
     * @return $this
     */
    public function thumb($width, $height) {
        return $this->resize($width, $height, true);
    }

    /**
     * 添加文字水印
     * @param string $text   水印文字
     * @param string $position 位置：top-left, top-right, center, bottom-left, bottom-right
     * @param array  $options  选项：['color' => [R,G,B], 'size' => 16, 'font' => '/path/to/font.ttf']
     * @return $this
     */
    public function textWatermark($text, $position = 'bottom-right', $options = []) {
        $color = $options['color'] ?? [255, 255, 255];
        $size  = $options['size']  ?? 16;
        $alpha = isset($options['alpha']) ? max(0, min(127, $options['alpha'])) : 40;

        if (!empty($options['font']) && file_exists($options['font'])) {
            // 使用 TTF 字体
            $box = imagettfbbox($size, 0, $options['font'], $text);
            $tw  = $box[2] - $box[0];
            $th  = $box[1] - $box[7];
        } else {
            // 使用内置字体
            $tw = strlen($text) * imagefontwidth(5);
            $th = imagefontheight(5);
        }

        list($x, $y) = $this->calcPosition($position, $tw, $th, 10);

        $col = imagecolorallocatealpha($this->image, $color[0], $color[1], $color[2], $alpha);

        if (!empty($options['font']) && file_exists($options['font'])) {
            imagettftext($this->image, $size, 0, $x, $y + $th, $col, $options['font'], $text);
        } else {
            imagestring($this->image, 5, $x, $y, $text, $col);
        }

        return $this;
    }

    /**
     * 添加图片水印
     * @param string $watermarkPath 水印图片路径
     * @param string $position      位置
     * @param int    $opacity       透明度（0-100），0=透明，100=不透明
     * @return $this
     */
    public function watermark($watermarkPath, $position = 'bottom-right', $opacity = 100) {
        if (!file_exists($watermarkPath)) {
            throw new InvalidArgumentException('水印图片不存在: ' . $watermarkPath);
        }

        $wmInfo = @getimagesize($watermarkPath);
        if (!$wmInfo) return $this;

        switch ($wmInfo[2]) {
            case IMAGETYPE_JPEG:
                $wmImg = @imagecreatefromjpeg($watermarkPath);
                break;
            case IMAGETYPE_PNG:
                $wmImg = @imagecreatefrompng($watermarkPath);
                break;
            default:
                return $this;
        }

        if (!$wmImg) return $this;

        list($x, $y) = $this->calcPosition($position, $wmInfo[0], $wmInfo[1], 10);

        if ($opacity < 100) {
            imagecopymerge($this->image, $wmImg, $x, $y, 0, 0, $wmInfo[0], $wmInfo[1], $opacity);
        } else {
            imagecopy($this->image, $wmImg, $x, $y, 0, 0, $wmInfo[0], $wmInfo[1]);
        }

        imagedestroy($wmImg);
        return $this;
    }

    /**
     * 旋转图片
     * @param float $angle 旋转角度
     * @param int   $bgColor 背景色（十六进制 RGB，如 0xFFFFFF）
     * @return $this
     */
    public function rotate($angle, $bgColor = 0xFFFFFF) {
        $bg = imagecolorallocate($this->image, ($bgColor >> 16) & 0xFF, ($bgColor >> 8) & 0xFF, $bgColor & 0xFF);
        $this->image = imagerotate($this->image, $angle, $bg);
        $this->width  = imagesx($this->image);
        $this->height = imagesy($this->image);
        return $this;
    }

    /**
     * 翻转图片
     * @param string $direction horizontal / vertical / both
     * @return $this
     */
    public function flip($direction = 'horizontal') {
        $newW = $this->width;
        $newH = $this->height;
        $newImg = imagecreatetruecolor($newW, $newH);
        $this->preserveAlpha($newImg);

        switch ($direction) {
            case 'horizontal':
                imagecopyresampled($newImg, $this->image, 0, 0, $this->width - 1, 0, $newW, $newH, -$this->width, $this->height);
                break;
            case 'vertical':
                imagecopyresampled($newImg, $this->image, 0, 0, 0, $this->height - 1, $newW, $newH, $this->width, -$this->height);
                break;
            case 'both':
                imagecopyresampled($newImg, $this->image, 0, 0, $this->width - 1, $this->height - 1, $newW, $newH, -$this->width, -$this->height);
                break;
        }

        imagedestroy($this->image);
        $this->image = $newImg;
        return $this;
    }

    /**
     * 转换为灰度图
     * @return $this
     */
    public function grayscale() {
        imagefilter($this->image, IMG_FILTER_GRAYSCALE);
        return $this;
    }

    /**
     * 模糊处理
     * @param int $times 模糊次数
     * @return $this
     */
    public function blur($times = 1) {
        for ($i = 0; $i < $times; $i++) {
            imagefilter($this->image, IMG_FILTER_GAUSSIAN_BLUR);
        }
        return $this;
    }

    /**
     * 输出图片到浏览器
     * @param string $format 输出格式：jpg, png, gif, webp
     */
    public function output($format = null) {
        $format = $format ?: $this->getExtension();
        header('Content-Type: ' . self::mimeFor($format));
        $this->render(null, $format);
    }

    /**
     * 保存图片到文件
     * @param string $path   保存路径
     * @param string $format 格式：jpg, png, gif, webp（默认根据扩展名自动判断）
     * @return bool
     */
    public function save($path = null, $format = null) {
        $path = $path ?: $this->sourcePath;

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $format = $format ?: strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $this->render($path, $format);
    }

    /**
     * 渲染图片
     */
    protected function render($path, $format) {
        switch ($format) {
            case 'jpg':
            case 'jpeg':
                return imagejpeg($this->image, $path, $this->quality);
            case 'png':
                $pngQuality = max(0, min(9, (int)((100 - $this->quality) / 11.1)));
                return imagepng($this->image, $path, $pngQuality);
            case 'gif':
                return imagegif($this->image, $path);
            case 'webp':
                if (function_exists('imagewebp')) {
                    return imagewebp($this->image, $path, $this->quality);
                }
                throw new RuntimeException('当前 PHP 版本不支持 WebP 输出');
            default:
                return imagejpeg($this->image, $path, $this->quality);
        }
    }

    /**
     * 保存为 JPEG
     * @param string $path
     * @return bool
     */
    public function saveAsJpg($path) {
        return $this->save($path, 'jpg');
    }

    /**
     * 保存为 PNG
     * @param string $path
     * @return bool
     */
    public function saveAsPng($path) {
        return $this->save($path, 'png');
    }

    /**
     * 保存为 WebP
     * @param string $path
     * @return bool
     */
    public function saveAsWebp($path) {
        return $this->save($path, 'webp');
    }

    /**
     * 获取图片 base64 编码
     * @param string $format
     * @return string
     */
    public function toBase64($format = null) {
        ob_start();
        $this->output($format);
        $data = ob_get_clean();
        $format = $format ?: $this->getExtension();
        return 'data:' . self::mimeFor($format) . ';base64,' . base64_encode($data);
    }

    /**
     * 获取原始 GD 资源
     * @return resource|null
     */
    public function getResource() {
        return $this->image;
    }

    /**
     * 释放资源
     */
    public function destroy() {
        if ($this->image) {
            imagedestroy($this->image);
            $this->image = null;
        }
    }

    public function __destruct() {
        $this->destroy();
    }

    // ── 内部帮助方法 ──

    /**
     * 保持 PNG/GIF 透明通道
     */
    protected function preserveAlpha($img) {
        if ($this->keepAlpha) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
            imagefill($img, 0, 0, $transparent);
        }
    }

    /**
     * 计算水印位置
     */
    protected function calcPosition($position, $objW, $objH, $padding) {
        switch ($position) {
            case 'top-left':
                return [$padding, $padding];
            case 'top-right':
                return [$this->width - $objW - $padding, $padding];
            case 'center':
                return [($this->width - $objW) / 2, ($this->height - $objH) / 2];
            case 'bottom-left':
                return [$padding, $this->height - $objH - $padding];
            case 'bottom-right':
            default:
                return [$this->width - $objW - $padding, $this->height - $objH - $padding];
        }
    }

    /**
     * 获取当前图片格式的扩展名
     */
    protected function getExtension() {
        $map = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_GIF  => 'gif',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_BMP  => 'bmp',
        ];
        return $map[$this->type] ?? 'jpg';
    }

    /**
     * 获取格式对应的 MIME 类型
     */
    protected static function mimeFor($format) {
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
        ];
        return $map[$format] ?? 'image/jpeg';
    }

    // ── 静态工具方法 ──

    /**
     * 快速生成缩略图
     * @param string $source  源文件路径
     * @param string $dest    目标文件路径
     * @param int    $width   宽度
     * @param int    $height  高度
     * @param bool   $crop    是否裁剪
     * @return bool
     */
    public static function makeThumb($source, $dest, $width, $height, $crop = true) {
        try {
            $img = new self($source);
            $img->resize($width, $height, $crop);
            return $img->save($dest);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 获取图片信息
     * @param string $path
     * @return array|false
     */
    public static function info($path) {
        if (!file_exists($path)) return false;
        $info = @getimagesize($path);
        if (!$info) return false;
        return [
            'width'  => $info[0],
            'height' => $info[1],
            'mime'   => $info['mime'],
            'type'   => image_type_to_extension($info[2], false),
            'size'   => filesize($path),
        ];
    }
}
