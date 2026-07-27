<?php
error_reporting(0);
header('Content-Type: image/png');

// ===== 获取 Bing 每日图片 =====
$bingApi = 'https://www.bing.com/HPImageArchive.aspx?format=js&idx=0&n=1&mkt=zh-CN';
$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]
]);
$response = @file_get_contents($bingApi, false, $context);

if ($response) {
    $json = json_decode($response, true);
    $imageUrl = 'https://www.bing.com' . $json['images'][0]['urlbase'] . '_UHD.jpg';
} else {
    // 备用：使用小图
    $imageUrl = 'https://www.bing.com/th?id=OHR.ZH-CN_' . date('Ymd') . '_UHD.jpg&rf=LaD_1920x1080.jpg&pid=hp';
}

// 下载 Bing 图片
$imgData = @file_get_contents($imageUrl, false, $context);
if (!$imgData) {
    // 如果下载失败，创建一个渐变背景
    $size = 1920;
    $image = imagecreatetruecolor($size, $size);
    $bgTop = imagecolorallocate($image, 100, 150, 200);
    $bgBottom = imagecolorallocate($image, 50, 80, 150);
    for ($i = 0; $i < $size; $i++) {
        $ratio = $i / $size;
        $r = 100 - (50 * $ratio);
        $g = 150 - (70 * $ratio);
        $b = 200 - (50 * $ratio);
        $color = imagecolorallocate($image, $r, $g, $b);
        imageline($image, 0, $i, $size, $i, $color);
    }
    $useBing = false;
} else {
    $useBing = true;
    $sourceImage = @imagecreatefromstring($imgData);
    if (!$sourceImage) {
        // 图片格式不支持，创建渐变背景
        $sourceImage = imagecreatetruecolor(1920, 1080);
        $bgTop = imagecolorallocate($sourceImage, 100, 150, 200);
        $bgBottom = imagecolorallocate($sourceImage, 50, 80, 150);
        for ($i = 0; $i < 1080; $i++) {
            $ratio = $i / 1080;
            $r = 100 - (50 * $ratio);
            $g = 150 - (70 * $ratio);
            $b = 200 - (50 * $ratio);
            $color = imagecolorallocate($sourceImage, $r, $g, $b);
            imageline($sourceImage, 0, $i, 1920, $i, $color);
        }
    }
    
    // 获取原图尺寸
    $srcWidth = imagesx($sourceImage);
    $srcHeight = imagesy($sourceImage);
    
    // 目标尺寸（保持比例，可能裁剪）
    $targetWidth = 800;
    $targetHeight = 600;
    
    // 创建目标画布
    $image = imagecreatetruecolor($targetWidth, $targetHeight);
    
    // 计算缩放和裁剪
    $srcRatio = $srcWidth / $srcHeight;
    $targetRatio = $targetWidth / $targetHeight;
    
    if ($srcRatio > $targetRatio) {
        // 图片太宽，裁剪左右
        $newWidth = $srcHeight * $targetRatio;
        $srcX = ($srcWidth - $newWidth) / 2;
        $srcY = 0;
        imagecopyresampled($image, $sourceImage, 0, 0, $srcX, $srcY, $targetWidth, $targetHeight, $newWidth, $srcHeight);
    } else {
        // 图片太高，裁剪上下
        $newHeight = $srcWidth / $targetRatio;
        $srcX = 0;
        $srcY = ($srcHeight - $newHeight) / 2;
        imagecopyresampled($image, $sourceImage, 0, 0, $srcX, $srcY, $targetWidth, $targetHeight, $srcWidth, $newHeight);
    }
    
    imagedestroy($sourceImage);
}

// ===== 字体 =====
$font = __DIR__ . '/../../../assets/fonts/MaShanZheng/MaShanZheng-Regular.ttf';

// ===== 日期数据 =====
$year = date('Y');    // 年份
$month = date('n');   // 数字月份
$day = date('j');     // 日期数字
$weekday = ['日', '一', '二', '三', '四', '五', '六'][date('w')]; // 星期

// ===== 半透明遮罩层 =====
$overlay = imagecreatetruecolor($targetWidth, $targetHeight);
$black = imagecolorallocate($overlay, 0, 0, 0);
imagefill($overlay, 0, 0, $black);
imagecopymerge($image, $overlay, 0, 0, 0, 0, $targetWidth, $targetHeight, 30);
imagedestroy($overlay);

// ===== 圆角卡片（右下角）=====
$cardWidth = 220;
$cardHeight = 160;
$cardX = $targetWidth - $cardWidth - 20;
$cardY = $targetHeight - $cardHeight - 20;
$radius = 20;

// 阴影
$shadowColor = imagecolorallocate($image, 0, 0, 0);
imagefilledrectangle($image, $cardX + 5, $cardY + 5, $cardX + $cardWidth, $cardY + $cardHeight, $shadowColor);

// 白色卡片
$white = imagecolorallocate($image, 255, 255, 255);
imagefilledrectangle($image, $cardX, $cardY, $cardX + $cardWidth, $cardY + $cardHeight, $white);

