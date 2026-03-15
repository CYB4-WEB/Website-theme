<?php
/**
 * Project Alpha – Front Controller
 * Point your web server document root at this directory.
 */

declare(strict_types=1);

define('ALPHA_ROOT', __DIR__);
define('ALPHA_START', microtime(true));

// ── Autoloader ───────────────────────────────────────────────────────────────
$autoload = ALPHA_ROOT . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(503);
    echo '<pre>Run <strong>composer install</strong> first.</pre>';
    exit;
}
require $autoload;

// ── Bootstrap & dispatch ─────────────────────────────────────────────────────
use Alpha\Core\App;

App::getInstance()->run();
