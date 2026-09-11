<?php

declare(strict_types=1);

namespace Tms\Domain\Attachment;

use DomainException;

final class AttachmentPolicy
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/x-zip-compressed',
        ],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip-compressed',
        ],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/vnd.rar', 'application/x-rar', 'application/x-rar-compressed'],
    ];

    public function __construct(private readonly int $maxBytes = 10_485_760)
    {
        if ($maxBytes < 1) {
            throw new DomainException('Attachment size limit must be positive.');
        }
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function normalizeOriginalName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment';
        }

        if (mb_strlen($name) > 255) {
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $suffix = $extension === '' ? '' : '.' . $extension;
            $baseLength = max(1, 255 - mb_strlen($suffix));
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, $baseLength) . $suffix;
        }

        return $name;
    }

    public function assertSize(int $size): void
    {
        if ($size < 1) {
            throw new DomainException('Attachment is empty.');
        }
        if ($size > $this->maxBytes) {
            throw new DomainException('Attachment exceeds the configured size limit.');
        }
    }

    public function assertAllowed(string $originalName, int $size, string $detectedMime): void
    {
        $this->assertSize($size);

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedMimeTypes = self::ALLOWED[$extension] ?? null;
        if ($allowedMimeTypes === null) {
            throw new DomainException('Attachment file extension is not allowed.');
        }
        if (!in_array($detectedMime, $allowedMimeTypes, true)) {
            throw new DomainException('Attachment content type does not match an allowed file type.');
        }
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        return array_keys(self::ALLOWED);
    }
}
