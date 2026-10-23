<?php

namespace Kestrel\Router;

use Closure;
use Kestrel\Router\Exception\MethodNotAllowedException;
use Kestrel\Router\Exception\RouteNotFoundException;

class Router
{
    /** @var Route[] */
    private $routes = array();

    /** @var array */
    private $globalMiddlewares = array();

    /** @var string 控制器命名空间前缀 */
    private $namespace = '';

    /**
     * 设置控制器命名空间前缀，例如 "App\\Controllers"。
     */
    public function setNamespace($namespace)
    {
        $namespace = trim($namespace, '\\');
        $this->namespace = $namespace === '' ? '' : $namespace . '\\';
        return $this;
    }

    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * 注册全局中间件。
     */
    public function middleware($middleware)
    {
        $this->globalMiddlewares[] = $middleware;
        return $this;
    }

    public function addGlobalMiddleware($middleware)
    {
        return $this->middleware($middleware);
    }

    public function addRoute($methods, $pattern, $handler)
    {
        $route = new Route($methods, $pattern, $handler);
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

    /**
     * 为同一路径注册多个 HTTP 方法。
     */
    public function map(array $methods, $pattern, $handler)
    {
        return $this->addRoute($methods, $pattern, $handler);
    }

    /**
     * 路由分组。
     */
    public function group($prefix, callable $callback, array $middlewares = array())
    {
        $group = new RouteGroup($prefix, $middlewares);
        $callback($group);

        foreach ($group->getRoutes() as $route) {
            $this->routes[] = $route;
        }

        return $group;
    }

    public function getRoutes()
    {
        return $this->routes;
    }

    /**
     * 分发请求并返回 Response。
     * 未传 $request 时自动从当前运行环境构建。
     *
     * 出错时抛出异常而不是返回错误响应：
     *   - 无匹配路由：RouteNotFoundException
     *   - 方法不允许：MethodNotAllowedException
     *   - 处理器/中间件抛出的其它异常会原样向上传播
     *
     * @param Request|null $request
     * @throws RouteNotFoundException
     * @throws MethodNotAllowedException
     */
    public function dispatch($request = null)
    {
        if ($request === null) {
            $request = Request::createFromGlobals();
        } elseif (!($request instanceof Request)) {
            throw new \InvalidArgumentException('Argument 1 passed to dispatch() must be an instance of Kestrel\Router\Request or null.');
        }

        return $this->runThroughMiddlewares(
            $request,
            function (Request $request) {
                return $this->dispatchCore($request);
            },
            $this->globalMiddlewares
        );
    }

    /**
     * 实际分发：匹配路由、处理 OPTIONS/404/405，并执行路由级中间件与处理器。
     */
    private function dispatchCore(Request $request)
    {
        $method = $request->getMethod();
        $uri = $request->getUri();

        // 自动处理 OPTIONS 预检
        if ($method === Route::OPTIONS) {
            $matched = $this->findRoutesByPath($uri);
            if (!empty($matched)) {
                $allowed = array();
                foreach ($matched as $route) {
                    $allowed = array_merge($allowed, $route->getMethods());
                }
                return (new Response('', Response::HTTP_NO_CONTENT))
                    ->header('Allow', implode(', ', $this->expandMethods($allowed)));
            }
            throw new RouteNotFoundException("No route matches OPTIONS {$uri}");
        }

        // 命中返回 array；路径存在但方法不允许时抛出 MethodNotAllowedException
        $result = $this->match($method, $uri);

        if ($result === null) {
            throw new RouteNotFoundException("No route matches {$method} {$uri}");
        }

        $request->setRouteParams($result['params']);

        return $this->runThroughMiddlewares(
            $request,
            function (Request $request) use ($result) {
                return $this->executeHandler($result['route']->getHandler(), $request);
            },
            $result['route']->getMiddlewares()
        );
    }

    /**
     * 匹配路由。
     *
     * @return array|null 命中时返回 array('route' => Route, 'params' => array)；未命中返回 null
     * @throws MethodNotAllowedException 路径存在但方法不允许时抛出
     */
    public function match($method, $uri)
    {
        $method = strtoupper($method);
        $uri = '/' . ltrim($uri, '/');

        $pathMatched = false;
        $allowedMethods = array();

        foreach ($this->routes as $route) {
            $regex = $this->patternToRegex($route->getPattern());
            $matched = preg_match($regex, $uri, $matches);

            if ($matched === false) {
                throw new \RuntimeException('Invalid route pattern: ' . $route->getPattern());
            }
            if ($matched === 0) {
                continue;
            }

            $allowedMethods = array_merge($allowedMethods, $route->getMethods());

            if ($route->allowsMethod($method)) {
                $params = array();
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }
                return array('route' => $route, 'params' => $params);
            }

            $pathMatched = true;
        }

        if ($pathMatched) {
            throw new MethodNotAllowedException($this->expandMethods($allowedMethods));
        }

        return null;
    }

