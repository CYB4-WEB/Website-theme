<?php

declare(strict_types=1);

namespace Alpha\Services\Storage;

interface StorageInterface
{
    /**
     * Upload a file to storage.
     * @param  string $localPath Temporary/local file path
     * @param  string $remotePath Desired destination path (relative)
     * @return string Public URL of the uploaded file
     */
    public function upload(string $localPath, string $remotePath): string;

    /** Return the public URL for a stored file. */
    public function getUrl(string $remotePath): string;

    /** Delete a stored file. */
    public function delete(string $remotePath): bool;

    /** Check whether a stored file exists. */
    public function exists(string $remotePath): bool;
}
