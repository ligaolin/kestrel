<?php

namespace Kestrel\Router;

interface MiddlewareInterface
{
    /**
     * 处理请求并决定是否继续向下传递。
     *
     * @param Request  $request 当前请求
     * @param callable $next    下一个中间件（或最终处理器），返回 Response
     * @return mixed 通常应返回 Response 或可被转换为 Response 的值
     */
    public function handle(Request $request, callable $next);
}
