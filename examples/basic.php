<?php

/**
 * 基础示例：展示路由定义、分组、中间件、命名路由与分发。
 *
 * 运行方式（需要先 composer install）：
 *   php -S localhost:8000 examples/basic.php
 * 然后访问 http://localhost:8000/ 或 http://localhost:8000/api/users/1
 */

require __DIR__ . '/../vendor/autoload.php';

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

// 分发并输出
$response = $router->dispatch();
$response->send();
