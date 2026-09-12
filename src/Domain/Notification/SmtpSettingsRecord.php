<?php

declare(strict_types=1);

namespace Tms\Domain\Notification;

final readonly class SmtpSettingsRecord
{
    public function __construct(
        public bool $enabled,
        public string $host,
        public int $port,
        public string $username,
        public ?string $passwordCiphertext,
        public string $encryption,
        public string $fromEmail,
        public string $fromName,
    ) {
    }
}
