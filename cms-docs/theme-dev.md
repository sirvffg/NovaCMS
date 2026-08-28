# 主题开发

版本：1.2

***

NovaCMS 通过主题机制控制前台页面渲染。主题位于 `vendor/nova-themes/`，每个主题一个独立目录，通过 `theme.json` 声明元数据，模板文件放在 `themes/` 子目录输出 HTML。本文档基于系统实际实现（`config/theme_functions.php` 主题校验 + `index.php` 路由分发）描述如何从零开发一个 NovaCMS 主题。

***

## 目录

- [📄️ 介绍](#1-介绍)
- [📄️ 准备工作](#2-准备工作)
- [📄️ 入门：最小可运行主题](#3-入门最小可运行主题)
- [🗃 基础](#4-基础)
  - [主题加载与生命周期](#41-主题加载与生命周期)
  - [模板与路由分发](#42-模板与路由分发)
  - [模板作用域变量](#43-模板作用域变量)
  - [partials 与 assets](#44-partials-与-assets)
  - [页面模板（page_templates）](#45-页面模板page_templates)
  - [自定义路由（routes）](#46-自定义路由routes)
  - [父主题继承](#47-父主题继承)
  - [主题预览](#48-主题预览)
- [🗃 进阶能力](#5-进阶能力)
  - [theme.php 入口与 Nova_Theme 基类](#51-themephp-入口与-nova_theme-基类)
  - [钩子注入](#52-钩子注入)
  - [注册 REST API 路由](#53-注册-rest-api-路由)
  - [调用本地 API（Nova_API / Nova_Proxy）](#54-调用本地-apinova_api--nova_proxy)
  - [数据库操作](#55-数据库操作)
  - [文件上传与图片处理](#56-文件上传与图片处理)
- [🗃 API 参考](#6-api-参考)
- [🗃 案例与最佳实践](#7-案例与最佳实践)
- [🗃 附录](#8-附录)

***

## 1. 介绍

### 什么是 NovaCMS 主题？

主题是一组 PHP 模板 + 静态资源 + 清单文件的集合，控制前台所有页面的渲染。通过主题可以：

- **渲染页面** — 首页、文章列表、独立页面、片刻、相册、留言板、友链、个人中心、404
- **声明自定义路由** — `theme.json` 的 `routes` 字段把路径映射到模板（如 `/terms` → `terms.php`），无需改核心代码
- **声明页面布局** — `page_templates` 供后台「页面」编辑器选择布局
- **继承父主题** — 只写差异部分，模板/路由自动回退父主题
- **注册 REST 路由** — 通过 `themes/theme.php` 入口 + `Nova_Theme` 基类（仅 API 请求时加载）
- **挂载系统钩子** — `nova_head` / `nova_footer` / `nova_inject` 等
- **读取站点配置** — 模板作用域内的 `$config` 数组（站点名、favicon、社交链接等）

### 主题渲染架构概览

```
客户端请求
   │
   ├─ /nova-json/* ─────────────► vendor/nova-json/init.php（API 侧）
   │                                 └─ 自动加载所有主题的 themes/theme.php（如存在）
   │
   └─ 其他路径 ─────────────────► index.php（前台渲染）
                                      1. 插件 page_routes 匹配（最高优先级，命中即退出）
                                      2. .php 后缀 301 清洁化（GET/HEAD）
                                      3. 加载启用插件 → nova_init → 输出缓冲开启
                                      4. 主题解析：
                                         active_theme 配置
                                         → nova_theme_preview 签名预览（管理员）
                                         → novaThemeResolveActive() 校验
                                           （无效则 fallback 到 default）
                                      5. 路由分发：
                                         系统硬编码路由（/ /blog /page/{slug} ...）
                                         → 合并路由表（系统标准路由 + 主题 routes）
                                         → 直接文件回退
                                         → 404
                                      6. loadTheme($template, $data)
                                         → 主题模板 + partials 渲染
                                      7. shutdown：插件注入钩子输出插入 HTML
```

### 核心概念

| 概念 | 说明 |
| --- | --- |
| **slug** | 主题唯一标识 = 目录名。规则：以字母或数字开头，仅含字母/数字/`_`/`-`，长度 ≤ 100（`novaThemeIsValidSlug()` 校验） |
| **模板** | `themes/` 下的 `.php` 文件，由 `loadTheme($template, $data)` 加载（自动拼 `.php` 后缀） |
| **partials** | `themes/partials/` 子目录，通常拆分 `header.php` / `navbar.php` / `footer.php`，模板内手动 `include` |
| **theme.json** | 主题清单，声明元数据、布局与路由；由 `novaThemeInspect()` 校验 |
| **page_templates** | 独立页面的布局选项，后台「页面」编辑器读取为下拉框 |
| **routes** | 路径 → 模板映射，主题自主声明的自定义路由 |
| **parent** | 父主题 slug，声明后模板与路由可继承父主题 |
| **theme.php** | 主题可选入口（`themes/theme.php`），实例化 `Nova_Theme` 子类注册 REST 路由，**仅 `/nova-json/*` 请求时加载** |

### 内置主题（可作为开发参考）

| 主题 | slug | 特点 | 参考价值 |
| --- | --- | --- | --- |
| 默认主题 | `default` | 现代博客风、深浅色切换 | 完整模板集 + theme.json 全字段用法 |
| Monochrome | `monochrome` | 极简黑白 | 独立样式体系的主题 |
| Lumen | `lumen` | 流光渐变 | `"parent": "default"` 子主题继承范例 |

***

## 2. 准备工作

### 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 7.4+（推荐 8.0+；内置主题的 `str_starts_with` 等函数需 8.0） |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ |
| 扩展 | PDO、GD、JSON、mbstring |
| Web 服务器 | Apache / Nginx（需配置伪静态，见根目录 `伪静态.txt`） |

### 目录结构

```
vendor/nova-themes/
└── my-theme/                   # 主题目录（slug，唯一）
    ├── theme.json              # 元数据清单（必须）
    ├── LICENSE                 # 许可证文件（建议）
    ├── logo.png                # 主题小图标（默认查找 logo.png；支持 png/jpg/jpeg/webp/gif/svg/ico）
    ├── screenshot.png          # 主题大截图（默认查找 screenshot.png；支持 png/jpg/jpeg/webp/gif）
    └── themes/                 # 所有主题文件（模板、资源必须放这里）
        ├── index.php           # 首页模板（必须）
        ├── 404.php             # 404 模板（必须）
        ├── blog.php            # 文章列表页
        ├── page.php            # 独立页面模板（后台「页面」）
        ├── instant.php         # 片刻页
        ├── guestbook.php       # 留言板页
        ├── gallery.php         # 相册页
        ├── friend-links.php    # 友链页
        ├── profile.php         # 个人中心页
        ├── announcement.php    # 公告页（可选）
        ├── terms.php           # 条款/隐私页（配合 routes 使用）
        ├── theme.php           # 主题入口（可选，注册 REST 路由，详见 5.1）
        ├── partials/           # 模板片段（模板内手动 include）
        │   ├── header.php
        │   ├── navbar.php
        │   └── footer.php
        └── assets/             # 主题自有资源
            ├── css/
            ├── js/
            ├── fonts/
            └── images/
```

> ⚠️ 模板与资源必须放在 `themes/` 子目录下（`$themePath` 指向 `{slug}/themes`），放在主题根目录的模板不会被加载。

### theme.json 清单（全字段）

以 `vendor/nova-themes/default/theme.json` 为基础的真实示例：

```json
{
    "name": "默认主题",
    "slug": "default",
    "version": "2.1.0",
    "author": "NovaCMS",
    "description": "NovaCMS 现代个人博客主题，支持文章流、博客侧栏、深浅色与响应式社区页面",
    "logo": "logo.png",
    "screenshot": "screenshot.png",
    "license": "MIT",
    "min_nova_version": "1.0.0",
    "page_templates": {
        "default": "默认页面",
        "wide": "宽版页面",
        "landing": "落地页"
    }
}
```

带路由与继承的完整示例（参考 lumen / monochrome）：

```json
{
    "name": "Lumen 流光",
    "slug": "lumen",
    "version": "1.0.0",
    "author": "NovaCMS",
    "description": "轻盈通透的个人站主题",
    "logo": "logo.png",
    "screenshot": "screenshot.png",
    "license": "MIT",
    "min_nova_version": "1.0.0",
    "parent": "default",
    "page_templates": {
        "default": "标准页面",
        "wide": "宽幅阅读",
        "landing": "沉浸落地页"
    },
    "routes": {
        "/terms": "terms.php",
        "/privacy": "terms.php",
        "/cookies": "terms.php"
    }
}
```

#### 字段规范（`novaThemeInspect()` 实际校验）

| 字段 | 必填 | 类型/格式 | 校验级别 | 说明 |
| --- | --- | --- | --- | --- |
| `name` | 否 | string，≤120 字 | — | 主题名，留空以 slug 显示 |
| `slug` | 建议 | string | **error**（不一致时） | 必须与目录名完全一致 |
| `version` | 否 | string，≤40 | — | 默认 `1.0.0` |
| `author` | 否 | string，≤120 | — | 默认 `Unknown` |
| `description` | 否 | string，≤500 | — | 主题描述 |
| `homepage` / `theme_uri` | 否 | HTTP(S) URL | warning（格式错误时） | 主题主页 |
| `min_nova_version` | 否 | string，≤40 | — | 最低 NovaCMS 版本 |
| `parent` | 否 | 有效 slug | **error**（无效时） | 父主题 slug；不得等于自身；父主题必须存在且有 theme.json |
| `license` | 否 | string，≤64 | — | SPDX 标识或自定义文本 |
| `logo` | 否 | 相对路径 | warning（文件缺失时） | 默认 `logo.png`；支持 png/jpg/jpeg/webp/gif/svg/ico；路径必须相对且位于主题目录内（禁 `..`/绝对路径） |
| `screenshot` | 否 | 相对路径 | warning（文件缺失时） | 默认 `screenshot.png`；支持 png/jpg/jpeg/webp/gif |
| `page_templates` | 否 | object | — | key 须符合 slug 格式，value（标签）≤80 字；系统自动补 `default` 项 |
| `routes` | 否 | object | warning（不合法条目丢弃） | 详见 [4.6](#46-自定义路由routes) |

**校验结果分级：**

- **error**（`errors[]`）→ 主题标记 `valid = false`，后台无法启用；前台若为当前主题则自动回退 default
- **warning**（`warnings[]`）→ 仅提示，不影响使用

**必须存在的模板（缺失为 error）：**

| 模板 | 说明 |
| --- | --- |
| `themes/index.php` | 首页 |
| `themes/404.php` | 错误页 |

**系统识别的可选模板（存在即登记到 `templates[]`，缺失记入 `missing_templates[]`）：**

`blog`（文章列表）、`page`（独立页面）、`instant`（片刻）、`guestbook`（留言板）、`gallery`（图库）、`friend-links`（友情链接）、`announcement`（公告）、`profile`（个人中心）

***

## 3. 入门：最小可运行主题

一个只需 3 个文件的主题：

### 3.1 theme.json

```json
{
    "name": "我的主题",
    "slug": "my-theme",
    "version": "1.0.0",
    "author": "你的名字",
    "description": "最小可运行主题"
}
```

### 3.2 themes/index.php

```php
<?php
/** @var array $config 站点配置（website_config 整行） */
$siteName = e(trim((string)($config['website_name'] ?? 'NovaCMS')) ?: 'NovaCMS');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $siteName ?></title>
</head>
<body>
    <h1><?= $siteName ?></h1>
    <p>这是 <?= $siteName ?> 的首页。</p>
    <nav>
        <a href="/blog">文章</a> ·
        <a href="/guestbook">留言板</a> ·
        <a href="/friend-links">友链</a>
    </nav>
</body>
</html>
```

### 3.3 themes/404.php

```php
<?php http_response_code(404); ?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><title>404</title></head>
<body>
    <h1>404 - 页面不存在</h1>
    <a href="/">返回首页</a>
</body>
</html>
```

### 3.4 验证

1. 后台「系统 → 主题管理」（`/admin/themes.php`）出现「我的主题」，状态为有效（绿）
2. 点击启用 → 前台首页渲染 index.php
3. 访问不存在的路径 → 渲染 404.php
4. `/blog`、`/guestbook` 等系统路由 → 由于主题缺对应模板，走 `loadTheme()` 找不到文件 → 回退 404（补齐 `blog.php` 等模板即可）

***

## 4. 基础

### 4.1 主题加载与生命周期

一次前台请求中主题相关的完整时序（`index.php`）：

```
1. recordVisit() 记录访问
2. 插件 page_routes 匹配（命中则与主题无关，直接退出）
3. .php 后缀 301 重定向（GET/HEAD，/index.php 除外）
4. 前台插件运行时：加载启用插件入口 → nova_init → Nova_Cron::maybe_run_on_visit()
5. ob_start() + register_shutdown_function（插件注入钩子的输出缓冲）
6. 读取 $config = website_config 全行
7. 主题解析：
   a. $configuredTheme = $config['active_theme']（默认 'default'）
   b. 预览检查：?nova_theme_preview={slug}&nova_theme_token={token}（需管理员会话 + HMAC 校验）
   c. novaThemeResolveActive($configuredTheme)：
      - novaThemeFind() → novaThemeInspect() 校验
      - valid → 直接使用
      - 无效/不存在 → fallback 到 default（标记 using_fallback + fallback_reason，写 error_log）
      - 连 default 都无效 → 500「主题不可用」终止
8. 设置变量：$activeTheme / $themePath（= path/themes）/ $themeUrl
9. define('NOVA_THEME_URL', $themeUrl)
10. 路由分发（见 4.2）→ $route + $routeData
11. loadTheme($route, $routeData) → require 模板
12. shutdown：插件钩子输出插入 </head> / <body> / </nav> / </body> 锚点
```

#### 关键函数（config/theme_functions.php）

| 函数 | 说明 |
| --- | --- |
| `novaThemeRoot($root = null)` | 主题根目录，默认 `vendor/nova-themes` |
| `novaThemeInspect($themePath, $slug = null, $themesRoot = null)` | 校验单个主题，返回完整信息数组（见下表） |
| `novaThemeScan($themesRoot = null)` | 扫描所有主题（按名称排序），后台列表使用 |
| `novaThemeFind($slug, $themesRoot = null)` | 按 slug 查找并校验单个主题 |
| `novaThemeResolveActive($configuredSlug, $themesRoot = null, $fallbackSlug = 'default')` | 解析当前应使用的主题（含 fallback） |
| `novaThemePreviewToken($slug)` | 生成预览签名（HMAC-SHA256，基于会话 CSRF token） |
| `novaThemeValidatePreviewToken($slug, $token)` | 校验预览签名 |
| `novaThemeKnownTemplates()` | 系统识别的模板清单（slug → 中文名） |

`novaThemeInspect()` 返回数组关键字段：`slug / name / version / author / description / parent / license / path / logo_url / screenshot_url / templates / missing_templates / page_templates / routes / errors / warnings / valid`。

### 4.2 模板与路由分发

`index.php` 的完整分发优先级（从高到低）：

| 优先级 | 来源 | 示例 | 说明 |
| --- | --- | --- | --- |
| 1 | API 拦截 | `/nova-json/*` | 转交 API 框架，与主题无关 |
| 2 | 插件 page_routes | `/rss.xml` | 插件页面路由，命中即渲染插件文件 |
| 3 | .php 301 清洁化 | `/blog.php` → `/blog` | GET/HEAD 请求；POST 等放行走文件回退 |
| 4 | **系统硬编码路由** | `/` `/blog` `/page/{slug}` `/instant` `/guestbook` `/gallery` `/friend-links` `/announcement` `/profile` | 见下表，`match(true)` 精确/前缀匹配 |
| 5 | **合并路由表** | `/terms` `/privacy` `/cookies` | 系统标准路由 + 主题 routes 合并，见 4.6 |
| 6 | 直接文件回退 | `/vendor/login` | 物理文件存在则 require；无后缀路径尝试同名 .php；`terms/privacy/cookies` 单段路径除外（强制走合并路由表） |
| 7 | 404 | — | `theme404()` 加载 `themes/404.php` |

**系统硬编码路由表：**

| 请求路径 | 模板 | 附带数据 |
| --- | --- | --- |
| `/` | `index` | — |
| `/blog` | `blog` | — |
| `/page/{slug}`（正则 `#^/page/([^/]+)/?$#u`） | `page` | `$routeData['contentPage']`（`contentModuleGetPublishedPageBySlug()` 查询的已发布页面） |
| 前缀 `/instant` | `instant` | — |
| 前缀 `/guestbook` | `guestbook` | — |
| 前缀 `/gallery` | `gallery` | — |
| 前缀 `/friend-links` | `friend-links` | — |
| 前缀 `/announcement` | `announcement` | — |
| `/profile` | `profile` | — |

> `/blog` 同时承担文章列表与详情：详情通过查询参数区分（`/blog?id=N`），模板内自行读取 `$_GET['id']`。

**loadTheme 实现：**

```php
function loadTheme($template, array $data = []) {
    global $themePath, $activeTheme, $themeUrl, $config, $db;
    $file = $themePath . '/' . $template . '.php';
    if (file_exists($file)) {
        extract($data, EXTR_SKIP);   // $routeData 展开为模板变量
        require $file;
    } else {
        theme404();   // 模板缺失 → 404
    }
    exit;
}
```

> 模板不存在时**不会**自动回退父主题（系统路由场景）；仅「合并路由表」命中时有父主题回退逻辑（见 4.6/4.7）。

### 4.3 模板作用域变量

模板由 `loadTheme()` 在全局作用域 `require`，因此可用的变量/常量如下（均为真实存在，参考 default 主题写法）：

#### 全局变量

| 变量 | 类型 | 说明 |
| --- | --- | --- |
| `$config` | array | `website_config` 表整行：`website_name`、`website_author`、`description`、`robot_description`、`contact_email`、`favicon`、`active_theme`、`bing_verification` 等 |
| `$db` | PDO | 全局数据库连接 |
| `$themePath` | string | 当前主题模板目录（`vendor/nova-themes/{slug}/themes`），include partials 用 |
| `$activeTheme` | string | 当前主题 slug |
| `$themeUrl` | string | 同 `NOVA_THEME_URL` |
| `$requestPath` | string | 当前请求路径（`parse_url(REQUEST_URI, PHP_URL_PATH)`），terms.php 判断当前 Tab 用 |
| `$routeData` | array | 路由附带数据（如 page 模板的 `contentPage`）；经 `extract` 展开后可直接用 `$contentPage` |

#### 常量

| 常量 | 说明 |
| --- | --- |
| `NOVA_THEME_URL` | 主题资源 URL 前缀：`/vendor/nova-themes/{slug}/themes`（引用 CSS/JS 用，见 4.4） |
| `NOVA_THEME_PREVIEW` | 主题预览模式时定义（管理员预览其他主题） |
| `NOVA_BOOTSTRAP` | 框架启动标记（防直接访问校验用） |

#### 常用全局函数（config/*.php 已加载）

| 函数 | 说明 |
| --- | --- |
| `e($value)` | HTML 转义输出（**所有动态输出必须使用**） |
| `getDB()` | 获取 PDO 连接 |
| `generateCSRFToken()` / `validateCSRFToken($t)` | CSRF token 生成/校验 |
| `contentModuleSubstring($str, $start, $len)` | 内容模块的 UTF-8 安全截断 |
| `contentModuleGetPublishedPageBySlug($slug)` | 按 slug 取已发布页面 |
| `isCommentsEnabled()` | 评论是否开启（`config/comment_functions.php`） |

**常用 `$config` 字段速查：**

| 字段 | 说明 |
| --- | --- |
| `website_name` / `website_author` | 站点名 / 作者 |
| `description` / `robot_description` | 站点描述 / SEO 描述（default 主题有回退链：pageDescription → robot_description → description） |
| `favicon` | 站点图标 URL |
| `contact_email` | 联系邮箱 |
| `active_theme` | 配置的主题 slug（注意：预览模式下实际渲染的主题可能不同，模板内用 `$activeTheme` 才准确） |
| `bing_verification` | 必应站长验证码 |

> 💡 default 主题模板顶部的惯用写法（可作为起点）：
>
> ```php
> $siteName     = trim((string)($config['website_name'] ?? 'NovaCMS')) ?: 'NovaCMS';
> $pageDescription = trim(strip_tags((string)($config['description'] ?? '')));
> $faviconUrl   = trim((string)($config['favicon'] ?? ''));
> ```

### 4.4 partials 与 assets

#### partials（模板片段）

模板内**手动 include**，传递变量靠作用域（require 天然继承当前变量）：

```php
<?php
// themes/page.php 顶部
$pageTitle       = '关于';
$pageDescription = '关于本站';
$extraHead       = '<link href="' . NOVA_THEME_URL . '/assets/css/content.css" rel="stylesheet">';

include $themePath . '/partials/header.php';   // <head> + <body> 开始
include $themePath . '/partials/navbar.php';   // 导航
?>

<main id="main-content">
    <!-- 页面内容 -->
</main>

<?php include $themePath . '/partials/footer.php'; ?>
```

约定（参考 default 主题）：

| partial | 职责 | 消费的变量 |
| --- | --- | --- |
| `header.php` | `<!doctype>` 到 `</head>` + `<body>` 开始 | `$pageTitle`、`$pageDescription`、`$extraHead`、`$config` |
| `navbar.php` | 站点导航 | `$config` |
| `footer.php` | 页脚 + `</body></html>` | `$config`、`$extraFooter`（可选） |

#### assets（主题资源）

资源放 `themes/assets/`，引用一律使用 `NOVA_THEME_URL` 常量（default 主题 header.php 真实写法）：

```php
<link rel="stylesheet" href="<?= e(NOVA_THEME_URL) ?>/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= e(NOVA_THEME_URL) ?>/assets/css/style.css<?= $styleVersion > 0 ? '?v=' . $styleVersion : '' ?>">
```

**要点：**

1. `NOVA_THEME_URL` 已包含 `/vendor/nova-themes/{slug}/themes` 段，后续直接拼 `/assets/...`
2. **URL 中必须包含 `themes/` 段**——主题资源结构重构后，跨主题共享的存根文件若漏掉该段会产生 404
3. 版本号建议用文件修改时间，避免缓存（真实写法）：
   ```php
   $styleVersion = isset($themePath) ? (int)@filemtime($themePath . '/assets/css/style.css') : 0;
   ```
4. 跨主题共享资源（如 CDN 备用）可用 `getResourceUrl()`（后台）或直接写 jsDelivr CDN 绝对 URL（前台主题）
5. CSRF token 已由 default 主题注入 `<meta name="nova-csrf-token">`，主题自行实现时建议保留同样约定，供前端 JS 使用

### 4.5 页面模板（page_templates）

`theme.json` 的 `page_templates` 声明独立页面的布局选项，后台「页面」编辑器读取为下拉框，保存的值通过 `$contentPage` 传给 `page.php` 模板。

#### theme.json

```json
{
    "page_templates": {
        "default": "默认页面",
        "wide": "宽版页面",
        "landing": "落地页"
    }
}
```

- key：slug 格式（字母开头，字母/数字/`_`/`-`）
- value：后台下拉框显示的标签（≤80 字）
- 系统自动补 `default` 项（若未声明）

#### page.php 内部如何使用

后台保存的布局标识在 `$contentPage['template']`（default 主题 page.php 真实写法：`in_array($contentPage['template'] ?? '', ['wide', 'landing'], true) ? $contentPage['template'] : 'default'`），模板做白名单分发：

```php
<?php
// themes/page.php（default 主题真实模式：单模板 + CSS 类切换）
$pageTitle    = $contentPage['title'] ?? '页面';
$pageTemplate = in_array($contentPage['template'] ?? '', ['wide', 'landing'], true)
    ? $contentPage['template'] : 'default';

include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>

<main id="main-content" class="site-main content-page content-page-<?= e($pageTemplate) ?>">
    <!-- 正文：布局差异交给 CSS（.content-page-wide / .content-page-landing）-->
    <article>…</article>
</main>

<?php include $themePath . '/partials/footer.php'; ?>
```

> 💡 default 用「单 page.php + 布局类」控制差异；布局差异大时也可拆子模板（`include $themePath . '/page-' . e($pageTemplate) . '.php'`），但需自行保证文件存在，注意 `e()` 后的值做 `in_array` 白名单校验。

### 4.6 自定义路由（routes）

主题通过 `theme.json` 的 `routes` 字段声明「路径 → 模板」映射，**自主新增前台页面，无需修改任何系统代码**。

#### 工作流程

1. `novaThemeInspect()` 解析并校验 `routes`（不合法条目丢弃并记 warning，不致命）
2. 前台路由分发时（系统硬编码路由未命中），`index.php` 合并路由表：
   - 系统标准路由打底：`/terms => terms`、`/privacy => terms`
   - 父主题 routes（打底）
   - 当前主题 routes（同键覆盖）
   - `array_merge` 后形成最终路由表
3. 请求路径命中合并路由表 → 在当前主题 `themes/` 找模板：
   - 找到 → `loadTheme($templateName)` 渲染
   - 没找到且存在父主题 → 回退父主题 `themes/` 直接 `require`（并展开 `$routeData`）

#### theme.json 示例（default / monochrome / lumen 真实写法）

```json
{
    "routes": {
        "/terms":   "terms",
        "/privacy": "terms",
        "/cookies": "terms"
    }
}
```

三个路径共用 `terms.php` 模板，模板内用 `$requestPath` 区分当前 Tab：

```php
<?php
// themes/terms.php
$activeTab = 'terms';
if ($requestPath === '/privacy')     $activeTab = 'privacy';
if ($requestPath === '/cookies')     $activeTab = 'cookies';

$tabs = [
    'terms'   => ['href' => '/terms',   'label' => '服务条款'],
    'privacy' => ['href' => '/privacy', 'label' => '隐私政策'],
    'cookies' => ['href' => '/cookies', 'label' => 'Cookie 政策'],
];
// 渲染 Tab 导航 + 对应内容
```

#### 字段规范（校验失败的条目会被丢弃并记 warning，不致命）

| 项 | 规则 |
| --- | --- |
| 路径（key） | 正则 `#^/[A-Za-z0-9/_.-]{0,200}$#`：以 `/` 开头，仅字母/数字/`_`/`.`/`-`/`/`，总长 ≤ 200 |
| 模板名（value） | 正则 `/^[a-z0-9_-]{1,100}$/i`：仅字母/数字/下划线/短横线，1–100 字符（**不含 `.php` 后缀**，系统自动拼接） |

#### 路由优先级（从高到低）

1. 插件 `page_routes`
2. 系统硬编码路由（`/`、`/blog`、`/page/{slug}` 等）——**主题 routes 无法覆盖这些路径**
3. 合并路由表（系统标准路由打底，主题同键可覆盖 `/terms`、`/privacy`）
4. 直接文件回退（`terms/privacy/cookies` 单段路径例外，强制回路由表）

#### 模板作用域里拿到「当前请求路径」

`$requestPath` 全局变量（如上例）。也可用 `$_SERVER['REQUEST_URI']` 解析，但 `$requestPath` 已去除查询串，推荐直接用。

#### 父主题路由继承

合并顺序：**父主题 routes 打底 → 当前主题 routes 覆盖同键**。

```json
// 父主题 default
{ "routes": { "/terms": "terms", "/privacy": "terms", "/cookies": "terms" } }

// 子主题（可只覆盖需要的）
{ "routes": { "/privacy": "my-privacy" } }
// 最终生效：/terms→terms、/privacy→my-privacy、/cookies→terms
```

### 4.7 父主题继承

声明 `"parent": "{父主题slug}"` 即建立继承关系。

#### 继承规则

| 能力 | 继承方式 |
| --- | --- |
| **routes** | 父主题 routes 打底，子主题同键覆盖（合并路由表阶段） |
| **routes 模板回退** | 合并路由命中模板名后：子主题没有该模板 → 回退父主题 `themes/` 直接 require（含 `$routeData` 展开） |
| **partial / 其他模板** | 无自动回退。子主题模板内可**显式 require 父主题文件**复用（lumen 的做法） |
| **page_templates** | 不合并；子主题需完整声明（可照抄父主题） |
| **校验** | 父主题必须存在、位于 nova-themes 内且有合法 theme.json，否则子主题 error 级无效 |

#### lumen 复用父主题模板的真实写法

```php
<?php
// vendor/nova-themes/lumen/themes/terms.php —— 子主题复用 default 的模板
require dirname(__DIR__, 2) . '/default/themes/terms.php';
```

> `dirname(__DIR__, 2)`：`lumen/themes` → `lumen` → `nova-themes`，再进 `default/themes/`。

#### 校验细节

- `parent` 等于自身 slug → error「父主题标识无效」
- 父主题目录不存在 / 无 theme.json / 越出主题根 → error「父主题不存在或不完整」
- 子主题自身仍必须提供 `index.php` 与 `404.php`（这两个不继承）

### 4.8 主题预览

管理员可**不切换当前主题**临时预览任意已安装主题：

1. 后台主题管理页为每个有效主题生成预览链接（含签名 token）
2. token = `hash_hmac('sha256', 'nova-theme-preview:{slug}', 会话 CSRF token)`（`novaThemePreviewToken()`）
3. 访问 `?nova_theme_preview={slug}&nova_theme_token={token}`：
   - 需管理员会话（`$_SESSION['admin_id']`）+ `hash_equals` 常量时间比较
   - 校验通过 → 本次请求使用预览主题渲染，并加响应头：`Cache-Control: no-store`、`X-Robots-Tag: noindex, nofollow`，定义 `NOVA_THEME_PREVIEW`
   - 校验失败 → 静默忽略，继续使用当前主题

> 预览对访客不可见、对搜索引擎不收录，可安全用于主题开发调试。

***

## 5. 进阶能力

### 5.1 theme.php 入口与 Nova_Theme 基类

#### 加载机制

⚠️ **重要：`themes/theme.php` 仅在 `/nova-json/*` API 请求时加载**（`vendor/nova-json/init.php` 会 `glob('{nova-themes}/*/themes/theme.php')` 并 require）。前台页面请求（`index.php`）**不会**加载它。

因此 theme.php 的用途是：为主题注册 REST API 端点（供前端 JS 通过 API 取数据），而不是前台渲染逻辑。

#### 最小示例

```php
<?php
// themes/theme.php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class My_Theme extends Nova_Theme {

    protected $name    = 'my-theme';
    protected $version = '1.0.0';

    public function init() {
        // GET /nova-json/v1/my-theme/stats
        $this->register_route('v1', '/my-theme/stats', [
            'methods'  => 'GET',
            'callback' => function () {
                $db = $this->db();
                return ['posts' => (int)$db->get_var("SELECT COUNT(*) FROM posts")];
            },
        ]);
    }
}
new My_Theme();
```

#### Nova_Theme 类 API（vendor/nova-json/class/theme/class-theme.php）

| 成员 | 说明 |
| --- | --- |
| `__construct()` | 反射推导 `theme_path`/`theme_url` → 自动加载 `routes/*.php` → 注册 `init()` 到 `nova_init` |
| `register_route($namespace, $route, $args)` | 注册 REST 路由（内部挂 `rest_api_init`） |
| `load_routes()` | 自动加载 `{theme_path}/routes/*.php` |
| `db()` | 返回 `Nova_DB` 实例 |
| `asset($path)` | 主题资源 URL：`{theme_url}/assets/{path}` |
| `set_layout($layout)` | 设置布局标识 |
| `render($template, $data)` | 渲染 `views/{template}.php`（需自建 views 目录） |

| protected 属性 | 说明 |
| --- | --- |
| `$name` / `$version` | 主题名（留空取目录名）/ 版本 |
| `$theme_path` | theme.php 所在目录（即 `themes/`） |
| `$theme_url` | 该目录的完整 URL（自动按 http/https 推导） |
| `$layout` | 布局标识（默认 `default`） |

> REST 路由的 `{param}` 占位符、`permission_callback` 等写法与插件一致，详见 `cms-docs/plugin-dev.md` 第 4.4 节。

### 5.2 钩子注入

主题同样可以挂载前台注入钩子（在 `themes/theme.php` 的 `init()` 中）——但注意 5.1 的加载时机：**API 请求才会加载 theme.php**，而注入钩子在前台页面请求的 shutdown 阶段执行。因此主题的前台注入通常改由配套插件完成，或直接在 partials 输出。

若主题确实需要注入，标准做法（action 直接 echo）：

```php
Nova_Hooks::add_action('nova_head', function () {
    echo '<meta property="og:image" content="https://example.com/cover.jpg">' . "\n";
});
```

内置注入锚点：

| 钩子 | 注入位置 |
| --- | --- |
| `nova_head` | `</head>` 前 |
| `nova_body_start` | `<body>` 后 |
| `nova_navbar_end` | 首个 `</nav>` 后 |
| `nova_footer` | `</body>` 前 |
| `nova_inject`（filter） | 任意 CSS 选择器位置（注入项含 selector/position/html/retry/delay） |

详见 `cms-docs/plugin-dev.md` 第 4.3 节。

### 5.3 注册 REST API 路由

见 5.1（`Nova_Theme::register_route`）。与系统内置路由的关系：

- 系统内置路由在 `vendor/nova-json/routes/{模块}/`（content / posts / links / statuses / users / stats）
- 主题路由与插件路由共用 `Nova_REST_Server`，namespace 各自独立
- 文档：`cms-docs/routes.md`

### 5.4 调用本地 API（Nova_API / Nova_Proxy）

主题模板/JS 需要数据时两种方式：

| 方式 | 特点 |
| --- | --- |
| `Nova_API` | PHP 内部调度本地 REST 路由，零网络开销 |
| `Nova_Proxy` | 内部调度 + 公网代理请求 |

```php
// PHP 侧（theme.php 或模板内）—— Nova_API 为静态调用
$result = Nova_API::get('/v1/posts/hot');
$posts  = $result['data'] ?? [];

// 也可带查询参数
$result = Nova_API::get('/v1/posts', ['page' => 1, 'per_page' => 10]);
```

前端 JS 侧直接 fetch（推荐，与系统前端一致）：

```js
// 主题 assets/js 中
fetch('/nova-json/v1/posts/hot')
    .then(r => r.json())
    .then(res => {
        const posts = res.data ?? [];
        // 渲染到模板预置的骨架节点
    });
```

> 💡 default 主题 `blog.php` 就是这个模式：模板只输出骨架屏（`data-post-title`、`data-post-body` 等占位节点），由 `assets/js/content.js` 请求 `/nova-json/v1/...` 填充。SSR 骨架 + CSR 数据，首屏快且无需在模板里写查询。

### 5.5 数据库操作

模板内推荐用全局 `getDB()`（PDO）或 `Nova_DB`（链式/参数化），两者指向同一数据库：

```php
// 方式一：getDB()（前台模板最常用）
$db   = getDB();
$stmt = $db->prepare("SELECT id, title FROM posts WHERE status = 'published' ORDER BY created_at DESC LIMIT 10");
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 方式二：Nova_DB（参数化 CRUD）
$ndb = new Nova_DB();
$rows   = $ndb->get_results("SELECT * FROM posts WHERE category = ? ORDER BY id DESC LIMIT 10", [$cat]);
$count  = $ndb->get_var("SELECT COUNT(*) FROM posts");
```

**原则：**

1. 模板只做**只读查询**；写操作（发布、更新）应通过后台或 API 走完整校验链（CSRF、权限）
2. 所有 SQL 参数化，禁止字符串拼接用户输入
3. 预计数据量大时加 `LIMIT`，循环内禁止再发查询（先取 ID 批量查）
4. `theme.php`（API 侧）内用 `$this->db()` 获取 `Nova_DB` 实例

> 优先考虑「模板骨架 + JS 调 API」取数据（见 5.4），API 侧已有缓存与分页封装，模板内直接 SQL 仅适合少量固定数据（如页脚统计）。

### 5.6 文件上传与图片处理

主题模板通常不处理上传（评论/投稿等提交走 API）。若 `theme.php` 注册的路由确需接收文件，用 `Nova_Upload`：

```php
$this->register_route('v1', '/my-theme/upload', [
    'methods'  => 'POST',
    'callback' => function () {
        if (empty($_FILES['file'])) {
            return new Nova_REST_Response([
                'code' => 'no_file', 'message' => '未选择文件', 'data' => ['status' => 400],
            ], 400);
        }
        $upload = new Nova_Upload($_FILES['file']);
        $upload->onlyImages()->maxSize(2 * 1024 * 1024)->subDir('my-theme');

        if ($upload->validate()) {
            $result = $upload->save();          // ['url' => '/uploads/my-theme/xxx.jpg', 'path' => ...]
            return ['url' => $result['url']];
        }
        return new Nova_REST_Response([
            'code' => 'upload_failed', 'message' => $upload->getError(), 'data' => ['status' => 400],
        ], 400);
    },
]);
```

> 系统路由的错误返回惯例是 `new Nova_REST_Response(['code' => ..., 'message' => ..., 'data' => ['status' => N]], N)`（参考 `routes/statuses/guestbook.php` 的 `nova_guestbook_error()`）。

图片处理（缩略图/水印，GD）：

```php
$img = new Nova_Image('uploads/my-theme/source.jpg');
$img->thumb(300, 200)->save('uploads/my-theme/thumb.jpg');
```

> 完整链式 API 见 `cms-docs/plugin-dev.md` 第 4.9 节（`allowedTypes` / `prefix` / `overwrite` 等），两个扩展体系共用同一套类。

***

## 6. API 参考

### 6.1 主题函数（config/theme_functions.php）

| 函数 | 说明 |
| --- | --- |
| `novaThemeRoot($root = null)` | 主题根目录（默认 `vendor/nova-themes`） |
| `novaThemeInspect($themePath, $slug = null, $themesRoot = null)` | 校验单个主题，返回信息数组（slug/name/version/…/templates/routes/errors/valid） |
| `novaThemeScan($themesRoot = null)` | 扫描全部主题（按名称排序） |
| `novaThemeFind($slug, $themesRoot = null)` | 按 slug 查找并校验 |
| `novaThemeResolveActive($configuredSlug, $themesRoot = null, $fallbackSlug = 'default')` | 解析生效主题（无效自动回退） |
| `novaThemeIsValidSlug($slug)` | slug 合法性（字母/数字开头，`_`/`-`，≤100） |
| `novaThemePreviewToken($slug)` | 生成预览签名（HMAC-SHA256） |
| `novaThemeValidatePreviewToken($slug, $token)` | 校验预览签名 |
| `novaThemeKnownTemplates()` | 系统识别的模板清单 |

### 6.2 Nova_Theme（vendor/nova-json/class/theme/class-theme.php）

见 [5.1 theme.php 入口与 Nova_Theme 基类](#51-themephp-入口与-nova_theme-基类)。

| 成员 | 说明 |
| --- | --- |
| `__construct()` | 反射推导 `$theme_path`/`$theme_url` → 自动加载 `routes/*.php` → `init()` 注册到 `nova_init` 钩子 |
| `register_route($ns, $route, $args)` `protected` | 注册 REST 路由（内部挂 `rest_api_init`） |
| `load_routes()` `protected` | 自动 require `{theme_path}/routes/*.php` |
| `db()` `protected` | 返回 `Nova_DB` 实例 |
| `asset($path)` `protected` | `{theme_url}/assets/{path}` |
| `set_layout($layout)` `protected` | 设置布局标识 |
| `render($template, $data)` `protected` | 渲染 `views/{template}.php`（extract 展开数据） |

### 6.3 模板作用域速查

见 [4.3 模板作用域变量](#43-模板作用域变量)：`$config` / `$db` / `$themePath` / `$activeTheme` / `$themeUrl` / `$requestPath` / `$routeData` + `NOVA_THEME_URL` / `NOVA_THEME_PREVIEW` 常量 + `e()` / `getDB()` / `generateCSRFToken()` 等全局函数。

### 6.4 相关类

- `Nova_Hooks` — Actions/Filters（见 `cms-docs/plugin-dev.md` 4.2）
- `Nova_REST_Server` — REST 分发（见 `cms-docs/plugin-dev.md` 5.5）
- `Nova_DB` / `Nova_Upload` / `Nova_Image` / `Nova_API` / `Nova_Proxy` — 详见 `cms-docs/class.md`

***

## 7. 案例与最佳实践

### 7.1 案例一：最小独立主题（参考 monochrome）

特点：不依赖父主题，自建完整模板集与样式体系。

```
vendor/nova-themes/monochrome/
├── theme.json           # 无 parent、无 routes 的极简声明
├── logo.png
├── screenshot.png
└── themes/
    ├── index.php        # 独立实现的首页
    ├── 404.php
    ├── blog.php / page.php / instant.php / guestbook.php / gallery.php / friend-links.php / profile.php
    ├── partials/        # 自己的 header/navbar/footer
    └── assets/          # 自己的 CSS/JS
```

适用：风格与 default 差异大、不想背继承包袱的主题。开发量 = 全套模板，但不存在父主题升级连带影响。

### 7.2 案例二：子主题（参考 lumen）

特点：`"parent": "default"`，只写差异文件，其余 require 父主题模板。

```php
<?php
// vendor/nova-themes/lumen/themes/terms.php —— 直接复用 default 的模板
require dirname(__DIR__, 2) . '/default/themes/terms.php';
```

适用：想调整 default 的局部视觉/文案。注意继承边界（见 4.7）：

- `routes` 自动合并继承（父打底、子覆盖）
- 其他模板**没有**自动回退，需要哪个就显式 require 哪个
- `index.php` / `404.php` 必须自己提供

### 7.3 案例三：SSR 骨架 + CSR 数据（default 的 blog.php 模式）

模板输出骨架占位，JS 从 `/nova-json/v1/` 取数填充：

```php
<?php
// themes/my-archive.php
$postId = max(0, (int)($_GET['id'] ?? 0));
$extraFooter = '<script src="' . e(NOVA_THEME_URL) . '/assets/js/archive.js?v=' . e(@filemtime($themePath . '/assets/js/archive.js')) . '"></script>';
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<main id="main-content" data-archive-page data-post-id="<?= $postId ?>">
    <div data-archive-list>
        <div class="skeleton"></div>   <!-- JS 到位后替换为真实列表 -->
    </div>
</main>
<?php include $themePath . '/partials/footer.php'; ?>
```

```js
// assets/js/archive.js
fetch('/nova-json/v1/posts?page=1')
    .then(r => r.json())
    .then(res => {
        document.querySelector('[data-archive-list]').innerHTML =
            (res.data ?? []).map(p => `<article><h2>${p.title}</h2></article>`).join('');
    });
```

适用：列表/详情类页面。优点：复用 API 的鉴权、缓存、分页；模板保持轻薄。

### 7.4 最佳实践清单

1. **模板输出必转义**：动态内容一律 `e()`，Markdown 渲染交给系统 API（已做过滤）
2. **资源引用只用 `NOVA_THEME_URL`**：路径含 `themes/` 段，勿手写 `/vendor/nova-themes/{slug}/...`（lumen 复用 default 资源时除外）
3. **资源带版本号**：`?v=' . @filemtime(...)` 破缓存，用户改版后无需教访客强刷
4. **slug 与目录名严格一致**，`theme.json` 里如实声明
5. **CSRF 约定保留**：`<meta name="nova-csrf-token">`（default 的 header.php 写法），前端提交类 JS 需要它
6. **POST/状态变更不进模板**：模板只读；写操作走 `/nova-json/*` API（含 CSRF/权限校验）
7. **模板缺失场景自查**：系统路由对应的模板（blog/page/instant/guestbook/gallery/friend-links/announcement/profile）缺失时该路径直接 404，发布前逐一路径检查
8. **routes 键避开系统路由**：`/`、`/blog`、`/page/*` 等无法被主题 routes 覆盖（见 4.6 优先级）
9. **子主题升级注意**：父主题模板改动可能影响 require 它的子主题，升级父主题后回归测试子主题
10. **深浅色适配**：default 用 `data-bs-theme` 属性切换；自建主题至少声明 `color-scheme`，避免表单控件在暗色系统下发白刺眼

***

## 8. 附录

### 8.1 访问控制

所有敏感主题 PHP 文件（theme.php、partials、模板）**建议首行**：

```php
<?php defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
```

**原理**：`NOVA_BOOTSTRAP` 仅由 `index.php`（前台）与 `vendor/nova-json/init.php`（API）定义。直接通过 URL 访问主题文件时该常量未定义，脚本立即退出，防止绕过路由/权限逻辑执行模板。（系统硬编码路由加载的普通模板通常省略此行——default 主题即如此；但 `theme.php` 这类含注册逻辑的入口文件必须加。）

**额外防护**（已由系统提供，主题无需处理）：

- `vendor/nova-themes/` 与 `vendor/` 下各敏感目录通过 `.htaccess` 拒绝直接访问
- 主题目录经 realpath 校验，必须位于 `vendor/nova-themes` 之内
- logo/screenshot 路径经规范化校验（禁 `..`、绝对路径、控制字符，且必须真实存在于主题目录内）

### 8.2 主题开发检查清单

- [ ] 目录 `vendor/nova-themes/{slug}/`，slug 与目录名一致（`[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}`）
- [ ] `themes/index.php` 与 `themes/404.php` 存在（error 级必需）
- [ ] 系统路由模板按需补齐（blog/page/instant/guestbook/gallery/friend-links/announcement/profile）
- [ ] 所有模板/局部文件输出经 `e()` 转义
- [ ] 资源引用使用 `NOVA_THEME_URL` 且路径含 `themes/` 段
- [ ] CSS/JS 引用带 `filemtime` 版本号
- [ ] `theme.json` 声明 `min_nova_version`（使用 routes 字段需 ≥ 1.1）
- [ ] routes 键不与系统路由冲突，value 无 `.php` 后缀；`/terms`、`/privacy`、`/cookies` 不要创建同名物理文件
- [ ] 子主题的 `parent` 指向存在且合法的主题；`index.php`/`404.php` 自备
- [ ] screenshot.png（建议 1200×675）、logo.png（建议 256×256）就位
- [ ] 后台主题管理页显示「有效」（无 error / warning）
- [ ] `?nova_theme_preview={slug}&...` 预览通过后再启用

### 8.3 相关文件索引

| 文件 | 作用 |
| --- | --- |
| `config/theme_functions.php` | 主题校验/扫描/解析/预览签名全部函数 |
| `index.php` | 前台路由分发、主题加载、合并路由表、loadTheme() |
| `vendor/nova-json/init.php` | API 侧自动加载所有 `themes/theme.php` |
| `vendor/nova-json/class/theme/class-theme.php` | `Nova_Theme` 基类 |
| `admin/themes.php` | 后台主题管理（启用/预览/删除） |
| `vendor/nova-themes/default/` | 完整参考实现（模板集 + partials + routes） |
| `vendor/nova-themes/lumen/` | 子主题参考（parent 继承 + require 复用） |
| `vendor/nova-themes/monochrome/` | 独立主题参考（无继承） |

### 8.4 调试技巧

| 现象 | 排查 |
| --- | --- |
| 后台列表主题「无效」 | 看 `errors[]`：slug 不一致 / 缺 index 或 404 / parent 非法 |
| 前台整站回退 default | 当前主题 error 级无效（error_log 有 fallback_reason） |
| 自定义路由 404 | routes 键格式（`#^/[A-Za-z0-9/_.-]{0,200}$#`）或 value 带 `.php` → 条目被丢弃，检查 warnings |
| 资源 404 | URL 是否含 `themes/` 段；文件是否真的放在 `themes/assets/` 下 |
| theme.php 的路由不生效 | 确认请求路径是 `/nova-json/{ns}/...`（theme.php 仅 API 侧加载） |
| 改了 CSS 不生效 | 版本号参数没变化 → 用 `filemtime`；浏览器缓存 → Ctrl+F5 |

### 8.5 版本兼容性

| NovaCMS 版本 | 主题规范 |
| --- | --- |
| 1.1+ | 当前规范：模板/资源位于 `themes/` 子目录；theme.json 支持 routes、parent、page_templates、logo/screenshot 相对路径校验 |
| 1.0（旧） | 模板位于主题根目录（无 themes/ 段）；新系统按 `themes/` 查找，旧主题需把模板与 partials 移入 `themes/` |

> 用 `min_nova_version` 声明最低版本（使用 routes 字段需 ≥ 1.1）；三个内置主题的 theme.json 均已声明。
