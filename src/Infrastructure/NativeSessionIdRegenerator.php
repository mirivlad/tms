<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

use RuntimeException;
use Tms\Security\SessionIdRegenerator;

final class NativeSessionIdRegenerator implements SessionIdRegenerator
{
    public function regenerate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Cannot rotate an inactive PHP session.');
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Failed to rotate PHP session ID.');
        }
    }
}
