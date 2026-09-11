<?php

declare(strict_types=1);

namespace Tms\Security;

use Tms\Domain\User\UserRecord;

final class SessionManager
{
    public function __construct(private readonly SessionIdRegenerator $regenerator)
    {
    }

    public function establish(UserRecord $user): void
    {
        $_SESSION = [];
        $this->regenerator->regenerate();

        $_SESSION['user_id'] = $user->id;
        $_SESSION['username'] = $user->username;
        $_SESSION['role'] = $user->role;
        $_SESSION['logged_in'] = true;
    }

    public function clear(): void
    {
        $_SESSION = [];
        $this->regenerator->regenerate();
    }

    public function isAuthenticated(): bool
    {
        $loggedIn = $_SESSION['logged_in'] ?? false;
        $userId = $_SESSION['user_id'] ?? null;

        return $loggedIn === true && is_int($userId);
    }

    public function currentUserId(): ?int
    {
        $userId = $_SESSION['user_id'] ?? null;
        return $this->isAuthenticated() && is_int($userId) ? $userId : null;
    }

    public function currentUsername(): ?string
    {
        $username = $_SESSION['username'] ?? null;
        return $this->isAuthenticated() && is_string($username) ? $username : null;
    }

    public function currentRole(): ?string
    {
        $role = $_SESSION['role'] ?? null;
        return $this->isAuthenticated() && is_string($role) ? $role : null;
    }
}
