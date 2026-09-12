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

    public function startImpersonation(UserRecord $target): bool
    {
        $adminId = $this->currentUserId();
        if ($adminId === null || $this->currentRole() !== 'admin' || $target->id === $adminId) {
            return false;
        }
        $_SESSION['original_admin_id'] = $adminId;
        $_SESSION['user_id'] = $target->id;
        $_SESSION['username'] = $target->username;
        $_SESSION['role'] = $target->role;
        $_SESSION['logged_in'] = true;
        $this->regenerator->regenerate();
        return true;
    }

    public function isImpersonating(): bool
    {
        return $this->isAuthenticated() && is_int($_SESSION['original_admin_id'] ?? null);
    }

    public function originalAdminId(): ?int
    {
        $id = $_SESSION['original_admin_id'] ?? null;
        return $this->isImpersonating() && is_int($id) ? $id : null;
    }

    public function stopImpersonation(UserRecord $admin): bool
    {
        $originalId = $this->originalAdminId();
        if ($originalId === null || $admin->id !== $originalId || $admin->role !== 'admin' || !$admin->isActive) {
            return false;
        }
        unset($_SESSION['original_admin_id']);
        $_SESSION['user_id'] = $admin->id;
        $_SESSION['username'] = $admin->username;
        $_SESSION['role'] = $admin->role;
        $_SESSION['logged_in'] = true;
        $this->regenerator->regenerate();
        return true;
    }

    public function refreshIdentity(UserRecord $user): void
    {
        if ($this->currentUserId() !== $user->id) {
            return;
        }
        $_SESSION['username'] = $user->username;
        $_SESSION['role'] = $user->role;
    }
}
