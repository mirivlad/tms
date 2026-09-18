<?php

declare(strict_types=1);

namespace Tms\Domain\Project;

use DomainException;
use PDO;
use Throwable;

final class ProjectAttachmentRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<ProjectAttachmentRecord> */
    public function listForProject(int $userId, int $projectId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.id, a.project_id, a.uploaded_by, a.storage_name, a.original_name,
                    a.mime_type, a.file_size, a.sha256, a.created_at
             FROM project_attachments a
             INNER JOIN projects p ON p.id = a.project_id
             WHERE a.project_id = :project_id
               AND p.owner_user_id = :user_id
               AND p.owner_team_id IS NULL
             ORDER BY a.created_at DESC, a.id DESC'
        );
        $stmt->execute(['project_id' => $projectId, 'user_id' => $userId]);
        return $this->fetchAll($stmt);
    }

    /** @return list<ProjectAttachmentRecord> */
    public function listForProjectsOwnedByUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.id, a.project_id, a.uploaded_by, a.storage_name, a.original_name,
                    a.mime_type, a.file_size, a.sha256, a.created_at
             FROM project_attachments a
             INNER JOIN projects p ON p.id = a.project_id
             WHERE p.owner_user_id = :user_id AND p.owner_team_id IS NULL
             ORDER BY a.id ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $this->fetchAll($stmt);
    }

    public function findForProject(int $userId, int $projectId, int $attachmentId): ?ProjectAttachmentRecord
    {
        $stmt = $this->db->prepare(
            'SELECT a.id, a.project_id, a.uploaded_by, a.storage_name, a.original_name,
                    a.mime_type, a.file_size, a.sha256, a.created_at
             FROM project_attachments a
             INNER JOIN projects p ON p.id = a.project_id
             WHERE a.id = :id
               AND a.project_id = :project_id
               AND p.owner_user_id = :user_id
               AND p.owner_team_id IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $attachmentId,
            'project_id' => $projectId,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param list<array{storage_name:string,original_name:string,mime_type:string,file_size:int,sha256:string}> $files
     */
    public function createManyForProject(int $userId, int $projectId, array $files): void
    {
        if ($files === []) {
            return;
        }
        if (!$this->projectBelongsToUser($userId, $projectId)) {
            throw new DomainException('Project is unavailable.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO project_attachments (
                    project_id, uploaded_by, storage_name, original_name, mime_type, file_size, sha256, created_at
                 ) VALUES (
                    :project_id, :uploaded_by, :storage_name, :original_name, :mime_type, :file_size, :sha256,
                    CURRENT_TIMESTAMP
                 )'
            );
            foreach ($files as $file) {
                $this->assertMetadata($file);
                $stmt->execute([
                    'project_id' => $projectId,
                    'uploaded_by' => $userId,
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

    public function deleteForProject(int $userId, int $projectId, int $attachmentId): bool
    {
        if (!$this->projectBelongsToUser($userId, $projectId)) {
            return false;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM project_attachments WHERE id = :id AND project_id = :project_id'
        );
        $stmt->execute(['id' => $attachmentId, 'project_id' => $projectId]);
        return $stmt->rowCount() === 1;
    }

    private function projectBelongsToUser(int $userId, int $projectId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM projects
             WHERE id = :id AND owner_user_id = :user_id AND owner_team_id IS NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $projectId, 'user_id' => $userId]);
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

    /** @param \PDOStatement $stmt
     * @return list<ProjectAttachmentRecord>
     */
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
    private function hydrate(array $row): ProjectAttachmentRecord
    {
        return new ProjectAttachmentRecord(
            id: (int) $row['id'],
            projectId: (int) $row['project_id'],
            uploadedBy: $row['uploaded_by'] !== null ? (int) $row['uploaded_by'] : null,
            storageName: (string) $row['storage_name'],
            originalName: (string) $row['original_name'],
            mimeType: (string) $row['mime_type'],
            fileSize: (int) $row['file_size'],
            sha256: (string) $row['sha256'],
            createdAt: (string) $row['created_at'],
        );
    }
}
