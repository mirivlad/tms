<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Application\TaskEventNotificationConsumer;
use Tms\Domain\Event\DomainEvent;
use Tms\Domain\Notification\InternalNotificationRepository;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\TelegramSender;

final class TaskEventNotificationConsumerTest extends TestCase
{
    private PDO $db;
    private InternalNotificationRepository $notifications;
    private RecordingTaskEventEmailSender $email;
    private TaskEventNotificationConsumer $consumer;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->sqliteCreateFunction(
            'TIME_FORMAT',
            static fn (?string $value, string $format): ?string => $value === null ? null : substr($value, 0, 5),
            2,
        );

        $this->db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            email TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE teams (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            created_by INTEGER NOT NULL
        )');
        $this->db->exec('CREATE TABLE team_members (
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            PRIMARY KEY (team_id, user_id)
        )');
        $this->db->exec('CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            owner_user_id INTEGER NULL,
            owner_team_id INTEGER NULL
        )');
        $this->db->exec('CREATE TABLE tasks (
            id INTEGER PRIMARY KEY,
            created_by INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            deadline TEXT NULL,
            scheduled_at TEXT NULL,
            status_id INTEGER NULL,
            type_id INTEGER NULL,
            priority INTEGER NOT NULL DEFAULT 0,
            customer_id INTEGER NULL,
            project_id INTEGER NULL,
            assignee_user_id INTEGER NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE notification_settings (
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
        $this->db->exec('CREATE TABLE internal_notifications (
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
        )');

        $this->db->exec("INSERT INTO users (id,username,email) VALUES
            (1,'owner','owner@example.test'),
            (2,'assignee','assignee@example.test'),
            (3,'previous','previous@example.test'),
            (4,'teammate','teammate@example.test')");
        $this->db->exec("INSERT INTO teams (id,name,created_by) VALUES (7,'Core',1)");
        $this->db->exec("INSERT INTO team_members (team_id,user_id,role) VALUES
            (7,1,'lead'),(7,2,'member'),(7,3,'member'),(7,4,'member')");
        $this->db->exec("INSERT INTO projects (id,owner_user_id,owner_team_id) VALUES (10,NULL,7)");
        $this->db->exec("INSERT INTO tasks (
            id,created_by,title,description,deadline,scheduled_at,status_id,type_id,priority,
            customer_id,project_id,assignee_user_id,created_at,updated_at
        ) VALUES (
            100,1,'Deploy','', '2026-09-25 17:00:00','2026-09-24 09:00:00',
            NULL,NULL,2,NULL,10,2,'2026-09-20 09:00:00','2026-09-20 09:00:00'
        )");
        $this->db->exec("INSERT INTO notification_settings (
            user_id,email_enabled,email_address,notify_task_assignments,notify_task_dates,notify_task_status
        ) VALUES
            (1,0,NULL,1,1,0),
            (2,1,'assignee-delivery@example.test',1,0,1),
            (3,0,NULL,1,1,1),
            (4,0,NULL,1,1,1)");

        $this->notifications = new InternalNotificationRepository($this->db);
        $this->email = new RecordingTaskEventEmailSender();
        $this->consumer = new TaskEventNotificationConsumer(
            new TaskRepository($this->db),
            $this->notifications,
            new NotificationSettingsRepository($this->db),
            $this->email,
            new RecordingTaskEventTelegramSender(),
            new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'en'),
            'https://tms.example.test',
        );
    }

    public function testAssignmentNotifiesNewAssigneeAndIsIdempotent(): void
    {
        $event = $this->event(
            id: '11111111-2222-4333-8444-555555555555',
            type: 'task.updated',
            actorUserId: 1,
            changes: ['assignee' => ['old' => 'previous', 'new' => 'assignee']],
            previousAssigneeUserId: 3,
        );

        $this->consumer->consume($event);
        $this->consumer->consume($event);

        self::assertSame(1, $this->notifications->countUnreadForUser(2));
        $notification = $this->notifications->listForUser(2)[0];
        self::assertSame('task_reassigned', $notification->notificationType);
        self::assertSame('/tasks/100/edit', $notification->targetUrl);
        self::assertCount(1, $this->email->messages);
        self::assertSame('assignee-delivery@example.test', $this->email->messages[0]['to']);
        self::assertSame(0, $this->notifications->countUnreadForUser(3));
    }

    public function testDateAndStatusPreferencesAreAppliedPerRecipient(): void
    {
        $event = $this->event(
            id: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            type: 'task.updated',
            actorUserId: 4,
            changes: [
                'scheduled_at' => ['old' => '2026-09-24 09:00:00', 'new' => '2026-09-24 10:00:00'],
                'deadline' => ['old' => '2026-09-25 17:00:00', 'new' => '2026-09-26 17:00:00'],
                'status' => ['old' => 'Todo', 'new' => 'Doing'],
            ],
            previousAssigneeUserId: 2,
        );

        $this->consumer->consume($event);

        $ownerTypes = array_map(
            static fn ($notification): string => $notification->notificationType,
            $this->notifications->listForUser(1),
        );
        sort($ownerTypes);
        self::assertSame(['task_deadline_changed', 'task_scheduled_changed'], $ownerTypes);

        $assigneeTypes = array_map(
            static fn ($notification): string => $notification->notificationType,
            $this->notifications->listForUser(2),
        );
        self::assertSame(['task_status_changed'], $assigneeTypes);
    }

    /** @param array<string, array{old:?string,new:?string}> $changes */
    private function event(
        string $id,
        string $type,
        int $actorUserId,
        array $changes,
        ?int $previousAssigneeUserId,
    ): DomainEvent {
        return new DomainEvent(
            id: $id,
            type: $type,
            schemaVersion: 1,
            actorUserId: $actorUserId,
            actorUsername: $actorUserId === 1 ? 'owner' : 'teammate',
            taskId: 100,
            projectId: 10,
            commentId: null,
            visibilityUserId: null,
            visibilityTeamId: 7,
            payload: [
                'subject_title' => 'Deploy',
                'changes' => $changes,
                'owner_user_id' => 1,
                'assignee_user_id' => 2,
                'previous_assignee_user_id' => $previousAssigneeUserId,
            ],
            occurredAt: '2026-09-23 12:00:00.000000',
        );
    }
}

final class RecordingTaskEventEmailSender implements EmailSender
{
    /** @var list<array{to:string,subject:string,text:string}> */
    public array $messages = [];

    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
    {
        $this->messages[] = ['to' => $toEmail, 'subject' => $subject, 'text' => $text];
        return true;
    }
}

final class RecordingTaskEventTelegramSender implements TelegramSender
{
    public function send(string $chatId, string $text): bool
    {
        return true;
    }
}
