<?php

declare(strict_types=1);

namespace Tms\Domain\Project;

use DomainException;
use PDO;
use Throwable;

final class ProjectRepository
{
    /** @var list<string> */
    public const LIFECYCLE_STATUSES = ['active', 'paused', 'done', 'archived'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<ProjectRecord> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT p.id, p.owner_user_id, p.owner_team_id, p.created_by, p.name, p.description,
                    p.lifecycle_status, p.created_at, p.updated_at
             FROM projects p
             LEFT JOIN team_members tm
               ON tm.team_id = p.owner_team_id
              AND tm.user_id = :team_user_id
             WHERE (p.owner_user_id = :personal_user_id AND p.owner_team_id IS NULL)
                OR (p.owner_user_id IS NULL AND p.owner_team_id IS NOT NULL AND tm.user_id IS NOT NULL)
             ORDER BY
                CASE p.lifecycle_status
                    WHEN \'active\' THEN 0
                    WHEN \'paused\' THEN 1
                    WHEN \'done\' THEN 2
                    ELSE 3
                END,
                p.updated_at DESC,
                p.id DESC'
        );
        $stmt->execute([
            'team_user_id' => $userId,
            'personal_user_id' => $userId,
        ]);

        $projects = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $projects[] = $this->hydrate($row);
            }
        }
        return $projects;
    }

    public function findForUser(int $userId, int $projectId): ?ProjectRecord
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.owner_user_id, p.owner_team_id, p.created_by, p.name, p.description,
                    p.lifecycle_status, p.created_at, p.updated_at
             FROM projects p
             LEFT JOIN team_members tm
               ON tm.team_id = p.owner_team_id
              AND tm.user_id = :team_user_id
             WHERE p.id = :id
               AND (
                    (p.owner_user_id = :personal_user_id AND p.owner_team_id IS NULL)
                    OR
                    (p.owner_user_id IS NULL AND p.owner_team_id IS NOT NULL AND tm.user_id IS NOT NULL)
               )
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $projectId,
            'team_user_id' => $userId,
            'personal_user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findManageableForUser(int $userId, int $projectId): ?ProjectRecord
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.owner_user_id, p.owner_team_id, p.created_by, p.name, p.description,
                    p.lifecycle_status, p.created_at, p.updated_at
             FROM projects p
             LEFT JOIN team_members tm
               ON tm.team_id = p.owner_team_id
              AND tm.user_id = :team_user_id
             WHERE p.id = :id
               AND (
                    (p.owner_user_id = :personal_user_id AND p.owner_team_id IS NULL)
                    OR
                    (p.owner_user_id IS NULL AND p.owner_team_id IS NOT NULL AND tm.role = \'lead\')
               )
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $projectId,
            'team_user_id' => $userId,
            'personal_user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function canManageForUser(int $userId, int $projectId): bool
    {
        return $this->findManageableForUser($userId, $projectId) !== null;
    }

    /** @return list<ProjectRecord> */
    public function listForTeamForUser(int $userId, int $teamId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.owner_user_id, p.owner_team_id, p.created_by, p.name, p.description,
                    p.lifecycle_status, p.created_at, p.updated_at
             FROM projects p
             INNER JOIN team_members tm ON tm.team_id = p.owner_team_id
             WHERE p.owner_team_id = :team_id
               AND p.owner_user_id IS NULL
               AND tm.user_id = :user_id
             ORDER BY p.updated_at DESC, p.id DESC'
        );
        $stmt->execute(['team_id' => $teamId, 'user_id' => $userId]);

        $projects = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $projects[] = $this->hydrate($row);
            }
        }
        return $projects;
    }

    public function createForUser(
        int $userId,
        string $name,
        string $description,
        string $lifecycleStatus = 'active',
    ): int {
        $name = $this->normalizeName($name);
        $description = $this->normalizeDescription($description);
        $lifecycleStatus = $this->normalizeLifecycleStatus($lifecycleStatus);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO projects (
                    owner_user_id, owner_team_id, created_by, name, description,
                    lifecycle_status, created_at, updated_at
                 ) VALUES (
                    :user_id, NULL, :created_by, :name, :description,
                    :lifecycle_status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 )'
            );
            $stmt->execute([
                'user_id' => $userId,
                'created_by' => $userId,
                'name' => $name,
                'description' => $description,
                'lifecycle_status' => $lifecycleStatus,
            ]);
            $projectId = (int) $this->db->lastInsertId();

            $clone = $this->db->prepare(
                'INSERT INTO statuses (
                    user_id, project_id, source_status_id, name, description, color, sort_order,
                    is_default, is_completion, show_on_board, created_at, updated_at
                 )
                 SELECT
                    NULL, :project_id, id, name, description, color, sort_order,
                    is_default, is_completion, show_on_board, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 FROM statuses
                 WHERE user_id = :user_id AND project_id IS NULL
                 ORDER BY sort_order ASC, id ASC'
            );
            $clone->execute(['project_id' => $projectId, 'user_id' => $userId]);

            $cloneFields = $this->db->prepare(
                'INSERT INTO custom_fields (
                    user_id, project_id, source_field_id, name, field_type, options_json,
                    is_required, sort_order, created_at, updated_at
                 )
                 SELECT
                    NULL, :project_id, id, name, field_type, options_json,
                    is_required, sort_order, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 FROM custom_fields
                 WHERE user_id = :user_id AND project_id IS NULL
                 ORDER BY sort_order ASC, id ASC'
            );
            $cloneFields->execute(['project_id' => $projectId, 'user_id' => $userId]);

            $this->db->commit();
            return $projectId;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function createForTeam(
        int $userId,
        int $teamId,
        string $name,
        string $description,
        string $lifecycleStatus = 'active',
    ): int {
        $lead = $this->db->prepare(
            "SELECT 1 FROM team_members
             WHERE team_id = :team_id AND user_id = :user_id AND role = 'lead'
             LIMIT 1"
        );
        $lead->execute(['team_id' => $teamId, 'user_id' => $userId]);
        if ($lead->fetchColumn() === false) {
            throw new DomainException('Only a Team Lead can create a team project.');
        }

        $name = $this->normalizeName($name);
        $description = $this->normalizeDescription($description);
        $lifecycleStatus = $this->normalizeLifecycleStatus($lifecycleStatus);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO projects (
                    owner_user_id, owner_team_id, created_by, name, description,
                    lifecycle_status, created_at, updated_at
                 ) VALUES (
                    NULL, :team_id, :created_by, :name, :description,
                    :lifecycle_status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 )'
            );
            $stmt->execute([
                'team_id' => $teamId,
                'created_by' => $userId,
                'name' => $name,
                'description' => $description,
                'lifecycle_status' => $lifecycleStatus,
            ]);
            $projectId = (int) $this->db->lastInsertId();

            $cloneStatuses = $this->db->prepare(
                'INSERT INTO statuses (
                    user_id, project_id, source_status_id, name, description, color, sort_order,
                    is_default, is_completion, show_on_board, created_at, updated_at
                 )
                 SELECT
                    NULL, :project_id, id, name, description, color, sort_order,
                    is_default, is_completion, show_on_board, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 FROM statuses
                 WHERE user_id = :user_id AND project_id IS NULL
                 ORDER BY sort_order ASC, id ASC'
            );
            $cloneStatuses->execute(['project_id' => $projectId, 'user_id' => $userId]);

            $cloneFields = $this->db->prepare(
                'INSERT INTO custom_fields (
                    user_id, project_id, source_field_id, name, field_type, options_json,
                    is_required, sort_order, created_at, updated_at
                 )
                 SELECT
                    NULL, :project_id, id, name, field_type, options_json,
                    is_required, sort_order, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 FROM custom_fields
                 WHERE user_id = :user_id AND project_id IS NULL
                 ORDER BY sort_order ASC, id ASC'
            );
            $cloneFields->execute(['project_id' => $projectId, 'user_id' => $userId]);

            $this->db->commit();
            return $projectId;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function updateForUser(
        int $userId,
        int $projectId,
        string $name,
        string $description,
        string $lifecycleStatus,
    ): bool {
        if ($this->findManageableForUser($userId, $projectId) === null) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE projects
             SET name = :name,
                 description = :description,
                 lifecycle_status = :lifecycle_status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $this->normalizeName($name),
            'description' => $this->normalizeDescription($description),
            'lifecycle_status' => $this->normalizeLifecycleStatus($lifecycleStatus),
            'id' => $projectId,
        ]);

        return true;
    }

    public function changeOwnershipForUser(int $userId, int $projectId, ?int $teamId): bool
    {
        $project = $this->findManageableForUser($userId, $projectId);
        if ($project === null) {
            return false;
        }

        if ($teamId !== null) {
            $lead = $this->db->prepare(
                "SELECT 1 FROM team_members
                 WHERE team_id = :team_id AND user_id = :user_id AND role = 'lead'
                 LIMIT 1"
            );
            $lead->execute(['team_id' => $teamId, 'user_id' => $userId]);
            if ($lead->fetchColumn() === false) {
                throw new DomainException('Only a Team Lead can assign a project to that team.');
            }
        }

        if ($project->ownerTeamId === $teamId
            && (($teamId === null && $project->ownerUserId === $userId)
                || $teamId !== null)) {
            return true;
        }

        $this->db->beginTransaction();
        try {
            if ($teamId === null) {
                $stmt = $this->db->prepare(
                    'UPDATE projects
                     SET owner_user_id = :user_id,
                         owner_team_id = NULL,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :project_id'
                );
                $stmt->execute(['user_id' => $userId, 'project_id' => $projectId]);
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE projects
                     SET owner_user_id = NULL,
                         owner_team_id = :team_id,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :project_id'
                );
                $stmt->execute(['team_id' => $teamId, 'project_id' => $projectId]);
            }

            // Assignees belong to the old team context and cannot be carried safely.
            $clearAssignees = $this->db->prepare(
                'UPDATE tasks
                 SET assignee_user_id = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE project_id = :project_id
                   AND assignee_user_id IS NOT NULL'
            );
            $clearAssignees->execute(['project_id' => $projectId]);

            // Personal task type/customer metadata must never become visible through a team project.
            if ($teamId !== null) {
                $clearPersonalMetadata = $this->db->prepare(
                    'UPDATE tasks
                     SET type_id = NULL,
                         customer_id = NULL,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE project_id = :project_id
                       AND (type_id IS NOT NULL OR customer_id IS NOT NULL)'
                );
                $clearPersonalMetadata->execute(['project_id' => $projectId]);
            }

            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function deleteForUser(int $userId, int $projectId): bool
    {
        $project = $this->findManageableForUser($userId, $projectId);
        if ($project === null) {
            return false;
        }

        if ($project->isTeamOwned()) {
            $used = $this->db->prepare(
                'SELECT 1 FROM tasks WHERE project_id = :project_id LIMIT 1'
            );
            $used->execute(['project_id' => $projectId]);
            if ($used->fetchColumn() !== false) {
                throw new DomainException('A team project with tasks cannot be deleted.');
            }

            $stmt = $this->db->prepare('DELETE FROM projects WHERE id = :id AND owner_team_id = :team_id');
            $stmt->execute(['id' => $projectId, 'team_id' => $project->ownerTeamId]);
            return $stmt->rowCount() === 1;
        }

        $this->db->beginTransaction();
        try {
            $fallback = $this->personalDefaultStatusId($userId);
            if ($fallback === null) {
                throw new DomainException('A personal default status is required before deleting a project.');
            }

            $projectFields = $this->db->prepare(
                'SELECT id, source_field_id, field_type
                 FROM custom_fields
                 WHERE user_id IS NULL AND project_id = :project_id
                 ORDER BY id ASC'
            );
            $projectFields->execute(['project_id' => $projectId]);

            $sourceField = $this->db->prepare(
                'SELECT field_type, options_json
                 FROM custom_fields
                 WHERE id = :field_id AND user_id = :user_id AND project_id IS NULL
                 LIMIT 1'
            );
            $projectValues = $this->db->prepare(
                'SELECT v.task_id, v.user_id, v.value
                 FROM task_custom_field_values v
                 INNER JOIN tasks t
                    ON t.id = v.task_id
                   AND t.created_by = v.user_id
                 WHERE v.field_id = :field_id
                   AND t.project_id = :project_id
                   AND t.created_by = :user_id'
            );
            $deletePersonalValue = $this->db->prepare(
                'DELETE FROM task_custom_field_values
                 WHERE task_id = :task_id AND field_id = :field_id AND user_id = :user_id'
            );
            $insertPersonalValue = $this->db->prepare(
                'INSERT INTO task_custom_field_values (
                    task_id, field_id, user_id, value, created_at, updated_at
                 ) VALUES (
                    :task_id, :field_id, :user_id, :value, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 )'
            );

            while (($field = $projectFields->fetch()) !== false) {
                if (!is_array($field) || $field['source_field_id'] === null) {
                    continue;
                }

                $sourceId = (int) $field['source_field_id'];
                $sourceField->execute(['field_id' => $sourceId, 'user_id' => $userId]);
                $source = $sourceField->fetch();
                if (!is_array($source) || (string) $source['field_type'] !== (string) $field['field_type']) {
                    continue;
                }

                $projectValues->execute([
                    'field_id' => (int) $field['id'],
                    'project_id' => $projectId,
                    'user_id' => $userId,
                ]);
                while (($value = $projectValues->fetch()) !== false) {
                    if (!is_array($value)) {
                        continue;
                    }
                    $storedValue = (string) $value['value'];
                    if (!$this->customValueCompatibleWithSourceField(
                        (string) $source['field_type'],
                        is_string($source['options_json'] ?? null) ? (string) $source['options_json'] : null,
                        $storedValue,
                    )) {
                        continue;
                    }

                    $params = [
                        'task_id' => (int) $value['task_id'],
                        'field_id' => $sourceId,
                        'user_id' => (int) $value['user_id'],
                    ];
                    $deletePersonalValue->execute($params);
                    $insertPersonalValue->execute($params + ['value' => $storedValue]);
                }
            }

            $statusRows = $this->db->prepare(
                'SELECT id, source_status_id
                 FROM statuses
                 WHERE user_id IS NULL AND project_id = :project_id
                 ORDER BY id ASC'
            );
            $statusRows->execute(['project_id' => $projectId]);

            $sourceOwned = $this->db->prepare(
                'SELECT 1 FROM statuses
                 WHERE id = :status_id AND user_id = :user_id AND project_id IS NULL
                 LIMIT 1'
            );
            $remapOne = $this->db->prepare(
                'UPDATE tasks
                 SET status_id = :target_status,
                     project_id = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE project_id = :project_id
                   AND created_by = :user_id
                   AND status_id = :project_status'
            );

            while (($row = $statusRows->fetch()) !== false) {
                if (!is_array($row)) {
                    continue;
                }
                $target = $fallback;
                if ($row['source_status_id'] !== null) {
                    $sourceOwned->execute([
                        'status_id' => (int) $row['source_status_id'],
                        'user_id' => $userId,
                    ]);
                    if ($sourceOwned->fetchColumn() !== false) {
                        $target = (int) $row['source_status_id'];
                    }
                }

                $remapOne->execute([
                    'target_status' => $target,
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'project_status' => (int) $row['id'],
                ]);
            }

            $remapRemaining = $this->db->prepare(
                'UPDATE tasks
                 SET status_id = :fallback_status,
                     project_id = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE project_id = :project_id AND created_by = :user_id'
            );
            $remapRemaining->execute([
                'fallback_status' => $fallback,
                'project_id' => $projectId,
                'user_id' => $userId,
            ]);

            $stmt = $this->db->prepare(
                'DELETE FROM projects
                 WHERE id = :id AND owner_user_id = :user_id AND owner_team_id IS NULL'
            );
            $stmt->execute([
                'id' => $projectId,
                'user_id' => $userId,
            ]);
            $deleted = $stmt->rowCount() === 1;
            if (!$deleted) {
                $this->db->rollBack();
                return false;
            }

            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function customValueCompatibleWithSourceField(
        string $type,
        ?string $optionsJson,
        string $value,
    ): bool {
        if ($type === 'checkbox') {
            return in_array($value, ['0', '1'], true);
        }
        if ($type === 'select') {
            $options = $this->decodeFieldOptions($optionsJson);
            return in_array($value, $options, true);
        }
        if ($type === 'checkbox_list') {
            $options = $this->decodeFieldOptions($optionsJson);
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                return false;
            }
            foreach ($decoded as $selected) {
                if (!is_string($selected) || !in_array($selected, $options, true)) {
                    return false;
                }
            }
            return true;
        }
        return in_array($type, ['text', 'textarea', 'money'], true);
    }

    /** @return list<string> */
    private function decodeFieldOptions(?string $optionsJson): array
    {
        if ($optionsJson === null || $optionsJson === '') {
            return [];
        }
        $decoded = json_decode($optionsJson, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, 'is_string'));
    }

    private function personalDefaultStatusId(int $userId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM statuses
             WHERE user_id = :user_id AND project_id IS NULL
             ORDER BY is_default DESC, sort_order ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new DomainException('Project name must contain 1-160 characters.');
        }
        return $name;
    }

    private function normalizeDescription(string $description): string
    {
        $description = trim($description);
        if (mb_strlen($description) > 20_000) {
            throw new DomainException('Project description cannot exceed 20000 characters.');
        }
        return $description;
    }

    private function normalizeLifecycleStatus(string $status): string
    {
        $status = trim($status);
        if (!in_array($status, self::LIFECYCLE_STATUSES, true)) {
            throw new DomainException('Unsupported project lifecycle status.');
        }
        return $status;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ProjectRecord
    {
        return new ProjectRecord(
            id: (int) $row['id'],
            ownerUserId: $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null,
            ownerTeamId: $row['owner_team_id'] !== null ? (int) $row['owner_team_id'] : null,
            createdBy: (int) $row['created_by'],
            name: (string) $row['name'],
            description: (string) ($row['description'] ?? ''),
            lifecycleStatus: (string) $row['lifecycle_status'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
