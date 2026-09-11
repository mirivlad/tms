<?php

declare(strict_types=1);

namespace Tms\Tests\Domain;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Attachment\AttachmentRepository;

final class AttachmentRepositoryTest extends TestCase
{
    private PDO $db;
    private AttachmentRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, created_by INTEGER NOT NULL)');
        $this->db->exec(
            'CREATE TABLE attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                storage_name TEXT NOT NULL UNIQUE,
                original_name TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                file_size INTEGER NOT NULL,
                sha256 TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec('INSERT INTO tasks (id, created_by) VALUES (10, 1), (20, 2)');
        $this->repository = new AttachmentRepository($this->db);
    }

    public function testCreateListFindAndDeleteAreOwnerScoped(): void
    {
        $this->repository->createManyForTask(1, 10, [[
            'storage_name' => str_repeat('a', 64),
            'original_name' => 'notes.txt',
            'mime_type' => 'text/plain',
            'file_size' => 5,
            'sha256' => str_repeat('b', 64),
        ]]);

        $records = $this->repository->listForTask(1, 10);
        self::assertCount(1, $records);
        self::assertSame('notes.txt', $records[0]->originalName);
        self::assertNotNull($this->repository->findForTask(1, 10, $records[0]->id));
        self::assertNull($this->repository->findForTask(2, 10, $records[0]->id));
        self::assertFalse($this->repository->deleteForTask(2, 10, $records[0]->id));
        self::assertTrue($this->repository->deleteForTask(1, 10, $records[0]->id));
        self::assertSame([], $this->repository->listForTask(1, 10));
    }

    public function testCannotCreateAttachmentForForeignTask(): void
    {
        $this->expectException(DomainException::class);
        $this->repository->createManyForTask(1, 20, [[
            'storage_name' => str_repeat('c', 64),
            'original_name' => 'foreign.txt',
            'mime_type' => 'text/plain',
            'file_size' => 7,
            'sha256' => str_repeat('d', 64),
        ]]);
    }
}
