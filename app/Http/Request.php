<?php
/**
 * 极简 HTTP 请求对象
 */
declare(strict_types=1);

namespace Wstat\Http;

class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $body;
    public array $params = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $uri = parse_url($uri, PHP_URL_PATH) ?: '/';
        $this->path = rtrim($uri, '/') ?: '/';
        $this->query = $_GET;
        $this->body = wstat_body();
    }

    public function param(string $k, $default = null)
    {
        return $this->params[$k] ?? $default;
    }

    public function input(string $k, $default = null)
    {
        if (array_key_exists($k, $this->body)) {
            return $this->body[$k];
        }
        if (array_key_exists($k, $this->query)) {
            return $this->query[$k];
        }
        return $default;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$key] ?? '');
    }

    /** 提取 Bearer token */
    public function bearer(): string
    {
        $h = $this->header('Authorization');
        if (preg_match('/Bearer\s+(.+)/i', $h, $m)) {
            return trim($m[1]);
        }
        return (string) $this->input('token', '');
    }
}
