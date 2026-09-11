<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

use finfo;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

final class AttachmentStorage
{
    private bool $initialized = false;

    public function __construct(
        private readonly string $root,
        private readonly string $publicRoot,
    ) {
        if (trim($root) === '') {
            throw new RuntimeException('Attachment storage path cannot be empty.');
        }
    }

    public function store(UploadedFileInterface $file): StoredAttachment
    {
        $this->initialize();

        $storageName = bin2hex(random_bytes(32));
        $path = $this->pathFor($storageName);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create attachment storage directory.');
        }

        try {
            $file->moveTo($path);
            @chmod($path, 0600);

            $size = filesize($path);
            if ($size === false) {
                throw new RuntimeException('Unable to determine stored attachment size.');
            }

            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!is_string($mimeType) || $mimeType === '') {
                throw new RuntimeException('Unable to determine stored attachment MIME type.');
            }

            $sha256 = hash_file('sha256', $path);
            if ($sha256 === false) {
                throw new RuntimeException('Unable to hash stored attachment.');
            }

            return new StoredAttachment(
                storageName: $storageName,
                fileSize: $size,
                mimeType: $mimeType,
                sha256: $sha256,
            );
        } catch (\Throwable $error) {
            if (is_file($path)) {
                @unlink($path);
            }
            throw $error;
        }
    }

    public function pathFor(string $storageName): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $storageName) !== 1) {
            throw new RuntimeException('Invalid attachment storage name.');
        }

        return rtrim($this->root, '/\\')
            . DIRECTORY_SEPARATOR . substr($storageName, 0, 2)
            . DIRECTORY_SEPARATOR . $storageName;
    }

    public function exists(string $storageName): bool
    {
        $this->initialize();
        return is_file($this->pathFor($storageName));
    }

    public function delete(string $storageName): bool
    {
        $this->initialize();
        $path = $this->pathFor($storageName);
        if (!is_file($path)) {
            return true;
        }

        if (!unlink($path)) {
            return false;
        }

        $directory = dirname($path);
        $items = @scandir($directory);
        if (is_array($items) && count($items) === 2) {
            @rmdir($directory);
        }
        return true;
    }

    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create attachment storage root.');
        }

        $storageRoot = realpath($this->root);
        $publicRoot = realpath($this->publicRoot);
        if ($storageRoot === false || $publicRoot === false) {
            throw new RuntimeException('Unable to resolve attachment/public storage paths.');
        }

        $publicPrefix = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($storageRoot === $publicRoot || str_starts_with($storageRoot . DIRECTORY_SEPARATOR, $publicPrefix)) {
            throw new RuntimeException('Attachment storage must be outside the public web root.');
        }

        if (!is_writable($storageRoot)) {
            throw new RuntimeException('Attachment storage root is not writable.');
        }

        $this->initialized = true;
    }
}
