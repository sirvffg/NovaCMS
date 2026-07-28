# NovaCMS API 文档

> 基础路径: `/nova-json`  
> 命名空间: `v1`  
> 响应格式: JSON

---

## 目录

1. [Posts - 文章模块](#1-posts---文章模块)
2. [Categories - 分类模块](#2-categories---分类模块)
3. [Tags - 标签模块](#3-tags---标签模块)
4. [Search - 搜索模块](#4-search---搜索模块)
5. [Comments - 评论模块](#5-comments---评论模块)
6. [Links - 友链模块](#6-links---友链模块)
7. [Auth - 认证模块](#7-auth---认证模块)
8. [Users - 用户管理模块](#8-users---用户管理模块)
9. [Statuses - 动态模块](#9-statuses---动态模块)
10. [Public - 公共模块](#10-public---公共模块)

---

## 通用响应结构

### 成功响应

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        // ... 业务数据
    }
}
```

### 错误响应

```json
{
    "code": "rest_error",
    "message": "错误描述信息",
    "data": {
        "status": 400
    }
}
```

### HTTP 状态码

| 状态码 | 说明 |
|--------|------|
| 200 | 成功 |
| 201 | 创建成功 |
| 204 | 预检请求成功（无内容） |
| 400 | 请求参数错误 |
| 401 | 未登录/未授权 |
| 403 | 权限不足 |
| 404 | 资源不存在 |
| 409 | 资源冲突（重复） |
| 429 | 请求频率过高 |
| 500 | 服务器内部错误 |

---

## 1. Posts - 文章模块

### 1.1 获取文章列表

`GET /v1/posts`

获取已发布的文章列表，支持分页、筛选。

**请求参数**

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| page | int | 否 | 1 | 页码，需配合 per_page 使用 |
| per_page | int | 否 | - | 每页条数(最大100)，不传则返回全部 |
| category | string | 否 | - | 按分类名称筛选 |
| tag | string | 否 | - | 按标签名称筛选 |
| search | string | 否 | - | 关键词搜索(匹配标题、摘要、内容) |
| content | bool | 否 | false | 是否返回文章全文内容 |

**示例请求**

```
GET /v1/posts?page=1&per_page=10&category=技术&content=1
```

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.status | int | HTTP 状态码 |
| data.total | int | 文章总数 |
| data.page | int | 当前页码 |
| data.per_page | int | 每页条数 |
| data.total_pages | int | 总页数 |
| data.items[].id | int | 文章ID |
| data.items[].title | string | 文章标题 |
| data.items[].author | string | 作者 |
| data.items[].cover_image | string | 封面图URL |
| data.items[].category | string | 分类名称 |
| data.items[].tags | array | 标签数组 |
| data.items[].views | int | 浏览量 |
| data.items[].is_pinned | bool | 是否置顶 |
| data.items[].is_featured | bool | 是否精选 |
| data.items[].published_at | string | 发布时间 |
| data.items[].created_at | string | 创建时间 |
| data.items[].updated_at | string | 更新时间 |
| data.items[].license | string | 许可协议 |
| data.items[].has_privacy_content | bool | 是否有隐私内容 |
| data.items[].has_paid_content | bool | 是否有付费内容 |
| data.items[].current_user_id | int | 当前用户ID |
| data.items[].current_user_has_privacy_access | bool | 当前用户是否有隐私访问权限 |
| data.items[].current_user_has_paid_access | bool | 当前用户是否有付费访问权限 |
| data.items[].content | string | 文章全文(仅当 content=1 时返回) |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "total": 25,
        "page": 1,
        "per_page": 10,
        "total_pages": 3,
        "items": [
            {
                "id": 1,
                "title": "PHP 入门指南",
                "author": "admin",
                "cover_image": "/uploads/covers/php-guide.jpg",
                "category": "技术",
                "tags": ["PHP", "入门", "编程"],
                "views": 1280,
                "is_pinned": true,
                "is_featured": false,
                "published_at": "2026-07-20 10:00:00",
                "created_at": "2026-07-20 10:00:00",
                "updated_at": "2026-07-25 14:30:00",
                "license": "CC BY-NC-SA 4.0",
                "has_privacy_content": false,
                "has_paid_content": true,
                "current_user_id": 1,
                "current_user_has_privacy_access": false,
                "current_user_has_paid_access": true,
                "content": "这是文章全文内容..."
            }
        ]
    }
}
```

---

### 1.2 获取文章详情

`GET /v1/posts/{id}`

根据文章ID获取单篇文章详情。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 文章ID |

**示例请求**

```
GET /v1/posts/1
```

**响应字段说明**

同 1.1 中的 items[] 内各字段。

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "item": {
            "id": 1,
            "title": "PHP 入门指南",
            "author": "admin",
            "cover_image": "/uploads/covers/php-guide.jpg",
            "category": "技术",
            "tags": ["PHP", "入门", "编程"],
            "views": 1280,
            "is_pinned": true,
            "is_featured": false,
            "published_at": "2026-07-20 10:00:00",
            "created_at": "2026-07-20 10:00:00",
            "updated_at": "2026-07-25 14:30:00",
            "license": "CC BY-NC-SA 4.0",
            "has_privacy_content": false,
            "has_paid_content": true,
            "current_user_id": 1,
            "current_user_has_privacy_access": false,
            "current_user_has_paid_access": true,
            "content": "这是包含付费标记的全文内容..."
        }
    }
}
```

---

### 1.3 按标识(slug)获取文章

`GET /v1/posts/slug/{slug}`

根据标题或ID获取文章。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| slug | string | 文章标题(URL编码)或文章ID |

**示例请求**

```
GET /v1/posts/slug/PHP%20入门指南
```

**响应字段说明**

同 1.1 中 items[] 内各字段。

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "item": {
            "id": 1,
            "title": "PHP 入门指南",
            "author": "admin",
            "content": "文章全文内容...",
            // ... 其他字段同文章详情
        }
    }
}
```

---

### 1.4 检查付费内容访问

`POST /v1/posts/paid`

检查或获取付费内容的访问状态。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| post_id | int | 是 | 文章ID |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.status | int | HTTP 状态码 |
| data.has_access | bool | 是否已有访问权限 |
| data.post_id | int | 文章ID |
| data.post_title | string | 文章标题 |
| data.price | float/null | 价格（已有权限时为 null） |
| data.pay_url | string/null | 支付链接（已有权限时为 null） |

**模拟请求**

```json
POST /v1/posts/paid
{
    "post_id": 1
}
```

**模拟响应（未支付）**

```json
{
    "code": "rest_ok",
    "message": "该文章包含付费内容，需要支付后才能查看",
    "data": {
        "status": 200,
        "has_access": false,
        "post_id": 1,
        "post_title": "PHP 入门指南",
        "price": 9.99,
        "pay_url": "/vendor/public/epay/pay.php?post_id=1"
    }
}
```

**模拟响应（已支付）**

```json
{
    "code": "rest_ok",
    "message": "您已获得该文章的付费内容访问权限",
    "data": {
        "status": 200,
        "has_access": true,
        "post_id": 1,
        "post_title": "PHP 入门指南",
        "price": null,
        "pay_url": null
    }
}
```

---

### 1.5 提交隐私问题答案

`POST /v1/posts/privacy`

提交隐私问题的答案以申请隐私内容访问权限。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| post_id | int | 是 | 文章ID |
| answer | string | 否 | 隐私问题答案（首次请求可不传，获取问题） |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.access_granted | bool | 是否获得访问权限 |
| data.question | string | 隐私问题(仅首次请求时) |
| data.privacy_type | string | 隐私类型 |
| data.custom_text | string | 自定义说明文本 |
| data.pending_approval | bool | 是否需要管理员审批 |

**模拟请求**

```json
POST /v1/posts/privacy
{
    "post_id": 1,
    "answer": "我的答案是..."
}
```

**模拟响应（需要回答问题）**

```json
{
    "code": "rest_need_answer",
    "message": "请回答隐私问题",
    "data": {
        "status": 400,
        "question": "您最喜欢的编程语言是什么？",
        "privacy_type": "answer",
        "custom_text": "提示：请回答您注册时设置的问题"
    }
}
```

**模拟响应（答案正确）**

```json
{
    "code": "rest_ok",
    "message": "验证通过",
    "data": {
        "status": 200,
        "access_granted": true,
        "pending_approval": false
    }
}
```

---

### 1.6 发送下载验证码

`POST /v1/posts/{id}/download/send-code`

向管理员邮箱发送文章下载所需的验证码（需管理员权限）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 文章ID |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.status | int | HTTP 状态码 |

**模拟请求**

```
POST /v1/posts/1/download/send-code
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "验证码已发送到您的邮箱",
    "data": {
        "status": 200
    }
}
```

---

### 1.7 下载文章

`GET /v1/posts/{id}/download`

下载文章的 ZIP 压缩包（重定向至下载处理页面）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 文章ID |

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| code | string | 否 | 邮箱验证码 |
| password | string | 否 | 下载密码 |

**示例请求**

```
GET /v1/posts/1/download?code=123456&password=mypassword
```

**响应**

该接口会 302 重定向到 `/vendor/download_article_zip.php` 处理实际下载。

---

## 2. Categories - 分类模块

### 2.1 获取分类列表

`GET /v1/categories`

获取所有文章分类（含文章数）。

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.items[].id | int | 分类ID |
| data.items[].name | string | 分类名称 |
| data.items[].slug | string | 分类别名 |
| data.items[].description | string | 分类描述 |
| data.items[].color | string | 颜色标识 |
| data.items[].sort_order | int | 排序值 |
| data.items[].post_count | int | 该分类下文章数量 |
| data.items[].created_at | string | 创建时间 |

**模拟响应**

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
                "sort_order": 1,
                "post_count": 15,
                "created_at": "2026-01-01 00:00:00"
            },
            {
                "id": 2,
                "name": "生活",
                "slug": "life",
                "description": "生活随笔",
                "color": "#28a745",
                "sort_order": 2,
                "post_count": 10,
                "created_at": "2026-01-01 00:00:00"
            }
        ]
    }
}
```

---

### 2.2 创建分类

`POST /v1/categories`

创建新分类（需登录）。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| name | string | 是 | 分类名称 |
| slug | string | 否 | 分类别名（自动生成） |
| description | string | 否 | 分类描述 |
| sort_order | int | 否 | 排序值（默认0） |
| color | string | 否 | 颜色（默认 #007bff） |

**模拟请求**

```json
POST /v1/categories
{
    "name": "教程",
    "slug": "tutorial",
    "description": "各类教程",
    "sort_order": 3,
    "color": "#dc3545"
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "分类已创建",
    "data": {
        "status": 201,
        "id": 3
    }
}
```

---

### 2.3 更新分类

`PUT /v1/categories/{id}`

更新指定分类（需登录）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 分类ID |

**请求参数**

同 2.2 创建分类参数。

**模拟请求**

```json
PUT /v1/categories/3
{
    "name": "高级教程",
    "sort_order": 4
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "分类已更新",
    "data": {
        "status": 200,
        "id": 3
    }
}
```

---

### 2.4 删除分类

`DELETE /v1/categories/{id}`

删除指定分类，同时将该分类下文章的分类置空（需登录）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 分类ID |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.cleared_post_count | int | 受影响（清空分类）的文章数 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "分类已删除，已清空 3 篇文章的分类",
    "data": {
        "status": 200,
        "id": 3,
        "cleared_post_count": 3
    }
}
```

---

## 3. Tags - 标签模块

### 3.1 获取标签列表

`GET /v1/tags`

获取所有标签及其文章数（按使用量降序）。

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.items[].id | string | 标签ID（基于名称的MD5） |
| data.items[].name | string | 标签名称 |
| data.items[].count | int | 使用该标签的文章数 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "items": [
            {
                "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
                "name": "PHP",
                "count": 8
            },
            {
                "id": "b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7",
                "name": "JavaScript",
                "count": 5
            },
            {
                "id": "c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8",
                "name": "Vue",
                "count": 3
            }
        ]
    }
}
```

---

## 4. Search - 搜索模块

### 4.1 全局搜索

`GET /v1/search`

跨内容搜索，目前支持文章搜索。

**请求参数**

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| q | string | 是 | - | 搜索关键词 |
| type | string | 否 | post | 搜索类型（目前仅支持 post） |
| field | string | 否 | all | 搜索字段：all(全部)、title、tags、content |
| page | int | 否 | 1 | 页码 |
| per_page | int | 否 | - | 每页条数(最大50)，不传返回全部 |

**示例请求**

```
GET /v1/search?q=PHP&field=title&page=1&per_page=10
```

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.query | string | 搜索关键词 |
| data.field | string | 搜索字段 |
| data.type | string | 搜索类型 |
| data.total | int | 搜索结果总数 |
| data.items[].id | int | 文章ID |
| data.items[].title | string | 文章标题 |
| data.items[].author | string | 作者 |
| data.items[].cover_image | string | 封面图 |
| data.items[].category | string | 分类 |
| data.items[].tags | array | 标签 |
| data.items[].published_at | string | 发布时间 |
| data.items[].has_privacy_content | bool | 是否有隐私内容 |
| data.items[].has_paid_content | bool | 是否有付费内容 |
| data.items[].type | string | 内容类型（固定 post） |

**模拟响应**

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
        "field": "title",
        "type": "post",
        "items": [
            {
                "id": 1,
                "title": "PHP 入门指南",
                "author": "admin",
                "cover_image": "/uploads/covers/php-guide.jpg",
                "category": "技术",
                "tags": ["PHP", "入门", "编程"],
                "published_at": "2026-07-20 10:00:00",
                "has_privacy_content": false,
                "has_paid_content": false,
                "type": "post"
            }
        ]
    }
}
```

