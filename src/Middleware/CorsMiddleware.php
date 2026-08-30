<?php

namespace Kestrel\Router\Middleware;

use Kestrel\Router\MiddlewareInterface;
use Kestrel\Router\Request;
use Kestrel\Router\Response;

/**
 * CORS 跨域中间件。
 *
 * 用法：
 *   $router->middleware(new CorsMiddleware(array(
 *       'origin'      => array('https://a.com', 'https://b.com'), // 或 '*'
 *       'methods'     => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
 *       'headers'     => 'Content-Type, Authorization, X-Requested-With',
 *       'credentials' => false,
 *       'expose_headers' => '',
 *       'max_age'     => 3600,
 *   )));
 */
class CorsMiddleware implements MiddlewareInterface
{
    /** @var array */
    private $origins = array('*');

    /** @var string */
    private $allowMethods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';

    /** @var string */
    private $allowHeaders = 'Content-Type, Authorization, X-Requested-With';

    /** @var bool */
    private $allowCredentials = false;

    /** @var string */
    private $exposeHeaders = '';

    /** @var int */
    private $maxAge = 0;

    public function __construct(array $options = array())
    {
        if (isset($options['origin'])) {
            $this->setOrigins($options['origin']);
        }
        if (isset($options['methods'])) {
            $this->allowMethods = (string) $options['methods'];
        }
        if (isset($options['headers'])) {
            $this->allowHeaders = (string) $options['headers'];
        }
        if (isset($options['credentials'])) {
            $this->allowCredentials = (bool) $options['credentials'];
        }
        if (isset($options['expose_headers'])) {
            $this->exposeHeaders = (string) $options['expose_headers'];
        }
        if (isset($options['max_age'])) {
            $this->maxAge = (int) $options['max_age'];
        }
    }

    /**
     * 允许的源：'*'、单个域名，或域名数组。
     */
    public function setOrigins($origins)
    {
        if ($origins === '*') {
            $this->origins = array('*');
        } else {
            $this->origins = array_values((array) $origins);
        }
        return $this;
    }

    public function allowMethods($methods)
    {
        $this->allowMethods = $methods;
        return $this;
    }

    public function allowHeaders($headers)
    {
        $this->allowHeaders = $headers;
        return $this;
    }

    public function allowCredentials($allow = true)
    {
        $this->allowCredentials = (bool) $allow;
        return $this;
    }

    public function exposeHeaders($headers)
    {
        $this->exposeHeaders = $headers;
        return $this;
    }

    public function maxAge($seconds)
    {
        $this->maxAge = (int) $seconds;
        return $this;
    }

    public function handle(Request $request, callable $next)
    {
        $response = $next($request);

        if (!$response instanceof Response) {
            $response = new Response((string) $response);
        }

        $this->apply($response, $request);

        return $response;
    }

    private function apply(Response $response, Request $request)
    {
        $origin = $request->header('Origin');
        $allowed = $this->resolveOrigin($origin);

        if ($allowed === null) {
            return;
        }

        $response->setHeader('Access-Control-Allow-Origin', $allowed);
        $response->setHeader('Vary', 'Origin');

        if ($this->allowCredentials) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }
        if ($this->exposeHeaders !== '') {
            $response->setHeader('Access-Control-Expose-Headers', $this->exposeHeaders);
        }

        // 预检请求额外返回允许的方法与请求头
        if ($request->getMethod() === 'OPTIONS') {
            $response->setHeader('Access-Control-Allow-Methods', $this->allowMethods);
            if ($this->allowHeaders !== '') {
                $response->setHeader('Access-Control-Allow-Headers', $this->allowHeaders);
            }
            if ($this->maxAge > 0) {
                $response->setHeader('Access-Control-Max-Age', (string) $this->maxAge);
            }
        }
    }

    private function resolveOrigin($origin)
    {
        if ($origin === null || $origin === '') {
            return null;
        }

        $origin = (string) $origin;

        if (in_array('*', $this->origins, true)) {
            // 携带凭证时不允许使用 '*'，回显具体源
            return $this->allowCredentials ? $origin : '*';
        }

        if (in_array($origin, $this->origins, true)) {
            return $origin;
        }

        return null;
    }
}
