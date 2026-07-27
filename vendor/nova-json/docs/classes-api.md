# Nova Classes API 文档

Nova JSON API 的核心类，模仿 WordPress REST API 设计。

---

## 目录

- [Nova_REST_Request](#nova_rest_request)
- [Nova_REST_Response](#nova_rest_response)
- [Nova_REST_Server](#nova_rest_server)
- [Nova_DB](#nova_db)
- [Nova_Hooks](#nova_hooks)
- [Nova_API](#nova_api)
- [Nova_Plugin](#nova_plugin)
- [Nova_Theme](#nova_theme)
- [全局函数](#全局函数)
- [数据流](#数据流)

---

## Nova_REST_Request

封装 HTTP 请求数据，提供统一的参数访问接口。

### 构造函数

```
__construct($method, $route)
```

| 参数 | 类型 | 说明 |
|------|------|------|
| `method` | string | HTTP 方法（GET/POST/PUT/DELETE 等），内部自动转为大写 |
| `route` | string | 路由路径 |

### 方法

#### 获取请求信息

| 方法 | 返回类型 | 说明 |
|------|---------|------|
| `get_method()` | string | 获取 HTTP 方法 |
| `get_route()` | string | 获取路由路径 |
| `get_body()` | string/null | 获取原始请求体（`php://input`） |

#### 设置请求参数（由 Server 内部调用）

| 方法 | 参数 | 说明 |
|------|------|------|
| `set_query_params($params)` | array | 设置 URL 查询参数（`$_GET`） |
| `set_body_params($params)` | array | 设置请求体参数，JSON 自动解析为数组，否则为 `$_POST` |
| `set_file_params($params)` | array | 设置上传文件参数（`$_FILES`） |
| `set_url_params($params)` | array | 设置 URL 路径参数（由路由匹配提取） |
| `set_headers($headers)` | array | 设置请求头 |
| `set_body($body)` | string | 设置原始请求体 |

#### 获取参数

**`get_param($key)`** — 按优先级从 URL 参数 → 查询参数 → 请求体 → 文件参数中查找指定键。

```php
$request->get_param('id');    // 获取任意来源的参数
$request->get_param('page');  // 获取分页参数
```

**`get_params()`** — 合并所有来源的参数。

| 来源 | 优先级 | 说明 |
|------|--------|------|
| `url` | 最高 | URL 路径参数（如 `{id}`） |
| `query` | 次高 | URL 查询字符串（`?page=1`） |
| `body` | 次低 | 请求体参数 |
| `file` | 最低 | 上传文件参数 |

#### 获取请求头

| 方法 | 返回 | 说明 |
|------|------|------|
| `get_header($key)` | string/null | 获取指定请求头（键名不区分大小写） |
| `get_headers()` | array | 获取所有请求头 |

---

## Nova_REST_Response

封装 HTTP 响应数据，提供统一的 JSON 输出格式。

### 构造函数

```
__construct($data = null, $status = 200)
```

| 参数 | 类型 | 说明 |
|------|------|------|
| `data` | mixed | 响应数据，通常是数组 |
| `status` | int | HTTP 状态码，默认 200 |

### 方法

| 方法 | 参数 | 返回 | 说明 |
|------|------|------|------|
| `set_status($status)` | int | void | 设置 HTTP 状态码 |
| `get_status()` | — | int | 获取 HTTP 状态码 |
| `set_header($key, $value)` | string, string | void | 设置响应头 |
| `get_headers()` | — | array | 获取所有响应头 |
| `get_data()` | — | mixed | 获取响应数据 |
| `to_json()` | — | string | 输出 JSON 格式字符串（UNICODE 转义+美化缩进） |

**to_json 输出示例：**

```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": {
    "status": 200,
    "items": []
  }
}
```

---

## Nova_REST_Server

核心路由引擎，负责路由注册、请求匹配和分发。

### 常量

| 常量 | 值 | 说明 |
|------|----|------|
| `READABLE` | `GET` | 可读方法 |
| `CREATABLE` | `POST` | 可创建方法 |
| `EDITABLE` | `POST, PUT, PATCH` | 可编辑方法 |
| `DELETABLE` | `DELETE` | 可删除方法 |
| `ALLMETHODS` | `GET, POST, PUT, PATCH, DELETE` | 所有方法 |

### 方法

#### 路由注册

**`register_route($namespace, $route, $args, $override = false)`**

注册一条 REST 路由。

| 参数 | 类型 | 说明 |
|------|------|------|
| `namespace` | string | 命名空间（如 `v1`） |
| `route` | string | 路由路径（如 `/posts/{id}`），支持 `{param}` 占位符 |
| `args` | array | 路由配置，见下方 |
| `override` | bool | 是否覆盖已存在的路由，默认 false |

**`$args` 配置结构：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `methods` | string/array | 是 | HTTP 方法，如 `'GET'`、`'POST'`、`'GET, POST'` |
| `callback` | callable | 是 | 处理函数，接收 `Nova_REST_Request` 参数 |
| `permission_callback` | callable | 否 | 权限检查函数，返回 `true` 允许，否则 403 |
| `args` | array | 否 | 参数定义（预留） |

注册命名空间时会自动注册 `GET /{namespace}` 端点，返回该命名空间下的路由列表。

#### 路由查询

| 方法 | 参数 | 返回 | 说明 |
|------|------|------|------|
| `get_namespaces()` | — | string[] | 获取所有已注册的命名空间 |
| `get_routes($namespace = '')` | string | array | 获取路由列表，可选按命名空间过滤。返回的键为正则表达式模式 |

#### 请求处理

**`serve_request($path = null)`**

入口方法，处理 HTTP 请求并返回响应。

处理流程：
1. 创建 `Nova_REST_Request` 实例，注入请求数据
2. 解析 `Content-Type`：JSON 自动解析 body，否则使用 `$_POST`
3. 收集请求头
4. 执行 `rest_api_init` 钩子（触发所有 `register_rest_route()` 注册）
5. 调用 `dispatch()` 分发请求

**`dispatch($request)`**

匹配路由并执行回调。

处理流程：
1. 调用 `match_request_to_handler()` 匹配路由
2. 提取 URL 路径参数（如 `{id}`）
3. 如果有 `permission_callback`，先执行权限检查
4. 执行 `callback` 并包装为 `Nova_REST_Response`

**`match_request_to_handler($request)`**

内部方法，将请求匹配到已注册的路由处理器。

匹配规则：
1. 根据请求路径确定命名空间
2. 遍历该命名空间下的所有路由
3. 将路由中的 `{param}` 转换为正则 `(?P<param>[^/]+)`
4. 匹配 HTTP 方法
5. 返回匹配结果或 404 错误响应

#### 辅助方法

| 方法 | 说明 |
|------|------|
| `ensure_response($result)` | 确保返回值为 `Nova_REST_Response` 实例 |
| `error_to_response($error)` | 将错误信息转换为 `Nova_REST_Response` |
| `get_namespace_index($request)` | 返回命名空间下的路由索引 |

#### 钩子系统

**`add_init_hook($callback)`**（静态方法）

注册一个 `rest_api_init` 钩子，在 `serve_request()` 中被调用。

```php
Nova_REST_Server::add_init_hook(function($server) {
    $server->register_route('v1', '/my-route', [
        'methods'  => 'GET',
        'callback' => 'my_handler',
    ]);
});
```

**`do_rest_api_init()`**

执行所有已注册的 `rest_api_init` 钩子。

---

## Nova_DB

数据库读写封装类，提供 CRUD 及表管理操作。

### 构造函数

```
new Nova_DB()
```

内部调用 `getDB()` 获取 PDO 实例。

### 查询方法

| 方法 | 参数 | 返回 | 说明 |
|------|------|------|------|
| `get_var($sql, $params)` | string, array | string/null | 获取单行单列的值 |
| `get_row($sql, $params)` | string, array | array/null | 获取单行（关联数组） |
| `get_results($sql, $params)` | string, array | array | 获取多行 |

示例：
```php
$db = new Nova_DB();

$count = $db->get_var("SELECT COUNT(*) FROM shuoshuo");
$row   = $db->get_row("SELECT * FROM admins WHERE id = ?", [1]);
$items = $db->get_results("SELECT * FROM shuoshuo ORDER BY id DESC");
```

### 写入方法

| 方法 | 参数 | 返回 | 说明 |
|------|------|------|------|
| `insert($table, $data)` | string, array | int | 插入一行，返回新 ID |
| `insert_batch($table, $rows)` | string, array | int | 批量插入，返回行数 |
| `update($table, $data, $where)` | string, array, array | int | 更新，返回影响行数 |
| `delete($table, $where)` | string, array | int | 删除，返回影响行数 |

示例：
```php
$db = new Nova_DB();

$id = $db->insert('shuoshuo', [
    'content'    => '今日分享',
    'created_at' => date('Y-m-d H:i:s'),
]);

$db->update('shuoshuo', ['content' => '已修改'], ['id' => $id]);

$db->delete('shuoshuo', ['id' => $id]);
```

### 表管理

| 方法 | 参数 | 说明 |
|------|------|------|
| `create_table($table, $columns)` | string, array | 创建表（`IF NOT EXISTS`） |
| `drop_table($table)` | string | 删除表 |
| `table_exists($table)` | string | 检查表是否存在 |
| `truncate($table)` | string | 清空表数据 |
| `add_column($table, $column, $definition)` | string, string, string | 新增字段（自动检测避免重复） |

示例：
```php
$db = new Nova_DB();

$db->create_table('my_plugin_data', [
    'id INT AUTO_INCREMENT PRIMARY KEY',
    'name VARCHAR(100) NOT NULL',
    'content TEXT',
    'created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
]);

if (!$db->table_exists('my_plugin_data')) {
    // 表不存在时的处理
}

$db->add_column('website_config', 'my_setting', "TEXT COMMENT '插件配置' AFTER id");
```

### 事务支持

```php
$db->begin();
try {
    $db->insert('table_a', [...]);
    $db->update('table_b', [...], ['id' => 1]);
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
}
```

### 其他方法

| 方法 | 说明 |
|------|------|
| `insert_id()` | 获取最后插入 ID |
| `query($sql)` | 执行 DDL/无参数 SQL |
| `raw_query($sql, $params)` | 返回 PDOStatement（高级用法） |
| `get_pdo()` | 获取原生 PDO 实例 |

---

## Nova_Hooks

Actions & Filters 钩子系统，类似 WordPress 的 `add_action` / `add_filter`。

### Actions（动作钩子）

| 方法 | 参数 | 说明 |
|------|------|------|
| `add_action($tag, $callback, $priority)` | string, callable, int | 注册动作（priority 默认 10） |
| `do_action($tag, ...$args)` | string, mixed | 执行动作 |
| `remove_action($tag, $callback, $priority)` | string, callable, int | 移除动作 |
| `has_action($tag, $callback)` | string, callable/null | 检查动作是否已注册 |

```php
Nova_Hooks::add_action('nova_init', function() {
    error_log('系统初始化完成');
});

Nova_Hooks::do_action('nova_init');
```

### Filters（过滤器钩子）

| 方法 | 参数 | 说明 |
|------|------|------|
| `add_filter($tag, $callback, $priority)` | string, callable, int | 注册过滤器（callback 必须 return $value） |
| `apply_filters($tag, $value, ...$args)` | string, mixed, mixed | 执行过滤器，返回过滤后的值 |
| `remove_filter($tag, $callback, $priority)` | string, callable, int | 移除过滤器 |
| `has_filter($tag, $callback)` | string, callable/null | 检查过滤器是否已注册 |

```php
Nova_Hooks::add_filter('nova_post_data', function($data) {
    $data['extra'] = '自定义字段';
    return $data;
});

$post = Nova_Hooks::apply_filters('nova_post_data', $original);
```

---

## Nova_API

内部 API 调用类。插件和主题可以直接调用已注册的 REST 端点，无需 HTTP 请求。

### 静态方法

| 方法 | 参数 | 说明 |
|------|------|------|
| `get($route, $params)` | string, array | GET 请求 |
| `post($route, $data)` | string, array | POST 请求 |
| `put($route, $data)` | string, array | PUT 请求 |
| `delete($route)` | string | DELETE 请求 |

### 示例

```php
// 获取说说列表
$shuoshuo = Nova_API::get('/v1/statuses/shuoshuo', ['per_page' => 10]);

// 发布评论
$result = Nova_API::post('/v1/posts/comments', [
    'post_id' => 1,
    'content' => '好文章！',
]);

// 删除日志（管理员）
$result = Nova_API::delete('/v1/statuses/logs/3');

// 获取文章列表
$posts = Nova_API::get('/v1/posts', ['category_id' => 2]);
```

所有请求内部走 Server->dispatch()，返回的数据结构与直接请求 API 完全一致：
```json
{
  "code": "rest_ok",
  "message": "获取成功",
  "data": { ... }
}
```

---

## Nova_Plugin

插件基类。所有插件继承此类后自动获得：路径检测、路由加载、数据库访问。

### 用法

```php
class HitokotoPlugin extends Nova_Plugin {
    public function init() {
        $this->register_route('v1', '/hitokoto', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_hitokoto'],
        ]);
    }

    public function get_hitokoto($request) {
        $db = $this->db();
        $count = $db->get_var("SELECT COUNT(*) FROM hitokoto");
        return ['count' => $count];
    }
}
new HitokotoPlugin();
```

### 目录结构

```
plugins/my-plugin/
  plugin.php          主文件（class extends Nova_Plugin）
  routes/             路由文件（自动加载）
  assets/             静态资源
  views/              模板文件
```

### 内置方法

| 方法 | 说明 |
|------|------|
| `register_route($ns, $route, $args)` | 注册 REST 路由 |
| `db()` | 获取 `Nova_DB` 实例 |
| `log($message, $level)` | 写入插件日志 |
| `$this->name` | 插件名称 |
| `$this->version` | 插件版本 |
| `$this->plugin_path` | 插件绝对路径 |
| `$this->plugin_url` | 插件 URL |

---

## Nova_Theme

主题基类。所有主题继承此类后自动获得：布局渲染、资源管理、路由注册。

### 用法

```php
class MyTheme extends Nova_Theme {
    protected $name = 'my-theme';
    public function init() {
        $this->set_layout('default');
    }
}
new MyTheme();
```

### 目录结构

```
vendor/nova-themes/my-theme/
  theme.php            主文件（class extends Nova_Theme）
  views/               模板文件（.php）
  routes/              路由文件（自动加载）
  assets/              静态资源（CSS/JS/图片）
```

### 内置方法

| 方法 | 说明 |
|------|------|
| `set_layout($name)` | 设置布局模板 |
| `render($template, $data)` | 渲染 `views/{template}.php` 模板，$data 自动解包为变量 |
| `register_route($ns, $route, $args)` | 注册 REST 路由 |
| `asset($path)` | 生成 `assets/` 下的资源 URL |
| `db()` | 获取 `Nova_DB` 实例 |

### 模板示例

```php
// theme.php 中：
$theme->render('index', ['posts' => $posts]);

// views/index.php 中：
// <h1><?= $title ?></h1>
// <?php foreach ($posts as $post): ?>
//   <div><?= $post['title'] ?></div>
// <?php endforeach; ?>
```

---

## 全局函数

### register_rest_route

```
register_rest_route($namespace, $route, $args, $override = false)
```

全局辅助函数，将路由注册延迟到 `rest_api_init` 阶段执行。

| 参数 | 类型 | 说明 |
|------|------|------|
| `namespace` | string | 命名空间 |
| `route` | string | 路由路径 |
| `args` | array | 路由配置 |
| `override` | bool | 是否覆盖 |

实现原理：将注册操作加入 `Nova_REST_Server` 的静态钩子队列，在 `serve_request()` 的 `do_rest_api_init()` 阶段统一执行。这样保证了所有路由文件加载完成后，再进行路由注册。

---

## 数据流

```
请求到达
    │
    ▼
Nova_REST_Server::serve_request()
    │
    ├─ 创建 Nova_REST_Request（注入 $_GET / $_POST / $_FILES / 请求头 / 原始 body）
    │
    ├─ do_rest_api_init()
    │   └─ 执行所有 register_rest_route() 注册的路由
    │
    ├─ dispatch()
    │   ├─ match_request_to_handler() → 匹配路由 + 提取 URL 参数
    │   ├─ permission_callback? → 权限检查
    │   └─ callback() → 执行业务逻辑
    │
    └─ Nova_REST_Response::to_json() → JSON 输出
```

### 路由匹配示例

| 注册路由 | 请求路径 | 匹配结果 |
|---------|---------|---------|
| `/v1/posts` | `/v1/posts` | 匹配，无参数 |
| `/v1/posts/{id}` | `/v1/posts/123` | 匹配，`id=123` |
| `/v1/posts/{id}` | `/v1/posts/abc` | 匹配，`id="abc"` |
| `/v1/posts/{id}` | `/v1/posts/` | 不匹配（`{id}` 需至少一个字符） |
| `/v1/posts` | `/v1/posts/123` | 不匹配（路径长度不同） |

### 错误码

| code | HTTP | 说明 |
|------|------|------|
| `rest_no_route` | 404 | 找不到匹配的路由 |
| `rest_forbidden` | 403 | 权限检查未通过 |
| `rest_invalid_handler` | 500 | 路由配置无效（缺少 callback） |
