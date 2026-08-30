<?php

namespace Kestrel\Router;

class RouteGroup
{
    /** @var string */
    private $prefix;

    /** @var array */
    private $middlewares = array();

    /** @var Route[] */
    private $routes = array();

    /** @var RouteGroup[] */
    private $groups = array();

    public function __construct($prefix, array $middlewares = array())
    {
        $this->prefix = $this->normalizePrefix($prefix);
        $this->middlewares = $middlewares;
    }

    /**
     * 给该分组内“之后注册”的路由追加中间件。
     */
    public function middleware($middleware)
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function addMiddleware($middleware)
    {
        return $this->middleware($middleware);
    }

    /**
     * 嵌套分组：前缀与中间件会累积到子分组。
     */
    public function group($prefix, callable $callback)
    {
        $group = new self($this->join($this->prefix, $prefix), $this->middlewares);
        $callback($group);
        $this->groups[] = $group;
        return $group;
    }

    public function addRoute($methods, $pattern, $handler)
    {
        $route = new Route($methods, $this->join($this->prefix, $pattern), $handler);

        foreach ($this->middlewares as $middleware) {
            $route->middleware($middleware);
        }

        $this->routes[] = $route;
        return $route;
    }

    public function get($pattern, $handler)
    {
        return $this->addRoute(Route::GET, $pattern, $handler);
    }

    public function post($pattern, $handler)
    {
        return $this->addRoute(Route::POST, $pattern, $handler);
    }

    public function put($pattern, $handler)
    {
        return $this->addRoute(Route::PUT, $pattern, $handler);
    }

    public function patch($pattern, $handler)
    {
        return $this->addRoute(Route::PATCH, $pattern, $handler);
    }

    public function delete($pattern, $handler)
    {
        return $this->addRoute(Route::DELETE, $pattern, $handler);
    }

    public function options($pattern, $handler)
    {
        return $this->addRoute(Route::OPTIONS, $pattern, $handler);
    }

    public function any($pattern, $handler)
    {
        return $this->addRoute(Route::ANY, $pattern, $handler);
    }

    public function map(array $methods, $pattern, $handler)
    {
        return $this->addRoute($methods, $pattern, $handler);
    }

    public function getRoutes()
    {
        $routes = $this->routes;

        foreach ($this->groups as $group) {
            foreach ($group->getRoutes() as $route) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    public function getPrefix()
    {
        return $this->prefix;
    }

    public function getMiddlewares()
    {
        return $this->middlewares;
    }

    private function normalizePrefix($prefix)
    {
        $prefix = trim($prefix);
        if ($prefix === '' || $prefix === '/') {
            return '';
        }
        return '/' . trim($prefix, '/');
    }

    private function join($prefix, $pattern)
    {
        $pattern = '/' . ltrim($pattern, '/');
        $pattern = $pattern === '/' ? '/' : $pattern;

        if ($prefix === '') {
            return $pattern;
        }
        if ($pattern === '/') {
            return $prefix;
        }

        return $prefix . $pattern;
    }
}
