<?php

declare(strict_types=1);

namespace Tms\Domain\Project;

final readonly class ProjectAttachmentRecord
{
    public function __construct(
        public int $id,
        public int $projectId,
        public ?int $uploadedBy,
        public string $storageName,
        public string $originalName,
        public string $mimeType,
        public int $fileSize,
        public string $sha256,
        public string $createdAt,
    ) {
    }
}
