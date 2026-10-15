<?php
/**
 * Router: HTTP method + URI pattern dispatcher.
 *
 * Supports parameterized paths via "{name}" placeholders.
 * Matched parameters are passed as the first argument to the handler
 * (associative array). Returns null when no route matches so that
 * the caller may render a 404 page.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,params:string[],handler:callable}> */
    private array $routes = [];

    /**
     * Register a route.
     *
     * @param  string   $method  HTTP method, uppercase (GET|POST|...)
     * @param  string   $pattern URI pattern; '/api/skills/{id}'
     * @param  callable $handler fn(array $params, array $context): mixed
     * @return void
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex  = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $pattern
        );
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /** @param callable $handler */
    public function get(string $pattern, callable $handler): void    { $this->add('GET', $pattern, $handler); }
    /** @param callable $handler */
    public function post(string $pattern, callable $handler): void   { $this->add('POST', $pattern, $handler); }
    /** @param callable $handler */
    public function delete(string $pattern, callable $handler): void { $this->add('DELETE', $pattern, $handler); }

    /**
     * Dispatch a request to the first matching route. Returns null when no match.
     *
     * @param  string $method  HTTP method
     * @param  string $uri     Request URI (path only — query string stripped)
     * @param  array<string,mixed> $context Extra data passed to handler
     * @return mixed Handler return value, or null when no route matched.
     */
    public function dispatch(string $method, string $uri, array $context = []): mixed
    {
        $method = strtoupper($method);
        $path   = parse_url($uri, PHP_URL_PATH) ?: '/';
        foreach ($this->routes as $r) {
            if ($r['method'] !== $method) {
                continue;
            }
            if (preg_match($r['regex'], $path, $m) === 1) {
                $params = [];
                foreach ($r['params'] as $p) {
                    $params[$p] = $m[$p] ?? '';
                }
                return ($r['handler'])($params, $context);
            }
        }
        return null;
    }

    /**
     * @return array<int,array{method:string,pattern:string}> Read-only listing for diagnostics.
     */
    public function list(): array
    {
        return array_map(
            static fn(array $r): array => ['method' => $r['method'], 'pattern' => $r['pattern']],
            $this->routes
        );
    }
}
