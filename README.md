# NovaCMS

> 一款轻量、可扩展、开箱即用的 PHP 内容管理系统，聚焦个人博客与小型站点的写作、展示与运营。

![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-%3E%3D5.7-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)
![License](https://img.shields.io/badge/License-Open%20Source-success?style=for-the-badge)

NovaCMS 是一套基于原生 PHP + MySQL + Bootstrap 5 构建的单租户 CMS，无需复杂框架，部署简单，自带后台、主题、插件、API 与行为验证码，适合用于搭建个人博客、文档站、作品集与小型社区站点。

---

## 目录

- [特性一览](#特性一览)
- [技术栈](#技术栈)
- [环境要求](#环境要求)
- [快速开始](#快速开始)
- [目录结构](#目录结构)
- [主题与插件](#主题与插件)
- [API 与集成](#api-与集成)
- [安全实践](#安全实践)
- [参与贡献](#参与贡献)

---

## 特性一览

**内容管理**
- 文章（Markdown / 双栏编辑器 EasyMDE）、独立页面、文档中心
- 说说（短动态）、相册（图集 / 单图）、留言板、友情链接
- 分类与标签、封面与附件管理、文章加密与付费下载
- RSS、Sitemap、SEO 工具（meta / 关键词 / 友好链接）

**用户与权限**
- 前台注册 / 登录 / 找回密码 / 申诉解封
- 后台管理员账户、角色与封禁管理
- 隐私访问控制（按内容粒度授权）
- 会话保护与登录行为验证码

**运营与统计**
- 实时访问趋势、热门页面、访客 IP 地理解析
- 邮件通知（PHPMailer）、订阅、留言与评论审核
- AI 问答与 AI 设置模块

**系统能力**
- 主题系统（默认主题 `default`，支持 `theme.json` 描述）
- 插件系统（内置 `backup` 备份插件示例）
- RESTful API（`nova-json`，含路由、钩子、REST Server）
- 一键备份 / 恢复（数据库 + 文件）
- 伪静态与多错误页（400 / 401 / 403 / 404 / 500 / 502 / 503 / 504 / 封禁页）

## 技术栈

| 层级 | 选型 |
| --- | --- |
| 后端 | 原生 PHP（PDO + MySQL），无重型框架 |
| 数据库 | MySQL 5.7+ / 8.x（utf8mb4） |
| 前端 | Bootstrap 5.3、Bootstrap Icons、HarmonyOS Sans |
| 交互 | jQuery 3.7、Vue 3（global build）、Chart.js、DataTables |
| 编辑器 | EasyMDE（Markdown）、Marked.js |
| 邮件 | PHPMailer |
| Markdown | Parsedown |
| 验证码 | 自研行为验证码（滑块拼图 + POW + 行为分析） |
| IP 解析 | GeoCN.mmdb / qqwry.ipdb（本地库，离线查询） |

## 环境要求

- **PHP** ≥ 7.4（推荐 8.x；需启用 `pdo_mysql`、`mbstring`、`openssl`、`curl`、`gd`）
- **MySQL** ≥ 5.7（推荐 8.x，字符集 `utf8mb4`）
- **Web 服务器**：Nginx + PHP-FPM（推荐）或 Apache；需支持伪静态
- **HTTPS**：登录与后台建议在 HTTPS 下运行（部分浏览器 API 在 HTTP 下受限）

## 快速开始

### 1. 获取源码

将项目放置到 Web 根目录，例如 `/www/wwwroot/your-site/`。

### 2. 导入数据库

导入根目录的 `blog.sql`：

```bash
mysql -u root -p your_db_name < blog.sql
```

或通过 phpMyAdmin / 1Panel 直接导入。

### 3. 配置数据库连接

编辑 [config/database.php](config/database.php)，或通过环境变量注入（生产推荐）：

```php
// 方式一：直接修改默认值
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'novablog');
define('DB_USER', 'novablog');   // 注意：仅填用户名，不要带 '@host'
define('DB_PASS', 'your_password');
```

```bash
# 方式二：环境变量（推荐，1Panel/Docker 友好）
NOVACMS_DB_HOST=127.0.0.1
NOVACMS_DB_NAME=novablog
NOVACMS_DB_USER=novablog
NOVACMS_DB_PASS=your_password
```

> 提示：用户名不要写成 `user@%`，MySQL 的 `@host` 由服务端匹配，PDO 只接收纯用户名。

### 4. 配置伪静态

参考根目录 [伪静态.txt](伪静态.txt)，将规则写入 Nginx 或 Apache。Nginx 示例：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 5. 访问站点

- 前台首页：`http://your-domain/`
- 后台登录：`http://your-domain/admin/login.php`
- 默认管理员账号请在首次登录后及时修改密码

### 6. 目录权限

确保以下目录可写：

```
uploads/    logs/    vendor/nova-plugins/backup/backups/    vendor/temp/
```

## 目录结构

```
NovaCMS/
├── admin/                  # 后台管理（文章、页面、用户、统计、主题、插件…）
│   ├── api/                # 后台数据接口（统计、访问趋势、上传等）
│   └── includes/           # 后台公共组件与控制器
├── assets/                 # 全站静态资源（CSS / JS / 字体 / 图标）
├── config/                 # 配置与公共函数（数据库、邮件、评论、权限…）
├── license/                # RSS、Sitemap、条款页
├── logs/                   # 日志目录（邮件、下载）
├── uploads/                # 用户上传内容（按类型分目录）
├── vendor/
│   ├── api/                # 第三方能力（IP 地理查询 qqwry/GeoCN）
│   ├── nova-json/          # RESTful API 框架（路由 / 钩子 / 主题 / 插件）
│   ├── nova-plugins/       # 插件目录（内置 backup 备份插件）
│   ├── nova-themes/        # 主题目录（内置 default 主题）
│   └── public/             # 公共能力（验证码、支付、PHPMailer、Parsedown、错误页）
├── index.php               # 前台入口
├── blog.sql                # 数据库结构
├── 伪静态.txt               # 伪静态规则参考
└── robots.txt
```

## 主题与插件

### 主题

主题存放于 `vendor/nova-themes/`，每个主题一个目录，通过 `theme.json` 描述元信息，支持 `partials/`（头部、导航、页脚）与独立的 `assets/` 资源。默认主题 `default` 包含文章、页面、文档、说说、相册、留言板、友链、个人中心等模板。

开发新主题可参考 `vendor/nova-themes/default/theme.json`。

### 插件

插件存放于 `vendor/nova-plugins/`，遵循 `nova-json` 的插件规范，核心文件 `class-<name>.php` 与 `plugin.php` 入口。内置 `backup` 插件提供数据库与文件的一键备份 / 恢复，可作为开发示例。

插件菜单通过 `nova-json` 系统注册，而非直接引用 PHP 文件。

## API 与集成

NovaCMS 通过 `vendor/nova-json/` 提供一套 RESTful API 框架，内置路由模块：

- `content` 内容
- `posts` 文章 / 分类 / 标签 / 评论 / 搜索 / 下载 / 付费 / 隐私
- `links` 友链 / 申请
- `statuses` 说说 / 相册 / 留言板 / 设置 / 条款
- `users` 认证 / 用户

API 开发文档见 `vendor/nova-json/docs/`（`routes.md`、`plugin-dev.md`、`class.md`）。

已集成的第三方能力：

- **行为验证码**：滑块拼图 + POW 工作量证明 + 行为轨迹分析（`vendor/public/captcha/`）
- **支付**：易支付（epay）接入（`vendor/public/epay/`）
- **邮件**：PHPMailer SMTP（`vendor/public/phpmailer/`）
- **IP 解析**：本地 qqwry / GeoCN 离线库（`vendor/api/ipsearch/`）

## 安全实践

- 所有状态变更操作（增删改、审核、封禁等）均校验 CSRF Token
- 表单使用 `<?= csrfField() ?>`，AJAX 使用 `getCsrfToken()` 附加令牌
- 密码使用 `password_hash` / `password_verify` 存储
- 登录会话使用 `session_regenerate_id(true)` 防止固定会话
- 后台登录前置行为验证码，验证令牌一次性消费防重放
- 敏感目录（`config/`、`logs/`、`uploads/`）通过 `.htaccess` 限制访问
- 数据库错误信息仅写入日志，不直接暴露给用户

## 参与贡献

项目当前处于测试 / 搭建阶段，欢迎通过以下方式参与：

- 提交 Issue 反馈 Bug 或建议新特性
- 提交 Pull Request 修复问题或完善文档
- 分享主题与插件

> 目前这是一个正在测试 / 搭建中的博客系统，部分功能仍在迭代。
