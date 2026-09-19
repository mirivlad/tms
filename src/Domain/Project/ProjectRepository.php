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
            'SELECT id, owner_user_id, owner_team_id, created_by, name, description,
                    lifecycle_status, created_at, updated_at
             FROM projects
             WHERE owner_user_id = :user_id AND owner_team_id IS NULL
             ORDER BY
                CASE lifecycle_status
                    WHEN \'active\' THEN 0
                    WHEN \'paused\' THEN 1
                    WHEN \'done\' THEN 2
                    ELSE 3
                END,
                updated_at DESC,
                id DESC'
        );
        $stmt->execute(['user_id' => $userId]);

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
            'SELECT id, owner_user_id, owner_team_id, created_by, name, description,
                    lifecycle_status, created_at, updated_at
             FROM projects
             WHERE id = :id AND owner_user_id = :user_id AND owner_team_id IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $projectId,
            'user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
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
        if ($this->findForUser($userId, $projectId) === null) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE projects
             SET name = :name,
                 description = :description,
                 lifecycle_status = :lifecycle_status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND owner_user_id = :user_id AND owner_team_id IS NULL'
        );
        $stmt->execute([
            'name' => $this->normalizeName($name),
            'description' => $this->normalizeDescription($description),
            'lifecycle_status' => $this->normalizeLifecycleStatus($lifecycleStatus),
            'id' => $projectId,
            'user_id' => $userId,
        ]);

        return true;
    }

    public function deleteForUser(int $userId, int $projectId): bool
    {
        if ($this->findForUser($userId, $projectId) === null) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $fallback = $this->personalDefaultStatusId($userId);
            if ($fallback === null) {
                throw new DomainException('A personal default status is required before deleting a project.');
            }

            $remap = $this->db->prepare(
                'UPDATE tasks t
                 INNER JOIN statuses ps
                    ON ps.id = t.status_id
                   AND ps.project_id = :project_id_status
                   AND ps.user_id IS NULL
                 LEFT JOIN statuses source
                    ON source.id = ps.source_status_id
                   AND source.user_id = :source_user_id
                   AND source.project_id IS NULL
                 SET t.status_id = COALESCE(source.id, :fallback_status),
                     t.project_id = NULL,
                     t.updated_at = CURRENT_TIMESTAMP
                 WHERE t.project_id = :project_id_task
                   AND t.created_by = :task_user_id'
            );
            $remap->execute([
                'project_id_status' => $projectId,
                'source_user_id' => $userId,
                'fallback_status' => $fallback,
                'project_id_task' => $projectId,
                'task_user_id' => $userId,
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
