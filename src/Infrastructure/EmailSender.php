<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

interface EmailSender
{
    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool;
}
