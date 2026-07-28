# NovaCMS Class 类文档

> 命名空间: `Nova_*`  
> 语言: PHP  
> 依赖: PDO, GD 库

---

## 目录

1. [System - 系统核心](#1-system---系统核心)
2. [REST - API 路由引擎](#2-rest---api-路由引擎)
3. [Database - 数据库层](#3-database---数据库层)
4. [Filesystem - 文件系统](#4-filesystem---文件系统)
5. [Backend - 后台管理](#5-backend---后台管理)
6. [Plugin & Theme - 插件与主题](#6-plugin--theme---插件与主题)

---

## 1. System - 系统核心

### Nova_API

**文件**: `system/class-api.php`

内部 API 调用类，提供零网络开销的内部 REST 调用，插件可直接通过静态方法调用已注册的路由端点。

**主要方法**

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `get($route, $params=[])` | string route, array params | array/Response | 内部 GET 请求 |
| `post($route, $data=[])` | string route, array data | array/Response | 内部 POST 请求 |
| `put($route, $data=[])` | string route, array data | array/Response | 内部 PUT 请求 |
| `delete($route)` | string route | array/Response | 内部 DELETE 请求 |

**示例**

```php
// 获取最新文章
$posts = Nova_API::get('/v1/posts', ['per_page' => 5]);

// 提交留言
$result = Nova_API::post('/v1/statuses/guestbook', [
    'nickname' => 'Test',
    'content'  => 'Hello',
]);
```

---

### Nova_Hooks

**文件**: `system/class-hooks.php`

Actions & Filters 钩子系统，类似 WordPress 的事件机制。插件可通过钩子在特定时机执行代码或修改数据。

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `add_action($tag, $callback, $priority=10)` | tag, callback, priority | 注册动作钩子 |
| `do_action($tag, ...$args)` | tag, args | 执行动作钩子 |
| `remove_action($tag, $callback, $priority=10)` | tag, callback, priority | 移除动作钩子 |
| `has_action($tag, $callback=null)` | tag, callback | 检查动作是否已注册 |
| `add_filter($tag, $callback, $priority=10)` | tag, callback, priority | 注册过滤器钩子 |
| `apply_filters($tag, $value, ...$args)` | tag, value, args | 执行过滤器钩子 |
| `remove_filter($tag, $callback, $priority=10)` | tag, callback, priority | 移除过滤器钩子 |
| `has_filter($tag, $callback=null)` | tag, callback | 检查过滤器是否已注册 |
| `clear_all()` | - | 清空所有钩子(测试用) |

**示例**

```php
// 注册动作
Nova_Hooks::add_action('nova_init', function() {
    // 初始化逻辑
});

// 注册过滤器
Nova_Hooks::add_filter('nova_post_data', function($data) {
    $data['extra'] = '自定义数据';
    return $data;
});

// 触发
Nova_Hooks::do_action('nova_init');
$filtered = Nova_Hooks::apply_filters('nova_post_data', $originalData);
```

---

## 2. REST - API 路由引擎

### Nova_REST_Server

**文件**: `rest/class-server.php`

核心 REST 路由引擎，负责路由注册、请求分发、权限验证。类似 WordPress 的 WP_REST_Server。

**常量**

| 常量 | 值 | 说明 |
|------|-----|------|
| `READABLE` | GET | 可读 |
| `CREATABLE` | POST | 可创建 |
| `EDITABLE` | POST, PUT, PATCH | 可编辑 |
| `DELETABLE` | DELETE | 可删除 |
| `ALLMETHODS` | GET, POST, PUT, PATCH, DELETE | 全部方法 |

**主要方法**

| 方法 | 参数 | 说明 |
|------|------|------|
| `register_route($namespace, $route, $args, $override=false)` | namespace, route, args, override | 注册 REST 路由 |
| `get_namespaces()` | - | 获取所有命名空间 |
| `get_routes($namespace='')` | namespace | 获取路由列表 |
| `serve_request($path=null)` | path | 处理 HTTP 请求入口 |
| `dispatch($request)` | Nova_REST_Request | 分发请求到对应处理器 |
| `get_namespace_index($request)` | request | 获取命名空间下的路由索引 |
| `add_init_hook($callback)` | callback | 注册初始化钩子 |
| `do_rest_api_init()` | - | 执行所有初始化钩子 |

**全局函数**

```php
function register_rest_route($namespace, $route, $args, $override = false);
```

**路由注册示例**

```php
register_rest_route('v1', '/posts', [
    'methods'  => 'GET',
    'callback' => function($request) {
        return new Nova_REST_Response([
            'code' => 'rest_ok',
            'data' => ['items' => getPosts()],
        ]);
    },
    'permission_callback' => function($request) {
        return true; // 公开访问
    },
]);
```

---

### Nova_REST_Request

**文件**: `rest/class-request.php`

请求数据封装类，统一管理所有来源的请求参数。

**主要方法**

| 方法 | 参数 | 说明 |
|------|------|------|
| `__construct($method, $route)` | method, route | 构造函数 |
| `get_method()` | - | 获取请求方法 |
| `get_route()` | - | 获取路由路径 |
| `set_query_params($params)` | array | 设置 URL 查询参数 |
| `set_body_params($params)` | array | 设置请求体参数 |
| `set_file_params($params)` | array | 设置文件上传参数 |
| `set_url_params($params)` | array | 设置 URL 路径参数 |
| `set_headers($headers)` | array | 设置请求头 |
| `set_body($body)` | string | 设置原始请求体 |
| `get_param($key)` | string | 获取单个参数(自动遍历 url → query → body → file) |
| `get_params()` | - | 获取所有合并参数 |
| `get_header($key)` | string | 获取请求头 |
| `get_headers()` | - | 获取所有请求头 |
| `get_body()` | - | 获取原始请求体 |

---

### Nova_REST_Response

**文件**: `rest/class-response.php`

响应数据封装类，统一管理 HTTP 响应。

**主要方法**

| 方法 | 参数 | 说明 |
|------|------|------|
| `__construct($data=null, $status=200)` | data, status | 构造函数 |
| `set_status($status)` | int | 设置状态码 |
| `get_status()` | - | 获取状态码 |
| `set_header($key, $value)` | key, value | 设置响应头 |
| `get_headers()` | - | 获取所有响应头 |
| `get_data()` | - | 获取响应数据 |
| `to_json()` | - | 序列化为 JSON 字符串 |

---

## 3. Database - 数据库层

### Nova_DB

**文件**: `database/class-db.php`

基础数据库操作封装类，提供快捷的 CRUD 操作方法。

**方法说明**

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `get_var($sql, $params=[])` | sql, params | mixed | 获取单行单列值 |
| `get_row($sql, $params=[])` | sql, params | array/null | 获取单行数据(关联数组) |
| `get_results($sql, $params=[])` | sql, params | array | 获取多行数据 |
| `insert($table, $data)` | table, data | int | 插入一行，返回自增ID |
| `insert_batch($table, $rows)` | table, rows | int | 批量插入多行，返回行数 |
| `update($table, $data, $where)` | table, data, where | int | 更新数据，返回受影响行数 |
| `delete($table, $where)` | table, where | int | 删除数据，返回受影响行数 |
| `create_table($table, $columns)` | table, columns | bool | 创建表 |
| `drop_table($table)` | table | bool | 删除表 |
| `table_exists($table)` | table | bool | 检查表是否存在 |
| `truncate($table)` | table | bool | 清空表(重置自增ID) |
| `add_column($table, $column, $definition)` | table, column, definition | bool | 添加字段(不存在时) |
| `query($sql)` | sql | int | 执行原始 DDL 语句 |
| `raw_query($sql, $params=[])` | sql, params | PDOStatement | 执行原始查询 |
| `insert_id()` | - | int | 获取最后插入ID |
| `get_pdo()` | - | PDO | 获取原生PDO实例 |
| `begin()` | - | bool | 开启事务 |
| `commit()` | - | bool | 提交事务 |
| `rollback()` | - | bool | 回滚事务 |

**示例**

```php
$db = new Nova_DB();

// 查询
$count = $db->get_var("SELECT COUNT(*) FROM posts");
$post  = $db->get_row("SELECT * FROM posts WHERE id = ?", [1]);
$posts = $db->get_results("SELECT * FROM posts ORDER BY id DESC");

// 写入
$id = $db->insert('shuoshuo', [
    'content' => '你好',
    'created_at' => date('Y-m-d H:i:s'),
]);

// 事务
$db->begin();
$db->insert('logs', ['msg' => '操作1']);
$db->insert('logs', ['msg' => '操作2']);
$db->commit();
```

---

### Nova_DB_Query

**文件**: `database/class-db-query.php`

链式查询构建器，用 OOP 方式构建 SELECT 查询，支持 JOIN、WHERE、排序、分页、聚合等。

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `from($table)` / `table($table)` | string | 设置查询表名 |
| `select($columns)` | array/string | 设置要查询的字段 |
| `distinct($flag=true)` | bool | DISTINCT 查询 |
| `join($table, $on, $type='INNER')` | table, on, type | JOIN 子句 |
| `leftJoin($table, $on)` | table, on | LEFT JOIN |
| `rightJoin($table, $on)` | table, on | RIGHT JOIN |
| `where($column, $value, $op='=', $glue='AND')` | column, value, op, glue | WHERE 条件 |
| `orWhere($column, $value, $op='=')` | column, value, op | OR WHERE |
| `whereIn($column, $values)` | column, values | WHERE IN |
| `whereNotIn($column, $values)` | column, values | WHERE NOT IN |
| `whereBetween($column, $start, $end)` | column, start, end | WHERE BETWEEN |
| `whereNull($column)` | string | WHERE NULL |
| `whereNotNull($column)` | string | WHERE NOT NULL |
| `like($column, $value, $side='both')` | column, value, side | LIKE 查询(both/left/right) |
| `orLike($column, $value, $side='both')` | column, value, side | OR LIKE |
| `whereRaw($sql, $params=[])` | sql, params | 原始 WHERE |
| `orderBy($column, $direction='ASC')` | column, direction | 排序 |
| `orderByRaw($sql)` | string | 原始排序 |
| `groupBy($columns)` | array/string | 分组 |
| `having($column, $value, $op='=')` | column, value, op | HAVING |
| `limit($limit)` | int | 限制条数 |
| `offset($offset)` | int | 偏移量 |
| `page($page, $perPage=20)` | page, perPage | 分页 |
| `get()` | - | 获取多行结果 |
| `first()` | - | 获取第一行 |
| `value($column)` | string | 获取单列值 |
| `pluck($column)` | string | 获取单列值列表 |
| `count($column='*')` | string | 计数 |
| `sum($column)` | string | 求和 |
| `avg($column)` | string | 平均值 |
| `max($column)` | string | 最大值 |
| `min($column)` | string | 最小值 |
| `exists()` | - | 判断记录是否存在 |
| `doesntExist()` | - | 判断记录不存在 |
| `paginate($perPage=20, $page=null)` | perPage, page | 分页查询(返回分页信息) |
| `toSql()` | - | 获取构建的 SQL(调试) |
| `toSqlWithBindings()` | - | 获取 SQL 和绑定参数(调试) |

**示例**

```php
$q = new Nova_DB_Query();

// 基本查询
$results = $q->from('posts')
    ->where('status', 'publish')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// 单条
$row = $q->from('users')->where('id', 1)->first();

// 聚合
$count = $q->from('comments')->where('post_id', 1)->count();

// 分页
$page = $q->from('posts')
    ->where('category', '技术')
    ->paginate(20, 1);
// 返回: ['items'=>[], 'total'=>N, 'page'=>1, 'per_page'=>20, 'pages'=>N]
```

---

### Nova_DB_Schema

**文件**: `database/class-db-schema.php`

数据库表结构管理类，提供建表、改表、字段/索引管理等操作。

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `create($table, $columns, $options=[])` | table, columns, options | 创建表(engine/charset/collate/comment) |
| `drop($table)` | string | 删除表 |
| `truncate($table)` | string | 清空表 |
| `hasTable($table)` | string | 检查表是否存在 |
| `rename($oldName, $newName)` | oldName, newName | 重命名表 |
| `addColumn($table, $column, $definition)` | table, column, definition | 添加字段 |
| `modifyColumn($table, $column, $definition)` | table, column, definition | 修改字段 |
| `dropColumn($table, $column)` | table, column | 删除字段 |
| `hasColumn($table, $column)` | table, column | 检查字段是否存在 |
| `addIndex($table, $name, $columns, $type='INDEX')` | table, name, columns, type | 添加索引(INDEX/UNIQUE/PRIMARY/FULLTEXT) |
| `dropIndex($table, $name)` | table, name | 删除索引 |
| `getColumns($table)` | string | 获取表的字段信息 |
| `getIndexes($table)` | string | 获取表的索引信息 |
| `getCreateSql($table)` | string | 获取建表 SQL |
| `getTables($prefix='')` | string | 获取所有表名(可按前缀过滤) |

**示例**

```php
$schema = new Nova_DB_Schema();

// 创建表
$schema->create('my_table', [
    'id INT AUTO_INCREMENT PRIMARY KEY',
    'name VARCHAR(100) NOT NULL',
    'status TINYINT(1) DEFAULT 1',
    'created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
], [
    'engine'  => 'InnoDB',
    'comment' => '示例表',
]);

// 添加字段
$schema->addColumn('my_table', 'email', "VARCHAR(255) AFTER name");

// 添加索引
$schema->addIndex('my_table', 'idx_status', ['status'], 'INDEX');
```

---

### Nova_DB_Migration

**文件**: `database/class-db-migration.php`

数据库迁移管理类，提供版本化的数据库结构变更能力。

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `create($name, $callback)` | name, callback(Nova_DB_Schema) | 注册并执行迁移 |
| `run()` | - | 从迁移目录加载并执行待迁移文件 |
| `rollback($downCallback=null)` | downCallback | 回滚最后一批迁移 |
| `reset($downCallback=null)` | downCallback | 重置(回滚)全部迁移 |
| `status()` | - | 获取迁移状态(已执行/待执行) |
| `generate($name)` | string | 生成迁移文件模板 |
| `getRan()` | - | 获取已执行的迁移列表 |
| `removeRecord($migration)` | string | 删除迁移记录(不执行SQL回滚) |

**示例**

```php
$mig = new Nova_DB_Migration();

// 编程方式定义迁移
$mig->create('create_users_table', function($schema) {
    $schema->create('users', [
        'id INT AUTO_INCREMENT PRIMARY KEY',
        'username VARCHAR(50) NOT NULL',
        'email VARCHAR(255) NOT NULL',
        'created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
    ]);
});

// 从文件运行迁移
$count = $mig->run(); // 返回执行数量

// 回滚
$rolled = $mig->rollback();

// 查看状态
$status = $mig->status();
// ['ran' => [...], 'pending' => [...]]

// 生成迁移文件
$path = $mig->generate('add_email_to_users');
```

---

### Nova_DB_Seeder

**文件**: `database/class-db-seeder.php`

数据填充器，用于向数据库插入测试数据或默认数据。

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `table($table)` | string | 设置表名 |
| `columns($columns)` | array | 设置字段列表 |
| `count($count)` | int | 设置生成数量(generate 用) |
| `batchSize($size)` | int | 设置每批插入行数 |
| `seed($rows)` | array | 插入指定数据，返回行数 |
| `insert($data)` | array | 插入单行，返回插入ID |
| `generate($generator=null)` | callable | 使用生成器填充数据 |
| `truncate()` | - | 清空表并重置自增ID |
| `fresh($rows)` | array | 先清空再填充 |
| `seedWithSummary($rows)` | array | 填充并返回摘要信息 |
| `getTableColumns()` | - | 获取表的所有字段名 |
| `fromJson($filepath)` | string | 从 JSON 文件导入数据 |
| `reset()` | - | 重置状态 |

**示例**

```php
$seeder = new Nova_DB_Seeder();

// 插入指定数据
$seeder->table('shuoshuo')
    ->columns(['content', 'created_at'])
    ->seed([
        ['第一条说说', '2025-01-01 12:00:00'],
        ['第二条说说', '2025-01-02 12:00:00'],
    ]);

// 从 JSON 导入
$seeder->table('categories')
    ->fromJson('/path/to/categories.json');

// 使用生成器
$seeder->table('users')
    ->columns(['username', 'email'])
    ->count(50)
    ->generate(function($i) {
        return [
            'username' => "user_{$i}",
            'email'    => "user{$i}@example.com",
        ];
    });

// 清空重填
$seeder->table('configs')->fresh([
    ['key' => 'site_name', 'value' => 'My Site'],
]);
```

---

### Nova_DB_Cache

**文件**: `database/class-db-cache.php`

数据库查询缓存类，提供文件级查询结果缓存，适用于频繁读取但不常变更的数据。

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `get($key, $callback=null, $ttl=null)` | key, callback, ttl | 获取缓存，不存在则通过回调生成 |
| `set($key, $data, $ttl=null)` | key, data, ttl | 写入缓存 |
| `delete($key)` | string | 删除指定缓存 |
| `flush()` | - | 清空所有数据库缓存 |
| `has($key)` | string | 检查缓存是否有效 |
| `setDefaultTtl($ttl)` | int | 设置默认有效期 |

**示例**

```php
$cache = new Nova_DB_Cache();

$result = $cache->get('posts_latest', function() use ($db) {
    return $db->get_results("SELECT * FROM posts WHERE status='publish' ORDER BY id DESC LIMIT 10");
}, 3600); // 缓存1小时
```

---

## 4. Filesystem - 文件系统

### Nova_File

**文件**: `filesystem/class-file.php`

基础文件操作封装类，提供文件的读写、复制、移动、删除等操作。

**实例方法**

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `__construct($path)` | string path | - | 构造函数 |
| `path()` | - | string | 获取文件路径 |
| `name()` | - | string | 获取文件名(含扩展名) |
| `stem()` | - | string | 获取文件名(不含扩展名) |
| `extension()` | - | string | 获取扩展名(小写) |
| `dirname()` | - | string | 获取文件所在目录 |
| `read()` | - | string/false | 读取文件全部内容 |
| `write($content, $append=false)` | content, append | bool | 写入/追加文件 |
| `append($content)` | string | bool | 追加内容 |
| `delete()` | - | bool | 删除文件 |
| `copy($destination)` | string | bool | 复制文件 |
| `move($destination)` | string | bool | 移动/重命名文件 |
| `size()` | - | int/false | 获取文件大小(字节) |
| `sizeForHumans($decimals=2)` | int | string | 获取格式化后大小 |
| `mimeType()` | - | string/false | 获取MIME类型 |
| `lastModified()` | - | int/false | 获取最后修改时间戳 |
| `exists()` | - | bool | 判断文件是否存在 |
| `isReadable()` | - | bool | 是否可读 |
| `isWritable()` | - | bool | 是否可写 |
| `isImage()` | - | bool | 是否为图片文件 |
| `readHeader($length=512)` | int | string/false | 读取前N字节 |
| `lines()` | - | array/false | 逐行读取 |
| `permissions()` | - | string | 获取权限(八进制) |
| `chmod($mode)` | int | bool | 设置文件权限 |

**静态方法**

| 方法 | 参数 | 说明 |
|------|------|------|
| `exists($path)` | string | 判断文件是否存在 |
| `copy($source, $destination)` | source, dest | 复制文件 |
| `delete($path)` | string | 删除文件 |
| `read($path)` | string | 读取文件 |
| `put($path, $content)` | path, content | 写入文件 |

**示例**

```php
$file = new Nova_File('path/to/file.txt');
$content = $file->read();
$file->write('新内容');
$file->append('追加内容');

// 静态调用
if (Nova_File::exists('config.php')) {
    $content = Nova_File::read('config.php');
}
```

---

### Nova_Image

**文件**: `filesystem/class-image.php`

图片处理类，基于 GD 库，提供缩略图生成、尺寸调整、水印、格式转换等功能。

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `__construct($path)` | string path | 加载图片(支持 jpg/png/gif/webp/bmp) |
| `width()` | - | 获取图片宽度 |
| `height()` | - | 获取图片高度 |
| `mime()` | - | 获取 MIME 类型 |
| `quality($quality)` | int(1-100) | 设置输出质量 |
| `scale($maxWidth, $maxHeight=null)` | maxWidth, maxHeight | 等比例缩放 |
| `resize($width, $height, $crop=false)` | width, height, crop | 缩放到精确尺寸 |
| `thumb($width, $height)` | width, height | 生成固定尺寸缩略图(居中裁剪) |
| `textWatermark($text, $position='bottom-right', $options=[])` | text, position, options | 添加文字水印 |
| `watermark($watermarkPath, $position='bottom-right', $opacity=100)` | path, position, opacity | 添加图片水印 |
| `rotate($angle, $bgColor=0xFFFFFF)` | angle, bgColor | 旋转图片 |
| `flip($direction='horizontal')` | direction | 翻转图片(horizontal/vertical/both) |
| `grayscale()` | - | 转换为灰度图 |
| `blur($times=1)` | int | 模糊处理 |
| `output($format=null)` | string | 输出到浏览器 |
| `save($path=null, $format=null)` | path, format | 保存到文件 |
| `saveAsJpg($path)` | string | 保存为 JPEG |
| `saveAsPng($path)` | string | 保存为 PNG |
| `saveAsWebp($path)` | string | 保存为 WebP |
| `toBase64($format=null)` | string | 获取 base64 编码 |
| `getResource()` | - | 获取 GD 资源 |
| `destroy()` | - | 释放资源 |
| `makeThumb($source, $dest, $width, $height, $crop=true)` | static | 快速生成缩略图 |
| `info($path)` | static | 获取图片信息 |

**示例**

```php
// 加载图片
$img = new Nova_Image('photo.jpg');

// 等比例缩放至宽度 800
$img->scale(800)->save('large.jpg');

// 生成居中裁剪的缩略图 300x200
$img->thumb(300, 200)->save('thumb.jpg');

// 添加水印
$img->watermark('watermark.png', 'bottom-right', 80)
    ->save('watermarked.jpg');

// 添加文字水印
$img->textWatermark('© 2026', 'bottom-right', [
    'color' => [255, 255, 255],
    'size'  => 20,
])->save('textwm.jpg');

// 格式转换
$img->saveAsWebp('output.webp');

// 静态工具
Nova_Image::makeThumb('source.jpg', 'thumb.jpg', 300, 200);
$info = Nova_Image::info('photo.jpg');
// ['width'=>1920, 'height'=>1080, 'mime'=>'image/jpeg', 'type'=>'jpeg', 'size'=>123456]
```

---

### Nova_Upload

**文件**: `filesystem/class-upload.php`

文件上传处理类，提供安全的上传验证、文件类型检测、存储等功能。

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `__construct($file)` | $_FILES['field'] | 构造函数 |
| `allowedTypes($types)` | array | 允许的文件扩展名 |
| `allowedMimes($mimes)` | array | 允许的 MIME 类型 |
| `onlyImages()` | - | 仅允许图片类型(jpg/png/gif/webp/bmp/svg) |
| `maxSize($bytes)` | int | 设置最大文件大小(字节) |
| `minSize($bytes)` | int | 设置最小文件大小(字节) |
| `toDir($dir)` | string | 设置上传目标目录(绝对路径) |
| `toUrl($url)` | string | 设置上传 URL 前缀 |
| `subDir($subDir)` | string | 设置子目录(自动创建) |
| `overwrite($flag=true)` | bool | 是否允许覆盖 |
| `prefix($prefix)` | string | 设置文件名前缀 |
| `useOriginalName($flag=true)` | bool | 是否使用原始文件名 |
| `validate()` | - | 验证上传文件 |
| `save()` | - | 保存文件，返回 ['path', 'url', 'name', 'size', 'ext'] |
| `saveGetUrl()` | - | 保存并返回 URL |
| `getError()` | - | 获取错误信息 |
| `getSavedPath()` | - | 获取已保存文件路径 |
| `getSavedUrl()` | - | 获取已保存文件 URL |
| `getMime($ext)` | static | 获取扩展名对应的 MIME 类型 |
| `isImageFile($filePath)` | static | 检测文件是否为图片(通过文件头) |
| `deleteByUrl($url)` | static | 根据 URL 安全删除上传文件 |

**示例**

```php
$upload = new Nova_Upload($_FILES['avatar']);
$upload->onlyImages()
       ->maxSize(2 * 1024 * 1024)  // 2MB
       ->toDir('/path/to/uploads')
       ->subDir('avatars')
       ->prefix('avatar_');

if ($upload->validate()) {
    $result = $upload->save();
    // $result['path'] => '/path/to/uploads/avatars/avatar_abc123.jpg'
    // $result['url']  => '/uploads/avatars/avatar_abc123.jpg'
    echo $result['url'];
} else {
    echo $upload->getError(); // "文件太大，最大 2MB"
}
```

---

## 5. Backend - 后台管理

### Nova_Backend_Page

**文件**: `backend/class-backend-page.php`

后台页面基类，插件可继承此类创建标准化的后台管理页面，包含权限验证、页面标题、面包屑、表单、表格、分页等。

**属性**

| 属性 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| $page_title | string | '页面' | 页面标题 |
| $menu_title | string | '' | 菜单显示名称 |
| $menu_id | string | '' | 菜单唯一 ID |
| $menu_icon | string | '' | 菜单图标 |
| $menu_position | int | 50 | 菜单位置 |
| $require_admin | bool | true | 是否要求管理员权限 |
| $parent_menu | string | '' | 父级菜单ID(子菜单) |

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `checkPermission()` | - | 验证用户权限 |
| `permissionDenied()` | - | 权限不足处理 |
| `with($key, $value=null)` | key, value | 设置模板数据 |
| `addBreadcrumb($title, $url='')` | title, url | 添加面包屑 |
| `addCss($css)` | string | 添加内联 CSS |
| `addJs($js)` | string | 添加内联 JS |
| `header()` | - | 输出页面头部(标题+面包屑) |
| `footer()` | - | 输出页面尾部(JS) |
| `card($title='', $class='')` | title, class | 卡片容器开始 |
| `endCard()` | - | 卡片结束 |
| `alert($type, $message)` | type, message | 显示提示消息(type=success/error/warning/info) |
| `table($headers, $rows, $options=[])` | headers, rows, options | 渲染数据表格 |
| `pagination($total, $perPage, $current, $baseUrl)` | total, perPage, current, baseUrl | 生成分页 HTML |
| `searchBox($keyword='', $placeholder='')` | keyword, placeholder | 搜索框 |
| `formOpen($options=[])` | options | 表单开始 |
| `formClose()` | - | 表单结束 |
| `formField($type, $name, $label, $value='', $options=[])` | type, name, label, value, options | 渲染表单字段 |
| `submitButton($text='保存', $class='btn btn-primary')` | text, class | 提交按钮 |
| `render()` | - | 页面渲染入口(子类重写) |
| `renderPage()` | - | 执行页面渲染(外部调用) |
| `success($msg)` | string | 成功提示 |
| `error($msg)` | string | 错误提示 |
| `warning($msg)` | string | 警告提示 |

**表单字段类型**

| type | 说明 |
|------|------|
| text, email, password, number | 标准输入框 |
| textarea | 多行文本框 |
| select | 下拉选择框 |
| checkbox | 复选框 |
| radio | 单选按钮 |
| file | 文件上传 |
| color | 颜色选择器 |
| hidden | 隐藏字段 |
| switch | 开关切换 |

**示例**

```php
class MySettingsPage extends Nova_Backend_Page {
    protected $page_title = '插件设置';
    protected $menu_title = '设置';
    protected $menu_id    = 'my-settings';
    protected $menu_icon  = '⚙';

    public function render() {
        $this->header();
        $this->card('基本配置');
        $this->formOpen(['action' => '']);
        $this->formField('text', 'api_key', 'API Key', '');
        $this->formField('select', 'theme', '主题', 'dark', [
            'options' => ['light' => '浅色', 'dark' => '深色'],
        ]);
        $this->submitButton('保存');
        $this->formClose();
        $this->endCard();
        $this->footer();
    }
}
new MySettingsPage();
```

---

### Nova_Backend_Menu

**文件**: `backend/class-backend-menu.php`

后台菜单管理类，类似 WordPress 的 add_menu_page / add_submenu_page。插件可用于注册侧边栏菜单项。

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `add_menu($title, $id, $url, $icon='', $position=50, $options=[])` | title, id, url, icon, position, options | 注册顶级菜单 |
| `add_submenu($parent_id, $title, $id, $url, $position=10, $options=[])` | parent_id, title, id, url, position, options | 注册子菜单 |
| `remove_menu($id)` | string | 移除菜单 |
| `remove_submenu($parent_id, $id)` | parent_id, id | 移除子菜单 |
| `getMenus()` | - | 获取所有已注册菜单数据 |
| `render($echo=true)` | bool | 渲染侧边栏菜单 HTML |
| `reset()` | - | 重置所有菜单 |

**示例**

```php
// 注册顶级菜单
Nova_Backend_Menu::add_menu('插件中心', 'my-plugin', '/admin/my-plugin.php', '🔌', 30);

// 注册子菜单
Nova_Backend_Menu::add_submenu('my-plugin', '设置', 'my-plugin-settings', '/admin/my-plugin-settings.php');

// 带徽标的菜单
Nova_Backend_Menu::add_menu('消息', 'messages', '/admin/messages.php', '💬', 20, [
    'badge' => '3',
    'badge_type' => 'danger',
]);

// 在 header.php 中输出
Nova_Backend_Menu::render();
```

---

### Nova_Backend_Ajax

**文件**: `backend/class-backend-ajax.php`

后台 AJAX 处理器，插件通过此类注册 AJAX 回调，前后端通过统一的 `/admin/ajax.php?action=xxx` 入口调用。

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `add($action, $callback, $needAuth=true, $method='POST')` | action, callback, needAuth, method | 注册 AJAX 处理器 |
| `addPublic($action, $callback, $method='POST')` | action, callback, method | 注册无需认证的处理器 |
| `remove($action)` | string | 移除处理器 |
| `getActions()` | - | 获取所有已注册的操作列表 |
| `handle()` | - | 处理 AJAX 请求 |
| `init()` | - | 初始化入口(在 ajax.php 调用) |
| `url($action, $params=[])` | action, params | 生成前端 AJAX URL |
| `script()` | - | 生成前端 AJAX 脚本 |

**示例**

```php
// 后端注册
Nova_Backend_Ajax::add('my_plugin_save', function($input) {
    $id = (int)$input['id'];
    // 处理逻辑
    return ['success' => true, 'id' => $id];
});

// 前端调用
// fetch('/admin/ajax.php?action=my_plugin_save', {
//     method: 'POST',
//     body: new URLSearchParams({ id: 1 })
// }).then(r => r.json()).then(console.log);
```

---

### Nova_Backend_Notice

**文件**: `backend/class-backend-notice.php`

后台通知/提示消息管理类，类似 WordPress 的 admin_notices，支持一次性或持久化显示。

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `add($message, $type='info', $persist=false, $options=[])` | message, type, persist, options | 添加通知消息 |
| `success($msg, $persist=true)` | msg, persist | 快捷：成功消息 |
| `error($msg, $persist=true)` | msg, persist | 快捷：错误消息 |
| `warning($msg, $persist=true)` | msg, persist | 快捷：警告消息 |
| `info($msg, $persist=true)` | msg, persist | 快捷：信息消息 |
| `getNotices()` | - | 获取所有待显示通知 |
| `render($echo=true)` | bool | 渲染所有通知消息 |
| `clear()` | - | 清除所有通知 |
| `hasCapability($cap)` | string | 检查用户权限 |

**类型说明**

| type | CSS 类 | 说明 |
|------|--------|------|
| success | alert-success | 成功 |
| error | alert-danger | 错误 |
| warning | alert-warning | 警告 |
| info | alert-primary | 信息 |

**示例**

```php
// 添加提示
Nova_Backend_Notice::success('插件已激活');
Nova_Backend_Notice::error('配置有误', true); // 持久化到 Session

// 在页面中输出
Nova_Backend_Notice::render();
```

---

### Nova_Backend_List_Table

**文件**: `backend/class-backend-list-table.php`

数据列表表格类，类似 WordPress 的 WP_List_Table，提供分页、搜索、排序、批量操作等功能。

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `__construct($options=[])` | options | 构造函数 |
| `prepareItems()` | - | 准备数据(子类重写) |
| `column($row, $col)` | row, col | 渲染单元格(子类重写) |
| `rowAttrs($row)` | row | 行额外属性 |
| `getSearch()` | - | 获取搜索关键词 |
| `getPage()` | - | 获取当前页码 |
| `getPerPage()` | - | 获取每页数量 |
| `totalPages()` | - | 获取总页数 |
| `render()` | - | 渲染完整表格 |

**Options 参数说明**

| 选项 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| per_page | int | 20 | 每页数量 |
| columns | array | [] | 列定义 ['field' => '显示名'] |
| bulk_actions | array | [] | 批量操作 ['delete' => '删除'] |
| sort_by | string | '' | 默认排序字段 |
| sort_order | string | ASC | 默认排序方向 |
| search | bool | false | 是否启用搜索 |
| row_number | bool | false | 是否显示序号 |
| empty_text | string | 暂无数据 | 空数据提示 |
| class | string | table... | 表格 CSS 类 |
| sortable | array | [] | 可排序字段列表 |

**示例**

```php
class MyPostsTable extends Nova_Backend_List_Table {
    public function prepareItems() {
        $db = new Nova_DB();
        $this->data  = $db->get_results("SELECT * FROM posts");
        $this->total = $db->get_var("SELECT COUNT(*) FROM posts");
    }
    public function column($row, $col) {
        if ($col === 'actions') {
            return '<a href="edit.php?id=' . $row['id'] . '">编辑</a>';
        }
        return e($row[$col] ?? '');
    }
}

$table = new MyPostsTable([
    'per_page'     => 20,
    'columns'      => ['id' => 'ID', 'title' => '标题', 'status' => '状态'],
    'bulk_actions' => ['delete' => '删除'],
    'search'       => true,
    'sortable'     => ['id', 'title'],
]);
$table->render();
```

---

## 6. Plugin & Theme - 插件与主题

### Nova_Plugin

**文件**: `plugin/class-plugin.php`

插件基类。所有插件应继承此类，可自动加载 routes/ 目录、注册 REST 路由。

**属性**

| 属性 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| $name | string | '' | 插件名称(自动检测) |
| $version | string | '1.0.0' | 插件版本 |
| $plugin_path | string | '' | 插件目录路径(自动) |
| $plugin_url | string | '' | 插件 URL(自动) |

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `register_route($namespace, $route, $args)` | namespace, route, args | 注册 REST 路由 |
| `db()` | - | 获取 Nova_DB 实例 |
| `log($message, $level='INFO')` | message, level | 写入插件日志 |

**示例**

```php
class MyPlugin extends Nova_Plugin {
    public function init() {
        $this->register_route('v1', '/my-plugin/data', [
            'methods'  => 'GET',
            'callback' => [$this, 'getData'],
        ]);
    }

    public function getData($request) {
        $this->log('请求数据');
        return new Nova_REST_Response([
            'code' => 'rest_ok',
            'data' => ['version' => $this->version],
        ]);
    }
}
new MyPlugin();
```

---

### Nova_Theme

**文件**: `theme/class-theme.php`

主题基类。所有主题应继承此类，支持布局模板、路由注册、资源管理。

**属性**

| 属性 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| $name | string | '' | 主题名称(自动检测) |
| $version | string | '1.0.0' | 主题版本 |
| $theme_path | string | '' | 主题目录路径(自动) |
| $theme_url | string | '' | 主题 URL(自动) |
| $layout | string | 'default' | 布局模板 |

**方法说明**

| 方法 | 参数 | 说明 |
|------|------|------|
| `set_layout($layout)` | string | 设置布局模板 |
| `render($template, $data=[])` | template, data | 渲染模板(views/目录下) |
| `register_route($namespace, $route, $args)` | namespace, route, args | 注册 REST 路由 |
| `db()` | - | 获取 Nova_DB 实例 |
| `asset($path)` | string | 获取主题资源 URL |

**示例**

```php
class MyTheme extends Nova_Theme {
    public function init() {
        $this->set_layout('default');
        $this->register_route('v1', '/my-theme/data', [
            'methods' => 'GET',
            'callback' => [$this, 'getData'],
        ]);
    }
}
new MyTheme();
```

**主题目录结构**

```
themes/my-theme/
├── style.css
├── views/
│   ├── header.php
│   ├── footer.php
│   └── index.php
├── routes/
│   └── api.php
└── assets/
    ├── css/
    └── js/
```

> 文档版本: v1.0  
> 生成日期: 2026-07-28
