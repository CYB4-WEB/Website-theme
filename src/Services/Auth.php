<?php

declare(strict_types=1);

namespace Alpha\Services;

use Alpha\Core\{Config, Database, Session};

/**
 * Authentication service.
 * Handles login, registration, password reset, role checks.
 * Mirrors the WP class-user-auth.php capabilities without WordPress.
 */
class Auth
{
    private const MAX_ATTEMPTS  = 5;
    private const LOCKOUT_MINS  = 15;
    private const SESSION_KEY   = '_alpha_user';
    private const REMEMBER_DAYS = 7;

    // ── Current user ──────────────────────────────────────────────────────────

    public static function user(): ?array
    {
        // 1. Session
        $u = Session::get(self::SESSION_KEY);
        if ($u) {
            return $u;
        }

        // 2. Remember-me cookie
        $token = $_COOKIE['alpha_remember'] ?? null;
        if ($token) {
            $u = self::userByRememberToken($token);
            if ($u) {
                Session::set(self::SESSION_KEY, $u);
                return $u;
            }
        }

        return null;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function guest(): bool
    {
        return !self::check();
    }

    public static function role(): string
    {
        return self::user()['role'] ?? 'guest';
    }

    /**
     * Check if the current user has a capability.
     * Simple role-based: admin > starter_editor > starter_author > starter_vip > starter_member > guest
     */
    public static function can(string $cap): bool
    {
        $user = self::user();
        if (!$user) {
            return false;
        }
        $caps = self::roleCaps($user['role']);
        return in_array($cap, $caps, true) || in_array('*', $caps, true);
    }

    public static function isAdmin(): bool
    {
        return in_array(self::role(), ['admin', 'administrator'], true);
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    /**
     * Attempt login. Returns ['success' => bool, 'message' => string, 'user' => array|null].
     */
    public static function attempt(string $identifier, string $password, bool $remember = false, string $ip = '0.0.0.0'): array
    {
        // Rate limit
        if (self::isRateLimited($ip)) {
            return ['success' => false, 'message' => 'Too many login attempts. Try again in ' . self::LOCKOUT_MINS . ' minutes.'];
        }

        $db  = Database::getInstance();
        $tbl = Database::table('users');

        // Find by email or username
        $user = $db->get_row(
            "SELECT * FROM `{$tbl}` WHERE email = :id OR username = :id2 LIMIT 1",
            ['id' => $identifier, 'id2' => $identifier]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            self::recordAttempt($ip);
            return ['success' => false, 'message' => 'Invalid credentials.'];
        }

        if ($user['status'] === 'banned') {
            return ['success' => false, 'message' => 'Your account has been suspended.'];
        }

        if ($user['status'] === 'pending') {
            return ['success' => false, 'message' => 'Please verify your email first.'];
        }

        self::clearAttempts($ip);
        self::loginUser($user, $remember);

        return ['success' => true, 'message' => 'Logged in.', 'user' => $user];
    }

    public static function loginUser(array $user, bool $remember = false): void
    {
        $safe = self::safeUser($user);
        Session::regenerate();
        Session::set(self::SESSION_KEY, $safe);

        // Update last login
        $db  = Database::getInstance();
        $tbl = Database::table('users');
        $db->update($tbl, ['last_login' => date('Y-m-d H:i:s')], ['id' => $user['id']]);

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $expires = time() + (self::REMEMBER_DAYS * 86400);

            $db->update($tbl, ['remember_token' => hash('sha256', $token), 'remember_expires' => date('Y-m-d H:i:s', $expires)], ['id' => $user['id']]);
            setcookie('alpha_remember', $token, [
                'expires'  => $expires,
                'path'     => '/',
                'httponly' => true,
                'secure'   => isset($_SERVER['HTTPS']),
                'samesite' => 'Lax',
            ]);
        }
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user) {
            $db  = Database::getInstance();
            $tbl = Database::table('users');
            $db->update($tbl, ['remember_token' => null, 'remember_expires' => null], ['id' => $user['id']]);
        }

        setcookie('alpha_remember', '', time() - 3600, '/');
        Session::remove(self::SESSION_KEY);
        Session::regenerate();
    }

    // ── Registration ──────────────────────────────────────────────────────────

    public static function register(array $data): array
    {
        $db  = Database::getInstance();
        $tbl = Database::table('users');

        // Validate
        $errors = [];
        if (empty($data['username'])) {
            $errors[] = 'Username is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $data['username'])) {
            $errors[] = 'Username must be 3–30 chars (letters, numbers, underscore).';
        }

        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required.';
        }

