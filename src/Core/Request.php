<?php

declare(strict_types=1);

namespace Alpha\Core;

/**
 * HTTP Request wrapper.
 */
class Request
{
    private static ?self $instance = null;

    private array $get;
    private array $post;
    private array $server;
    private array $files;
    private array $cookies;
    private ?string $body = null;

    public function __construct()
    {
        $this->get     = $_GET;
        $this->post    = $_POST;
        $this->server  = $_SERVER;
        $this->files   = $_FILES;
        $this->cookies = $_COOKIE;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── URI / Method ──────────────────────────────────────────────────────────

    public function uri(): string
    {
        $uri = parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return '/' . ltrim($uri ?? '/', '/');
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function isPost(): bool { return $this->method() === 'POST'; }
    public function isGet():  bool { return $this->method() === 'GET'; }
    public function isAjax(): bool
    {
        return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    // ── Input ─────────────────────────────────────────────────────────────────

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->get[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->get, $this->post);
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }

    public function body(): string
    {
        if ($this->body === null) {
            $this->body = (string)file_get_contents('php://input');
        }
        return $this->body;
    }

    public function json(): mixed
    {
        return json_decode($this->body(), true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($this->server[$k])) {
                return explode(',', $this->server[$k])[0];
            }
        }
        return '0.0.0.0';
    }

    public function bearerToken(): ?string
    {
        $auth = $this->server['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Sanitize a string input value. */
    public function str(string $key, string $default = ''): string
    {
        $val = $this->input($key, $default);
        return htmlspecialchars(strip_tags((string)$val), ENT_QUOTES, 'UTF-8');
    }

    public function int(string $key, int $default = 0): int
    {
        return (int)($this->input($key, $default));
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->input($key, $default), FILTER_VALIDATE_BOOLEAN);
    }
}
