<?php

declare(strict_types=1);

namespace Tms\Tests\Domain;

use DateInterval;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Notification\TelegramLinkTokenRepository;

final class TelegramLinkTokenRepositoryTest extends TestCase
{
    private PDO $db;
    private TelegramLinkTokenRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE telegram_link_tokens (selector TEXT PRIMARY KEY, user_id INTEGER UNIQUE NOT NULL, verifier_hash TEXT NOT NULL, expires_at TEXT NOT NULL, consumed_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->repository = new TelegramLinkTokenRepository($this->db);
    }

    public function testTokenIsHashedSingleUseAndUserScoped(): void
    {
        $now = new DateTimeImmutable('2026-09-11 12:00:00');
        $token = $this->repository->issue(7, $now, new DateInterval('PT24H'));
        [$selector, $verifier] = explode('.', $token, 2);
        $stored = $this->db->query("SELECT verifier_hash FROM telegram_link_tokens WHERE selector = '" . $selector . "'")->fetchColumn();
        self::assertSame(hash('sha256', $verifier), $stored);
        self::assertNotSame($verifier, $stored);
        self::assertFalse($this->repository->consume(8, $token, $now));
        self::assertTrue($this->repository->consume(7, $token, $now));
        self::assertFalse($this->repository->consume(7, $token, $now));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $now = new DateTimeImmutable('2026-09-11 12:00:00');
        $token = $this->repository->issue(7, $now, new DateInterval('PT1H'));
        self::assertFalse($this->repository->consume(7, $token, $now->modify('+2 hours')));
    }
}
