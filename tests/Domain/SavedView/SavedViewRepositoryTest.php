<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\SavedView;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\SavedView\SavedViewRepository;

final class SavedViewRepositoryTest extends TestCase
{
    private PDO $db;
    private SavedViewRepository $views;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('CREATE TABLE task_saved_views (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            query_json TEXT NOT NULL,
            is_default INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (user_id, name)
        )');

        $this->views = new SavedViewRepository($this->db);
    }

    public function testViewsAreOwnerScopedAndDefaultIsUniquePerUser(): void
    {
        $first = $this->views->create(1, 'Urgent', ['priority' => 'urgent'], true);
        $second = $this->views->create(1, 'No project', ['project' => 'none'], false);
        $foreign = $this->views->create(2, 'Foreign', ['project' => 'all'], true);

        self::assertSame($first, $this->views->defaultForUser(1)?->id);
        self::assertCount(2, $this->views->listForUser(1));
        self::assertNull($this->views->findForUser(1, $foreign));
        self::assertFalse($this->views->setDefault(1, $foreign));

        self::assertTrue($this->views->setDefault(1, $second));
        self::assertSame($second, $this->views->defaultForUser(1)?->id);
        self::assertFalse($this->views->findForUser(1, $first)?->isDefault ?? true);
        self::assertSame($foreign, $this->views->defaultForUser(2)?->id);
    }

    public function testDeleteCannotCrossUserBoundary(): void
    {
        $owned = $this->views->create(1, 'Owned', ['q' => 'needle'], false);
        $foreign = $this->views->create(2, 'Foreign', ['q' => 'secret'], false);

        self::assertFalse($this->views->delete(1, $foreign));
        self::assertNotNull($this->views->findForUser(2, $foreign));

        self::assertTrue($this->views->delete(1, $owned));
        self::assertNull($this->views->findForUser(1, $owned));
    }
}
