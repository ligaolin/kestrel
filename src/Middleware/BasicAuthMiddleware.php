<?php

namespace Kestrel\Router\Middleware;

use Kestrel\Router\MiddlewareInterface;
use Kestrel\Router\Request;
use Kestrel\Router\Response;

/**
 * HTTP Basic 认证中间件。
 *
 * 用法一（静态用户表）：
 *   $router->middleware(new BasicAuthMiddleware(array('admin' => 'secret')));
 *
 * 用法二（自定义校验回调，可用于 password_verify）：
 *   $router->middleware(new BasicAuthMiddleware(function ($user, $pass) {
 *       return $user === 'admin' && password_verify($pass, $hash);
 *   }));
 */
class BasicAuthMiddleware implements MiddlewareInterface
{
    /** @var array|callable */
    private $users;

    /** @var string */
    private $realm;

    public function __construct($users = array(), $realm = 'Restricted')
    {
        $this->users = $users;
        $this->realm = $realm;
    }

    public function handle(Request $request, callable $next)
    {
        $credentials = $this->extractCredentials($request);
        $user = $credentials[0];
        $pass = $credentials[1];

        if (!$this->check($user, $pass)) {
            return (new Response('Unauthorized', Response::HTTP_UNAUTHORIZED))
                ->setHeader('WWW-Authenticate', 'Basic realm="' . $this->realm . '"');
        }

        $request->setAttribute('auth.user', $user);

        return $next($request);
    }

    private function extractCredentials(Request $request)
    {
        $header = $request->header('Authorization');
        if ($header === null || stripos($header, 'Basic ') !== 0) {
            return array(null, null);
        }

        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            return array(null, null);
        }

        return explode(':', $decoded, 2);
    }

    private function check($user, $pass)
    {
        if ($user === null) {
            return false;
        }

        if (is_callable($this->users)) {
            return (bool) call_user_func($this->users, $user, $pass);
        }

        if (is_array($this->users)) {
            return array_key_exists($user, $this->users)
                && $this->safeEquals((string) $this->users[$user], (string) $pass);
        }

        return false;
    }

    /**
     * 常量时间比较：PHP 5.6+ 用 hash_equals，5.4/5.5 使用回退实现。
     */
    private function safeEquals($known, $user)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($known, $user);
        }

        if (!is_string($known) || !is_string($user)) {
            return false;
        }

        $knownLen = strlen($known);
        $userLen = strlen($user);

        if ($knownLen !== $userLen) {
            return false;
        }

        $result = 0;
        for ($i = 0; $i < $knownLen; $i++) {
            $result |= ord($known[$i]) ^ ord($user[$i]);
        }

        return $result === 0;
    }
}
