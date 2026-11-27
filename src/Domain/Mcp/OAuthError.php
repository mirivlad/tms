<?php

declare(strict_types=1);

namespace Tms\Domain\Mcp;

final class OAuthError extends \RuntimeException
{
    public function __construct(public readonly string $error, string $description, public readonly int $status = 400)
    {
        parent::__construct($description);
    }
}
