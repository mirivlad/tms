<?php

declare(strict_types=1);

namespace Tms\Domain\Team;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

final class TeamInvitationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function invite(
        int $teamId,
        int $invitedUserId,
        int $invitedBy,
        ?DateTimeImmutable $now = null,
    ): int {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->expirePending($now);

        if ($this->membershipExists($teamId, $invitedUserId)) {
            throw new DomainException('User is already a team member.');
        }
        if ($this->pendingInvitationExists($teamId, $invitedUserId, $now)) {
            throw new DomainException('A pending invitation already exists.');
        }

        $expiresAt = $now->add(new DateInterval('P7D'))->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO team_invitations (
                team_id, invited_user_id, invited_by, status, expires_at, created_at
             ) VALUES (
                :team_id, :invited_user_id, :invited_by, \'pending\', :expires_at, :created_at
             )'
        );
        $stmt->execute([
            'team_id' => $teamId,
            'invited_user_id' => $invitedUserId,
            'invited_by' => $invitedBy,
            'expires_at' => $expiresAt,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return list<TeamInvitationRecord> */
    public function listPendingForUser(
        int $userId,
        ?DateTimeImmutable $now = null,
    ): array {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->expirePending($now);

        $stmt = $this->db->prepare(
            $this->selectSql()
            . " WHERE i.invited_user_id = :user_id
                  AND i.status = 'pending'
                  AND i.expires_at > :now
                ORDER BY i.created_at DESC, i.id DESC"
        );
        $stmt->execute([
            'user_id' => $userId,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
        return $this->fetchAll($stmt);
    }

    public function countPendingForUser(int $userId): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->expirePending($now);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM team_invitations
             WHERE invited_user_id = :user_id
               AND status = 'pending'
               AND expires_at > :now"
        );
        $stmt->execute([
            'user_id' => $userId,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<TeamInvitationRecord> */
    public function listForTeam(int $actorUserId, int $teamId): array
    {
        $this->expirePending(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $stmt = $this->db->prepare(
            $this->selectSql()
            . ' WHERE i.team_id = :team_id
                AND EXISTS (
                    SELECT 1 FROM team_members tm
                    WHERE tm.team_id = i.team_id
                      AND tm.user_id = :actor_user_id
                      AND tm.role = \'lead\'
                )
                ORDER BY i.created_at DESC, i.id DESC'
        );
        $stmt->execute([
            'team_id' => $teamId,
            'lead_team_id' => $teamId,
            'actor_user_id' => $actorUserId,
        ]);
        return $this->fetchAll($stmt);
    }

    public function accept(
        int $invitedUserId,
        int $invitationId,
        ?DateTimeImmutable $now = null,
    ): bool {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->db->beginTransaction();
        try {
            $invitation = $this->pendingForUser($invitedUserId, $invitationId);
            if ($invitation === null) {
                $this->db->rollBack();
                return false;
            }
            if ($invitation->expiresAt <= $now->format('Y-m-d H:i:s')) {
                $this->markExpired($invitationId, $now);
                $this->db->commit();
                return false;
            }

            if (!$this->membershipExists($invitation->teamId, $invitedUserId)) {
                $stmt = $this->db->prepare(
                    'INSERT INTO team_members (team_id, user_id, role, joined_at)
                     VALUES (:team_id, :user_id, \'member\', :joined_at)'
                );
                $stmt->execute([
                    'team_id' => $invitation->teamId,
                    'user_id' => $invitedUserId,
                    'joined_at' => $now->format('Y-m-d H:i:s'),
                ]);
            }

            $update = $this->db->prepare(
                "UPDATE team_invitations
                 SET status = 'accepted', responded_at = :responded_at
                 WHERE id = :id AND invited_user_id = :user_id AND status = 'pending'"
            );
            $update->execute([
                'responded_at' => $now->format('Y-m-d H:i:s'),
                'id' => $invitationId,
                'user_id' => $invitedUserId,
            ]);

            $this->db->commit();
            return $update->rowCount() === 1;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function decline(
        int $invitedUserId,
        int $invitationId,
        ?DateTimeImmutable $now = null,
    ): bool {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->expirePending($now);

        $stmt = $this->db->prepare(
            "UPDATE team_invitations
             SET status = 'declined', responded_at = :responded_at
             WHERE id = :id
               AND invited_user_id = :user_id
               AND status = 'pending'
               AND expires_at > :now"
        );
        $timestamp = $now->format('Y-m-d H:i:s');
        $stmt->execute([
            'responded_at' => $timestamp,
            'id' => $invitationId,
            'user_id' => $invitedUserId,
            'now' => $timestamp,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function revokeForLead(
        int $actorUserId,
        int $teamId,
        int $invitationId,
        ?DateTimeImmutable $now = null,
    ): bool {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->expirePending($now);

        $stmt = $this->db->prepare(
            "UPDATE team_invitations
             SET status = 'revoked', responded_at = :responded_at
             WHERE id = :id
               AND team_id = :team_id
               AND status = 'pending'
               AND EXISTS (
                    SELECT 1 FROM team_members tm
                    WHERE tm.team_id = :lead_team_id
                      AND tm.user_id = :actor_user_id
                      AND tm.role = 'lead'
               )"
        );
        $stmt->execute([
            'responded_at' => $now->format('Y-m-d H:i:s'),
            'id' => $invitationId,
            'team_id' => $teamId,
            'actor_user_id' => $actorUserId,
        ]);
        return $stmt->rowCount() === 1;
    }

    private function pendingForUser(int $userId, int $invitationId): ?TeamInvitationRecord
    {
        $stmt = $this->db->prepare(
            $this->selectSql()
            . " WHERE i.id = :id
                  AND i.invited_user_id = :user_id
                  AND i.status = 'pending'
                LIMIT 1"
        );
        $stmt->execute(['id' => $invitationId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    private function pendingInvitationExists(
        int $teamId,
        int $userId,
        DateTimeImmutable $now,
    ): bool {
        $stmt = $this->db->prepare(
            "SELECT 1
             FROM team_invitations
             WHERE team_id = :team_id
               AND invited_user_id = :user_id
               AND status = 'pending'
               AND expires_at > :now
             LIMIT 1"
        );
        $stmt->execute([
            'team_id' => $teamId,
            'user_id' => $userId,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
        return $stmt->fetchColumn() !== false;
    }

    private function membershipExists(int $teamId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM team_members WHERE team_id = :team_id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['team_id' => $teamId, 'user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    private function expirePending(DateTimeImmutable $now): void
    {
        $stmt = $this->db->prepare(
            "UPDATE team_invitations
             SET status = 'expired', responded_at = :responded_at
             WHERE status = 'pending' AND expires_at <= :now"
        );
        $timestamp = $now->format('Y-m-d H:i:s');
        $stmt->execute(['responded_at' => $timestamp, 'now' => $timestamp]);
    }

    private function markExpired(int $invitationId, DateTimeImmutable $now): void
    {
        $stmt = $this->db->prepare(
            "UPDATE team_invitations
             SET status = 'expired', responded_at = :responded_at
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([
            'responded_at' => $now->format('Y-m-d H:i:s'),
            'id' => $invitationId,
        ]);
    }

    private function selectSql(): string
    {
        return "SELECT i.id, i.team_id, t.name AS team_name,
                       i.invited_user_id, invited.username AS invited_username,
                       invited.email AS invited_email,
                       i.invited_by, inviter.username AS invited_by_username,
                       i.status, i.expires_at, i.created_at, i.responded_at
                FROM team_invitations i
                INNER JOIN teams t ON t.id = i.team_id
                INNER JOIN users invited ON invited.id = i.invited_user_id
                LEFT JOIN users inviter ON inviter.id = i.invited_by";
    }

    /** @return list<TeamInvitationRecord> */
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
    private function hydrate(array $row): TeamInvitationRecord
    {
        return new TeamInvitationRecord(
            id: (int) $row['id'],
            teamId: (int) $row['team_id'],
            teamName: (string) $row['team_name'],
            invitedUserId: (int) $row['invited_user_id'],
            invitedUsername: (string) $row['invited_username'],
            invitedEmail: (string) $row['invited_email'],
            invitedBy: $row['invited_by'] !== null ? (int) $row['invited_by'] : null,
            invitedByUsername: $row['invited_by_username'] !== null ? (string) $row['invited_by_username'] : null,
            status: (string) $row['status'],
            expiresAt: (string) $row['expires_at'],
            createdAt: (string) $row['created_at'],
            respondedAt: $row['responded_at'] !== null ? (string) $row['responded_at'] : null,
        );
    }
}
