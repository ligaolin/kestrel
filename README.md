# Kestrel Router

一个轻量、快速、**零依赖**的 PHP 路由包。支持路由分组、中间件、命名路由与反向路由、控制器自动解析、JSON 响应等常见能力。

> 包名：`kestrel/router`，PHP 命名空间：`Kestrel\Router`，要求 PHP >= 5.4（也兼容 PHP 7 / 8）。

---

## 特性

- 零第三方依赖，纯 PHP 实现
- PSR-4 自动加载，标准 Composer 包结构
- 链式 API：`get / post / put / patch / delete / options / any / map`
- 路由参数 + 自定义正则约束 + 可选参数（`{id} / {id:\d+} / {path?}`）
- 路由分组（支持前缀、组中间件、嵌套分组）
- 中间件：全局 / 分组 / 单路由三级，支持闭包、`MiddlewareInterface`、可调用类
- 内置中间件：CORS 跨域、Basic 认证、限流、日志、安全响应头、方法覆盖
- 控制器多种写法：闭包、`[类, 方法]`、`类@方法`、`类::方法`、单类名默认 `index`、`__invoke`
- 命名路由 + 反向路由（URL 生成）
- 请求对象：统一读取 query / body / JSON / 路由参数 / header
- 响应对象：`text / html / json / redirect` 便捷构造 + 状态码常量
- 自动处理 `HEAD`（回退 GET）、`OPTIONS`（返回 `Allow`）；404/405/处理器异常以异常抛出，可捕获后自定义输出

---

## 目录结构

```
router/
├── composer.json
├── LICENSE
├── README.md
├── examples/
│   └── basic.php
└── src/
    ├── Router.php
    ├── Route.php
    ├── RouteGroup.php
    ├── Request.php
    ├── Response.php
    ├── MiddlewareInterface.php
    ├── Middleware/
    │   ├── CorsMiddleware.php
    │   ├── BasicAuthMiddleware.php
    │   ├── RateLimitMiddleware.php
    │   ├── LoggerMiddleware.php
    │   ├── SecurityHeadersMiddleware.php
    │   └── MethodOverrideMiddleware.php
    └── Exception/
        └── MethodNotAllowedException.php
```

---

## 安装

### 作为 Composer 包使用

```bash
composer require kestrel/router
```

```php
require __DIR__ . '/vendor/autoload.php';

use Kestrel\Router\Router;

$router = new Router();
```

### 本地开发（尚未发布到 Packagist）

在 `composer.json` 中使用 `path` 仓库：

```json
{
    "repositories": [
        { "type": "path", "url": "../router" }
    ],
    "require": {
        "kestrel/router": "*"
    }
}
```

---

## 快速开始

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Kestrel\Router\Router;
use Kestrel\Router\Request;
use Kestrel\Router\Response;

$router = new Router();

$router->get('/', function (Request $request) {
    return 'Hello, World!';
});

$router->get('/user/{id}', function (Request $request) {
    return Response::json([
        'id'   => $request->param('id'),
        'name' => 'Alice',
    ]);
});

// 分发并输出响应（出错时 dispatch() 抛异常，可捕获后自定义输出）
try {
    $response = $router->dispatch();
    $response->send();
} catch (\Kestrel\Router\Exception\RouteNotFoundException $e) {
    // 404
} catch (\Kestrel\Router\Exception\MethodNotAllowedException $e) {
    // 405
} catch (\Exception $e) {
    // 500
}
```

用 PHP 内置服务器运行：

```bash
php -S localhost:8000 index.php
```

---

## 路由定义

```php
$router->get('/users', $handler);
$router->post('/users', $handler);
$router->put('/users/{id}', $handler);
$router->patch('/users/{id}', $handler);
$router->delete('/users/{id}', $handler);
$router->options('/users', $handler);

// 匹配任意方法
$router->any('/ping', function () { return 'pong'; });

// 同一路径注册多个方法
$router->map(['GET', 'POST'], '/form', $handler);
```

### 路由参数

```php
// 任意非斜杠字符
$router->get('/user/{id}', function (Request $request) {
    return $request->param('id');
});

// 自定义正则约束
$router->get('/user/{id:\d+}', function (Request $request) {
    return $request->param('id'); // 只匹配数字
});

$router->get('/slug/{slug:[a-z0-9\-]+}', $handler);

// 可选参数（连同其前的斜杠一起可选）
$router->get('/file/{path?}', function (Request $request) {
    return $request->param('path', 'default');
});
// /file 和 /file/abc 都能命中
```

> 注意：静态路径中的正则特殊字符（如 `.`、`-`）会被正确转义，不再误匹配。

### 命名路由与反向路由

```php
$router->get('/user/{id}', $handler)->name('user.show');