// 圆角遮罩（用黑色画圆角，然后合并白色）
$mask = imagecreatetruecolor($cardWidth, $cardHeight);
$maskBlack = imagecolorallocate($mask, 0, 0, 0);
$maskWhite = imagecolorallocate($mask, 255, 255, 255);
imagefilledrectangle($mask, 0, $radius, $cardWidth, $cardHeight, $maskBlack);
imagefilledrectangle($mask, $radius, 0, $cardWidth, $cardHeight, $maskBlack);
imagefilledrectangle($mask, 0, 0, $cardWidth, $cardHeight - $radius, $maskBlack);
imagefilledrectangle($mask, 0, 0, $cardWidth - $radius, $cardHeight, $maskBlack);
imagefilledellipse($mask, $radius, $radius, $radius * 2, $radius * 2, $maskBlack);
imagefilledellipse($mask, $cardWidth - $radius - 1, $radius, $radius * 2, $radius * 2, $maskBlack);
imagefilledellipse($mask, $radius, $cardHeight - $radius - 1, $radius * 2, $radius * 2, $maskBlack);
imagefilledellipse($mask, $cardWidth - $radius - 1, $cardHeight - $radius - 1, $radius * 2, $radius * 2, $maskBlack);
imagefilledrectangle($mask, 0, $radius, $cardWidth, $cardHeight - $radius, $maskBlack);
imagefilledrectangle($mask, $radius, 0, $cardWidth - $radius, $cardHeight, $maskBlack);

// 合并到主图
for ($y = 0; $y < $cardHeight; $y++) {
    for ($x = 0; $x < $cardWidth; $x++) {
        $maskPixel = imagecolorat($mask, $x, $y);
        if ($maskPixel == $maskBlack) {
            imagesetpixel($image, $cardX + $x, $cardY + $y, $white);
        }
    }
}
imagedestroy($mask);

// ===== 顶部渐变色块 =====
$gradientHeight = 50;
$gradientY = $cardY;
for ($i = 0; $i < $gradientHeight; $i++) {
    $ratio = $i / $gradientHeight;
    $r = 255 - ($ratio * 20);
    $g = 107 + (52 * $ratio);
    $b = 107 - (40 * $ratio);
    $color = imagecolorallocate($image, $r, $g, $b);
    imageline($image, $cardX, $gradientY + $i, $cardX + $cardWidth, $gradientY + $i, $color);
}

// ===== 顶部日期文字 =====
$topText = $year . '年' . $month . '月' . $day . '日';
$fontSize = 14;
$topBox = imagettfbbox($fontSize, 0, $font, $topText);
$topWidth = $topBox[2] - $topBox[0];
$x = $cardX + ($cardWidth - $topWidth) / 2;
$y = $gradientY + 35;
$white = imagecolorallocate($image, 255, 255, 255);
imagettftext($image, $fontSize, 0, $x, $y, $white, $font, $topText);

// ===== 星期 =====
$weekText = '星期' . $weekday;
$weekSize = 12;
$weekBox = imagettfbbox($weekSize, 0, $font, $weekText);
$weekWidth = $weekBox[2] - $weekBox[0];
$x = $cardX + ($cardWidth - $weekWidth) / 2;
$y = $gradientY + 55;
$gray = imagecolorallocate($image, 100, 100, 100);
imagettftext($image, $weekSize, 0, $x, $y, $gray, $font, $weekText);

// ===== 中间大日期数字 =====
$bigSize = 65;
$dayStr = $day;
$dayBox = imagettfbbox($bigSize, 0, $font, $dayStr);
$dayWidth = $dayBox[2] - $dayBox[0];
$dayHeight = $dayBox[1] - $dayBox[7];

// 居中于卡片下半部分
$bigAreaY = $gradientY + $gradientHeight;
$bigAreaHeight = $cardHeight - $gradientHeight;
$bigCenterY = $bigAreaY + $bigAreaHeight / 2;
$y = $bigCenterY + $dayHeight / 2 - 5;

$x = $cardX + ($cardWidth - $dayWidth) / 2;
$redColor = imagecolorallocate($image, 255, 80, 80);
imagettftext($image, $bigSize, 0, $x, $y, $redColor, $font, $dayStr);

// ===== "日"字 =====
$riText = '日';
$riSize = 20;
$riBox = imagettfbbox($riSize, 0, $font, $riText);
$riWidth = $riBox[2] - $riBox[0];
$x = $cardX + $cardWidth - 50;
$y = $bigCenterY + $dayHeight / 2 + 5;
$orangeColor = imagecolorallocate($image, 255, 140, 0);
imagettftext($image, $riSize, 0, $x, $y, $orangeColor, $font, $riText);

// ===== 底部装饰圆点 =====
$dotColor = imagecolorallocate($image, 255, 180, 100);
$dotY = $cardY + $cardHeight - 15;
$dotSpacing = 20;
$totalDotsWidth = 4 * $dotSpacing;
$startX = $cardX + ($cardWidth - $totalDotsWidth) / 2;
for ($i = 0; $i < 5; $i++) {
    $dotX = $startX + $i * $dotSpacing;
    imagefilledellipse($image, $dotX, $dotY, 6, 6, $dotColor);
}

// 输出
imagepng($image);
imagedestroy($image);
exit;
