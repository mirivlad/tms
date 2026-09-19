<?php

declare(strict_types=1);

namespace Tms\Domain\Attachment;

use DomainException;
use PDO;
use Throwable;

final class AttachmentRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<AttachmentRecord> */
    public function listForTask(int $userId, int $taskId): array
    {
        if (!$this->taskAccessible($userId, $taskId)) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT id, task_id, user_id, storage_name, original_name, mime_type, file_size, sha256, created_at
             FROM attachments
             WHERE task_id = :task_id
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['task_id' => $taskId]);

        $records = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $records[] = $this->hydrate($row);
            }
        }
        return $records;
    }

    /** @return list<AttachmentRecord> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, task_id, user_id, storage_name, original_name, mime_type, file_size, sha256, created_at
             FROM attachments WHERE user_id = :user_id ORDER BY id ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        $records = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $records[] = $this->hydrate($row);
            }
        }
        return $records;
    }

    public function findForTask(int $userId, int $taskId, int $attachmentId): ?AttachmentRecord
    {
        if (!$this->taskAccessible($userId, $taskId)) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT id, task_id, user_id, storage_name, original_name, mime_type, file_size, sha256, created_at
             FROM attachments
             WHERE id = :id AND task_id = :task_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $attachmentId,
            'task_id' => $taskId,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param list<array{storage_name:string,original_name:string,mime_type:string,file_size:int,sha256:string}> $files
     */
    public function createManyForTask(int $userId, int $taskId, array $files): void
    {
        if ($files === []) {
            return;
        }
        if (!$this->taskAccessible($userId, $taskId)) {
            throw new DomainException('Task is unavailable.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO attachments (
                    task_id, user_id, storage_name, original_name, mime_type, file_size, sha256, created_at
                 ) VALUES (
                    :task_id, :user_id, :storage_name, :original_name, :mime_type, :file_size, :sha256,
                    CURRENT_TIMESTAMP
                 )'
            );
            foreach ($files as $file) {
                $this->assertMetadata($file);
                $stmt->execute([
                    'task_id' => $taskId,
                    'user_id' => $userId,
                    'storage_name' => $file['storage_name'],
                    'original_name' => $file['original_name'],
                    'mime_type' => $file['mime_type'],
                    'file_size' => $file['file_size'],
                    'sha256' => $file['sha256'],
                ]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function deleteForTask(int $userId, int $taskId, int $attachmentId): bool
    {
        if (!$this->taskAccessible($userId, $taskId)) {
            return false;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM attachments WHERE id = :id AND task_id = :task_id'
        );
        $stmt->execute([
            'id' => $attachmentId,
            'task_id' => $taskId,
        ]);
        return $stmt->rowCount() === 1;
    }

    private function taskAccessible(int $userId, int $taskId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
             FROM tasks t
             LEFT JOIN projects p ON p.id = t.project_id
             LEFT JOIN team_members tm
                ON tm.team_id = p.owner_team_id
               AND tm.user_id = :member_user_id
             WHERE t.id = :task_id
               AND (
                    (t.project_id IS NULL AND t.created_by = :personal_user_id)
                    OR
                    (t.project_id IS NOT NULL
                     AND p.owner_user_id = :project_user_id
                     AND p.owner_team_id IS NULL)
                    OR
                    (t.project_id IS NOT NULL
                     AND p.owner_user_id IS NULL
                     AND p.owner_team_id IS NOT NULL
                     AND tm.user_id IS NOT NULL)
               )
             LIMIT 1'
        );
        $stmt->execute([
            'member_user_id' => $userId,
            'task_id' => $taskId,
            'personal_user_id' => $userId,
            'project_user_id' => $userId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array{storage_name:string,original_name:string,mime_type:string,file_size:int,sha256:string} $file */
    private function assertMetadata(array $file): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $file['storage_name']) !== 1) {
            throw new DomainException('Invalid attachment storage name.');
        }
        if ($file['original_name'] === '' || mb_strlen($file['original_name']) > 255) {
            throw new DomainException('Invalid attachment original name.');
        }
        if ($file['mime_type'] === '' || strlen($file['mime_type']) > 127) {
            throw new DomainException('Invalid attachment MIME type.');
        }
        if ($file['file_size'] < 1) {
            throw new DomainException('Invalid attachment size.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $file['sha256']) !== 1) {
            throw new DomainException('Invalid attachment digest.');
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AttachmentRecord
    {
        return new AttachmentRecord(
            id: (int) $row['id'],
            taskId: (int) $row['task_id'],
            userId: $row['user_id'] !== null ? (int) $row['user_id'] : null,
            storageName: (string) $row['storage_name'],
            originalName: (string) $row['original_name'],
            mimeType: (string) $row['mime_type'],
            fileSize: (int) $row['file_size'],
            sha256: (string) $row['sha256'],
            createdAt: (string) $row['created_at'],
        );
    }
}
