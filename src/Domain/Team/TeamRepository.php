<?php

declare(strict_types=1);

namespace Tms\Domain\Team;

use DomainException;
use PDO;
use Throwable;

final class TeamRepository
{
    public const ROLES = ['lead', 'member'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<TeamRecord> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.name, t.description, t.created_by, t.created_at, t.updated_at, tm.role
             FROM teams t
             INNER JOIN team_members tm ON tm.team_id = t.id
             WHERE tm.user_id = :user_id
             ORDER BY t.updated_at DESC, t.id DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        $records = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $records[] = $this->hydrateTeam($row);
            }
        }
        return $records;
    }

    public function findForMember(int $userId, int $teamId): ?TeamRecord
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.name, t.description, t.created_by, t.created_at, t.updated_at, tm.role
             FROM teams t
             INNER JOIN team_members tm ON tm.team_id = t.id
             WHERE t.id = :team_id AND tm.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['team_id' => $teamId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrateTeam($row) : null;
    }

    public function findForLead(int $userId, int $teamId): ?TeamRecord
    {
        $team = $this->findForMember($userId, $teamId);
        return $team !== null && $team->currentUserIsLead() ? $team : null;
    }

    public function createForUser(int $userId, string $name, string $description): int
    {
        $name = $this->normalizeName($name);
        $description = $this->normalizeDescription($description);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO teams (name, description, created_by, created_at, updated_at)
                 VALUES (:name, :description, :created_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                'name' => $name,
                'description' => $description,
                'created_by' => $userId,
            ]);
            $teamId = (int) $this->db->lastInsertId();

            $member = $this->db->prepare(
                'INSERT INTO team_members (team_id, user_id, role, joined_at)
                 VALUES (:team_id, :user_id, :role, CURRENT_TIMESTAMP)'
            );
            $member->execute([
                'team_id' => $teamId,
                'user_id' => $userId,
                'role' => 'lead',
            ]);

            $this->db->commit();
            return $teamId;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function updateForLead(
        int $userId,
        int $teamId,
        string $name,
        string $description,
    ): bool {
        if ($this->findForLead($userId, $teamId) === null) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE teams
             SET name = :name,
                 description = :description,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :team_id'
        );
        $stmt->execute([
            'name' => $this->normalizeName($name),
            'description' => $this->normalizeDescription($description),
            'team_id' => $teamId,
        ]);
        return true;
    }

    /** @return list<TeamMemberRecord> */
    public function listMembers(int $userId, int $teamId): array
    {
        if ($this->findForMember($userId, $teamId) === null) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT tm.team_id, tm.user_id, u.username, u.email, tm.role, tm.joined_at
             FROM team_members tm
             INNER JOIN users u ON u.id = tm.user_id
             WHERE tm.team_id = :team_id
             ORDER BY CASE tm.role WHEN \'lead\' THEN 0 ELSE 1 END,
                      u.username ASC,
                      tm.user_id ASC'
        );
        $stmt->execute(['team_id' => $teamId]);

        $records = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $records[] = new TeamMemberRecord(
                    teamId: (int) $row['team_id'],
                    userId: (int) $row['user_id'],
                    username: (string) $row['username'],
                    email: (string) $row['email'],
                    role: (string) $row['role'],
                    joinedAt: (string) $row['joined_at'],
                );
            }
        }
        return $records;
    }

    public function isMember(int $userId, int $teamId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM team_members WHERE team_id = :team_id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['team_id' => $teamId, 'user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public function roleForUser(int $userId, int $teamId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT role FROM team_members WHERE team_id = :team_id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['team_id' => $teamId, 'user_id' => $userId]);
        $value = $stmt->fetchColumn();
        return is_string($value) ? $value : null;
    }

    public function addMember(int $teamId, int $userId, string $role = 'member'): bool
    {
        $role = $this->normalizeRole($role);
        if ($this->isMember($userId, $teamId)) {
            return false;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO team_members (team_id, user_id, role, joined_at)
             VALUES (:team_id, :user_id, :role, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'team_id' => $teamId,
            'user_id' => $userId,
            'role' => $role,
        ]);
        return true;
    }

    public function changeRoleForLead(
        int $actorUserId,
        int $teamId,
        int $targetUserId,
        string $role,
    ): bool {
        $role = $this->normalizeRole($role);
        if ($this->findForLead($actorUserId, $teamId) === null) {
            return false;
        }
        $current = $this->roleForUser($targetUserId, $teamId);
        if ($current === null) {
            return false;
        }
        if ($current === $role) {
            return true;
        }
        if ($current === 'lead' && $role !== 'lead' && $this->leadCount($teamId) <= 1) {
            throw new DomainException('A team must have at least one lead.');
        }

        $stmt = $this->db->prepare(
            'UPDATE team_members SET role = :role WHERE team_id = :team_id AND user_id = :user_id'
        );
        $stmt->execute([
            'role' => $role,
            'team_id' => $teamId,
            'user_id' => $targetUserId,
        ]);
        return true;
    }

    public function removeMemberForLead(int $actorUserId, int $teamId, int $targetUserId): bool
    {
        if ($this->findForLead($actorUserId, $teamId) === null) {
            return false;
        }
        $role = $this->roleForUser($targetUserId, $teamId);
        if ($role === null) {
            return false;
        }
        if ($role === 'lead' && $this->leadCount($teamId) <= 1) {
            throw new DomainException('A team must have at least one lead.');
        }

        $stmt = $this->db->prepare(
            'DELETE FROM team_members WHERE team_id = :team_id AND user_id = :user_id'
        );
        $stmt->execute(['team_id' => $teamId, 'user_id' => $targetUserId]);
        return $stmt->rowCount() === 1;
    }

    public function leave(int $userId, int $teamId): bool
    {
        $role = $this->roleForUser($userId, $teamId);
        if ($role === null) {
            return false;
        }
        if ($role === 'lead' && $this->leadCount($teamId) <= 1) {
            throw new DomainException('A team must have at least one lead.');
        }

        $stmt = $this->db->prepare(
            'DELETE FROM team_members WHERE team_id = :team_id AND user_id = :user_id'
        );
        $stmt->execute(['team_id' => $teamId, 'user_id' => $userId]);
        return $stmt->rowCount() === 1;
    }

    public function deleteForLead(int $userId, int $teamId): bool
    {
        if ($this->findForLead($userId, $teamId) === null) {
            return false;
        }
        $stmt = $this->db->prepare('DELETE FROM teams WHERE id = :team_id');
        $stmt->execute(['team_id' => $teamId]);
        return $stmt->rowCount() === 1;
    }

    private function leadCount(int $teamId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM team_members WHERE team_id = :team_id AND role = 'lead'"
        );
        $stmt->execute(['team_id' => $teamId]);
        return (int) $stmt->fetchColumn();
    }

    private function normalizeRole(string $role): string
    {
        $role = trim($role);
        if (!in_array($role, self::ROLES, true)) {
            throw new DomainException('Unsupported team role.');
        }
        return $role;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new DomainException('Team name must contain 1-160 characters.');
        }
        return $name;
    }

    private function normalizeDescription(string $description): string
    {
        $description = trim($description);
        if (mb_strlen($description) > 20_000) {
            throw new DomainException('Team description cannot exceed 20000 characters.');
        }
        return $description;
    }

    /** @param array<string, mixed> $row */
    private function hydrateTeam(array $row): TeamRecord
    {
        return new TeamRecord(
            id: (int) $row['id'],
            name: (string) $row['name'],
            description: (string) ($row['description'] ?? ''),
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            currentUserRole: (string) $row['role'],
        );
    }
}
