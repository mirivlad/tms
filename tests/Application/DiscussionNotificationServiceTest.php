<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Application\DiscussionNotificationService;
use Tms\Domain\Discussion\DiscussionRepository;
use Tms\Domain\Notification\InternalNotificationRepository;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Team\TeamRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\TelegramSender;

final class DiscussionNotificationServiceTest extends TestCase
{
    private PDO $db;
    private DiscussionRepository $discussions;
    private InternalNotificationRepository $notifications;
    private RecordingDiscussionEmailSender $email;
    private RecordingDiscussionTelegramSender $telegram;
    private DiscussionNotificationService $service;

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
            email TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            approved_at TEXT NULL
        )');
        $this->db->exec('CREATE TABLE teams (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            created_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->db->exec('CREATE TABLE team_members (
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (team_id, user_id)
        )');
        $this->db->exec('CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            owner_user_id INTEGER NULL,
            owner_team_id INTEGER NULL,
            name TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE tasks (
            id INTEGER PRIMARY KEY,
            project_id INTEGER NULL,
            title TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE discussion_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NULL,
            task_id INTEGER NULL,
            team_id INTEGER NULL,
            parent_comment_id INTEGER NULL,
            author_user_id INTEGER NULL,
            body_html TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT NULL
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

        $this->db->exec("INSERT INTO users (id,username,email,approved_at) VALUES
            (1,'lead','lead@example.test',CURRENT_TIMESTAMP),
            (2,'member','member@example.test',CURRENT_TIMESTAMP),
            (3,'watcher','watcher@example.test',CURRENT_TIMESTAMP),
            (4,'outsider','outsider@example.test',CURRENT_TIMESTAMP)");
        $this->db->exec("INSERT INTO teams (id,name,created_by) VALUES (7,'Core',1)");
        $this->db->exec("INSERT INTO team_members (team_id,user_id,role) VALUES
            (7,1,'lead'),(7,2,'member'),(7,3,'member')");
        $this->db->exec("INSERT INTO projects (id,owner_user_id,owner_team_id,name)
            VALUES (10,NULL,7,'Shared project')");
        $this->db->exec("INSERT INTO tasks (id,project_id,title) VALUES (100,10,'Shared task')");
        $this->db->exec("INSERT INTO notification_settings (
            user_id,email_enabled,email_address,telegram_enabled,telegram_chat_id
        ) VALUES
            (1,1,'lead-delivery@example.test',1,'111'),
            (2,0,NULL,0,NULL),
            (3,0,NULL,0,NULL)");

        $this->discussions = new DiscussionRepository($this->db);
        $this->notifications = new InternalNotificationRepository($this->db);
        $this->email = new RecordingDiscussionEmailSender();
        $this->telegram = new RecordingDiscussionTelegramSender();
        $this->service = new DiscussionNotificationService(
            $this->discussions,
            new TeamRepository($this->db),
            $this->notifications,
            new NotificationSettingsRepository($this->db),
            $this->email,
            $this->telegram,
            new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'en'),
            'https://tms.example.test',
        );
    }

    public function testMentionTargetsCurrentTeamMembersAndExternalChannels(): void
    {
        $commentId = $this->discussions->createForProject(
            2,
            10,
            '<p>Hello @lead and @outsider</p>',
        );
        $this->service->processComment(2, $commentId);

        self::assertSame('7', (string) $this->db->query(
            'SELECT team_id FROM discussion_comments WHERE id = ' . $commentId
        )->fetchColumn());
        self::assertSame(1, $this->notifications->countUnreadForUser(1));
        self::assertSame(0, $this->notifications->countUnreadForUser(4));
        $notification = $this->notifications->listForUser(1)[0];
        self::assertSame('discussion_mention', $notification->notificationType);
        self::assertSame('/projects/10/discussion#comment-' . $commentId, $notification->targetUrl);
        self::assertStringContainsString('@lead', $notification->bodyPreview);

        self::assertCount(1, $this->email->messages);
        self::assertSame('lead-delivery@example.test', $this->email->messages[0]['to']);
        self::assertStringContainsString('https://tms.example.test/projects/10/discussion#comment-', $this->email->messages[0]['text']);
        self::assertCount(1, $this->telegram->messages);
        self::assertSame('111', $this->telegram->messages[0]['chat_id']);

        $this->service->processComment(2, $commentId);
        self::assertSame(1, $this->notifications->countUnreadForUser(1));
        self::assertCount(1, $this->email->messages);
    }

    public function testReplyWinsOverDuplicateMentionAndEditCanNotifyNewMention(): void
    {
        $root = $this->discussions->createForTask(2, 100, '<p>Root</p>');
        $reply = $this->discussions->createForTask(1, 100, '<p>@member reply and @watcher ping</p>', $root);
        $this->service->processComment(1, $reply);

        $member = $this->notifications->listForUser(2);
        self::assertCount(1, $member);
        self::assertSame('discussion_reply', $member[0]->notificationType);
        self::assertStringContainsString('/tasks/100/discussion#comment-', $member[0]->targetUrl);

        $watcher = $this->notifications->listForUser(3);
        self::assertCount(1, $watcher);
        self::assertSame('discussion_mention', $watcher[0]->notificationType);

        $rootByLead = $this->discussions->createForProject(1, 10, '<p>No mention yet</p>');
        $this->service->processComment(1, $rootByLead);
        self::assertSame(1, $this->notifications->countUnreadForUser(3));

        self::assertTrue($this->discussions->updateForProject(
            1,
            10,
            $rootByLead,
            '<p>Now @watcher is mentioned</p>',
        ));
        $this->service->processComment(1, $rootByLead);
        self::assertSame(2, $this->notifications->countUnreadForUser(3));

        $this->service->processComment(1, $rootByLead);
        self::assertSame(2, $this->notifications->countUnreadForUser(3));
    }
}

final class RecordingDiscussionEmailSender implements EmailSender
{
    /** @var list<array{to:string,subject:string,text:string}> */
    public array $messages = [];

    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): bool
    {
        $this->messages[] = ['to' => $toEmail, 'subject' => $subject, 'text' => $text];
        return true;
    }
}

final class RecordingDiscussionTelegramSender implements TelegramSender
{
    /** @var list<array{chat_id:string,text:string}> */
    public array $messages = [];

    public function send(string $chatId, string $text): bool
    {
        $this->messages[] = ['chat_id' => $chatId, 'text' => $text];
        return true;
    }
}