---

## 5. Comments - 评论模块

### 5.1 获取评论列表

`GET /v1/comments`

获取已审核的评论列表，支持分页和按文章筛选。

**请求参数**

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| page | int | 否 | 1 | 页码 |
| per_page | int | 否 | - | 每页条数(最大100)，不传返回全部 |
| post_id | int | 否 | - | 按文章ID筛选 |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.items[].id | int | 评论ID |
| data.items[].post_id | int | 文章ID |
| data.items[].post_title | string | 文章标题 |
| data.items[].username | string | 评论者用户名 |
| data.items[].content | string | 评论内容 |
| data.items[].parent_id | int/null | 父评论ID（回复时） |
| data.items[].created_at | string | 评论时间 |
| data.items[].email | string | 评论者邮箱（仅管理员可见） |

**模拟响应**

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
                "post_id": 1,
                "post_title": "PHP 入门指南",
                "username": "张三",
                "content": "非常好的入门文章！",
                "parent_id": null,
                "created_at": "2026-07-21 15:30:00"
            },
            {
                "id": 2,
                "post_id": 1,
                "post_title": "PHP 入门指南",
                "username": "李四",
                "content": "谢谢分享！",
                "parent_id": 1,
                "created_at": "2026-07-21 16:00:00"
            }
        ]
    }
}
```

---

### 5.2 获取评论详情

`GET /v1/comments/{id}`

获取单条评论详情。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 评论ID |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "item": {
            "id": 1,
            "post_id": 1,
            "post_title": "PHP 入门指南",
            "username": "张三",
            "content": "非常好的入门文章！",
            "parent_id": null,
            "status": "approved",
            "created_at": "2026-07-21 15:30:00"
        }
    }
}
```

