<?php
error_reporting(0);
header('Content-Type: image/png');

// 尺寸
$size = 400;
$image = imagecreatetruecolor($size, $size);

// 渐变背景（从浅蓝到浅紫）
$bgTop = imagecolorallocate($image, 200, 210, 240);
$bgBottom = imagecolorallocate($image, 230, 200, 240);
for ($i = 0; $i < $size; $i++) {
    $r = 200 + (30 * $i / $size);
    $g = 210 - (10 * $i / $size);
    $b = 240 - (20 * $i / $size);
    $color = imagecolorallocate($image, $r, $g, $b);
    imageline($image, 0, $i, $size, $i, $color);
}

// ===== 字体 =====
$font = __DIR__ . '/../../../assets/fonts/MaShanZheng/MaShanZheng-Regular.ttf';

// ===== 日期数据 =====
$year = date('Y');    // 年份
$month = date('n');   // 数字月份
$day = date('j');     // 日期数字
$weekday = ['日', '一', '二', '三', '四', '五', '六'][date('w')]; // 星期

// ===== 圆角卡片 =====
// 阴影
$shadowColor = imagecolorallocate($image, 180, 180, 200);
$radius = 30;
$shadowOffset = 8;
// 阴影（偏移）
imagefilledrectangle($image, $shadowOffset + $radius, $shadowOffset + $radius, 
    $size - $shadowOffset - $radius, $size - $shadowOffset - $radius, $shadowColor);
imagefilledrectangle($image, $shadowOffset + $radius, $shadowOffset, 
    $size - $shadowOffset - $radius, $size - $shadowOffset, $shadowColor);
imagefilledrectangle($image, $shadowOffset, $shadowOffset + $radius, 
    $size - $shadowOffset, $size - $shadowOffset - $radius, $shadowColor);
imagefilledrectangle($image, $shadowOffset + $radius, $shadowOffset, 
    $size - $shadowOffset, $size - $shadowOffset, $shadowColor);

// 白色卡片背景
$white = imagecolorallocate($image, 255, 255, 255);
imagefilledrectangle($image, $radius, 0, $size - $radius - 1, $size, $white);
imagefilledrectangle($image, 0, $radius, $size, $size - $radius - 1, $white);
// 四角
imagefilledellipse($image, $radius, $radius, $radius * 2, $radius * 2, $white);
imagefilledellipse($image, $size - $radius - 1, $radius, $radius * 2, $radius * 2, $white);
imagefilledellipse($image, $radius, $size - $radius - 1, $radius * 2, $radius * 2, $white);
imagefilledellipse($image, $size - $radius - 1, $size - $radius - 1, $radius * 2, $radius * 2, $white);
imagefilledrectangle($image, $radius, 0, $size - $radius - 1, $size, $white);
imagefilledrectangle($image, 0, $radius, $size, $size - $radius - 1, $white);

// ===== 渐变色块顶部 =====
$gradientTop = imagecolorallocate($image, 255, 107, 107);  // 珊瑚红
$gradientMid = imagecolorallocate($image, 255, 159, 67);   // 橙色
// 渐变色块（渐变矩形）
$blockHeight = 90;
for ($i = 0; $i < $blockHeight; $i++) {
    $ratio = $i / $blockHeight;
    $r = 255 - ($ratio * 10);
    $g = 107 + (52 * $ratio);
    $b = 107 - (40 * $ratio);
    $color = imagecolorallocate($image, $r, $g, $b);
    imageline($image, $radius, $i, $size - $radius - 1, $i, $color);
}

// ===== 顶部日期文字 =====
$topText = $year . '年' . $month . '月' . $day . '日 星期' . $weekday;
$fontSize = 16;
$topBox = imagettfbbox($fontSize, 0, $font, $topText);
$topWidth = $topBox[2] - $topBox[0];
$topHeight = $topBox[1] - $topBox[7];
$x = ($size - $topWidth) / 2;
$y = 50 + $topHeight;
$white = imagecolorallocate($image, 255, 255, 255);
imagettftext($image, $fontSize, 0, $x, $y, $white, $font, $topText);

// ===== 中间装饰圆环 =====
$centerX = $size / 2;
$centerY = $size / 2 + 30;
$ringRadius = 95;
// 外圈（浅橙色）
$ringOuter = imagecolorallocate($image, 255, 200, 150);
imageellipse($image, $centerX, $centerY, $ringRadius * 2, $ringRadius * 2, $ringOuter);
imagefilledellipse($image, $centerX, $centerY, $ringRadius * 2 - 20, $ringRadius * 2 - 20, $white);
// 内圈渐变（浅红）
$ringInner = imagecolorallocate($image, 255, 240, 240);
imagefilledellipse($image, $centerX, $centerY, $ringRadius * 2 - 30, $ringRadius * 2 - 30, $ringInner);

// ===== 中间大日期数字 =====
$bigSize = 110;
$dayStr = $day;
$dayBox = imagettfbbox($bigSize, 0, $font, $dayStr);
$dayWidth = $dayBox[2] - $dayBox[0];
$dayHeight = $dayBox[1] - $dayBox[7];
$x = ($size - $dayWidth) / 2;
$y = $centerY + $dayHeight / 2;
$redColor = imagecolorallocate($image, 255, 80, 80);
imagettftext($image, $bigSize, 0, $x, $y, $redColor, $font, $dayStr);

// ===== "日"字 =====
// 小"日"字在底部
$riText = '日';
$riSize = 28;
$riBox = imagettfbbox($riSize, 0, $font, $riText);
$riWidth = $riBox[2] - $riBox[0];
$x = $centerX + $dayWidth / 2 - $riWidth + 5;
$y = $centerY + $dayHeight / 2 + 30;
$orangeColor = imagecolorallocate($image, 255, 140, 0);
imagettftext($image, $riSize, 0, $x, $y, $orangeColor, $font, $riText);

// ===== 底部装饰 =====
// 小圆点装饰
$dotColor = imagecolorallocate($image, 255, 180, 100);
$dotY = $size - 50;
for ($i = 0; $i < 5; $i++) {
    $dotX = $centerX - 60 + $i * 30;
    imagefilledellipse($image, $dotX, $dotY, 8, 8, $dotColor);
}

// ===== 边框线 =====
// 内边框（柔和的灰线）
$borderColor = imagecolorallocate($image, 230, 230, 235);
imagerectangle($image, 5, 5, $size - 6, $size - 6, $borderColor);

// 输出
imagepng($image);
imagedestroy($image);
exit;