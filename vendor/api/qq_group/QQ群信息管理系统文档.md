# QQ 群信息解析与管理系统文档

本项目包含三个核心文件，分别负责前端 UI 展示、QQ 群链接解析 API 以及本地数据库的群列表读取。它们可以组合使用，构建一个完整的 QQ 群信息管理和解析平台。

---

## 文件概述

| 文件名 | 类型 | 主要功能 |
| :--- | :--- | :--- |
| `index.html` | 前端页面 | 提供用户交互界面，支持输入分享链接并调用后端接口展示群的详细信息卡片。 |
| `api.php` | 后端 API | 接收分享链接，模拟浏览器请求真实页面，解析页面源码提取群详细信息并返回 JSON。 |
| `get_groups.php` | 后端 API | 从本地数据库中查询启用了 API 显示的 QQ 群列表及全局通知信息，返回 JSON 数据。 |

---

## 详细说明

### 1. `index.html` - 群信息解析前端界面
这是一个纯静态的 HTML 页面，包含 HTML、CSS 和 JavaScript。

**主要功能：**
- **可视化查询**：提供一个输入框和按钮，用户可以粘贴 `https://qm.qq.com/...` 格式的链接。
- **异步请求**：通过 `fetch` 调用同目录下的 `api.php` 接口，避免跨域 (CORS) 问题。
- **信息渲染**：将接口返回的数据（群名称、群号、人数、标签、活跃度、成员分布、群资产等）渲染成一张精美的卡片。
- **URL 参数支持**：
  - 支持通过 `?url=链接` 自动填充并触发查询。
  - 支持通过 `?url=链接&format=json` 自动重定向至纯 JSON 输出。

**使用示例：**
浏览器访问：`http://你的域名/index.html?url=https://qm.qq.com/q/7qsEndvLgc`

---

### 2. `api.php` - QQ 群链接解析 API
这是一个基于 PHP 的核心解析脚本。

**解析原理：**
1. 接收 GET 参数 `url`。
2. 使用 `cURL` 模拟真实浏览器 (携带 User-Agent) 请求该分享链接。
3. 自动跟随 `302` 重定向跳转至 `qun.qq.com` 落地页。
4. 使用正则表达式 `/<script type="application\/json" data-nuxt-data="nuxt-app"[^>]*>(.*?)<\/script>/s` 提取由 Nuxt.js 渲染在页面中的初始 JSON 数据。
5. 遍历解析后的数组，寻找包含 `"groupinfo"` 的节点，提取群基础信息（群名、群号、人数等）以及附加信息（`activity` 活跃度、`memberTags` 成员标签、`assetInfo` 群资产）。
6. 返回标准的 JSON 格式响应。

**接口响应示例：**
```json
{
  "success": true,
  "info": {
    "name": "冷月笙寒的小窝",
    "groupCode": "1077539098",
    "memberCount": 994,
    "description": "欢迎来到...",
    "tags": ["IT", "信息交流"],
    "activity": [...],
    "memberTags": [...],
    "assetInfo": [...]
  }
}
```

**返回字段说明：**
| 字段 | 类型 | 说明 |
| :--- | :--- | :--- |
| `success` | Boolean | 请求解析是否成功 (`true` 或 `false`)。 |
| `error` | String | 错误信息（仅在 `success` 为 `false` 时存在）。 |
| `info` | Object | 解析成功后返回的群核心信息对象。 |
| `info.name` | String | QQ 群名称。 |
| `info.groupCode` | String | QQ 群号。 |
| `info.memberCount` | Number | 当前群人数。 |
| `info.description` | String | 群介绍或公告内容（可能为空）。 |
| `info.tags` | Array | 群标签数组（如 `["IT", "信息交流"]`）。 |
| `info.avatar` | String | 群头像图片的直链 URL。 |
| `info.createTime` | String | 建群时间（格式化为 `Y/m/d H:i:s`）。 |
| `info.activity` | Array | 群活跃度信息（包含活跃人数统计等对象）。 |
| `info.memberTags` | Array | 成员分布标签（如男女比例、00后人数、地区分布等）。 |
| `info.assetInfo` | Array | 群资产信息（包含群文件数量、相册数量、精华消息数量等对象）。 |

---

### 3. `get_groups.php` - 数据库群列表读取 API
这是一个用于从本地数据库获取已收录 QQ 群列表的接口。

**主要功能：**
- 引入上层目录的 `config/database.php` 建立 PDO 数据库连接。
- 容错查询：
  - 尝试查询 `qq_groups` 表中 `api_show = 1`（允许在 API 中显示）的群组。
  - 如果字段不存在，则平滑降级，返回所有群组。
- 查询全局通知：尝试从 `qq_groups_notification` 表获取站点的全局通知配置。
- 构建包含基础 URL、群名称、链接、最大人数、群介绍等字段的 JSON 数据。

**接口响应示例：**
```json
{
  "code": 200,
  "msg": "获取成功",
  "count": 5,
  "api_url": "http://你的域名/index.html",
  "notification": "欢迎使用本系统",
  "close_wait_time": 5,
  "close_button_text": "我知道了",
  "data": [
    {
      "name": "示例群",
      "link": "https://qm.qq.com/...",
      "max_members": 200,
      "description": "这是一个测试群"
    }
  ]
}
```

**返回字段说明：**
| 字段 | 类型 | 说明 |
| :--- | :--- | :--- |
| `code` | Number | 状态码，`200` 表示成功，`500` 表示服务器或数据库错误。 |
| `msg` | String | 接口执行结果的提示信息。 |
| `count` | Number | 返回的 QQ 群总数量。 |
| `api_url` | String | 当前环境的完整基础 URL 路径，便于前端拼接完整路径。 |
| `notification` | String | （可选）全局通知内容，若没有配置则不返回此字段。 |
| `close_wait_time` | Number | （可选）通知弹窗强制等待时间（秒）。 |
| `close_button_text`| String | （可选）通知弹窗关闭按钮的自定义文本。 |
| `data` | Array | QQ 群列表数组。 |
| `data[].name` | String | 数据库中保存的 QQ 群名称。 |
| `data[].link` | String | 数据库中保存的 QQ 加群分享链接。 |
| `data[].max_members`| Number | 数据库中保存的群最大人数（如：200、500、1000等）。 |
| `data[].description`| String | 数据库中保存的群介绍说明。 |

---

## 部署建议
1. 将这三个文件放置在支持 PHP 的 Web 服务器目录下（如 Nginx + PHP-FPM，或 Apache）。
2. 确保 PHP 启用了 `curl` 和 `pdo` 扩展。
3. 确保存在 `config/database.php` 并配置了正确的数据库连接信息。
4. 可以将 `get_groups.php` 返回的群链接 `link` 传递给 `api.php` 或 `index.html`，实现群信息的实时更新与动态展示。