---

### 5.3 添加评论

`POST /v1/comments`

添加评论或回复（需登录）。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| post_id | int | 是 | 文章ID |
| content | string | 是 | 评论内容 |
| parent_id | int/null | 否 | 父评论ID（回复时传） |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.comment_id | int | 新评论ID |
| data.comment | array | 评论详情 |

**模拟请求**

```json
POST /v1/comments
{
    "post_id": 1,
    "content": "写的很棒！",
    "parent_id": null
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "评论成功",
    "data": {
        "status": 201,
        "comment_id": 3,
        "comment": {
            "id": 3,
            "post_id": 1,
            "content": "写的很棒！",
            "username": "admin",
            "created_at": "2026-07-28 10:00:00"
        }
    }
}
```

---

### 5.4 删除评论

`DELETE /v1/comments/{id}`

删除指定评论（需登录）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 评论ID |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "评论已删除",
    "data": {
        "status": 200
    }
}
```

---

## 6. Links - 友链模块

### 6.1 获取友链列表

`GET /v1/links`

获取所有活跃的友情链接（按分类分组）。

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.total | int | 友链总数 |
| data.items[].id | int | 友链ID |
| data.items[].name | string | 网站名称 |
| data.items[].url | string | 网站链接 |
| data.items[].logo | string | Logo地址 |
| data.items[].description | string | 网站描述 |
| data.items[].rss_url | string | RSS地址 |
| data.items[].category | object/null | 分类信息{id, name} |
| data.items[].sort_order | int | 排序值 |
| data.items[].created_at | string | 创建时间 |
| data.grouped | object | 按分类名分组的好友链 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "total": 6,
        "items": [
            {
                "id": 1,
                "name": "示例博客",
                "url": "https://example.com",
                "logo": "https://example.com/logo.png",
                "description": "一个示例博客",
                "rss_url": "https://example.com/rss",
                "category": {
                    "id": 1,
                    "name": "技术博客"
                },
                "sort_order": 0,
                "created_at": "2026-01-01 00:00:00"
            }
        ],
        "grouped": {
            "技术博客": [
                {
                    "id": 1,
                    "name": "示例博客",
                    "url": "https://example.com",
                    "logo": "https://example.com/logo.png",
                    "description": "一个示例博客",
                    "rss_url": "https://example.com/rss",
                    "category": {
                        "id": 1,
                        "name": "技术博客"
                    },
                    "sort_order": 0,
                    "created_at": "2026-01-01 00:00:00"
                }
            ],
            "生活博客": [],
            "未分类": []
        }
    }
}
```

