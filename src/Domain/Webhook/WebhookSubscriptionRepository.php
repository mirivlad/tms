<?php

declare(strict_types=1);

namespace Tms\Domain\Webhook;

use DomainException;
use JsonException;
use PDO;
use Tms\Domain\Event\DomainEventCatalog;
use Tms\Infrastructure\SecretBox;

final class WebhookSubscriptionRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly SecretBox $secretBox,
    ) {
    }

    /** @return list<WebhookSubscriptionRecord> */
    public function listAll(): array
    {
        $stmt = $this->db->query(
            'SELECT id, name, endpoint_url, secret_ciphertext, event_types_json,
                    is_active, active_since, created_by, created_at, updated_at
             FROM webhook_subscriptions
             ORDER BY id ASC'
        );
        return $stmt === false ? [] : $this->fetchAll($stmt);
    }

    /** @return list<WebhookSubscriptionRecord> */
    public function listActive(): array
    {
        $stmt = $this->db->query(
            'SELECT id, name, endpoint_url, secret_ciphertext, event_types_json,
                    is_active, active_since, created_by, created_at, updated_at
             FROM webhook_subscriptions
             WHERE is_active = 1
             ORDER BY id ASC'
        );
        return $stmt === false ? [] : $this->fetchAll($stmt);
    }

    public function find(int $id): ?WebhookSubscriptionRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, endpoint_url, secret_ciphertext, event_types_json,
                    is_active, active_since, created_by, created_at, updated_at
             FROM webhook_subscriptions
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param list<string> $eventTypes
     * @return array{id:int,secret:string}
     */
    public function create(
        int $createdBy,
        string $name,
        string $endpointUrl,
        array $eventTypes,
        bool $isActive = true,
    ): array {
        [$name, $endpointUrl, $eventTypes] = $this->validate($name, $endpointUrl, $eventTypes);
        $secret = $this->generateSecret();

        $stmt = $this->db->prepare(
            'INSERT INTO webhook_subscriptions (
                name, endpoint_url, secret_ciphertext, event_types_json,
                is_active, active_since, created_by, created_at, updated_at
             ) VALUES (
                :name, :endpoint_url, :secret_ciphertext, :event_types_json,
                :is_active, CURRENT_TIMESTAMP, :created_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'name' => $name,
            'endpoint_url' => $endpointUrl,
            'secret_ciphertext' => $this->secretBox->encrypt($secret),
            'event_types_json' => json_encode(
                $eventTypes,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            'is_active' => $isActive ? 1 : 0,
            'created_by' => $createdBy,
        ]);

        return ['id' => (int) $this->db->lastInsertId(), 'secret' => $secret];
    }

    /** @param list<string> $eventTypes */
    public function update(
        int $id,
        string $name,
        string $endpointUrl,
        array $eventTypes,
        bool $isActive,
    ): bool {
        if ($this->find($id) === null) {
            return false;
        }
        [$name, $endpointUrl, $eventTypes] = $this->validate($name, $endpointUrl, $eventTypes);

        $stmt = $this->db->prepare(
            'UPDATE webhook_subscriptions
             SET name = :name,
                 endpoint_url = :endpoint_url,
                 event_types_json = :event_types_json,
                 is_active = :is_active,
                 active_since = CASE WHEN :is_active_since = 1 THEN CURRENT_TIMESTAMP ELSE active_since END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'endpoint_url' => $endpointUrl,
            'event_types_json' => json_encode(
                $eventTypes,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            'is_active' => $isActive ? 1 : 0,
            'is_active_since' => $isActive ? 1 : 0,
        ]);
        return true;
    }

    public function rotateSecret(int $id): ?string
    {
        if ($this->find($id) === null) {
            return null;
        }
        $secret = $this->generateSecret();
        $stmt = $this->db->prepare(
            'UPDATE webhook_subscriptions
             SET secret_ciphertext = :secret_ciphertext, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'secret_ciphertext' => $this->secretBox->encrypt($secret),
        ]);
        return $secret;
    }

    public function secret(WebhookSubscriptionRecord $subscription): string
    {
        return $this->secretBox->decrypt($subscription->secretCiphertext);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM webhook_subscriptions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param list<string> $eventTypes
     * @return array{0:string,1:string,2:list<string>}
     */
    private function validate(string $name, string $endpointUrl, array $eventTypes): array
    {
        $name = trim($name);
        $endpointUrl = trim($endpointUrl);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Webhook name must contain 1-120 characters.');
        }
        if (strlen($endpointUrl) > 2048) {
            throw new DomainException('Webhook endpoint URL is too long.');
        }
        $parts = parse_url($endpointUrl);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new DomainException('Webhook endpoint must be an HTTP or HTTPS URL without embedded credentials.');
        }

        $normalized = DomainEventCatalog::normalize($eventTypes);
        $requested = array_values(array_unique(array_map('trim', $eventTypes)));
        sort($requested);
        if ($normalized === [] || $normalized !== $requested) {
            throw new DomainException('Select one or more supported webhook event types.');
        }

        return [$name, $endpointUrl, $normalized];
    }

    private function generateSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @return list<WebhookSubscriptionRecord> */
    private function fetchAll(\PDOStatement $stmt): array
    {
        $records = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $records[] = $this->hydrate($row);
            }
        }
        return $records;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): WebhookSubscriptionRecord
    {
        $eventTypes = [];
        try {
            $decoded = json_decode((string) $row['event_types_json'], true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach ($decoded as $type) {
                    if (is_string($type)) {
                        $eventTypes[] = $type;
                    }
                }
            }
        } catch (JsonException) {
            $eventTypes = [];
        }

        return new WebhookSubscriptionRecord(
            id: (int) $row['id'],
            name: (string) $row['name'],
            endpointUrl: (string) $row['endpoint_url'],
            secretCiphertext: (string) $row['secret_ciphertext'],
            eventTypes: DomainEventCatalog::normalize($eventTypes),
            isActive: (bool) $row['is_active'],
            activeSince: (string) $row['active_since'],
            createdBy: (int) $row['created_by'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
