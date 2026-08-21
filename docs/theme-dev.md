# 主题开发

版本：1.0

***

NovaCMS 采用主题机制控制前台页面渲染。主题位于 `vendor/nova-themes/`，每个主题一个独立目录，通过 `theme.json` 声明元数据、模板文件输出 HTML。本文档描述如何从零开发一个 NovaCMS 主题，并集成 `class/` 目录下的类库与 `routes/` 目录下的 REST API。

***

## 目录

- [📄️ 介绍](#1-介绍)
- [📄️ 准备工作](#2-准备工作)
- [📄️ 入门：最小可运行主题](#3-入门最小可运行主题)
- [🗃 基础](#4-基础)
  - [主题加载与生命周期](#41-主题加载与生命周期)
  - [目录结构](#42-目录结构)
  - [theme.json 清单](#43-themejson-清单)
  - [模板与路由分发](#44-模板与路由分发)
  - [模板作用域变量](#45-模板作用域变量)
  - [partials 与 assets](#46-partials-与-assets)
  - [页面模板（page_templates）](#47-页面模板page_templates)
  - [父主题继承](#48-父主题继承)
  - [主题预览](#49-主题预览)
- [🗃 进阶能力](#5-进阶能力)
  - [theme.php 入口与 Nova_Theme 基类](#51-themephp-入口与-nova_theme-基类)
  - [钩子系统](#52-钩子系统)
  - [注册 REST API 路由](#53-注册-rest-api-路由)
  - [调用本地 API（Nova_API / Nova_Proxy）](#54-调用本地-apinova_api--nova_proxy)
  - [数据库操作](#55-数据库操作)
  - [文件上传与图片处理](#56-文件上传与图片处理)
- [🗃 API 参考](#6-api-参考)
  - [Nova_Theme](#61-nova_theme)
  - [Nova_Hooks](#62-nova_hooks)
  - [register_rest_route](#63-register_rest_route)
  - [Nova_DB](#64-nova_db)
  - [Nova_API](#65-nova_api)
  - [Nova_Proxy](#66-nova_proxy)
- [🗃 案例和最佳实践](#7-案例和最佳实践)
  - [示例一：读取站点图标与配置](#71-示例一读取站点图标与配置)
  - [示例二：自定义页面模板分支](#72-示例二自定义页面模板分支)
  - [示例三：主题注册独立 REST 端点](#73-示例三主题注册独立-rest-端点)
- [🗃 附录](#8-附录)
  - [访问控制](#81-访问控制)
  - [开发检查清单](#82-开发检查清单)
  - [版本兼容性](#83-版本兼容性)

***

## 1. 介绍

### 什么是 NovaCMS 主题？

主题是一组 PHP 模板 + 静态资源 + 清单文件的集合，控制前台所有页面的渲染。开发者通过主题可以：

- **渲染页面** — 文章、独立页面、文档、说说、相册、留言板、友链、个人中心
- **注册 REST 路由** — 通过 `theme.php` + `Nova_Theme` 基类
- **挂载系统钩子** — `nova_head` / `nova_footer` / `nova_inject` 等
- **读取站点配置** — `$config` 全局数组（favicon、网站名、社交链接等）
- **操作数据库** — 直接使用 `Nova_DB` 系列
- **调用本地/外部 API** — `Nova_API` / `Nova_Proxy`

### 主题架构概览

```
┌─────────────────────────────────────────────────────────┐
│                      客户端请求                          │
└──────────────────────┬──────────────────────────────────┘
                       │ HTTP
┌──────────────────────▼──────────────────────────────────┐
│                   index.php (前台入口)                   │
│  1. 加载 nova-json/init.php（已 require 全部 class/*.php）│
│  2. 扫描 vendor/nova-themes/*/themes/theme.php 并 require_once │
│  3. 触发 nova_init（主题/插件的 init() 在此执行）         │
│  4. 解析当前主题 → 设置 $themePath / NOVA_THEME_URL       │
│  5. 路由分发 match → loadTheme($route, $data)             │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│             主题模板文件 (vendor/nova-themes/{slug}/)    │
│  index.php / 404.php / page.php / blog.php / ...        │
│  └─ partials/header.php + navbar.php + footer.php       │
└──────────────────────┬──────────────────────────────────┘
                       │ 可选
┌──────────────────────▼──────────────────────────────────┐
│         Nova_REST_Server (REST API 路由分发)              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │  系统内置路由  │  │  插件注册路由  │  │  主题注册路由  │  │
│  │ routes/*.php │  │              │  │              │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│       Nova_Hooks / Nova_DB / Nova_API / Nova_Proxy      │
└─────────────────────────────────────────────────────────┘
```

### 核心概念

| 概念        | 说明                                                              |
| --------- | --------------------------------------------------------------- |
| **slug**  | 主题唯一标识，对应 `vendor/nova-themes/{slug}/` 目录名，必须为英文/数字/`_`/`-` |
| **模板**   | 主题根目录下的 `.php` 文件，由 `loadTheme($template, $data)` 加载            |
| **partials** | `partials/` 子目录，通常拆分为 `header.php` / `navbar.php` / `footer.php` |
| **page_templates** | `theme.json` 中声明的页面模板，供后台「页面」编辑器作为下拉选项                    |
| **parent** | 父主题 slug，用于模板继承（缺文件时回退到父主题）                                   |
| **theme.php** | 主题可选入口，实例化 `Nova_Theme` 子类以注册路由/钩子                            |

***

## 2. 准备工作

### 环境要求

| 项目       | 要求                         |
| -------- | -------------------------- |
| PHP      | 7.4+ 推荐 8.0+（使用 `str_starts_with` 等需 8.0） |
| 数据库      | MySQL 5.7+ / MariaDB 10.3+ |
| 扩展       | PDO, GD（图片处理）, JSON         |
| Web 服务器  | Apache / Nginx / IIS       |

### 目录结构

NovaCMS 主题约定放置在 `vendor/nova-themes/` 目录，每个主题一个独立文件夹：

```
vendor/nova-themes/
└── my-theme/                   # 主题目录（slug，唯一）
    ├── theme.json              # 元数据清单（必须）
    ├── LICENSE                # 许可证文件（建议，内容由作者自定义）
    ├── logo.png               # 主题小图标（建议 256×256，后台卡片 28×28 显示）
    ├── screenshot.png         # 主题大截图（建议 1200×675，16:9）
    └── themes/                # 所有主题文件（PHP/JS/CSS 等）
        ├── index.php           # 首页模板（必须）
        ├── 404.php             # 404 模板（必须）
        ├── blog.php            # 文章列表页（推荐）
        ├── page.php            # 独立页面模板（推荐，对应后台「页面」）
        ├── document.php        # 文档详情页（推荐）
        ├── docs.php            # 文档列表页（推荐）
        ├── shuoshuo.php        # 说说页（推荐）
        ├── guestbook.php       # 留言板页（推荐）
        ├── gallery.php         # 相册页（推荐）
        ├── friend-links.php    # 友链页（推荐）
        ├── profile.php         # 个人中心页（推荐）
        ├── announcement.php    # 公告页（可选）
        ├── theme.php           # 主题入口（可选，用于注册路由/钩子）
        ├── partials/            # 模板片段
        │   ├── header.php
        │   ├── navbar.php
        │   └── footer.php
        ├── assets/             # 静态资源
        │   ├── css/
        │   ├── js/
        │   ├── fonts/
        │   └── images/
        └── (views/)            # 可选，Nova_Theme::render() 渲染目录
```

> **根目录文件**：`theme.json`（必须）、`LICENSE`、`logo.png`、`screenshot.png`（建议）。根目录**只放**这四个文件，所有其他文件（PHP 模板、JS、CSS 等）必须放在 `themes/` 子目录内。
>
> **必须模板**：`themes/index.php`、`themes/404.php`。缺一不可，否则后台校验失败、主题不可启用。

### 快速启动模板

```bash
cd vendor/nova-themes/
mkdir -p my-theme/themes/partials my-theme/themes/assets/css my-theme/themes/assets/js

# 创建清单
cat > my-theme/theme.json << 'EOF'
{
    "name": "我的主题",
    "slug": "my-theme",
    "version": "1.0.0",
    "author": "你的名字",
    "description": "我的第一个 NovaCMS 主题",
    "logo": "logo.png",
    "screenshot": "screenshot.png",
    "license": "MIT",
    "min_nova_version": "1.0.0"
}
EOF

# 创建最小模板
cat > my-theme/themes/index.php << 'EOF'
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
include $themePath . '/partials/header.php';
?>
<main class="site-main"><div class="container"><h1>hello, <?= e($siteName ?? 'NovaCMS') ?></h1></div></main>
<?php include $themePath . '/partials/footer.php'; ?>
EOF

cat > my-theme/themes/404.php << 'EOF'
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
http_response_code(404);
echo '<h1>404</h1>';
EOF
```

完成后访问后台「外观 → 主题」，即可看到并启用新主题。

***

## 3. 入门：最小可运行主题

下面创建一个真正可用的最小主题，演示如何读取站点配置、引入资源、使用 partials。

### 3.1 `theme.json`

```json
{
    "name": "Hello Theme",
    "slug": "hello-theme",
    "version": "1.0.0",
    "author": "NovaCMS",
    "description": "最小可运行主题示例",
    "logo": "logo.png",
    "screenshot": "screenshot.png",
    "license": "MIT",
    "min_nova_version": "1.0.0",
    "page_templates": {
        "default": "默认页面"
    }
}
```

### 3.2 `partials/header.php`

```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

$siteName = trim((string)($config['website_name'] ?? 'NovaCMS')) ?: 'NovaCMS';
$pageTitle = trim((string)($pageTitle ?? '')) ?: $siteName;

// 安全读取 favicon（来自 website_config.favicon）
$faviconUrl = trim((string)($config['favicon'] ?? ''));
$faviconIsSafe = $faviconUrl !== ''
    && !preg_match('/[\x00-\x1F\x7F\\\\]/', $faviconUrl)
    && (
        (str_starts_with($faviconUrl, '/') && !str_starts_with($faviconUrl, '//')) ||
        (filter_var($faviconUrl, FILTER_VALIDATE_URL)
            && in_array(strtolower((string)parse_url($faviconUrl, PHP_URL_SCHEME)), ['http', 'https'], true))
    );

// 主题资源 URL（由 index.php 定义的全局常量）
$cssVersion = (int)@filemtime($themePath . '/assets/css/style.css');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <?php if ($faviconIsSafe): ?>
        <link rel="icon" href="<?= e($faviconUrl) ?>">
        <link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(NOVA_THEME_URL) ?>/assets/css/style.css<?= $cssVersion > 0 ? '?v=' . $cssVersion : '' ?>">
</head>
<body class="<?= e($pageKey ?? 'page') ?>">
```

### 3.3 `partials/footer.php`

```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
?>
<footer class="site-footer">
    <div class="container">&copy; <?= date('Y') ?> <?= e($siteName ?? 'NovaCMS') ?></div>
</footer>
</body>
</html>
```

### 3.4 `index.php`

```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

$pageTitle = '首页';
$pageKey = 'home';
$siteName = trim((string)($config['website_name'] ?? 'NovaCMS')) ?: 'NovaCMS';

include $themePath . '/partials/header.php';
?>
<main class="site-main">
    <div class="container">
        <h1><?= e($siteName) ?></h1>
        <p>欢迎来到 <?= e($siteName) ?>。</p>
    </div>
</main>
<?php
include $themePath . '/partials/footer.php';
```

### 3.5 `404.php`

```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
http_response_code(404);
$pageTitle = '页面不存在';
include $themePath . '/partials/header.php';
?>
<main class="site-main"><div class="container"><h1>404</h1><p>抱歉，页面未找到。</p></div></main>
<?php include $themePath . '/partials/footer.php';
```

完成后进入后台启用主题即可访问首页。

***

## 4. 基础

### 4.1 主题加载与生命周期

NovaCMS 前台入口 `index.php` 加载主题的流程：

```
请求进入 index.php
   │
   ├── require vendor/nova-json/init.php
   │     ├─ require 全部 class/*.php（Nova_Theme、Nova_Hooks、Nova_DB、Nova_REST_*…）
   │     ├─ require 全部 routes/*.php（系统内置 REST 路由注册）
   │     ├─ 扫描 vendor/nova-plugins/*/plugin/plugin.php 并 require（仅启用项）
   │     └─ 扫描 vendor/nova-themes/*/themes/theme.php 并 require_once
   │
   ├── Nova_Hooks::do_action('nova_init')
   │     └─ 执行所有主题/插件的 init()
   │
   ├── 读取 website_config.active_theme → novaThemeResolveActive()
   │     ├─ 校验 theme.json、必需模板、slug
   │     ├─ 失败 → 回退到 default 主题
   │     └─ 设置 $themePath / $activeTheme / NOVA_THEME_URL
   │
   ├── 路由分发 match($requestPath) → $route
   │     └─ 例：/page/{slug} → 'page' → contentModuleGetPublishedPageBySlug() → $routeData['contentPage']
   │
   └── loadTheme($route, $routeData)
         ├─ extract($routeData, EXTR_SKIP)  // 注入到模板作用域
         └─ require $themePath . '/' . $route . '.php'
```

#### 关键时序

| 时机                  | 已可用资源                                                        |
| ------------------- | ------------------------------------------------------------ |
| `theme.php` 构造函数执行时 | `Nova_Theme`、`Nova_Hooks`、`Nova_DB` 等类，但 `nova_init` 未触发 |
| `init()` 被调用时（`nova_init` 钩子） | 全部类、全部已加载插件，但 `$config` / `$themePath` 等全局变量还未设置     |
| 模板文件被 require 时     | `$config`、`$db`、`$themePath`、`$themeUrl`、`NOVA_THEME_URL`、`extract` 注入的 `$routeData` |

> **注意**：`init()` 在路由分发之前触发，此时还不知道要渲染哪个页面。需要请求信息的逻辑应在模板文件内执行，或注册到 `nova_head` / `nova_footer` 等模板渲染时触发的钩子。

***

### 4.2 目录结构

完整结构参考 [2. 准备工作 → 目录结构](#目录结构)。主题校验规则：

| 项目                | 规则                                                                                |
| ----------------- | --------------------------------------------------------------------------------- |
| 目录名（slug）         | 必须满足 `^[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}$`，且与 `theme.json` 中的 `slug` 一致              |
| 必需文件              | `theme.json`、`index.php`、`404.php`                                                |
| 截图                | `screenshot.png`/`jpg`/`jpeg`/`webp`/`gif`，路径必须在主题目录内                              |
| 路径安全              | 主题目录必须位于 `vendor/nova-themes/` 内（`realpath` 校验）                                  |
| `min_nova_version` | 可选；若设置且系统版本低于要求，主题不可启用                                                            |

校验失败的主题在后台「外观 → 主题」会显示「校验失败」徽标且无法启用。若数据库 `active_theme` 指向的主题被删除或损坏，前台会自动回退到 `default` 主题。

***

### 4.3 theme.json 清单

```json
{
    "name": "我的主题",
    "slug": "my-theme",
    "version": "1.0.0",
    "author": "NovaCMS",
    "author_uri": "https://example.com",
    "description": "主题描述",
    "logo": "logo.png",
    "screenshot": "screenshot.png",
    "license": "MIT",
    "min_nova_version": "1.0.0",
    "parent": "default",
    "page_templates": {
        "default": "默认页面",
        "wide": "宽版页面",
        "landing": "落地页"
    }
}
```

| 字段                  | 类型     | 必填 | 说明                                                                                  |
| ------------------- | ------ | -- | ----------------------------------------------------------------------------------- |
| `name`              | string | 是  | 主题显示名称                                                                              |
| `slug`              | string | 是  | 主题唯一标识，必须与目录名一致，满足 `novaThemeIsValidSlug` 规则                                        |
| `version`           | string | 是  | 版本号                                                                                 |
| `author`            | string | 否  | 作者                                                                                  |
| `author_uri`        | string | 否  | 作者主页                                                                                |
| `description`       | string | 否  | 主题描述                                                                                |
| `logo`              | string | 否  | 主题小图标相对路径，默认 `logo.png`，扩展名限 `png`/`jpg`/`jpeg`/`webp`/`gif`/`svg`/`ico`。后台主题卡片以 28×28 显示 |
| `screenshot`        | string | 否  | 大截图相对路径，默认 `screenshot.png`，扩展名限 `png`/`jpg`/`jpeg`/`webp`/`gif`                      |
| `license`           | string | 否  | 许可证标识（SPDX 字符串或自定义文本，如 `MIT`、`GPL-2.0`、`proprietary`），最长 64 字符。对应主题根目录的 `LICENSE` 文件   |
| `min_nova_version`  | string | 否  | 最低 NovaCMS 版本要求                                                                     |
| `parent`            | string | 否  | 父主题 slug，用于模板继承                                                                     |
| `page_templates`    | object | 否  | 页面模板映射，key 为模板 slug（必须满足 slug 规则），value 为显示名。系统会自动补 `default`。详见 [4.7 页面模板](#47-页面模板page_templates) |

> **slug 校验**：`novaThemeIsValidSlug()`（[config/theme_functions.php#L17](file:///d:/File/代码/网站源码/NovaCMS/config/theme_functions.php#L17)）使用正则 `/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}\z/D`（`\A`/`\z` 严格首尾锚 + `D` 修饰符禁用 `$` 多行匹配），等价于 `^[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}$` 语义，首位必须是字母或数字，长度 1-100。

***

### 4.4 模板与路由分发

NovaCMS 前台在 `index.php` 用 `match(true)` 表达式匹配请求路径，输出路由名：

| 请求路径                                | 路由名                  | 加载模板             | 注入数据 `$routeData`                                           |
| ----------------------------------- | -------------------- | ---------------- | ----------------------------------------------------------- |
| `/`、`/index.php`                    | `index`              | `index.php`      | —                                                           |
| `/blog`、`/blog.php`                 | `blog`               | `blog.php`       | —                                                           |
| `/page/{slug}`                      | `page`               | `page.php`       | `contentPage` = `contentModuleGetPublishedPageBySlug()`    |
| `/docs`、`/docs/`                    | `docs`               | `docs.php`       | `documentResults`、`documentCategories`                      |
| `/docs/{slug}`                      | `document`           | `document.php`   | `document` = `contentModuleGetPublishedDocumentBySlug()`     |
| `/docs/{slug}/download`             | `document-download`  | （重定向，不渲染模板）      | —                                                           |
| `/shuoshuo`                         | `shuoshuo`          | `shuoshuo.php`   | —                                                           |
| `/guestbook`                        | `guestbook`         | `guestbook.php`  | —                                                           |
| `/gallery`                         | `gallery`          | `gallery.php`    | —                                                           |
| `/friend-links`                    | `friend-links`     | `friend-links.php` | —                                                           |
| `/profile`                         | `profile`          | `profile.php`    | —                                                           |
| `/announcement`                    | `announcement`     | `announcement.php` | —                                                         |
| 其他在根目录存在的 PHP 文件                    | （直接 require）       | 对应文件             | —                                                           |
| 其他                                  | `false`             | `404.php`        | —                                                           |

`loadTheme()` 实现：

```php
function loadTheme($template, array $data = []) {
    global $themePath, $activeTheme, $themeUrl, $config, $db;
    $file = $themePath . '/' . $template . '.php';
    if (file_exists($file)) {
        extract($data, EXTR_SKIP);   // 把 $data 的 key 注入为变量
        require $file;
    } else {
        theme404();
    }
    exit;
}
```

**关键点**：

- `$template` 是路由名（如 `page`），不是数据库里的模板 slug
- `extract($data, EXTR_SKIP)` 把 `$routeData` 数组的 key 展开为模板作用域变量，**不会覆盖已存在的同名变量**
- 模板文件不存在 → 调用 `theme404()` 输出 404 页面
- 父子主题：若当前主题缺少 `$template.php`，不会自动回退到父主题（继承仅作用于 `theme.json` 字段与后台显示）

> 若需要按 `page_templates` 选择不同 PHP 文件，应在 `page.php` 内部 `include` 对应的 `page-{template}.php`，详见 [示例二](#72-示例二自定义页面模板分支)。

***

### 4.5 模板作用域变量

模板被 `require` 时，以下变量通过 `global` 或 `extract` 进入作用域：

| 变量                  | 来源                          | 类型     | 说明                          |
| ------------------- | --------------------------- | ------ | --------------------------- |
| `$config`           | `global`（`website_config` 表） | array  | 全站配置（favicon、website_name 等） |
| `$db`               | `global`                    | PDO    | 原生 PDO 实例                  |
| `$themePath`         | `global`                    | string | 当前主题 `themes/` 子目录绝对路径       |
| `$activeTheme`       | `global`                    | string | 当前主题 slug                   |
| `$themeUrl`          | `global`                    | string | 主题 `themes/` 子目录 URL 前缀（同 `NOVA_THEME_URL`） |
| `$pageTitle`         | 模板自定义                       | string | 当前页面标题（建议在模板开头赋值）           |
| `$pageKey`           | 模板自定义                       | string | 当前页面标识（用于 body class）       |
| `$pageDescription`   | 模板自定义                       | string | 当前页面描述（用于 meta description） |
| `$extraHead`         | 模板自定义                       | string | 注入到 `</head>` 之前的额外 HTML     |
| `$extraFooter`       | 模板自定义                       | string | 注入到 `</body>` 之前的额外 HTML     |
| `$contentPage`       | `extract`（路由 `page`）         | array  | 独立页面数据                      |
| `$document`          | `extract`（路由 `document`）     | array  | 文档数据                        |
| `$documentResults`   | `extract`（路由 `docs`）        | array  | 文档列表分页结果                    |
| `$documentCategories`| `extract`（路由 `docs`）        | array  | 文档分类列表                      |

#### 常用全局常量

| 常量               | 定义位置        | 说明                       |
| ---------------- | ----------- | ------------------------ |
| `NOVA_THEME_URL` | `index.php` | 当前主题 URL，如 `/vendor/nova-themes/default` |
| `NOVA_BOOTSTRAP` | `index.php` / `admin-bootstrap.php` | 访问控制标志                    |
| `NOVA_API`       | `init.php`  | 是否为 API 请求               |
| `NOVA_THEME_PREVIEW` | `index.php` | 主题预览模式（仅当管理员带签名预览时定义）     |

***

### 4.6 partials 与 assets

#### partials/

通常把 `<head>`、导航、页脚拆到 `partials/` 子目录，模板文件用 `include` 引入：

```php
<?php
// page.php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

$pageTitle = $contentPage['title'] ?? '页面';
$pageKey = 'page';

// 主题专属资源（带文件 mtime 版本号，避免缓存）
$contentCssVersion = (string)(@filemtime($themePath . '/assets/css/content.css') ?: 1);
$extraHead  = '<link href="' . NOVA_THEME_URL . '/assets/css/content.css?v=' . e($contentCssVersion) . '" rel="stylesheet">';
$extraFooter = '<script src="' . NOVA_THEME_URL . '/assets/js/content.js"></script>';

include $themePath . '/partials/header.php';   // 输出 <html><head>...<body>
include $themePath . '/partials/navbar.php';    // 输出 <nav>...</nav>
?>
<main class="site-main">
    <!-- 页面内容 -->
</main>
<?php
include $themePath . '/partials/footer.php';   // 输出 </body></html>
```

#### assets/

`assets/` 子目录存放主题静态资源，通过 `NOVA_THEME_URL` 引用：

```php
<link rel="stylesheet" href="<?= e(NOVA_THEME_URL) ?>/assets/css/style.css">
<script src="<?= e(NOVA_THEME_URL) ?>/assets/js/theme.js"></script>
<img src="<?= e(NOVA_THEME_URL) ?>/assets/images/logo.png" alt="Logo">
```

> **推荐做法**：用 `filemtime` 给 CSS/JS 加版本号，避免浏览器缓存旧版本：
>
> ```php
> $v = (int)@filemtime($themePath . '/assets/css/style.css');
> echo '<link rel="stylesheet" href="' . e(NOVA_THEME_URL) . '/assets/css/style.css?v=' . $v . '">';
> ```

#### 资源 URL 安全

`NOVA_THEME_URL` 已对 slug 做 `rawurlencode`，可直接拼接到 HTML 属性中。但若路径包含中文/特殊字符，仍建议对完整 URL 做 `e()` 转义。

***

### 4.7 页面模板（page_templates）

`theme.json` 的 `page_templates` 字段声明主题支持的页面模板，供后台「内容 / 页面」编辑器作为下拉选项。

#### 工作流程

```
theme.json: page_templates
       ↓
config/theme_functions.php: novaThemeNormalizePageTemplates()
       ↓  （校验 key 是否为合法 slug，自动补 default）
admin/pages.php: 渲染 select 字段
       ↓
用户选择 → 存入 cms_pages.template 字段
       ↓
前台 /page/{slug} → contentModuleGetPublishedPageBySlug() 返回 contentPage（含 template）
       ↓
loadTheme('page', ['contentPage' => $row])
       ↓
page.php 内部读 $contentPage['template'] 切换样式或加载不同子模板
```

#### theme.json 示例

```json
{
    "page_templates": {
        "default": "默认页面",
        "wide": "宽版页面",
        "landing": "落地页"
    }
}
```

- **key**：模板 slug，必须满足 `novaThemeIsValidSlug`（首位字母/数字，仅字母/数字/`_`/`-`）
- **value**：显示名（任意字符串）
- 系统会自动补 `default => '默认页面'`（若未声明）

#### page.php 内部如何使用

默认主题的 `page.php` 用 `in_array` 白名单切换 CSS class：

```php
<?php
$pageTemplate = in_array($contentPage['template'] ?? '', ['wide', 'landing'], true)
    ? $contentPage['template']
    : 'default';
?>
<main class="content-page content-page-<?= e($pageTemplate) ?>">
    <!-- 根据 class 渲染不同样式 -->
</main>
```

若想为不同模板加载完全不同的 PHP 文件：

```php
<?php
$tpl = $contentPage['template'] ?? 'default';
$file = $themePath . '/page-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $tpl) . '.php';
if (is_file($file)) {
    include $file;
} else {
    include $themePath . '/page-default.php';
}
```

详见 [示例二](#72-示例二自定义页面模板分支)。

***

### 4.8 父主题继承

通过 `theme.json` 的 `parent` 字段声明父主题：

```json
{
    "name": "Lumen 流光",
    "slug": "lumen",
    "parent": "default",
    ...
}
```

#### 继承规则

| 字段/资源            | 是否继承自父主题 | 说明                                       |
| ---------------- | -------- | ---------------------------------------- |
| `page_templates` | 是        | 子主题未声明时，使用父主题的；子主题声明则覆盖                  |
| `min_nova_version` | 否        | 各自独立                                     |
| 模板文件             | 否        | 模板查找不回退到父主题，子主题需自己提供（或用 `include` 显式引用父主题文件） |
| `assets/`        | 否        | 资源路径基于当前主题 `NOVA_THEME_URL`，不回退          |

> **常见用法**：子主题只重写部分模板（如 `index.php`），其他模板用 `include` 显式引入父主题：
>
> ```php
> <?php
> // lumen/blog.php
> include dirname($themePath) . '/default/blog.php';
> ```

***

### 4.9 主题预览

管理员可在不切换当前主题的情况下临时预览其他主题：

```
https://your-site.com/?nova_theme_preview={slug}&nova_theme_token={token}
```

- 必须已登录管理员（`$_SESSION['admin_id']` 存在）
- `nova_theme_token` 通过后台「外观 → 主题」的「预览」按钮生成（带签名）
- 预览模式会设置 `NOVA_THEME_PREVIEW` 常量，并发送 `Cache-Control: no-store`、`X-Robots-Tag: noindex` 等响应头，避免被缓存或搜索引擎收录

模板中可通过 `defined('NOVA_THEME_PREVIEW') && NOVA_THEME_PREVIEW` 判断是否处于预览模式。

***

## 5. 进阶能力

### 5.1 theme.php 入口与 Nova_Theme 基类

若主题需要注册 REST 路由、挂载钩子、或使用 `views/` 渲染机制，可在 `themes/` 子目录创建 `theme.php`，并继承 `Nova_Theme` 基类。

#### 加载机制

`vendor/nova-json/init.php` 在加载所有类文件后：

```php
$themesDir = dirname($novaDir) . '/nova-themes';
if (is_dir($themesDir)) {
    foreach (glob($themesDir . '/*/theme.php') as $theme) {
        require_once $theme;
    }
}

Nova_Hooks::do_action('nova_init');   // 触发所有主题/插件的 init()
```

- 文件名**必须**是 `theme.php`（位于 `themes/` 子目录内）
- 一个主题可有任意数量的类，但入口处必须 `new MyTheme();` 实例化
- `init()` 方法会被自动挂载到 `nova_init` 钩子，在所有主题/插件加载完后统一触发

#### 最小示例

```php
<?php
// vendor/nova-themes/my-theme/themes/theme.php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class MyTheme extends Nova_Theme {

    protected $version = '1.0.0';

    public function init() {
        // 设置布局名（对应 views/{layout}.php）
        $this->set_layout('default');

        // 注册 REST 路由
        $this->register_route('v1', '/my-theme/info', [
            'methods'  => 'GET',
            'callback' => [$this, 'getInfo'],
        ]);

        // 挂载钩子
        Nova_Hooks::add_action('nova_head', [$this, 'injectMeta']);
    }

    public function getInfo($request) {
        return new Nova_REST_Response([
            'code' => 'rest_ok',
            'data' => [
                'name'    => $this->name,
                'version' => $this->version,
                'path'    => $this->theme_path,
                'url'     => $this->theme_url,
            ],
        ]);
    }

    public function injectMeta() {
        echo '<meta name="my-theme-version" content="' . e($this->version) . '">';
    }
}

new MyTheme();
```

#### 自动属性

`Nova_Theme` 构造函数自动检测：

| 属性           | 类型     | 说明                                |
| ------------ | ------ | --------------------------------- |
| `$name`      | string | 主题名称（未设置时取目录名）                    |
| `$version`   | string | 版本号（默认 `'1.0.0'`）                 |
| `$theme_path` | string | 主题目录绝对路径                          |
| `$theme_url`  | string | 主题完整 URL，如 `https://example.com/vendor/nova-themes/my-theme` |

> **注意**：`Nova_Theme::$theme_url` 是完整 URL（含协议和 host），而模板里的 `NOVA_THEME_URL` 是相对路径（`/vendor/nova-themes/{slug}`）。在 HTML 中引用资源时，优先用 `NOVA_THEME_URL`。

***

### 5.2 钩子系统

主题可使用 `Nova_Hooks` 在前台注入内容或修改数据。所有钩子用法与插件一致（详见 `plugin-dev.md`）。

#### 内置钩子

| 钩子名                 | 类型     | 时机                      | 适用范围  |
| ------------------- | ------ | ----------------------- | ----- |
| `nova_init`          | Action | 系统初始化完成时                 | API + 前台 |
| `rest_api_init`      | Action | REST API 路由注册时           | 仅 API |
| `nova_head`          | Action | 前台 `</head>` 之前          | 仅前台   |
| `nova_body_start`    | Action | 前台 `<body>` 之后           | 仅前台   |
| `nova_navbar_end`    | Action | 前台首个 `</nav>` 之后          | 仅前台   |
| `nova_footer`        | Action | 前台 `</body>` 之前          | 仅前台   |
| `nova_inject`        | Filter | 页面输出前收集任意位置注入项           | 仅前台   |

> **说明**：`plugin-dev.md` 中提到的 `nova_post_data` 过滤器目前在源码中**尚未被 `apply_filters` 真正触发**，仅作为文档示例与 `Nova_Hooks` 用法说明存在。主题不要依赖此钩子修改文章数据。

#### 示例：在 `<head>` 注入 Open Graph 标签

```php
class MyTheme extends Nova_Theme {
    public function init() {
        Nova_Hooks::add_action('nova_head', [$this, 'injectOg']);
    }

    public function injectOg() {
        $siteName = $GLOBALS['config']['website_name'] ?? 'NovaCMS';
        echo '<meta property="og:site_name" content="' . e($siteName) . '">';
    }
}
new MyTheme();
```

#### nova_inject（任意位置注入）

`nova_inject` 是 Filter 钩子，支持基于 CSS 选择器向任意 DOM 位置注入 HTML：

```php
class MyTheme extends Nova_Theme {
    public function init() {
        // 仅在文章详情页注入
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($path === '/blog' && (int)($_GET['id'] ?? 0) > 0) {
            Nova_Hooks::add_filter('nova_inject', [$this, 'injectWidget']);
        }
    }

    public function injectWidget(array $items): array {
        $items[] = [
            'selector' => 'article.article-shell',
            'position' => 'append',           // before | after | prepend | append
            'html'     => '<div class="article-widget">主题注入内容</div>',
            'retry'    => 3,                  // 重试次数（默认 3，最大 10）
            'delay'    => 200,                // 重试间隔毫秒（默认 200，最大 5000）
        ];
        return $items;
    }
}
new MyTheme();
```

| position 值  | 插入位置       |
| ----------- | ---------- |
| `before`    | 目标元素**之前**（同级） |
| `after`     | 目标元素**之后**（同级） |
| `prepend`   | 目标元素内部**开头**  |
| `append`    | 目标元素内部**末尾**  |

> **注意**：钩子输出原样插入 HTML，请用 `e()` / `htmlspecialchars` 转义动态内容防止 XSS。

***

### 5.3 注册 REST API 路由

主题可通过 `theme.php` 注册 REST 路由，与系统内置路由（`vendor/nova-json/routes/`）共用同一个 `Nova_REST_Server`。

#### 路由示例

```php
class MyTheme extends Nova_Theme {
    public function init() {
        // GET /nova-json/v1/my-theme/posts
        $this->register_route('v1', '/my-theme/posts', [
            'methods'  => 'GET',
            'callback' => [$this, 'getPosts'],
        ]);

        // 带路径参数
        $this->register_route('v1', '/my-theme/posts/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'getPost'],
        ]);
    }

    public function getPosts($request) {
        $per_page = min(50, max(1, (int)$request->get_param('per_page') ?: 10));
        $page     = max(1, (int)$request->get_param('page') ?: 1);

        $db = $this->db();
        $items = $db->get_results(
            "SELECT id, title, slug FROM blog_posts WHERE is_published = 1 ORDER BY published_at DESC LIMIT ?, ?",
            [($page - 1) * $per_page, $per_page]
        );

        return new Nova_REST_Response([
            'code' => 'rest_ok',
            'data' => ['items' => $items, 'page' => $page, 'per_page' => $per_page],
        ]);
    }

    public function getPost($request) {
        $id = (int)$request->get_param('id');
        $db = $this->db();
        $post = $db->get_row("SELECT * FROM blog_posts WHERE id = ? AND is_published = 1", [$id]);
        if (!$post) {
            return new Nova_REST_Response([
                'code'    => 'rest_error',
                'message' => '文章不存在',
                'data'    => ['status' => 404],
            ], 404);
        }
        return new Nova_REST_Response([
            'code' => 'rest_ok',
            'data' => $post,
        ]);
    }
}
new MyTheme();
```

#### 与系统内置路由的关系

系统内置路由位于 `vendor/nova-json/routes/`，按模块拆分：

| 目录                  | 模块          | 命名空间 | 主要路由                                                                  |
| ------------------- | ----------- | ----- | --------------------------------------------------------------------- |
| `routes/posts/`     | 文章          | `v1`  | `/v1/posts`、`/v1/posts/{id}`、`/v1/posts/slug/{slug}`、`/v1/categories`、`/v1/tags`、`/v1/comments`、`/v1/search`、`/v1/posts/privacy`、`/v1/posts/paid`、`/v1/posts/{id}/download` |
| `routes/links/`     | 友链          | `v1`  | `/v1/links`、`/v1/links/categories`、`/v1/links/apply`、`/v1/links/siteinfo` |
| `routes/statuses/`  | 动态/社区       | `v1`  | `/v1/statuses/settings`、`/v1/statuses/shuoshuo`、`/v1/statuses/gallery/*`、`/v1/statuses/guestbook`、`/v1/statuses/terms` |
| `routes/users/`     | 用户          | `v1`  | `/v1/auth/login`、`/v1/auth/verify`、`/v1/auth/me`、`/v1/auth/logout`    |
| `routes/content/`   | 内容（页面/文档）   | `v1`  | 内容模块（页面/文档）相关端点                                                        |

完整端点列表见 `vendor/nova-json/index.php` 头部注释或 `docs/routes.md`。主题注册的路由与系统路由共用同一前缀（`/nova-json/v1/`），建议用主题 slug 作命名空间隔离（如 `/v1/my-theme/posts`）。

#### 调用本地 API 的两种方式

主题既可以用 `register_rest_route` 注册路由让客户端调用，也可以在 PHP 层用 `Nova_API::get/post/put/delete()` 或 `Nova_Proxy::internal()` 调用已注册的端点（零网络开销）。详见 [5.4](#54-调用本地-apinova_api--nova_proxy)。

***

### 5.4 调用本地 API（Nova_API / Nova_Proxy）

#### Nova_API（内部调度，零网络开销）

`Nova_API` 是静态类，直接调用 `Nova_REST_Server` 已注册的回调，不走 HTTP：

```php
class MyTheme extends Nova_Theme {
    public function init() {
        Nova_Hooks::add_action('nova_init', [$this, 'preloadPosts']);
    }

    public function preloadPosts() {
        // 调用 GET /v1/posts?per_page=5
        $result = Nova_API::get('/v1/posts', ['per_page' => 5]);
        // $result 是数组或 Nova_REST_Response
        $GLOBALS['myThemeRecentPosts'] = $result['data']['items'] ?? [];
    }
}
new MyTheme();
```

#### Nova_Proxy（内部 + 公网代理）

`Nova_Proxy` 提供更强的能力：

- `internal($route, $method, $params)` — 内部调度本地 API（零网络开销），与 `Nova_API` 类似
- `request($url, $method, $options)` — 公网代理请求外部 URL，内置 SSRF 防护

```php
class MyTheme extends Nova_Theme {
    public function init() {
        Nova_Hooks::add_action('nova_head', [$this, 'injectGithubStars']);
    }

    public function injectGithubStars() {
        // 公网请求 GitHub API（在 nova-themes/ 目录内调用才允许）
        $resp = Nova_Proxy::request('https://api.github.com/repos/NovaCMS/NovaCMS', 'GET', [
            'headers' => ['Accept' => 'application/vnd.github+json'],
            'timeout' => 5,
        ]);
        $stars = $resp['data']['body']['stargazers_count'] ?? 0;
        echo '<meta name="github-stars" content="' . (int)$stars . '">';
    }
}
new MyTheme();
```

> **调用来源校验**：`Nova_Proxy` 用 `debug_backtrace` 检查调用栈，只有栈中存在来自 `nova-themes/` 或 `nova-plugins/` 目录的帧才放行，否则抛 `RuntimeException`。在 `routes/` 路由文件或根目录脚本调用会失败。

详见 [6.5 Nova_API](#65-nova_api) 与 [6.6 Nova_Proxy](#66-nova_proxy)。

***

### 5.5 数据库操作

主题可直接使用 `Nova_DB` 系列（与插件用法完全一致，详见 `docs/class.md`）。

```php
class MyTheme extends Nova_Theme {
    public function init() {
        Nova_Hooks::add_action('nova_init', [$this, 'cacheStats']);
    }

    public function cacheStats() {
        $db = $this->db();   // 等价于 new Nova_DB()

        // 链式查询构建器
        $q = new Nova_DB_Query();
        $recent = $q->from('blog_posts')
            ->where('is_published', 1)
            ->orderBy('published_at', 'DESC')
            ->limit(5)
            ->get();

        // 缓存查询结果
        $cache = new Nova_DB_Cache();
        $stats = $cache->get('my_theme_stats', function() use ($db) {
            return [
                'posts'     => (int)$db->get_var("SELECT COUNT(*) FROM blog_posts WHERE is_published = 1"),
                'comments'  => (int)$db->get_var("SELECT COUNT(*) FROM comments WHERE status = 'approved'"),
            ];
        }, 3600);

        $GLOBALS['myThemeStats'] = $stats;
    }
}
new MyTheme();
```

> **注意**：主题注册路由时，若需修改数据库结构，应使用 `Nova_DB_Schema` 而不是直接执行 DDL。主题通常**不应**创建新表，如需扩展存储请优先考虑用插件。

***

### 5.6 文件上传与图片处理

主题可直接使用 `Nova_Upload` 和 `Nova_Image`（详见 `docs/class.md`）。常见场景：自定义头像上传、主题截图处理。

```php
// 在主题自定义路由中处理上传
public function handleUpload($request) {
    if (empty($_FILES['avatar'])) {
        return new Nova_REST_Response(['code' => 'rest_error', 'message' => '未选择文件'], 400);
    }

    $upload = new Nova_Upload($_FILES['avatar']);
    $upload->onlyImages()
           ->maxSize(2 * 1024 * 1024)
           ->toDir($this->theme_path . '/assets/uploads')
           ->toUrl($this->theme_url . '/assets/uploads')
           ->prefix('avatar_');

    if (!$upload->validate()) {
        return new Nova_REST_Response(['code' => 'rest_error', 'message' => $upload->getError()], 400);
    }

    $result = $upload->save();
    return new Nova_REST_Response([
        'code' => 'rest_ok',
        'data' => ['url' => $result['url']],
    ]);
}
```

> **建议**：主题静态资源放在 `assets/` 下；用户上传内容应单独目录（如 `assets/uploads/`）并配置访问控制。NovaCMS 全局上传目录是 `/assets/images/`，主题可用 `toDir`/`toUrl` 指向主题内。

***

## 6. API 参考

### 6.1 Nova_Theme

主题基类，所有需要在 `theme.php` 注册路由/钩子的主题应继承此类。

**文件**: `vendor/nova-json/class/theme/class-theme.php`

**属性**

| 属性             | 类型     | 默认值     | 说明                       |
| -------------- | ------ | ------- | ------------------------ |
| `$name`        | string | ''      | 主题名称（未设置时取目录 basename）    |
| `$version`     | string | '1.0.0' | 主题版本号                    |
| `$theme_path`   | string | ''      | 主题目录绝对路径（构造函数自动检测）       |
| `$theme_url`    | string | ''      | 主题完整 URL（含协议与 host，自动检测） |
| `$layout`      | string | 'default' | 布局模板名                    |

**方法**

| 方法                  | 签名                                 | 说明                                  |
| ------------------- | ---------------------------------- | ----------------------------------- |
| `__construct()`     | `void`                             | 自动检测路径/URL、加载 `routes/` 目录、注册 `init()` 到 `nova_init` |
| `init()`            | `void`                             | 初始化入口，子类重写                           |
| `set_layout($name)` | `(string): void`                   | 设置布局名（对应 `views/{name}.php`）        |
| `render($tpl, $data)` | `(string, array): void`            | 渲染 `views/{tpl}.php`，`$data` 通过 `extract` 注入 |
| `register_route($ns, $route, $args)` | `(string, string, array): void` | 注册 REST 路由                          |
| `load_routes()`     | `void`                             | 自动加载 `routes/*.php`（构造函数调用）          |
| `db()`              | `(): Nova_DB`                      | 返回 `Nova_DB` 实例                      |
| `asset($path)`      | `(string): string`                 | 返回 `主题URL/assets/{path}` 的完整 URL    |

***

### 6.2 Nova_Hooks

钩子系统，管理 Actions 和 Filters。主题与插件共用同一实例。

**文件**: `vendor/nova-json/class/system/class-hooks.php`

**静态方法**

| 方法                | 签名                             | 说明    |
| ----------------- | ------------------------------ | ----- |
| `add_action()`    | `(tag, callback, priority=10)` | 注册动作  |
| `do_action()`     | `(tag, ...args)`               | 执行动作  |
| `remove_action()` | `(tag, callback, priority=10)` | 移除动作  |
| `has_action()`    | `(tag, callback=null): bool`   | 检查动作  |
| `add_filter()`    | `(tag, callback, priority=10)` | 注册过滤器 |
| `apply_filters()` | `(tag, value, ...args): mixed` | 执行过滤器 |
| `remove_filter()` | `(tag, callback, priority=10)` | 移除过滤器 |
| `has_filter()`    | `(tag, callback=null): bool`   | 检查过滤器 |

***

### 6.3 register_rest_route

全局函数，注册 REST 路由（与 `Nova_Theme::register_route` 等价）。

**文件**: `vendor/nova-json/class/rest/class-server.php`

```php
register_rest_route(
    string $namespace,  // 命名空间，如 'v1'
    string $route,      // 路由路径，如 '/my-theme/data'，支持 (?P<id>\d+)
    array  $args,       // 路由配置
    bool   $override = false
);
```

**$args 参数说明**

| 参数                    | 类型           | 必填 | 说明                            |
| --------------------- | ------------ | -- | ----------------------------- |
| `methods`             | string/array | 是  | HTTP 方法 (GET/POST/PUT/DELETE) |
| `callback`            | callable     | 是  | 请求处理回调                        |
| `permission_callback` | callable     | 否  | 权限验证回调                        |
| `args`                | array        | 否  | 参数验证规则                        |

完整用法见 `docs/plugin-dev.md` 第 4.3 节。

***

### 6.4 Nova_DB

数据库操作封装。

**文件**: `vendor/nova-json/class/database/class-db.php`

**核心方法**

| 方法                           | 说明        |
| ---------------------------- | --------- |
| `get_var(sql, params)`       | 获取单行单列值   |
| `get_row(sql, params)`       | 获取单行关联数组  |
| `get_results(sql, params)`   | 获取多行数组    |
| `insert(table, data)`        | 插入并返回自增ID |
| `update(table, data, where)` | 更新，返回行数   |
| `delete(table, where)`       | 删除，返回行数   |
| `begin()`                    | 开启事务      |
| `commit()`                   | 提交事务      |
| `rollback()`                 | 回滚事务      |
| `get_pdo()`                  | 获取原生 PDO  |

配套类：`Nova_DB_Query`（链式查询）、`Nova_DB_Schema`（表结构管理）、`Nova_DB_Cache`（查询缓存）、`Nova_DB_Migration`（迁移）。详见 `docs/class.md`。

***

### 6.5 Nova_API

内部 API 调用，零网络开销。直接调用 `Nova_REST_Server` 已注册的回调。

**文件**: `vendor/nova-json/class/system/class-api.php`

```php
// GET 请求
$result = Nova_API::get('/v1/posts', ['per_page' => 5]);

// POST 请求
$result = Nova_API::post('/v1/statuses/guestbook', [
    'nickname' => 'Test',
    'content'  => 'Hello',
]);

// PUT 请求
$result = Nova_API::put('/v1/statuses/gallery/albums/1', ['title' => '新标题']);

// DELETE 请求
$result = Nova_API::delete('/v1/statuses/shuoshuo/5');
```

返回值是数组或 `Nova_REST_Response`。若端点返回 `Nova_REST_Response`，其 `get_data()` 即为业务数据。

***

### 6.6 Nova_Proxy

代理请求类（公网 + 内部）。仅供主题/插件在 PHP 层调用，**不暴露为 HTTP 端点**。

**文件**: `vendor/nova-json/class/rest/class-proxy.php`

调用来源校验通过 `debug_backtrace` 实现，只有调用栈中存在来自 `nova-plugins/` 或 `nova-themes/` 目录的帧才放行，否则抛 `RuntimeException`。内置 SSRF 防护：仅允许公网 http/https、禁止内网/环回地址、DNS 固定解析防 Rebinding、超时与响应体大小限制。

**静态方法**

| 方法           | 签名                                                             | 说明                  |
| ------------ | -------------------------------------------------------------- | ------------------- |
| `request()`  | `(url, method='GET', options=[]): array/Response`              | 公网代理请求外部 URL        |
| `internal()` | `(routeOrUrl, method='GET', params=[]): array/Response/string` | 内部代理调度本地 API（零网络开销） |

**request 选项**

| 键         | 类型    | 默认值    | 说明                |
| --------- | ----- | ------ | ----------------- |
| `headers` | array | `[]`   | 自定义请求头            |
| `body`    | mixed | `null` | 请求体（string/array） |
| `timeout` | int   | `10`   | 超时秒数（1-30）        |

**示例**

```php
// 公网代理 — 请求外部 API
$resp = Nova_Proxy::request('https://api.github.com/repos/NovaCMS/NovaCMS', 'GET', [
    'headers' => ['Accept' => 'application/vnd.github+json'],
    'timeout' => 5,
]);
$stars = $resp['data']['body']['stargazers_count'] ?? 0;

// 内部代理 — 调度本地 API（零网络开销）
$data = Nova_Proxy::internal('/v1/posts', 'GET', ['per_page' => 5]);

// 内部代理 — 也可传完整 URL，自动解析为本地请求
$data = Nova_Proxy::internal('https://你的域名/nova-json/v1/posts', 'GET');

// 内部代理 — POST 调度
$result = Nova_Proxy::internal('/v1/statuses/guestbook', 'POST', [
    'nickname' => 'Test',
    'content'  => 'Hello',
]);
```

> **注意**: 在 `nova-themes/` 目录外的代码（如 `routes/` 路由文件、根目录脚本）调用 `Nova_Proxy` 会抛 `RuntimeException`。

***

## 7. 案例和最佳实践

### 7.1 示例一：读取站点图标与配置

主题需要从 `$config` 数组读取站点配置（favicon、网站名、社交链接等），并安全地输出到 HTML。

```php
<?php
// partials/header.php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

$siteName = trim((string)($config['website_name'] ?? 'NovaCMS')) ?: 'NovaCMS';
$siteDescription = trim(strip_tags((string)($config['description'] ?? '')));
if ($siteDescription === '') {
    $siteDescription = $siteName . '，记录知识、灵感与持续成长。';
}

// 安全读取 favicon（仅允许站内相对路径或 http/https URL）
$faviconUrl = trim((string)($config['favicon'] ?? ''));
$faviconIsSafe = $faviconUrl !== ''
    && !preg_match('/[\x00-\x1F\x7F\\\\]/', $faviconUrl)
    && (
        (str_starts_with($faviconUrl, '/') && !str_starts_with($faviconUrl, '//'))
        || (filter_var($faviconUrl, FILTER_VALIDATE_URL)
            && in_array(strtolower((string)parse_url($faviconUrl, PHP_URL_SCHEME)), ['http', 'https'], true))
    );
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? $siteName) ?></title>
    <meta name="description" content="<?= e($siteDescription) ?>">
    <?php if ($faviconIsSafe): ?>
        <link rel="icon" href="<?= e($faviconUrl) ?>">
        <link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(NOVA_THEME_URL) ?>/assets/css/style.css">
</head>
<body>
```

#### 常用 `$config` 字段

| 字段                  | 说明                  |
| ------------------- | ------------------- |
| `website_name`      | 网站名称                |
| `website_author`    | 网站作者                |
| `description`       | 网站描述                |
| `robot_description` | 用于 SEO 的描述          |
| `favicon`           | 网站图标 URL            |
| `logo`              | 网站 Logo URL         |
| `contact_email`     | 联系邮箱                |
| `contact_qq`        | 联系 QQ               |
| `social_wechat`     | 微信号                 |
| `social_github`     | GitHub 用户名/URL      |
| `social_douyin`     | 抖音号                 |
| `social_bilibili`   | B 站号                |
| `social_x`          | X (Twitter)         |
| `social_discord`    | Discord             |
| `social_youtube`    | YouTube             |
| `website_start_time` | 网站开办时间              |
| `footer_extra`      | 页脚附加信息（HTML）        |
| `icp_record`        | ICP 备案号             |
| `public_security_record` | 公安备案号               |
| `active_theme`      | 当前启用的主题 slug（不推荐在模板读取，用 `$activeTheme`） |
| `active_plugins`    | 已启用插件 id 数组（JSON 字符串，由 `admin/plugins.php` 运行时 `ALTER TABLE` 动态添加列，不在初始表结构中） |

> **注意**：
> - 完整字段列表见 `blog.sql` 中 `website_config` 表结构，或访问 `GET /nova-json/v1/statuses/settings` 获取已脱敏的公开字段。
> - `$config` 是 `SELECT * FROM website_config` 的完整结果，包含 SMTP 密码、支付密钥等敏感字段，**切勿输出到 HTML 或返回给前端**。
> - `default/partials/header.php` 中有 `bing_verification` 字段的兼容检查（`!empty($config['bing_verification'])`），但该字段**不在 `website_config` 表结构中**，是预留的占位逻辑，读取始终为空。

***

### 7.2 示例二：自定义页面模板分支

主题声明 `page_templates` 后，后台「页面」编辑器会出现下拉选项。`page.php` 根据用户选择的模板切换不同布局。

#### theme.json

```json
{
    "page_templates": {
        "default": "标准页面",
        "wide": "宽幅阅读",
        "landing": "落地页"
    }
}
```

#### page.php（白名单 + 子模板）

```php
<?php
// page.php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

$pageTitle = $contentPage['title'] ?? '页面';
$pageKey = 'page';
$pageDescription = !empty($contentPage['summary']) ? (string)$contentPage['summary'] : '站点独立页面。';

// 1. 白名单校验模板
$allowedTemplates = ['default', 'wide', 'landing'];
$pageTemplate = in_array($contentPage['template'] ?? '', $allowedTemplates, true)
    ? $contentPage['template']
    : 'default';

// 2. 加载子模板（page-{template}.php）
$subTemplate = $themePath . '/page-' . $pageTemplate . '.php';
if (!is_file($subTemplate)) {
    $subTemplate = $themePath . '/page-default.php';
}

// 3. 引入 header / navbar
include $themePath . '/partials/header.php';
include $themePath . '/partials/navbar.php';
?>
<main id="main-content" class="site-main content-page content-page-<?= e($pageTemplate) ?>">
    <div class="site-container">
        <nav class="site-breadcrumb"><a href="/">首页</a> › <span><?= e($contentPage['title']) ?></span></nav>
        <?php include $subTemplate; ?>
    </div>
</main>
<?php include $themePath . '/partials/footer.php';
```

#### page-wide.php（宽幅布局子模板）

```php
<article class="content-page-card content-page-card-wide">
    <h1><?= e($contentPage['title']) ?></h1>
    <div class="article-content"><?= nl2br(e($contentPage['content'])) ?></div>
</article>
```

#### page-landing.php（落地页子模板）

```php
<section class="content-page-landing">
    <h1 class="landing-title"><?= e($contentPage['title']) ?></h1>
    <?php if (!empty($contentPage['summary'])): ?>
        <p class="landing-subtitle"><?= e($contentPage['summary']) ?></p>
    <?php endif; ?>
    <div class="article-content"><?= nl2br(e($contentPage['content'])) ?></div>
</section>
```

> **要点**：模板字段必须白名单校验，不能直接拼路径 `include $themePath . '/page-' . $contentPage['template'] . '.php'`，否则可能被路径遍历攻击。

***

### 7.3 示例三：主题注册独立 REST 端点

主题注册一个返回主题元信息的 REST 端点，并支持缓存。

```php
<?php
// vendor/nova-themes/my-theme/themes/theme.php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class MyTheme extends Nova_Theme {

    protected $version = '1.0.0';

    public function init() {
        // 注册公开端点
        $this->register_route('v1', '/my-theme/info', [
            'methods'  => 'GET',
            'callback' => [$this, 'getInfo'],
            'permission_callback' => function() { return true; },  // 公开访问
        ]);

        // 注册需管理员权限的端点
        $this->register_route('v1', '/my-theme/stats', [
            'methods'  => 'GET',
            'callback' => [$this, 'getStats'],
            'permission_callback' => function() {
                return !empty($_SESSION['admin_id']);
            },
        ]);
    }

    public function getInfo($request) {
        $cache = new Nova_DB_Cache();
        $data = $cache->get('my_theme_info_' . $this->version, function() {
            return [
                'name'        => $this->name,
                'version'     => $this->version,
                'theme_path'  => basename($this->theme_path),
                'has_screenshot' => is_file($this->theme_path . '/screenshot.png'),
            ];
        }, 3600);

        return new Nova_REST_Response([
            'code' => 'rest_ok',
            'data' => $data,
        ]);
    }

    public function getStats($request) {
        $db = $this->db();
        return new Nova_REST_Response([
            'code' => 'rest_ok',
            'data' => [
                'posts'    => (int)$db->get_var("SELECT COUNT(*) FROM blog_posts WHERE is_published = 1"),
                'comments' => (int)$db->get_var("SELECT COUNT(*) FROM comments WHERE status = 'approved'"),
            ],
        ]);
    }
}

new MyTheme();
```

访问 `GET /nova-json/v1/my-theme/info`，返回：

```json
{
    "code": "rest_ok",
    "data": {
        "name": "my-theme",
        "version": "1.0.0",
        "theme_path": "my-theme",
        "has_screenshot": true
    }
}
```

***

## 8. 附录

### 8.1 访问控制

NovaCMS 使用 `NOVA_BOOTSTRAP` 常量防止 PHP 文件被直接 HTTP 访问。

#### 原理

- `index.php`（前台）和 `admin-bootstrap.php`（后台）在启动时定义 `NOVA_BOOTSTRAP`
- 所有主题 PHP 文件（模板、partials、`theme.php`、`routes/` 中的路由文件）应在开头检查此常量
- 通过 `require`/`include` 加载的文件继承常量（进程内操作）
- 直接 HTTP 访问是新进程，常量不存在 → `exit('禁止直接访问')`

#### 主题文件必须添加的检查

**模板文件**（`index.php`、`404.php`、`page.php` 等）：

```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
```

**partials 片段**（`partials/header.php` 等）：

```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
```

**theme.php 入口**：

```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
```

**主题 REST 路由文件**（`routes/*.php`）：

```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
```

#### 额外防护

- `vendor/nova-themes/.htaccess`（若 Apache）应阻止直接 HTTP 访问（项目级配置）
- `NOVA_BOOTSTRAP` 是 PHP 级别的次级防线，对 Nginx 等环境也有效

#### 常量说明

| 常量               | 定义位置                                | 用途                                     |
| ---------------- | ----------------------------------- | -------------------------------------- |
| `NOVA_BOOTSTRAP` | `index.php` / `admin-bootstrap.php` | 访问控制 — 表示请求经过框架                        |
| `NOVA_API`       | `init.php`                          | 业务逻辑 — 区分是否为 API 请求（仅 `/nova-json` 路径） |
| `NOVA_THEME_URL` | `index.php`                         | 当前主题 URL 前缀                             |
| `NOVA_THEME_PREVIEW` | `index.php`                        | 主题预览模式                                 |

***

### 8.2 开发检查清单

- [ ] 创建主题目录 `vendor/nova-themes/{slug}/`，slug 满足 `novaThemeIsValidSlug`
- [ ] 根目录只放 `theme.json`、`LICENSE`、`logo.png`、`screenshot.png` 四个文件
- [ ] 所有 PHP/JS/CSS 等文件放入 `themes/` 子目录
- [ ] 创建 `theme.json`，`slug` 与目录名一致
- [ ] 提供 `themes/index.php`、`themes/404.php` 两个必需模板
- [ ] 提供 `logo.png`（小图标，建议 256×256，支持 png/jpg/webp/svg/ico）
- [ ] 提供 `screenshot.png`（大截图，建议 1200×675，16:9）
- [ ] 提供 `LICENSE` 许可证文件，并在 `theme.json` 中通过 `license` 字段声明 SPDX 标识
- [ ] 所有 PHP 文件首行添加 `defined('NOVA_BOOTSTRAP') or exit('禁止直接访问')`
- [ ] 所有输出到 HTML 的动态内容使用 `e()` / `htmlspecialchars` 转义
- [ ] 模板字段（如 `page_templates`）在 `page.php` 中做白名单校验，不直接拼路径
- [ ] `favicon` / Logo 等用户输入 URL 在输出前做协议与字符安全校验
- [ ] CSS/JS 资源引用 `NOVA_THEME_URL`，并加 `filemtime` 版本号避免缓存
- [ ] 静态资源放在 `assets/` 子目录，遵守项目 CDN 约定
- [ ] 若需注册 REST 路由或钩子，创建 `theme.php` 继承 `Nova_Theme`
- [ ] 若需数据库查询，使用 `Nova_DB` 参数绑定查询防止 SQL 注入
- [ ] 若声明 `page_templates`，提供对应的 `page-{slug}.php` 或在 `page.php` 内做分支
- [ ] 若声明 `parent`，确认父主题已存在且模板查找逻辑符合预期
- [ ] 设置 `min_nova_version` 声明最低系统版本
- [ ] 提供 `LICENSE` 许可证文件

***

### 8.3 版本兼容性

| NovaCMS 版本 | 主题 API 版本 | 变更说明                                                                                     |
| ---------- | --------- | --------------------------------------------------------------------------------------- |
| 1.0+       | 1.0       | 初始主题机制：`theme.json` + 模板文件 + `loadTheme()` 路由分发                                          |
| 1.1+       | 1.1       | 引入 `parent` 父主题字段；`page_templates` 自动规范化（slug 校验 + 自动补 default）；后台主题校验强化（路径安全、必需模板检查） |
| 1.2+       | 1.2       | 主题可选创建 `theme.php` 继承 `Nova_Theme` 注册 REST 路由；新增 4 个前台注入钩子 `nova_head` / `nova_body_start` / `nova_navbar_end` / `nova_footer`；新增 `nova_inject` 过滤器支持任意位置注入；主题预览（带签名 token） |
| 1.3+       | 1.3       | `theme.json` 新增 `logo`（小图标）与 `license`（许可证标识）字段；后台主题卡片在标题左侧显示 logo、在 meta 区显示许可证；主题目录规范化：根目录只放 `theme.json` / `LICENSE` / `logo.png` / `screenshot.png`，所有 PHP/JS/CSS 等文件移入 `themes/` 子目录 |

***

> 最后更新：2026-08-22
