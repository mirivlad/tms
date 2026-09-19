<?php

declare(strict_types=1);

namespace Tms\Domain\Status;

final readonly class StatusRecord
{
    public function __construct(
        public int $id,
        public ?int $userId,
        public ?int $projectId,
        public ?int $sourceStatusId,
        public string $name,
        public string $description,
        public string $color,
        public int $sortOrder,
        public bool $isDefault,
        public bool $isCompletion,
        public bool $showOnBoard,
    ) {
    }

    public function isPersonal(): bool
    {
        return $this->userId !== null && $this->projectId === null;
    }

    public function isProjectScoped(): bool
    {
        return $this->userId === null && $this->projectId !== null;
    }
}
