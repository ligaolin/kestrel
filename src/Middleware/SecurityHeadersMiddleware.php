<?php

namespace Kestrel\Router\Middleware;

use Kestrel\Router\MiddlewareInterface;
use Kestrel\Router\Request;
use Kestrel\Router\Response;

/**
 * 追加常用安全响应头。
 *
 * 用法：
 *   $router->middleware(new SecurityHeadersMiddleware());
 *   // 覆盖默认值
 *   $router->middleware(new SecurityHeadersMiddleware(array(
 *       'X-Frame-Options' => 'DENY',
 *   )));
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @var array */
    private $headers;

    public function __construct(array $headers = array())
    {
        $this->headers = array_merge(array(
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'no-referrer-when-downgrade',
        ), $headers);
    }

    public function handle(Request $request, callable $next)
    {
        $response = $next($request);

        if ($response instanceof Response) {
            $response->withHeaders($this->headers);
        }

        return $response;
    }
}
