<?php

declare(strict_types=1);

namespace Alpha\Core;

/**
 * Application bootstrap. Single entry point.
 */
class App
{
    private static ?self $instance = null;
    private Router  $router;
    private Request $request;

    private function __construct()
    {
        // Load config & environment
        Config::load(ALPHA_ROOT);

        // Start session
        Session::start();

        // Set view path
        View::setPath(ALPHA_ROOT . '/views');

        // Error reporting
        if (Config::bool('APP_DEBUG')) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
            set_exception_handler([$this, 'handleException']);
            set_error_handler([$this, 'handleError']);
        }

        $this->request = Request::getInstance();
        $this->router  = new Router();

        // Load route definitions
        $routes = ALPHA_ROOT . '/routes.php';
        if (file_exists($routes)) {
            require $routes;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function run(): void
    {
        $this->router->dispatch($this->request);
    }

    // ── Error handlers ────────────────────────────────────────────────────────

    public function handleException(\Throwable $e): void
    {
        error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        View::render('errors/500', ['error' => $e->getMessage()]);
    }

    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        error_log("{$errstr} in {$errfile}:{$errline}");
        return true;
    }
}
