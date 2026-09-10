<?php

declare(strict_types=1);

namespace Tms\Http;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CookiePolicy
{
    public function __construct(
        private bool $secure,
        private string $sameSite = 'Lax',
    ) {
        if (!in_array($this->sameSite, ['Lax', 'Strict'], true)) {
            throw new InvalidArgumentException('SameSite must be Lax or Strict.');
        }
    }

    /**
     * @return array{expires:int, path:string, secure:bool, httponly:bool, samesite:string}
     */
    public function persistent(DateTimeImmutable $expiresAt): array
    {
        return [
            'expires' => $expiresAt->getTimestamp(),
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => $this->sameSite,
        ];
    }

    /**
     * @return array{expires:int, path:string, secure:bool, httponly:bool, samesite:string}
     */
    public function expired(): array
    {
        return [
            'expires' => 1,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => $this->sameSite,
        ];
    }
}
