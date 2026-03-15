<?php

declare(strict_types=1);

namespace Alpha\Services\Storage;

use Alpha\Core\Config;

/**
 * AWS S3 / S3-compatible storage.
 * Uses the AWS SDK v3 (optional; falls back to local if not installed).
 * Install with: composer require aws/aws-sdk-php
 */
class S3Storage implements StorageInterface
{
    private ?object $s3 = null;
    private string  $bucket;
    private string  $region;
    private ?string $endpoint;
    private string  $baseUrl;

    public function __construct()
    {
        $this->bucket   = Config::get('STARTER_S3_BUCKET', '');
        $this->region   = Config::get('STARTER_S3_REGION', 'us-east-1');
        $this->endpoint = Config::get('STARTER_S3_ENDPOINT') ?: null;
        $this->baseUrl  = Config::get('STORAGE_CDN_URL', "https://{$this->bucket}.s3.{$this->region}.amazonaws.com");

        if (!class_exists('\Aws\S3\S3Client')) {
            throw new \RuntimeException('AWS SDK not installed. Run: composer require aws/aws-sdk-php');
        }

        $args = [
            'version'     => 'latest',
            'region'      => $this->region,
            'credentials' => [
                'key'    => Config::get('STARTER_S3_KEY', ''),
                'secret' => Config::get('STARTER_S3_SECRET', ''),
            ],
        ];
        if ($this->endpoint) {
            $args['endpoint']                = $this->endpoint;
            $args['use_path_style_endpoint'] = true;
        }

        $this->s3 = new \Aws\S3\S3Client($args);
    }

    public function upload(string $localPath, string $remotePath): string
    {
        $this->s3->putObject([
            'Bucket'     => $this->bucket,
            'Key'        => ltrim($remotePath, '/'),
            'SourceFile' => $localPath,
            'ACL'        => 'public-read',
        ]);
        return $this->getUrl($remotePath);
    }

    public function getUrl(string $remotePath): string
    {
        return $this->baseUrl . '/' . ltrim($remotePath, '/');
    }

    public function delete(string $remotePath): bool
    {
        $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => ltrim($remotePath, '/')]);
        return true;
    }

    public function exists(string $remotePath): bool
    {
        return $this->s3->doesObjectExist($this->bucket, ltrim($remotePath, '/'));
    }
}
