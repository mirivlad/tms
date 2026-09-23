<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Application\NotificationRunner;
use Tms\Domain\Notification\InternalNotificationRepository;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Notification\NotificationTaskRepository;
use Tms\Domain\Notification\SentNotificationRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\TelegramSender;

final class NotificationRunnerTest extends TestCase
{
    public function testSuccessfulChannelIsDedupedWhileFailedChannelRetries(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction(
            'TIME_FORMAT',
            static fn (mixed $value, mixed $format): string => substr((string) $value, 0, 5),
            2,
        );
        $this->schema($db);

        $db->exec(
            "INSERT INTO users (id, username, email, is_active, approved_at)
             VALUES (1, 'alice', 'alice@example.com', 1, '2026-09-01 00:00:00')"
        );
        $db->exec(
            "INSERT INTO notification_settings (
                user_id, email_enabled, telegram_enabled, telegram_chat_id,
                notify_tomorrow, tomorrow_time, notify_upcoming,
                urgent_minutes, high_minutes, medium_minutes, low_minutes,
                notify_overdue, overdue_time, notify_digest, digest_time
             ) VALUES (
                1, 1, 1, '12345',
                1, '08:00:00', 0,
                15, 60, 240, 1440,
                0, '09:00:00', 0, '19:30:00'
             )"
        );
        $db->exec("INSERT INTO statuses (id, user_id, is_completion) VALUES (10, 1, 0)");
        $db->exec(
            "INSERT INTO tasks (id, created_by, title, deadline, priority, status_id)
             VALUES (100, 1, 'Tomorrow task', '2026-09-12 12:00:00', 2, 10)"
        );

        $email = new class implements EmailSender {
            public int $calls = 0;

            public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
            {
                $this->calls++;
                return true;
            }
        };
        $telegram = new class implements TelegramSender {
            public int $calls = 0;

            public function send(string $chatId, string $text): bool
            {
                $this->calls++;
                return $this->calls >= 2;
            }
        };

