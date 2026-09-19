<?php

declare(strict_types=1);

namespace Tms\Domain\Project;

use PDO;

final class ProjectAccessRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function roleForUser(int $userId, int $projectId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT p.owner_user_id, p.owner_team_id, tm.role AS team_role
             FROM projects p
             LEFT JOIN team_members tm
                ON tm.team_id = p.owner_team_id
               AND tm.user_id = :member_user_id
             WHERE p.id = :project_id
             LIMIT 1'
        );
        $stmt->execute([
            'member_user_id' => $userId,
            'project_id' => $projectId,
        ]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        if ($row['owner_user_id'] !== null
            && $row['owner_team_id'] === null
            && (int) $row['owner_user_id'] === $userId) {
            return 'owner';
        }
        if ($row['owner_user_id'] === null
            && $row['owner_team_id'] !== null
            && is_string($row['team_role'])
            && in_array($row['team_role'], ['lead', 'member'], true)) {
            return $row['team_role'];
        }
        return null;
    }

    public function canAccess(int $userId, int $projectId): bool
    {
        return $this->roleForUser($userId, $projectId) !== null;
    }

    public function canManage(int $userId, int $projectId): bool
    {
        return in_array($this->roleForUser($userId, $projectId), ['owner', 'lead'], true);
    }

    public function isTeamProject(int $projectId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT owner_team_id FROM projects WHERE id = :project_id LIMIT 1'
        );
        $stmt->execute(['project_id' => $projectId]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null;
    }

    public function teamId(int $projectId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT owner_team_id FROM projects WHERE id = :project_id LIMIT 1'
        );
        $stmt->execute(['project_id' => $projectId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }
}
