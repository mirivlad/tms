<?php

declare(strict_types=1);

namespace Tms\Domain\Attachment;

final readonly class AttachmentRecord
{
    public function __construct(
        public int $id,
        public int $taskId,
        public int $userId,
        public string $storageName,
        public string $originalName,
        public string $mimeType,
        public int $fileSize,
        public string $sha256,
        public string $createdAt,
    ) {
    }
}
