<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Webhook;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Webhook\WebhookSubscriptionRepository;
use Tms\Infrastructure\SecretBox;

final class WebhookSubscriptionRepositoryTest extends TestCase
{
    private PDO $db;
    private WebhookSubscriptionRepository $subscriptions;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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
        $this->subscriptions = new WebhookSubscriptionRepository(
            $this->db,
            new SecretBox(base64_encode(str_repeat('w', 32))),
        );
    }

    public function testCreateStoresEncryptedSecretAndSelectedEvents(): void
    {
        $created = $this->subscriptions->create(
            7,
            'Agent bridge',
            'https://agent.example.test/tms',
            ['task.updated', 'task.created'],
        );

        self::assertGreaterThan(0, $created['id']);
        self::assertNotSame('', $created['secret']);

        $record = $this->subscriptions->find($created['id']);
        self::assertNotNull($record);
        self::assertSame(['task.created', 'task.updated'], $record->eventTypes);
        self::assertSame('Agent bridge', $record->name);
        self::assertNotSame($created['secret'], $record->secretCiphertext);
        self::assertSame($created['secret'], $this->subscriptions->secret($record));
    }

    public function testRotateSecretInvalidatesOldSecret(): void
    {
        $created = $this->subscriptions->create(
            7,
            'Agent bridge',
            'http://agent.internal/hook',
            ['task.created'],
        );
        $rotated = $this->subscriptions->rotateSecret($created['id']);

        self::assertNotNull($rotated);
        self::assertNotSame($created['secret'], $rotated);
        $record = $this->subscriptions->find($created['id']);
        self::assertNotNull($record);
        self::assertSame($rotated, $this->subscriptions->secret($record));
    }

    public function testRejectsUnsupportedEventTypesAndCredentialUrls(): void
    {
        try {
            $this->subscriptions->create(
                7,
                'Bad events',
                'https://example.test/hook',
                ['task.created', 'system.root'],
            );
            self::fail('Unsupported event type should be rejected.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $this->expectException(DomainException::class);
        $this->subscriptions->create(
            7,
            'Credentials',
            'https://user:pass@example.test/hook',
            ['task.created'],
        );
    }
}