---

### 6.2 获取友链分类

`GET /v1/links/categories`

获取所有友链分类（含各分类下活跃友链数量）。

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.items[].id | int | 分类ID |
| data.items[].name | string | 分类名称 |
| data.items[].description | string | 分类描述 |
| data.items[].sort_order | int | 排序值 |
| data.items[].link_count | int | 该分类下活跃友链数 |
| data.items[].created_at | string | 创建时间 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "total": 3,
        "items": [
            {
                "id": 1,
                "name": "技术博客",
                "description": "优秀的技术博客",
                "sort_order": 1,
                "link_count": 3,
                "created_at": "2026-01-01 00:00:00"
            },
            {
                "id": 2,
                "name": "生活博客",
                "description": "记录生活点滴",
                "sort_order": 2,
                "link_count": 2,
                "created_at": "2026-01-01 00:00:00"
            }
        ]
    }
}
```

---

### 6.3 提交友链申请

`POST /v1/links/apply`

提交友情链接申请。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| name | string | 是 | 网站名称 |
| url | string | 是 | 网站链接 |
| logo | string | 否 | Logo地址 |
| description | string | 否 | 网站描述 |
| rss_url | string | 否 | RSS地址 |
| category_id | int | 否 | 分类ID |
| contact_email | string | 是 | 联系邮箱 |
| contact_name | string | 是 | 联系人姓名 |

**模拟请求**

```json
POST /v1/links/apply
{
    "name": "我的博客",
    "url": "https://myblog.com",
    "logo": "https://myblog.com/logo.png",
    "description": "分享技术和生活",
    "contact_email": "admin@myblog.com",
    "contact_name": "站长"
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "申请提交成功，我们会尽快审核您的申请！",
    "data": {
        "status": 200,
        "id": 1
    }
}
```

---

### 6.4 获取本站信息

`GET /v1/links/siteinfo`

获取本站信息，供其他网站添加友链时参考。

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.name | string | 网站名称 |
| data.url | string | 网站地址 |
| data.description | string | 网站简介 |
| data.rss_url | string | RSS地址 |
| data.logo | string | Logo地址 |
| data.author | string | 站长名称 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "name": "冷月笙寒的小窝",
        "url": "https://example.com/",
        "description": "个人博客，分享技术与生活",
        "rss_url": "https://example.com/license/rss.php",
        "logo": "https://example.com/logo.png",
        "author": "冷月笙寒"
    }
}
```

---

## 7. Auth - 认证模块

### 7.1 登录（第一步：验证凭据，发送邮箱验证码）

`POST /v1/auth/login`

验证用户名/邮箱和密码，通过后发送邮箱验证码。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 用户名或邮箱 |
| password | string | 是 | 密码 |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.requires_verification | bool | 是否需要二次验证 |
| data.email_hint | string | 脱敏后的邮箱地址 |

**模拟请求**

```json
POST /v1/auth/login
{
    "username": "admin",
    "password": "mypassword"
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "验证码已发送到您的邮箱",
    "data": {
        "status": 200,
        "requires_verification": true,
        "email_hint": "ad***@example.com"
    }
}
```

---

### 7.2 验证码验证（第二步：完成登录）

`POST /v1/auth/verify`

验证邮箱验证码，完成登录，返回 Token。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 用户名或邮箱 |
| code | string | 是 | 邮箱验证码(6位数字) |
| remember_me | bool | 否 | 是否记住登录（默认 false） |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.token | string | Bearer Token（用于API认证） |
| data.user.id | int | 用户ID |
| data.user.username | string | 用户名 |
| data.user.email | string | 邮箱 |
| data.user.role | string | 角色(admin/user) |

**模拟请求**

```json
POST /v1/auth/verify
{
    "username": "admin",
    "code": "123456",
    "remember_me": true
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "登录成功",
    "data": {
        "status": 200,
        "token": "abc123def456...",
        "user": {
            "id": 1,
            "username": "admin",
            "email": "admin@example.com",
            "role": "admin"
        }
    }
}
```

> **注意**: 之后请求需在 `Authorization` 头携带 `Bearer {token}` 或使用 Session Cookie。

---

### 7.3 注册（第一步：验证信息，发送邮箱验证码）

`POST /v1/auth/register`

提交注册信息，发送邮箱验证码。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 用户名(2-20字符，允许字母、数字、下划线、中文) |
| email | string | 是 | 邮箱（需在允许域名列表中） |
| password | string | 是 | 密码（至少6位） |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.email_hint | string | 脱敏后的邮箱地址 |

**模拟请求**

```json
POST /v1/auth/register
{
    "username": "newuser",
    "email": "newuser@qq.com",
    "password": "123456"
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "验证码已发送到您的邮箱",
    "data": {
        "status": 200,
        "email_hint": "ne***@qq.com"
    }
}
```

---

### 7.4 注册验证（第二步：验证邮箱验证码，完成注册）

`POST /v1/auth/register-verify`

验证邮箱验证码，创建用户。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 用户名 |
| email | string | 是 | 邮箱 |
| code | string | 是 | 邮箱验证码 |
| password | string | 是 | 密码 |

**模拟请求**

```json
POST /v1/auth/register-verify
{
    "username": "newuser",
    "email": "newuser@qq.com",
    "code": "123456",
    "password": "123456"
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "注册成功",
    "data": {
        "status": 201,
        "id": 2,
        "username": "newuser"
    }
}
```

---

### 7.5 登出

`POST /v1/auth/logout`

