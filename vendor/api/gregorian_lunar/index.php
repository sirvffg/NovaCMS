<?php
/**
 * 农历公历转换 API
 * 参照 LunarSolarConverter.class.php 封装
 *
 * 接口说明：
 *   公历转农历：index.php?type=solar&year=2026&month=7&day=18
 *   农历转公历：index.php?type=lunar&year=2026&month=6&day=5&isLeap=0
 *
 * 返回格式：JSON
 */

header('Content-Type: application/json; charset=utf-8');

define('__ROOT__', dirname(__FILE__));
require_once(__ROOT__ . '/LunarSolarConverter.class.php');

// ===== 农历显示用文字 =====
$LUNAR_MONTHS = array('正', '二', '三', '四', '五', '六', '七', '八', '九', '十', '冬', '腊');
$LUNAR_DAYS = array(
    '初一', '初二', '初三', '初四', '初五', '初六', '初七', '初八', '初九', '初十',
    '十一', '十二', '十三', '十四', '十五', '十六', '十七', '十八', '十九', '二十',
    '廿一', '廿二', '廿三', '廿四', '廿五', '廿六', '廿七', '廿八', '廿九', '三十'
);
$WEEKDAYS = array('星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六');
$ZODIAC = array('鼠', '牛', '虎', '兔', '龙', '蛇', '马', '羊', '猴', '鸡', '狗', '猪');
$GAN = array('甲', '乙', '丙', '丁', '戊', '己', '庚', '辛', '壬', '癸');
$ZHI = array('子', '丑', '寅', '卯', '辰', '巳', '午', '未', '申', '酉', '戌', '亥');

function pad2($n)
{
    return str_pad((string)$n, 2, '0', STR_PAD_LEFT);
}

function formatLunarText($lunar)
{
    global $LUNAR_MONTHS, $LUNAR_DAYS;
    $monthStr = ($lunar->isleap ? '闰' : '') . $LUNAR_MONTHS[$lunar->lunarMonth - 1] . '月';
    $dayStr = $LUNAR_DAYS[$lunar->lunarDay - 1];
    return '农历' . $monthStr . $dayStr;
}

function ganzhi($year)
{
    global $GAN, $ZHI;
    $i = ($year - 4) % 10;
    $j = ($year - 4) % 12;
    return $GAN[$i] . $ZHI[$j] . '年';
}

function zodiac($year)
{
    global $ZODIAC;
    return $ZODIAC[($year - 4) % 12];
}

// 星座计算
function constellation($month, $day)
{
    $constellations = array(
        array('摩羯座', '水瓶座'), array('水瓶座', '双鱼座'), array('双鱼座', '白羊座'),
        array('白羊座', '金牛座'), array('金牛座', '双子座'), array('双子座', '巨蟹座'),
        array('巨蟹座', '狮子座'), array('狮子座', '处女座'), array('处女座', '天秤座'),
        array('天秤座', '天蝎座'), array('天蝎座', '射手座'), array('射手座', '摩羯座')
    );
    $boundary = array(20, 19, 21, 20, 21, 22, 23, 23, 23, 24, 23, 22);
    $idx = $day < $boundary[$month - 1] ? 0 : 1;
    return $constellations[$month - 1][$idx];
}

// ===== 主逻辑 =====
$type = isset($_REQUEST['type']) ? $_REQUEST['type'] : 'solar';
$year = isset($_REQUEST['year']) ? intval($_REQUEST['year']) : 0;
$month = isset($_REQUEST['month']) ? intval($_REQUEST['month']) : 0;
$day = isset($_REQUEST['day']) ? intval($_REQUEST['day']) : 0;
$isLeap = isset($_REQUEST['isLeap']) ? intval($_REQUEST['isLeap']) : 0;

// 参数校验
if ($year < 1900 || $year > 2099) {
    echo json_encode(array(
        'status' => 0,
        'info' => '年份超出范围，仅支持 1900-2099',
        'data' => null
    ), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($month < 1 || $month > 12) {
    echo json_encode(array(
        'status' => 0,
        'info' => '月份超出范围',
        'data' => null
    ), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($day < 1 || $day > 31) {
    echo json_encode(array(
        'status' => 0,
        'info' => '日期超出范围',
        'data' => null
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($type === 'solar') {
        // ===== 公历转农历 =====
        $solar = new Solar();
        $solar->solarYear = $year;
        $solar->solarMonth = $month;
        $solar->solarDay = $day;

        $lunar = LunarSolarConverter::SolarToLunar($solar);

        $solarText = $year . '-' . pad2($month) . '-' . pad2($day);
        $weekday = $WEEKDAYS[date('w', mktime(0, 0, 0, $month, $day, $year))];

        $result = array(
            'status' => 1,
            'info' => '查询成功',
            'data' => array(
                'type' => 'solar_to_lunar',
                'solar' => $solarText,
                'solarYear' => $year,
                'solarMonth' => $month,
                'solarDay' => $day,
                'weekday' => $weekday,
                'lunar' => $year . '-' . $lunar->lunarMonth . '-' . $lunar->lunarDay,
                'lunarYear' => $lunar->lunarYear,
                'lunarMonth' => $lunar->lunarMonth,
                'lunarDay' => $lunar->lunarDay,
                'isLeap' => $lunar->isleap ? 1 : 0,
                'lunarText' => formatLunarText($lunar),
                'ganzhi' => ganzhi($lunar->lunarYear),
                'zodiac' => zodiac($lunar->lunarYear),
                'constellation' => constellation($month, $day)
            )
        );
    } else if ($type === 'lunar') {
        // ===== 农历转公历 =====
        $lunar = new Lunar();
        $lunar->lunarYear = $year;
        $lunar->lunarMonth = $month;
        $lunar->lunarDay = $day;
        $lunar->isleap = ($isLeap == 1);

        $solar = LunarSolarConverter::LunarToSolar($lunar);

        $sy = $solar->solarYear;
        $sm = $solar->solarMonth;
        $sd = $solar->solarDay;
        $solarText = $sy . '-' . pad2($sm) . '-' . pad2($sd);
        $weekday = $WEEKDAYS[date('w', mktime(0, 0, 0, $sm, $sd, $sy))];

        $result = array(
            'status' => 1,
            'info' => '查询成功',
            'data' => array(
                'type' => 'lunar_to_solar',
                'lunar' => $year . '-' . $month . '-' . $day,
                'lunarYear' => $year,
                'lunarMonth' => $month,
                'lunarDay' => $day,
                'isLeap' => $isLeap,
                'lunarText' => formatLunarText($lunar),
                'solar' => $solarText,
                'solarYear' => $sy,
                'solarMonth' => $sm,
                'solarDay' => $sd,
                'weekday' => $weekday,
                'ganzhi' => ganzhi($year),
                'zodiac' => zodiac($year)
            )
        );
    } else {
        $result = array(
            'status' => 0,
            'info' => 'type 参数错误，应为 solar 或 lunar',
            'data' => null
        );
    }
} catch (Exception $e) {
    $result = array(
        'status' => 0,
        'info' => '转换失败：' . $e->getMessage(),
        'data' => null
    );
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
