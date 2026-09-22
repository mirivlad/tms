<?php

declare(strict_types=1);

namespace Tms\Domain\Activity;

use JsonException;
use PDO;
use Tms\Domain\Project\ProjectRecord;
use Tms\Domain\Task\TaskRecord;

final class ActivityRepository
{
    private const PRIORITIES = [
        0 => 'low',
        1 => 'medium',
        2 => 'high',
        3 => 'urgent',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public function recordTaskCreated(int $actorUserId, TaskRecord $task): void
    {
        [$visibilityUserId, $visibilityTeamId] = $this->taskVisibility($task);
        $this->insert(
            actorUserId: $actorUserId,
            eventType: 'task.created',
            taskId: $task->id,
            projectId: $task->projectId,
            visibilityUserId: $visibilityUserId,
            visibilityTeamId: $visibilityTeamId,
            payload: ['subject_title' => $task->title, 'changes' => []],
        );
    }

    public function recordTaskChanged(int $actorUserId, TaskRecord $before, TaskRecord $after): void
    {
        $beforeVisibility = $this->taskVisibility($before);
        $afterVisibility = $this->taskVisibility($after);
        $changes = $this->diff($this->taskSnapshot($before), $this->taskSnapshot($after));
        if ($before->description !== $after->description) {
            $changes['description'] = ['old' => null, 'new' => null];
        }
        if ($changes === []) {
            return;
        }
        if ($beforeVisibility !== $afterVisibility) {
            $changes = $this->redactPreviousContext($changes);
        }

        [$visibilityUserId, $visibilityTeamId] = $afterVisibility;
        $this->insert(
            actorUserId: $actorUserId,
            eventType: 'task.updated',
            taskId: $after->id,
            projectId: $after->projectId,
            visibilityUserId: $visibilityUserId,
            visibilityTeamId: $visibilityTeamId,
            payload: ['subject_title' => $after->title, 'changes' => $changes],
        );
    }

    /**
     * @param array<string, array{old:?string,new:?string}> $changes
     */
    public function recordTaskEvent(int $actorUserId, TaskRecord $task, string $eventType, array $changes = []): void
    {
        if (!str_starts_with($eventType, 'task.')) {
            return;
        }
        [$visibilityUserId, $visibilityTeamId] = $this->taskVisibility($task);
        $this->insert(
            actorUserId: $actorUserId,
            eventType: $eventType,
            taskId: $task->id,
            projectId: $task->projectId,
            visibilityUserId: $visibilityUserId,
            visibilityTeamId: $visibilityTeamId,
            payload: ['subject_title' => $task->title, 'changes' => $changes],
        );
    }

    public function recordProjectCreated(int $actorUserId, ProjectRecord $project): void
    {
        [$visibilityUserId, $visibilityTeamId] = $this->projectVisibility($project);
        $this->insert(
            actorUserId: $actorUserId,
            eventType: 'project.created',
            taskId: null,
            projectId: $project->id,
            visibilityUserId: $visibilityUserId,
            visibilityTeamId: $visibilityTeamId,
            payload: ['subject_title' => $project->name, 'changes' => []],
        );
    }

    public function recordProjectChanged(int $actorUserId, ProjectRecord $before, ProjectRecord $after): void
    {
        $beforeVisibility = $this->projectVisibility($before);
        $afterVisibility = $this->projectVisibility($after);
        $changes = $this->diff($this->projectSnapshot($before), $this->projectSnapshot($after));
        if ($before->description !== $after->description) {
            $changes['description'] = ['old' => null, 'new' => null];
        }
        if ($changes === []) {
            return;
        }
        if ($beforeVisibility !== $afterVisibility) {
            $changes = $this->redactPreviousContext($changes);
        }

        [$visibilityUserId, $visibilityTeamId] = $afterVisibility;
        $this->insert(
            actorUserId: $actorUserId,
            eventType: 'project.updated',
            taskId: null,
            projectId: $after->id,
            visibilityUserId: $visibilityUserId,
            visibilityTeamId: $visibilityTeamId,
            payload: ['subject_title' => $after->name, 'changes' => $changes],
        );
    }

    /** @return list<ActivityRecord> */
    public function listForTask(int $userId, int $taskId, int $limit = 100): array
    {
        return $this->listVisible(
            $userId,
            'e.task_id = :subject_id',
            $taskId,
            max(1, min(500, $limit)),
        );
    }

    /** @return list<ActivityRecord> */
    public function listForProject(int $userId, int $projectId, int $limit = 200): array
    {
        return $this->listVisible(
            $userId,
            'e.project_id = :subject_id',
            $projectId,
            max(1, min(1000, $limit)),
        );
    }

    /**
     * @param array<string, ?string> $before
     * @param array<string, ?string> $after
     * @return array<string, array{old:?string,new:?string}>
     */
    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $newValue) {
            $oldValue = $before[$field] ?? null;
            if ($oldValue !== $newValue) {
                $changes[$field] = ['old' => $oldValue, 'new' => $newValue];
            }
        }
        return $changes;
    }

    /**
     * @param array<string, array{old:?string,new:?string}> $changes
     * @return array<string, array{old:?string,new:?string}>
     */
    private function redactPreviousContext(array $changes): array
    {
        foreach ($changes as $field => $change) {
            $changes[$field] = ['old' => null, 'new' => $change['new']];
        }
        return $changes;
    }

    /** @return array<string, ?string> */
    private function taskSnapshot(TaskRecord $task): array
    {
        return [
            'title' => $task->title,
            'status' => $this->label('statuses', $task->statusId),
            'type' => $this->label('task_types', $task->typeId),
            'priority' => self::PRIORITIES[$task->priority] ?? (string) $task->priority,
            'customer' => $this->label('customers', $task->customerId),
            'project' => $this->label('projects', $task->projectId),
            'assignee' => $this->label('users', $task->assigneeUserId, 'username'),
            'deadline' => $task->deadline,
        ];
    }

    /** @return array<string, ?string> */
    private function projectSnapshot(ProjectRecord $project): array
    {
        return [
            'name' => $project->name,
            'lifecycle_status' => $project->lifecycleStatus,
            'owner' => $this->projectOwnerLabel($project),
        ];
    }

    private function projectOwnerLabel(ProjectRecord $project): ?string
    {
        if ($project->ownerTeamId !== null) {
            return $this->label('teams', $project->ownerTeamId);
        }
        if ($project->ownerUserId !== null) {
            return $this->label('users', $project->ownerUserId, 'username');
        }
        return null;
    }

    private function label(string $table, ?int $id, string $column = 'name'): ?string
    {
        if ($id === null) {
            return null;
        }
        if (!in_array($table, ['statuses', 'task_types', 'customers', 'projects', 'users', 'teams'], true)
            || !in_array($column, ['name', 'username'], true)) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT ' . $column . ' FROM ' . $table . ' WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    /** @return array{0:?int,1:?int} */
    private function taskVisibility(TaskRecord $task): array
    {
        if ($task->projectId === null) {
            return [$task->ownerId, null];
        }

        $stmt = $this->db->prepare(
            'SELECT owner_user_id, owner_team_id FROM projects WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $task->projectId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return [$task->ownerId, null];
        }
        return [
            $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null,
            $row['owner_team_id'] !== null ? (int) $row['owner_team_id'] : null,
        ];
    }

    /** @return array{0:?int,1:?int} */
    private function projectVisibility(ProjectRecord $project): array
    {
        return [$project->ownerUserId, $project->ownerTeamId];
    }

    /**
     * @param array{subject_title:string,changes:array<string,array{old:?string,new:?string}>} $payload
     */
    private function insert(
        int $actorUserId,
        string $eventType,
        ?int $taskId,
        ?int $projectId,
        ?int $visibilityUserId,
        ?int $visibilityTeamId,
        array $payload,
    ): void {
        if (($visibilityUserId === null) === ($visibilityTeamId === null)) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO activity_events (
                actor_user_id, actor_username, event_type, task_id, project_id,
                visibility_user_id, visibility_team_id, payload_json, created_at
             ) VALUES (
                :actor_user_id, :actor_username, :event_type, :task_id, :project_id,
                :visibility_user_id, :visibility_team_id, :payload_json, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'actor_user_id' => $actorUserId,
            'actor_username' => $this->label('users', $actorUserId, 'username') ?? ('user#' . $actorUserId),
            'event_type' => $eventType,
            'task_id' => $taskId,
            'project_id' => $projectId,
            'visibility_user_id' => $visibilityUserId,
            'visibility_team_id' => $visibilityTeamId,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return list<ActivityRecord> */
    private function listVisible(int $userId, string $subjectCondition, int $subjectId, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.id, e.actor_user_id, e.actor_username, e.event_type, e.task_id, e.project_id,
                    e.payload_json, e.created_at
             FROM activity_events e
             LEFT JOIN team_members tm
               ON tm.team_id = e.visibility_team_id
              AND tm.user_id = :team_user_id
             WHERE ' . $subjectCondition . '
               AND (
                    (e.visibility_user_id = :personal_user_id AND e.visibility_team_id IS NULL)
                    OR
                    (e.visibility_user_id IS NULL AND e.visibility_team_id IS NOT NULL AND tm.user_id IS NOT NULL)
               )
             ORDER BY e.created_at DESC, e.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([
            'subject_id' => $subjectId,
            'team_user_id' => $userId,
            'personal_user_id' => $userId,
        ]);

        $events = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $events[] = $this->hydrate($row);
            }
        }
        return $events;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ActivityRecord
    {
        $payload = [];
        try {
            $decoded = json_decode((string) $row['payload_json'], true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        } catch (JsonException) {
            $payload = [];
        }

        $changes = [];
        $rawChanges = $payload['changes'] ?? [];
        if (is_array($rawChanges)) {
            foreach ($rawChanges as $field => $change) {
                if (!is_string($field) || !is_array($change)) {
                    continue;
                }
                $old = array_key_exists('old', $change) && $change['old'] !== null ? (string) $change['old'] : null;
                $new = array_key_exists('new', $change) && $change['new'] !== null ? (string) $change['new'] : null;
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return new ActivityRecord(
            id: (int) $row['id'],
            actorUserId: $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
            actorUsername: (string) $row['actor_username'],
            eventType: (string) $row['event_type'],
            taskId: $row['task_id'] !== null ? (int) $row['task_id'] : null,
            projectId: $row['project_id'] !== null ? (int) $row['project_id'] : null,
            subjectTitle: is_string($payload['subject_title'] ?? null) ? $payload['subject_title'] : null,
            changes: $changes,
            createdAt: (string) $row['created_at'],
        );
    }
}
