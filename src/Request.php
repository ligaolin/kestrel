<?php

namespace Kestrel\Router;

class Request
{
    /** @var string */
    private $method;

    /** @var string */
    private $uri;

    /** @var array */
    private $query = array();

    /** @var array */
    private $body = array();

    /** @var array */
    private $routeParams = array();

    /** @var array */
    private $attributes = array();

    /** @var array */
    private $headers = array();

    public function __construct($method, $uri, array $query = array(), array $body = array(), array $headers = array())
    {
        $this->method = strtoupper($method);
        $this->uri = $this->normalizeUri($uri);
        $this->query = $query;
        $this->body = $body;
        $this->headers = $headers;
    }

    /**
     * 从当前 PHP 运行环境的全局变量构建请求对象。
     */
    public static function createFromGlobals()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $uri = parse_url($uri, PHP_URL_PATH) ?: '/';

        // 去掉入口脚本（如 /index.php）
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        if ($scriptName !== '' && $uri !== '' && strpos($uri, $scriptName) === 0) {
            $uri = substr($uri, strlen($scriptName));
        }

        $headers = self::resolveHeaders();
        $body = self::parseBody($method, $headers);

        return new self($method, $uri, $_GET, $body, $headers);
    }

    private static function resolveHeaders()
    {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }

        $headers = array();
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    private static function parseBody($method, array $headers)
    {
        if ($method === 'GET' || $method === 'HEAD') {
            return $_POST;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $_POST;
        }

        $contentType = isset($headers['Content-Type'])
            ? $headers['Content-Type']
            : (isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '');

        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode($raw, true);
            return is_array($data) ? $data : $_POST;
        }

        parse_str($raw, $parsed);
        return array_merge($_POST, $parsed);
    }

    // ---------- 基本信息 ----------

    public function getMethod()
    {
        return $this->method;
    }

    public function getUri()
    {
        return $this->uri;
    }

    public function getPath()
    {
        return $this->uri;
    }

    public function isMethod($method)
    {
        return $this->method === strtoupper($method);
    }

    public function setMethod($method)
    {
        $this->method = strtoupper($method);
        return $this;
    }

    // ---------- 路由参数 ----------

    public function setRouteParams(array $params)
    {
        $this->routeParams = $params;
        return $this;
    }

    public function getRouteParams()
    {
        return $this->routeParams;
    }

    public function param($key, $default = null)
    {
        return array_key_exists($key, $this->routeParams) ? $this->routeParams[$key] : $default;
    }

    // ---------- 查询参数 ----------

    public function query($key, $default = null)
    {
        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    public function getQuery()
    {
        return $this->query;
    }

    // ---------- 请求体 ----------

    public function input($key, $default = null)
    {
        return array_key_exists($key, $this->body) ? $this->body[$key] : $default;
    }

    public function getBody()
    {
        return $this->body;
    }

    // ---------- 统一查找：路由参数 > query > body ----------

    public function get($key, $default = null)
    {
        if (array_key_exists($key, $this->routeParams)) {
            return $this->routeParams[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }
        return $default;
    }

    public function all()
    {
        return array_merge($this->body, $this->query, $this->routeParams);
    }

    public function only($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $all = $this->all();
        $result = array();
        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                $result[$key] = $all[$key];
            }
        }
        return $result;
    }

    public function has($key)
    {
        return array_key_exists($key, $this->routeParams)
            || array_key_exists($key, $this->query)
            || array_key_exists($key, $this->body);
    }

    // ---------- 请求头 ----------

    public function header($key, $default = null)
    {
        foreach ($this->headers as $name => $value) {
            if (strcasecmp($name, $key) === 0) {
                return $value;
            }
        }
        return $default;
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    // ---------- 中间件共享数据 ----------

    public function setAttribute($key, $value)
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function getAttribute($key, $default = null)
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    private function normalizeUri($uri)
    {
        $uri = parse_url($uri, PHP_URL_PATH) ?: $uri;
        if ($uri === '') {
            return '/';
        }
        return '/' . ltrim($uri, '/');
    }
}