登出当前用户，清除 Session 和 Token。

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "已登出",
    "data": {
        "status": 200
    }
}
```

---

### 7.6 获取当前用户信息

`GET /v1/auth/me`

获取当前登录用户信息。

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.user.id | int | 用户ID |
| data.user.username | string | 用户名 |
| data.user.email | string | 邮箱 |
| data.user.role | string | 角色(admin/user) |
| data.user.is_banned | bool | 是否被封禁 |
| data.user.register_ip | string | 注册IP |
| data.user.last_login | string | 最后登录时间 |
| data.user.created_at | string | 创建时间 |
| data.user.recent_ips | array | 最近登录IP列表 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "user": {
            "id": 1,
            "username": "admin",
            "email": "admin@example.com",
            "role": "admin",
            "is_banned": false,
            "register_ip": "192.168.1.1",
            "last_login": "2026-07-28 09:00:00",
            "created_at": "2026-01-01 00:00:00",
            "recent_ips": ["192.168.1.1", "10.0.0.1"]
        }
    }
}
```

---

### 7.7 获取设备列表

`GET /v1/auth/devices`

获取当前用户的所有活跃设备列表。

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.items[].id | int | 设备记录ID |
| data.items[].device_token | string | 脱敏后的设备Token |
| data.items[].device_name | string | 设备名称 |
| data.items[].ip_address | string | IP地址 |
| data.items[].login_at | string | 登录时间 |
| data.items[].last_active_at | string | 最后活跃时间 |
| data.items[].is_current | bool | 是否为当前设备 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "items": [
            {
                "id": 1,
                "device_token": "abc12345...",
                "device_name": "Chrome on Windows",
                "ip_address": "192.168.1.1",
                "login_at": "2026-07-28 09:00:00",
                "last_active_at": "2026-07-28 10:00:00",
                "is_current": true
            }
        ]
    }
}
```

---

### 7.8 登出指定设备

`POST /v1/auth/devices/logout`

登出指定的非当前设备。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| device_token | string | 是 | 设备的 device_token |

**模拟请求**

```json
POST /v1/auth/devices/logout
{
    "device_token": "abc12345..."
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "设备已登出",
    "data": {
        "status": 200
    }
}
```

---

### 7.9 忘记密码（发送验证码）

`POST /v1/auth/forgot-password`

提交用户名或邮箱，发送密码重置验证码。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 用户名或邮箱 |

**模拟请求**

```json
POST /v1/auth/forgot-password
{
    "username": "admin"
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "如果该账号存在且已绑定邮箱，验证码已发送",
    "data": {
        "status": 200
    }
}
```

---

### 7.10 重置密码

`POST /v1/auth/reset-password`

验证验证码并重置密码。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 用户名或邮箱 |
| code | string | 是 | 邮箱验证码 |
| password | string | 是 | 新密码（至少6位） |

**模拟请求**

```json
POST /v1/auth/reset-password
{
    "username": "admin",
    "code": "123456",
    "password": "newpassword123"
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "密码已重置，请重新登录",
    "data": {
        "status": 200
    }
}
```

---

## 8. Users - 用户管理模块

### 8.1 获取用户列表

`GET /v1/users`

获取所有用户列表（需管理员权限）。

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.items[].id | int | 用户ID |
| data.items[].username | string | 用户名 |
| data.items[].email | string | 邮箱 |
| data.items[].role | string | 角色(admin/user) |
| data.items[].last_login | string | 最后登录时间 |
| data.items[].created_at | string | 创建时间 |
| data.items[].is_banned | bool | 是否被封禁 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "total": 2,
        "items": [
            {
                "id": 1,
                "username": "admin",
                "email": "admin@example.com",
                "role": "admin",
                "last_login": "2026-07-28 09:00:00",
                "created_at": "2026-01-01 00:00:00",
                "is_banned": false
            },
            {
                "id": 2,
                "username": "user1",
                "email": "user1@example.com",
                "role": "user",
                "last_login": "2026-07-27 15:00:00",
                "created_at": "2026-06-01 00:00:00",
                "is_banned": false
            }
        ]
    }
}
```

---

### 8.2 创建用户

`POST /v1/users`

创建新用户（需管理员权限）。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| username | string | 是 | 用户名 |
| password | string | 是 | 密码 |
| email | string | 否 | 邮箱 |
| role | string | 否 | 角色(admin/user，默认 user) |

**模拟请求**

```json
POST /v1/users
{
    "username": "newuser",
    "password": "123456",
    "email": "newuser@example.com",
    "role": "user"
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "用户已创建",
    "data": {
        "status": 201,
        "id": 3,
        "username": "newuser",
        "role": "user"
    }
}
```

---

### 8.3 获取当前用户信息

`GET /v1/users/me`

获取当前登录用户的基本信息。

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.item.id | int | 用户ID |
| data.item.username | string | 用户名 |
| data.item.email | string | 邮箱 |
| data.item.role | string | 角色 |
| data.item.last_login | string | 最后登录时间 |
| data.item.created_at | string | 创建时间 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "item": {
            "id": 1,
            "username": "admin",
            "email": "admin@example.com",
            "role": "admin",
            "last_login": "2026-07-28 09:00:00",
            "created_at": "2026-01-01 00:00:00"
        }
    }
}
```

---

### 8.4 更新当前用户信息

`PUT /v1/users/me`

更新当前登录用户的信息（修改密码需提供旧密码）。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| email | string | 否 | 新邮箱 |
| password | string | 否 | 新密码（需同时提供 old_password） |
| old_password | string | 否 | 旧密码（修改密码时必填） |

**模拟请求**

```json
PUT /v1/users/me
{
    "email": "newemail@example.com",
    "password": "newpassword123",
    "old_password": "oldpassword"
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "用户已更新",
    "data": {
        "status": 200,
        "id": 1
    }
}
```

---

### 8.5 获取指定用户

`GET /v1/users/{id}`

获取指定用户的信息（需自己或管理员权限）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 用户ID |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "item": {
            "id": 2,
            "username": "user1",
            "email": "user1@example.com",
            "role": "user",
            "last_login": "2026-07-27 15:00:00",
            "created_at": "2026-06-01 00:00:00",
            "is_banned": false
        }
    }
}
```

---

### 8.6 更新指定用户

`PUT /v1/users/{id}`

