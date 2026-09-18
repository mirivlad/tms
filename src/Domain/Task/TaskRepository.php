<?php

declare(strict_types=1);

namespace Tms\Domain\Task;

use DomainException;
use PDO;

final class TaskRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findForUser(int $userId, int $taskId): ?TaskRecord
    {
        $stmt = $this->db->prepare(
            'SELECT id, created_by, title, description, deadline, status_id, type_id, priority, customer_id, project_id, created_at, updated_at
             FROM tasks
             WHERE id = :task_id AND created_by = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'task_id' => $taskId,
            'user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<TaskRecord> */
    public function listForUser(int $userId): array
    {
        return $this->listFilteredForUser($userId);
    }

    /** @return list<TaskRecord> */
    public function listFilteredForUser(
        int $userId,
        ?int $statusId = null,
        bool $statusInvert = false,
        ?int $typeId = null,
        ?int $customerId = null,
        string $customerQuery = '',
        ?int $priority = null,
        string $query = '',
        bool $overdue = false,
        string $deadlineFrom = '',
        string $deadlineTo = '',
        string $createdFrom = '',
        string $createdTo = '',
        ?int $projectId = null,
        bool $withoutProject = false,
    ): array {
        $customerQuery = trim($customerQuery);
        $sql = 'SELECT t.id, t.created_by, t.title, t.description, t.deadline, t.status_id, t.type_id,
                       t.priority, t.customer_id, t.project_id, t.created_at, t.updated_at
                FROM tasks t';
        if ($overdue) {
            $sql .= ' LEFT JOIN statuses s ON s.id = t.status_id';
        }
        if ($customerQuery !== '') {
            $sql .= ' LEFT JOIN customers c ON c.id = t.customer_id AND c.user_id = t.created_by';
        }
        $sql .= ' WHERE t.created_by = :user_id';
        $params = ['user_id' => $userId];

        if ($statusId !== null) {
            $sql .= $statusInvert
                ? ' AND (t.status_id IS NULL OR t.status_id != :status_id)'
                : ' AND t.status_id = :status_id';
            $params['status_id'] = $statusId;
        }
        if ($typeId !== null) {
            $sql .= ' AND t.type_id = :type_id';
            $params['type_id'] = $typeId;
        }
        if ($projectId !== null) {
            $sql .= ' AND t.project_id = :project_id';
            $params['project_id'] = $projectId;
        } elseif ($withoutProject) {
            $sql .= ' AND t.project_id IS NULL';
        }
        if ($customerId !== null) {
            $sql .= ' AND t.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }
        if ($customerQuery !== '') {
            $sql .= " AND c.name LIKE :customer_query ESCAPE '!'";
            $params['customer_query'] = '%' . $this->escapeLike($customerQuery) . '%';
        }
        if ($priority !== null) {
            $this->assertPriority($priority);
            $sql .= ' AND t.priority = :priority';
            $params['priority'] = $priority;
        }

        $query = trim($query);
        if ($query !== '') {
            $sql .= " AND (t.title LIKE :query_title ESCAPE '!' OR t.description LIKE :query_description ESCAPE '!')";
            $escapedQuery = '%' . $this->escapeLike($query) . '%';
            $params['query_title'] = $escapedQuery;
            $params['query_description'] = $escapedQuery;
        }

        if ($deadlineFrom !== '') {
            $sql .= ' AND DATE(t.deadline) >= :deadline_from';
            $params['deadline_from'] = $deadlineFrom;
        }
        if ($deadlineTo !== '') {
            $sql .= ' AND DATE(t.deadline) <= :deadline_to';
            $params['deadline_to'] = $deadlineTo;
        }
        if ($createdFrom !== '') {
            $sql .= ' AND DATE(t.created_at) >= :created_from';
            $params['created_from'] = $createdFrom;
        }
        if ($createdTo !== '') {
            $sql .= ' AND DATE(t.created_at) <= :created_to';
            $params['created_to'] = $createdTo;
        }

        if ($overdue) {
            $sql .= ' AND t.deadline IS NOT NULL AND t.deadline < CURRENT_TIMESTAMP
                      AND (s.is_completion = 0 OR s.id IS NULL)';
        }

        $sql .= ' ORDER BY CASE WHEN t.deadline IS NULL THEN 1 ELSE 0 END ASC,
                          t.deadline ASC, t.priority DESC, t.updated_at DESC, t.id DESC';

        return $this->fetchTasks($sql, $params);
    }

    /**
     * @param list<int> $statusIds
     * @param list<int> $typeIds
     * @param list<int> $priorities
     * @return list<TaskRecord>
     */
    public function listCalendarForUser(
        int $userId,
        string $rangeStart,
        string $rangeEnd,
        string $mode = 'deadlines_only',
        array $statusIds = [],
        array $typeIds = [],
        ?int $customerId = null,
        array $priorities = [],
        bool $statusInvert = false,
        bool $typeInvert = false,
        bool $priorityInvert = false,
        string $customerQuery = '',
        ?int $projectId = null,
        bool $withoutProject = false,
    ): array {
        if (!in_array($mode, ['deadlines_only', 'no_deadlines', 'all'], true)) {
            throw new DomainException('Unsupported calendar mode.');
        }
        $statusIds = $this->positiveIds($statusIds);
        $typeIds = $this->positiveIds($typeIds);
        $priorities = array_values(array_unique($priorities));
        foreach ($priorities as $priority) {
            $this->assertPriority($priority);
        }

        $customerQuery = trim($customerQuery);
        $sql = 'SELECT t.id, t.created_by, t.title, t.description, t.deadline, t.status_id, t.type_id,
                       t.priority, t.customer_id, t.project_id, t.created_at, t.updated_at
                FROM tasks t';
        if ($customerQuery !== '') {
            $sql .= ' LEFT JOIN customers c ON c.id = t.customer_id AND c.user_id = t.created_by';
        }
        $sql .= ' WHERE t.created_by = :user_id AND ';
        $params = ['user_id' => $userId];

        if ($mode === 'deadlines_only') {
            $sql .= '(t.deadline >= :deadline_range_start AND t.deadline < :deadline_range_end)';
            $params['deadline_range_start'] = $rangeStart;
            $params['deadline_range_end'] = $rangeEnd;
        } elseif ($mode === 'no_deadlines') {
            $sql .= '(t.deadline IS NULL AND t.created_at >= :created_range_start AND t.created_at < :created_range_end)';
            $params['created_range_start'] = $rangeStart;
            $params['created_range_end'] = $rangeEnd;
        } else {
            $sql .= '((t.deadline IS NOT NULL AND t.deadline >= :deadline_range_start AND t.deadline < :deadline_range_end)
                     OR (t.deadline IS NULL AND t.created_at >= :created_range_start AND t.created_at < :created_range_end))';
            $params['deadline_range_start'] = $rangeStart;
            $params['deadline_range_end'] = $rangeEnd;
            $params['created_range_start'] = $rangeStart;
            $params['created_range_end'] = $rangeEnd;
        }

        if ($statusIds !== []) {
            $condition = $this->calendarInCondition('t.status_id', 'status', $statusIds, $params, $statusInvert, true);
            $sql .= ' AND ' . $condition;
        }
        if ($typeIds !== []) {
            $condition = $this->calendarInCondition('t.type_id', 'type', $typeIds, $params, $typeInvert, true);
            $sql .= ' AND ' . $condition;
        }
        if ($projectId !== null) {
            $sql .= ' AND t.project_id = :project_id';
            $params['project_id'] = $projectId;
        } elseif ($withoutProject) {
            $sql .= ' AND t.project_id IS NULL';
        }
        if ($customerId !== null) {
            $sql .= ' AND t.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }
        if ($customerQuery !== '') {
            $sql .= " AND c.name LIKE :customer_query ESCAPE '!'";
            $params['customer_query'] = '%' . $this->escapeLike($customerQuery) . '%';
        }
        if ($priorities !== []) {
            $condition = $this->calendarInCondition('t.priority', 'priority', $priorities, $params, $priorityInvert, false);
            $sql .= ' AND ' . $condition;
        }

        $sql .= ' ORDER BY COALESCE(t.deadline, t.created_at) ASC, t.priority DESC, t.id ASC';
        return $this->fetchTasks($sql, $params);
    }

    /**
     * @param list<int> $values
     * @param array<string, int|string|null> $params
     */
    private function calendarInCondition(
        string $column,
        string $prefix,
        array $values,
        array &$params,
        bool $invert,
        bool $includeNullWhenInverted,
    ): string {
        $placeholders = [];
        foreach ($values as $index => $value) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }
        $operator = $invert ? 'NOT IN' : 'IN';
        $condition = $column . ' ' . $operator . ' (' . implode(', ', $placeholders) . ')';
        return $invert && $includeNullWhenInverted ? '(' . $column . ' IS NULL OR ' . $condition . ')' : $condition;
    }

    /**
     * @param list<int> $values
     * @return list<int>
     */
    private function positiveIds(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn (int $id): bool => $id > 0)));
    }

    public function createForUser(
        int $userId,
        string $title,
        string $description,
        ?string $deadline,
        int $statusId,
        ?int $typeId,
        int $priority,
        ?int $customerId,
        ?int $projectId = null,
    ): int {
        $title = $this->validateTitle($title);
        $this->assertPriority($priority);
        $this->assertOwnedProject($userId, $projectId);
        $this->assertStatusForScope($userId, $statusId, $projectId);
        $this->assertOwnedMetadata($userId, $typeId, $customerId);

        $stmt = $this->db->prepare(
            'INSERT INTO tasks (
                created_by, title, description, deadline, status_id, type_id, priority, customer_id, project_id,
                created_at, updated_at
             ) VALUES (
                :user_id, :title, :description, :deadline, :status_id, :type_id, :priority, :customer_id, :project_id,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'title' => $title,
            'description' => trim($description),
            'deadline' => $deadline,
            'status_id' => $statusId,
            'type_id' => $typeId,
            'priority' => $priority,
            'customer_id' => $customerId,
            'project_id' => $projectId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateForUser(
        int $userId,
        int $taskId,
        string $title,
        string $description,
        ?string $deadline,
        int $statusId,
        ?int $typeId,
        int $priority,
        ?int $customerId,
        ?int $projectId = null,
    ): bool {
        if ($this->findForUser($userId, $taskId) === null) {
            return false;
        }

        $title = $this->validateTitle($title);
        $this->assertPriority($priority);
        $this->assertOwnedProject($userId, $projectId);
        $this->assertStatusForScope($userId, $statusId, $projectId);
        $this->assertOwnedMetadata($userId, $typeId, $customerId);

        $stmt = $this->db->prepare(
            'UPDATE tasks
             SET title = :title,
                 description = :description,
                 deadline = :deadline,
                 status_id = :status_id,
                 type_id = :type_id,
                 priority = :priority,
                 customer_id = :customer_id,
                 project_id = :project_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :task_id AND created_by = :user_id'
        );
        $stmt->execute([
            'title' => $title,
            'description' => trim($description),
            'deadline' => $deadline,
            'status_id' => $statusId,
            'type_id' => $typeId,
            'priority' => $priority,
            'customer_id' => $customerId,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'user_id' => $userId,
        ]);
        return true;
    }

    /** @return list<TaskRecord> */
    public function listForProjectForUser(int $userId, int $projectId): array
    {
        if (!$this->projectOwned($userId, $projectId)) {
            return [];
        }

        return $this->listFilteredForUser($userId, projectId: $projectId);
    }

    public function quickUpdateForUser(
        int $userId,
        int $taskId,
        string $description,
        ?string $deadline,
        int $statusId,
    ): bool {
        $task = $this->findForUser($userId, $taskId);
        if ($task === null) {
            return false;
        }
        $this->assertStatusForScope($userId, $statusId, $task->projectId);

        $stmt = $this->db->prepare(
            'UPDATE tasks
             SET description = :description,
                 deadline = :deadline,
                 status_id = :status_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :task_id AND created_by = :user_id'
        );
        $stmt->execute([
            'description' => trim($description),
            'deadline' => $deadline,
            'status_id' => $statusId,
            'task_id' => $taskId,
            'user_id' => $userId,
        ]);
        return true;
    }

    public function updateStatusForUser(int $userId, int $taskId, int $statusId): bool
    {
        $task = $this->findForUser($userId, $taskId);
        if ($task === null) {
            return false;
        }
        $this->assertStatusForScope($userId, $statusId, $task->projectId);

        $stmt = $this->db->prepare(
            'UPDATE tasks
             SET status_id = :status_id, updated_at = CURRENT_TIMESTAMP
             WHERE id = :task_id AND created_by = :user_id'
        );
        $stmt->execute([
            'status_id' => $statusId,
            'task_id' => $taskId,
            'user_id' => $userId,
        ]);

        return true;
    }

    public function deleteForUser(int $userId, int $taskId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM tasks WHERE id = :task_id AND created_by = :user_id');
        $stmt->execute(['task_id' => $taskId, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    /** @param list<int> $taskIds */
    public function bulkUpdateStatusForUser(int $userId, array $taskIds, int $statusId): int
    {
        $taskIds = array_values(array_unique(array_filter($taskIds, static fn (int $id): bool => $id > 0)));
        if ($taskIds === []) {
            return 0;
        }

        $statusScope = $this->statusScopeForUser($userId, $statusId);
        if ($statusScope === null) {
            throw new DomainException('Selected status is unavailable.');
        }

        $params = ['user_id' => $userId];
        $placeholders = [];
        foreach ($taskIds as $index => $taskId) {
            $key = 'task_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $taskId;
        }

        $scopeSql = $statusScope === 0
            ? 'project_id IS NULL'
            : 'project_id = :project_id';
        if ($statusScope !== 0) {
            $params['project_id'] = $statusScope;
        }

        $check = $this->db->prepare(
            'SELECT COUNT(*)
             FROM tasks
             WHERE created_by = :user_id
               AND id IN (' . implode(', ', $placeholders) . ')
               AND ' . $scopeSql
        );
        $check->execute($params);
        if ((int) $check->fetchColumn() !== count($taskIds)) {
            throw new DomainException('Selected status is unavailable for one or more tasks.');
        }

        return $this->bulkUpdateForUser($userId, $taskIds, 'status_id', $statusId);
    }

    /** @param list<int> $taskIds */
    public function bulkUpdateTypeForUser(int $userId, array $taskIds, ?int $typeId): int
    {
        if ($typeId !== null && !$this->metadataOwned('task_types', $userId, $typeId)) {
            throw new DomainException('Selected task type is unavailable.');
        }
        return $this->bulkUpdateForUser($userId, $taskIds, 'type_id', $typeId);
    }

    /** @param list<int> $taskIds */
    public function bulkUpdatePriorityForUser(int $userId, array $taskIds, int $priority): int
    {
        $this->assertPriority($priority);
        return $this->bulkUpdateForUser($userId, $taskIds, 'priority', $priority);
    }

    /** @param list<int> $taskIds */
    public function bulkUpdateDeadlineForUser(int $userId, array $taskIds, ?string $deadline): int
    {
        return $this->bulkUpdateForUser($userId, $taskIds, 'deadline', $deadline);
    }

    /** @param list<int> $taskIds */
    private function bulkUpdateForUser(int $userId, array $taskIds, string $column, int|string|null $value): int
    {
        if (!in_array($column, ['status_id', 'type_id', 'priority', 'deadline'], true)) {
            throw new DomainException('Unsupported bulk task field.');
        }
        $taskIds = array_values(array_unique(array_filter($taskIds, static fn (int $id): bool => $id > 0)));
        if ($taskIds === []) {
            return 0;
        }

        $params = ['user_id' => $userId, 'value' => $value];
        $placeholders = [];
        foreach ($taskIds as $index => $taskId) {
            $key = 'task_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $taskId;
        }

        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM tasks WHERE created_by = :user_id AND id IN (' . implode(', ', $placeholders) . ')'
        );
        $countParams = $params;
        unset($countParams['value']);
        $count->execute($countParams);
        $matched = (int) $count->fetchColumn();
        if ($matched === 0) {
            return 0;
        }

        $stmt = $this->db->prepare(
            'UPDATE tasks SET ' . $column . ' = :value, updated_at = CURRENT_TIMESTAMP
             WHERE created_by = :user_id AND id IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        return $matched;
    }

    private function assertOwnedProject(int $userId, ?int $projectId): void
    {
        if ($projectId !== null && !$this->projectOwned($userId, $projectId)) {
            throw new DomainException('Selected project does not belong to the current user.');
        }
    }

    private function projectOwned(int $userId, int $projectId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
             FROM projects
             WHERE id = :id AND owner_user_id = :user_id AND owner_team_id IS NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $projectId, 'user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    private function metadataOwned(string $table, int $userId, int $id): bool
    {
        if (!in_array($table, ['task_types'], true)) {
            throw new DomainException('Unsupported metadata table.');
        }
        $stmt = $this->db->prepare('SELECT 1 FROM ' . $table . ' WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    private function assertOwnedMetadata(int $userId, ?int $typeId, ?int $customerId): void
    {
        if ($typeId !== null && !$this->ownedReferenceExists('task_types', $userId, $typeId)) {
            throw new DomainException('Selected task type does not belong to the current user.');
        }
        if ($customerId !== null && !$this->ownedReferenceExists('customers', $userId, $customerId)) {
            throw new DomainException('Selected customer does not belong to the current user.');
        }
    }

    private function assertStatusForScope(int $userId, int $statusId, ?int $projectId): void
    {
        $scope = $this->statusScopeForUser($userId, $statusId);
        $expected = $projectId ?? 0;
        if ($scope === null || $scope !== $expected) {
            throw new DomainException('Selected status is unavailable for this task scope.');
        }
    }

    /**
     * Returns 0 for a personal status, a positive project id for a project
     * status, or null when the status is not accessible to the user.
     */
    private function statusScopeForUser(int $userId, int $statusId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT s.user_id, s.project_id
             FROM statuses s
             LEFT JOIN projects p ON p.id = s.project_id
             WHERE s.id = :status_id
               AND (
                    (s.user_id = :personal_user_id AND s.project_id IS NULL)
                    OR
                    (s.user_id IS NULL
                     AND s.project_id IS NOT NULL
                     AND p.owner_user_id = :project_user_id
                     AND p.owner_team_id IS NULL)
               )
             LIMIT 1'
        );
        $stmt->execute([
            'status_id' => $statusId,
            'personal_user_id' => $userId,
            'project_user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return $row['project_id'] === null ? 0 : (int) $row['project_id'];
    }

    private function ownedReferenceExists(string $table, int $userId, int $id): bool
    {
        if (!in_array($table, ['task_types', 'customers'], true)) {
            throw new DomainException('Unsupported task metadata reference.');
        }

        $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE id = :id AND user_id = :user_id LIMIT 1");
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    private function validateTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new DomainException('Task title cannot be empty.');
        }
        if (mb_strlen($title) > 255) {
            throw new DomainException('Task title cannot exceed 255 characters.');
        }
        return $title;
    }

    private function assertPriority(int $priority): void
    {
        if ($priority < 0 || $priority > 3) {
            throw new DomainException('Task priority must be between 0 and 3.');
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<TaskRecord>
     */
    private function fetchTasks(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $tasks = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $tasks[] = $this->hydrate($row);
            }
        }
        return $tasks;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): TaskRecord
    {
        return new TaskRecord(
            id: (int) $row['id'],
            ownerId: (int) $row['created_by'],
            title: (string) $row['title'],
            description: (string) ($row['description'] ?? ''),
            deadline: $row['deadline'] !== null ? (string) $row['deadline'] : null,
            statusId: $row['status_id'] !== null ? (int) $row['status_id'] : null,
            typeId: $row['type_id'] !== null ? (int) $row['type_id'] : null,
            priority: (int) ($row['priority'] ?? 0),
            customerId: $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
            projectId: $row['project_id'] !== null ? (int) $row['project_id'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
