<?php

declare(strict_types=1);

namespace Alpha\Services\Storage;

use Alpha\Core\Config;

class LocalStorage implements StorageInterface
{
    private string $basePath;
    private string $baseUrl;

    public function __construct()
    {
        $this->basePath = rtrim(Config::get('STORAGE_LOCAL_PATH', ALPHA_ROOT . '/uploads'), '/');
        $this->baseUrl  = rtrim(Config::get('APP_URL', ''), '/') . '/uploads';
    }

    public function upload(string $localPath, string $remotePath): string
    {
        $dest = $this->basePath . '/' . ltrim($remotePath, '/');
        $dir  = dirname($dest);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!copy($localPath, $dest)) {
            throw new \RuntimeException("Failed to copy {$localPath} to {$dest}");
        }

        return $this->getUrl($remotePath);
    }

    public function getUrl(string $remotePath): string
    {
        return $this->baseUrl . '/' . ltrim($remotePath, '/');
    }

    public function delete(string $remotePath): bool
    {
        $path = $this->basePath . '/' . ltrim($remotePath, '/');
        if (file_exists($path)) {
            return unlink($path);
        }
        return true;
    }

    public function exists(string $remotePath): bool
    {
        return file_exists($this->basePath . '/' . ltrim($remotePath, '/'));
    }
}
