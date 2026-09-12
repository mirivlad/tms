<?php

declare(strict_types=1);

namespace Tms\Tests\Security;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Security\PasswordResetTokenRepository;

final class PasswordResetTokenRepositoryTest extends TestCase
{
    private PDO $db;
    private PasswordResetTokenRepository $tokens;
    private DateTimeZone $utc;

    protected function setUp(): void
    {
        $this->utc = new DateTimeZone('UTC');
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE password_reset_tokens (
                selector TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                verifier_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                consumed_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->tokens = new PasswordResetTokenRepository($this->db);
    }

    public function testIssuedTokenStoresOnlyVerifierHashAndCanBeConsumedOnce(): void
    {
        $now = new DateTimeImmutable('2026-09-12 00:00:00', $this->utc);
        $token = $this->tokens->issue(7, $now, new DateInterval('PT1H'));
        $raw = $token->value();
        [$selector, $verifier] = explode('.', $raw, 2);

        $row = $this->db->query("SELECT verifier_hash FROM password_reset_tokens WHERE selector = '$selector'")?->fetch();
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $verifier), $row['verifier_hash']);
        self::assertNotSame($verifier, $row['verifier_hash']);
        self::assertSame(7, $this->tokens->findValidUserId($raw, $now));
        self::assertSame(7, $this->tokens->consume($raw, $now));
        self::assertNull($this->tokens->consume($raw, $now));
    }

    public function testExpiredAndTamperedTokensAreRejected(): void
    {
        $now = new DateTimeImmutable('2026-09-12 00:00:00', $this->utc);
        $token = $this->tokens->issue(9, $now, new DateInterval('PT1H'));
        $raw = $token->value();
        [$selector] = explode('.', $raw, 2);

        self::assertNull($this->tokens->findValidUserId($selector . '.' . str_repeat('0', 64), $now));
        self::assertNull($this->tokens->findValidUserId($raw, $now->add(new DateInterval('PT2H'))));
    }

    public function testIssuingNewTokenRevokesPreviousTokenForUser(): void
    {
        $now = new DateTimeImmutable('2026-09-12 00:00:00', $this->utc);
        $first = $this->tokens->issue(11, $now, new DateInterval('PT1H'));
        $second = $this->tokens->issue(11, $now, new DateInterval('PT1H'));

        self::assertNull($this->tokens->findValidUserId($first->value(), $now));
        self::assertSame(11, $this->tokens->findValidUserId($second->value(), $now));
    }
}