更新指定用户的信息（需自己或管理员权限）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 用户ID |

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| email | string | 否 | 新邮箱 |
| password | string | 否 | 新密码（需同时提供 old_password） |
| old_password | string | 否 | 旧密码 |
| role | string | 否 | 角色(仅管理员可修改) |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "用户已更新",
    "data": {
        "status": 200,
        "id": 2
    }
}
```

---

### 8.7 删除用户

`DELETE /v1/users/{id}`

删除指定用户（需管理员权限，不能删除自己）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 用户ID |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "用户已删除",
    "data": {
        "status": 200,
        "id": 3
    }
}
```

---

## 9. Statuses - 动态模块

### 9.1 说说列表

`GET /v1/statuses/shuoshuo`

获取说说列表，支持分页。

**请求参数**

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| page | int | 否 | 1 | 页码 |
| per_page | int | 否 | 20 | 每页条数(最大50)，不传返回全部 |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.items[].id | int | 说说ID |
| data.items[].content | string | 说说内容 |
| data.items[].image_path | string | 图片路径 |
| data.items[].created_at | string | 发布时间 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "total": 30,
        "page": 1,
        "per_page": 20,
        "items": [
            {
                "id": 1,
                "content": "今天天气真好！",
                "image_path": "/uploads/shuoshuo/abc123.jpg",
                "created_at": "2026-07-28 08:00:00"
            }
        ]
    }
}
```

---

### 9.2 发布说说

`POST /v1/statuses/shuoshuo`

发布新说说（需管理员权限）。

**请求参数** (multipart/form-data)

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| content | string | 是 | 说说内容 |
| image | file | 否 | 图片文件(jpg/png/gif/webp，最大5MB) |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "发布成功",
    "data": {
        "status": 200,
        "id": 2,
        "content": "今天天气真好！",
        "image_path": "/uploads/shuoshuo/def456.jpg"
    }
}
```

---

### 9.3 删除说说

`DELETE /v1/statuses/shuoshuo/{id}`

删除指定说说（需管理员权限）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 说说ID |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.deleted_id | int | 已删除的说说ID |
| data.remaining | int | 剩余说说数量 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "删除成功，ID 已重排",
    "data": {
        "status": 200,
        "deleted_id": 2,
        "remaining": 29
    }
}
```

---

### 9.4 留言列表

`GET /v1/statuses/guestbook`

获取留言列表（含回复），支持分页。

**请求参数**

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| page | int | 否 | 1 | 页码 |
| per_page | int | 否 | 10 | 每页条数(最大50)，不传返回全部 |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.items[].id | int | 留言ID |
| data.items[].nickname | string | 昵称 |
| data.items[].email | string | 邮箱 |
| data.items[].website | string | 网站 |
| data.items[].content | string | 留言内容 |
| data.items[].reply_content | string | 管理员回复内容 |
| data.items[].reply_time | string | 回复时间 |
| data.items[].created_at | string | 留言时间 |
| data.items[].replies[].id | int | 回复ID |
| data.items[].replies[].nickname | string | 回复者昵称 |
| data.items[].replies[].content | string | 回复内容 |
| data.items[].replies[].created_at | string | 回复时间 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "total": 15,
        "page": 1,
        "per_page": 10,
        "items": [
            {
                "id": 1,
                "nickname": "访客张三",
                "email": "zhangsan@example.com",
                "website": "https://zhangsan.com",
                "content": "博主你好，网站很棒！",
                "reply_content": "谢谢支持！",
                "reply_time": "2026-07-21 10:00:00",
                "created_at": "2026-07-20 15:30:00",
                "replies": [
                    {
                        "id": 2,
                        "nickname": "李四",
                        "email": "",
                        "website": "",
                        "content": "确实不错！",
                        "created_at": "2026-07-21 09:00:00"
                    }
                ]
            }
        ]
    }
}
```

---

### 9.5 提交留言或回复

`POST /v1/statuses/guestbook`

提交留言或回复（公开，无需登录）。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| nickname | string | 是 | 昵称 |
| content | string | 是 | 留言内容 |
| email | string | 否 | 邮箱 |
| website | string | 否 | 网站 |
| parent_id | int | 否 | 回复目标ID(0=顶级留言) |

**模拟请求**

```json
POST /v1/statuses/guestbook
{
    "nickname": "新访客",
    "content": "来踩一踩",
    "email": "visitor@example.com",
    "website": "https://visitor.com",
    "parent_id": 0
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "留言成功",
    "data": {
        "status": 201,
        "id": 16,
        "nickname": "新访客",
        "email": "visitor@example.com",
        "website": "https://visitor.com",
        "content": "来踩一踩",
        "parent_id": 0,
        "created_at": "2026-07-28 10:00:00"
    }
}
```

---

### 9.6 管理员回复留言

`PUT /v1/statuses/guestbook/{id}/reply`

管理员回复留言（需管理员权限）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 留言ID |

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| reply_content | string | 是 | 回复内容 |

**模拟请求**

```json
PUT /v1/statuses/guestbook/1/reply
{
    "reply_content": "感谢您的留言！"
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "回复成功",
    "data": {
        "status": 200,
        "id": 1,
        "reply_content": "感谢您的留言！"
    }
}
```

---

### 9.7 删除留言及回复

`DELETE /v1/statuses/guestbook/{id}`

删除留言及其所有回复（需管理员权限）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 留言ID |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "留言及回复已删除",
    "data": {
        "status": 200,
        "deleted_id": 1
    }
}
```

---

### 9.8 相册列表

`GET /v1/statuses/gallery/albums`

获取所有相册（含照片数量）。

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.items[].id | int | 相册ID |
| data.items[].name | string | 相册名称 |
| data.items[].description | string | 相册描述 |
| data.items[].cover_image | string | 封面图URL |
| data.items[].sort_order | int | 排序值 |
| data.items[].photo_count | int | 照片数量 |
| data.items[].created_at | string | 创建时间 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "total": 3,
        "items": [
            {
                "id": 1,
                "name": "旅行摄影",
                "description": "记录旅途中的美好瞬间",
                "cover_image": "/uploads/gallery/covers/cover1.jpg",
                "sort_order": 1,
                "photo_count": 20,
                "created_at": "2026-06-01 00:00:00"
            },
            {
                "id": 2,
                "name": "生活日常",
                "description": "生活中的点滴",
                "cover_image": "/uploads/gallery/covers/cover2.jpg",
                "sort_order": 2,
                "photo_count": 15,
                "created_at": "2026-06-15 00:00:00"
            }
        ]
    }
}
```

