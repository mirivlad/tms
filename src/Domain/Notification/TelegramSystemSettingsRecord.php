<?php

declare(strict_types=1);

namespace Tms\Domain\Notification;

final readonly class TelegramSystemSettingsRecord
{
    public function __construct(
        public string $botName,
        public ?string $botTokenCiphertext,
        public ?string $webhookSecretCiphertext,
        public bool $proxyEnabled,
        public ?string $proxyUrlCiphertext,
    ) {
    }
}
