<?php

declare(strict_types=1);

namespace Tms\Security;

use Tms\Domain\User\UserRecord;
use Tms\Domain\User\UserRepository;

final class PasswordAuthenticator
{
    private const DUMMY_HASH = '$2y$12$HeXsDSrVUBU27ELJ6gHwFecIX3j2cREmcB6YpgmCluOzs4pOBpolG';

    public function __construct(private readonly UserRepository $users)
    {
    }

    public function authenticate(string $username, string $password): ?UserRecord
    {
        $user = $this->users->findByUsername($username);

        if ($user === null) {
            password_verify($password, self::DUMMY_HASH);
            return null;
        }

        if (!password_verify($password, $user->passwordHash)) {
            return null;
        }

        if (!$user->isActive || !$user->isApproved) {
            return null;
        }

        if (password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
            $this->users->replacePasswordHash($user->id, password_hash($password, PASSWORD_DEFAULT));
        }

        return $user;
    }
}
