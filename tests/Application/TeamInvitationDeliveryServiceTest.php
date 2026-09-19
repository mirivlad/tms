<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Application\TeamInvitationDeliveryService;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Team\TeamInvitationRecord;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\TelegramSender;

final class TeamInvitationDeliveryServiceTest extends TestCase
{
    public function testConfiguredEmailAndTelegramBothReceiveInvitationLink(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->sqliteCreateFunction(
            'TIME_FORMAT',
            static fn (?string $value, string $format): ?string => $value === null ? null : substr($value, 0, 5),
            2,
        );
        $db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            email TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            approved_at TEXT NULL
        )');
        $db->exec('CREATE TABLE notification_settings (
            user_id INTEGER PRIMARY KEY,
            email_enabled INTEGER NOT NULL DEFAULT 0,
            email_address TEXT NULL,
            telegram_enabled INTEGER NOT NULL DEFAULT 0,
            telegram_chat_id TEXT NULL,
            telegram_username TEXT NULL,
            notify_tomorrow INTEGER NOT NULL DEFAULT 0,
            tomorrow_time TEXT NULL,
            notify_upcoming INTEGER NOT NULL DEFAULT 0,
            urgent_minutes INTEGER NOT NULL DEFAULT 15,
            high_minutes INTEGER NOT NULL DEFAULT 60,
            medium_minutes INTEGER NOT NULL DEFAULT 240,
            low_minutes INTEGER NOT NULL DEFAULT 1440,
            notify_overdue INTEGER NOT NULL DEFAULT 0,
            overdue_time TEXT NULL,
            notify_digest INTEGER NOT NULL DEFAULT 0,
            digest_time TEXT NULL
        )');
        $db->exec("INSERT INTO users (id, username, email, approved_at)
                   VALUES (2, 'invitee', 'account@example.test', CURRENT_TIMESTAMP)");
        $db->exec("INSERT INTO notification_settings (
            user_id, email_enabled, email_address, telegram_enabled, telegram_chat_id
        ) VALUES (2, 1, 'delivery@example.test', 1, '123456')");

        $email = new RecordingEmailSender();
        $telegram = new RecordingTelegramSender();
        $service = new TeamInvitationDeliveryService(
            new NotificationSettingsRepository($db),
            $email,
            $telegram,
            'https://tms.example.test/',
            new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'en'),
        );

        $stats = $service->deliver(new TeamInvitationRecord(
            id: 10,
            teamId: 7,
            teamName: 'Core Team',
            invitedUserId: 2,
            invitedUsername: 'invitee',
            invitedEmail: 'account@example.test',
            invitedBy: 1,
            invitedByUsername: 'lead',
            status: 'pending',
            expiresAt: '2026-09-26 10:00:00',
            createdAt: '2026-09-19 10:00:00',
            respondedAt: null,
        ));

        self::assertSame(['attempted' => 2, 'sent' => 2], $stats);
        self::assertSame('delivery@example.test', $email->to);
        self::assertStringContainsString('Core Team', $email->subject);
        self::assertStringContainsString('https://tms.example.test/invitations', $email->text);
        self::assertSame('123456', $telegram->chatId);
        self::assertStringContainsString('lead invited you', $telegram->text);
        self::assertStringContainsString('https://tms.example.test/invitations', $telegram->text);
    }
}

final class RecordingEmailSender implements EmailSender
{
    public string $to = '';
    public string $subject = '';
    public string $text = '';

    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
    {
        $this->to = $toEmail;
        $this->subject = $subject;
        $this->text = $text;
        return true;
    }
}

final class RecordingTelegramSender implements TelegramSender
{
    public string $chatId = '';
    public string $text = '';

    public function send(string $chatId, string $text): bool
    {
        $this->chatId = $chatId;
        $this->text = $text;
        return true;
    }
}
