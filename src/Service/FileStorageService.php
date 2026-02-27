<?php

namespace App\Service;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class FileStorageService
{
    private ?S3Client $s3Client = null;
    private string $bucket;
    private array $allowedExtensions = [
        'pdf', 'docx', 'xlsx', 'csv', 'txt', 'json',
        'jpg', 'jpeg', 'png', 'gif', 'bmp'
    ];
    private int $maxFileSize = 104857600; // 100MB
    private bool $bucketChecked = false;

    public function __construct(
        private ParameterBagInterface $params,
        private LoggerInterface $logger
    ) {
        $this->bucket = $params->get('minio.bucket');
    }

    private function getS3Client(): S3Client
    {
        if ($this->s3Client === null) {
            $this->s3Client = new S3Client([
                'version' => 'latest',
                'region' => $this->params->get('minio.region'),
                'endpoint' => $this->params->get('minio.endpoint'),
                'use_path_style_endpoint' => $this->params->get('minio.use_path_style'),
                'credentials' => [
                    'key' => $this->params->get('minio.root_user'),
                    'secret' => $this->params->get('minio.root_password'),
                ],
            ]);
        }
        return $this->s3Client;
    }

    private function ensureBucketExists(): void
    {
        if ($this->bucketChecked) {
            return;
        }

        try {
            $s3Client = $this->getS3Client();
            if (!$s3Client->doesBucketExist($this->bucket)) {
                $s3Client->createBucket([
                    'Bucket' => $this->bucket,
                ]);
            }
            $this->bucketChecked = true;
        } catch (S3Exception $e) {
            $this->logger->error('Failed to create bucket', [
                'bucket' => $this->bucket,
                'error' => $e->getMessage()
            ]);
            $this->bucketChecked = true; // Mark as checked even on failure to avoid repeated attempts
        }
    }

    public function upload(UploadedFile $file, string $orgUuid, int $userId): array
    {
        $this->ensureBucketExists();

        // Validate file
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $this->allowedExtensions)) {
            throw new \InvalidArgumentException('File type not allowed');
        }

        if ($file->getSize() > $this->maxFileSize) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size');
        }

        $filename = sprintf(
            '%s/%d/%s_%s',
            $orgUuid,
            $userId,
            uniqid(),
            $file->getClientOriginalName()
        );

        try {
            $result = $this->getS3Client()->putObject([
                'Bucket' => $this->bucket,
                'Key' => $filename,
                'Body' => fopen($file->getPathname(), 'r'),
                'ContentType' => $file->getMimeType(),
                'Metadata' => [
                    'original_name' => $file->getClientOriginalName(),
                    'uploaded_by' => (string) $userId,
                    'uploaded_at' => date('Y-m-d H:i:s'),
                ]
            ]);

            return [
                'key' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'url' => $result['ObjectURL'] ?? null,
            ];
        } catch (S3Exception $e) {
            $this->logger->error('File upload failed', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName()
            ]);
            throw new \RuntimeException('Failed to upload file');
        }
    }

    public function uploadContent(string $content, string $filename, string $orgUuid, int $userId): array
    {
        $this->ensureBucketExists();

        // Sanitize filename: strip unsafe chars, keep alphanumeric, dashes, underscores, dots
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        // Fallback if filename is empty after sanitization
        if ($filename === '' || $filename === null) {
            $filename = 'manual_' . date('Y-m-d_His');
        }

        // Auto-append .txt if missing
        if (!str_ends_with(strtolower($filename), '.txt')) {
            $filename .= '.txt';
        }

        // Validate content size
        $contentSize = strlen($content);
        if ($contentSize > $this->maxFileSize) {
            throw new \InvalidArgumentException('Content size exceeds maximum allowed size');
        }

        $key = sprintf(
            '%s/%d/%s_%s',
            $orgUuid,
            $userId,
            uniqid(),
            $filename
        );

        try {
            $result = $this->getS3Client()->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $content,
                'ContentType' => 'text/plain; charset=utf-8',
                'Metadata' => [
                    'original_name' => $filename,
                    'uploaded_by' => (string) $userId,
                    'uploaded_at' => date('Y-m-d H:i:s'),
                ]
            ]);

            return [
                'key' => $key,
                'original_name' => $filename,
                'size' => $contentSize,
                'mime_type' => 'text/plain',
                'url' => $result['ObjectURL'] ?? null,
            ];
        } catch (S3Exception $e) {
            $this->logger->error('Text file creation failed', [
                'error' => $e->getMessage(),
                'filename' => $filename
            ]);
            throw new \RuntimeException('Failed to create text file');
        }
    }

    public function listFiles(string $orgUuid): array
    {
        $this->ensureBucketExists();

        try {
            $s3Client = $this->getS3Client();
            $objects = $s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $orgUuid . '/',
            ]);

            $files = [];
            foreach ($objects['Contents'] ?? [] as $object) {
                $metadata = $s3Client->headObject([
                    'Bucket' => $this->bucket,
                    'Key' => $object['Key'],
                ]);

                $files[] = [
                    'key' => $object['Key'],
                    'original_name' => $metadata['Metadata']['original_name'] ?? basename($object['Key']),
                    'size' => $object['Size'],
                    'last_modified' => $object['LastModified'],
                    'uploaded_by' => $metadata['Metadata']['uploaded_by'] ?? null,
                ];
            }

            return $files;
        } catch (S3Exception $e) {
            $this->logger->error('Failed to list files', [
                'error' => $e->getMessage(),
                'org_uuid' => $orgUuid
            ]);

            // Re-throw the exception to let the controller handle it
            throw new \RuntimeException('Unable to connect to file storage service: ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $key): bool
    {
        $this->ensureBucketExists();

        try {
            $this->getS3Client()->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            return true;
        } catch (S3Exception $e) {
            $this->logger->error('Failed to delete file', [
                'error' => $e->getMessage(),
                'key' => $key
            ]);
            return false;
        }
    }

    public function getDownloadUrl(string $key, int $expiration = 3600): string
    {
        $this->ensureBucketExists();

        try {
            $s3Client = $this->getS3Client();
            $cmd = $s3Client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            $request = $s3Client->createPresignedRequest($cmd, '+' . $expiration . ' seconds');
            return (string) $request->getUri();
        } catch (S3Exception $e) {
            $this->logger->error('Failed to generate download URL', [
                'error' => $e->getMessage(),
                'key' => $key
            ]);
            throw new \RuntimeException('Failed to generate download URL');
        }
    }

    public function isConnected(): bool
    {
        try {
            // Try to check if the bucket exists as a connectivity test
            $this->getS3Client()->headBucket([
                'Bucket' => $this->bucket,
            ]);
            return true;
        } catch (S3Exception $e) {
            $this->logger->error('MinIO connection check failed', [
                'error' => $e->getMessage(),
                'bucket' => $this->bucket
            ]);
            return false;
        }
    }
}
