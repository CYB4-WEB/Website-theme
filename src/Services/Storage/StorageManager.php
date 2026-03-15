<?php

declare(strict_types=1);

namespace Alpha\Services\Storage;

use Alpha\Core\Config;

/**
 * Factory / façade for storage backends.
 * Reads STORAGE_DRIVER env var (local|s3|ftp|external).
 * Falls back to local on error.
 */
class StorageManager
{
    private static ?StorageInterface $instance = null;

    public static function driver(): StorageInterface
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $driver = Config::get('STORAGE_DRIVER', 'local');

        try {
            self::$instance = match ($driver) {
                's3'       => new S3Storage(),
                'ftp'      => new FtpStorage(),
                'external' => new ExternalStorage(),
                default    => new LocalStorage(),
            };
        } catch (\Throwable) {
            // Graceful degradation to local
            self::$instance = new LocalStorage();
        }

        return self::$instance;
    }

    /**
     * Upload a chapter image with automatic path organisation.
     * Returns public URL.
     */
    public static function uploadChapterImage(string $localPath, int $mangaId, int $chapterId, string $filename): string
    {
        $ext      = pathinfo($filename, PATHINFO_EXTENSION);
        $safe     = preg_replace('/[^a-z0-9_-]/i', '_', pathinfo($filename, PATHINFO_FILENAME));
        $remote   = "manga/{$mangaId}/chapter-{$chapterId}/{$safe}.{$ext}";
        return self::driver()->upload($localPath, $remote);
    }

    /**
     * Upload a manga cover image. Returns public URL.
     */
    public static function uploadCover(string $localPath, int $mangaId, string $filename): string
    {
        $ext    = pathinfo($filename, PATHINFO_EXTENSION);
        $remote = "manga/{$mangaId}/cover.{$ext}";
        return self::driver()->upload($localPath, $remote);
    }
}
