<?php
/**
 * 极简路由：方法 + 路径（支持 {param} 占位 / 前缀分组）
 */
declare(strict_types=1);

namespace Wstat\Http;

class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'handler' => $handler];
    }

    public function get(string $p, callable $h): void { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void { $this->add('POST', $p, $h); }
    public function patch(string $p, callable $h): void { $this->add('PATCH', $p, $h); }
    public function delete(string $p, callable $h): void { $this->add('DELETE', $p, $h); }

    /** 注册一组带前缀的路由：$fn(Router $r) */
    public function group(string $prefix, callable $fn): void
    {
        $inner = new self();
        $fn($inner);
        $prefix = rtrim($prefix, '/');
        foreach ($inner->routes as $r) {
            $this->routes[] = [
                'method' => $r['method'],
                'pattern' => $prefix . $r['pattern'],
                'handler' => $r['handler'],
            ];
        }
    }

    /** 分发。未命中 404；OPTIONS 由 CORS 消费返回 204 */
    public function dispatch(Request $req): void
    {
        $this->applyCors();

        if ($req->method === 'OPTIONS') {
            http_response_code(204);
            exit(0);
        }

        foreach ($this->routes as $r) {
            if ($r['method'] !== $req->method) {
                continue;
            }
            $regex = '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $r['pattern']) . '$#';
            if (preg_match($regex, $req->path, $m)) {
                foreach ($m as $k => $v) {
                    if (!is_int($k)) {
                        $req->params[$k] = rawurldecode($v);
                    }
                }
                call_user_func($r['handler'], $req);
                return;
            }
        }
        wstat_err('Not Found', 404);
    }

    private function applyCors(): void
    {
        $c = wstat_config('cors');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allow = $c['allow_origin'] ?? '*';
        if ($allow === '*' || $origin === '') {
            header('Access-Control-Allow-Origin: *');
        } else {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: ' . ($c['allow_methods'] ?? 'GET,POST,PATCH,DELETE,OPTIONS'));
        header('Access-Control-Allow-Headers: ' . ($c['allow_headers'] ?? 'Content-Type,Authorization'));
        header('Access-Control-Max-Age: ' . ($c['max_age'] ?? 86400));
        header('Access-Control-Allow-Credentials: true');
    }
}
