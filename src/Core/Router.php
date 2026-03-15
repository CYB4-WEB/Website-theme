<?php

declare(strict_types=1);

namespace Alpha\Core;

/**
 * Simple named-parameter URL router.
 * Supports GET, POST, any-method routes.
 * Named params:  /manga/{slug}
 * Optional:      /user/{id?}
 * Wildcard:      /admin/{path*}
 */
class Router
{
    private array $routes      = [];
    private array $middleware  = [];
    private array $groupStack  = [];

    // ── Route registration ────────────────────────────────────────────────────

    public function get(string $pattern, string|callable $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, string|callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function any(string $pattern, string|callable $handler, array $middleware = []): void
    {
        $this->add('*', $pattern, $handler, $middleware);
    }

    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $this->groupStack[] = ['prefix' => $prefix, 'middleware' => $middleware];
        $callback($this);
        array_pop($this->groupStack);
    }

    private function add(string $method, string $pattern, string|callable $handler, array $mw = []): void
    {
        // Apply group prefixes
        $prefix = '';
        $groupMw = [];
        foreach ($this->groupStack as $g) {
            $prefix  .= $g['prefix'];
            $groupMw  = array_merge($groupMw, $g['middleware']);
        }
        $pattern = $prefix . $pattern;

        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'mw'      => array_merge($groupMw, $mw),
            'regex'   => $this->compilePattern($pattern),
            'params'  => $this->extractParamNames($pattern),
        ];
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    public function dispatch(Request $request): void
    {
        $uri    = $request->uri();
        $method = $request->method();

        foreach ($this->routes as $route) {
            if ($route['method'] !== '*' && $route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $uri, $matches)) {
                array_shift($matches);
                $params = array_combine($route['params'], array_slice($matches, 0, count($route['params'])));

                // Run middleware
                foreach ($route['mw'] as $mwClass) {
                    $mw = new $mwClass();
                    if (method_exists($mw, 'handle')) {
                        $mw->handle($request);
                    }
                }

                $this->callHandler($route['handler'], $params, $request);
                return;
            }
        }

        // 404
        Response::notFound();
    }

    private function callHandler(string|callable $handler, array $params, Request $request): void
    {
        if (is_callable($handler)) {
            $handler($request, $params);
            return;
        }

        // 'ControllerClass@method' or 'ControllerClass::method'
        $separator = str_contains($handler, '@') ? '@' : '::';
        [$class, $method] = explode($separator, $handler, 2);

        $ns = '\\Alpha\\Controllers\\';
        $fqcn = str_contains($class, '\\') ? $class : $ns . $class;

        if (!class_exists($fqcn)) {
            throw new \RuntimeException("Controller not found: {$fqcn}");
        }

        $controller = new $fqcn();
        $controller->$method($request, $params);
    }

    // ── Pattern compiler ──────────────────────────────────────────────────────

    private function compilePattern(string $pattern): string
    {
        // Escape everything except our param markers
        $regex = preg_quote($pattern, '#');

        // {name*} — greedy wildcard
        $regex = preg_replace('#\\\{(\w+)\*\\\}#', '(?P<$1>.+)', $regex);
        // {name?} — optional
        $regex = preg_replace('#\\\{(\w+)\\\?\\\}#', '(?P<$1>[^/]*)?', $regex);
        // {name}  — required segment
        $regex = preg_replace('#\\\{(\w+)\\\}#', '(?P<$1>[^/]+)', $regex);

        return '#^' . $regex . '/?$#';
    }

    private function extractParamNames(string $pattern): array
    {
        preg_match_all('#\{(\w+)[*?]?\}#', $pattern, $m);
        return $m[1];
    }
}
