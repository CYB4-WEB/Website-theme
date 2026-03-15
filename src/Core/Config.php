<?php

declare(strict_types=1);

namespace Alpha\Core;

/**
 * Configuration manager.
 * Loads .env, merges with config/config.php, and exposes get/set.
 */
class Config
{
    private static array $data = [];
    private static bool  $loaded = false;

    public static function load(string $root): void
    {
        if (self::$loaded) {
            return;
        }

        // 1. Load .env file
        $env = $root . '/.env';
        if (file_exists($env)) {
            foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                [$key, $val] = array_map('trim', explode('=', $line, 2));
                $val = trim($val, '"\'');
                self::$data[$key] = $val;
                if (!isset($_ENV[$key])) {
                    $_ENV[$key]    = $val;
                    putenv("{$key}={$val}");
                }
            }
        }

        // 2. Merge config/config.php defaults
        $cfg = $root . '/config/config.php';
        if (file_exists($cfg)) {
            $defaults = require $cfg;
            self::$data = array_merge($defaults, self::$data);
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$data[$key] = $value;
    }

    public static function all(): array
    {
        return self::$data;
    }

    /** Convenience helper: cast to bool. */
    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /** Convenience helper: cast to int. */
    public static function int(string $key, int $default = 0): int
    {
        return (int)(self::get($key) ?? $default);
    }
}