---

### 9.9 获取相册详情

`GET /v1/statuses/gallery/albums/{id}`

获取相册详情及照片列表。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 相册ID |

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码 |
| per_page | int | 否 | 每页条数(最大100)，不传返回全部 |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.album.id | int | 相册ID |
| data.album.name | string | 相册名称 |
| data.album.description | string | 描述 |
| data.album.cover_image | string | 封面图 |
| data.album.sort_order | int | 排序值 |
| data.album.created_at | string | 创建时间 |
| data.photos[].id | int | 照片ID |
| data.photos[].url | string | 照片URL |
| data.photos[].title | string | 照片标题 |
| data.photos[].description | string | 照片描述 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "album": {
            "id": 1,
            "name": "旅行摄影",
            "description": "记录旅途中的美好瞬间",
            "cover_image": "/uploads/gallery/covers/cover1.jpg",
            "sort_order": 1,
            "created_at": "2026-06-01 00:00:00"
        },
        "total": 20,
        "page": 1,
        "per_page": 10,
        "photos": [
            {
                "id": 1,
                "album_id": 1,
                "url": "/uploads/gallery/photo1.jpg",
                "title": "夕阳",
                "description": "海边的夕阳",
                "sort_order": 0,
                "created_at": "2026-06-02 18:00:00"
            }
        ]
    }
}
```

---

### 9.10 创建相册

`POST /v1/statuses/gallery/albums`

创建新相册（需管理员权限）。

**请求参数** (multipart/form-data)

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| name | string | 是 | 相册名称 |
| description | string | 否 | 相册描述 |
| sort_order | int | 否 | 排序值 |
| cover_image | file | 否 | 封面图片(jpg/png/gif/webp，最大5MB) |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "相册已创建",
    "data": {
        "status": 201,
        "id": 3,
        "name": "美食摄影",
        "description": "美食记录",
        "sort_order": 3
    }
}
```

---

### 9.11 更新相册

`PUT /v1/statuses/gallery/albums/{id}`

更新相册信息（需管理员权限）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 相册ID |

**请求参数** (multipart/form-data)

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| name | string | 否 | 相册名称 |
| description | string | 否 | 相册描述 |
| sort_order | int | 否 | 排序值 |
| cover_image | file | 否 | 封面图片 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "相册已更新",
    "data": {
        "status": 200,
        "id": 3,
        "name": "美食摄影集",
        "description": "美食摄影精选集",
        "sort_order": 3
    }
}
```

---

### 9.12 删除相册

`DELETE /v1/statuses/gallery/albums/{id}`

删除相册及其中所有照片（需管理员权限）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 相册ID |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.deleted_photos | int | 同时删除的照片数量 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "相册已删除",
    "data": {
        "status": 200,
        "deleted_id": 3,
        "deleted_photos": 5
    }
}
```

---

### 9.13 照片列表

`GET /v1/statuses/gallery/photos`

获取照片列表，可按相册筛选。

**请求参数**

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| album_id | int | 否 | - | 按相册ID筛选 |
| page | int | 否 | 1 | 页码 |
| per_page | int | 否 | - | 每页条数(最大100)，不传返回全部 |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.items[].id | int | 照片ID |
| data.items[].album_id | int | 相册ID |
| data.items[].album_name | string | 相册名称 |
| data.items[].url | string | 照片URL |
| data.items[].title | string | 照片标题 |
| data.items[].description | string | 照片描述 |
| data.items[].sort_order | int | 排序值 |
| data.items[].created_at | string | 上传时间 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "total": 20,
        "page": 1,
        "per_page": 10,
        "items": [
            {
                "id": 1,
                "album_id": 1,
                "album_name": "旅行摄影",
                "url": "/uploads/gallery/photo1.jpg",
                "title": "夕阳",
                "description": "海边的夕阳",
                "sort_order": 0,
                "created_at": "2026-06-02 18:00:00"
            }
        ]
    }
}
```

---

### 9.14 上传照片

`POST /v1/statuses/gallery/photos`

上传照片（需管理员权限）。

**请求参数** (multipart/form-data)

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| image | file | 否 | 图片文件(jpg/png/gif/webp，最大10MB) |
| url | string | 否 | 图片链接（与 image 二选一） |
| album_id | int | 否 | 相册ID（不传则使用默认相册） |
| title | string | 否 | 照片标题 |
| description | string | 否 | 照片描述 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "上传成功",
    "data": {
        "status": 201,
        "album_id": 1,
        "photos": [
            {
                "id": 21,
                "url": "/uploads/gallery/photo21.jpg",
                "title": "新照片"
            }
        ]
    }
}
```

---

### 9.15 获取照片详情

`GET /v1/statuses/gallery/photos/{id}`

获取单张照片的详细信息。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 照片ID |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "item": {
            "id": 1,
            "album_id": 1,
            "album_name": "旅行摄影",
            "url": "/uploads/gallery/photo1.jpg",
            "title": "夕阳",
            "description": "海边的夕阳",
            "sort_order": 0,
            "created_at": "2026-06-02 18:00:00"
        }
    }
}
```

---

### 9.16 更新照片信息

`PUT /v1/statuses/gallery/photos/{id}`

更新照片信息（需管理员权限）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 照片ID |

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| title | string | 否 | 新标题 |
| description | string | 否 | 新描述 |
| album_id | int | 否 | 新相册ID |
| sort_order | int | 否 | 新排序值 |

**模拟请求**

```json
PUT /v1/statuses/gallery/photos/1
{
    "title": "海上日落",
    "description": "绝美的海上日落景色"
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "照片已更新",
    "data": {
        "status": 200,
        "id": 1
    }
}
```

---

### 9.17 删除照片

`DELETE /v1/statuses/gallery/photos/{id}`

删除指定照片（需管理员权限）。

**路径参数**

| 参数 | 类型 | 说明 |
|------|------|------|
| id | int | 照片ID |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "照片已删除",
    "data": {
        "status": 200,
        "deleted_id": 1
    }
}
```

