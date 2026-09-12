<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

interface TelegramSender
{
    public function send(string $chatId, string $text): bool;
}
