# Nova Users API 文档

基础路径：`/nova-json/v1`

所有 API 均返回 JSON 格式，支持同域访问（不支持跨域）。

---

## 目录

- [认证 Auth](#认证-auth)
  - [登录（第一步）](#登录第一步)
  - [邮箱验证（第二步）](#邮箱验证第二步)
  - [当前用户信息](#当前用户信息)
  - [设备列表](#设备列表)
  - [登出指定设备](#登出指定设备)
  - [登出（当前设备）](#登出当前设备)
  - [忘记密码（发送验证码）](#忘记密码发送验证码)
  - [重置密码（验证码）](#重置密码验证码)
- [用户管理 Users](#用户管理-users)
- [通用响应结构](#通用响应结构)

---

## 认证 Auth

认证采用**两步登录**和**两步注册**流程：

**登录：**
1. `POST /v1/auth/login` → 验证用户名/邮箱和密码，发送邮箱验证码
2. `POST /v1/auth/verify` → 验证邮箱验证码，返回 `token`（即 `device_token`）

**注册：**
1. `POST /v1/auth/register` → 提交用户名、邮箱、密码，发送邮箱验证码
2. `POST /v1/auth/register-verify` → 验证邮箱验证码，创建用户

后续请求在 `Authorization` 头携带 `Bearer <token>` 即可认证。

---

### 登录（第一步）

```
POST /v1/auth/login
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `username` | string | 是 | 用户名或邮箱 |
| `password` | string | 是 | 密码 |

**响应：**

```json
{
  "code": "rest_ok",
  "message": "验证码已发送到您的邮箱",
  "data": {
    "status": 200,
    "requires_verification": true,
    "email_hint": "26***@qq.com"
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.requires_verification` | bool | 是否需要进行邮箱验证（固定为 `true`） |
| `data.email_hint` | string | 邮箱脱敏显示，用于提示用户验证码已发送到哪个邮箱 |

**错误码：**

| code | HTTP | 说明 |
|------|------|------|
| `rest_missing_fields` | 400 | 用户名或密码为空 |
| `rest_invalid_login` | 401 | 用户名/邮箱或密码错误 |
| `rest_user_banned` | 403 | 账号已被封禁 |
| `rest_rate_limited` | 429 | 登录尝试过于频繁，每IP每5分钟最多10次 |
| `rest_no_email` | 400 | 账号未绑定邮箱，无法完成二次验证 |

---

### 邮箱验证（第二步）

```
POST /v1/auth/verify
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `username` | string | 是 | 用户名或邮箱（与第一步一致） |
| `code` | string | 是 | 6位邮箱验证码 |
| `remember_me` | bool | 否 | 是否记住设备。`true` 时 Token 和 Cookie 有效期 30 天；`false` 时浏览器关闭即失效。默认 `false` |

**响应：**

```json
{
  "code": "rest_ok",
  "message": "登录成功",
  "data": {
    "status": 200,
    "token": "a1b2c3d4e5f6...64位hex字符串",
    "user": {
      "id": 1,
      "username": "Galaxy",
      "email": "2648181326@qq.com",
      "role": "admin"
    }
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.token` | string | Bearer Token（即 `device_token`），后续请求需在 `Authorization` 头携带 `Bearer <token>` |
| `data.user.id` | int | 用户 ID |
| `data.user.username` | string | 用户名 |
| `data.user.email` | string | 邮箱 |
| `data.user.role` | string | 角色：`admin`（管理员）/ `user`（普通用户） |

**注意：**
- 验证码 10 分钟有效
- 登录成功后会自动设置 `nova_token` HttpOnly Cookie（浏览器端自动认证）
- 同一邮箱验证码发送频率限制：5分钟内最多3次

---

### 当前用户信息

```
GET /v1/auth/me
```

**请求头：** `Authorization: Bearer <token>`

**响应：**

```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": {
    "status": 200,
    "user": {
      "id": 1,
      "username": "Galaxy",
      "email": "2648181326@qq.com",
      "role": "admin",
      "is_banned": false,
      "register_ip": "1.2.3.4",
      "last_login": "2026-07-24 18:00:00",
      "created_at": "2025-12-03 03:21:11",
      "recent_ips": [
        "1.2.3.4",
        "5.6.7.8"
      ]
    }
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.user.id` | int | 用户 ID |
| `data.user.username` | string | 用户名 |
| `data.user.email` | string | 邮箱 |
| `data.user.role` | string | 角色：`admin` / `user` |
| `data.user.is_banned` | bool | 是否被封禁 |
| `data.user.register_ip` | string | 注册时的 IP 地址 |
| `data.user.last_login` | string/null | 最后登录时间 |
| `data.user.created_at` | string | 注册时间 |
| `data.user.recent_ips` | string[] | 最近 5 条登录 IP 地址 |

**注意：** 需要登录，未登录返回 401。

---

### 设备列表

```
GET /v1/auth/devices
```

**请求头：** `Authorization: Bearer <token>`

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
        "device_token": "a1b2c3d4...",
        "device_name": "Windows 10 Chrome",
        "ip_address": "1.2.3.4",
        "login_at": "2026-07-24 12:00:00",
        "last_active_at": "2026-07-24 18:00:00",
        "is_current": true
      },
      {
        "id": 2,
        "device_token": "e5f6g7h8...",
        "device_name": "Android 14 Chrome",
        "ip_address": "9.10.11.12",
        "login_at": "2026-07-23 08:00:00",
        "last_active_at": "2026-07-23 20:00:00",
        "is_current": false
      }
    ]
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.items[].id` | int | 设备会话 ID |
| `data.items[].device_token` | string | Token 前 8 位（仅用于标识） |
| `data.items[].device_name` | string | 设备名称（User-Agent 解析） |
| `data.items[].ip_address` | string | 登录时的 IP 地址 |
| `data.items[].login_at` | string | 登录时间 |
| `data.items[].last_active_at` | string | 最后活跃时间 |
| `data.items[].is_current` | bool | 是否为当前正在使用的设备 |

**注意：** 需要登录，未登录返回 401。

---

### 登出指定设备

```
POST /v1/auth/devices/logout
```

**请求头：** `Authorization: Bearer <token>`

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `device_token` | string | 是 | 设备 Token（从设备列表获取的完整值） |

**响应（成功）：**

```json
{
  "code": "rest_ok",
  "message": "设备已登出",
  "data": {
    "status": 200
  }
}
```

**注意：**
- 需要登录，未登录返回 401
- **不能通过此接口登出当前设备**，当前设备登出请使用 `/auth/logout`
- 如果设备已下线或不存在，返回 404

---

### 登出（当前设备）

```
POST /v1/auth/logout
```

**请求头：** `Authorization: Bearer <token>`

**响应：**

```json
{
  "code": "rest_ok",
  "message": "已登出",
  "data": {
    "status": 200
  }
}
```

**注意：**
- 登出后当前 `device_token` 将在数据库中被标记为下线
- 同时清除 `nova_token` 和 `device_token` Cookie
- Session 会被销毁

---

### 忘记密码（发送验证码）

```
POST /v1/auth/forgot-password
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `username` | string | 是 | 用户名或邮箱 |

**响应：**

```json
{
  "code": "rest_ok",
  "message": "如果该账号存在且已绑定邮箱，验证码已发送",
  "data": {
    "status": 200
  }
}
```

**注意：**
- 无论账号是否存在，返回相同消息（防止枚举）
- 验证码 10 分钟有效
- 频率限制：每 IP 每 5 分钟最多 3 次

---

### 重置密码（验证码）

```
POST /v1/auth/reset-password
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `username` | string | 是 | 用户名或邮箱 |
| `code` | string | 是 | 6 位邮箱验证码（从 `forgot-password` 获取） |
| `password` | string | 是 | 新密码（最少 6 位） |

**响应（成功）：**

```json
{
  "code": "rest_ok",
  "message": "密码已重置，请重新登录",
  "data": {
    "status": 200
  }
}
```

**注意：**
- 验证成功后会自动**使所有设备下线**（旧 Token 全部失效），需重新登录
- 密码使用 bcrypt（cost=12）加密存储
- 支持两种改密码方式：

| 方式 | 接口 | 适用场景 |
|------|------|---------|
| 邮箱验证码重置 | `forgot-password` + `reset-password` | 忘记密码 |
| 旧密码验证修改 | `PUT /v1/users/me`（传 `old_password`+`password`） | 记得旧密码，想换新密码 |

---

### 注册（第一步：发送验证码）

```
POST /v1/auth/register
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `username` | string | 是 | 用户名，2-20位，支持字母、数字、下划线和中文 |
| `email` | string | 是 | 邮箱 |
| `password` | string | 是 | 密码，至少 6 位 |

**响应：**

```json
{
  "code": "rest_ok",
  "message": "验证码已发送到您的邮箱",
  "data": {
    "status": 200,
    "email_hint": "26***@qq.com"
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.email_hint` | string | 邮箱脱敏显示 |

**错误码：**

| code | HTTP | 说明 |
|------|------|------|
| `rest_missing_fields` | 400 | 用户名、邮箱或密码为空 |
| `rest_invalid_username` | 400 | 用户名长度或格式不合法 |
| `rest_invalid_email` | 400 | 邮箱格式无效 |
| `rest_email_not_allowed` | 400 | 邮箱后缀不在允许注册范围内，`data.allowed_domains` 字段返回可用列表 |
| `rest_weak_password` | 400 | 密码长度少于 6 位 |
| `rest_duplicate_username` | 409 | 用户名已被使用 |
| `rest_duplicate_email` | 409 | 邮箱已被注册 |
| `rest_rate_limited` | 429 | 注册或验证码发送过于频繁 |

**响应示例（邮箱后缀不被允许）：**

```json
{
  "code": "rest_email_not_allowed",
  "message": "该邮箱后缀不被允许注册，支持的邮箱：qq.com、163.com、gmail.com",
  "data": {
    "status": 400,
    "allowed_domains": ["qq.com", "163.com", "gmail.com"]
  }
}
```

---

### 注册（第二步：验证并创建账号）

```
POST /v1/auth/register-verify
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `username` | string | 是 | 用户名（与第一步一致） |
| `email` | string | 是 | 邮箱（与第一步一致） |
| `code` | string | 是 | 6位邮箱验证码 |
| `password` | string | 是 | 密码（与第一步一致） |

**响应（成功）：**

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

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.id` | int | 新用户 ID |
| `data.username` | string | 新用户名 |

**错误码：**

| code | HTTP | 说明 |
|------|------|------|
| `rest_missing_fields` | 400 | 参数不完整 |
| `rest_duplicate` | 409 | 用户名或邮箱已被占用（防止并发注册） |
| `rest_invalid_code` | 401 | 验证码错误或已过期 |
| `rest_register_failed` | 500 | 注册失败，请重试 |

**注意：**
- 验证码 10 分钟有效
- 同一邮箱验证码发送频率限制：5分钟内最多3次
- 注册成功后用户的角色为 `user`

---

## 用户管理 Users

用户管理 API 均需要管理员权限（除了用户自己查看/更新）。

### 获取用户列表

```
GET /v1/users
```

**请求头：** `Authorization: Bearer <token>`

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
        "username": "Galaxy",
        "email": "2648181326@qq.com",
        "role": "admin",
        "last_login": "2026-07-24 18:00:00",
        "created_at": "2025-12-03 03:21:11",
        "is_banned": false
      }
    ]
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.total` | int | 用户总数 |
| `data.items[].id` | int | 用户 ID |
| `data.items[].username` | string | 用户名 |
| `data.items[].email` | string | 邮箱 |
| `data.items[].role` | string | 角色 |
| `data.items[].last_login` | string/null | 最后登录时间 |
| `data.items[].created_at` | string | 注册时间 |
| `data.items[].is_banned` | bool | 是否被封禁 |

> **仅管理员**可操作，普通用户返回 403。

---

### 创建用户

```
POST /v1/users
```

**请求头：** `Authorization: Bearer <token>`

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `username` | string | 是 | 用户名 |
| `password` | string | 是 | 密码 |
| `email` | string | 否 | 邮箱 |
| `role` | string | 否 | 角色：`admin` 或 `user`，默认 `user` |

**响应：**

```json
{
  "code": "rest_ok",
  "message": "用户已创建",
  "data": {
    "status": 201,
    "id": 11,
    "username": "newuser",
    "role": "user"
  }
}
```

> **仅管理员**可操作。

---

### 获取当前用户

```
GET /v1/users/me
```

**请求头：** `Authorization: Bearer <token>`

返回格式与 `/v1/auth/me` 类似（字段较精简）。

---

### 更新当前用户

```
PUT /v1/users/me
```

**请求头：** `Authorization: Bearer <token>`

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `email` | string | 否 | 新邮箱 |
| `password` | string | 否 | 新密码（提供时需同时提供 `old_password`） |
| `old_password` | string | 当修改密码时必填 | 旧密码，用于验证身份 |

**响应：**

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

**注意：**
- 修改密码**必须提供 `old_password`** 并验证正确
- 邮箱会通过 `strip_tags()` 过滤 HTML 并验证邮箱格式

---

### 获取指定用户

```
GET /v1/users/{id}
```

**请求头：** `Authorization: Bearer <token>`

**响应：**

```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": {
    "status": 200,
    "item": {
      "id": 1,
      "username": "Galaxy",
      "email": "2648181326@qq.com",
      "role": "admin",
      "last_login": "2026-07-24 18:00:00",
      "created_at": "2025-12-03 03:21:11",
      "is_banned": false
    }
  }
}
```

**返回字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `data.item.id` | int | 用户 ID |
| `data.item.username` | string | 用户名 |
| `data.item.email` | string | 邮箱 |
| `data.item.role` | string | 角色 |
| `data.item.last_login` | string/null | 最后登录时间 |
| `data.item.created_at` | string | 注册时间 |
| `data.item.is_banned` | bool | 是否被封禁 |

**注意：**
- **仅自己和管理员**可查看，其他用户返回 403
- **未登录**用户同样返回 403

---

### 更新指定用户

```
PUT /v1/users/{id}
```

**请求头：** `Authorization: Bearer <token>`

**请求体：** 与 `PUT /v1/users/me` 相同。管理员额外可设置 `role` 字段。

**权限：**
- 可更新**自己**的信息
- **管理员**可更新任意用户的信息
- 普通用户无权修改其他用户

---

### 删除用户

```
DELETE /v1/users/{id}
```

**请求头：** `Authorization: Bearer <token>`

**响应：**

```json
{
  "code": "rest_ok",
  "message": "用户已删除",
  "data": {
    "status": 200,
    "id": 11
  }
}
```

**注意：**
- **仅管理员**可删除用户
- 不能删除自己
- 删除用户时会同时清除该用户的所有会话记录

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
| `rest_missing_fields` | 400 | 缺少必填参数 |
| `rest_invalid_login` | 401 | 用户名/密码错误 |
| `rest_user_banned` | 403 | 账号被封禁 |
| `rest_rate_limited` | 429 | 请求频率限制 |
| `rest_duplicate_username` | 409 | 用户名已存在 |
| `rest_duplicate_email` | 409 | 邮箱已被使用 |
| `rest_user_not_found` | 404 | 用户不存在 |
| `rest_invalid_code` | 401 | 验证码错误或过期 |
| `rest_invalid_email` | 400 | 邮箱格式无效 |
| `rest_missing_old_password` | 400 | 修改密码必须提供旧密码 |
| `rest_invalid_old_password` | 403 | 旧密码错误 |
| `rest_cannot_create` | 403 | 无创建权限 |
| `rest_cannot_edit` | 403 | 无权修改 |
| `rest_cannot_delete` | 403 | 无删除权限 |
| `rest_cannot_delete_self` | 400 | 不能删除自己 |
| `rest_cannot_logout_current` | 400 | 不能通过设备登出接口登出当前设备 |
| `rest_device_not_found` | 404 | 设备不存在或已下线 |
