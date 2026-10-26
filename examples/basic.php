<?php

/**
 * 基础示例：展示路由定义、分组、中间件、命名路由与分发。
 *
 * 运行方式（需要先 composer install）：
 *   php -S localhost:8000 examples/basic.php
 * 然后访问 http://localhost:8000/ 或 http://localhost:8000/api/users/1
 */

require __DIR__ . '/../vendor/autoload.php';

use Kestrel\Router\Exception\MethodNotAllowedException;
use Kestrel\Router\Exception\RouteNotFoundException;
use Kestrel\Router\Middleware\CorsMiddleware;
use Kestrel\Router\Request;
use Kestrel\Router\Response;
use Kestrel\Router\Router;

$router = new Router();
$router->setNamespace('App\\Controllers');

// 内置中间件：跨域（放在最外层，包裹 404/405/OPTIONS 等所有响应）
$router->middleware(new CorsMiddleware(array(
    'origin' => '*',
    'methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
)));

// 全局中间件：记录耗时
$router->middleware(function (Request $request, $next) {
    $start = microtime(true);
    $response = $next($request);
    $response->setHeader('X-Elapsed', (microtime(true) - $start) . 's');
    return $response;
});

// 普通路由
$router->get('/', function (Request $request) {
    return 'Hello, Kestrel Router!';
});

// 带参数与正则约束 + 命名路由
$router->get('/user/{id:\d+}', function (Request $request) {
    return Response::json(array(
        'id'   => $request->param('id'),
        'name' => 'Alice',
    ));
})->name('user.show');

// 反向路由
$router->get('/profile', function () use ($router) {
    return 'Go to: ' . $router->url('user.show', array('id' => 42));
});

// 分组（前缀 + 组中间件）
$router->group('/api', function ($api) {
    $api->middleware(function (Request $request, $next) {
        $request->setAttribute('started', time());
        return $next($request);
    });

    $api->get('/users', function (Request $request) {
        return Response::json(array('users' => array('Alice', 'Bob')));
    });

    $api->get('/users/{id}', 'UserController@show');
}, array());

// 错误处理器：404/405/异常统一转为自定义响应。
// 错误响应在中间件链内生成，会穿过上方注册的全局中间件（CORS 头不丢）。
$router->setErrorHandler(function ($e) {
    if ($e instanceof MethodNotAllowedException) {
        return (new Response('自定义 405', Response::HTTP_METHOD_NOT_ALLOWED))
            ->header('Allow', implode(', ', $e->getAllowedMethods()));
    }
    if ($e instanceof RouteNotFoundException) {
        return new Response('自定义 404', Response::HTTP_NOT_FOUND);
    }
    return new Response('自定义 500: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
});

// 分发并输出：设置错误处理器后无需再 try/catch
$router->dispatch()->send();