// 生成 URL
echo $router->url('user.show', ['id' => 42]); // /user/42
echo $router->route('user.show', ['id' => 42]); // 别名，等价
```

---

## 路由分组

```php
$router->group('/admin', function ($admin) {
    $admin->get('/dashboard', $handler); // /admin/dashboard
    $admin->get('/users', $handler);     // /admin/users

    // 嵌套分组
    $admin->group('/settings', function ($settings) {
        $settings->get('/profile', $handler); // /admin/settings/profile
    });
});

// 分组 + 组中间件
$router->group('/api', function ($api) {
    $api->get('/users', $handler);
}, [new AuthMiddleware()]);

// 或使用链式 group->middleware()（仅对之后注册的路由生效）
$router->group('/api', function ($api) {
    $api->middleware(new AuthMiddleware());
    $api->get('/users', $handler);
});
```

---

## 中间件

### 闭包中间件

```php
$router->middleware(function (Request $request, $next) {
    // 前置逻辑
    $start = microtime(true);

    $response = $next($request); // 交给下一层

    // 后置逻辑
    $response->setHeader('X-Elapsed', microtime(true) - $start);
    return $response;
});
```

### 类中间件（实现接口）

```php
use Kestrel\Router\MiddlewareInterface;
use Kestrel\Router\Request;

class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        if (!$request->header('Authorization')) {
            return new \Kestrel\Router\Response('Unauthorized', 401);
        }
        return $next($request);
    }
}

$router->middleware(new AuthMiddleware());
```

### 可调用类中间件（`__invoke`）

```php
class Logger
{
    public function __invoke(Request $request, $next)
    {
        error_log($request->getMethod() . ' ' . $request->getUri());
        return $next($request);
    }
}

$router->middleware('Logger'); // 传类名，自动实例化
```

### 中间件作用范围

| 作用范围 | 写法 |
| --- | --- |
| 全局 | `$router->middleware($m)` |
| 分组 | `$router->group('/x', function ($g) { ... }, [$m])` 或 `$g->middleware($m)` |
| 单路由 | `$router->get('/x', $h)->middleware($m)` |

> 执行顺序：全局 → 分组 → 单路由，按洋葱模型逐层包裹。

---

## 内置中间件

包内自带若干可直接使用的中间件（位于 `Kestrel\Router\Middleware` 命名空间）。

### CORS 跨域

```php
use Kestrel\Router\Middleware\CorsMiddleware;

$router->middleware(new CorsMiddleware([
    'origin'      => ['https://a.com', 'https://b.com'], // 或 '*'
    'methods'     => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
    'headers'     => 'Content-Type, Authorization, X-Requested-With',
    'credentials' => true,
    'max_age'     => 3600,
]));
```

> 全局中间件会包裹正常响应与 `OPTIONS` 预检响应。注意：404/405 现在以异常形式抛出，若需要给它们加跨域头，请在 catch 分支里自行加上。

### Basic 认证

```php
use Kestrel\Router\Middleware\BasicAuthMiddleware;

// 静态用户表
$router->middleware(new BasicAuthMiddleware(['admin' => 'secret']));

// 自定义校验（如 password_verify）
$router->middleware(new BasicAuthMiddleware(function ($user, $pass) {
    return $user === 'admin' && password_verify($pass, $hash);
}));
```

认证通过后，用户名写入 `$request->getAttribute('auth.user')`。

### 限流

```php
use Kestrel\Router\Middleware\RateLimitMiddleware;

// 60 秒窗口内最多 60 次（按客户端 IP）
$router->middleware(new RateLimitMiddleware(60, 60));
```

超限返回 `429`，并带 `Retry-After` / `X-RateLimit-*` 头。计数默认写入系统临时目录，跨请求生效。

### 日志

```php
use Kestrel\Router\Middleware\LoggerMiddleware;

$router->middleware(new LoggerMiddleware()); // 默认 error_log

// 自定义输出
$router->middleware(new LoggerMiddleware(function ($message) {
    file_put_contents('/tmp/access.log', $message . PHP_EOL, FILE_APPEND);
}));
```

### 安全响应头

```php
use Kestrel\Router\Middleware\SecurityHeadersMiddleware;

$router->middleware(new SecurityHeadersMiddleware());
// 覆盖默认项
$router->middleware(new SecurityHeadersMiddleware(['X-Frame-Options' => 'DENY']));
```

### 方法覆盖

```php
use Kestrel\Router\Middleware\MethodOverrideMiddleware;

$router->middleware(new MethodOverrideMiddleware());
// 支持 <input type="hidden" name="_method" value="PUT"> 或请求头 X-HTTP-Method-Override
```

---

## 控制器解析

先设置控制器命名空间：

```php
$router->setNamespace('App\\Controllers');
```

支持以下写法（`$request` 可写可不写，反射会自动判断）：

```php
// 1. 闭包
$router->get('/a', function (Request $request) {
    return 'ok';
});