    /**
     * 根据命名路由生成 URL。
     */
    public function url($name, array $params = array())
    {
        foreach ($this->routes as $route) {
            if ($route->getName() === $name) {
                return $this->generateUrl($route->getPattern(), $params);
            }
        }

        throw new \InvalidArgumentException("Named route not found: {$name}");
    }

    public function route($name, array $params = array())
    {
        return $this->url($name, $params);
    }

    private function findRoutesByPath($uri)
    {
        $uri = '/' . ltrim($uri, '/');
        $result = array();

        foreach ($this->routes as $route) {
            $matched = preg_match($this->patternToRegex($route->getPattern()), $uri);
            if ($matched === false) {
                throw new \RuntimeException('Invalid route pattern: ' . $route->getPattern());
            }
            if ($matched === 1) {
                $result[] = $route;
            }
        }

        return $result;
    }

    private function generateUrl($pattern, array $params)
    {
        $url = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?(?::[^{}]+)?\}/',
            function ($m) use ($params) {
                $name = $m[1];
                $optional = $m[2] !== '';

                if (array_key_exists($name, $params) && $params[$name] !== null) {
                    return rawurlencode((string) $params[$name]);
                }
                if ($optional) {
                    return '';
                }
                throw new \InvalidArgumentException("Missing required parameter \"{$name}\" for route generation.");
            },
            $pattern
        );

        $url = preg_replace('#/+#', '/', $url);
        if ($url !== '/') {
            $url = rtrim($url, '/');
        }
        return $url === '' ? '/' : $url;
    }

    // ---------- 中间件管道 ----------

    private function runThroughMiddlewares(Request $request, callable $core, array $middlewares)
    {
        $next = function (Request $request) use ($core) {
            return $this->normalizeResponse($core($request));
        };

        foreach (array_reverse($middlewares) as $middleware) {
            $next = $this->wrapMiddleware($middleware, $next);
        }

        return $next($request);
    }

    private function wrapMiddleware($middleware, callable $next)
    {
        $middleware = $this->resolveMiddleware($middleware);

        return function (Request $request) use ($middleware, $next) {
            if ($middleware instanceof MiddlewareInterface) {
                $result = $middleware->handle($request, $next);
            } else {
                $result = $middleware($request, $next);
            }

            return $this->normalizeResponse($result);
        };
    }

    private function resolveMiddleware($middleware)
    {
        if ($middleware instanceof MiddlewareInterface || $middleware instanceof Closure) {
            return $middleware;
        }

        if (is_string($middleware) && class_exists($middleware)) {
            $instance = new $middleware();
            if ($instance instanceof MiddlewareInterface) {
                return $instance;
            }
            if (is_callable($instance)) {
                return $instance;
            }
            throw new \RuntimeException("Middleware class must implement MiddlewareInterface or be invokable: {$middleware}");
        }

        if (is_object($middleware) && is_callable($middleware)) {
            return $middleware;
        }

        throw new \RuntimeException('Invalid middleware: ' . (is_object($middleware) ? get_class($middleware) : gettype($middleware)));
    }

    private function normalizeResponse($result)
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        if (is_object($result) && !method_exists($result, '__toString')) {
            return Response::json($result);
        }
        return new Response((string) $result);
    }

    // ---------- 处理器解析与执行 ----------

    /**
     * 支持：
     *  1. 闭包 function (Request $request) {}
     *  2. 数组 array('UserController', 'index') 或 array(new UserController(), 'index')
     *  3. 字符串 'UserController@index'
     *  4. 字符串 'UserController::index'（静态方法 / 可调用类）
     *  5. 单个类名，默认调用 index（或 __invoke）
     *  6. 其它可调用对象
     */
    private function resolveHandler($handler)
    {
        if ($handler instanceof Closure) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2) {
            list($controller, $method) = $handler;
            if (is_string($controller)) {
                $controller = $this->resolveClass($controller);
            }
            return array($controller, $method);
        }

        if (is_string($handler)) {
            if (strpos($handler, '@') !== false) {
                list($controller, $method) = explode('@', $handler, 2);
                return array($this->resolveClass($controller), $method);
            }

            if (strpos($handler, '::') !== false) {
                $handler = $this->resolveClass($handler);
                if (is_callable($handler)) {
                    return $handler;
                }
                throw new \RuntimeException("Uncallable handler: {$handler}");
            }

            $class = $this->resolveClass($handler);
            if (class_exists($class)) {
                if (method_exists($class, '__invoke')) {
                    return $class;
                }
                return array($class, 'index');
            }
        }

        if (is_callable($handler)) {
            return $handler;
        }

        return $handler;
    }

    private function resolveClass($class)
    {
        if (strpos($class, '\\') === 0) {
            return ltrim($class, '\\');
        }
        return $this->namespace . ltrim($class, '\\');
    }

    private function executeHandler($handler, Request $request)
    {
        $resolved = $this->resolveHandler($handler);

        if ($resolved instanceof Closure) {
            return $this->invoke($resolved, $request);
        }

        if (is_array($resolved) && count($resolved) === 2) {
            list($controller, $method) = $resolved;

            if (is_string($controller)) {
                if (!class_exists($controller)) {
                    throw new \RuntimeException("Controller class not found: {$controller}");
                }
                $controller = new $controller();
            }

            if (!is_object($controller) || !method_exists($controller, $method)) {
                $name = is_object($controller) ? get_class($controller) : (string) $controller;
                throw new \RuntimeException("Method not found: {$name}::{$method}");
            }

            return $this->invoke(array($controller, $method), $request);
        }

        if (is_callable($resolved)) {
            return $this->invoke($resolved, $request);
        }

        return $resolved;
    }

    /**
     * 通过反射调用，允许处理器方法声明 0 个或 1 个参数。
     */
    private function invoke($callable, Request $request)
    {
        if (is_array($callable)) {
            $reflection = new \ReflectionMethod($callable[0], $callable[1]);
            // 静态方法传 null，实例方法传实例对象
            $object = $reflection->isStatic() ? null : $callable[0];
        } elseif (is_string($callable) && strpos($callable, '::') !== false) {
            $reflection = new \ReflectionMethod($callable);
            $object = null;
        } elseif (is_object($callable) && !($callable instanceof Closure)) {
            $reflection = new \ReflectionMethod($callable, '__invoke');
            $object = $callable;
        } else {
            $reflection = new \ReflectionFunction($callable);
            if (count($reflection->getParameters()) === 0) {
                return $reflection->invokeArgs(array());
            }
            return $reflection->invokeArgs(array($request));
        }

        if (count($reflection->getParameters()) === 0) {
            return $reflection->invokeArgs($object, array());
        }

        return $reflection->invokeArgs($object, array($request));
    }

    // ---------- 路径匹配 ----------

    /**
     * 将路由模式转换为正则表达式。
     * 支持：
     *   /user/{id}            任意非斜杠字符
     *   /user/{id:\d+}        自定义正则约束
     *   /file/{path?}         可选参数（含其前的斜杠）
     */
    private function patternToRegex($pattern)
    {
        if (!preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?(?::([^{}]+))?\}/', $pattern, $matches, PREG_OFFSET_CAPTURE)) {
            return '#^' . preg_quote($pattern, '#') . '$#';
        }

        $regex = '';
        $offset = 0;

        foreach ($matches[0] as $i => $full) {
            list($fullText, $position) = $full;

            $static = substr($pattern, $offset, $position - $offset);
            $name = $matches[1][$i][0];
            $optional = $matches[2][$i][0] !== '';
            $sub = isset($matches[3][$i][0]) && $matches[3][$i][0] !== '' ? $matches[3][$i][0] : '[^/]+';

            if ($optional) {
                $static = preg_replace('#/$#', '', $static);
                $regex .= preg_quote($static, '#') . '(?:/(?P<' . $name . '>' . $sub . '))?';
            } else {
                $regex .= preg_quote($static, '#') . '(?P<' . $name . '>' . $sub . ')';
            }

            $offset = $position + strlen($fullText);
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');

        return '#^' . $regex . '$#';
    }

    private function expandMethods(array $methods)
    {
        $all = array(
            Route::GET,
            Route::POST,
            Route::PUT,
            Route::PATCH,
            Route::DELETE,
            Route::OPTIONS,
            Route::HEAD,
        );

        $result = array();
        foreach ($methods as $method) {
            if ($method === Route::ANY) {
                $result = array_merge($result, $all);
            } else {
                $result[] = $method;
            }

            if ($method === Route::GET) {
                $result[] = Route::HEAD;
            }
        }

        return array_values(array_unique($result));
    }
}
