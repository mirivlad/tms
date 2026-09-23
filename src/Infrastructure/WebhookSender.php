<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

interface WebhookSender
{
    /** @param array<string, string> $headers */
    public function send(string $url, array $headers, string $body): WebhookDeliveryResult;
}