        $runner = new NotificationRunner(
            new NotificationSettingsRepository($db),
            new NotificationTaskRepository($db),
            new InternalNotificationRepository($db),
            new SentNotificationRepository($db),
            $email,
            $telegram,
            'https://tms.example.test',
            new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'en'),
        );
        $now = new DateTimeImmutable('2026-09-11 10:00:00');

        $first = $runner->run($now);
        self::assertSame(['users' => 1, 'attempted' => 2, 'sent' => 1], $first);
        self::assertSame(1, $email->calls);
        self::assertSame(1, $telegram->calls);
        self::assertSame(
            1,
            (int) $db->query("SELECT COUNT(*) FROM sent_notifications WHERE channel='email'")->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $db->query("SELECT COUNT(*) FROM sent_notifications WHERE channel='telegram'")->fetchColumn(),
        );

        $second = $runner->run($now);
        self::assertSame(['users' => 1, 'attempted' => 1, 'sent' => 1], $second);
        self::assertSame(1, $email->calls, 'Successful email delivery must not be sent again.');
        self::assertSame(2, $telegram->calls, 'Failed Telegram delivery should be retried.');
        self::assertSame(
            1,
            (int) $db->query("SELECT COUNT(*) FROM sent_notifications WHERE channel='telegram'")->fetchColumn(),
        );

        $third = $runner->run($now);
        self::assertSame(['users' => 1, 'attempted' => 0, 'sent' => 0], $third);
        self::assertSame(1, $email->calls);
        self::assertSame(2, $telegram->calls);
        self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM sent_notifications')->fetchColumn());
    }

    public function testUpcomingPlannedTimeCreatesCanonicalInboxNotification(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction(
            'TIME_FORMAT',
            static fn (mixed $value, mixed $format): string => substr((string) $value, 0, 5),
            2,
        );
        $this->schema($db);

        $db->exec("INSERT INTO users (id, username, email, is_active, approved_at)
            VALUES (1, 'alice', 'alice@example.com', 1, '2026-09-01 00:00:00')");
        $db->exec("INSERT INTO notification_settings (
            user_id, notify_upcoming, urgent_minutes, high_minutes, medium_minutes, low_minutes
        ) VALUES (1, 1, 15, 60, 240, 1440)");
        $db->exec("INSERT INTO statuses (id, user_id, is_completion) VALUES (10, 1, 0)");
        $db->exec("INSERT INTO tasks (
            id, created_by, title, scheduled_at, priority, status_id
        ) VALUES (101, 1, 'Planned task', '2026-09-11 10:10:00', 3, 10)");

        $email = new class implements EmailSender {
            public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
            {
                return true;
            }
        };
        $telegram = new class implements TelegramSender {
            public function send(string $chatId, string $text): bool
            {
                return true;
            }
        };

        $runner = new NotificationRunner(
            new NotificationSettingsRepository($db),
            new NotificationTaskRepository($db),
            new InternalNotificationRepository($db),
            new SentNotificationRepository($db),
            $email,
            $telegram,
            'https://tms.example.test',
            new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'en'),
        );

        $stats = $runner->run(new DateTimeImmutable('2026-09-11 10:00:00'));
        self::assertSame(1, $stats['users']);
        self::assertSame(0, $stats['attempted']);
        self::assertSame(
            1,
            (int) $db->query("SELECT COUNT(*) FROM internal_notifications WHERE notification_type='reminder_planned_upcoming'")->fetchColumn(),
        );
        self::assertSame(
            '/tasks/101/edit',
            (string) $db->query("SELECT target_url FROM internal_notifications LIMIT 1")->fetchColumn(),
        );

        $runner->run(new DateTimeImmutable('2026-09-11 10:00:00'));
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM internal_notifications')->fetchColumn());
    }

    private function schema(PDO $db): void
    {
        $db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                is_active INTEGER NOT NULL,
                approved_at TEXT NULL
            )'
        );
        $db->exec(
            'CREATE TABLE notification_settings (
                user_id INTEGER PRIMARY KEY,
                email_enabled INTEGER NOT NULL DEFAULT 0,
                email_address TEXT NULL,
                telegram_enabled INTEGER NOT NULL DEFAULT 0,
                telegram_chat_id TEXT NULL,
                telegram_username TEXT NULL,
                notify_task_assignments INTEGER NOT NULL DEFAULT 1,
                notify_task_dates INTEGER NOT NULL DEFAULT 1,
                notify_task_status INTEGER NOT NULL DEFAULT 0,
                notify_tomorrow INTEGER NOT NULL DEFAULT 0,
                tomorrow_time TEXT NOT NULL DEFAULT "08:00:00",
                notify_upcoming INTEGER NOT NULL DEFAULT 0,
                urgent_minutes INTEGER NOT NULL DEFAULT 15,
                high_minutes INTEGER NOT NULL DEFAULT 60,
                medium_minutes INTEGER NOT NULL DEFAULT 240,
                low_minutes INTEGER NOT NULL DEFAULT 1440,
                notify_overdue INTEGER NOT NULL DEFAULT 0,
                overdue_time TEXT NOT NULL DEFAULT "09:00:00",
                notify_digest INTEGER NOT NULL DEFAULT 0,
                digest_time TEXT NOT NULL DEFAULT "19:30:00"
            )'
        );
        $db->exec(
            'CREATE TABLE statuses (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NULL,
                is_completion INTEGER NOT NULL DEFAULT 0
            )'
        );
        $db->exec(
            'CREATE TABLE projects (
                id INTEGER PRIMARY KEY,
                owner_user_id INTEGER NULL,
                owner_team_id INTEGER NULL
            )'
        );
        $db->exec(
            'CREATE TABLE team_members (
                team_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT NOT NULL,
                PRIMARY KEY (team_id, user_id)
            )'
        );
        $db->exec(
            'CREATE TABLE tasks (
                id INTEGER PRIMARY KEY,
                created_by INTEGER NOT NULL,
                title TEXT NOT NULL,
                deadline TEXT NULL,
                scheduled_at TEXT NULL,
                priority INTEGER NOT NULL DEFAULT 1,
                status_id INTEGER NULL,
                project_id INTEGER NULL,
                assignee_user_id INTEGER NULL
            )'
        );
        $db->exec(
            'CREATE TABLE internal_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                actor_user_id INTEGER NULL,
                actor_username TEXT NOT NULL,
                notification_type TEXT NOT NULL,
                context_label TEXT NOT NULL,
                body_preview TEXT NOT NULL DEFAULT "",
                target_url TEXT NOT NULL,
                project_id INTEGER NULL,
                task_id INTEGER NULL,
                comment_id INTEGER NULL,
                dedupe_key TEXT NOT NULL,
                read_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, dedupe_key)
            )'
        );
        $db->exec(
            'CREATE TABLE sent_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                task_id INTEGER NULL,
                channel TEXT NOT NULL,
                notification_type TEXT NOT NULL,
                dedupe_key TEXT NOT NULL,
                sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, channel, dedupe_key)
            )'
        );
    }
}
