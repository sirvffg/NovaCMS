# 行为验证码 (Dino Captcha) 集成文档

## 1. 安装

访问一次 `vendor/captcha/install.php`，自动创建数据表：

```
captcha_sessions  — 验证会话表（自动过期清理）
captcha_tokens    — 业务令牌表（验证通过后生成）
```

## 2. 配置

编辑 `vendor/captcha/AuthApi.php` 中的属性默认值：

| 属性 | 默认值 | 说明 |
|------|--------|------|
| `$tokenSecret` | `your_secret_key_change_me` | HMAC 签名密钥 |
| `$imageApiUrl` | `https://picsum.photos/300/150` | 拼图背景图源 |
| `$defaultDifficulty` | `5` | POW 难度（前导零个数） |
| `$sessionTTL` | `45` | 验证会话有效期（秒） |
| `$bizTokenTTL` | `3600` | 业务令牌有效期（秒） |
| `$positionTolerance` | `5` | 滑块位置容差（像素） |

也可创建 `vendor/captcha/config.php` 返回数组覆盖：

```php
<?php
return [
    'tokenSecret' => '你的签名密钥',
    'imageApiUrl' => 'https://picsum.photos/300/150',
    'defaultDifficulty' => 5(生产环境建议添加风控系统),
];
```

## 3. 前端引入

### 3.1 加载 JS 文件

```html
<!-- 可选：WASM 加速库，不加则自动降级为纯 JS -->
<script src="https://cdn.jsdelivr.net/npm/hash-wasm@4/dist/sha256.umd.min.js"></script>

<!-- 必须：验证码核心 -->
<script src="/vendor/captcha/captcha.js"></script>//默认经混淆的js
```

### 3.2 创建容器

```html
<div id="你的容器名"></div>
```

### 3.3 初始化

```javascript
const auth = new BehaviorAuth('你的容器名', '/vendor/captcha/AuthApi.php');
```

参数说明：

| 参数 | 类型 | 说明 |
|------|------|------|
| `containerId` | string | 容器元素 ID |
| `apiBaseUrl` | string | 后端接口地址，默认 `/vendor/captcha/AuthApi.php` |

## 4. 回调

```javascript
const auth = new BehaviorAuth('auth-container', '/vendor/captcha/AuthApi.php');

// 验证成功回调，bizToken 为业务令牌
auth.onSuccess = function(bizToken) {
    console.log('验证成功，令牌:', bizToken);
    // 将 bizToken 提交到你的业务接口
};

// 验证失败回调
auth.onFail = function(msg) {
    console.log('验证失败:', msg);
};
```

## 5. 后端校验

验证码通过后前端拿到 `bizToken`，提交到业务接口时，后端校验该令牌：

### 方式一：调用接口校验

```php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/vendor/captcha/AuthApi.php?action=verify-token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['token' => $bizToken]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$result = json_decode(curl_exec($ch), true);
curl_close($ch);

if ($result['valid'] ?? false) {
    // 令牌有效，继续业务逻辑
}
```

### 方式二：直接查数据库

```php
require_once __DIR__ . '/vendor/captcha/AuthApi.php';

$auth = new BehaviorAuth();
$valid = $auth->verifyBizToken($bizToken);

if ($valid) {
    // 令牌有效
}
```

> 注意：`verifyBizToken` 是一次性消费，校验后令牌即删除，防止重放攻击。

## 6. 完整示例

### 前端（表单提交场景）

```html
<form id="myForm">
    <input type="text" name="content" placeholder="留言内容" required>

    <!-- 验证码容器 -->
    <div id="auth-container"></div>

    <button type="submit" id="submitBtn" disabled>提交</button>
</form>

<script src="https://cdn.jsdelivr.net/npm/hash-wasm@4/dist/sha256.umd.min.js"></script>
<script src="/vendor/captcha/BehaviorAuth.js"></script>
<script>
    let captchaToken = '';

    const auth = new BehaviorAuth('auth-container', '/vendor/captcha/AuthApi.php');

    auth.onSuccess = function(bizToken) {
        captchaToken = bizToken;
        document.getElementById('submitBtn').disabled = false;
    };

    auth.onFail = function() {
        captchaToken = '';
        document.getElementById('submitBtn').disabled = true;
    };

    document.getElementById('myForm').addEventListener('submit', function(e) {
        e.preventDefault();
        if (!captchaToken) {
            alert('请先完成验证');
            return;
        }

        fetch('/api/submit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                content: this.content.value,
                captcha_token: captchaToken
            })
        });
    });
</script>
```

### 后端（业务接口校验）

```php
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/captcha/AuthApi.php';

$captchaToken = $_POST['captcha_token'] ?? '';

$auth = new BehaviorAuth();
if (!$auth->verifyBizToken($captchaToken)) {
    http_response_code(403);
    echo json_encode(['error' => '验证码校验失败']);
    exit;
}

// 验证通过，继续业务逻辑
// ...
```

## 7. API 接口

| 接口 | 方法 | 参数 | 说明 |
|------|------|------|------|
| `?action=init` | GET | — | 初始化验证会话，返回 token/salt/difficulty |
| `?action=verify-pow` | POST | `{token, nonce}` | POW 工作量证明验证 |
| `?action=get-puzzle` | GET | `token` (query) | 获取拼图背景图和滑块图 |
| `?action=verify-final` | POST | `{token, offset_x, behavior_data}` | 最终验证，返回业务令牌 |
| `?action=verify-token` | POST | `{token}` | 校验业务令牌是否有效 |
