<?php

namespace Kestrel\Router;

class Response
{
    const HTTP_CONTINUE = 100;
    const HTTP_OK = 200;
    const HTTP_CREATED = 201;
    const HTTP_ACCEPTED = 202;
    const HTTP_NO_CONTENT = 204;
    const HTTP_MOVED_PERMANENTLY = 301;
    const HTTP_FOUND = 302;
    const HTTP_NOT_MODIFIED = 304;
    const HTTP_BAD_REQUEST = 400;
    const HTTP_UNAUTHORIZED = 401;
    const HTTP_FORBIDDEN = 403;
    const HTTP_NOT_FOUND = 404;
    const HTTP_METHOD_NOT_ALLOWED = 405;
    const HTTP_UNPROCESSABLE_ENTITY = 422;
    const HTTP_TOO_MANY_REQUESTS = 429;
    const HTTP_INTERNAL_SERVER_ERROR = 500;

    /** @var string */
    private $content = '';

    /** @var int */
    private $statusCode = 200;

    /** @var array */
    private $headers = array();

    public function __construct($content = '', $statusCode = 200, array $headers = array())
    {
        $this->content = (string) $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public static function text($content, $statusCode = 200)
    {
        return (new self($content, $statusCode))
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public static function html($content, $statusCode = 200)
    {
        return (new self($content, $statusCode))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    public static function json($data, $statusCode = 200, $options = 0)
    {
        $options |= JSON_UNESCAPED_UNICODE;
        return (new self(json_encode($data, $options), $statusCode))
            ->header('Content-Type', 'application/json; charset=utf-8');
    }

    public static function redirect($url, $statusCode = 302)
    {
        return (new self('', $statusCode))->header('Location', $url);
    }

    public function header($name, $value)
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withHeaders(array $headers)
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function status($code)
    {
        $this->statusCode = $code;
        return $this;
    }

    public function getContent()
    {
        return $this->content;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function setContent($content)
    {
        $this->content = (string) $content;
        return $this;
    }

    public function setStatusCode($code)
    {
        return $this->status($code);
    }

    public function setHeader($name, $value)
    {
        return $this->header($name, $value);
    }

    public function send()
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->content;
    }

    public function __toString()
    {
        return $this->content;
    }
}