// 2. 数组 [类, 方法]（类名自动加命名空间前缀）
$router->get('/b', ['UserController', 'index']);

// 3. 字符串 类@方法
$router->get('/c', 'UserController@index');

// 4. 字符串 类::方法（静态方法）
$router->get('/d', 'UserController::list');

// 5. 单个类名 → 默认调用 index；若定义了 __invoke 则调用 __invoke
$router->get('/e', 'UserController');

// 6. 可调用对象
$router->get('/f', new MyInvokable());
```

控制器方法示例：

```php
namespace App\Controllers;

use Kestrel\Router\Request;
use Kestrel\Router\Response;

class UserController
{
    public function index(Request $request)
    {
        return Response::json(['list' => []]);
    }

    public function show(Request $request)
    {
        return Response::json(['id' => $request->param('id')]);
    }
}
```

> 使用 `\` 开头的完全限定类名（如 `\App\Services\User`）会自动跳过命名空间前缀。

---

## Request 对象

```php
use Kestrel\Router\Request;

function (Request $request) {
    $request->getMethod();          // 'GET'
    $request->getUri();             // '/user/1'
    $request->isMethod('POST');     // bool

    $request->param('id');          // 路由参数
    $request->query('page');        // 查询串 ?page=2
    $request->input('name');        // 请求体（POST/JSON/form）
    $request->get('id');            // 依次查找：路由参数 → query → body

    $request->all();                // 全部参数
    $request->only('id', 'page');   // 只取部分
    $request->has('id');            // 是否存在

    $request->header('Authorization'); // 请求头（大小写不敏感）

    // 中间件传值
    $request->setAttribute('user', $user);
    $request->getAttribute('user');
};
```

> 请求体自动解析 `application/json` 与 `application/x-www-form-urlencoded`。

---

## Response 对象

```php
use Kestrel\Router\Response;

// 处理器里可以直接返回字符串/数组，会自动转换
return 'ok';
return ['code' => 0, 'data' => []]; // 自动 JSON

// 也可以显式构造
return Response::text('plain text');
return Response::html('<h1>Hi</h1>');
return Response::json(['user' => 'Alice']);
return Response::json(['error' => 'bad'], 400);
return Response::redirect('/login');

// 链式设置
return (new Response('created', 201))
    ->header('X-Custom', '1')
    ->withHeaders(['X-A' => 'a', 'X-B' => 'b']);

// 常用状态码常量
Response::HTTP_OK                  // 200
Response::HTTP_CREATED             // 201
Response::HTTP_NO_CONTENT          // 204
Response::HTTP_BAD_REQUEST         // 400
Response::HTTP_UNAUTHORIZED        // 401
Response::HTTP_FORBIDDEN           // 403
Response::HTTP_NOT_FOUND           // 404
Response::HTTP_METHOD_NOT_ALLOWED  // 405
Response::HTTP_UNPROCESSABLE_ENTITY// 422
Response::HTTP_INTERNAL_SERVER_ERROR // 500
```

---

## 错误处理

`dispatch()` 出错时会抛出异常而不是返回错误响应，方便你捕获后自定义输出：

| 异常 | 触发场景 |
| --- | --- |
| `RouteNotFoundException` | 没有匹配到路由（404） |
| `MethodNotAllowedException` | 路径存在但请求方法不允许（405），可用 `getAllowedMethods()` 拿到允许的方法 |
| 其它 `\Exception` | 控制器 / 中间件内部抛出的异常会原样向上传播（500） |

```php
try {
    $response = $router->dispatch();
} catch (\Kestrel\Router\Exception\RouteNotFoundException $e) {
    $response = new Response('页面不存在', 404);
} catch (\Kestrel\Router\Exception\MethodNotAllowedException $e) {
    $response = (new Response('方法不允许', 405))
        ->header('Allow', implode(', ', $e->getAllowedMethods()));
} catch (\Exception $e) {
    $response = new Response('服务器错误：' . $e->getMessage(), 500);
}
$response->send();
```

以上异常都继承自 `Kestrel\Router\Exception\RouterException`，也可以只捕获这个基类统一处理。

---

## 手动匹配与测试

不依赖 HTTP 环境，可手动调用 `match()` / `dispatch()`：

```php
$router = new Router();
$router->get('/user/{id:\d+}', $handler)->name('user.show');

// 手动构造请求
$request = new Request('GET', '/user/12');
$response = $router->dispatch($request); // 命中返回 Response；未命中/方法不允许抛异常

// 只做匹配
$result = $router->match('GET', '/user/12');
// ['route' => Route, 'params' => ['id' => '12']]
```

---

## 完整示例

见 [`examples/basic.php`](examples/basic.php)：

```bash
php -S localhost:8000 examples/basic.php
```

访问：
- `http://localhost:8000/`
- `http://localhost:8000/user/42`
- `http://localhost:8000/api/users`

---

## License

MIT
