# Nova Posts API 文档

基础路径：`/nova-json/v1`

所有 API 均返回 JSON 格式，支持同域访问（不支持跨域）。

## 下载文章 Download

### 发送邮箱验证码

```
POST /v1/posts/{id}/download/send-code
```

向当前登录管理员的邮箱发送 6 位验证码，用于下载文章时的身份验证。

**请求参数：** 无需请求体。

**响应（成功）：**

```json
{
  "code": "rest_ok",
  "message": "验证码已发送到您的邮箱",
  "data": {
    "status": 200
  }
}
```

**错误码：**

| code | HTTP | 说明 |
|------|------|------|
| `rest_forbidden` | 403 | 非管理员无权操作 |
| `rest_error` | 400 | 当前用户未绑定有效邮箱 |
| `rest_error` | 500 | 邮件发送失败 |

**注意：**
- **需要登录**，仅管理员可调用
- 验证码 10 分钟有效，发送新验证码会覆盖旧的
- 开发环境（`EMAIL_MODE=test`）会在返回消息中附带验证码

---

### 下载文章

```
GET /v1/posts/{id}/download?password=xxx&code=123456
```

验证通过后跳转至 `vendor/download_article_zip.php` 处理实际 ZIP 下载。

**请求参数：**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `password` | string | 是 | 操作密码，对应 `config/markdown_copy_password.config` 中的值 |
| `code` | string | 是 | 邮箱验证码（通过 send-code 接口获取） |

**注意：**
- 验证流程：管理员角色 → 邮箱验证码 → 操作密码 → 文章存在 → ZIP 下载
- 验证码一次性有效，使用后自动标记为已用
- 此端点为浏览器跳转，客户端应直接打开返回的 URL

---

## 目录

