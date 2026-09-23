<?php

declare(strict_types=1);

namespace Tms\Domain\Webhook;

use PDO;
use Tms\Domain\Event\DomainEvent;

final class WebhookDeliveryRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function enqueue(int $subscriptionId, string $eventId): bool
    {
        $check = $this->db->prepare(
            'SELECT 1 FROM webhook_deliveries
             WHERE subscription_id = :subscription_id AND event_id = :event_id
             LIMIT 1'
        );
        $check->execute(['subscription_id' => $subscriptionId, 'event_id' => $eventId]);
        if ($check->fetchColumn() !== false) {
            return false;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO webhook_deliveries (
                subscription_id, event_id, status, attempt_count, next_attempt_at,
                created_at, updated_at
             ) VALUES (
                :subscription_id, :event_id, 'pending', 0, CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute(['subscription_id' => $subscriptionId, 'event_id' => $eventId]);
        return true;
    }

    /** @return list<WebhookDeliveryRecord> */
    public function listDue(string $now, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare(
            'SELECT id, subscription_id, event_id, status, attempt_count,
                    next_attempt_at, last_attempt_at, response_status, last_error,
                    delivered_at, created_at, updated_at
             FROM webhook_deliveries
             WHERE status IN ('pending', 'retry')
               AND next_attempt_at IS NOT NULL
               AND next_attempt_at <= :now_at
             ORDER BY next_attempt_at ASC, id ASC
             LIMIT ' . $limit
        );
        $stmt->execute(['now_at' => $now]);
        return $this->fetchAll($stmt);
    }

    public function markSucceeded(int $id, int $attemptCount, int $responseStatus, string $now): void
    {
        $stmt = $this->db->prepare(
            'UPDATE webhook_deliveries
             SET status = 'succeeded',
                 attempt_count = :attempt_count,
                 next_attempt_at = NULL,
                 last_attempt_at = :last_attempt_at,
                 response_status = :response_status,
                 last_error = NULL,
                 delivered_at = :delivered_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'attempt_count' => $attemptCount,
            'last_attempt_at' => $now,
            'response_status' => $responseStatus,
            'delivered_at' => $now,
        ]);
    }

    public function scheduleRetry(
        int $id,
        int $attemptCount,
        string $nextAttemptAt,
        ?int $responseStatus,
        string $error,
        string $now,
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE webhook_deliveries
             SET status = 'retry',
                 attempt_count = :attempt_count,
                 next_attempt_at = :next_attempt_at,
                 last_attempt_at = :last_attempt_at,
                 response_status = :response_status,
                 last_error = :last_error,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'attempt_count' => $attemptCount,
            'next_attempt_at' => $nextAttemptAt,
            'last_attempt_at' => $now,
            'response_status' => $responseStatus,
            'last_error' => mb_substr($error, 0, 512),
        ]);
    }

    public function markFailed(
        int $id,
        int $attemptCount,
        ?int $responseStatus,
        string $error,
        string $now,
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE webhook_deliveries
             SET status = 'failed',
                 attempt_count = :attempt_count,
                 next_attempt_at = NULL,
                 last_attempt_at = :last_attempt_at,
                 response_status = :response_status,
                 last_error = :last_error,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'attempt_count' => $attemptCount,
            'last_attempt_at' => $now,
            'response_status' => $responseStatus,
            'last_error' => mb_substr($error, 0, 512),
        ]);
    }

    public function retry(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE webhook_deliveries
             SET status = 'pending',
                 attempt_count = 0,
                 next_attempt_at = CURRENT_TIMESTAMP,
                 last_attempt_at = NULL,
                 response_status = NULL,
                 last_error = NULL,
                 delivered_at = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'failed''
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Reconciles journaled events that were not enqueued synchronously.
     */
    public function recoverMissing(WebhookSubscriptionRecord $subscription, int $limit = 200): int
    {
        if (!$subscription->isActive || $subscription->eventTypes === []) {
            return 0;
        }
        $limit = max(1, min(1000, $limit));
        $params = [
            'subscription_id' => $subscription->id,
            'active_since' => $subscription->activeSince,
        ];
        $placeholders = [];
        foreach ($subscription->eventTypes as $index => $eventType) {
            $key = 'event_type_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $eventType;
        }

        $stmt = $this->db->prepare(
            'SELECT e.event_id
             FROM domain_events e
             WHERE e.occurred_at >= :active_since
               AND e.event_type IN (' . implode(', ', $placeholders) . ')
               AND NOT EXISTS (
                   SELECT 1 FROM webhook_deliveries d
                   WHERE d.subscription_id = :subscription_id
                     AND d.event_id = e.event_id
               )
             ORDER BY e.occurred_at ASC, e.event_id ASC
             LIMIT ' . $limit
        );
        $stmt->execute($params);

        $created = 0;
        while (($eventId = $stmt->fetchColumn()) !== false) {
            if ($this->enqueue($subscription->id, (string) $eventId)) {
                $created++;
            }
        }
        return $created;
    }

    /**
     * @return list<array{
     *   id:int,subscription_id:int,subscription_name:string,event_id:string,event_type:string,
     *   status:string,attempt_count:int,response_status:?int,last_error:?string,
     *   last_attempt_at:?string,delivered_at:?string,created_at:string
     * }>
     */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->db->query(
            'SELECT d.id, d.subscription_id, s.name AS subscription_name,
                    d.event_id, e.event_type, d.status, d.attempt_count,
                    d.response_status, d.last_error, d.last_attempt_at,
                    d.delivered_at, d.created_at
             FROM webhook_deliveries d
             INNER JOIN webhook_subscriptions s ON s.id = d.subscription_id
             INNER JOIN domain_events e ON e.event_id = d.event_id
             ORDER BY d.created_at DESC, d.id DESC
             LIMIT ' . $limit
        );
        if ($stmt === false) {
            return [];
        }
        $rows = [];
        while (($row = $stmt->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'subscription_id' => (int) $row['subscription_id'],
                'subscription_name' => (string) $row['subscription_name'],
                'event_id' => (string) $row['event_id'],
                'event_type' => (string) $row['event_type'],
                'status' => (string) $row['status'],
                'attempt_count' => (int) $row['attempt_count'],
                'response_status' => $row['response_status'] !== null ? (int) $row['response_status'] : null,
                'last_error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
                'last_attempt_at' => $row['last_attempt_at'] !== null ? (string) $row['last_attempt_at'] : null,
                'delivered_at' => $row['delivered_at'] !== null ? (string) $row['delivered_at'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }
        return $rows;
    }

    /** @return list<WebhookDeliveryRecord> */
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
    private function hydrate(array $row): WebhookDeliveryRecord
    {
        return new WebhookDeliveryRecord(
            id: (int) $row['id'],
            subscriptionId: (int) $row['subscription_id'],
            eventId: (string) $row['event_id'],
            status: (string) $row['status'],
            attemptCount: (int) $row['attempt_count'],
            nextAttemptAt: $row['next_attempt_at'] !== null ? (string) $row['next_attempt_at'] : null,
            lastAttemptAt: $row['last_attempt_at'] !== null ? (string) $row['last_attempt_at'] : null,
            responseStatus: $row['response_status'] !== null ? (int) $row['response_status'] : null,
            lastError: $row['last_error'] !== null ? (string) $row['last_error'] : null,
            deliveredAt: $row['delivered_at'] !== null ? (string) $row['delivered_at'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
