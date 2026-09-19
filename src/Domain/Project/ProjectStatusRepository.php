<?php

declare(strict_types=1);

namespace Tms\Domain\Project;

use DomainException;
use PDO;
use Throwable;
use Tms\Domain\Status\StatusRecord;

final class ProjectStatusRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<StatusRecord> */
    public function listForProject(int $userId, int $projectId, bool $boardOnly = false): array
    {
        if (!$this->projectAccessible($userId, $projectId)) {
            return [];
        }

        $sql = 'SELECT id, user_id, project_id, source_status_id, name, description, color, sort_order,
                       is_default, is_completion, show_on_board
                FROM statuses
                WHERE user_id IS NULL AND project_id = :project_id';
        if ($boardOnly) {
            $sql .= ' AND show_on_board = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['project_id' => $projectId]);
        return $this->fetchAll($stmt);
    }

    public function findForProject(int $userId, int $projectId, int $statusId): ?StatusRecord
    {
        if (!$this->projectAccessible($userId, $projectId)) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, user_id, project_id, source_status_id, name, description, color, sort_order,
                    is_default, is_completion, show_on_board
             FROM statuses
             WHERE id = :id AND user_id IS NULL AND project_id = :project_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $statusId, 'project_id' => $projectId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function defaultForProject(int $userId, int $projectId): ?StatusRecord
    {
        if (!$this->projectAccessible($userId, $projectId)) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, user_id, project_id, source_status_id, name, description, color, sort_order,
                    is_default, is_completion, show_on_board
             FROM statuses
             WHERE user_id IS NULL AND project_id = :project_id AND is_default = 1
             ORDER BY sort_order ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute(['project_id' => $projectId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function createForProject(
        int $userId,
        int $projectId,
        string $name,
        string $description = '',
        string $color = '#6b7280',
        bool $isDefault = false,
        bool $isCompletion = false,
        bool $showOnBoard = true,
    ): int {
        $this->assertProjectManageable($userId, $projectId);
        $name = $this->normalizeName($name);

        $this->db->beginTransaction();
        try {
            if ($isDefault) {
                $this->clearFlag($projectId, 'is_default');
            }
            if ($isCompletion) {
                $this->clearFlag($projectId, 'is_completion');
            }

            $stmt = $this->db->prepare(
                'INSERT INTO statuses (
                    user_id, project_id, source_status_id, name, description, color, sort_order,
                    is_default, is_completion, show_on_board, created_at, updated_at
                 ) VALUES (
                    NULL, :project_id, NULL, :name, :description, :color, :sort_order,
                    :is_default, :is_completion, :show_on_board, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 )'
            );
            $stmt->execute([
                'project_id' => $projectId,
                'name' => $name,
                'description' => trim($description),
                'color' => $this->normalizeColor($color),
                'sort_order' => $this->nextSortOrder($projectId),
                'is_default' => $isDefault ? 1 : 0,
                'is_completion' => $isCompletion ? 1 : 0,
                'show_on_board' => $showOnBoard ? 1 : 0,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->db->commit();
            return $id;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function updateForProject(
        int $userId,
        int $projectId,
        int $statusId,
        string $name,
        string $description,
        string $color,
        bool $showOnBoard,
    ): bool {
        if (!$this->projectManageable($userId, $projectId)
            || $this->findForProject($userId, $projectId, $statusId) === null) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE statuses
             SET name = :name,
                 description = :description,
                 color = :color,
                 show_on_board = :show_on_board,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id IS NULL AND project_id = :project_id'
        );
        $stmt->execute([
            'name' => $this->normalizeName($name),
            'description' => trim($description),
            'color' => $this->normalizeColor($color),
            'show_on_board' => $showOnBoard ? 1 : 0,
            'id' => $statusId,
            'project_id' => $projectId,
        ]);
        return true;
    }

    public function setDefaultForProject(int $userId, int $projectId, int $statusId): bool
    {
        return $this->setExclusiveFlag($userId, $projectId, $statusId, 'is_default');
    }

    public function setCompletionForProject(int $userId, int $projectId, int $statusId): bool
    {
        return $this->setExclusiveFlag($userId, $projectId, $statusId, 'is_completion');
    }

    public function deleteForProject(int $userId, int $projectId, int $statusId): bool
    {
        if (!$this->projectManageable($userId, $projectId)) {
            return false;
        }
        $status = $this->findForProject($userId, $projectId, $statusId);
        if ($status === null || $status->isDefault || $status->isCompletion) {
            return false;
        }

        $used = $this->db->prepare(
            'SELECT 1 FROM tasks WHERE project_id = :project_id AND status_id = :status_id LIMIT 1'
        );
        $used->execute(['project_id' => $projectId, 'status_id' => $statusId]);
        if ($used->fetchColumn() !== false) {
            return false;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM statuses
             WHERE id = :id AND user_id IS NULL AND project_id = :project_id'
        );
        $stmt->execute(['id' => $statusId, 'project_id' => $projectId]);
        return $stmt->rowCount() === 1;
    }

    /** @param list<int> $statusIds */
    public function reorderForProject(int $userId, int $projectId, array $statusIds): bool
    {
        if (!$this->projectManageable($userId, $projectId)) {
            return false;
        }
        $owned = array_map(
            static fn (StatusRecord $record): int => $record->id,
            $this->listForProject($userId, $projectId),
        );
        sort($owned);
        $requested = $statusIds;
        sort($requested);
        if ($owned !== $requested || count($statusIds) !== count(array_unique($statusIds))) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE statuses SET sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id IS NULL AND project_id = :project_id'
            );
            foreach ($statusIds as $index => $statusId) {
                $stmt->execute([
                    'sort_order' => $index + 1,
                    'id' => $statusId,
                    'project_id' => $projectId,
                ]);
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

    private function setExclusiveFlag(int $userId, int $projectId, int $statusId, string $column): bool
    {
        if (!in_array($column, ['is_default', 'is_completion'], true)) {
            throw new DomainException('Unsupported status role.');
        }
        if (!$this->projectManageable($userId, $projectId)
            || $this->findForProject($userId, $projectId, $statusId) === null) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $this->clearFlag($projectId, $column);
            $stmt = $this->db->prepare(
                "UPDATE statuses SET {$column} = 1, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id IS NULL AND project_id = :project_id"
            );
            $stmt->execute(['id' => $statusId, 'project_id' => $projectId]);
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function clearFlag(int $projectId, string $column): void
    {
        if (!in_array($column, ['is_default', 'is_completion'], true)) {
            throw new DomainException('Unsupported status role.');
        }
        $stmt = $this->db->prepare(
            "UPDATE statuses SET {$column} = 0 WHERE user_id IS NULL AND project_id = :project_id"
        );
        $stmt->execute(['project_id' => $projectId]);
    }

    private function nextSortOrder(int $projectId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0)
             FROM statuses
             WHERE user_id IS NULL AND project_id = :project_id'
        );
        $stmt->execute(['project_id' => $projectId]);
        return (int) $stmt->fetchColumn() + 1;
    }

    private function assertProjectManageable(int $userId, int $projectId): void
    {
        if (!$this->projectManageable($userId, $projectId)) {
            throw new DomainException('Project is unavailable.');
        }
    }

    private function projectAccessible(int $userId, int $projectId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
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
        return $stmt->fetchColumn() !== false;
    }

    private function projectManageable(int $userId, int $projectId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
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
        return $stmt->fetchColumn() !== false;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Status name cannot be empty.');
        }
        if (mb_strlen($name) > 96) {
            throw new DomainException('Status name cannot exceed 96 characters.');
        }
        return $name;
    }

    private function normalizeColor(string $color): string
    {
        $color = strtolower(trim($color));
        if (preg_match('/^#[0-9a-f]{6}$/D', $color) !== 1) {
            throw new DomainException('Status color must be a six-digit hexadecimal color.');
        }
        return $color;
    }

    /** @return list<StatusRecord> */
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
    private function hydrate(array $row): StatusRecord
    {
        return new StatusRecord(
            id: (int) $row['id'],
            userId: $row['user_id'] !== null ? (int) $row['user_id'] : null,
            projectId: $row['project_id'] !== null ? (int) $row['project_id'] : null,
            sourceStatusId: $row['source_status_id'] !== null ? (int) $row['source_status_id'] : null,
            name: (string) $row['name'],
            description: (string) ($row['description'] ?? ''),
            color: (string) $row['color'],
            sortOrder: (int) $row['sort_order'],
            isDefault: (bool) $row['is_default'],
            isCompletion: (bool) $row['is_completion'],
            showOnBoard: (bool) $row['show_on_board'],
        );
    }
}
