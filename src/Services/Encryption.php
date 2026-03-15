<?php

declare(strict_types=1);

namespace Alpha\Services;

use Alpha\Core\Config;

/**
 * AES-256-GCM encryption for image URLs and tokens.
 * Ported from class-encryption.php — no WP dependencies.
 */
class Encryption
{
    private string $key;

    public function __construct()
    {
        $keyHex   = Config::get('STARTER_ENCRYPTION_KEY') ?: Config::get('APP_SECRET', '');
        $this->key = strlen($keyHex) === 64
            ? hex2bin($keyHex)
            : hash('sha256', $keyHex, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv         = random_bytes(12); // 96-bit IV for GCM
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        return $this->base64urlEncode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): ?string
    {
        $raw = $this->base64urlDecode($payload);
        if (strlen($raw) < 28) {
            return null;
        }
        $iv         = substr($raw, 0, 12);
        $tag        = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $result     = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        return $result !== false ? $result : null;
    }

    public function encryptUrl(string $url): string
    {
        return $this->encrypt($url);
    }

    public function decryptUrl(string $payload): ?string
    {
        return $this->decrypt($payload);
    }

    /**
     * Generate a short-lived signed token.
     * @param int $ttl seconds (default 5 minutes)
     */
    public function generateToken(string $data, int $ttl = 300): string
    {
        $expires = time() + $ttl;
        return $this->encrypt("{$expires}|{$data}");
    }

    /**
     * Validate token and return the embedded data, or null if expired/invalid.
     */
    public function validateToken(string $token): ?string
    {
        $raw = $this->decrypt($token);
        if (!$raw) {
            return null;
        }
        [$expires, $data] = explode('|', $raw, 2);
        if (time() > (int)$expires) {
            return null;
        }
        return $data;
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