---

### 9.18 获取站点设置

`GET /v1/statuses/settings`

获取站点公共配置信息（无需登录）。

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.item.website_name | string | 站点名称 |
| data.item.website_author | string | 站长 |
| data.item.website_intro | string | 站点简介 |
| data.item.robot_description | string | 搜索引擎描述 |
| data.item.logo | string | Logo地址 |
| data.item.favicon | string | 网站图标 |
| data.item.website_start_time | string | 网站开办时间 |
| data.item.contact_email | string | 联系邮箱 |
| data.item.contact_qq | string | QQ号 |
| data.item.social_wechat | string | 微信号 |
| data.item.social_github | string | GitHub |
| data.item.social_bilibili | string | B站号 |
| data.item.social_x | string | X (Twitter) |
| data.item.website_announcement | string | 公告内容 |
| data.item.website_announcement_enable | string | 是否启用公告 |
| data.item.footer_extra | string | 页脚附加信息(HTML) |
| data.item.icp_record | string | ICP备案号 |
| data.item.public_security_record | string | 公安备案号 |

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "item": {
            "website_name": "冷月笙寒的小窝",
            "website_author": "冷月笙寒",
            "website_intro": "个人博客，分享技术与生活",
            "logo": "/uploads/logo.png",
            "favicon": "/uploads/favicon.ico",
            "contact_email": "admin@example.com",
            "social_github": "https://github.com/example",
            "icp_record": "京ICP备xxxxxxxx号",
            "website_announcement": "网站已全新改版上线！",
            "website_announcement_enable": "1"
        }
    }
}
```

---

### 9.19 获取服务条款与隐私政策

`GET /v1/statuses/terms`

获取服务条款和/或隐私政策内容。

**请求参数**

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| type | string | 否 | - | terms(服务条款) / privacy(隐私政策)，不传返回全部 |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.items | array | 条款列表(当 type=terms 或 privacy 时) |
| data.terms | array | 服务条款(不传 type 时) |
| data.privacy | array | 隐私政策(不传 type 时) |
| items[].title | string | 章节标题 |
| items[].content | string | 章节内容 |
| items[].items | array | 子项列表 |

**示例请求**

```
GET /v1/statuses/terms?type=terms
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "items": [
            {
                "title": "1. 接受条款",
                "content": "通过访问和使用本网站，您同意遵守这些服务条款。",
                "items": []
            },
            {
                "title": "2. 使用许可",
                "content": "我们授予您有限的、非独占的许可来使用本网站，但您必须遵守以下条件：",
                "items": [
                    "不得将网站用于任何非法或未经授权的目的",
                    "不得干扰或破坏网站的正常运行"
                ]
            }
        ]
    }
}
```

---

## 10. Public - 公共模块

### 10.1 获取一言

`GET /v1/public/hitokoto`

获取一言（随机句子）。

**请求参数**

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| source | string | 否 | auto | auto(自动选择)/local(本站一言)/intro(简介) |

**响应字段说明**

| 字段 | 类型 | 说明 |
|------|------|------|
| data.source | string | 数据来源(local/api/text) |
| data.item | object | 一言数据(source=local时) |
| data.item.hitokoto | string | 一言内容 |
| data.item.from | string | 来源出处 |
| data.item.from_who | string | 原作者 |
| data.text | string | 文本内容(source=api/text时) |

**示例请求**

```
GET /v1/public/hitokoto?source=local
```

**模拟响应（本地一言）**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "source": "local",
        "item": {
            "id": 1,
            "hitokoto": "生活不止眼前的苟且，还有诗和远方的田野。",
            "from": "生活",
            "from_who": "高晓松",
            "creator": "admin",
            "created_at": "2026-01-01 00:00:00"
        }
    }
}
```

**模拟响应（简介文本）**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "source": "text",
        "text": "个人博客，分享技术与生活"
    }
}
```

---

### 10.2 公网代理

`GET/POST /v1/public/proxy`

代理请求外部API，解决跨域问题。

**请求参数**

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| url | string | 是 | - | 目标URL（仅允许公网http/https） |
| method | string | 否 | GET | 请求方法(GET/POST等) |
| headers | object | 否 | {} | 自定义请求头 |
| body | string/object | 否 | null | 请求体 |
| timeout | int | 否 | 10 | 超时时间(秒，最大30) |

**模拟请求**

```json
POST /v1/public/proxy
{
    "url": "https://api.example.com/data",
    "method": "GET",
    "headers": {
        "Accept": "application/json"
    },
    "timeout": 15
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "请求成功",
    "data": {
        "status": 200,
        "target_url": "https://api.example.com/data",
        "content_type": "application/json",
        "body": {
            "key": "value"
        }
    }
}
```

---

### 10.3 内部代理

`POST /v1/public/proxy/internal`

内部代理，直接调度本地 API 端点（零网络开销）。

**请求参数**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| route | string | 否 | 本地路由(如 /v1/posts)，与 url 二选一 |
| url | string | 否 | 本地URL，与 route 二选一 |
| method | string | 否 | 请求方法(GET/POST/PUT/DELETE，默认GET) |
| params | object | 否 | 请求参数 |

**模拟请求**

```json
POST /v1/public/proxy/internal
{
    "route": "/v1/posts/1",
    "method": "GET"
}
```

**模拟响应**

```json
{
    "code": "rest_ok",
    "message": "获取成功",
    "data": {
        "status": 200,
        "item": {
            "id": 1,
            "title": "PHP 入门指南",
            "content": "文章全文内容..."
        }
    }
}
```

---

> 文档版本: v1.0  
> 生成日期: 2026-07-28
