<?php

declare(strict_types=1);

namespace Alpha\Core;

/**
 * HTTP Response helper.
 */
class Response
{
    private int    $status  = 200;
    private array  $headers = [];
    private string $body    = '';

    public function status(int $code): static
    {
        $this->status = $code;
        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function body(string $content): static
    {
        $this->body = $content;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("{$k}: {$v}");
        }
        echo $this->body;
    }

    // ── Static factories ──────────────────────────────────────────────────────

    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $url, int $status = 302): void
    {
        http_response_code($status);
        header("Location: {$url}");
        exit;
    }

    public static function notFound(string $message = 'Not Found'): void
    {
        http_response_code(404);
        // Let the router render the 404 view if available
        View::render('errors/404', ['message' => $message]);
        exit;
    }

    public static function forbidden(string $message = 'Forbidden'): void
    {
        http_response_code(403);
        View::render('errors/403', ['message' => $message]);
        exit;
    }

    public static function abort(int $code, string $message = ''): void
    {
        http_response_code($code);
        echo $message;
        exit;
    }
}
