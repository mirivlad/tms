<?php

declare(strict_types=1);

namespace Tms\Tests\Security;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\User\UserRepository;
use Tms\Security\PasswordAuthenticator;

final class PasswordAuthenticatorTest extends TestCase
{
    private PDO $db;
    private PasswordAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL UNIQUE,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL,
                is_active INTEGER NOT NULL,
                approved_at TEXT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->insertUser(1, 'active', true, true);
        $this->insertUser(2, 'inactive', false, true);
        $this->insertUser(3, 'pending', true, false);

        $this->authenticator = new PasswordAuthenticator(new UserRepository($this->db));
    }

    public function testValidApprovedActiveUserAuthenticates(): void
    {
        $user = $this->authenticator->authenticate('active', 'correct horse battery staple');

        self::assertNotNull($user);
        self::assertSame(1, $user->id);
    }

    public function testWrongPasswordIsRejected(): void
    {
        self::assertNull($this->authenticator->authenticate('active', 'wrong password'));
    }

    public function testUnknownUserIsRejected(): void
    {
        self::assertNull($this->authenticator->authenticate('missing', 'anything'));
    }

    public function testInactiveUserIsRejectedEvenWithCorrectPassword(): void
    {
        self::assertNull($this->authenticator->authenticate('inactive', 'correct horse battery staple'));
    }

    public function testUnapprovedUserIsRejectedEvenWithCorrectPassword(): void
    {
        self::assertNull($this->authenticator->authenticate('pending', 'correct horse battery staple'));
    }

    private function insertUser(int $id, string $username, bool $active, bool $approved): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (id, username, email, password_hash, role, is_active, approved_at)
             VALUES (:id, :username, :email, :password_hash, :role, :is_active, :approved_at)'
        );
        $stmt->execute([
            'id' => $id,
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            'role' => 'user',
            'is_active' => $active ? 1 : 0,
            'approved_at' => $approved ? '2026-01-01 00:00:00' : null,
        ]);
    }
}
