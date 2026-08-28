# 插件开发

***

NovaCMS 采用可插拔架构，插件与核心解耦：安装即放入目录、卸载即删除目录，通过 `plugin.json` 声明元数据，通过钩子、REST 路由、页面路由、后台页面四种方式扩展系统能力。本文档基于系统实际实现（`vendor/nova-json/` 框架 + `vendor/nova-plugins/` 内置插件）描述如何开发 NovaCMS 插件。

***

## 目录

- [📄️ 介绍](#1-介绍)
- [📄️ 准备工作](#2-准备工作)
- [📄️ 入门：Hello World](#3-入门hello-world)
- [🗃 基础](#4-基础)
  - [插件基类与生命周期](#41-插件基类与生命周期)
  - [钩子系统：Actions & Filters](#42-钩子系统actions--filters)
  - [前台注入钩子与 nova_inject](#43-前台注入钩子与-nova_inject)
  - [注册 REST API 路由](#44-注册-rest-api-路由)
  - [注册自定义页面路由（page_routes）](#45-注册自定义页面路由page_routes)
  - [插件配置（config.json 声明式表单）](#46-插件配置configjson-声明式表单)
  - [后台管理页面与菜单](#47-后台管理页面与菜单)
  - [数据库操作](#48-数据库操作)
  - [文件上传与图片处理](#49-文件上传与图片处理)
  - [定时任务（Nova_Cron）](#410-定时任务nova_cron)
- [🗃 API 参考](#5-api-参考)
- [🗃 案例与最佳实践](#6-案例与最佳实践)
- [🗃 附录](#7-附录)

***

## 1. 介绍

### 什么是 NovaCMS 插件？

插件是放置在 `vendor/nova-plugins/{slug}/` 目录下的独立 PHP 代码包。通过插件，开发者可以在不修改核心代码的前提下：

- **注册 REST API 端点** — 通过 `plugin/routes/` 或 `register_route()`，挂到 `/nova-json/` 下
- **挂载系统钩子** — `nova_init` / `nova_head` / `nova_footer` / `nova_inject` 等
- **提供前台虚拟页面** — 通过 `plugin.json` 的 `page_routes` 声明路径映射
- **提供后台管理页面** — 放置 `plugin/admin/index.php`，由系统通用渲染器加载，侧边栏菜单自动注册
- **提供声明式配置界面** — 通过 `config.json` 自动生成后台设置表单
- **注册定时任务** — 通过 `Nova_Cron::register()`

### 插件架构概览

```
浏览器请求
   │
   ├─ /nova-json/* ──────────────► vendor/nova-json/init.php（API 侧加载）
   │                                  1. 加载全部核心类（class/*.php）
   │                                  2. Nova_Plugin_Registry::scan_all() 扫描插件
   │                                  3. 启用插件 → require 入口文件
   │                                     禁用插件 → 仅加载其 routes/（注册路由供拦截）
   │                                  4. do_action('nova_init') → 插件 init()
   │                                  5. Nova_REST_Server::serve_request() 分发
   │
   └─ 其他路径 ──────────────────► index.php（前台侧加载）
                                      1. 插件 page_routes 匹配（先于一切渲染）
                                      2. 加载启用插件入口 → do_action('nova_init')
                                      3. 输出缓冲 + shutdown 回调：
                                         nova_head / nova_body_start / nova_navbar_end /
                                         nova_footer / nova_inject 注入 HTML
                                      4. 主题模板渲染
```

### 核心概念

| 概念 | 说明 |
| --- | --- |
| **slug** | 插件目录名，`vendor/nova-plugins/{slug}/`，全局唯一 |
| **id** | 插件唯一标识，在 `plugin.json` 中手动填写（必须英文），排在 `name` 之前；启用/禁用状态以此为准 |
| **入口文件** | `plugin/plugin.php`（默认，可经 `entry` 字段修改），入口中实例化 `Nova_Plugin` 子类 |
| **注册表** | `Nova_Plugin_Registry`，被 `init.php`、`index.php`、`admin/plugins.php`、`admin/plugin-page.php` 共用 |
| **钩子** | 事件（Action）与过滤器（Filter），由 `Nova_Hooks` 管理，写法类似 WordPress |
| **页面路由** | `plugin.json` 的 `page_routes` 字段，声明「路径 → PHP 文件」映射，由前台 `index.php` 匹配 |

### 内置插件（可作为开发参考）

| 插件 | id | 类型 | 参考价值 |
| --- | --- | --- | --- |
| Backup | `backup` | 后台管理型 | 后台页面 `plugin/admin/index.php` + 核心类拆分 + 备份存储 |
| Comments | `comments` | 前台增强型 | QQ 头像拉取 + 页面路由 |
| Cron Manager | `cron-manager` | 后台配置型 | `config_path` 配置重定向 + `detail_tab` 自定义详情页 |
| Netease Player | `netease-player` | 前台注入型 | `nova_head`/`nova_footer` 注入 + `nova_inject` + config.json 表单 |
| RSS | `rss` | 页面路由型 | `page_routes` 输出 XML + config.json 读取 |
| Sitemap | `sitemap` | 页面路由型 | `page_routes` 输出 sitemap.xml |

***

## 2. 准备工作

### 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 7.4+（推荐 8.0+） |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ |
| 扩展 | PDO、GD（图片处理）、JSON、mbstring |
| Web 服务器 | Apache / Nginx（需配置伪静态，见根目录 `伪静态.txt`） |

### 目录结构

插件放置在 `vendor/nova-plugins/` 目录，每个插件一个独立文件夹。**自 NovaCMS 1.1 起，插件代码统一放在 `plugin/` 子目录**，元数据通过根目录的 `plugin.json` 声明：

```
vendor/nova-plugins/
└── my-plugin/                  # 插件目录（slug，唯一）
    ├── plugin.json             # 元数据（含 id，必须为英文）
    ├── config.json             # 声明式配置表单（可选，详见 4.6）
    ├── LICENSE                 # 许可证文件
    ├── plugin/                 # 插件代码目录（所有 PHP 代码）
    │   ├── plugin.php          # 入口文件（默认 entry）
    │   ├── class-my-plugin.php # 插件主类（推荐）
    │   ├── routes/             # REST 路由文件目录（实例化时自动加载）
    │   │   └── api.php
    │   └── admin/              # 后台管理页面（存在 index.php 即自动注册侧边栏菜单）
    │       ├── index.php       # 管理页面（由 admin/plugin-page.php 渲染）
    │       └── detail.php      # 插件详情页自定义 Tab（需配合 detail_tab 字段）
    ├── assets/                 # 静态资源目录（可选）
    │   ├── css/
    │   └── js/
    └── data/                   # 运行时数据目录（可选，如备份、缓存）
```

> 所有目录均可选，最小插件只需 `plugin.json` + `plugin/plugin.php`。

### plugin.json 格式

```json
{
    "id": "my-plugin",
    "name": "My Plugin",
    "uri": "https://example.com/my-plugin",
    "description": "我的第一个 NovaCMS 插件",
    "version": "1.0.0",
    "author": "你的名字",
    "author_uri": "https://example.com",
    "entry": "plugin/plugin.php",
    "min_nova_version": "1.1",
    "page_routes": {
        "/my-page": "plugin/pages/page.php",
        "/my-page/{id}": "plugin/pages/detail.php"
    },
    "sidebar": true,
    "config_path": "",
    "detail_tab": "数据统计"
}
```

#### 字段说明（Nova_Plugin_Registry 实际解析的全部字段）

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `id` | string | **是** | 插件唯一标识。**必须为英文**：以字母开头，仅含字母/数字/下划线/连字符；不可与其他插件重复；**排在 `name` 之前**。启用/禁用以此 id 为准。若未填写，系统自动以目录名（slug）回退并写回文件 |
| `name` | string | 是 | 插件显示名称（可中文），留空时以 slug 显示 |
| `version` | string | 否 | 版本号，默认 `1.0.0` |
| `description` | string | 否 | 插件描述，显示在后台插件列表 |
| `author` | string | 否 | 作者名 |
| `author_uri` | string | 否 | 作者主页 |
| `uri` | string | 否 | 插件主页 |
| `entry` | string | 否 | 入口文件相对路径，默认 `plugin/plugin.php` |
| `min_nova_version` | string | 否 | 最低 NovaCMS 版本要求 |
| `page_routes` | object | 否 | 前台页面路由映射：`"路径": "PHP 文件相对插件根目录的路径"`，支持 `{param}` 占位符（详见 4.5） |
| `sidebar` | bool | 否 | 是否在后台侧边栏注册菜单，默认 `true`；设 `false` 可隐藏（详见 4.7） |
| `config_path` | string | 否 | 配置文件重定向路径（相对插件根目录），用于把 config.json 放到插件目录之外（详见 4.6） |
| `detail_tab` | string | 否 | 插件详情页自定义 Tab 标题，需配合 `plugin/admin/detail.php`（详见 4.7） |

> ⚠️ `id` 重复的两个插件都会被扫描，但第二个会被标记 `duplicate`，系统加载时跳过，后台插件管理页提示删除。

***

## 3. 入门：Hello World

下面创建一个最小可运行插件：提供一条 REST 接口 + 一个前台虚拟页面。

### 3.1 创建目录与 plugin.json

```
vendor/nova-plugins/hello/
├── plugin.json
└── plugin/
    └── plugin.php
```

**plugin.json**

```json
{
    "id": "hello",
    "name": "Hello World",
    "description": "最小示例插件",
    "version": "1.0.0",
    "author": "你的名字",
    "entry": "plugin/plugin.php",
    "page_routes": {
        "/hello": "plugin/hello-page.php"
    }
}
```

### 3.2 创建入口文件

**plugin/plugin.php**

```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Hello_Plugin extends Nova_Plugin {

    protected $name    = 'hello';
    protected $version = '1.0.0';

    public function init() {
        // 注册一条 REST 路由：GET /nova-json/v1/hello
        $this->register_route('v1', '/hello', [
            'methods'  => 'GET',
            'callback' => function () {
                return ['message' => 'Hello, NovaCMS!'];
            },
        ]);
    }
}

new Hello_Plugin();
```

### 3.3 创建页面路由文件

**plugin/hello-page.php**（由 `page_routes` 的 `/hello` 指向）

```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

header('Content-Type: text/html; charset=utf-8');
echo '<h1>Hello, NovaCMS!</h1><p>这是插件提供的前台页面。</p>';
```

### 3.4 验证

1. 后台「插件」页面应出现 Hello World 插件，开关为启用（`active_plugins` 为 NULL 时全部启用）
2. 访问 `http://your-domain/hello` → 输出 Hello 页面
3. 访问 `http://your-domain/nova-json/v1/hello` → 返回 JSON
4. 在后台关闭该插件 → `/hello` 返回 403「此插件已禁用」，API 返回禁用提示

***

## 4. 基础

### 4.1 插件基类与生命周期

#### Nova_Plugin 类

所有插件主类应继承 `Nova_Plugin`（定义于 `vendor/nova-json/class/plugin/class-plugin.php`）：

```php
class My_Plugin extends Nova_Plugin {
    protected $name    = 'my-plugin';   // 插件名（留空则取类文件所在目录名）
    protected $version = '1.0.0';

    public function init() { /* 注册路由、挂载钩子 */ }
}
new My_Plugin();   // 入口文件末尾实例化
```

**构造函数自动完成：**

1. 通过反射推导 `plugin_path`（类文件所在目录）、`plugin_url`、`plugin_slug`（插件根目录名）
2. 通过 `Nova_Plugin_Registry::scan_all()` 反查 `plugin_id`
3. 设置 REST 插件上下文 → **自动加载 `plugin/routes/*.php`** → 清除上下文（保证路由文件里的 `register_rest_route()` 能归属到本插件，禁用时可拦截）
4. 若插件**已启用**且存在 `init()` 方法，注册到 `nova_init` 钩子（禁用插件不注册，避免副作用）

#### 生命周期（两条加载路径）

| 时机 | API 请求（`/nova-json/*`） | 前台请求（其他路径） |
| --- | --- | --- |
| 加载者 | `vendor/nova-json/init.php` | `index.php` |
| 启用插件 | require 入口文件（实例化 → 自动加载 routes） | require 入口文件 |
| 禁用插件 | **不加载入口**，仅加载其 `routes/`（路由仍注册，访问时被拦截返回禁用提示） | 完全不加载 |
| 初始化钩子 | `do_action('nova_init')` → 执行各插件 `init()` | 同左 |
| 后续 | REST 分发输出 JSON | 页面路由 → 主题渲染 → shutdown 注入钩子输出 |

> 💡 **禁用插件在 API 侧仍加载 routes 的原因**：让路由注册后能被 `Nova_REST_Server::dispatch()` 识别并返回「此插件已禁用」的明确提示，而不是含糊的 404。

#### 可用属性（protected）

| 属性 | 类型 | 说明 |
| --- | --- | --- |
| `$name` | string | 插件名，用于日志前缀 |
| `$version` | string | 版本号 |
| `$plugin_path` | string | 插件**代码目录**绝对路径（`plugin/`），反射自动推导 |
| `$plugin_url` | string | 插件代码目录的完整 URL（自动按 http/https 推导） |
| `$plugin_id` | string | 插件 id（与 plugin.json 一致） |
| `$plugin_slug` | string | 插件目录名 |

#### 可用方法

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `register_route()` | `protected register_route($namespace, $route, $args)` | 注册 REST 路由（快捷方式），自动附加 `plugin_id`/`plugin_slug` 便于禁用拦截 |
| `load_routes()` | `protected load_routes()` | 自动加载 `plugin_path/routes/*.php`（构造时已调用，一般无需手动） |
| `db()` | `protected db(): Nova_DB` | 获取数据库实例 |
| `log()` | `protected log($message, $level = 'INFO')` | 写入插件日志（error_log，前缀 `[插件名][级别]`） |
| `get_plugin_id()` | `public get_plugin_id(): string` | 获取插件 id |
| `get_plugin_slug()` | `public get_plugin_slug(): string` | 获取插件 slug |

#### 插件启用/禁用机制

- 启用列表存于数据库 `website_config.active_plugins` 字段（JSON 数组）
- **NULL / 空 = 全部启用**（向后兼容：未使用过后台开关时所有插件可用）
- 由 `Nova_Plugin_Registry::get_active_plugin_ids()` / `is_plugin_active($pluginId)` 读取
- 后台插件管理页（`admin/plugins.php`）通过 Bootstrap 滑动开关 AJAX 更新，切换后侧边栏菜单自动刷新
- 状态变更后系统调用 `Nova_Plugin_Registry::clear_cache()` 清除缓存

***

### 4.2 钩子系统：Actions & Filters

钩子由 `Nova_Hooks`（`vendor/nova-json/class/system/class-hooks.php`）管理，写法类似 WordPress。

#### Actions（动作钩子）— 执行副作用

```php
// 注册：add_action(钩子名, 回调, 优先级)
Nova_Hooks::add_action('nova_init', [$this, 'init']);
Nova_Hooks::add_action('nova_footer', 'my_footer_fn', 20);

// 触发：do_action(钩子名, ...参数)
Nova_Hooks::do_action('nova_footer');
```

#### Filters（过滤器钩子）— 修改并返回值

```php
// 注册
Nova_Hooks::add_filter('nova_inject', function (array $items) {
    $items[] = ['selector' => 'main', 'html' => '<div>...</div>'];
    return $items;
});

// 应用：$value 依次经过所有回调，返回最终值
$items = Nova_Hooks::apply_filters('nova_inject', []);
```

#### 完整 API

| 方法 | 说明 |
| --- | --- |
| `add_action($tag, $callback, $priority = 10)` | 注册动作，priority 越小越先执行 |
| `do_action($tag, ...$args)` | 执行动作 |
| `remove_action($tag, $callback, $priority = 10)` | 移除动作 |
| `has_action($tag, $callback = null)` | 是否已注册（callback 为 null 时检查任意回调） |
| `add_filter($tag, $callback, $priority = 10)` | 注册过滤器 |
| `apply_filters($tag, $value, ...$args)` | 应用过滤器，返回修改后的值 |
| `remove_filter($tag, $callback, $priority = 10)` | 移除过滤器 |
| `has_filter($tag, $callback = null)` | 是否已注册 |

#### 系统内置钩子清单

| 钩子 | 类型 | 触发时机 | 常见用途 |
| --- | --- | --- | --- |
| `nova_init` | Action | 插件加载完毕后（API 与前台各触发一次） | 插件初始化：注册路由、挂其他钩子 |
| `rest_api_init` | Action | REST Server 启动时，回调收到 `$server` 实例 | 低层路由注册（一般用 `register_rest_route()` 即可） |
| `nova_head` | Action | 页面 `</head>` 前（前台 shutdown 注入） | 注入 CSS link / meta / script |
| `nova_body_start` | Action | `<body>` 标签之后 | 注入首屏加载动画等 |
| `nova_navbar_end` | Action | 首个 `</nav>` 之后 | 注入导航栏下方横幅 |
| `nova_footer` | Action | `</body>` 前 | 注入统计代码、播放器 DOM |
| `nova_inject` | Filter | shutdown 注入阶段 | 任意位置 DOM 注入（见下节） |

> 前台注入类钩子（`nova_head` 等）基于输出缓冲：`index.php` 在 `ob_start()` 后渲染主题，脚本结束时统一将钩子输出用正则插入 HTML 锚点。因此**回调中直接 `echo` 即可**，无需返回值。

***

### 4.3 前台注入钩子与 nova_inject

#### 固定锚点注入

```php
public function init() {
    Nova_Hooks::add_action('nova_head',   [$this, 'injectCss']);
    Nova_Hooks::add_action('nova_footer', [$this, 'injectWidget']);
}

public function injectCss() {
    echo '<link rel="stylesheet" href="' . e($this->plugin_url) . '/assets/css/widget.css">' . "\n";
}

public function injectWidget() {
    echo '<div id="my-widget">...</div>' . "\n";
}
```

#### 任意位置注入（nova_inject 过滤器）

无需修改任何主题文件，通过 CSS 选择器把 HTML 注入到页面任意位置。系统在 shutdown 阶段收集所有注入项，生成一段 JS（含重试机制，适配异步渲染的 DOM）插到 `</body>` 前：

```php
Nova_Hooks::add_filter('nova_inject', function (array $items) {
    $items[] = [
        'selector' => 'article.article-shell', // CSS 选择器（注入到第一个匹配元素）
        'position' => 'append',                // before | after | prepend | append
        'html'     => '<div class="my-box">点赞</div>',
        'retry'    => 5,                       // 目标不存在时重试次数（默认 3，上限 10）
        'delay'    => 300,                     // 重试间隔毫秒（默认 200，上限 5000）
    ];
    return $items;
});
```

**注入项规范：**

| 键 | 必填 | 说明 |
| --- | --- | --- |
| `selector` | 是 | 合法 CSS 选择器，注入到**第一个**匹配元素 |
| `html` | 是 | 注入的 HTML 片段（内联 `<script>` 会被重新激活执行） |
| `position` | 否 | `before` / `after` / `prepend` / `append`，默认 `append` |
| `retry` | 否 | 选择器未命中时的重试次数，默认 3，范围 0–10 |
| `delay` | 否 | 重试间隔（毫秒），默认 200，范围 0–5000 |

系统会按 `selector + position + html 摘要` 去重，多个插件注入相同内容不会重复。

> 📖 真实示例：`vendor/nova-plugins/netease-player/plugin/plugin.php` 中 `injectPlayer()` 演示了「静态位置用 nova_inject 注入文章容器 / 固定位置直接 echo」的双模式写法。

***

### 4.4 注册 REST API 路由

#### 基本路由

在插件 `init()` 中使用基类快捷方法：

```php
public function init() {
    $this->register_route('v1', '/my-plugin/data', [
        'methods'  => 'GET',
        'callback' => [$this, 'get_data'],
    ]);
}

public function get_data($request) {
    return ['ok' => true, 'data' => []];
}
```

等效于在 `plugin/routes/api.php` 中写（两条路二选一）：

```php
register_rest_route('v1', '/my-plugin/data', [
    'methods'  => 'GET',
    'callback' => function ($request) {
        return ['ok' => true];
    },
]);
```

> `plugin/routes/*.php` 在插件实例化时**自动加载**（含 API 侧加载的禁用插件），加载期间 REST 上下文已指向本插件，`register_rest_route()` 注册的路由自动带上 `plugin_id`。

最终访问地址：`/nova-json/v1/my-plugin/data`（namespace `v1` 会自动成为 URL 前缀）。

#### 带 URL 参数的路由

路由中的 `{name}` 占位符会被转为命名捕获组（`[^/]+`）：

```php
$this->register_route('v1', '/my-plugin/item/(?P<id>\d+)', [
    'methods'  => 'GET',
    'callback' => function ($request) {
        $id = (int) $request->get_param('id');
        return ['id' => $id];
    },
]);
```

> 也支持简写 `'/my-plugin/item/{id}'`（系统自动转换），正则写法 `(?P<id>\d+)` 可约束格式。

#### 多请求方法

```php
$this->register_route('v1', '/my-plugin/items', [
    'methods'  => ['GET', 'POST'],
    'callback' => [$this, 'handle_items'],
]);
```

#### 权限控制

```php
$this->register_route('v1', '/my-plugin/admin', [
    'methods'             => 'POST',
    'callback'            => [$this, 'admin_action'],
    'permission_callback' => function () {
        return v1_is_admin(v1_get_current_user_id());   // 系统内置助手函数
    },
]);
```

`init.php` 提供两个全局助手：

| 函数 | 说明 |
| --- | --- |
| `v1_get_current_user_id()` | 当前登录用户 id（Session 或 Bearer Token），未登录返回 0 |
| `v1_is_admin($userId)` | 从数据库核验该用户是否为 `admin` 角色且未封禁 |

#### 获取请求参数

回调收到 `Nova_REST_Request` 对象：

```php
$method = $request->get_method();          // GET / POST ...
$id     = $request->get_param('id');        // 路由参数 + 查询参数 + JSON body 合并
$query  = $request->get_query_params();     // $_GET
$body   = $request->get_body_params();      // POST body（JSON 已解码）
$file   = $request->get_file_params();      // $_FILES
```

#### 返回值

回调返回数组即自动 JSON 输出（`Content-Type: application/json`，状态码 200）；需要错误状态时返回 `Nova_REST_Response`：

```php
return new Nova_REST_Response([
    'code'    => 'my_error',
    'message' => '参数不合法',
    'data'    => ['status' => 400],
], 400);
```

系统路由的错误惯例参考 `routes/statuses/guestbook.php` 的 `nova_guestbook_error($code, $message, $status)`。

***

### 4.5 注册自定义页面路由（page_routes）

插件可以不经过主题、直接提供前台页面（如 `/rss.xml`、`/sitemap.xml`、`/docs`）。

#### 工作原理

前台 `index.php` 在**主题渲染之前**扫描所有插件的 `page_routes`：

1. 把路由模式中的 `{param}` 转为正则捕获组并匹配当前请求路径
2. 命中后把捕获的参数写入 `$_GET`
3. 定义常量 `NOVA_PAGE_ROUTE`（路由模式）与 `NOVA_PAGE_ROUTE_PLUGIN`（插件 id）
4. `require` 映射的目标 PHP 文件并退出
5. **插件被禁用时**：返回 403 与「此插件已禁用」提示页

#### plugin.json 示例

```json
{
    "id": "docs",
    "name": "文档中心",
    "entry": "plugin/plugin.php",
    "page_routes": {
        "/docs": "plugin/docs-page.php",
        "/docs/{slug}": "plugin/docs-detail.php"
    }
}
```

访问 `/docs/php` 时，`plugin/docs-detail.php` 内可直接使用 `$_GET['slug']`（值为 `php`）。

#### 路由参数

- `{param}`：参数名须为合法 PHP 变量名（字母开头），匹配 `[^/]+`
- 参数在文件内通过 `$_GET['param']` 读取
- 同一插件的多条路由按声明顺序匹配，先声明先命中

#### 插件文件示例

```php
<?php
// plugin/docs-detail.php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

$slug = preg_replace('/[^a-z0-9_-]/i', '', $_GET['slug'] ?? '');

// 可直接使用全局函数与数据库（前台环境已加载 config/*.php）
$db   = getDB();
$stmt = $db->prepare("SELECT * FROM my_docs WHERE slug = ? LIMIT 1");
$stmt->execute([$slug]);
$doc  = $stmt->fetch();

header('Content-Type: text/html; charset=utf-8');
if (!$doc) {
    http_response_code(404);
    echo '<h1>404 文档不存在</h1>';
    exit;
}
echo '<h1>' . e($doc['title']) . '</h1>';
echo $doc['content'];
```

#### 可用常量

| 常量 | 说明 |
| --- | --- |
| `NOVA_BOOTSTRAP` | 框架启动标记（必须校验，防直接访问） |
| `NOVA_PAGE_ROUTE` | 当前命中的路由模式（如 `/docs/{slug}`），用于分支判断 |
| `NOVA_PAGE_ROUTE_PLUGIN` | 提供本页面的插件 id |
| `NOVA_API` | API 请求时定义（页面路由场景为未定义） |

#### 特点

- 优先级**高于**主题模板路由与 `.php` 后缀 301 重定向（在 `index.php` 最先匹配，仅 `/nova-json/*` API 更优先）
- 路由路径**建议不带 `.php` 后缀**：带后缀的物理文件会被服务器直接执行（绕过 index.php），必须依赖伪静态回退才能进入路由层；干净路径（如 `/docs`）始终可靠
- 页面文件必须以 `defined('NOVA_BOOTSTRAP') or exit` 开头
- 运行环境已包含 `config/functions.php` 等公共函数（`e()`、`getDB()`、`generateCSRFToken()` 可直接用）

> 📖 真实示例：`vendor/nova-plugins/rss/plugin.json` 声明 `"/rss.xml": "plugin/rss.php"`，输出 RSS XML；`vendor/nova-plugins/sitemap/` 同理。

***

### 4.6 插件配置（config.json 声明式表单）

插件在根目录放置 `config.json`，后台「插件 → 详情」会**自动渲染**配置表单并处理保存——无需编写任何配置界面代码。

#### config.json 格式（tabs → fields）

```json
{
    "tabs": [
        {
            "title": "基本设置",
            "description": "表单分组说明（可选）",
            "fields": [
                {
                    "type": "text",
                    "name": "api_base_url",
                    "label": "API 地址",
                    "value": "https://api.example.com",
                    "placeholder": "https://api.example.com",
                    "help": "帮助文案"
                },
                {
                    "type": "select",
                    "name": "theme",
                    "label": "主题",
                    "value": "auto",
                    "options": {
                        "auto": "跟随系统",
                        "light": "浅色",
                        "dark": "深色"
                    }
                },
                {
                    "type": "switch",
                    "name": "autoplay",
                    "label": "自动播放",
                    "value": false
                },
                {
                    "type": "textarea",
                    "name": "custom_paths",
                    "label": "自定义路径",
                    "value": "/",
                    "rows": 5
                }
            ]
        }
    ]
}
```

#### 支持的字段类型

| type | 控件 | 值类型 |
| --- | --- | --- |
| `text` | 单行输入框 | string |
| `textarea` | 多行输入框（`rows` 可选） | string |
| `select` | 下拉框（`options` 为 `{值: 标签}` 对象） | string |
| `switch` | Bootstrap 开关 | bool |

#### 通用字段属性

| 属性 | 说明 |
| --- | --- |
| `type` | 控件类型 |
| `name` | 字段名（保存键名） |
| `label` | 显示标签 |
| `value` | 默认值/当前值（后台保存后回写此处） |
| `placeholder` | 占位提示（text/textarea） |
| `help` | 字段下方帮助文案 |
| `options` | select 的选项映射 |
| `rows` | textarea 行数 |

#### 在插件代码中读取配置

配置保存后**回写到同一个 config.json 的 `value` 字段**，因此读取就是解析该文件（可参考内置插件写法）：

```php
private ?array $cfgCache = null;

private function getConfig(): array {
    if ($this->cfgCache !== null) {
        return $this->cfgCache;
    }
    $file = dirname($this->plugin_path) . '/config.json';  // plugin/ 的上级 = 插件根目录
    $cfg  = [];
    if (is_file($file)) {
        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data) && !empty($data['tabs'])) {
            foreach ($data['tabs'] as $tab) {
                foreach ($tab['fields'] ?? [] as $field) {
                    if (isset($field['name'])) {
                        $cfg[$field['name']] = $field['value'] ?? '';
                    }
                }
            }
        }
    }
    return $this->cfgCache = $cfg;
}
```

#### config_path：把配置放到插件目录之外

插件被卸载（删目录）时配置会一同丢失。通过 `plugin.json` 的 `config_path` 可把配置文件重定向到插件目录之外：

```json
{
    "id": "cron-manager",
    "config_path": "../../public/cron/config.json"
}
```

规则（`Nova_Plugin_Registry::resolve_config_file()` 实现）：

- 路径相对**插件根目录**解析（上例实际指向 `vendor/public/cron/config.json`）
- 解析结果必须位于**项目根目录之内**，越界（路径逃逸）自动回退默认 `config.json`
- 目标目录不存在时同样回退默认路径

> 📖 真实示例：`vendor/nova-plugins/cron-manager/`（config_path 用法）、`vendor/nova-plugins/netease-player/`（纯 config.json 用法）。

***

### 4.7 后台管理页面与菜单

#### 机制：零注册的后台页面

后台管理页面**不需要手动注册菜单**，完整流程由系统自动完成：

1. 插件提供 `plugin/admin/index.php`
2. 后台侧边栏（`admin/includes/header.php`）扫描所有插件：存在 `admin/index.php` 且 `sidebar` 未设为 `false` 且插件已启用 → 自动在「系统」分组下注册菜单项，链接到 `/admin/plugin-page.php?plugin={id}`
3. 管理员点击菜单 → `admin/plugin-page.php` 通用渲染器：
   - 解析 `?plugin=` 参数（接受 id 或 slug）
   - 校验插件存在、已启用（禁用则渲染提示页）
   - 引入 `includes/header.php` / `includes/footer.php`（后台外壳：导航、CSRF meta、样式）
   - `include` 插件的 `plugin/admin/index.php`

#### 编写后台页面

后台页面是「在后台外壳内的 HTML 片段」，**自行负责加载依赖**：

```php
<?php
// plugin/admin/index.php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

// 自加载依赖，使本文件可通过 admin/plugin-page.php 独立引入
require_once __DIR__ . '/../class-my-plugin.php';

$myPlugin = new My_Plugin_Core();
$items    = $myPlugin->getItems();
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1"><i class="bi bi-gear me-2"></i>我的插件管理</h4>
                <p class="text-muted mb-0">插件功能说明</p>
            </div>
            <button class="btn btn-primary" onclick="doAction()"><i class="bi bi-plus-circle me-1"></i>新建</button>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <!-- Bootstrap 5 卡片/表格 -->
        </div>
    </div>
</div>

<script>
function doAction() {
    // AJAX 请求需带 CSRF token（后台已注入 <meta name="csrf-token">）
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const fd = new FormData();
    fd.append('csrf_token', token);
    fetch('/nova-json/v1/my-plugin/action', { method: 'POST', body: fd })
        .then(r => r.json()).then(console.log);
}
</script>
```

#### 后台页面可用环境

| 能力 | 说明 |
| --- | --- |
| 后台外壳 | header/footer 已引入：Bootstrap 5 样式、侧边栏、深色模式切换 |
| CSRF | `<meta name="csrf-token">` 已注入，取值后随 AJAX/表单提交（`validateCSRFToken()` 服务端校验） |
| 全局函数 | `e()`、`getDB()`、`generateCSRFToken()`、`validateCSRFToken()` 可直接使用 |
| 图标 | Bootstrap Icons（`bi bi-*` 类）已加载 |
| 页面标题 | 渲染器已设置 `$page_title`（插件名） |

#### 隐藏侧边栏菜单

`plugin.json` 中声明：

```json
{ "sidebar": false }
```

适用场景：插件只有配置表单（config.json 自动渲染）而无独立管理页，或通过 `detail_tab` 提供详情页内容。

#### 插件详情页自定义 Tab（detail_tab）

后台插件详情页（`admin/plugins.php?plugin=xxx`）默认展示元数据表。插件可追加自定义 Tab：

1. `plugin.json` 声明 `"detail_tab": "数据统计"`
2. 放置 `plugin/admin/detail.php`（内容格式与 index.php 相同，在详情页内渲染）

> 📖 真实示例：`vendor/nova-plugins/cron-manager/plugin/admin/detail.php` 展示定时任务列表。
>
> 📖 真实示例：`vendor/nova-plugins/backup/plugin/admin/index.php` 演示了完整后台页面（统计卡片 + 列表 + 操作按钮）。

#### 低层菜单 API（供特殊需求）

`Nova_Backend_Menu` 类（`vendor/nova-json/class/backend/class-backend-menu.php`）可注册任意后台菜单，通常供核心或深度定制使用；插件常规场景**用不上**（系统已自动注册）：

```php
Nova_Backend_Menu::add_menu('工具名', 'my-tool', '/admin/my-tool.php', 'bi-gear', 30, [
    'group'       => 'tools',        // 分组
    'group_label' => '工具',
    'badge'       => '3',            // 徽标
    'badge_type'  => 'danger',
]);

Nova_Backend_Menu::add_submenu('my-tool', '子项', 'my-tool-sub', '/admin/my-tool-sub.php');
```

同理存在 `Nova_Backend_Page` / `Nova_Backend_List_Table` / `Nova_Backend_Ajax` / `Nova_Backend_Notice` 等后台构建类，主要用于核心后台页面；插件后台建议直接写 `plugin/admin/index.php` HTML 片段（更直观、无额外学习成本）。

***

### 4.8 数据库操作

#### Nova_DB 基础 CRUD

```php
$db = new Nova_DB();   // 或 $this->db()（插件基类内）

// 查询
$row    = $db->get_row("SELECT * FROM my_table WHERE id = ?", [5]);
$rows   = $db->get_results("SELECT * FROM my_table ORDER BY id DESC LIMIT 20");
$count  = $db->get_var("SELECT COUNT(*) FROM my_table");

// 写入（参数化）
$db->insert('my_table', ['name' => 'a', 'value' => 'b']);
$db->insert_batch('my_table', [['name' => 'a'], ['name' => 'b']]);
$newId  = $db->insert_id();

$db->update('my_table', ['value' => 'new'], ['id' => 5]);
$db->delete('my_table', ['id' => 5]);

// 事务
$db->begin();
try {
    $db->insert(...); $db->update(...);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
}
```

#### 创建数据表

```php
$db->create_table('my_plugin_data', [
    'id'         => 'INT AUTO_INCREMENT PRIMARY KEY',
    'name'       => 'VARCHAR(191) NOT NULL',
    'value'      => 'TEXT',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
]);

// 升级时增量加列（已存在会报错，需先 table_exists 判断）
if (!$db->table_exists('my_plugin_data')) {
    $db->create_table(...);
}
```

#### 原生 SQL

```php
$affected = $db->query("UPDATE my_table SET hits = hits + 1");   // 返回受影响行数
$stmt     = $db->raw_query("SELECT * FROM my_table WHERE id = ?", [5]);
$row      = $stmt->fetch();

$pdo = $db->get_pdo();   // 获取底层 PDO 实例
```

#### 也可以直接使用全局 getDB()

前台环境（页面路由、主题模板）已加载 `config/functions.php`，可直接：

```php
$db   = getDB();                 // 全局 PDO 连接（与 Nova_DB 同一数据库）
$stmt = $db->prepare("SELECT ...");
$stmt->execute([$id]);
$row  = $stmt->fetch(PDO::FETCH_ASSOC);
```

***

### 4.9 文件上传与图片处理

#### 上传文件（Nova_Upload）

```php
$upload = new Nova_Upload($_FILES['file']);

$upload->allowedTypes(['jpg', 'png', 'pdf'])   // 允许的扩展名
       ->maxSize(5 * 1024 * 1024)              // 最大 5MB
       ->subDir('my-plugin')                   // 上传到 uploads/my-plugin/
       ->prefix('plugin_');                    // 文件名前缀

if ($upload->validate()) {
    $result = $upload->save();
    // $result['url'] => '/uploads/my-plugin/plugin_abc123.jpg'
} else {
    $error = $upload->getError();
}
```

完整链式 API：

| 方法 | 说明 |
| --- | --- |
| `allowedTypes(array $types)` | 允许的扩展名列表 |
| `allowedMimes(array $mimes)` | 允许的 MIME 类型列表 |
| `onlyImages()` | 仅允许图片（等效内置图片类型集合） |
| `maxSize($bytes)` / `minSize($bytes)` | 文件大小限制 |
| `toDir($dir)` / `toUrl($url)` | 自定义保存目录/URL 前缀（默认 `uploads/` 子目录） |
| `subDir($subDir)` | uploads 下的子目录 |
| `prefix($prefix)` | 文件名前缀 |
| `overwrite(bool)` | 同名覆盖（默认追加随机后缀） |
| `useOriginalName(bool)` | 保留原始文件名 |
| `validate()` | 校验（返回 bool） |
| `save()` | 保存，返回含 `url`/`path` 的数组 |
| `saveGetUrl()` | 保存并直接返回 URL |
| `getError()` | 最近一次错误信息 |
| `getSavedPath()` / `getSavedUrl()` | 保存后的路径/URL |

#### 图片处理（Nova_Image）

```php
try {
    $img = new Nova_Image('uploads/my-plugin/source.jpg');

    $img->thumb(300, 200)->save('uploads/my-plugin/thumb.jpg');   // 缩略图

    $img->watermark('assets/images/mark.png', 'bottom-right', 80) // 水印
        ->save('uploads/my-plugin/watermarked.jpg');
} catch (Exception $e) {
    // GD 库异常
}
```

> 详细签名见 `vendor/nova-json/class/filesystem/class-image.php`。

***

### 4.10 定时任务（Nova_Cron）

#### 工作模式

Nova_Cron 支持两种执行模式（可在 Cron Manager 插件中切换）：

| 模式 | 机制 | 适用环境 |
| --- | --- | --- |
| **服务器 Cron** | 面板配置 `crontab` 定时请求 `vendor/public/cron/cron.php` | VPS / 独立服务器（推荐） |
| **访问触发** | 每次前台访问时 `Nova_Cron::maybe_run_on_visit()` 异步触发（独立进程，不阻塞访客） | 虚拟主机 / 无 Cron 环境 |

#### 注册定时任务

在插件 `init()` 中：

```php
public function init() {
    Nova_Cron::register(
        'my-plugin-daily',       // 任务 id：字母开头，仅字母/数字/下划线/连字符
        86400,                   // 间隔（秒），最小 60
        [$this, 'dailyJob'],     // 回调：无参数，失败抛异常即可
        '我的插件：每日任务'      // 描述（后台任务列表展示）
    );
}

public function dailyJob() {
    // 业务逻辑；失败时 throw new Exception('原因')
}
```

#### 任务状态与并发安全

- 执行状态记录于数据库（`Nova_Cron` 自动建表），含上次执行时间、结果
- 到期判断 + 锁机制保证并发安全（多访客同时触发不会重复执行）
- 后台「定时任务」页面（cron-manager 插件）可查看已注册任务与执行状态

#### API

| 方法 | 说明 |
| --- | --- |
| `register($id, $interval, $callback, $description = '')` | 注册任务（id 格式与 interval≥60 校验，失败返回 false） |
| `unregister($id)` | 注销任务 |
| `get_tasks()` | 已注册任务元数据列表 |
| `run_due($force = false)` | 执行所有到期任务（返回各任务结果） |
| `run_one($id, $force = false)` | 执行单个任务 |
| `maybe_run_on_visit()` | 访问触发入口（index.php 自动调用，含限频） |
| `enable_visit_trigger()` / `disable_visit_trigger()` | 开关访问触发模式 |
| `is_visit_trigger_enabled()` | 访问触发是否启用 |
| `get_last_run($id)` / `is_due($id)` | 查询任务状态 |

***

## 5. API 参考

### 5.1 Nova_Plugin_Registry

| 方法 | 说明 |
| --- | --- |
| `scan_all($force = false)` | 扫描 `vendor/nova-plugins/*/plugin.json`，返回插件信息数组（含 id/slug/name/version/entry/entry_path/plugin_dir/page_routes/sidebar/config_path/detail_tab/duplicate） |
| `find_plugin($key)` | 按 id 或 slug 查找单个插件 |
| `resolve_config_file(array $plugin)` | 解析插件配置文件路径（含 config_path 与安全校验） |
| `get_active_plugin_ids($force = false)` | 已启用插件 id 数组；**null = 全部启用** |
| `is_plugin_active($pluginId)` | 插件是否启用 |
| `clear_cache()` | 清除扫描与启用状态缓存（切换开关后调用） |

### 5.2 Nova_Hooks

见 [4.2 钩子系统](#42-钩子系统actions--filters) 完整方法表。

### 5.3 register_rest_route

```php
register_rest_route($namespace, $route, $args, $override = false);
```

| 参数 | 说明 |
| --- | --- |
| `$namespace` | 命名空间（如 `v1`），自动成为 URL 前缀 `/nova-json/{ns}/...` |
| `$route` | 路由模式，如 `/posts/(?P<id>\d+)` 或 `/items/{id}` |
| `$args` | 数组：`methods`（string/array）、`callback`（callable）、`permission_callback`（callable，可选）、`plugin_id`/`plugin_slug`（上下文自动注入） |
| `$override` | 同名路由是否覆盖（默认合并） |

首次注册某 namespace 时会自动生成 namespace 索引路由（`GET /nova-json/{ns}` 列出该空间所有路由）。

### 5.4 Nova_DB

见 [4.8 数据库操作](#48-数据库操作)。另有 Nova_DB_Cache（查询缓存）、Nova_DB_Schema、Nova_DB_Query（查询构建）、Nova_DB_Migration、Nova_DB_Seeder 等扩展类，详见 `cms-docs/class.md`。

### 5.5 Nova_REST_Server

| 方法 | 说明 |
| --- | --- |
| `register_route($ns, $route, $args, $override = false)` | 注册路由（自动注入当前插件上下文） |
| `serve_request($path = null)` | 处理当前请求（init.php 入口） |
| `dispatch($request)` | 路由匹配与分发（含禁用插件拦截） |
| `get_namespaces()` / `get_routes($ns = '')` | 已注册命名空间 / 路由表 |
| `set_current_plugin_context($id, $slug)` | 设置插件上下文（加载插件 routes 时系统自动调用） |
| `clear_current_plugin_context()` | 清除上下文 |

### 5.6 Nova_Cron

见 [4.10 定时任务](#410-定时任务nova_cron) 完整方法表。

### 5.7 Nova_Upload / Nova_Image

见 [4.9 文件上传与图片处理](#49-文件上传与图片处理)。

### 5.8 Nova_API / Nova_Proxy

- `Nova_API` — 内部调度本地 REST 路由，零网络开销
- `Nova_Proxy` — 本地调度 + 公网代理请求

详见 `cms-docs/class.md`。

***

## 6. 案例与最佳实践

### 6.1 案例一：前台注入型（参考 netease-player）

特点：挂载 `nova_head`/`nova_footer` 输出内容，配置驱动，无需数据库。

```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class Greeting_Plugin extends Nova_Plugin {
    protected $name = 'greeting';

    public function init() {
        Nova_Hooks::add_action('nova_footer', [$this, 'renderBar']);
    }

    public function renderBar() {
        // 读取 config.json（读取方式见 4.6）
        $cfg = $this->getConfig();
        if (($cfg['enabled'] ?? false) !== true) return;

        $text = e((string)($cfg['text'] ?? '欢迎来访'));
        echo "<div class=\"greeting-bar\">{$text}</div>\n";
    }
}
new Greeting_Plugin();
```

### 6.2 案例二：页面路由型（参考 rss / sitemap）

特点：`page_routes` 提供独立输出，常用于 XML/文件流。

```php
<?php
// plugin/robots-plus.php — page_routes: {"/robots-custom.txt": "plugin/robots-plus.php"}
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$disallow = [];
foreach ($db->query("SELECT slug FROM posts WHERE status = 'draft'") as $row) {
    $disallow[] = '/blog?id=' . (int)$row['slug'];
}

echo "User-agent: *\n";
foreach ($disallow as $path) {
    echo "Disallow: {$path}\n";
}
```

### 6.3 案例三：定时任务型（结合数据库）

特点：`init()` 注册任务，回调中执行维护逻辑。

```php
class Cleanup_Plugin extends Nova_Plugin {
    protected $name = 'cleanup';

    public function init() {
        Nova_Cron::register('cleanup-expired', 3600, [$this, 'purge'], '清理过期数据');
    }

    public function purge() {
        $db = $this->db();
        $db->query("DELETE FROM my_plugin_data WHERE expires_at < NOW()");
        if (random_int(0, 99) === 0) {
            throw new Exception('模拟失败：将被记录到任务状态');   // 抛异常即记为失败
        }
    }
}
```

### 6.4 最佳实践清单

1. **入口即校验**：所有 PHP 文件首行 `defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');`
2. **副作用放 init()**：路由注册、钩子挂载都写在 `init()`（禁用时不会被调用）；构造函数保持轻量
3. **id 用英文且唯一**：与目录名（slug）保持一致可避免困惑
4. **输出必须转义**：模板输出统一 `e()`，杜绝 XSS
5. **状态变更带 CSRF**：后台 AJAX 用 `<meta name="csrf-token">` 的 token，服务端 `validateCSRFToken()` 校验
6. **数据表先判断再建**：`table_exists()` 防重复建表报错
7. **配置读取带缓存**：一次请求内多次读取只解析一次（参考 4.6 的 getConfig 写法）
8. **路径写相对插件自身**：用 `$this->plugin_path` / `__DIR__`，勿硬编码绝对路径
9. **页面路由不带 .php 后缀**：干净路径始终经 index.php 分发，不依赖伪静态细节
10. **禁用态自检**：涉及定时任务时，任务回调里对数据缺失做容错（插件被禁用期间数据可能变化）

***

## 7. 附录

### 7.1 插件开发检查清单

- [ ] `plugin.json` 含英文 `id`（字母开头，仅字母/数字/`_`/`-`，唯一），排在 `name` 前
- [ ] 入口文件为 `plugin/plugin.php`（或 `entry` 已声明）
- [ ] 所有 PHP 文件有 `NOVA_BOOTSTRAP` 检查
- [ ] 主类继承 `Nova_Plugin`，入口末尾 `new My_Plugin()`
- [ ] 副作用逻辑在 `init()` 内
- [ ] 页面路由路径无 `.php` 后缀
- [ ] 后台页面（如有）位于 `plugin/admin/index.php`，自行 require 依赖
- [ ] AJAX/表单带 CSRF token 并服务端校验
- [ ] 数据库操作全部参数化（无字符串拼接 SQL）
- [ ] `min_nova_version` 如实声明（用到 1.1+ 规范需 ≥ 1.1）

### 7.2 相关文件索引

| 文件 | 作用 |
| --- | --- |
| `vendor/nova-json/class/plugin/class-plugin-registry.php` | 插件扫描、id 解析、启用状态 |
| `vendor/nova-json/class/plugin/class-plugin.php` | 插件基类 |
| `vendor/nova-json/init.php` | API 侧插件加载流程 |
| `index.php` | 前台侧插件加载 + page_routes 匹配 + 注入钩子 |
| `admin/plugins.php` | 插件管理页（列表/详情/开关/配置表单） |
| `admin/plugin-page.php` | 后台页面通用渲染器 |
| `admin/includes/header.php` | 侧边栏菜单自动注册 |
| `vendor/nova-plugins/*/` | 六个内置插件（开发参考） |

### 7.3 版本兼容性

| NovaCMS 版本 | 插件规范 |
| --- | --- |
| 1.1+ | 当前规范：`plugin/` 子目录 + `plugin.json`（含英文 id、page_routes、sidebar、config_path、detail_tab） |
| 1.0（旧） | 无 `plugin/` 子目录、无 id 的旧结构；系统对旧插件以 slug 回退 id，不做迁移，建议按新规范重排 |

> 使用 `min_nova_version` 字段声明最低版本；后台插件管理页会展示该要求。
