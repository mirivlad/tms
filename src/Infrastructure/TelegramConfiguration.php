<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

final readonly class TelegramConfiguration
{
    public function __construct(
        public string $botName,
        public string $botToken,
        public string $webhookSecret,
        public bool $proxyEnabled,
        public string $proxyUrl,
    ) {
    }
}
