<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Project;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Project\ProjectAttachmentRepository;

final class ProjectAttachmentRepositoryTest extends TestCase
{
    private PDO $db;
    private ProjectAttachmentRepository $attachments;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            owner_user_id INTEGER NULL,
            owner_team_id INTEGER NULL
        )');
        $this->db->exec('CREATE TABLE team_members (
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            PRIMARY KEY (team_id, user_id)
        )');
        $this->db->exec('CREATE TABLE project_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            uploaded_by INTEGER NULL,
            storage_name TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            file_size INTEGER NOT NULL,
            sha256 TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->db->exec('INSERT INTO projects (id, owner_user_id, owner_team_id) VALUES
            (10, 1, NULL), (20, 2, NULL), (30, NULL, 7)');

        $this->attachments = new ProjectAttachmentRepository($this->db);
    }

    public function testProjectFilesStayOwnerScoped(): void
    {
        $file = [[
            'storage_name' => str_repeat('a', 64),
            'original_name' => 'spec.txt',
            'mime_type' => 'text/plain',
            'file_size' => 12,
            'sha256' => str_repeat('b', 64),
        ]];

        $this->attachments->createManyForProject(1, 10, $file);
        $records = $this->attachments->listForProject(1, 10);
        self::assertCount(1, $records);
        self::assertSame('spec.txt', $records[0]->originalName);
        self::assertSame(1, $records[0]->uploadedBy);

        self::assertSame([], $this->attachments->listForProject(2, 10));
        self::assertNull($this->attachments->findForProject(2, 10, $records[0]->id));
        self::assertFalse($this->attachments->deleteForProject(2, 10, $records[0]->id));

        self::assertTrue($this->attachments->deleteForProject(1, 10, $records[0]->id));
        self::assertSame([], $this->attachments->listForProject(1, 10));
    }

    public function testCannotAttachToForeignOrTeamProjectBeforeTeamsStage(): void
    {
        $this->expectException(DomainException::class);
        $this->attachments->createManyForProject(1, 20, [[
            'storage_name' => str_repeat('c', 64),
            'original_name' => 'foreign.txt',
            'mime_type' => 'text/plain',
            'file_size' => 5,
            'sha256' => str_repeat('d', 64),
        ]]);
    }

    public function testAdminCleanupListsOnlyPersonallyOwnedProjects(): void
    {
        $this->attachments->createManyForProject(1, 10, [[
            'storage_name' => str_repeat('e', 64),
            'original_name' => 'owned.txt',
            'mime_type' => 'text/plain',
            'file_size' => 5,
            'sha256' => str_repeat('f', 64),
        ]]);
        $records = $this->attachments->listForProjectsOwnedByUser(1);
        self::assertCount(1, $records);
        self::assertSame(10, $records[0]->projectId);
    }
}
