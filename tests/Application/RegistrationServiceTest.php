<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Application\RegistrationService;
use Tms\Application\UserBootstrapService;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\TaskType\TaskTypeRepository;
use Tms\Domain\User\UserRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Security\EmailVerificationTokenRepository;

final class RegistrationServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
    }

    public function testRegistrationCreatesDefaultsAndVerificationActivatesAccount(): void
    {
        $mail = new class implements EmailSender {
            public string $text = '';
            public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
            {
                $this->text = $text;
                return true;
            }
        };
        $service = $this->service($mail, true);
        $now = new DateTimeImmutable('2026-09-12 10:00:00', new DateTimeZone('UTC'));

        $result = $service->register('alice', 'alice@example.test', 'registration-password-12345', $now);
        self::assertFalse($result['user']->isEmailVerified);
        self::assertFalse($result['user']->isApproved);
        self::assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM statuses WHERE user_id=1')->fetchColumn());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM task_types WHERE user_id=1')->fetchColumn());

        self::assertMatchesRegularExpression('/verify-email\?token=([0-9a-f]{24}\.[0-9a-f]{64})/', $mail->text);
        preg_match('/verify-email\?token=([0-9a-f]{24}\.[0-9a-f]{64})/', $mail->text, $matches);
        $verified = $service->verify($matches[1], $now);
        self::assertNotNull($verified);
        self::assertTrue($verified->isEmailVerified);
        self::assertTrue($verified->isApproved);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM email_verification_tokens')->fetchColumn());
    }

    public function testManualApprovalModeDoesNotApproveAfterEmailVerification(): void
    {
        $mail = new class implements EmailSender {
            public string $text = '';
            public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
            {
                $this->text = $text;
                return true;
            }
        };
        $service = $this->service($mail, false);
        $now = new DateTimeImmutable('2026-09-12 10:00:00', new DateTimeZone('UTC'));
        $service->register('bob', 'bob@example.test', 'registration-password-12345', $now);
        preg_match('/verify-email\?token=([0-9a-f]{24}\.[0-9a-f]{64})/', $mail->text, $matches);
        $verified = $service->verify($matches[1], $now);
        self::assertNotNull($verified);
        self::assertTrue($verified->isEmailVerified);
        self::assertFalse($verified->isApproved);
    }

    private function service(EmailSender $mail, bool $autoApprove): RegistrationService
    {
        $users = new UserRepository($this->db);
        $statuses = new StatusRepository($this->db);
        $types = new TaskTypeRepository($this->db);
        $translator = new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'en');
        return new RegistrationService(
            $users,
            new EmailVerificationTokenRepository($this->db),
            new UserBootstrapService($statuses, $types, $translator),
            $mail,
            $translator,
            'https://tms.example.test',
            $autoApprove,
        );
    }

    private function createSchema(): void
    {
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, email TEXT UNIQUE, password_hash TEXT, role TEXT, is_active INTEGER, email_verified_at TEXT NULL, approved_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->db->exec('CREATE TABLE email_verification_tokens (selector TEXT PRIMARY KEY, user_id INTEGER, verifier_hash TEXT, expires_at TEXT, consumed_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->db->exec('CREATE TABLE statuses (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, description TEXT DEFAULT "", color TEXT, sort_order INTEGER DEFAULT 0, is_default INTEGER DEFAULT 0, is_completion INTEGER DEFAULT 0, show_on_board INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->db->exec('CREATE TABLE task_types (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, description TEXT DEFAULT "", sort_order INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    }
}
