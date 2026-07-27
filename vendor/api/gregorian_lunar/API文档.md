# 农历公历转换 API 文档

> 基于 [Lunar-Solar-Calendar-Converter](https://github.com/isee15/Lunar-Solar-Calendar-Converter) 算法库封装  
> 支持年份范围：1900 - 2099

## 目录

- [接口概览](#接口概览)
- [公历转农历](#公历转农历)
- [农历转公历](#农历转公历)
- [返回字段说明](#返回字段说明)
- [错误码](#错误码)
- [使用示例](#使用示例)

---

## 接口概览

| 项目 | 说明 |
|------|------|
| 接口地址 | `http://你的域名/gregorian_lunar/index.php` |
| 请求方式 | GET / POST |
| 返回格式 | JSON |
| 字符编码 | UTF-8 |
| 认证方式 | 无需认证 |

### 通用请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `type` | String | 是 | 转换方向：`solar`（公历转农历）/ `lunar`（农历转公历）|
| `year` | Int | 是 | 年份（1900-2099）|
| `month` | Int | 是 | 月份（1-12）|
| `day` | Int | 是 | 日期（1-31）|
| `isLeap` | Int | 否 | 是否闰月，仅农历转公历时有效。`1`=闰月，`0`=非闰月，默认 `0`|

---

## 公历转农历

**接口地址**
```
GET index.php?type=solar&year={年}&month={月}&day={日}
```

**请求示例**
```
GET index.php?type=solar&year=2027&month=7&day=8
```

**响应示例**
```json
{
  "status": 1,
  "info": "查询成功",
  "data": {
    "type": "solar_to_lunar",
    "solar": "2027-07-08",
    "solarYear": 2027,
    "solarMonth": 7,
    "solarDay": 8,
    "weekday": "星期四",
    "lunar": "2027-6-5",
    "lunarYear": 2027,
    "lunarMonth": 6,
    "lunarDay": 5,
    "isLeap": 0,
    "lunarText": "农历六月初五",
    "ganzhi": "丁未年",
    "zodiac": "羊",
    "constellation": "巨蟹座"
  }
}
```

---

## 农历转公历

**接口地址**
```
GET index.php?type=lunar&year={年}&month={月}&day={日}&isLeap={0|1}
```

**请求示例**
```
GET index.php?type=lunar&year=2027&month=6&day=5&isLeap=0
```

**响应示例**
```json
{
  "status": 1,
  "info": "查询成功",
  "data": {
    "type": "lunar_to_solar",
    "lunar": "2027-6-5",
    "lunarYear": 2027,
    "lunarMonth": 6,
    "lunarDay": 5,
    "isLeap": 0,
    "lunarText": "农历六月初五",
    "solar": "2027-07-08",
    "solarYear": 2027,
    "solarMonth": 7,
    "solarDay": 8,
    "weekday": "星期四",
    "ganzhi": "丁未年",
    "zodiac": "羊"
  }
}
```

---

## 返回字段说明

### 顶层字段

| 字段 | 类型 | 说明 |
|------|------|------|
| `status` | Int | 状态码。`1`=成功，`0`=失败 |
| `info` | String | 状态说明 |
| `data` | Object | 转换结果数据，失败时为 `null` |

### `data` 字段（公历转农历）

| 字段 | 类型 | 说明 |
|------|------|------|
| `type` | String | 固定值 `solar_to_lunar` |
| `solar` | String | 公历日期，格式 `YYYY-MM-DD` |
| `solarYear` | Int | 公历年 |
| `solarMonth` | Int | 公历月 |
| `solarDay` | Int | 公历日 |
| `weekday` | String | 星期（如"星期四"）|
| `lunar` | String | 农历日期，格式 `年-月-日` |
| `lunarYear` | Int | 农历年 |
| `lunarMonth` | Int | 农历月（1-12）|
| `lunarDay` | Int | 农历日（1-30）|
| `isLeap` | Int | 是否闰月。`1`=是，`0`=否 |
| `lunarText` | String | 农历中文显示（如"农历六月初五"）|
| `ganzhi` | String | 干支纪年（如"丁未年"）|
| `zodiac` | String | 生肖（如"羊"）|
| `constellation` | String | 星座（如"巨蟹座"）|

### `data` 字段（农历转公历）

| 字段 | 类型 | 说明 |
|------|------|------|
| `type` | String | 固定值 `lunar_to_solar` |
| `lunar` | String | 农历日期，格式 `年-月-日` |
| `lunarYear` | Int | 农历年 |
| `lunarMonth` | Int | 农历月（1-12）|
| `lunarDay` | Int | 农历日（1-30）|
| `isLeap` | Int | 是否闰月。`1`=是，`0`=否 |
| `lunarText` | String | 农历中文显示（如"农历六月初五"）|
| `solar` | String | 公历日期，格式 `YYYY-MM-DD` |
| `solarYear` | Int | 公历年 |
| `solarMonth` | Int | 公历月 |
| `solarDay` | Int | 公历日 |
| `weekday` | String | 星期（如"星期四"）|
| `ganzhi` | String | 干支纪年（如"丁未年"）|
| `zodiac` | String | 生肖（如"羊"）|

---

## 错误码

### status 状态码

| 值 | 说明 |
|----|------|
| `1` | 查询成功 |
| `0` | 查询失败，详见 `info` 字段 |

### 常见错误

| 错误信息 | 原因 |
|---------|------|
| `年份超出范围，仅支持 1900-2099` | year 参数不在有效范围 |
| `月份超出范围` | month 参数不在 1-12 之间 |
| `日期超出范围` | day 参数不在 1-31 之间 |
| `type 参数错误，应为 solar 或 lunar` | type 参数值非法 |
| `转换失败：...` | 农历日期不合法（如闰月不存在）等异常 |

**错误响应示例**
```json
{
  "status": 0,
  "info": "年份超出范围，仅支持 1900-2099",
  "data": null
}
```

---

## 使用示例

### cURL

```bash
# 公历转农历
curl "http://你的域名/gregorian_lunar/index.php?type=solar&year=2027&month=7&day=8"

# 农历转公历（非闰月）
curl "http://你的域名/gregorian_lunar/index.php?type=lunar&year=2027&month=6&day=5&isLeap=0"

# 农历转公历（闰月）
curl "http://你的域名/gregorian_lunar/index.php?type=lunar&year=2025&month=6&day=5&isLeap=1"
```

### JavaScript

```javascript
// 公历转农历
fetch('http://你的域名/gregorian_lunar/index.php?type=solar&year=2027&month=7&day=8')
  .then(res => res.json())
  .then(json => {
    if (json.status === 1) {
      console.log(json.data.lunarText)  // 农历六月初五
      console.log(json.data.ganzhi)     // 丁未年
    }
  })

// 农历转公历
fetch('http://你的域名/gregorian_lunar/index.php?type=lunar&year=2027&month=6&day=5&isLeap=0')
  .then(res => res.json())
  .then(json => {
    if (json.status === 1) {
      console.log(json.data.solar)      // 2027-07-08
      console.log(json.data.weekday)    // 星期四
    }
  })
```

### PHP

```php
<?php
// 公历转农历
$url = 'http://你的域名/gregorian_lunar/index.php?type=solar&year=2027&month=7&day=8';
$result = json_decode(file_get_contents($url), true);
if ($result['status'] === 1) {
    echo $result['data']['lunarText'];  // 农历六月初五
}
?>
```

### ArkTS (HarmonyOS)

```typescript
import http from '@ohos.net.http';

let httpRequest = http.createHttp();
httpRequest.request(
  'http://你的域名/gregorian_lunar/index.php?type=lunar&year=2027&month=6&day=5&isLeap=0',
  { method: http.RequestMethod.GET, expectDataType: http.HttpDataType.STRING },
  (err, data) => {
    if (!err && data.responseCode === 200) {
      let result = JSON.parse(data.result as string)
      if (result.status === 1) {
        console.log(result.data.solar)      // 2027-07-08
        console.log(result.data.lunarText)  // 农历六月初五
      }
    }
    httpRequest.destroy()
  }
)
```

---

## 部署说明

### 目录结构

```
gregorian_lunar/
├── index.php                       # API 入口
├── LunarSolarConverter.class.php   # 核心转换库
├── Demo.php                        # 原始示例
└── API文档.md                       # 本文档
```

### 环境要求

- PHP 5.6+（推荐 PHP 7.0 及以上）
- 无需数据库
- 无需额外扩展

### 部署步骤

1. 将 `gregorian_lunar/` 整个目录上传到 Web 服务器（如 Nginx + PHP-FPM、Apache）
2. 确保 `index.php` 和 `LunarSolarConverter.class.php` 在同一目录
3. 访问 `http://你的域名/gregorian_lunar/index.php?type=solar&year=2026&month=7&day=18` 验证

---

## 注意事项

1. **年份范围**：仅支持 1900 - 2099 年，超出范围会返回错误
2. **闰月处理**：农历转公历时，若该年无指定闰月，转换结果可能不准确，请确保 `isLeap` 参数正确
3. **历史日期**：1582 年 10 月之前的公历日期不准确（儒略历与格里高利历切换），本接口不支持
4. **时区**：接口按东八区（北京时间）计算，星期基于公历日期得出
5. **无频率限制**：本接口为纯计算，无外部依赖，可承受高并发

---

## 数据准确性验证

以下为已验证的对照数据：

| 类型 | 输入 | 输出 | 说明 |
|------|------|------|------|
| 农历转公历 | 2024年正月初一 | 2024-02-10 | 2024年春节 |
| 农历转公历 | 2025年正月初一 | 2025-01-29 | 2025年春节 |
| 农历转公历 | 2026年正月初一 | 2026-02-17 | 2026年春节 |
| 农历转公历 | 2027年六月初五 | 2027-07-08 | - |
| 农历转公历 | 2033年六月初五 | 2033-07-01 | - |
| 农历转公历 | 2035年六月初五 | 2035-07-09 | - |
| 公历转农历 | 2026-02-17 | 农历正月初一 | 2026年春节 |
| 公历转农历 | 2027-07-08 | 农历六月初五 | - |
