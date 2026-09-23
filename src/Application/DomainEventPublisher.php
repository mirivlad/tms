<?php

declare(strict_types=1);

namespace Tms\Application;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tms\Domain\Event\DomainEvent;
use Tms\Domain\Project\ProjectRecord;
use Tms\Domain\Task\TaskRecord;

final class DomainEventPublisher
{
    private const PRIORITIES = [
        0 => 'low',
        1 => 'medium',
        2 => 'high',
        3 => 'urgent',
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly DomainEventBus $bus,
        private readonly string $applicationTimezone,
    ) {
    }

    public function taskCreated(int $actorUserId, TaskRecord $task): DomainEvent
    {
        [$visibilityUserId, $visibilityTeamId] = $this->taskVisibility($task);
        return $this->publish(
            actorUserId: $actorUserId,
            type: 'task.created',
            taskId: $task->id,
            projectId: $task->projectId,
            commentId: null,
            visibilityUserId: $visibilityUserId,
            visibilityTeamId: $visibilityTeamId,
            payload: [
                'subject_title' => $task->title,
                'changes' => [],
                'owner_user_id' => $task->ownerId,
                'assignee_user_id' => $task->assigneeUserId,
                'previous_assignee_user_id' => null,
            ],
        );
    }

    public function taskChanged(int $actorUserId, TaskRecord $before, TaskRecord $after): ?DomainEvent
    {
        $beforeVisibility = $this->taskVisibility($before);
        $afterVisibility = $this->taskVisibility($after);
        $changes = $this->diff($this->taskSnapshot($before), $this->taskSnapshot($after));
        if ($before->description !== $after->description) {
            $changes['description'] = ['old' => null, 'new' => null];
        }
        if ($changes === []) {
            return null;
        }
        if ($beforeVisibility !== $afterVisibility) {
            $changes = $this->redactPreviousContext($changes);
        }

        [$visibilityUserId, $visibilityTeamId] = $afterVisibility;
        return $this->publish(
            actorUserId: $actorUserId,
            type: 'task.updated',
            taskId: $after->id,
            projectId: $after->projectId,
            commentId: null,
            visibilityUserId: $visibilityUserId,
            visibilityTeamId: $visibilityTeamId,
            payload: [
                'subject_title' => $after->title,
                'changes' => $changes,
                'owner_user_id' => $after->ownerId,
                'assignee_user_id' => $after->assigneeUserId,
                'previous_assignee_user_id' => $before->assigneeUserId,
            ],
        );
    }

    /**
     * @param array<string, array{old:?string,new:?string}> $changes
     */
    public function taskEvent(int $actorUserId, TaskRecord $task, string $eventType, array $changes = []): ?DomainEvent
    {
        if (!str_starts_with($eventType, 'task.')) {
            return null;
        }
        [$visibilityUserId, $visibilityTeamId] = $this->taskVisibility($task);
        return $this->publish(
            actorUserId: $actorUserId,
            type: $eventType,
            taskId: $task->id,
            projectId: $task->projectId,
            commentId: null,
            visibilityUserId: $visibilityUserId,
            visibilityTeamId: $visibilityTeamId,
            payload: [
                'subject_title' => $task->title,
                'changes' => $changes,
                'owner_user_id' => $task->ownerId,
                'assignee_user_id' => $task->assigneeUserId,
                'previous_assignee_user_id' => $task->assigneeUserId,
            ],
        );
    }

    public function projectCreated(int $actorUserId, ProjectRecord $project): DomainEvent
    {
        return $this->publish(
            actorUserId: $actorUserId,
            type: 'project.created',
            taskId: null,
            projectId: $project->id,
            commentId: null,
            visibilityUserId: $project->ownerUserId,
            visibilityTeamId: $project->ownerTeamId,
            payload: ['subject_title' => $project->name, 'changes' => []],
        );
    }

