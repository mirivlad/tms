<?php

declare(strict_types=1);

namespace Tms\Domain\Recurrence;

use DomainException;
use PDO;

final class TaskRecurrenceRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findForTask(int $ownerUserId, int $taskId): ?TaskRecurrenceRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, owner_user_id, current_task_id, mode, interval_value, spawn_status_id,
                    timezone, anchor_day, next_deadline, next_run_at, sequence, is_active,
                    last_generated_at, last_error
             FROM task_recurrences
             WHERE owner_user_id = :owner_user_id AND current_task_id = :task_id
             LIMIT 1'
        );
        $stmt->execute(['owner_user_id' => $ownerUserId, 'task_id' => $taskId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function saveForTask(
        int $ownerUserId,
        int $taskId,
        string $mode,
        int $intervalValue,
        int $spawnStatusId,
        string $timezone,
        ?int $anchorDay,
        ?string $nextDeadline,
        ?string $nextRunAt,
    ): int {
        $authored = $this->db->prepare(
            'SELECT 1 FROM tasks WHERE id = :task_id AND created_by = :owner_user_id LIMIT 1'
        );
        $authored->execute(['task_id' => $taskId, 'owner_user_id' => $ownerUserId]);
        if ($authored->fetchColumn() === false) {
            throw new DomainException('Only the task author can manage recurrence.');
        }

        $existing = $this->findForTask($ownerUserId, $taskId);
        if ($existing !== null) {
            $stmt = $this->db->prepare(
                'UPDATE task_recurrences
                 SET mode = :mode,
                     interval_value = :interval_value,
                     spawn_status_id = :spawn_status_id,
                     timezone = :timezone,
                     anchor_day = :anchor_day,
                     next_deadline = :next_deadline,
                     next_run_at = :next_run_at,
                     is_active = 1,
                     last_error = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND owner_user_id = :owner_user_id'
            );
            $stmt->execute([
                'mode' => $mode,
                'interval_value' => $intervalValue,
                'spawn_status_id' => $spawnStatusId,
                'timezone' => $timezone,
                'anchor_day' => $anchorDay,
                'next_deadline' => $nextDeadline,
                'next_run_at' => $nextRunAt,
                'id' => $existing->id,
                'owner_user_id' => $ownerUserId,
            ]);
            return $existing->id;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO task_recurrences (
                owner_user_id, current_task_id, mode, interval_value, spawn_status_id,
                timezone, anchor_day, next_deadline, next_run_at, sequence, is_active,
                created_at, updated_at
             ) VALUES (
                :owner_user_id, :current_task_id, :mode, :interval_value, :spawn_status_id,
                :timezone, :anchor_day, :next_deadline, :next_run_at, 0, 1,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'owner_user_id' => $ownerUserId,
            'current_task_id' => $taskId,
            'mode' => $mode,
            'interval_value' => $intervalValue,
            'spawn_status_id' => $spawnStatusId,
            'timezone' => $timezone,
            'anchor_day' => $anchorDay,
            'next_deadline' => $nextDeadline,
            'next_run_at' => $nextRunAt,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return list<int> */
    public function candidateIds(string $nowUtc, int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->db->prepare(
            'SELECT r.id
             FROM task_recurrences r
             INNER JOIN tasks t ON t.id = r.current_task_id
             LEFT JOIN statuses s ON s.id = t.status_id
             WHERE r.is_active = 1
               AND (
                    (r.mode = \'after_completion\' AND s.is_completion = 1)
                    OR
                    (r.mode <> \'after_completion\'
                     AND r.next_run_at IS NOT NULL
                     AND r.next_run_at <= :now_utc)
               )
             ORDER BY COALESCE(r.next_run_at, \'1970-01-01 00:00:00\') ASC, r.id ASC
             LIMIT ' . $limit
        );
        $stmt->execute(['now_utc' => $nowUtc]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function lockById(int $recurrenceId): ?TaskRecurrenceRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, owner_user_id, current_task_id, mode, interval_value, spawn_status_id,
                    timezone, anchor_day, next_deadline, next_run_at, sequence, is_active,
                    last_generated_at, last_error
             FROM task_recurrences
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['id' => $recurrenceId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function recordOccurrence(
        int $recurrenceId,
        int $sequence,
        int $taskId,
        ?string $scheduledDeadline,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO task_recurrence_occurrences (
                recurrence_id, sequence, task_id, scheduled_deadline, generated_at
             ) VALUES (
                :recurrence_id, :sequence, :task_id, :scheduled_deadline, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'recurrence_id' => $recurrenceId,
            'sequence' => $sequence,
            'task_id' => $taskId,
            'scheduled_deadline' => $scheduledDeadline,
        ]);
    }

    public function advance(
        int $recurrenceId,
        int $currentTaskId,
        int $sequence,
        int $spawnStatusId,
        ?string $nextDeadline,
        ?string $nextRunAt,
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE task_recurrences
             SET current_task_id = :current_task_id,
                 sequence = :sequence,
                 spawn_status_id = :spawn_status_id,
                 next_deadline = :next_deadline,
                 next_run_at = :next_run_at,
                 last_generated_at = CURRENT_TIMESTAMP,
                 last_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'current_task_id' => $currentTaskId,
            'sequence' => $sequence,
            'spawn_status_id' => $spawnStatusId,
            'next_deadline' => $nextDeadline,
            'next_run_at' => $nextRunAt,
            'id' => $recurrenceId,
        ]);
    }

    public function pause(int $recurrenceId, string $error): void
    {
        $stmt = $this->db->prepare(
            'UPDATE task_recurrences
             SET is_active = 0, last_error = :last_error, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'last_error' => mb_substr(trim($error), 0, 512),
            'id' => $recurrenceId,
        ]);
    }

    public function deleteForTask(int $ownerUserId, int $taskId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM task_recurrences
             WHERE owner_user_id = :owner_user_id AND current_task_id = :task_id'
        );
        $stmt->execute(['owner_user_id' => $ownerUserId, 'task_id' => $taskId]);
        return $stmt->rowCount() === 1;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): TaskRecurrenceRecord
    {
        return new TaskRecurrenceRecord(
            id: (int) $row['id'],
            ownerUserId: (int) $row['owner_user_id'],
            currentTaskId: (int) $row['current_task_id'],
            mode: (string) $row['mode'],
            intervalValue: (int) $row['interval_value'],
            spawnStatusId: $row['spawn_status_id'] !== null ? (int) $row['spawn_status_id'] : null,
            timezone: (string) $row['timezone'],
            anchorDay: $row['anchor_day'] !== null ? (int) $row['anchor_day'] : null,
            nextDeadline: $row['next_deadline'] !== null ? (string) $row['next_deadline'] : null,
            nextRunAt: $row['next_run_at'] !== null ? (string) $row['next_run_at'] : null,
            sequence: (int) $row['sequence'],
            isActive: (bool) $row['is_active'],
            lastGeneratedAt: $row['last_generated_at'] !== null ? (string) $row['last_generated_at'] : null,
            lastError: $row['last_error'] !== null ? (string) $row['last_error'] : null,
        );
    }
}
