# 插件开发

版本：1.0

---

NovaCMS 采用可插拔架构，功能模块之间耦合度低、灵活性高，支持用户按需安装、卸载插件，操作便捷。同时提供插件开发接口以确保较高的扩展性和可维护性，这个系列的文档将帮助你了解如何开发 NovaCMS 插件。

---

## 目录

- [📄️ 介绍](#1-介绍)
- [📄️ 准备工作](#2-准备工作)
- [📄️ 入门：Hello World](#3-入门hello-world)
- [🗃 基础](#4-基础)
  - [插件基类与生命周期](#41-插件基类与生命周期)
  - [钩子系统：Actions & Filters](#42-钩子系统actions--filters)
  - [注册 REST API 路由](#43-注册-rest-api-路由)
  - [数据库操作](#44-数据库操作)
  - [后台页面与菜单](#45-后台页面与菜单)
  - [文件上传与图片处理](#46-文件上传与图片处理)
- [🗃 API 参考](#5-api-参考)
  - [Nova_Plugin](#51-nova_plugin)
  - [Nova_Hooks](#52-nova_hooks)
  - [register_rest_route](#53-register_rest_route)
  - [Nova_DB](#54-nova_db)
  - [Nova_Backend_Page](#55-nova_backend_page)
  - [Nova_Backend_Menu](#56-nova_backend_menu)
  - [Nova_Backend_Ajax](#57-nova_backend_ajax)
  - [Nova_Backend_Notice](#58-nova_backend_notice)
  - [Nova_API](#59-nova_api)
  - [Nova_Proxy](#510-nova_proxy)
- [🗃 案例和最佳实践](#6-案例和最佳实践)
  - [示例一：文章统计插件](#61-示例一文章统计插件)
  - [示例二：内容审核插件](#62-示例二内容审核插件)
  - [示例三：自定义短代码插件](#63-示例三自定义短代码插件)

---

## 1. 介绍

### 什么是 NovaCMS 插件？

插件是用于扩展 NovaCMS 功能的独立 PHP 代码包。通过插件机制，开发者可以在不影响核心代码的前提下：

- **注册新的 REST API 端点** — 扩展 API 能力
- **挂载系统钩子** — 在特定时机执行自定义逻辑
- **创建后台管理页面** — 提供配置界面
- **操作数据库** — 读写数据、创建表结构
- **处理文件上传** — 管理附件资源

### 插件架构概览

```
┌─────────────────────────────────────────────────────────┐
│                      前台/客户端                          │
└──────────────────────┬──────────────────────────────────┘
                       │ HTTP/REST
┌──────────────────────▼──────────────────────────────────┐
│            Nova_REST_Server (路由分发引擎)                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │ 系统内置路由  │  │  插件注册路由  │  │  主题注册路由  │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              Nova_Hooks (钩子事件系统)                    │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │  Action 钩子  │  │  Filter 钩子  │  │  生命周期钩子  │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│                Nova_DB (数据库层)                         │
│    Nova_DB_Query / Nova_DB_Schema / Nova_DB_Migration   │
└─────────────────────────────────────────────────────────┘
```

### 核心概念

| 概念 | 说明 |
|------|------|
| **插件** | 继承 `Nova_Plugin` 的 PHP 类，放在 `vendor/nova-plugins/` 目录 |
| **路由** | REST API 端点，通过 `register_rest_route()` 注册 |
| **钩子** | 事件（Action）和过滤器（Filter），通过 `Nova_Hooks` 管理 |
| **扩展点** | 系统预定义的可扩展位置，插件可注入自定义逻辑 |

---

## 2. 准备工作

### 环境要求

| 项目 | 要求 |
|------|------|
| PHP | 7.4+ 推荐 8.0+ |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ |
| 扩展 | PDO, GD (图片处理), JSON |
| Web 服务器 | Apache / Nginx / IIS |

### 目录结构

NovaCMS 的插件系统约定插件应放置在 `vendor/nova-plugins/` 目录，每个插件一个独立文件夹。**自 NovaCMS 1.1 起，插件采用统一的目录规范**：代码统一放在 `plugin/` 子目录，元数据通过根目录的 `plugin.json` 声明，其中 `id` 字段由开发者手动填写（必须为英文）：

```
vendor/nova-plugins/
├── my-plugin/                  # 插件目录（slug，唯一）
│   ├── plugin/                 # 插件代码目录（所有 PHP 代码）
│   │   ├── plugin.php          # 入口文件（默认 entry）
│   │   ├── class-my-plugin.php # 插件主类（推荐）
│   │   ├── routes/             # 路由文件目录（自动加载）
│   │   │   ├── api.php
│   │   │   └── web.php
│   │   ├── views/              # 视图模板目录
│   │   │   └── settings.php
│   │   └── admin/              # 后台管理页面
│   │       └── index.php
│   ├── plugin.json             # 元数据（含 id，必须为英文；可声明 page_routes）
│   ├── config.json             # 插件配置表单定义及存储（可选，详见 4.3.2）
│   ├── LICENSE                 # 许可证文件
│   ├── assets/                 # 静态资源目录（可选）
│   │   ├── css/
│   │   └── js/
│   └── data/                   # 运行时数据目录（可选，如备份、缓存等）
```

#### plugin.json 格式

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
    "min_nova_version": "1.0"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | string | 是 | 插件唯一标识符，**必须为英文**（字母、数字、下划线 `_`、连字符 `-`），**不可与其他插件重复**，建议与目录名一致。排在 `name` 之前。启用/禁用以此 id 为准。若未填写，系统自动以目录名（slug）作为 id 回退 |
| `name` | string | 是 | 插件显示名称 |
| `uri` | string | 否 | 插件主页 URL |
| `description` | string | 否 | 插件描述 |
| `version` | string | 是 | 版本号 |
| `author` | string | 否 | 作者 |
| `author_uri` | string | 否 | 作者主页 |
| `entry` | string | 否 | 入口文件相对路径，默认 `plugin/plugin.php` |
| `min_nova_version` | string | 否 | 最低 NovaCMS 版本要求 |
| `page_routes` | object | 否 | 自定义页面路由映射，key 为 URL 路径，value 为要执行的 PHP 文件路径（相对插件目录）。详见 [4.3.1 注册自定义页面路由](#431-注册自定义页面路由) |

> **注意**：`id` 字段必须为英文（字母、数字、下划线、连字符），由开发者在 `plugin.json` 中手动填写，排在 `name` 字段之前。**每个插件的 id 必须唯一，不可重复**。若未填写，系统会自动以插件目录名（slug）作为 id 回退并写回文件。

### 快速启动模板

创建一个最基本的插件目录和文件结构：

```bash
cd vendor/nova-plugins/
mkdir -p my-plugin/plugin/routes my-plugin/plugin/views my-plugin/plugin/admin my-plugin/assets
# 创建元数据文件
cat > my-plugin/plugin.json << 'EOF'
{
    "id": "my-plugin",
    "name": "My Plugin",
    "description": "插件描述",
    "version": "1.0.0",
    "author": "你的名字",
    "entry": "plugin/plugin.php"
}
EOF
# 创建入口文件
touch my-plugin/plugin/plugin.php
```

---

## 3. 入门：Hello World

此文档将帮助你了解如何构建你的第一个插件并在 NovaCMS 中使用它。

### 创建插件类

在 `vendor/nova-plugins/my-plugin/plugin/class-my-plugin.php` 中创建插件主类：

```php
<?php
// vendor/nova-plugins/my-plugin/plugin/class-my-plugin.php

defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');

class MyPlugin extends Nova_Plugin {

    protected $name    = 'my-plugin';
    protected $version = '1.0.0';

    /**
     * 插件初始化入口
     * 在系统加载时自动调用
     */
    public function init() {
        // 注册一个 REST API 路由
        $this->register_route('v1', '/my-plugin/hello', [
            'methods'  => 'GET',
            'callback' => [$this, 'hello'],
        ]);

        // 注册一个后台管理页面
        Nova_Backend_Menu::add_menu(
            '我的插件',          // 菜单显示名称
            'my-plugin',        // 菜单唯一 ID
            '/admin/my-plugin.php', // 页面 URL
            '🔌',               // 菜单图标
            30                  // 菜单位置
        );

        // 注册后台 AJAX 处理器
        Nova_Backend_Ajax::add('my_plugin_data', [$this, 'ajaxHandler']);
    }

    /**
     * REST API 回调
     */
    public function hello($request) {
        return new Nova_REST_Response([
            'code'    => 'rest_ok',
            'message' => '你好，世界！',
            'data'    => [
                'status'  => 200,
                'version' => $this->version,
                'greeting' => 'Hello from My Plugin!',
            ],
        ]);
    }

    /**
     * AJAX 处理器
     */
    public function ajaxHandler($input) {
        return [
            'success' => true,
            'data'    => ['message' => 'AJAX 请求成功'],
        ];
    }
}

// 实例化插件（必须）
new MyPlugin();
```

### 创建入口文件

在 `vendor/nova-plugins/my-plugin/plugin/plugin.php` 中创建入口文件：

```php
<?php
// vendor/nova-plugins/my-plugin/plugin/plugin.php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
require_once __DIR__ . '/class-my-plugin.php';
// 系统会自动扫描并加载此文件
```

### 创建 plugin.json

在 `vendor/nova-plugins/my-plugin/plugin.json` 中声明元数据：

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
    "min_nova_version": "1.0"
}
```

> `id` 字段必须为英文（字母、数字、下划线、连字符），由开发者手动填写，排在 `name` 之前。每个插件的 id 必须唯一，不可重复。若未填写，系统自动以目录名作为 id 回退。

### 验证插件

1. 将插件目录 `my-plugin` 放入 `vendor/nova-plugins/`，确保包含 `plugin.json`（含英文 `id`）和 `plugin/plugin.php`
2. 访问后台「插件管理」页面，系统会自动识别插件
3. 访问任意前台页面，插件即自动加载（需处于启用状态）
4. 访问 `GET /nova-json/v1/my-plugin/hello` 测试 API

**响应示例：**

```json
{
    "code": "rest_ok",
    "message": "你好，世界！",
    "data": {
        "status": 200,
        "version": "1.0.0",
        "greeting": "Hello from My Plugin!"
    }
}
```

---

## 4. 基础

### 4.1 插件基类与生命周期

#### Nova_Plugin 类

所有插件必须继承 `Nova_Plugin` 基类，该类提供了：

- **自动路径检测** — 自动识别插件文件和目录路径
- **自动路由加载** — 自动加载 `routes/` 目录下的 PHP 文件
- **生命周期管理** — 通过 `init()` 方法在系统初始化时执行

#### 生命周期

```
系统启动
    │
    ├── 加载全部插件文件
    │
    ├── 实例化插件类 (__construct)
    │   ├── 自动检测插件路径/URL
    │   ├── 自动加载 routes/ 目录
    │   └── 注册 init() 到钩子系统
    │
    ├── Nova_Hooks::do_action('nova_init')
    │   └── 执行所有插件的 init() 方法
    │
    ├── Nova_Hooks::do_action('rest_api_init')
    │   └── 执行所有路由注册
    │
    └── 处理请求 → 路由分发 → 执行回调
```

#### 可用属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `$name` | string | 插件名称（用于日志标识） |
| `$version` | string | 插件版本号 |
| `$plugin_path` | string | 插件目录绝对路径（自动检测） |
| `$plugin_url` | string | 插件 URL 地址（自动检测） |

#### 可用方法

| 方法 | 说明 |
|------|------|
| `register_route(namespace, route, args)` | 快捷注册 REST 路由 |
| `db()` | 获取 `Nova_DB` 实例 |
| `log(message, level)` | 写入 PHP 错误日志 |

---

### 4.2 钩子系统：Actions & Filters

NovaCMS 提供了类似 WordPress 的钩子系统（`Nova_Hooks`），包含两种钩子类型：

#### Actions（动作钩子）

在特定时机执行代码，不返回值。

```php
// 注册动作
Nova_Hooks::add_action('nova_init', 'my_callback', $priority = 10);

// 执行动作（系统在适当时机调用）
Nova_Hooks::do_action('nova_init');
```

#### Filters（过滤器钩子）

修改传递的值并返回。

```php
// 注册过滤器
Nova_Hooks::add_filter('nova_post_data', 'my_filter_callback', $priority = 10);

// 应用过滤器（系统在适当时机调用）
$data = Nova_Hooks::apply_filters('nova_post_data', $originalData);
```

#### 系统内置钩子

| 钩子名称 | 类型 | 时机 |
|----------|------|------|
| `nova_init` | Action | 系统初始化完成时 |
| `rest_api_init` | Action | REST API 路由注册时 |
| `nova_backend_render_{menu_id}` | Action | 后台页面渲染时 |
| `nova_backend_menu_capability` | Filter | 检查菜单权限时 |
| `nova_backend_user_capability` | Filter | 检查用户权限时 |
| `nova_post_data` | Filter | 获取文章数据时 |

#### 插件示例：使用钩子扩展功能

```php
class MyPlugin extends Nova_Plugin {
    public function init() {
        // 在系统初始化时执行
        Nova_Hooks::add_action('nova_init', [$this, 'onSystemInit']);

        // 修改文章数据
        Nova_Hooks::add_filter('nova_post_data', [$this, 'modifyPostData']);
    }

    public function onSystemInit() {
        // 自定义初始化逻辑
        $this->log('插件已加载');
    }

    public function modifyPostData($data) {
        // 为每篇文章添加自定义字段
        $data['plugin_info'] = '由 My Plugin 处理';
        return $data;
    }
}
```

---

### 4.3 注册 REST API 路由

插件可以通过 `register_rest_route()` 函数或 `Nova_Plugin::register_route()` 注册 API 端点。

#### 基本路由

```php
register_rest_route('v1', '/my-plugin/items', [
    'methods'  => 'GET',
    'callback' => function($request) {
        return new Nova_REST_Response([
            'code' => 'rest_ok',
            'data' => ['items' => ['item1', 'item2']],
        ]);
    },
    'permission_callback' => function($request) {
        return true; // 公开访问
    },
]);
```

#### 带 URL 参数的路由

```php
register_rest_route('v1', '/my-plugin/items/(?P<id>\d+)', [
    'methods'  => 'GET',
    'callback' => function($request) {
        $id = $request->get_param('id');
        return new Nova_REST_Response([
            'code' => 'rest_ok',
            'data' => ['id' => $id],
        ]);
    },
]);
```

#### 多种请求方法

```php
register_rest_route('v1', '/my-plugin/data', [
    [
        'methods'  => 'GET',
        'callback' => 'handle_get',
    ],
    [
        'methods'  => 'POST',
        'callback' => 'handle_post',
        'permission_callback' => function($request) {
            return is_admin();
        },
    ],
]);
```

#### 权限控制

```php
// 公开访问
'permission_callback' => function($request) {
    return true;
}

// 需要登录
'permission_callback' => function($request) {
    return !empty($_SESSION['user_id']);
}

// 需要管理员权限
'permission_callback' => function($request) {
    return !empty($_SESSION['admin_id']);
}

// 自定义权限验证
'permission_callback' => function($request) {
    $userId = $_SESSION['user_id'] ?? 0;
    return $userId > 0 && v1_is_admin($userId);
}
```

#### 获取请求参数

```php
// URL 路径参数 (?P<id>\d+)
$request->get_param('id');

// URL 查询参数 (?page=1&per_page=10)
$request->get_param('page');
$request->get_param('per_page');

// POST 请求体参数
$request->get_param('name');
$request->get_param('email');

// 获取所有合并参数
$params = $request->get_params();

// 获取请求头
$token = $request->get_header('authorization');
```

---

### 4.3.1 注册自定义页面路由

除了 REST API 路由（`/nova-json/v1/...`）之外，插件还可以注册**前台自定义页面路由**，让插件接管特定 URL 的页面输出。

#### 工作原理

在 `plugin.json` 中声明 `page_routes` 字段，系统会在主题路由之前检查当前请求路径是否匹配某个插件注册的页面路由。如果匹配：
- **插件已启用** → 加载对应的 PHP 文件，由该文件完全控制页面输出（可以输出 HTML、XML、JSON 等任意内容）
- **插件已禁用** → 返回 403 + "此插件已禁用" 提示页面

#### plugin.json 示例

```json
{
    "id": "rss",
    "name": "RSS 订阅",
    "version": "1.0.0",
    "entry": "plugin/plugin.php",
    "page_routes": {
        "/rss.xml": "plugin/rss.php",
        "/feed": "plugin/feed.php",
        "/feed/{category}": "plugin/feed.php"
    }
}
```

- **key** — URL 路径模式（相对于站点根目录），支持 `{param}` 参数占位符
- **value** — 要执行的 PHP 文件路径（相对于插件目录根目录）

#### 路由参数

路径中的 `{param}` 会被自动提取并设置到 `$_GET` 中，供插件文件使用：

```
/feed/{category}  →  访问 /feed/tech  →  $_GET['category'] = 'tech'
```

#### 插件文件示例

```php
<?php
// vendor/nova-plugins/rss/plugin/rss.php

// 设置 Content-Type 为 XML
header('Content-Type: application/xml; charset=utf-8');

// 可以通过 $_GET 获取路由参数
$category = $_GET['category'] ?? 'all';

// 输出 RSS XML 内容
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<rss version="2.0">';
echo '<channel>';
echo '<title>My Site RSS</title>';
echo '<link>https://example.com</link>';
echo '<description>最新文章订阅</description>';
// ... 输出文章列表
echo '</channel>';
echo '</rss>';
```

#### 可用常量

在页面路由文件中，以下常量已定义：

| 常量 | 说明 |
|------|------|
| `NOVA_PAGE_ROUTE` | 匹配的路由模式（如 `/rss.xml`） |
| `NOVA_PAGE_ROUTE_PLUGIN` | 插件 id（如 `rss`） |

#### 特点

- **零代码加载** — 仅扫描 `plugin.json`，无需加载插件入口代码即可判断路由匹配
- **任意扩展名** — `/rss.xml`、`/sitemap.xml`、`/manifest.json` 等均可注册，实际由 PHP 处理
- **参数支持** — `{category}` 等占位符自动注入 `$_GET`
- **禁用拦截** — 插件禁用后访问其页面路由会返回"此插件已禁用"提示
- **优先级** — 页面路由在主题路由之前执行，不会与现有页面冲突

> **注意**：`page_routes` 中的路径不要以 `/nova-json` 开头，该前缀保留给 REST API。

---

### 4.3.2 插件配置（config.json）

插件可以在目录根下创建 `config.json` 文件，声明配置表单。系统会自动在**插件详情页**（`/admin/plugin-detail.php?plugin=xxx`）中渲染配置标签页，无需编写任何 HTML。

#### 与 plugin-page.php 的区别

| | plugin-detail.php | plugin-page.php |
|---|---|---|
| 来源 | 系统自动生成 | 插件自定义 `plugin/admin/index.php` |
| 配置 | `config.json` 声明式表单 | 插件自行编写 HTML/PHP |
| 存储 | `config.json` 文件 | 插件自行管理 |
| 适用 | 简单键值对配置 | 复杂管理界面 |

#### config.json 格式

```json
{
    "tabs": [
        {
            "title": "基本设置",
            "description": "RSS 订阅的基本配置",
            "fields": [
                {
                    "type": "text",
                    "name": "rss_title",
                    "label": "RSS 标题",
                    "value": "我的站点订阅",
                    "placeholder": "输入 RSS 标题",
                    "help": "显示在 RSS 订阅中的标题"
                },
                {
                    "type": "switch",
                    "name": "enable_full_text",
                    "label": "全文输出",
                    "value": true,
                    "help": "开启后 RSS 将输出完整文章内容"
                },
                {
                    "type": "select",
                    "name": "update_interval",
                    "label": "更新频率",
                    "value": "hourly",
                    "options": {
                        "hourly": "每小时",
                        "daily": "每天",
                        "weekly": "每周"
                    }
                }
            ]
        },
        {
            "title": "高级设置",
            "fields": [
                {
                    "type": "number",
                    "name": "max_items",
                    "label": "最大条目数",
                    "value": 20,
                    "min": 1,
                    "max": 100,
                    "help": "RSS 中最多显示的文章数量"
                },
                {
                    "type": "textarea",
                    "name": "custom_css",
                    "label": "自定义样式",
                    "value": "",
                    "rows": 6,
                    "placeholder": "输入自定义 CSS"
                }
            ]
        }
    ]
}
```

#### 支持的字段类型

| type | 说明 | 额外属性 |
|------|------|----------|
| `text` | 文本输入框 | `placeholder` |
| `number` | 数字输入框 | `min`, `max`, `step`, `placeholder` |
| `textarea` | 多行文本 | `rows`, `placeholder` |
| `switch` | 开关 | 值为 `true`/`false` |
| `select` | 下拉选择 | `options`（key-value 对象） |

#### 通用字段属性

| 属性 | 说明 |
|------|------|
| `name` | 字段名（唯一标识，保存时使用） |
| `label` | 显示标签 |
| `value` | 当前值（保存后自动更新） |
| `help` | 帮助文本（显示在字段下方） |

#### 在插件代码中读取配置

```php
class MyPlugin extends Nova_Plugin {
    public function init() {
        // 读取 config.json
        $configFile = $this->plugin_path . '/../config.json';
        if (is_file($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            $rssTitle = $config['tabs'][0]['fields'][0]['value'] ?? '默认标题';
            $enableFullText = $config['tabs'][0]['fields'][1]['value'] ?? false;
        }
    }
}
```

> **注意**：`config.json` 路径是插件**目录根**（与 `plugin.json` 同级），不是 `plugin/` 子目录内。插件主类中通过 `$this->plugin_path` 获取的是 `plugin/` 目录，因此需要 `dirname($this->plugin_path) . '/config.json'`。

---

插件可以使用 `Nova_DB` 系列类操作数据库。

#### 基础 CRUD

```php
$db = new Nova_DB();

// 查询
$results = $db->get_results("SELECT * FROM posts WHERE status = ?", ['publish']);
$row     = $db->get_row("SELECT * FROM users WHERE id = ?", [1]);
$count   = $db->get_var("SELECT COUNT(*) FROM comments");

// 插入
$id = $db->insert('my_plugin_data', [
    'key'   => 'setting_key',
    'value' => 'setting_value',
]);

// 更新
$affected = $db->update('my_plugin_data', 
    ['value' => 'new_value'],
    ['key' => 'setting_key']
);

// 删除
$affected = $db->delete('my_plugin_data', ['id' => 1]);
```

#### 链式查询构建器

```php
$q = new Nova_DB_Query();

$results = $q->from('posts')
    ->where('status', 'publish')
    ->where('category', '技术')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// 分页查询
$pageData = $q->from('comments')
    ->where('post_id', 1)
    ->paginate(20); 
// ['items'=>[], 'total'=>N, 'page'=>1, 'per_page'=>20, 'pages'=>N]
```

#### 创建数据表

```php
// 使用 Nova_DB_Schema 创建表
$schema = new Nova_DB_Schema();

if (!$schema->hasTable('my_plugin_data')) {
    $schema->create('my_plugin_data', [
        'id INT AUTO_INCREMENT PRIMARY KEY',
        'key VARCHAR(100) NOT NULL UNIQUE',
        'value TEXT',
        'created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
    ], [
        'engine'  => 'InnoDB',
        'comment' => '插件数据表',
    ]);
}

// 添加字段
$schema->addColumn('my_plugin_data', 'status', "TINYINT(1) DEFAULT 1 AFTER value");

// 添加索引
$schema->addIndex('my_plugin_data', 'idx_key', ['key'], 'UNIQUE');
```

#### 数据库迁移

```php
$mig = new Nova_DB_Migration();

// 编程式迁移
$mig->create('v1_create_plugin_tables', function($schema) {
    $schema->create('my_plugin_data', [
        'id INT AUTO_INCREMENT PRIMARY KEY',
        'key VARCHAR(100) NOT NULL UNIQUE',
        'value TEXT',
        'created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
    ]);
});

// 生成迁移文件
$filepath = $mig->generate('add_status_column');

// 查看迁移状态
$status = $mig->status();
```

#### 缓存查询结果

```php
$cache = new Nova_DB_Cache();

$data = $cache->get('my_plugin_stats', function() use ($db) {
    return $db->get_results("SELECT ...");
}, 3600); // 缓存 1 小时

// 数据变更时清除缓存
$cache->delete('my_plugin_stats');
// 或清空全部
// $cache->flush();
```

---

### 4.5 访问控制

NovaCMS 使用 `NOVA_BOOTSTRAP` 常量防止 PHP 文件被直接 HTTP 访问。

#### 原理

- `index.php` 在启动时定义 `NOVA_BOOTSTRAP`
- 所有敏感 PHP 文件（类文件、插件入口、路由文件、page_routes 文件）在开头检查此常量
- 通过 `require_once` 加载的文件继承常量（进程内操作）
- 直接 HTTP 访问是新进程，常量不存在 → `exit('禁止直接访问')`

#### 插件文件必须添加的检查

**入口文件**（`plugin/plugin.php`）：
```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
```

**类文件**（`plugin/class-*.php`）：
```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
```

**API 路由文件**（`plugin/routes/api.php`）：
```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
```

**page_routes 文件**（如 `plugin/rss.php`）：
```php
<?php
defined('NOVA_BOOTSTRAP') or exit('禁止直接访问');
```

#### 额外防护

- `vendor/nova-json/.htaccess` 和 `vendor/nova-plugins/.htaccess` 阻止直接 HTTP 访问（Apache 级别）
- `NOVA_BOOTSTRAP` 是 PHP 级别的次级防线（Nginx 等环境也有效）

#### 常量说明

| 常量 | 定义位置 | 用途 |
|------|---------|------|
| `NOVA_BOOTSTRAP` | `index.php` / `admin-bootstrap.php` | 访问控制 — 表示请求经过框架 |
| `NOVA_API` | `init.php` | 业务逻辑 — 区分是否为 API 请求（仅 `/nova-json` 路径） |

---

### 4.6 后台页面与菜单

#### 注册菜单

```php
// 顶级菜单
Nova_Backend_Menu::add_menu(
    '插件名称',       // 菜单名
    'my-plugin',      // 唯一 ID
    '/admin/my-plugin.php', // URL
    '🔌',            // 图标
    30               // 位置
);

// 子菜单
Nova_Backend_Menu::add_submenu(
    'my-plugin',            // 父级菜单 ID
    '设置',                 // 子菜单名
    'my-plugin-settings',   // 唯一 ID
    '/admin/my-plugin-settings.php',
    10                      // 位置
);

// 带徽标的菜单
Nova_Backend_Menu::add_menu('消息', 'messages', '/admin/messages.php', '💬', 20, [
    'badge'      => '3',
    'badge_type' => 'danger',
]);
```

#### 创建后台页面

```php
class MyPluginSettingsPage extends Nova_Backend_Page {

    protected $page_title    = '插件设置';
    protected $menu_title    = '设置';
    protected $menu_id       = 'my-plugin-settings';
    protected $menu_icon     = '⚙';
    protected $menu_position = 10;
    protected $parent_menu   = 'my-plugin'; // 设为子菜单

    public function render() {
        $this->header();

        // 显示提示消息
        $this->success('设置已保存');

        // 卡片容器
        $this->card('基本配置');

        $this->formOpen(['method' => 'post', 'action' => '']);

        $this->formField('text', 'api_key', 'API Key', get_option('api_key'), [
            'placeholder' => '请输入 API Key',
            'help'        => '从第三方服务获取的 API 密钥',
        ]);

        $this->formField('select', 'theme', '主题样式', get_option('theme'), [
            'options' => [
                'light' => '浅色模式',
                'dark'  => '深色模式',
            ],
        ]);

        $this->formField('switch', 'enable_notify', '', get_option('enable_notify'), [
            'checkbox_label' => '启用通知',
        ]);

        $this->formField('textarea', 'custom_css', '自定义 CSS', get_option('custom_css'), [
            'class' => 'form-control font-monospace',
            'help'  => '输入自定义样式代码',
        ]);

        $this->submitButton('保存配置');
        $this->formClose();
        $this->endCard();

        $this->footer();
    }
}
new MyPluginSettingsPage();
```

#### 数据表格

```php
class MyDataTable extends Nova_Backend_List_Table {
    public function prepareItems() {
        $db = new Nova_DB();
        $this->data  = $db->get_results("SELECT * FROM my_plugin_data ORDER BY id DESC");
        $this->total = $db->get_var("SELECT COUNT(*) FROM my_plugin_data");
    }

    public function column($row, $col) {
        if ($col === 'actions') {
            return '<a href="?delete=' . $row['id'] . '" class="btn btn-sm btn-danger">删除</a>';
        }
        return htmlspecialchars($row[$col] ?? '');
    }
}

$table = new MyDataTable([
    'per_page'     => 20,
    'columns'      => [
        'id'    => 'ID',
        'key'   => '键名',
        'value' => '值',
        'created_at' => '创建时间',
    ],
    'search'       => true,
    'bulk_actions' => ['delete' => '删除选中'],
    'sortable'     => ['id', 'created_at'],
]);
$table->render();
```

---

### 4.6 文件上传与图片处理

#### 上传文件

```php
$upload = new Nova_Upload($_FILES['file']);
$upload->allowedTypes(['jpg', 'png', 'gif', 'pdf'])
       ->maxSize(5 * 1024 * 1024)  // 5MB
       ->subDir('my-plugin')
       ->prefix('plugin_');

if ($upload->validate()) {
    $result = $upload->save();
    // $result['url'] => '/uploads/my-plugin/plugin_abc123.jpg'
    echo '文件已上传：' . $result['url'];
} else {
    echo '上传失败：' . $upload->getError();
}
```

#### 图片处理

```php
try {
    $img = new Nova_Image('path/to/image.jpg');

    // 生成缩略图
    $img->thumb(300, 200)->save('path/to/thumb.jpg');

    // 添加水印
    $img->watermark('path/to/watermark.png', 'bottom-right', 80)
        ->save('path/to/watermarked.jpg');

    // 格式转换
    $img->saveAsWebp('path/to/image.webp');

} catch (Exception $e) {
    echo '图片处理错误：' . $e->getMessage();
}
```

---

## 5. API 参考

### 5.1 Nova_Plugin

插件基类，所有插件应继承此类。

**文件**: `vendor/nova-json/class/plugin/class-plugin.php`

**属性**

| 属性 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `$name` | string | '' | 插件名称，自动识别目录名 |
| `$version` | string | '1.0.0' | 插件版本号 |
| `$plugin_path` | string | '' | 插件目录绝对路径（自动检测） |
| `$plugin_url` | string | '' | 可公开访问的插件 URL |

**方法**

| 方法 | 签名 | 说明 |
|------|------|------|
| `__construct()` | `void` | 构造函数：自动检测路径、加载 routes/ 目录、注册 `init()` |
| `init()` | `void` | 初始化入口，子类重写此方法 |
| `register_route()` | `(namespace, route, args): void` | 注册 REST 路由 |
| `db()` | `(): Nova_DB` | 获取数据库实例 |
| `log()` | `(message, level): void` | 写入插件日志 |

---

### 5.2 Nova_Hooks

钩子系统，管理 Actions 和 Filters。

**文件**: `vendor/nova-json/class/system/class-hooks.php`

**静态方法**

| 方法 | 签名 | 说明 |
|------|------|------|
| `add_action()` | `(tag, callback, priority=10)` | 注册动作 |
| `do_action()` | `(tag, ...args)` | 执行动作 |
| `remove_action()` | `(tag, callback, priority=10)` | 移除动作 |
| `has_action()` | `(tag, callback=null): bool` | 检查动作 |
| `add_filter()` | `(tag, callback, priority=10)` | 注册过滤器 |
| `apply_filters()` | `(tag, value, ...args): mixed` | 执行过滤器 |
| `remove_filter()` | `(tag, callback, priority=10)` | 移除过滤器 |
| `has_filter()` | `(tag, callback=null): bool` | 检查过滤器 |

---

### 5.3 register_rest_route

全局函数，注册 REST 路由。

**文件**: `vendor/nova-json/class/rest/class-server.php`

```php
register_rest_route(
    string $namespace,  // 命名空间，如 'v1'
    string $route,      // 路由路径，如 '/my-plugin/data'
    array  $args,       // 路由配置
    bool   $override = false // 是否覆盖已有路由
);
```

**$args 参数说明**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `methods` | string/array | 是 | HTTP 方法 (GET/POST/PUT/DELETE) |
| `callback` | callable | 是 | 请求处理回调 |
| `permission_callback` | callable | 否 | 权限验证回调 |
| `args` | array | 否 | 参数验证规则 |

---

### 5.4 Nova_DB

数据库操作封装。

**文件**: `vendor/nova-json/class/database/class-db.php`

**方法**

| 方法 | 说明 |
|------|------|
| `get_var(sql, params)` | 获取单行单列值 |
| `get_row(sql, params)` | 获取单行关联数组 |
| `get_results(sql, params)` | 获取多行数组 |
| `insert(table, data)` | 插入并返回自增ID |
| `update(table, data, where)` | 更新，返回行数 |
| `delete(table, where)` | 删除，返回行数 |
| `begin()` | 开启事务 |
| `commit()` | 提交事务 |
| `rollback()` | 回滚事务 |

---

### 5.5 Nova_Backend_Page

后台页面基类。

**文件**: `vendor/nova-json/class/backend/class-backend-page.php`

**关键方法**

| 方法 | 说明 |
|------|------|
| `header()` | 输出页面头部(标题+面包屑) |
| `footer()` | 输出页面尾部(JS) |
| `card(title, class)` | 卡片容器开始 |
| `endCard()` | 卡片结束 |
| `alert(type, message)` | 显示提示框 |
| `table(headers, rows, options)` | 渲染数据表格 |
| `pagination(total, perPage, current, baseUrl)` | 分页HTML |
| `searchBox(keyword, placeholder)` | 搜索框 |
| `formOpen(options)` | 表单开始 |
| `formClose()` | 表单结束 |
| `formField(type, name, label, value, options)` | 表单字段 |
| `submitButton(text, class)` | 提交按钮 |

---

### 5.6 Nova_Backend_Menu

后台菜单管理。

**文件**: `vendor/nova-json/class/backend/class-backend-menu.php`

```php
// 注册顶级菜单
Nova_Backend_Menu::add_menu(
    string $title,     // 显示名称
    string $id,        // 唯一标识
    string $url,       // 链接地址
    string $icon = '', // 图标
    int    $position = 50,
    array  $options = []
);

// 注册子菜单
Nova_Backend_Menu::add_submenu(
    string $parent_id,
    string $title,
    string $id,
    string $url,
    int    $position = 10,
    array  $options = []
);

// 渲染菜单
Nova_Backend_Menu::render();
```

---

### 5.7 Nova_Backend_Ajax

后台 AJAX 处理器。

**文件**: `vendor/nova-json/class/backend/class-backend-ajax.php`

```php
// 注册需认证的 AJAX 处理器
Nova_Backend_Ajax::add(
    string   $action,     // 操作名
    callable $callback,   // 处理回调
    bool     $needAuth = true,
    string   $method = 'POST'
);

// 注册公开 AJAX 处理器（无需登录）
Nova_Backend_Ajax::addPublic(
    string   $action,
    callable $callback,
    string   $method = 'POST'
);
```

---

### 5.8 Nova_Backend_Notice

后台通知消息管理。

**文件**: `vendor/nova-json/class/backend/class-backend-notice.php`

```php
// 添加通知
Nova_Backend_Notice::success('操作成功');
Nova_Backend_Notice::error('操作失败', true); // true=持久化到Session
Nova_Backend_Notice::warning('请注意');
Nova_Backend_Notice::info('提示信息');

// 渲染输出
Nova_Backend_Notice::render();
```

---

### 5.9 Nova_API

内部 API 调用（零网络开销）。

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
$result = Nova_API::put('/v1/statuses/guestbook/1/reply', [
    'reply_content' => '谢谢',
]);

// DELETE 请求
$result = Nova_API::delete('/v1/statuses/shuoshuo/5');
```

---

### 5.10 Nova_Proxy

代理请求类（公网 + 内部）。仅供插件/主题在 PHP 层调用，**不暴露为 HTTP 端点**。

**文件**: `vendor/nova-json/class/rest/class-proxy.php`

调用来源校验通过 `debug_backtrace` 实现，只有调用栈中存在来自 `nova-plugins/` 或 `nova-themes/` 目录的帧才放行，否则抛出 `RuntimeException`。内置 SSRF 防护：仅允许公网 http/https、禁止内网/环回地址、DNS 固定解析防 Rebinding、超时与响应体大小限制。

**静态方法**

| 方法 | 签名 | 说明 |
|------|------|------|
| `request()` | `(url, method='GET', options=[]): array/Response` | 公网代理请求外部 URL |
| `internal()` | `(routeOrUrl, method='GET', params=[]): array/Response/string` | 内部代理调度本地 API（零网络开销） |

**request 选项 (options)**

| 键 | 类型 | 默认值 | 说明 |
|----|------|--------|------|
| `headers` | array | `[]` | 自定义请求头 |
| `body` | mixed | `null` | 请求体（string/array） |
| `timeout` | int | `10` | 超时秒数（1-30） |

**示例**

```php
// 公网代理 — 请求外部 API（在插件/主题内调用）
$resp = Nova_Proxy::request('https://api.github.com', 'GET', [
    'headers' => ['Accept' => 'application/json'],
    'timeout' => 10,
]);
// $resp['data']['body'] 为外部 API 返回的 JSON 解码结果

// 公网代理 — POST 请求
$resp = Nova_Proxy::request('https://api.example.com/webhook', 'POST', [
    'headers' => ['Content-Type' => 'application/json'],
    'body'    => ['event' => 'plugin_activated'],
]);

// 内部代理 — 调度本地 API 端点（零网络开销）
$data = Nova_Proxy::internal('/v1/posts', 'GET', ['per_page' => 5]);

// 内部代理 — 也可传完整 URL，自动解析为本地请求
$data = Nova_Proxy::internal('https://你的域名/nova-json/v1/posts', 'GET');

// 内部代理 — POST 调度
$result = Nova_Proxy::internal('/v1/statuses/guestbook', 'POST', [
    'nickname' => 'Test',
    'content'  => 'Hello',
]);
```

> **注意**: 在 `nova-plugins/` 或 `nova-themes/` 目录外的代码（如 `routes/` 路由文件、根目录脚本）调用 `Nova_Proxy` 会抛出 `RuntimeException`。

---

## 6. 案例和最佳实践

### 6.1 示例一：文章统计插件

在文章详情中添加阅读时长统计和关键词提取功能。

```php
class PostStatsPlugin extends Nova_Plugin {

    protected $name = 'post-stats';

    public function init() {
        // 通过过滤器扩展文章数据
        Nova_Hooks::add_filter('nova_post_data', [$this, 'addStats']);
    }

    public function addStats($data) {
        if (empty($data['content'])) return $data;

        $content = strip_tags($data['content']);

        // 计算阅读时长（中文约每分钟 300 字）
        $charCount = mb_strlen($content);
        $readTime = max(1, ceil($charCount / 300));

        // 提取关键词（简单取前 5 个出现次数最多的词）
        $words = $this->extractKeywords($content, 5);

        // 附加到文章数据
        $data['stats'] = [
            'char_count'    => $charCount,
            'read_time'     => $readTime . ' 分钟',
            'keywords'      => $words,
            'image_count'   => substr_count($data['content'], '<img'),
        ];

        return $data;
    }

    private function extractKeywords($text, $limit = 5) {
        // 简单的关键词提取实现
        $stopWords = ['的', '了', '在', '是', '我', '有', '和', '就', '不', '人'];
        $text = str_replace($stopWords, ' ', $text);
        $words = array_filter(explode(' ', $text));
        $counts = array_count_values($words);
        arsort($counts);
        return array_slice(array_keys($counts), 0, $limit);
    }
}
new PostStatsPlugin();
```

**预期效果：**

```json
{
    "id": 1,
    "title": "文章标题",
    "content": "文章内容...",
    "stats": {
        "char_count": 1520,
        "read_time": "5 分钟",
        "keywords": ["PHP", "编程", "入门", "教程", "开发"],
        "image_count": 3
    }
}
```

---

### 6.2 示例二：内容审核插件

为评论系统添加自动内容审核功能。

```php
class ContentModerationPlugin extends Nova_Plugin {

    protected $name = 'content-moderation';

    private $badWords = ['垃圾广告', '恶意链接', '敏感词1', '敏感词2'];

    public function init() {
        // 在评论添加前进行审核
        Nova_Hooks::add_filter('nova_comment_data', [$this, 'moderateComment']);
    }

    /**
     * 审核评论内容
     */
    public function moderateComment($commentData) {
        if (empty($commentData['content'])) return $commentData;

        $content = $commentData['content'];

        // 检查敏感词
        foreach ($this->badWords as $word) {
            if (mb_stripos($content, $word) !== false) {
                $commentData['status'] = 'pending'; // 标记为待审核
                $commentData['moderation_reason'] = '包含敏感内容';
                return $commentData;
            }
        }

        // 检查 URL 数量（垃圾评论通常含多个链接）
        $urlCount = preg_match_all('/https?:\/\/[^\s]+/', $content);
        if ($urlCount > 3) {
            $commentData['status'] = 'pending';
            $commentData['moderation_reason'] = '包含过多外部链接';
            return $commentData;
        }

        // 检查重复评论
        $db = new Nova_DB();
        $similar = $db->get_var(
            "SELECT COUNT(*) FROM comments WHERE content LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            ['%' . mb_substr($content, 0, 30) . '%']
        );
        if ($similar > 5) {
            $commentData['status'] = 'spam';
            $commentData['moderation_reason'] = '短时间内重复内容';
            return $commentData;
        }

        // 默认通过
        $commentData['status'] = 'approved';
        return $commentData;
    }
}
new ContentModerationPlugin();
```

---

### 6.3 示例三：自定义短代码插件

在文章内容中支持 `[gallery]` 短代码。

```php
class ShortcodePlugin extends Nova_Plugin {

    protected $name = 'shortcodes';

    public function init() {
        Nova_Hooks::add_filter('nova_post_content', [$this, 'parseShortcodes']);
    }

    public function parseShortcodes($content) {
        // 处理 [gallery ids="1,2,3"]
        $content = preg_replace_callback(
            '/\[gallery\s+ids="([^"]+)"\]/',
            [$this, 'renderGallery'],
            $content
        );

        // 处理 [button url="https://..." text="点击"]
        $content = preg_replace_callback(
            '/\[button\s+url="([^"]+)"\s+text="([^"]+)"\]/',
            [$this, 'renderButton'],
            $content
        );

        // 处理 [highlight]文字[/highlight]
        $content = preg_replace_callback(
            '/\[highlight\](.*?)\[\/highlight\]/',
            [$this, 'renderHighlight'],
            $content
        );

        return $content;
    }

    private function renderGallery($matches) {
        $ids = explode(',', $matches[1]);
        $html = '<div class="plugin-gallery">';
        foreach ($ids as $id) {
            $imgSrc = "/uploads/gallery/photo{$id}.jpg";
            $html .= "<img src=\"{$imgSrc}\" alt=\"\" class=\"gallery-item\">";
        }
        $html .= '</div>';
        return $html;
    }

    private function renderButton($matches) {
        $url = htmlspecialchars($matches[1]);
        $text = htmlspecialchars($matches[2]);
        return "<a href=\"{$url}\" class=\"btn btn-primary plugin-button\">{$text}</a>";
    }

    private function renderHighlight($matches) {
        $text = htmlspecialchars($matches[1]);
        return "<mark class=\"plugin-highlight\">{$text}</mark>";
    }
}
new ShortcodePlugin();
```

**文章内容：**

```
这是一篇测试文章。

[gallery ids="1,2,3,4"]

更多内容请 [button url="https://example.com" text="查看详情"]。

请注意这段 [highlight]重要文字[/highlight] 是高亮的。
```

**渲染结果：**

```html
这是一篇测试文章。

<div class="plugin-gallery">
    <img src="/uploads/gallery/photo1.jpg" alt="" class="gallery-item">
    <img src="/uploads/gallery/photo2.jpg" alt="" class="gallery-item">
    <img src="/uploads/gallery/photo3.jpg" alt="" class="gallery-item">
    <img src="/uploads/gallery/photo4.jpg" alt="" class="gallery-item">
</div>

更多内容请 <a href="https://example.com" class="btn btn-primary plugin-button">查看详情</a>。

请注意这段 <mark class="plugin-highlight">重要文字</mark> 是高亮的。
```

---

## 附录

### 插件开发检查清单

- [ ] 创建插件目录 `vendor/nova-plugins/{plugin-name}/`
- [ ] 创建 `plugin.json` 元数据文件（**id 必须为英文且唯一**，排在 name 前；name、version、entry 等字段）
- [ ] 创建 `plugin/plugin.php` 入口文件
- [ ] 创建插件主类，继承 `Nova_Plugin`
- [ ] 在 `init()` 方法中注册路由、钩子、菜单
- [ ] 如果需要自定义页面路由（如 `/rss.xml`），在 `plugin.json` 中声明 `page_routes`
- [ ] 如果需要配置表单，创建 `config.json`（声明式配置，自动渲染到插件详情页）
- [ ] 如果需要数据库表，使用 `Nova_DB_Schema` 创建
- [ ] 如果需要后台页面，继承 `Nova_Backend_Page`
- [ ] 提供 `LICENSE` 许可证文件
- [ ] 使用 `Nova_Hooks` 进行扩展，而非修改核心文件
- [ ] 使用 `Nova_DB` 的参数绑定查询防止 SQL 注入

### 文件说明

| 文件路径 | 说明 |
|----------|------|
| `vendor/nova-plugins/{name}/plugin.json` | 插件元数据（含英文 id） |
| `vendor/nova-plugins/{name}/config.json` | 插件配置表单定义及存储（可选） |
| `vendor/nova-plugins/{name}/plugin/plugin.php` | 插件入口文件（默认 entry） |
| `vendor/nova-plugins/{name}/plugin/class-{name}.php` | 插件主类文件 |
| `vendor/nova-plugins/{name}/plugin/routes/*.php` | REST 路由文件（自动加载） |
| `vendor/nova-plugins/{name}/plugin/views/*.php` | 后台视图模板 |
| `vendor/nova-plugins/{name}/plugin/admin/*.php` | 后台管理页面 |
| `vendor/nova-plugins/{name}/assets/` | 静态资源目录（可选） |
| `vendor/nova-plugins/{name}/LICENSE` | 许可证文件 |
| `vendor/nova-plugins/{name}/data/` | 运行时数据目录（可选） |

### 版本兼容性

| NovaCMS 版本 | 插件 API 版本 | 变更说明 |
|-------------|--------------|----------|
| 1.0+ | 1.0 | 初始版本 |
| 1.1+ | 1.1 | 引入 `plugin.json` 元数据 + `plugin/` 子目录规范；`id` 由开发者手动填写（必须为英文），排在 `name` 之前；启用/禁用以 `id` 为准；支持 `page_routes` 自定义页面路由；支持 `config.json` 声明式配置表单 |

---

> 最后更新：2026-08-10
