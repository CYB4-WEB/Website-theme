<?php

declare(strict_types=1);

namespace Alpha\Services;

use Alpha\Core\{Config, Database, Session};

/**
 * Security helpers: input sanitization, CSRF, nonces, rate-limiting, HTTP headers.
 */
class Security
{
    // ── Sanitization ──────────────────────────────────────────────────────────

    public static function sanitizeText(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    public static function sanitizeTextarea(string $input): string
    {
        // Allow line breaks but strip tags
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    public static function sanitizeEmail(string $email): string
    {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL) ?: '';
    }

    public static function sanitizeUrl(string $url): string
    {
        $sanitized = filter_var(trim($url), FILTER_SANITIZE_URL);
        return filter_var($sanitized, FILTER_VALIDATE_URL) ? $sanitized : '';
    }

    public static function sanitizeInt(mixed $val): int
    {
        return (int)filter_var($val, FILTER_SANITIZE_NUMBER_INT);
    }

    public static function sanitizeSlug(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9-]/', '-', $text);
        return preg_replace('/-+/', '-', $text);
    }

    public static function escHtml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    public static function escAttr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function escUrl(string $url): string
    {
        return self::sanitizeUrl($url);
    }

    // ── Nonces ────────────────────────────────────────────────────────────────

    /**
     * Generate a time-limited nonce for an action.
     * Valid for 12 hours (2 ticks of 6h each).
     */
    public static function createNonce(string $action): string
    {
        $tick   = (int)(time() / 21600); // 6-hour window
        $userId = Auth::id() ?? 0;
        $secret = Config::get('APP_SECRET', 'changeme');
        return substr(hash('sha256', "{$tick}|{$action}|{$userId}|{$secret}"), -12);
    }

    public static function verifyNonce(string $nonce, string $action): bool
    {
        $tick   = (int)(time() / 21600);
        $userId = Auth::id() ?? 0;
        $secret = Config::get('APP_SECRET', 'changeme');

        foreach ([$tick, $tick - 1] as $t) {
            $expected = substr(hash('sha256', "{$t}|{$action}|{$userId}|{$secret}"), -12);
            if (hash_equals($expected, $nonce)) {
                return true;
            }
        }
        return false;
    }

    public static function nonceField(string $action): string
    {
        $nonce = self::createNonce($action);
        $action = self::escAttr($action);
        return "<input type=\"hidden\" name=\"_nonce\" value=\"{$nonce}\"><input type=\"hidden\" name=\"_action\" value=\"{$action}\">";
    }

    // ── Honeypot ──────────────────────────────────────────────────────────────

    public static function honeypotField(): string
    {
        return '<input type="text" name="website" value="" style="display:none!important" tabindex="-1" autocomplete="off">';
    }

    public static function checkHoneypot(array $post): bool
    {
        // Should be empty – bots fill it in
        return empty($post['website']);
    }

    // ── HTTP Security headers ─────────────────────────────────────────────────

    public static function sendSecurityHeaders(): void
    {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

        $appUrl = Config::get('APP_URL', '');
        header("Content-Security-Policy: default-src 'self' {$appUrl}; script-src 'self' 'unsafe-inline' https://www.google.com https://www.gstatic.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; frame-ancestors 'none';");
    }

    // ── MIME validation ───────────────────────────────────────────────────────

    public static function getFileMimeType(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $type  = finfo_file($finfo, $path);
            finfo_close($finfo);
            return $type ?: 'application/octet-stream';
        }
        return mime_content_type($path) ?: 'application/octet-stream';
    }

    public static function isAllowedImageType(string $path): bool
    {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        return in_array(self::getFileMimeType($path), $allowed, true);
    }

    public static function isAllowedVideoType(string $path): bool
    {
        $allowed = ['video/mp4', 'video/webm', 'application/x-mpegURL', 'video/x-m4v'];
        return in_array(self::getFileMimeType($path), $allowed, true);
    }

    // ── IP helpers ────────────────────────────────────────────────────────────

    public static function getIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                return explode(',', $_SERVER[$k])[0];
            }
        }
        return '0.0.0.0';
    }
}
