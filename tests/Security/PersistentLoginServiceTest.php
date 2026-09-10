<?php

declare(strict_types=1);

namespace Tms\Tests\Security;

use DateInterval;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Security\PersistentLoginService;
use Tms\Security\RememberTokenRepository;

final class PersistentLoginServiceTest extends TestCase
{
    private PDO $db;
    private PersistentLoginService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE remember_tokens (
                selector TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                verifier_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );

        $this->service = new PersistentLoginService(
            new RememberTokenRepository($this->db),
            new DateInterval('P30D'),
        );
    }

    public function testIssuedTokenStoresOnlyVerifierHash(): void
    {
        $token = $this->service->issue(7, new DateTimeImmutable('2026-01-01 00:00:00'));
        $row = $this->db->query('SELECT selector, verifier_hash FROM remember_tokens')->fetch();

        self::assertIsArray($row);
        self::assertSame($token->selector, $row['selector']);
        self::assertTrue($token->matchesHash((string) $row['verifier_hash']));
        self::assertStringNotContainsString((string) $row['verifier_hash'], $token->cookieValue());
    }

    public function testSuccessfulConsumeRotatesToken(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $original = $this->service->issue(7, $now);

        $result = $this->service->consume($original->cookieValue(), $now->modify('+1 day'));

        self::assertNotNull($result);
        self::assertSame(7, $result->userId);
        self::assertNotSame($original->selector, $result->rotatedToken->selector);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM remember_tokens')->fetchColumn());
        self::assertSame(0, $this->countSelector($original->selector));
        self::assertSame(1, $this->countSelector($result->rotatedToken->selector));
    }

    public function testReplayingConsumedTokenFails(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $original = $this->service->issue(7, $now);
        self::assertNotNull($this->service->consume($original->cookieValue(), $now->modify('+1 day')));

        self::assertNull($this->service->consume($original->cookieValue(), $now->modify('+2 days')));
    }

    public function testWrongVerifierRevokesSelector(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $token = $this->service->issue(7, $now);
        $forged = $token->selector . '.' . str_repeat('0', 64);

        self::assertNull($this->service->consume($forged, $now->modify('+1 day')));
        self::assertSame(0, $this->countSelector($token->selector));
    }

    public function testExpiredTokenIsRejectedAndDeleted(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $token = $this->service->issue(7, $now);

        self::assertNull($this->service->consume($token->cookieValue(), $now->modify('+31 days')));
        self::assertSame(0, $this->countSelector($token->selector));
    }

    private function countSelector(string $selector): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM remember_tokens WHERE selector = :selector');
        $stmt->execute(['selector' => $selector]);

        return (int) $stmt->fetchColumn();
    }
}
