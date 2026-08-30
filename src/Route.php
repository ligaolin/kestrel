<?php

namespace Kestrel\Router;

class Route
{
    const GET = 'GET';
    const POST = 'POST';
    const PUT = 'PUT';
    const PATCH = 'PATCH';
    const DELETE = 'DELETE';
    const OPTIONS = 'OPTIONS';
    const HEAD = 'HEAD';
    const ANY = '*';

    /** @var array */
    private $methods = array();

    /** @var string */
    private $pattern;

    /** @var mixed */
    private $handler;

    /** @var array */
    private $middlewares = array();

    /** @var string|null */
    private $name;

    /**
     * @param string|string[] $methods
     */
    public function __construct($methods, $pattern, $handler)
    {
        $this->methods = array_values(array_unique(array_map('strtoupper', (array) $methods)));
        $this->pattern = $this->normalizePattern($pattern);
        $this->handler = $handler;
    }

    public function getMethods()
    {
        return $this->methods;
    }

    public function getMethod()
    {
        return isset($this->methods[0]) ? $this->methods[0] : '';
    }

    public function getPattern()
    {
        return $this->pattern;
    }

    public function getHandler()
    {
        return $this->handler;
    }

    public function getMiddlewares()
    {
        return $this->middlewares;
    }

    /**
     * 判断该路由是否接受某个 HTTP 方法（HEAD 自动回退到 GET）。
     */
    public function allowsMethod($method)
    {
        $method = strtoupper($method);

        if (in_array($method, $this->methods, true)) {
            return true;
        }
        if (in_array(self::ANY, $this->methods, true)) {
            return true;
        }
        if ($method === self::HEAD && in_array(self::GET, $this->methods, true)) {
            return true;
        }

        return false;
    }

    public function middleware($middleware)
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function addMiddleware($middleware)
    {
        return $this->middleware($middleware);
    }

    public function setMiddlewares(array $middlewares)
    {
        $this->middlewares = $middlewares;
        return $this;
    }

    public function name($name)
    {
        $this->name = $name;
        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    private function normalizePattern($pattern)
    {
        $pattern = '/' . ltrim($pattern, '/');
        return $pattern === '/' ? '/' : $pattern;
    }
}
