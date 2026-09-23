<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Application\WebhookDeliveryRunner;
use Tms\Application\WebhookEventConsumer;
use Tms\Domain\Event\DomainEvent;
use Tms\Domain\Event\DomainEventRepository;
use Tms\Domain\Webhook\WebhookDeliveryRepository;
use Tms\Domain\Webhook\WebhookSubscriptionRepository;
use Tms\Infrastructure\SecretBox;
use Tms\Infrastructure\WebhookDeliveryResult;
use Tms\Infrastructure\WebhookSender;

final class WebhookDeliveryRunnerTest extends TestCase
{
    private PDO $db;
    private DomainEventRepository $events;
    private WebhookSubscriptionRepository $subscriptions;
    private WebhookDeliveryRepository $deliveries;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->schema();

        $this->events = new DomainEventRepository($this->db);
        $this->subscriptions = new WebhookSubscriptionRepository(
            $this->db,
            new SecretBox(base64_encode(str_repeat('h', 32))),
        );
        $this->deliveries = new WebhookDeliveryRepository($this->db);
    }

    public function testEventConsumerEnqueuesMatchingSubscriptionOnlyOnce(): void
    {
        $matching = $this->subscriptions->create(
            1,
            'Tasks',
            'https://receiver.example.test/tasks',
            ['task.created'],
        );
        $this->subscriptions->create(
            1,
            'Projects',
            'https://receiver.example.test/projects',
            ['project.created'],
        );
        $event = $this->event();

        $this->events->append($event);
        $consumer = new WebhookEventConsumer($this->subscriptions, $this->deliveries);
        $consumer->consume($event);
        $consumer->consume($event);

        self::assertSame(
            1,
            (int) $this->db->query(
                'SELECT COUNT(*) FROM webhook_deliveries WHERE subscription_id=' . (int) $matching['id']
            )->fetchColumn(),
        );
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM webhook_deliveries')->fetchColumn());
    }

    public function testRunnerRecoversMissingDeliveryAndSignsExactBody(): void
    {
        $created = $this->subscriptions->create(
            1,
            'Tasks',
            'https://receiver.example.test/tasks',
            ['task.created'],
        );
        $event = $this->event();
        $this->events->append($event);

        // Simulate a synchronous enqueue failure: the journal exists, delivery does not.
        $sender = new RecordingWebhookSender(new WebhookDeliveryResult(true, 204, null));
        $runner = new WebhookDeliveryRunner(
            $this->subscriptions,
            $this->deliveries,
            $this->events,
            $sender,
        );

        $stats = $runner->run(new DateTimeImmutable('2026-09-23 13:00:00'));

        self::assertSame(1, $stats['recovered']);
        self::assertSame(1, $stats['attempted']);
        self::assertSame(1, $stats['delivered']);
        self::assertCount(1, $sender->requests);

        $request = $sender->requests[0];
        self::assertSame('https://receiver.example.test/tasks', $request['url']);
        self::assertSame('task.created', $request['headers']['X-TMS-Event-Type']);
        self::assertSame($event->id, $request['headers']['X-TMS-Event-Id']);
        self::assertSame('1', $request['headers']['X-TMS-Webhook-Version']);

        $subscription = $this->subscriptions->find($created['id']);
        self::assertNotNull($subscription);
        $timestamp = $request['headers']['X-TMS-Timestamp'];
        $expected = hash_hmac(
            'sha256',
            $timestamp . '.' . $request['body'],
            $this->subscriptions->secret($subscription),
        );
        self::assertSame('v1=' . $expected, $request['headers']['X-TMS-Signature']);

        $payload = json_decode($request['body'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame($event->id, $payload['id']);
        self::assertSame('task.created', $payload['type']);
        self::assertSame(42, $payload['subject']['task_id']);
        self::assertSame('Deploy', $payload['data']['subject_title']);

        self::assertSame(
            'succeeded',
            (string) $this->db->query('SELECT status FROM webhook_deliveries LIMIT 1')->fetchColumn(),
        );
        self::assertSame(
            204,
            (int) $this->db->query('SELECT response_status FROM webhook_deliveries LIMIT 1')->fetchColumn(),
        );
    }

    public function testFailedDeliveryIsScheduledWithBackoff(): void
    {
        $created = $this->subscriptions->create(
            1,
            'Tasks',
            'https://receiver.example.test/tasks',
            ['task.created'],
        );
        $event = $this->event();
        $this->events->append($event);
        $this->deliveries->enqueue($created['id'], $event->id);

        $runner = new WebhookDeliveryRunner(
            $this->subscriptions,
            $this->deliveries,
            $this->events,
            new RecordingWebhookSender(new WebhookDeliveryResult(false, 503, 'HTTP 503')),
        );

        $stats = $runner->run(new DateTimeImmutable('2026-09-23 13:00:00'));

        self::assertSame(1, $stats['retried']);
        $row = $this->db->query(
            'SELECT status, attempt_count, response_status, next_attempt_at, last_error
             FROM webhook_deliveries LIMIT 1'
        )->fetch();
        self::assertIsArray($row);
        self::assertSame('retry', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertSame(503, (int) $row['response_status']);
        self::assertSame('2026-09-23 13:01:00.000000', $row['next_attempt_at']);
        self::assertSame('HTTP 503', $row['last_error']);
    }

    private function event(): DomainEvent
    {
        return new DomainEvent(
            id: '11111111-2222-4333-8444-555555555555',
            type: 'task.created',
            schemaVersion: 1,
            actorUserId: 7,
            actorUsername: 'alice',
            taskId: 42,
            projectId: null,
            commentId: null,
            visibilityUserId: 7,
            visibilityTeamId: null,
            payload: ['subject_title' => 'Deploy', 'changes' => []],
            occurredAt: '2026-09-23 12:34:56.123456',
        );
    }

    private function schema(): void
    {
        $this->db->exec('CREATE TABLE domain_events (
            event_id TEXT PRIMARY KEY,
            event_type TEXT NOT NULL,
            schema_version INTEGER NOT NULL,
            actor_user_id INTEGER NULL,
            actor_username TEXT NOT NULL,
            task_id INTEGER NULL,
            project_id INTEGER NULL,
            comment_id INTEGER NULL,
            visibility_user_id INTEGER NULL,
            visibility_team_id INTEGER NULL,
            payload_json TEXT NOT NULL,
            occurred_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->db->exec('CREATE TABLE webhook_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            endpoint_url TEXT NOT NULL,
            secret_ciphertext TEXT NOT NULL,
            event_types_json TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            active_since TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->db->exec('CREATE TABLE webhook_deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id INTEGER NOT NULL,
            event_id TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            attempt_count INTEGER NOT NULL DEFAULT 0,
            next_attempt_at TEXT NULL DEFAULT CURRENT_TIMESTAMP,
            last_attempt_at TEXT NULL,
            response_status INTEGER NULL,
            last_error TEXT NULL,
            delivered_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (subscription_id, event_id)
        )');
    }
}

final class RecordingWebhookSender implements WebhookSender
{
    /** @var list<array{url:string,headers:array<string,string>,body:string}> */
    public array $requests = [];

    public function __construct(private readonly WebhookDeliveryResult $result)
    {
    }

    public function send(string $url, array $headers, string $body): WebhookDeliveryResult
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        return $this->result;
    }
}
