<?php

namespace Kestrel\Router\Middleware;

use Kestrel\Router\MiddlewareInterface;
use Kestrel\Router\Request;

/**
 * 方法覆盖中间件：让不支持 PUT/PATCH/DELETE 的表单也能发这些请求。
 *
 * 支持两种来源（仅当当前方法为 POST 时生效）：
 *  1. 请求头 X-HTTP-Method-Override: PUT
 *  2. 表单字段 _method=PUT
 *
 * 用法：
 *   $router->middleware(new MethodOverrideMiddleware());
 */
class MethodOverrideMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next)
    {
        if ($request->getMethod() === 'POST') {
            $override = $request->header('X-HTTP-Method-Override');

            if (!$override) {
                $override = $request->input('_method');
            }

            if ($override) {
                $request->setMethod(strtoupper((string) $override));
            }
        }

        return $next($request);
    }
}
