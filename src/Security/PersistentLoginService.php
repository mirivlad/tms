<?php

declare(strict_types=1);

namespace Tms\Security;

use DateInterval;
use DateTimeImmutable;

final class PersistentLoginService
{
    public function __construct(
        private readonly RememberTokenRepository $tokens,
        private readonly DateInterval $lifetime,
    ) {
    }

    public function issue(int $userId, DateTimeImmutable $now): RememberToken
    {
        $token = RememberToken::issue();
        $this->tokens->store($userId, $token, $now->add($this->lifetime));

        return $token;
    }

    public function consume(string $cookieValue, DateTimeImmutable $now): ?PersistentLoginResult
    {
        $token = RememberToken::fromCookie($cookieValue);
        if ($token === null) {
            return null;
        }

        $stored = $this->tokens->findBySelector($token->selector);
        if ($stored === null) {
            return null;
        }

        if ($stored->expiresAt <= $now || !$token->matchesHash($stored->verifierHash)) {
            $this->tokens->deleteBySelector($stored->selector);
            return null;
        }

        $this->tokens->deleteBySelector($stored->selector);
        $rotated = $this->issue($stored->userId, $now);

        return new PersistentLoginResult($stored->userId, $rotated);
    }

    public function revoke(string $cookieValue): void
    {
        $token = RememberToken::fromCookie($cookieValue);
        if ($token !== null) {
            $this->tokens->deleteBySelector($token->selector);
        }
    }

    public function revokeAllForUser(int $userId): void
    {
        $this->tokens->deleteAllForUser($userId);
    }
}