    public function projectChanged(int $actorUserId, ProjectRecord $before, ProjectRecord $after): ?DomainEvent
    {
        $beforeVisibility = [$before->ownerUserId, $before->ownerTeamId];
        $afterVisibility = [$after->ownerUserId, $after->ownerTeamId];
        $changes = $this->diff($this->projectSnapshot($before), $this->projectSnapshot($after));
        if ($before->description !== $after->description) {
            $changes['description'] = ['old' => null, 'new' => null];
        }
        if ($changes === []) {
            return null;
        }
        if ($beforeVisibility !== $afterVisibility) {
            $changes = $this->redactPreviousContext($changes);
        }

        return $this->publish(
            actorUserId: $actorUserId,
            type: 'project.updated',
            taskId: null,
            projectId: $after->id,
            commentId: null,
            visibilityUserId: $after->ownerUserId,
            visibilityTeamId: $after->ownerTeamId,
            payload: ['subject_title' => $after->name, 'changes' => $changes],
        );
    }

    /**
     * @param array<string, array{old:?string,new:?string}> $changes
     */
    public function projectEvent(int $actorUserId, ProjectRecord $project, string $eventType, array $changes = []): ?DomainEvent
    {
        if (!str_starts_with($eventType, 'project.')) {
            return null;
        }

        return $this->publish(
            actorUserId: $actorUserId,
            type: $eventType,
            taskId: null,
            projectId: $project->id,
            commentId: null,
            visibilityUserId: $project->ownerUserId,
            visibilityTeamId: $project->ownerTeamId,
            payload: ['subject_title' => $project->name, 'changes' => $changes],
        );
    }

    /**
     * @param array{
     *   comment_id:int,
     *   author_user_id:int,
     *   author_username:string,
     *   body_html:string,
     *   parent_comment_id:?int,
     *   parent_author_user_id:?int,
     *   team_id:int,
     *   effective_project_id:int,
     *   project_id:?int,
     *   task_id:?int,
     *   context_label:string
     * } $context
     */
    public function discussionComment(int $actorUserId, string $eventType, array $context): ?DomainEvent
    {
        if (!in_array($eventType, [
            'discussion.comment.created',
            'discussion.comment.updated',
            'discussion.comment.deleted',
        ], true)) {
            return null;
        }

        return $this->publish(
            actorUserId: $actorUserId,
            type: $eventType,
            taskId: $context['task_id'],
            projectId: $context['effective_project_id'],
            commentId: $context['comment_id'],
            visibilityUserId: null,
            visibilityTeamId: $context['team_id'],
            payload: [
                'subject_title' => $context['context_label'],
                'changes' => [],
                'author_user_id' => $context['author_user_id'],
                'author_username' => $context['author_username'],
                'body_html' => $context['body_html'],
                'parent_comment_id' => $context['parent_comment_id'],
                'parent_author_user_id' => $context['parent_author_user_id'],
                'discussion_project_id' => $context['project_id'],
                'discussion_task_id' => $context['task_id'],
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(
        int $actorUserId,
        string $type,
        ?int $taskId,
        ?int $projectId,
        ?int $commentId,
        ?int $visibilityUserId,
        ?int $visibilityTeamId,
        array $payload,
    ): DomainEvent {
        if (($visibilityUserId === null) === ($visibilityTeamId === null)) {
            throw new \LogicException('Domain event visibility must resolve to exactly one scope.');
        }

        $event = new DomainEvent(
            id: $this->uuidV4(),
            type: $type,
            schemaVersion: 1,
            actorUserId: $actorUserId,
            actorUsername: $this->label('users', $actorUserId, 'username') ?? ('user#' . $actorUserId),
            taskId: $taskId,
            projectId: $projectId,
            commentId: $commentId,
            visibilityUserId: $visibilityUserId,
            visibilityTeamId: $visibilityTeamId,
            payload: $payload,
            occurredAt: (new DateTimeImmutable('now', new DateTimeZone($this->applicationTimezone)))
                ->format('Y-m-d H:i:s.u'),
        );
        $this->bus->publish($event);
        return $event;
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
            'scheduled_at' => $task->scheduledAt,
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
        $stmt = $this->db->prepare('SELECT ' . $column . ' FROM ' . $table . ' WHERE id = :id LIMIT 1');
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

        $stmt = $this->db->prepare('SELECT owner_user_id, owner_team_id FROM projects WHERE id = :id LIMIT 1');
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

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
