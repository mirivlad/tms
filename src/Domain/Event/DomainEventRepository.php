<?php

declare(strict_types=1);

namespace Tms\Domain\Event;

use JsonException;
use PDO;

final class DomainEventRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function append(DomainEvent $event): void
    {
        if (($event->visibilityUserId === null) === ($event->visibilityTeamId === null)) {
            throw new \InvalidArgumentException('A domain event must have exactly one visibility scope.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO domain_events (
                event_id, event_type, schema_version, actor_user_id, actor_username,
                task_id, project_id, comment_id, visibility_user_id, visibility_team_id,
                payload_json, occurred_at, created_at
             ) VALUES (
                :event_id, :event_type, :schema_version, :actor_user_id, :actor_username,
                :task_id, :project_id, :comment_id, :visibility_user_id, :visibility_team_id,
                :payload_json, :occurred_at, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'event_id' => $event->id,
            'event_type' => $event->type,
            'schema_version' => $event->schemaVersion,
            'actor_user_id' => $event->actorUserId,
            'actor_username' => $event->actorUsername,
            'task_id' => $event->taskId,
            'project_id' => $event->projectId,
            'comment_id' => $event->commentId,
            'visibility_user_id' => $event->visibilityUserId,
            'visibility_team_id' => $event->visibilityTeamId,
            'payload_json' => json_encode(
                $event->payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'occurred_at' => $event->occurredAt,
        ]);
    }

    public function find(string $eventId): ?DomainEvent
    {
        $stmt = $this->db->prepare(
            'SELECT event_id, event_type, schema_version, actor_user_id, actor_username,
                    task_id, project_id, comment_id, visibility_user_id, visibility_team_id,
                    payload_json, occurred_at
             FROM domain_events
             WHERE event_id = :event_id
             LIMIT 1'
        );
        $stmt->execute(['event_id' => $eventId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): DomainEvent
    {
        $payload = [];
        try {
            $decoded = json_decode((string) $row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        } catch (JsonException) {
            $payload = [];
        }

        return new DomainEvent(
            id: (string) $row['event_id'],
            type: (string) $row['event_type'],
            schemaVersion: (int) $row['schema_version'],
            actorUserId: $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
            actorUsername: (string) $row['actor_username'],
            taskId: $row['task_id'] !== null ? (int) $row['task_id'] : null,
            projectId: $row['project_id'] !== null ? (int) $row['project_id'] : null,
            commentId: $row['comment_id'] !== null ? (int) $row['comment_id'] : null,
            visibilityUserId: $row['visibility_user_id'] !== null ? (int) $row['visibility_user_id'] : null,
            visibilityTeamId: $row['visibility_team_id'] !== null ? (int) $row['visibility_team_id'] : null,
            payload: $payload,
            occurredAt: (string) $row['occurred_at'],
        );
    }
}
