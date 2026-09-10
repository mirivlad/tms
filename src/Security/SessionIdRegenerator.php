<?php

declare(strict_types=1);

namespace Tms\Security;

interface SessionIdRegenerator
{
    public function regenerate(): void;
}
