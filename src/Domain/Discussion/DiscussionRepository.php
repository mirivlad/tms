<?php

declare(strict_types=1);

namespace Tms\Domain\Discussion;

use DomainException;
use PDO;

final class DiscussionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<DiscussionCommentRecord> */
    public function listForProject(int $userId, int $projectId): array
    {
        $context = $this->teamProjectContext($userId, $projectId);
        if ($context === null) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT c.id, c.project_id, c.task_id, c.parent_comment_id, c.author_user_id,
                    u.username AS author_username, c.body_html, c.created_at, c.updated_at, c.deleted_at
             FROM discussion_comments c
             LEFT JOIN users u ON u.id = c.author_user_id
             WHERE c.project_id = :project_id
               AND c.task_id IS NULL
               AND c.team_id = :team_id
             ORDER BY COALESCE(c.parent_comment_id, c.id) ASC,
                      CASE WHEN c.parent_comment_id IS NULL THEN 0 ELSE 1 END ASC,
                      c.created_at ASC, c.id ASC'
        );
        $stmt->execute(['project_id' => $projectId, 'team_id' => $context['team_id']]);
        return $this->fetchAll($stmt);
    }

    /** @return list<DiscussionCommentRecord> */
    public function listForTask(int $userId, int $taskId): array
    {
        $context = $this->teamTaskContext($userId, $taskId);
        if ($context === null) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT c.id, c.project_id, c.task_id, c.parent_comment_id, c.author_user_id,
                    u.username AS author_username, c.body_html, c.created_at, c.updated_at, c.deleted_at
             FROM discussion_comments c
             LEFT JOIN users u ON u.id = c.author_user_id
             WHERE c.task_id = :task_id
               AND c.project_id IS NULL
               AND c.team_id = :team_id
             ORDER BY COALESCE(c.parent_comment_id, c.id) ASC,
                      CASE WHEN c.parent_comment_id IS NULL THEN 0 ELSE 1 END ASC,
                      c.created_at ASC, c.id ASC'
        );
        $stmt->execute(['task_id' => $taskId, 'team_id' => $context['team_id']]);
        return $this->fetchAll($stmt);
    }

    /**
     * @return array<int, list<DiscussionCommentRecord>>
     */
    public function listTaskCommentsForProject(int $userId, int $projectId): array
    {
        $context = $this->teamProjectContext($userId, $projectId);
        if ($context === null) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT c.id, c.project_id, c.task_id, c.parent_comment_id, c.author_user_id,
                    u.username AS author_username, c.body_html, c.created_at, c.updated_at, c.deleted_at
             FROM discussion_comments c
             INNER JOIN tasks t ON t.id = c.task_id
             LEFT JOIN users u ON u.id = c.author_user_id
             WHERE t.project_id = :project_id
               AND c.project_id IS NULL
               AND c.task_id IS NOT NULL
               AND c.team_id = :team_id
             ORDER BY c.task_id ASC,
                      COALESCE(c.parent_comment_id, c.id) ASC,
                      CASE WHEN c.parent_comment_id IS NULL THEN 0 ELSE 1 END ASC,
                      c.created_at ASC, c.id ASC'
        );
        $stmt->execute(['project_id' => $projectId, 'team_id' => $context['team_id']]);

        $grouped = [];
        while (($row = $stmt->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $record = $this->hydrate($row);
            if ($record->taskId === null) {
                continue;
            }
            $grouped[$record->taskId][] = $record;
        }
        return $grouped;
    }

    public function createForProject(
        int $userId,
        int $projectId,
        string $bodyHtml,
        ?int $parentCommentId = null,
    ): int {
        $context = $this->teamProjectContext($userId, $projectId);
        if ($context === null) {
            throw new DomainException('Discussion is unavailable.');
        }
        $this->assertParentContext($parentCommentId, $projectId, null, $context['team_id']);

        return $this->insert(
            projectId: $projectId,
            taskId: null,
            teamId: $context['team_id'],
            parentCommentId: $parentCommentId,
            authorUserId: $userId,
            bodyHtml: $bodyHtml,
        );
    }

    public function createForTask(
        int $userId,
        int $taskId,
        string $bodyHtml,
        ?int $parentCommentId = null,
    ): int {
        $context = $this->teamTaskContext($userId, $taskId);
        if ($context === null) {
            throw new DomainException('Discussion is unavailable.');
        }
        $this->assertParentContext($parentCommentId, null, $taskId, $context['team_id']);

        return $this->insert(
            projectId: null,
            taskId: $taskId,
            teamId: $context['team_id'],
            parentCommentId: $parentCommentId,
            authorUserId: $userId,
            bodyHtml: $bodyHtml,
        );
    }

    /**
     * @return array{
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
     * }|null
     */
    public function notificationContextForMember(int $userId, int $commentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id AS comment_id,
                    c.author_user_id,
                    author.username AS author_username,
                    c.body_html,
                    c.parent_comment_id,
                    parent.author_user_id AS parent_author_user_id,
                    p.owner_team_id AS team_id,
                    p.id AS effective_project_id,
                    c.project_id,
                    c.task_id,
                    CASE WHEN c.task_id IS NOT NULL THEN t.title ELSE p.name END AS context_label
             FROM discussion_comments c
             LEFT JOIN discussion_comments parent ON parent.id = c.parent_comment_id
             LEFT JOIN users author ON author.id = c.author_user_id
             LEFT JOIN tasks t ON t.id = c.task_id
             INNER JOIN projects p
               ON p.id = CASE WHEN c.project_id IS NOT NULL THEN c.project_id ELSE t.project_id END
             INNER JOIN team_members tm
               ON tm.team_id = p.owner_team_id
              AND tm.user_id = :user_id
             WHERE c.id = :comment_id
               AND c.deleted_at IS NULL
               AND p.owner_user_id IS NULL
               AND p.owner_team_id IS NOT NULL
               AND c.team_id = p.owner_team_id
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'comment_id' => $commentId]);
        $row = $stmt->fetch();
        if (!is_array($row)
            || $row['author_user_id'] === null
            || $row['author_username'] === null
            || $row['team_id'] === null) {
            return null;
        }

        return [
            'comment_id' => (int) $row['comment_id'],
            'author_user_id' => (int) $row['author_user_id'],
            'author_username' => (string) $row['author_username'],
            'body_html' => (string) $row['body_html'],
            'parent_comment_id' => $row['parent_comment_id'] !== null ? (int) $row['parent_comment_id'] : null,
            'parent_author_user_id' => $row['parent_author_user_id'] !== null ? (int) $row['parent_author_user_id'] : null,
            'team_id' => (int) $row['team_id'],
            'effective_project_id' => (int) $row['effective_project_id'],
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'task_id' => $row['task_id'] !== null ? (int) $row['task_id'] : null,
            'context_label' => (string) $row['context_label'],
        ];
    }

    public function updateForProject(
        int $userId,
        int $projectId,
        int $commentId,
        string $bodyHtml,
    ): bool {
        return $this->updateForContext($userId, $commentId, $bodyHtml, $projectId, null);
    }

    public function updateForTask(
        int $userId,
        int $taskId,
        int $commentId,
        string $bodyHtml,
    ): bool {
        return $this->updateForContext($userId, $commentId, $bodyHtml, null, $taskId);
    }

    public function deleteForProject(int $userId, int $projectId, int $commentId): bool
    {
        return $this->deleteForContext($userId, $commentId, $projectId, null);
    }

    public function deleteForTask(int $userId, int $taskId, int $commentId): bool
    {
        return $this->deleteForContext($userId, $commentId, null, $taskId);
    }

    private function updateForContext(
        int $userId,
        int $commentId,
        string $bodyHtml,
        ?int $projectId,
        ?int $taskId,
    ): bool {
        $context = $this->commentContextForMember($userId, $commentId);
        if ($context === null
            || $context['author_user_id'] !== $userId
            || $context['deleted_at'] !== null
            || $context['project_id'] !== $projectId
            || $context['task_id'] !== $taskId) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE discussion_comments
             SET body_html = :body_html, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND author_user_id = :user_id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'body_html' => $this->normalizeBody($bodyHtml),
            'id' => $commentId,
            'user_id' => $userId,
        ]);
        return $stmt->rowCount() === 1;
    }

    private function deleteForContext(
        int $userId,
        int $commentId,
        ?int $projectId,
        ?int $taskId,
    ): bool {
        $context = $this->commentContextForMember($userId, $commentId);
        if ($context === null
            || $context['deleted_at'] !== null
            || $context['project_id'] !== $projectId
            || $context['task_id'] !== $taskId) {
            return false;
        }
        if ($context['author_user_id'] !== $userId && $context['role'] !== 'lead') {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE discussion_comments
             SET body_html = \'\', deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $commentId]);
        return $stmt->rowCount() === 1;
    }

    private function insert(
        ?int $projectId,
        ?int $taskId,
        int $teamId,
        ?int $parentCommentId,
        int $authorUserId,
        string $bodyHtml,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO discussion_comments (
                project_id, task_id, team_id, parent_comment_id, author_user_id, body_html,
                created_at, updated_at, deleted_at
             ) VALUES (
                :project_id, :task_id, :team_id, :parent_comment_id, :author_user_id, :body_html,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL
             )'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'task_id' => $taskId,
            'team_id' => $teamId,
            'parent_comment_id' => $parentCommentId,
            'author_user_id' => $authorUserId,
            'body_html' => $this->normalizeBody($bodyHtml),
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function assertParentContext(?int $parentCommentId, ?int $projectId, ?int $taskId, int $teamId): void
    {
        if ($parentCommentId === null) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT project_id, task_id, team_id, parent_comment_id, deleted_at
             FROM discussion_comments
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $parentCommentId]);
        $row = $stmt->fetch();
        if (!is_array($row)
            || $row['deleted_at'] !== null
            || ($row['project_id'] !== null ? (int) $row['project_id'] : null) !== $projectId
            || ($row['task_id'] !== null ? (int) $row['task_id'] : null) !== $taskId
            || ($row['team_id'] !== null ? (int) $row['team_id'] : null) !== $teamId) {
            throw new DomainException('Reply target is unavailable.');
        }
        if ($row['parent_comment_id'] !== null) {
            throw new DomainException('Replies can only be one level deep.');
        }
    }

    private function normalizeBody(string $bodyHtml): string
    {
        $bodyHtml = trim($bodyHtml);
        if ($bodyHtml === '' || mb_strlen($bodyHtml) > 20_000) {
            throw new DomainException('Discussion comment must contain 1-20000 characters.');
        }

        $plain = html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/[\s\x{00A0}]+/u', '', $plain) ?? '';
        if ($plain === '') {
            throw new DomainException('Discussion comment cannot be empty.');
        }
        return $bodyHtml;
    }

    /** @return array{team_id:int,role:string}|null */
    private function teamProjectContext(int $userId, int $projectId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.owner_team_id AS team_id, tm.role
             FROM projects p
             INNER JOIN team_members tm
               ON tm.team_id = p.owner_team_id
              AND tm.user_id = :user_id
             WHERE p.id = :project_id
               AND p.owner_user_id IS NULL
               AND p.owner_team_id IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'project_id' => $projectId]);
        $row = $stmt->fetch();
        if (!is_array($row) || $row['team_id'] === null) {
            return null;
        }
        return ['team_id' => (int) $row['team_id'], 'role' => (string) $row['role']];
    }

    /** @return array{project_id:int,team_id:int,role:string}|null */
    private function teamTaskContext(int $userId, int $taskId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id AS project_id, p.owner_team_id AS team_id, tm.role
             FROM tasks t
             INNER JOIN projects p ON p.id = t.project_id
             INNER JOIN team_members tm
               ON tm.team_id = p.owner_team_id
              AND tm.user_id = :user_id
             WHERE t.id = :task_id
               AND p.owner_user_id IS NULL
               AND p.owner_team_id IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'task_id' => $taskId]);
        $row = $stmt->fetch();
        if (!is_array($row) || $row['team_id'] === null) {
            return null;
        }
        return [
            'project_id' => (int) $row['project_id'],
            'team_id' => (int) $row['team_id'],
            'role' => (string) $row['role'],
        ];
    }

    /** @return array{author_user_id:int,role:string,deleted_at:?string,project_id:?int,task_id:?int}|null */
    private function commentContextForMember(int $userId, int $commentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.author_user_id, c.project_id, c.task_id, c.deleted_at, tm.role
             FROM discussion_comments c
             LEFT JOIN tasks t ON t.id = c.task_id
             INNER JOIN projects p
               ON p.id = CASE WHEN c.project_id IS NOT NULL THEN c.project_id ELSE t.project_id END
             INNER JOIN team_members tm
               ON tm.team_id = p.owner_team_id
              AND tm.user_id = :user_id
             WHERE c.id = :comment_id
               AND p.owner_user_id IS NULL
               AND p.owner_team_id IS NOT NULL
               AND c.team_id = p.owner_team_id
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'comment_id' => $commentId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'author_user_id' => $row['author_user_id'] !== null ? (int) $row['author_user_id'] : 0,
            'role' => (string) $row['role'],
            'deleted_at' => $row['deleted_at'] !== null ? (string) $row['deleted_at'] : null,
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'task_id' => $row['task_id'] !== null ? (int) $row['task_id'] : null,
        ];
    }

    /** @return list<DiscussionCommentRecord> */
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
    private function hydrate(array $row): DiscussionCommentRecord
    {
        return new DiscussionCommentRecord(
            id: (int) $row['id'],
            projectId: $row['project_id'] !== null ? (int) $row['project_id'] : null,
            taskId: $row['task_id'] !== null ? (int) $row['task_id'] : null,
            parentCommentId: $row['parent_comment_id'] !== null ? (int) $row['parent_comment_id'] : null,
            authorUserId: $row['author_user_id'] !== null ? (int) $row['author_user_id'] : null,
            authorUsername: $row['author_username'] !== null ? (string) $row['author_username'] : null,
            bodyHtml: (string) $row['body_html'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            deletedAt: $row['deleted_at'] !== null ? (string) $row['deleted_at'] : null,
        );
    }
}