        if (empty($data['password']) || strlen($data['password']) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Uniqueness check
        $existing = $db->get_var("SELECT id FROM `{$tbl}` WHERE email = :e OR username = :u LIMIT 1",
            ['e' => $data['email'], 'u' => $data['username']]);
        if ($existing) {
            return ['success' => false, 'errors' => ['Username or email already taken.']];
        }

        $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $emailToken = bin2hex(random_bytes(32));

        $id = $db->insert($tbl, [
            'username'       => $data['username'],
            'email'          => $data['email'],
            'password'       => $hash,
            'role'           => 'starter_member',
            'status'         => Config::bool('FEATURE_EMAIL_VERIFY') ? 'pending' : 'active',
            'email_token'    => $emailToken,
            'display_name'   => $data['display_name'] ?? $data['username'],
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'id' => $id, 'email_token' => $emailToken];
    }

    // ── Password reset ────────────────────────────────────────────────────────

    public static function generateResetToken(string $email): ?string
    {
        $db  = Database::getInstance();
        $tbl = Database::table('users');
        $user = $db->get_row("SELECT id FROM `{$tbl}` WHERE email = :e LIMIT 1", ['e' => $email]);
        if (!$user) {
            return null;
        }

        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        $db->update($tbl, ['reset_token' => hash('sha256', $token), 'reset_expires' => $expires], ['id' => $user['id']]);
        return $token;
    }

    public static function resetPassword(string $token, string $newPassword): bool
    {
        $db    = Database::getInstance();
        $tbl   = Database::table('users');
        $hashed = hash('sha256', $token);

        $user = $db->get_row(
            "SELECT id FROM `{$tbl}` WHERE reset_token = :t AND reset_expires > NOW() LIMIT 1",
            ['t' => $hashed]
        );
        if (!$user) {
            return false;
        }

        $db->update($tbl, [
            'password'      => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
            'reset_token'   => null,
            'reset_expires' => null,
        ], ['id' => $user['id']]);

        return true;
    }

    // ── Rate limiting (DB transient) ──────────────────────────────────────────

    private static function attemptKey(string $ip): string
    {
        return 'login_attempts_' . md5($ip);
    }

    private static function isRateLimited(string $ip): bool
    {
        $data = self::getTransient(self::attemptKey($ip));
        if (!$data) {
            return false;
        }
        return $data['count'] >= self::MAX_ATTEMPTS && $data['until'] > time();
    }

    private static function recordAttempt(string $ip): void
    {
        $data = self::getTransient(self::attemptKey($ip)) ?: ['count' => 0, 'until' => 0];
        $data['count']++;
        if ($data['count'] >= self::MAX_ATTEMPTS) {
            $data['until'] = time() + (self::LOCKOUT_MINS * 60);
        }
        self::setTransient(self::attemptKey($ip), $data, self::LOCKOUT_MINS * 60);
    }

    private static function clearAttempts(string $ip): void
    {
        self::deleteTransient(self::attemptKey($ip));
    }

    // ── Simple DB transients ──────────────────────────────────────────────────

    private static function getTransient(string $key): mixed
    {
        $db  = Database::getInstance();
        $tbl = Database::table('transients');
        $row = $db->get_row("SELECT `value` FROM `{$tbl}` WHERE `key` = :k AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1", ['k' => $key]);
        return $row ? json_decode($row['value'], true) : null;
    }

    private static function setTransient(string $key, mixed $value, int $ttl = 3600): void
    {
        $db      = Database::getInstance();
        $tbl     = Database::table('transients');
        $expires = date('Y-m-d H:i:s', time() + $ttl);
        $json    = json_encode($value);

        $existing = $db->get_var("SELECT `key` FROM `{$tbl}` WHERE `key` = :k LIMIT 1", ['k' => $key]);
        if ($existing) {
            $db->update($tbl, ['value' => $json, 'expires_at' => $expires], ['key' => $key]);
        } else {
            $db->insert($tbl, ['key' => $key, 'value' => $json, 'expires_at' => $expires]);
        }
    }

    private static function deleteTransient(string $key): void
    {
        $db  = Database::getInstance();
        $tbl = Database::table('transients');
        $db->delete($tbl, ['key' => $key]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function safeUser(array $user): array
    {
        return [
            'id'           => (int)$user['id'],
            'username'     => $user['username'],
            'email'        => $user['email'],
            'display_name' => $user['display_name'],
            'role'         => $user['role'],
            'avatar'       => $user['avatar'] ?? null,
            'coin_balance' => (int)($user['coin_balance'] ?? 0),
        ];
    }

    private static function userByRememberToken(string $token): ?array
    {
        $db    = Database::getInstance();
        $tbl   = Database::table('users');
        $hashed = hash('sha256', $token);

        $user = $db->get_row(
            "SELECT * FROM `{$tbl}` WHERE remember_token = :t AND remember_expires > NOW() LIMIT 1",
            ['t' => $hashed]
        );
        return $user ? self::safeUser($user) : null;
    }

    /** Caps each role has. * = all caps. */
    private static function roleCaps(string $role): array
    {
        static $map = [
            'admin'          => ['*'],
            'administrator'  => ['*'],
            'starter_editor' => ['read', 'edit_manga', 'delete_manga', 'moderate_comments', 'manage_chapters', 'access_premium'],
            'starter_author' => ['read', 'upload_manga', 'upload_chapter', 'manage_own_chapters', 'edit_own_manga', 'access_premium'],
            'starter_vip'    => ['read', 'access_premium', 'access_coin_chapters'],
            'starter_member' => ['read', 'comment', 'rate', 'bookmark'],
            'guest'          => ['read'],
        ];
        return $map[$role] ?? $map['guest'];
    }

    /** Refresh the session user data from DB. */
    public static function refresh(): void
    {
        $id = self::id();
        if (!$id) {
            return;
        }
        $db   = Database::getInstance();
        $tbl  = Database::table('users');
        $user = $db->get_row("SELECT * FROM `{$tbl}` WHERE id = :id LIMIT 1", ['id' => $id]);
        if ($user) {
            Session::set(self::SESSION_KEY, self::safeUser($user));
        }
    }
}