- [文章 Posts](#文章-posts)
- [分类 Categories](#分类-categories)
- [标签 Tags](#标签-tags)
- [搜索 Search](#搜索-search)
- [评论 Comments](#评论-comments)
- [隐私内容 Privacy](#隐私内容-privacy)
- [付费内容 Paid](#付费内容-paid)

---

## 文章 Posts

### 获取文章列表

```
GET /v1/posts
```

**请求参数：**

| 参数 | 类型 | 说明 |
|------|------|------|
| `page` | int | 页码（从1开始） |
| `per_page` | int | 每页条数（最大100，不传则返回全部） |
| `category` | string | 按分类筛选 |
| `tag` | string | 按标签筛选 |
| `search` | string | 关键词搜索（匹配标题/摘要/内容） |
| `content` | int | 设为 `1` 时返回文章全文 |

**响应：**

```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": {
    "status": 200,
    "total": 50,
    "page": 1,
    "per_page": 10,
    "total_pages": 5,
    "items": [
      {
        "id": 1,
        "title": "文章标题",
        "author": "Galaxy",
        "cover_image": "https://example.com/cover.jpg",
        "category": "技术",
        "tags": ["PHP", "API"],
        "views": 128,
        "is_pinned": true,
        "is_featured": false,
        "published_at": "2026-07-24 12:00:00",
        "created_at": "2026-07-24 12:00:00",
        "updated_at": "2026-07-24 12:00:00",
        "license": "CC BY-NC 4.0",
        "has_privacy_content": true,
        "has_paid_content": false,
        "current_user_id": 0,
        "current_user_has_privacy_access": false,
        "current_user_has_paid_access": false,
        "privacy_type": "fixed_answer"
      }
    ]
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `code` | string | 状态码，`rest_ok` 表示成功 |
| `message` | string | 提示消息 |
| `data.status` | int | HTTP 状态码 |
| `data.total` | int | 文章总数 |
| `data.page` | int | 当前页码 |
| `data.per_page` | int | 每页条数 |
| `data.total_pages` | int | 总页数 |
| `data.items[].id` | int | 文章 ID |
| `data.items[].title` | string | 文章标题 |
| `data.items[].author` | string | 作者 |
| `data.items[].cover_image` | string/null | 封面图 URL |
| `data.items[].category` | string | 分类名称 |
| `data.items[].tags` | string[] | 标签数组 |
| `data.items[].views` | int | 浏览次数 |
| `data.items[].is_pinned` | bool | 是否置顶 |
| `data.items[].is_featured` | bool | 是否精选 |
| `data.items[].published_at` | string | 发布时间 |
| `data.items[].created_at` | string | 创建时间 |
| `data.items[].updated_at` | string | 更新时间 |
| `data.items[].license` | string/null | 许可协议 |
| `data.items[].has_privacy_content` | bool | 是否包含隐私内容 |
| `data.items[].has_paid_content` | bool | 是否包含付费内容 |
| `data.items[].current_user_id` | int | 当前用户 ID（0=未登录） |
| `data.items[].current_user_has_privacy_access` | bool | 当前用户是否有隐私内容权限 |
| `data.items[].current_user_has_paid_access` | bool | 当前用户是否有付费内容权限 |
| `data.items[].privacy_type` | string | 隐私类型（仅 `has_privacy_content` 为 true 时出现）：`login_only`/`fixed_answer`/`open_answer`/`manual_approval` |

---

### 获取文章详情

```
GET /v1/posts/{id}
```

**请求参数：**

| 参数 | 类型 | 说明 |
|------|------|------|
| `id` | int | 文章ID |

**响应：**

```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": {
    "status": 200,
    "item": {
      "id": 1,
      "title": "文章标题",
      "author": "Galaxy",
      "content": "文章完整内容...",
      "category": "技术",
      "tags": ["PHP", "API"],
      "has_privacy_content": true,
      "has_paid_content": false,
      "current_user_id": 0,
      "current_user_has_privacy_access": false,
      "current_user_has_paid_access": false,
      "privacy_type": "fixed_answer"
    }
  }
}
```

**返回字段说明：**

列表中的字段同上，额外字段：

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.item.content` | string | 文章全文。`[Privacy]...[/Privacy]` 和 `[Paid]...[/Paid]` 区域会根据当前用户权限自动过滤：有权限时显示内容，无权限时显示提示信息 |
| `data.item.post_price` | float | 付费价格（仅 `has_paid_content` 为 true 时出现） |

---

### 按标识查文章

```
GET /v1/posts/slug/{slug}
```

**请求参数：**

| 参数 | 类型 | 说明 |
|------|------|------|
| `slug` | string | 文章标题（URL编码）或数字ID |

返回格式与文章详情相同。

---

## 分类 Categories

### 获取分类列表

```
GET /v1/categories
```

**响应：**

```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": {
    "status": 200,
    "items": [
      {
        "id": 1,
        "name": "技术",
        "slug": "tech",
        "description": "技术相关文章",
        "color": "#007bff",
        "sort_order": 0,
        "post_count": 15,
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
| `data.items[].id` | int | 分类 ID |
| `data.items[].name` | string | 分类名称 |
| `data.items[].slug` | string | 分类别名（URL友好） |
| `data.items[].description` | string | 分类描述 |
| `data.items[].color` | string | 分类颜色（十六进制） |
| `data.items[].sort_order` | int | 排序权重（越小越靠前） |
| `data.items[].post_count` | int | 该分类下的文章数 |
| `data.items[].created_at` | string | 创建时间 |

---

### 创建分类

```
POST /v1/categories
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `name` | string | 是 | 分类名称 |
| `slug` | string | 否 | 别名，不传则自动生成 |
| `description` | string | 否 | 描述 |
| `sort_order` | int | 否 | 排序权重，默认0 |
| `color` | string | 否 | 颜色，默认 `#007bff` |

**响应：**

```json
{
  "code": "rest_ok",
  "message": "分类已创建",
  "data": {
    "status": 201,
    "id": 5
  }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.id` | int | 新创建的分类 ID |

> 需要登录，仅管理员可操作。

---

### 更新分类

```
PUT /v1/categories/{id}
```

**请求体：** 与创建相同。

**响应：**

```json
{
  "code": "rest_ok",
  "message": "分类已更新",
  "data": {
    "status": 200,
    "id": 5
  }
}
```

> 需要登录，仅管理员可操作。

---

### 删除分类

```
DELETE /v1/categories/{id}
```

删除分类时，该分类下所有文章的 `category` 字段会被自动清空。

**响应：**

```json
{
  "code": "rest_ok",
  "message": "分类已删除，已清空 3 篇文章的分类",
  "data": {
    "status": 200,
    "id": 5,
    "cleared_post_count": 3
  }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.id` | int | 被删除的分类 ID |
| `data.cleared_post_count` | int | 受影响的文章数量 |

> 需要登录，仅管理员可操作。

---

## 标签 Tags

### 获取标签列表

```
GET /v1/tags
```

返回所有标签及使用次数，按使用量降序排列。

**响应：**

```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": {
    "status": 200,
    "items": [
      {
        "id": "md5hash",
        "name": "PHP",
        "count": 12
      },
      {
        "id": "md5hash",
        "name": "JavaScript",
        "count": 8
      }
    ]
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.items[].id` | string | 标签唯一标识（MD5(name)） |
| `data.items[].name` | string | 标签名称 |
| `data.items[].count` | int | 使用该标签的文章数量 |

---

## 搜索 Search

### 搜索文章

```
GET /v1/search
```

**请求参数：**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `q` | string | 是 | 搜索关键词 |
| `field` | string | 否 | 搜索字段：`all`（默认，搜索标题+内容）、`title`、`tags`、`content` |
| `page` | int | 否 | 页码 |
| `per_page` | int | 否 | 每页条数（最大50，不传则返回全部） |

**响应：**

```json
{
  "code": "rest_ok",
  "message": "搜索成功",
  "data": {
    "status": 200,
    "total": 5,
    "page": 1,
    "per_page": 10,
    "total_pages": 1,
    "query": "PHP",
    "field": "all",
    "type": "post",
    "items": [
      {
        "id": 1,
        "title": "PHP 入门指南",
        "author": "Galaxy",
        "cover_image": null,
        "category": "技术",
        "tags": ["PHP"],
        "published_at": "2026-07-24 12:00:00",
        "has_privacy_content": false,
        "has_paid_content": false,
        "type": "post"
      }
    ]
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.query` | string | 搜索关键词（回显） |
| `data.field` | string | 搜索字段 |
| `data.type` | string | 搜索类型（目前仅 `post`） |
| `data.items[].id` | int | 文章 ID |
| `data.items[].title` | string | 文章标题 |
| `data.items[].author` | string | 作者 |
| `data.items[].cover_image` | string/null | 封面图 |
| `data.items[].category` | string | 分类 |
| `data.items[].tags` | string[] | 标签数组 |
| `data.items[].published_at` | string | 发布时间 |
| `data.items[].has_privacy_content` | bool | 是否包含隐私内容 |
| `data.items[].has_paid_content` | bool | 是否包含付费内容 |
| `data.items[].type` | string | 固定为 `post` |

其他分页字段（`total`/`page`/`per_page`/`total_pages`）与文章列表相同。

---

## 评论 Comments

### 获取评论列表

```
GET /v1/comments
```

**请求参数：**

| 参数 | 类型 | 说明 |
|------|------|------|
| `page` | int | 页码 |
| `per_page` | int | 每页条数（最大100，不传则返回全部） |
| `post_id` | int | 按文章筛选 |

**响应：**

```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": {
    "status": 200,
    "total": 20,
    "page": 1,
    "per_page": 10,
    "total_pages": 2,
    "items": [
      {
        "id": 1,
        "post_id": 1,
        "post_title": "文章标题",
        "username": "访客",
        "content": "评论内容",
        "parent_id": null,
        "created_at": "2026-07-24 12:00:00",
        "email": "user@example.com"
      }
    ]
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.items[].id` | int | 评论 ID |
| `data.items[].post_id` | int | 关联文章 ID |
| `data.items[].post_title` | string | 关联文章标题 |
| `data.items[].username` | string | 评论者用户名 |
| `data.items[].email` | string | 评论者邮箱（**仅管理员可见**，普通用户不返回此字段） |
| `data.items[].content` | string | 评论内容 |
| `data.items[].parent_id` | int/null | 父级评论 ID（`null` 表示顶层评论） |
| `data.items[].created_at` | string | 评论时间 |

---

### 获取评论详情

```
GET /v1/comments/{id}
```

**响应：**

```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": {
    "status": 200,
    "item": {
      "id": 1,
      "post_id": 1,
      "post_title": "文章标题",
      "username": "访客",
      "content": "评论内容",
      "parent_id": null,
      "status": "approved",
      "created_at": "2026-07-24 12:00:00"
    }
  }
}
```

**额外字段：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.item.status` | string | 评论状态：`approved`（已审核）/ `pending`（待审核）/ `spam`（垃圾） |

---

### 添加评论 / 回复评论

```
POST /v1/comments
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `post_id` | int | 是 | 文章 ID |
| `content` | string | 是 | 评论内容（最长 1000 字符） |
| `parent_id` | int/null | 否 | 父级评论 ID。不传或 `null` 为顶层评论，传入则作为回复 |

**响应（成功）：**

```json
{
  "code": "rest_ok",
  "message": "评论成功",
  "data": {
    "status": 201,
    "comment_id": 10,
    "comment": {
      "id": 10,
      "post_id": 1,
      "user_id": 2,
      "username": "用户",
      "email": "user@example.com",
      "content": "评论内容",
      "parent_id": null,
      "status": "approved",
      "created_at": "2026-07-24 12:00:00"
    }
  }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.comment_id` | int | 新创建的评论 ID |
| `data.comment` | object | 完整评论对象 |

**注意：**
- **需要登录**，未登录返回 401
- 自带频率限制：同一用户 10 分钟内最多 300 条评论
- `parent_id` 为回复的父评论 ID，实现嵌套回复

---

### 删除评论

```
DELETE /v1/comments/{id}
```

**响应（成功）：**

```json
{
  "code": "rest_ok",
  "message": "评论已删除",
  "data": {
    "status": 200
  }
}
```

**注意：**
- **需要登录**，未登录返回 401
- **管理员**可删除任意评论
- **普通用户**只能删除自己的评论
- 删除评论时会同时删除该评论下的所有回复（子评论）

---

## 隐私内容 Privacy

### 提交隐私答案

```
POST /v1/posts/privacy
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `post_id` | int | 是 | 文章 ID |
| `answer` | string | 否 | 答案。不传此字段则返回隐私问题 |

**场景一：获取隐私问题（不传 `answer`）**

```json
// 请求 {"post_id": 1}
// 响应
{
  "code": "rest_need_answer",
  "message": "请回答隐私问题",
  "data": {
    "status": 400,
    "question": "我的宠物叫什么名字？",
    "privacy_type": "fixed_answer",
    "custom_text": ""
  }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.question` | string | 隐私问题文本 |
| `data.privacy_type` | string | 隐私类型：`fixed_answer`（固定答案）/ `open_answer`（开放答案）/ `manual_approval`（人工审核） |
| `data.custom_text` | string | 自定义提示文本 |

**场景二：答案正确**

```json
{
  "code": "rest_ok",
  "message": "答案正确，您现在可以查看隐私内容",
  "data": {
    "status": 200,
    "access_granted": true,
    "pending_approval": false
  }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.access_granted` | bool | 是否获得访问权限 |
| `data.pending_approval` | bool | 是否正在等待管理员审核 |

**场景三：需要管理员审核（open_answer / manual_approval）**

```json
{
  "code": "rest_ok",
  "message": "您的答案已提交，等待管理员审核",
  "data": {
    "status": 200,
    "access_granted": false,
    "pending_approval": true
  }
}
```

**场景四：答案错误**

```json
{
  "code": "rest_privacy_denied",
  "message": "答案错误，请重试",
  "data": {
    "status": 403,
    "access_granted": false,
    "pending_approval": false
  }
}
```

**注意：**
- **需要登录**，未登录返回 401
- **管理员**直接拥有权限，无需回答
- **`login_only` 类型**：登录即授权，无需提交

---

## 付费内容 Paid

### 检查付费内容访问状态

```
POST /v1/posts/paid
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `post_id` | int | 是 | 文章 ID |

**场景一：已付费 / 管理员**

```json
{
  "code": "rest_ok",
  "message": "您已获得该文章的付费内容访问权限",
  "data": {
    "status": 200,
    "has_access": true,
    "post_id": 1,
    "post_title": "文章标题",
    "price": null,
    "pay_url": null
  }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.has_access` | bool | 是否有访问权限 |
| `data.post_id` | int | 文章 ID |
| `data.post_title` | string | 文章标题 |
| `data.price` | float/null | 价格（已付费时为 `null`） |
| `data.pay_url` | string/null | 支付链接（已付费时为 `null`） |

**场景二：未付费**

```json
{
  "code": "rest_ok",
  "message": "该文章包含付费内容，需要支付后才能查看",
  "data": {
    "status": 200,
    "has_access": false,
    "post_id": 1,
    "post_title": "文章标题",
    "price": 9.99,
    "pay_url": "/vendor/epay/pay.php?post_id=1"
  }
}
```

**注意：**
- **需要登录**，未登录返回 401
- **管理员**自动拥有权限

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
| `rest_ok` | 200/201 | 请求成功 |
| `rest_not_logged_in` | 401 | 未登录 |
| `rest_forbidden` | 403 | 权限不足 |
| `rest_no_route` | 404 | 路由不存在 |
| `post_not_found` | 404 | 文章不存在 |
| `comment_not_found` | 404 | 评论不存在 |
| `rest_missing_fields` | 400 | 缺少必填参数 |
| `rest_need_answer` | 400 | 需要回答隐私问题 |
| `rest_no_privacy_content` | 400 | 文章没有隐私内容 |
| `rest_no_paid_content` | 400 | 文章没有付费内容 |
| `rest_duplicate_slug` | 409 | 别名已存在 |
| `rest_category_not_found` | 404 | 分类不存在 |
