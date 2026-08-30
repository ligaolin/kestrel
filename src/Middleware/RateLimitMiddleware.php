<?php

namespace Kestrel\Router\Middleware;

use Kestrel\Router\MiddlewareInterface;
use Kestrel\Router\Request;
use Kestrel\Router\Response;

/**
 * 基于固定时间窗口的简单限流中间件（按客户端 IP 计数）。
 *
 * 默认把计数写入系统临时目录，跨请求持久化（适合常规 PHP-FPM 环境）。
 * 也可传入自定义存储目录。
 *
 * 用法：
 *   $router->middleware(new RateLimitMiddleware(60, 60)); // 60 秒内最多 60 次
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /** @var int 窗口内最大请求数 */
    private $limit;

    /** @var int 窗口时长（秒） */
    private $window;

    /** @var string 计数文件目录 */
    private $storagePath;

    public function __construct($limit = 60, $window = 60, $storagePath = null)
    {
        $this->limit = (int) $limit;
        $this->window = (int) $window;
        $this->storagePath = $storagePath !== null
            ? $storagePath
            : (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kestrel-rate-limit');
    }

    public function handle(Request $request, callable $next)
    {
        $key = $this->resolveKey($request);
        $now = time();
        $bucket = (int) floor($now / $this->window);

        $data = $this->load($key);
        if ($data === null || $data['bucket'] !== $bucket) {
            $data = array('bucket' => $bucket, 'count' => 0);
        }

        $data['count']++;
        $remaining = $this->limit - $data['count'];
        $this->save($key, $data);

        if ($remaining < 0) {
            $retryAfter = ($bucket + 1) * $this->window - $now;
            return (new Response('Too Many Requests', Response::HTTP_TOO_MANY_REQUESTS))
                ->setHeader('Retry-After', (string) $retryAfter);
        }

        $response = $next($request);
        if ($response instanceof Response) {
            $response->setHeader('X-RateLimit-Limit', (string) $this->limit);
            $response->setHeader('X-RateLimit-Remaining', (string) max(0, $remaining));
        }

        return $response;
    }

    private function resolveKey(Request $request)
    {
        $forwarded = $request->header('X-Forwarded-For');
        $ip = null;

        if ($forwarded) {
            $parts = explode(',', $forwarded);
            $ip = trim($parts[0]);
        }
        if (!$ip) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        }

        return $ip;
    }

    private function file($key)
    {
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0777, true);
        }
        return $this->storagePath . DIRECTORY_SEPARATOR . md5($key) . '.json';
    }

    private function load($key)
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    private function save($key, array $data)
    {
        $file = $this->file($key);
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return;
        }

        if (flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            fwrite($handle, json_encode($data));
            fflush($handle);
            flock($handle, LOCK_UN);
        }

        fclose($handle);
    }
}
