<?php

declare(strict_types=1);

namespace Alpha\Core;

/**
 * PHP native template renderer.
 * Templates live in views/ and can extend layouts via $layout.
 */
class View
{
    private static string $viewPath = '';

    public static function setPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/');
    }

    /**
     * Render a view file into a string.
     * Within the view, calling $this->section() / $this->yield() is not available
     * (use include for partials instead, or the layout wrapper).
     */
    public static function render(string $view, array $data = [], bool $return = false): string
    {
        $path = self::$viewPath . '/' . str_replace('.', '/', $view) . '.php';

        if (!file_exists($path)) {
            throw new \RuntimeException("View not found: {$view} ({$path})");
        }

        // Make data keys available as variables
        extract($data, EXTR_SKIP);

        // Helpers available in every view
        $csrf   = Session::csrfField();
        $flash  = Session::flashAll();
        $config = fn(string $k, mixed $d = null) => Config::get($k, $d);
        $url    = fn(string $path = '') => self::url($path);
        $asset  = fn(string $p) => self::asset($p);
        $e      = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $user   = \Alpha\Services\Auth::user();

        ob_start();
        require $path;
        $content = ob_get_clean();

        if ($return) {
            return $content;
        }

        echo $content;
        return '';
    }

    /**
     * Render a view inside a layout.
     * The layout file receives $content with the rendered inner view.
     */
    public static function renderWithLayout(
        string $layout,
        string $view,
        array  $data = [],
        array  $layoutData = []
    ): void {
        $content = self::render($view, $data, true);
        self::render($layout, array_merge($layoutData, ['content' => $content]));
    }

    /**
     * Render a partial (short-hand for nested views).
     */
    public static function partial(string $partial, array $data = []): string
    {
        return self::render('partials/' . $partial, $data, true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function url(string $path = ''): string
    {
        $base = rtrim(Config::get('APP_URL', ''), '/');
        return $base . '/' . ltrim($path, '/');
    }

    public static function asset(string $path): string
    {
        return self::url('assets/' . ltrim($path, '/'));
    }

    public static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
