<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

final readonly class StoredAttachment
{
    public function __construct(
        public string $storageName,
        public int $fileSize,
        public string $mimeType,
        public string $sha256,
    ) {
    }
}
