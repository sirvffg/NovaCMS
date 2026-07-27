# Nova Links API 文档

基础路径：`/nova-json/v1`

所有 API 均返回 JSON 格式，支持同域访问（不支持跨域）。

---

## 目录

- [友情链接 Links](#友情链接-links)
- [链接分类 Categories](#链接分类-categories)
- [提交申请 Apply](#提交申请-apply)
- [本站信息 Site Info](#本站信息-site-info)
- [通用响应结构](#通用响应结构)

---

## 友情链接 Links

### 获取友链列表

```
GET /v1/links
```

返回所有显示的友情链接，按前台分类顺序排列（"单向友链"和"失联博客"排在最后），并按 `sort_order` 升序排列。

**响应：**

```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": {
    "status": 200,
    "total": 10,
    "items": [
      {
        "id": 1,
        "name": "示例博客",
        "url": "https://example.com",
        "logo": "/uploads/friendlink/logo.svg",
        "description": "一个示例博客",
        "rss_url": "https://example.com/feed",
        "category": {
          "id": 2,
          "name": "友情链接"
        },
        "sort_order": 0,
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "grouped": {
      "友情链接": [
        { "...": "..." }
      ],
      "技术社区": [
        { "...": "..." }
      ]
    }
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `code` | string | 状态码，`rest_ok` 表示成功 |
| `message` | string | 提示消息 |
| `data.status` | int | HTTP 状态码 |
| `data.total` | int | 友链总数 |
| `data.items[].id` | int | 友链 ID |
| `data.items[].name` | string | 网站名称 |
| `data.items[].url` | string | 网站链接 |
| `data.items[].logo` | string/null | 网站 Logo URL |
| `data.items[].description` | string/null | 网站描述 |
| `data.items[].rss_url` | string/null | RSS 订阅地址 |
| `data.items[].category` | object/null | 分类对象：`{ id: int, name: string }`，`null` 表示未分类 |
| `data.items[].sort_order` | int | 排序权重（越小越靠前） |
| `data.items[].created_at` | string | 创建时间 |
| `data.grouped` | object | 按分类名称分组的友链，键为分类名，值为该分类下的友链数组 |

---

## 链接分类 Categories

### 获取分类列表

```
GET /v1/links/categories
```

返回所有友链分类，包含每个分类下的活跃链接数。

**响应：**

```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": {
    "status": 200,
    "total": 5,
    "items": [
      {
        "id": 1,
        "name": "技术社区",
        "description": "技术社区类网站",
        "sort_order": 0,
        "link_count": 3,
        "created_at": "2026-01-01 00:00:00"
      }
    ]
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `code` | string | 状态码 |
| `message` | string | 提示消息 |
| `data.total` | int | 分类总数 |
| `data.items[].id` | int | 分类 ID |
| `data.items[].name` | string | 分类名称 |
| `data.items[].description` | string/null | 分类描述 |
| `data.items[].sort_order` | int | 排序权重（越小越靠前） |
| `data.items[].link_count` | int | 该分类下的活跃友链数 |
| `data.items[].created_at` | string | 创建时间 |

**注意：** `link_count` 仅统计 `is_active = 1` 的友链。

---

## 提交申请 Apply

### 提交友链申请

```
POST /v1/links/apply
```

向站长提交友链申请，申请通过后会在后台显示，站长审核后可添加到友链列表。

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `name` | string | 是 | 网站名称 |
| `url` | string | 是 | 网站链接，需为有效 URL |
| `logo` | string | 否 | 网站 Logo URL |
| `description` | string | 否 | 网站描述 |
| `rss_url` | string | 否 | RSS 订阅地址 |
| `category_id` | int | 否 | 分类 ID（从分类列表获取） |
| `contact_email` | string | 是 | 联系邮箱 |
| `contact_name` | string | 是 | 联系人姓名 |

**请求示例：**

```json
{
  "name": "示例博客",
  "url": "https://example.com",
  "logo": "https://example.com/logo.png",
  "description": "一个示例博客",
  "rss_url": "https://example.com/feed",
  "category_id": 2,
  "contact_email": "admin@example.com",
  "contact_name": "张三"
}
```

**响应（成功）：**

```json
{
  "code": "rest_ok",
  "message": "申请提交成功，我们会尽快审核您的申请！",
  "data": {
    "status": 200,
    "id": 15
  }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.id` | int | 新创建的申请记录 ID |

**错误码：**

| code | HTTP | 说明 |
|------|------|------|
| `rest_error` | 400 | 参数验证失败（如名称/链接/邮箱为空、URL 格式无效等） |
| `rest_error` | 409 | 该链接已存在，或已有待审核的申请 |

---

## 本站信息 Site Info

### 获取本站信息

```
GET /v1/links/siteinfo
```

返回本站的友链信息，供其他站长申请友链时参考填写。

**响应：**

```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": {
    "status": 200,
    "name": "冷月笙寒的小窝",
    "url": "https://lygalaxy.cn/",
    "description": "发现巷子里的那颗星星(技术与生活分享)",
    "rss_url": "https://lygalaxy.cn/license/rss.php",
    "logo": "https://lygalaxy.cn/uploads/logo.svg",
    "author": "Galaxy"
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `code` | string | 状态码 |
| `message` | string | 提示消息 |
| `data.name` | string | 网站名称 |
| `data.url` | string | 网站首页地址 |
| `data.description` | string | 网站简介 |
| `data.rss_url` | string | RSS 订阅地址 |
| `data.logo` | string | 网站 Logo 地址 |
| `data.author` | string | 站点所有者名称 |

---

## 通用响应结构

所有 API 的响应均遵循统一格式：

| 字段 | 类型 | 说明 |
|------|------|------|
| `code` | string | 业务状态码 |
| `message` | string | 人类可读的消息 |
| `data` | object | 响应数据，包含 `status`（HTTP 状态码）及其他业务字段 |

### 通用错误码

| code | HTTP状态码 | 说明 |
|------|-----------|------|
| `rest_ok` | 200 | 请求成功 |
| `rest_error` | 400/409 | 请求失败（具体看 message） |
