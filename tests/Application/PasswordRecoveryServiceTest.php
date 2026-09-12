<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Application\PasswordRecoveryService;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\User\UserRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\TelegramSender;
use Tms\Security\PasswordResetTokenRepository;
use Tms\Security\RememberTokenRepository;

final class PasswordRecoveryServiceTest extends TestCase
{
    private PDO $db;
    private PasswordResetTokenRepository $resetTokens;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->sqliteCreateFunction(
            'TIME_FORMAT',
            static fn (mixed $value, string $format): ?string => $value === null ? null : substr((string) $value, 0, 5),
            2,
        );
        $this->createSchema();
        $this->resetTokens = new PasswordResetTokenRepository($this->db);
    }

    public function testRecoveryDoesNotRequireConfiguredExternalChannel(): void
    {
        $this->insertUser();
        $email = new class implements EmailSender {
            public int $calls = 0;
            public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
            {
                $this->calls++;
                return false;
            }
        };
        $telegram = new class implements TelegramSender {
            public int $calls = 0;
            public function send(string $chatId, string $text): bool
            {
                $this->calls++;
                return false;
            }
        };
        $service = $this->service($email, $telegram);
        $service->request('recovery', new DateTimeImmutable('2026-09-12 00:00:00', new DateTimeZone('UTC')));

        self::assertSame(1, $email->calls);
        self::assertSame(0, $telegram->calls);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }

    public function testResetChangesPasswordAndRevokesPersistentTokens(): void
    {
        $this->insertUser();
        $now = new DateTimeImmutable('2026-09-12 00:00:00', new DateTimeZone('UTC'));
        $token = $this->resetTokens->issue(1, $now, new \DateInterval('PT1H'));
        $this->db->exec("INSERT INTO remember_tokens (selector,user_id,verifier_hash,expires_at) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaa',1,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','2026-09-13 00:00:00')");

        $service = $this->service(
            new class implements EmailSender { public function send(string $a, string $b, string $c, string $d, string $e): bool { return false; } },
            new class implements TelegramSender { public function send(string $a, string $b): bool { return false; } },
        );
        self::assertTrue($service->reset($token->value(), 'replacement-password-12345', $now));

        $hash = (string) $this->db->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn();
        self::assertTrue(password_verify('replacement-password-12345', $hash));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM remember_tokens WHERE user_id=1')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=1')->fetchColumn());
    }

    private function service(EmailSender $email, TelegramSender $telegram): PasswordRecoveryService
    {
        return new PasswordRecoveryService(
            new UserRepository($this->db),
            $this->resetTokens,
            new RememberTokenRepository($this->db),
            new NotificationSettingsRepository($this->db),
            $email,
            $telegram,
            new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'en'),
            'https://tms.example.test',
        );
    }

    private function insertUser(): void
    {
        $hash = password_hash('original-password-12345', PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("INSERT INTO users (id,username,email,password_hash,role,is_active,approved_at) VALUES (1,'recovery','recovery@example.test',:hash,'admin',1,'2026-01-01 00:00:00')");
        $stmt->execute(['hash' => $hash]);
    }

    private function createSchema(): void
    {
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT UNIQUE, email TEXT UNIQUE, password_hash TEXT, role TEXT, is_active INTEGER, approved_at TEXT)');
        $this->db->exec('CREATE TABLE remember_tokens (selector TEXT PRIMARY KEY, user_id INTEGER, verifier_hash TEXT, expires_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->db->exec('CREATE TABLE password_reset_tokens (selector TEXT PRIMARY KEY, user_id INTEGER, verifier_hash TEXT, expires_at TEXT, consumed_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->db->exec("CREATE TABLE notification_settings (user_id INTEGER PRIMARY KEY, email_enabled INTEGER DEFAULT 0, email_address TEXT NULL, telegram_enabled INTEGER DEFAULT 0, telegram_chat_id TEXT NULL, telegram_username TEXT NULL, notify_tomorrow INTEGER DEFAULT 0, tomorrow_time TEXT DEFAULT '08:00', notify_upcoming INTEGER DEFAULT 0, urgent_minutes INTEGER DEFAULT 15, high_minutes INTEGER DEFAULT 60, medium_minutes INTEGER DEFAULT 240, low_minutes INTEGER DEFAULT 1440, notify_overdue INTEGER DEFAULT 0, overdue_time TEXT DEFAULT '09:00', notify_digest INTEGER DEFAULT 0, digest_time TEXT DEFAULT '19:30')");
    }
}
