<?php

namespace Kestrel\Router\Middleware;

use Kestrel\Router\MiddlewareInterface;
use Kestrel\Router\Request;
use Kestrel\Router\Response;

/**
 * 请求日志中间件，记录方法、路径、状态码与耗时。
 *
 * 用法：
 *   $router->middleware(new LoggerMiddleware());                       // 默认 error_log
 *   $router->middleware(new LoggerMiddleware(function ($message) {
 *       file_put_contents('/tmp/access.log', $message . PHP_EOL, FILE_APPEND);
 *   }));
 */
class LoggerMiddleware implements MiddlewareInterface
{
    /** @var callable|null */
    private $logger;

    public function __construct($logger = null)
    {
        $this->logger = $logger;
    }

    public function handle(Request $request, callable $next)
    {
        $start = microtime(true);

        $response = $next($request);

        $elapsed = (microtime(true) - $start) * 1000;
        $status = $response instanceof Response ? $response->getStatusCode() : 200;

        $message = sprintf(
            '[%s] %s %s -> %d (%.2f ms)',
            date('Y-m-d H:i:s'),
            $request->getMethod(),
            $request->getUri(),
            $status,
            $elapsed
        );

        if ($this->logger === null) {
            error_log($message);
        } elseif (is_callable($this->logger)) {
            call_user_func($this->logger, $message, $request, $response);
        }

        return $response;
    }
}
