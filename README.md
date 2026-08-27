# NovaCMS

> 一款轻量、可扩展、开箱即用的 PHP 内容管理系统，聚焦个人博客与小型站点的写作、展示与运营。

[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-%3E%3D5.7-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)](https://getbootstrap.com/)
[![Themes](https://img.shields.io/badge/Themes-3-FF6B6B?style=for-the-badge)](#内置主题)
[![Plugins](https://img.shields.io/badge/Plugins-6-4ECDC4?style=for-the-badge)](#内置插件)
[![License](https://img.shields.io/badge/License-Open%20Source-success?style=for-the-badge)](#许可证)

NovaCMS 是一套基于原生 PHP + MySQL + Bootstrap 5 构建的单租户 CMS，无需复杂框架，部署简单。自带管理后台、主题系统、插件体系、RESTful API、行为验证码与访问统计，适合用于搭建个人博客、作品集、小型社区与企业展示站点。

---

## 目录

- [✨ 核心亮点](#-核心亮点)
- [🛠 技术栈](#-技术栈)
- [📋 环境要求](#-环境要求)
- [🚀 快速开始](#-快速开始)
- [📁 目录结构](#-目录结构)
- [🎨 主题系统](#-主题系统)
  - [内置主题](#内置主题)
  - [主题开发](#主题开发)
- [🔌 插件系统](#-插件系统)
  - [内置插件](#内置插件)
  - [插件开发](#插件开发)
- [🌐 API 与集成](#-api-与集成)
- [📚 开发文档](#-开发文档)
- [🔒 安全实践](#-安全实践)
- [❓ 常见问题](#-常见问题)
- [🤝 参与贡献](#-参与贡献)
- [📄 许可证](#-许可证)

---

## ✨ 核心亮点

| 模块 | 能力说明 |
| --- | --- |
| **内容管理** | 文章（Markdown 双栏编辑器 EasyMDE）、独立页面、片刻（短动态）、相册（图集/单图）、留言板、友情链接、分类与标签、封面与附件管理、文章加密与付费下载、RSS / Sitemap、SEO 工具（meta/关键词/友好链接） |
| **用户与权限** | 前台注册 / 登录 / 找回密码 / 申诉解封、后台管理员账户与角色管理、用户封禁与解禁、按内容粒度的隐私访问控制、会话保护与登录行为验证码 |
| **运营与统计** | 实时访问趋势图、热门页面排行、访客 IP 地理解析（本地离线库）、邮件通知（PHPMailer SMTP）、邮件订阅、留言与评论审核、AI 问答与 AI 设置模块 |
| **系统能力** | 主题系统（3 套内置主题 + 子主题继承 + 自定义路由）、插件系统（6 个内置插件 + 插件页面路由）、RESTful API（`nova-json` 框架，含路由/钩子/REST Server）、一键备份/恢复（数据库 + 文件）、定时任务管理（服务器 Cron / 虚拟面板双模式）、伪静态与多错误页（400/401/403/404/5xx/封禁页） |

---

## 🛠 技术栈

| 层级 | 选型 |
| --- | --- |
| 后端 | 原生 PHP（PDO + MySQL），无重型框架 |
| 数据库 | MySQL 5.7+ / 8.x（utf8mb4） |
| 前端 | Bootstrap 5.3、Bootstrap Icons、HarmonyOS Sans、Font Awesome |
| 交互 | jQuery 3.7、Vue 3（global build）、Chart.js、DataTables、PJAX 无刷新导航 |
| 编辑器 | EasyMDE（Markdown）、Marked.js、Parsedown |
| 邮件 | PHPMailer（SMTP） |
| 支付 | 易支付（epay）接入 |
| 验证码 | 自研行为验证码（滑块拼图 + POW 工作量证明 + 行为轨迹分析） |
| IP 解析 | GeoCN.mmdb / qqwry.ipdb（本地离线库，无需联网） |
| 定时任务 | 服务器 Cron + 虚拟触发双模式，内置 Cron 管理器插件 |

---

## 📋 环境要求

- **PHP** ≥ 7.4（推荐 8.0+；需启用扩展：`pdo_mysql`、`mbstring`、`openssl`、`curl`、`gd`、`json`）
- **MySQL** ≥ 5.7（推荐 8.0+，字符集 `utf8mb4`，排序规则 `utf8mb4_unicode_ci`）
- **Web 服务器**：Nginx + PHP-FPM（推荐）或 Apache；需支持伪静态（URL Rewrite）
- **HTTPS**：登录与后台建议在 HTTPS 下运行（部分浏览器 API 在 HTTP 下受限，如行为验证码、剪贴板等）
- **磁盘空间**：≥ 100MB（含主题、插件、IP 库）；根据上传内容按需扩容

---

## 🚀 快速开始

### 1. 获取源码

将项目放置到 Web 根目录，例如：

```
# Nginx 示例
/www/wwwroot/your-site/

# XAMPP / WAMP 示例
C:/xampp/htdocs/novacms/
```

### 2. 导入数据库

导入根目录的 `blog.sql` 到已创建的数据库中：

```bash
# 命令行方式
mysql -u root -p your_db_name < blog.sql
```

或通过 **phpMyAdmin** / **1Panel** / **宝塔面板** 等可视化工具直接导入。

> 导入前请确保数据库字符集为 `utf8mb4`。

### 3. 配置数据库连接

编辑 [config/database.php](config/database.php)，或通过环境变量注入（生产环境推荐）：

```php
// 方式一：直接修改默认值
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'novablog');
define('DB_USER', 'novablog');   // 注意：仅填用户名，不要带 '@host'
define('DB_PASS', 'your_password');
```

```bash
# 方式二：环境变量（推荐，1Panel / Docker 友好）
NOVACMS_DB_HOST=127.0.0.1
NOVACMS_DB_NAME=novablog
NOVACMS_DB_USER=novablog
NOVACMS_DB_PASS=your_password
```

> ⚠️ 提示：用户名不要写成 `user@%`，MySQL 的 `@host` 由服务端匹配，PDO 只接收纯用户名。

### 4. 配置伪静态

参考根目录的 [伪静态.txt](伪静态.txt)，将规则写入 Nginx 或 Apache 配置。

**Nginx 示例：**

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

# 禁止直接访问敏感目录
location ~* ^/(vendor|config|admin/includes)/ {
    deny all;
}
```

**Apache 示例：** 项目根目录已自带 `.htaccess`，启用 `mod_rewrite` 即可。

### 5. 设置目录权限

确保以下目录对 PHP 进程**可写**：

```
uploads/          # 用户上传内容（图片、附件、封面…）
logs/             # 系统日志（邮件日志、下载日志）
vendor/temp/      # 临时文件目录
vendor/nova-plugins/backup/backups/   # 备份文件存储
```

Linux / macOS 可执行：

```bash
chmod -R 755 uploads logs vendor/temp
chown -R www-data:www-data uploads logs vendor/temp
```

### 6. 访问站点

部署完成后，访问以下地址：

| 入口 | 地址 | 说明 |
| --- | --- | --- |
| 前台首页 | `http://your-domain/` | 博客前台 |
| 后台登录 | `http://your-domain/admin/login.php` | 管理后台入口 |
| API 根路径 | `http://your-domain/nova-json/` | RESTful API 入口 |

> 🔐 默认管理员账号请在首次登录后**立即修改密码**，并启用邮箱验证。

---

## 📁 目录结构

```
NovaCMS/
├── admin/                          # 后台管理
│   ├── api/                        #   后台数据接口（统计、上传、访问趋势等）
│   ├── includes/                   #   后台公共组件（header/footer/菜单/控制器）
│   ├── posts.php                   #   文章管理
│   ├── pages.php                   #   独立页面管理
│   ├── comments.php                #   评论审核
│   ├── categories.php              #   分类与标签
│   ├── instant.php                 #   片刻管理
│   ├── gallery.php                 #   相册管理
│   ├── guestbook.php               #   留言板
│   ├── links.php                   #   友情链接
│   ├── plugins.php                 #   插件管理（列表 + 详情 + 配置）
│   ├── plugin-page.php             #   插件自定义页面入口
│   ├── themes.php                  #   主题管理
│   ├── admins.php                  #   管理员与角色
│   ├── users.php                   #   前台用户
│   ├── stats.php                   #   访问统计
│   ├── config.php                  #   系统设置
│   ├── privacy_access.php          #   隐私访问控制
│   ├── subscriptions.php           #   邮件订阅
│   ├── files.php                   #   文件库
│   └── view_logs.php               #   系统日志
│
├── assets/                         # 全站静态资源（后台/公共页面）
│   ├── css/                        #   样式表（Bootstrap、后台样式、字体）
│   ├── js/                         #   脚本（jQuery/Vue/Chart.js/EasyMDE 等）
│   ├── fonts/                      #   字体文件（HarmonyOS Sans、Bootstrap Icons）
│   └── images/                     #   公共图片
│
├── cms-docs/                       # 📚 开发文档
│   ├── class.md                    #   核心类 API 参考
│   ├── routes.md                   #   API 路由文档
│   ├── plugin-dev.md               #   插件开发指南
│   └── theme-dev.md                #   主题开发指南
│
├── config/                         # 配置与公共函数库
│   ├── database.php                #   数据库连接配置
│   ├── functions.php               #   全局工具函数（核心）
│   ├── theme_functions.php         #   主题加载、路由解析、检查器
│   ├── content_module_functions.php #  内容模块（文章/页面/片刻/相册）
│   ├── comment_functions.php       #   评论与留言板
│   ├── account_functions.php       #   用户账户（注册/登录/找回密码）
│   ├── privacy_functions.php       #   隐私访问控制
│   ├── paid_functions.php          #   付费内容与支付
│   ├── email_config.php            #   邮件发送（PHPMailer）
│   └── cdn_config.php              #   CDN / 资源路径配置
│
├── license/                        # 系统页模板（兼容旧路径）
│
├── logs/                           # 日志目录
│   ├── email/                      #   邮件发送日志
│   └── download/                   #   下载日志
│
├── uploads/                        # 用户上传目录
│   ├── images/                     #   文章图片
│   ├── cover/                      #   封面图
│   ├── gallery/                    #   相册图片
│   ├── files/                      #   附件下载
│   ├── videos/                     #   视频
│   ├── audio/                      #   音频
│   ├── posts/                      #   文章附件
│   ├── instant/                    #   片刻附件
│   ├── logo/                       #   站点 Logo
│   └── friendlink/                 #   友链图标
│
├── vendor/
│   ├── api/
│   │   └── ipsearch/               #   IP 地理查询（qqwry / GeoCN 本地库）
│   │
│   ├── nova-json/                  # 🌐 RESTful API 框架
│   │   ├── class/                  #   核心类（数据库/插件/主题/REST/钩子/定时任务）
│   │   ├── routes/                 #   API 路由（content/posts/links/statuses/users/stats）
│   │   ├── test/                   #   API 调用示例页面
│   │   ├── docs/                   #   框架文档
│   │   └── init.php                #   API 初始化入口
│   │
│   ├── nova-plugins/               # 🔌 插件目录
│   │   ├── backup/                 #   备份插件（数据库+文件）
│   │   ├── comments/               #   QQ 头像增强插件
│   │   ├── cron-manager/           #   定时任务管理器
│   │   ├── netease-player/         #   网易云音乐播放器
│   │   ├── rss/                    #   RSS 订阅输出
│   │   └── sitemap/                #   站点地图 Sitemap
│   │
│   ├── nova-themes/                # 🎨 主题目录
│   │   ├── default/                #   默认主题（现代博客风）
│   │   ├── monochrome/             #   极简黑白主题
│   │   └── lumen/                  #   流光渐变主题
│   │
│   └── public/                     # 公共能力库
│       ├── captcha/                #   行为验证码（滑块+POW+轨迹）
│       ├── phpmailer/              #   PHPMailer 邮件库
│       ├── epay/                   #   易支付接口
│       ├── Parsedown.php           #   Markdown 解析
│       ├── error/                  #   多状态错误页模板
│       ├── cron/                   #   定时任务执行引擎
│       └── image_compressor/       #   图片压缩工具
│
├── index.php                       # 前台总入口（路由分发）
├── blog.sql                        # 数据库初始化脚本
├── 伪静态.txt                       # Nginx/Apache 伪静态规则参考
├── robots.txt                      # 搜索引擎爬虫规则
├── .htaccess                       # Apache 伪静态与目录保护
└── README.md                       # 本文档
```

---

## 🎨 主题系统

NovaCMS 的主题系统采用**目录隔离 + 声明式配置**，每个主题独立存放于 `vendor/nova-themes/{slug}/`，通过 `theme.json` 描述元信息。

### 内置主题

| 主题 | Slug | 版本 | 说明 |
| --- | --- | --- | --- |
| **默认主题** | `default` | `2.1.0` | 现代个人博客风，支持文章流、侧栏、深浅色切换、响应式社区页面 |
| **Monochrome** | `monochrome` | `1.0.0` | 极简黑白风格，专注阅读体验，适合技术博客与文字创作 |
| **Lumen** | `lumen` | `1.0.0` | 流光渐变风格，视觉活泼，适合作品集与个人主页 |

三套主题均内置以下模板：
- `index.php` 首页、`blog.php` 博客列表
- `page.php` 独立页面、`404.php` 错误页
- `instant.php` 片刻、`gallery.php` 相册
- `guestbook.php` 留言板、`friend-links.php` 友情链接
- `profile.php` 用户中心、`terms.php` 条款/隐私政策
- `partials/header.php` `navbar.php` `footer.php` 公共部件

> 主题可通过 `theme.json` 的 `page_templates` 字段声明独立页面布局（如默认页、宽版页、落地页），后台编辑器会自动读取。

### 主题开发

#### 最小目录结构

```
vendor/nova-themes/your-theme/
├── theme.json          # 主题元信息（必填）
├── index.php           # 首页模板（必填）
├── 404.php             # 404 模板（必填）
├── themes/
│   ├── partials/       # 公共部件（header/navbar/footer）
│   └── assets/         # 主题自有资源（CSS/JS/字体/图片）
├── screenshot.png      # 后台预览截图（建议 1280x800）
├── logo.png            # 主题 Logo（可选）
└── LICENSE             # 许可证文件
```

#### theme.json 核心字段

```json
{
  "name": "主题名称",
  "slug": "your-theme",
  "version": "1.0.0",
  "author": "作者名",
  "description": "主题描述",
  "parent": "default",
  "license": "MIT",
  "min_nova_version": "1.0.0",
  "page_templates": {
    "default": "默认页面",
    "wide": "宽版页面",
    "landing": "落地页"
  },
  "routes": {
    "/terms": "terms.php",
    "/privacy": "terms.php",
    "/cookies": "terms.php"
  }
}
```

#### 关键特性

- **自定义路由**：通过 `routes` 字段声明路径 → 模板映射，无需修改系统代码
- **子主题继承**：设置 `parent` 为父主题 slug，自动合并模板、资源与路由
- **安全校验**：后台会校验主题标识、清单格式、截图路径与必需模板，校验失败无法启用
- **安全回退**：若数据库配置的主题被删除或损坏，前台自动回退到 `default` 主题
- **预览模式**：管理员可在不切换当前主题的情况下，安全预览其他有效主题

> 📖 完整开发指南请阅读：[cms-docs/theme-dev.md](cms-docs/theme-dev.md)

---

## 🔌 插件系统

NovaCMS 插件遵循 `nova-json` 框架规范，核心文件为 `plugin/plugin.php`（入口）与 `plugin/class-*.php`（类实现），通过 `plugin.json` 声明元数据与能力。

### 内置插件

| 插件 | ID | 版本 | 说明 |
| --- | --- | --- | --- |
| **Backup** | `backup` | `1.0.0` | 一键备份 / 恢复数据库与全站文件，支持手动 + 定时自动备份 |
| **Comments** | `comments` | `1.0.0` | 评论 QQ 头像增强，自动拉取 QQ 邮箱对应头像 |
| **Cron Manager** | `cron-manager` | `1.0.0` | 定时任务管理，切换服务器 Cron / 虚拟面板模式，查看已注册任务与执行状态 |
| **Netease Player** | `netease-player` | `1.0.0` | 网易云音乐播放器，支持歌单/单曲嵌入 |
| **RSS** | `rss` | `1.0.0` | 生成并输出 RSS 2.0 订阅源，实时推送最新文章与片刻 |
| **Sitemap** | `sitemap` | `1.0.0` | 生成 XML 格式站点地图，助力搜索引擎收录与 SEO |

### 插件开发

#### 标准目录结构（NovaCMS 1.1+）

```
vendor/nova-plugins/your-plugin/
├── plugin.json           # 插件元数据 + 系统 ID（必填，ID 放最前）
├── plugin/
│   ├── plugin.php        # 插件入口文件（必填，注册钩子/菜单）
│   ├── class-*.php       # 核心类（如 class-your-plugin.php）
│   ├── routes/           # 自定义 API 路由
│   │   └── api.php
│   └── admin/            # 后台管理页面
│       ├── index.php     # 管理页内容
│       └── detail.php    # 自定义详情 Tab（可选）
├── assets/               # 插件静态资源（可选）
├── data/                 # 插件数据目录（可选）
└── LICENSE               # 许可证文件
```

#### plugin.json 核心字段

```json
{
  "id": "your_plugin",
  "name": "插件名称",
  "uri": "https://example.com",
  "description": "插件功能描述",
  "version": "1.0.0",
  "author": "作者名",
  "author_uri": "https://example.com",
  "entry": "plugin/plugin.php",
  "min_nova_version": "1.2",
  "sidebar": true,
  "config_path": "../../data/your-plugin/config.json",
  "detail_tab": "自定义Tab标题",
  "page_routes": {
    "/example": "plugin/pages/example.php",
    "/example/{id}": "plugin/pages/detail.php"
  }
}
```

#### 关键特性

- **英文插件 ID**：`id` 字段必须以字母开头，仅含字母/数字/下划线/连字符，且全局唯一
- **自定义页面路由**：通过 `page_routes` 声明前台虚拟路径，支持 `{param}` 参数注入
- **插件启用/禁用**：后台使用 Bootstrap 滑动开关，状态存于 `website_config.active_plugins`
- **PJAX 无刷新**：插件管理页与插件详情页均支持 PJAX，菜单自动刷新
- **详情页自定义 Tab**：通过 `detail_tab` + `plugin/admin/detail.php` 扩展后台详情页
- **外部配置重定向**：通过 `config_path` 将配置文件重定向到自定义路径
- **访问保护**：已禁用插件的页面路由被访问时返回 403「此插件已禁用」

> 📖 完整开发指南请阅读：[cms-docs/plugin-dev.md](cms-docs/plugin-dev.md)

---

## 🌐 API 与集成

NovaCMS 通过 `vendor/nova-json/` 提供一套 RESTful API 框架，路由入口为 `/nova-json/`。

### 内置路由模块

| 模块 | 前缀 | 能力 |
| --- | --- | --- |
| **内容** | `/nova-json/content/` | 站点信息、全局设置 |
| **文章** | `/nova-json/posts/` | 文章列表/详情、分类、标签、评论、搜索、下载、付费内容、隐私控制 |
| **友链** | `/nova-json/links/` | 友链列表、分类、申请提交、站点信息 |
| **状态** | `/nova-json/statuses/` | 片刻、相册专辑/照片、留言板、系统设置、条款 |
| **用户** | `/nova-json/users/` | 注册、登录、Token 刷新、用户信息、用户操作 |
| **统计** | `/nova-json/stats/` | 访问统计、访问趋势 |

### 已集成的第三方能力

- **行为验证码**：滑块拼图 + POW 工作量证明 + 行为轨迹分析（`vendor/public/captcha/`）
- **支付**：易支付（epay）接口（`vendor/public/epay/`）
- **邮件**：PHPMailer SMTP（`vendor/public/phpmailer/`）
- **IP 解析**：本地 qqwry / GeoCN 离线库，无需联网（`vendor/api/ipsearch/`）
- **Markdown**：Parsedown 解析 + EasyMDE 编辑器

> 📖 完整路由文档与示例请阅读：[cms-docs/routes.md](cms-docs/routes.md)
> 📖 核心类 API 参考：[cms-docs/class.md](cms-docs/class.md)
> 🧪 API 调用示例页面：`vendor/nova-json/test/*.html`

---

## 📚 开发文档

项目在 `cms-docs/` 目录提供了完整的二次开发文档：

| 文档 | 路径 | 内容 |
| --- | --- | --- |
| **核心类 API** | [cms-docs/class.md](cms-docs/class.md) | 数据库、插件、主题、REST、钩子、定时任务等核心类的方法参考 |
| **API 路由** | [cms-docs/routes.md](cms-docs/routes.md) | 所有 RESTful 接口的请求方式、参数、返回格式与示例 |
| **插件开发** | [cms-docs/plugin-dev.md](cms-docs/plugin-dev.md) | 插件结构、元数据声明、钩子系统、菜单注册、页面路由、配置管理、开发规范 |
| **主题开发** | [cms-docs/theme-dev.md](cms-docs/theme-dev.md) | 主题结构、theme.json 全字段说明、自定义路由、父主题继承、页面模板、资源引用 |

---

## 🔒 安全实践

NovaCMS 在架构层面内置了多重防护，部署时请额外执行以下加固：

### 目录与访问保护

- ✅ **敏感目录禁止直接访问**：`vendor/`、`config/`、`admin/includes/` 已通过 `.htaccess` 添加 `Require all denied`
- ✅ **文件入口保护**：所有敏感 PHP 文件（类文件、插件文件、页面路由）均以 `defined('NOVA_BOOTSTRAP') or exit` 开头，防止直接执行
- ✅ **备份目录保护**：`vendor/nova-plugins/backup/backups/` 已通过 `.htaccess` 按 User-Agent 限制访问

### CSRF 防护

- ✅ 所有状态变更操作（创建、删除、更新、封禁、审核等）均强制校验 CSRF Token
- ✅ 表单提交使用 `<?= csrfField() ?>` 注入隐藏字段
- ✅ AJAX 请求通过 `<meta name="csrf-token">` 获取 Token 并附加在请求体

### 输入与输出

- ✅ 数据库操作统一使用 PDO 预处理语句，杜绝 SQL 注入
- ✅ 模板输出统一使用 HTML 转义函数，杜绝 XSS
- ✅ 文件上传校验 MIME + 扩展名 + 尺寸，上传目录禁止执行 PHP

### 账户与会话

- ✅ 密码使用 `password_hash()` (bcrypt) 加盐哈希，不可逆存储
- ✅ 登录页启用行为验证码（滑块 + POW + 行为轨迹），防暴力破解
- ✅ 会话固定保护、登录失败计数与临时封禁
- ✅ 后台操作日志记录，支持审计追踪

### 生产环境建议

1. 将 `config/database.php` 中的配置改为**环境变量注入**，避免明文写入代码
2. 部署 SSL 证书，全站强制 HTTPS（HSTS 头）
3. 后台路径建议通过 Web 服务器做 IP 白名单或二级认证
4. 定期执行 Backup 插件的**自动备份**，并将备份文件下载到异地存储
5. 禁用 PHP `display_errors`，错误日志写入文件而非输出到页面
6. 保持 PHP / MySQL / Nginx 版本更新，及时补安全补丁

---

## ❓ 常见问题

### Q1: 导入数据库后页面空白？

检查：
1. `config/database.php` 的数据库连接是否正确
2. PHP 版本是否 ≥ 7.4，扩展 `pdo_mysql` / `mbstring` 是否启用
3. PHP 错误日志（通常在 `/var/log/php-fpm/` 或服务器面板的「错误日志」页）

### Q2: 访问文章/页面出现 404？

伪静态规则未生效，请检查：
1. Nginx 是否已添加 `try_files $uri $uri/ /index.php?$query_string;`
2. Apache 是否已启用 `mod_rewrite`，且 `.htaccess` 可被读取
3. 参考根目录 [伪静态.txt](伪静态.txt)

### Q3: 登录后台提示验证码不显示？

行为验证码依赖浏览器的 `window.crypto.subtle`（Web Crypto API），仅在 **HTTPS** 或 **localhost** 下可用。
- 本地上传：使用 `http://localhost/` 访问
- 生产环境：务必部署 SSL 证书，改用 HTTPS

### Q4: 上传图片/附件失败？

检查目录权限：
- `uploads/` 及其子目录对 PHP 进程必须可写
- PHP 配置项：`upload_max_filesize`、`post_max_size`、`memory_limit` 按需调大
- 磁盘空间是否充足

### Q5: 主题/插件在后台不显示？

1. 确认目录位置正确：主题 → `vendor/nova-themes/{slug}/`，插件 → `vendor/nova-plugins/{slug}/`
2. 检查 `theme.json` / `plugin.json` 的 JSON 语法是否合法（可用 JSONLint 校验）
3. 插件 `id` 是否与其他插件重复，是否以字母开头、仅含字母/数字/下划线/连字符

### Q6: 邮件发送失败？

1. 后台「系统设置 → 邮件配置」检查 SMTP 主机、端口、账号、授权码（注意是**授权码**而非邮箱登录密码）
2. 端口：465 走 SSL，25/587 走 STARTTLS，注意与加密方式匹配
3. 查看 `logs/email/` 目录下的日志文件获取详细报错

---

## 🤝 参与贡献

NovaCMS 目前处于活跃迭代阶段，欢迎通过以下方式参与建设：

### 反馈与建议

- **报告 Bug**：提交 Issue，请附带 PHP 版本、MySQL 版本、错误日志截图、复现步骤
- **提出特性**：欢迎建议新功能或改进方向，请说明使用场景与期望行为
- **安全反馈**：如发现安全漏洞，请邮件联系维护者（而非公开 Issue），我们会优先处理

### 贡献代码

1. Fork 本仓库到你的账号
2. 创建特性分支：`git checkout -b feature/your-feature`
3. 提交变更：`git commit -m 'feat: 添加 xxx 功能'`
4. 推送分支：`git push origin feature/your-feature`
5. 发起 Pull Request，描述变更内容与验证方式

### 贡献主题与插件

- 将你开发的主题放在 `vendor/nova-themes/` 下，插件放在 `vendor/nova-plugins/` 下
- 确保 `theme.json` / `plugin.json` 格式合法，所有敏感文件加入 NOVA_BOOTSTRAP 入口检查
- 提交 PR 时附上主题截图或插件功能说明，我们会优先收录优质主题与插件

> ⚠️ 注意：所有提交的代码须遵循项目现有编码风格，并确保**不会引入安全漏洞**。

---

## 📄 许可证

NovaCMS 为**开源项目**，默认主题与核心代码遵循各自目录下的 `LICENSE` 文件说明：

- 核心代码：详见各源码文件授权声明
- 默认主题 `default`：MIT License
- 其他主题与插件：以各自目录下的 `LICENSE` 为准
- 第三方库（PHPMailer、Parsedown、Bootstrap、jQuery 等）：遵循其原项目许可证

---

> 💡 **项目状态**：目前 NovaCMS 正处于测试与功能完善阶段，部分模块仍在持续迭代。如遇问题欢迎提交 Issue 或查阅 `cms-docs/` 获取开发文档。
