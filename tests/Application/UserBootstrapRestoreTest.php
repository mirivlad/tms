<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Application\UserBootstrapService;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\TaskType\TaskTypeRepository;
use Tms\I18n\Translator;

final class UserBootstrapRestoreTest extends TestCase
{
    private PDO $db;
    private StatusRepository $statuses;
    private TaskTypeRepository $types;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE statuses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                color TEXT NOT NULL DEFAULT "#6b7280",
                sort_order INTEGER NOT NULL DEFAULT 0,
                is_default INTEGER NOT NULL DEFAULT 0,
                is_completion INTEGER NOT NULL DEFAULT 0,
                show_on_board INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, name)
            )'
        );
        $this->db->exec(
            'CREATE TABLE task_types (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, name)
            )'
        );
        $this->db->exec(
            'CREATE TABLE tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_by INTEGER NOT NULL,
                title TEXT NOT NULL,
                status_id INTEGER NULL,
                type_id INTEGER NULL,
                customer_id INTEGER NULL
            )'
        );

        $this->statuses = new StatusRepository($this->db);
        $this->types = new TaskTypeRepository($this->db);
    }

    public function testRestoreAddsOnlyMissingTemplatesAndKeepsExistingRoles(): void
    {
        $customDefault = $this->statuses->createForUser(1, 'My inbox', isDefault: true);
        $customDone = $this->statuses->createForUser(1, 'My done', isCompletion: true);
        $bootstrap = new UserBootstrapService($this->statuses, $this->types, $this->translator('en'));

        self::assertSame(3, $bootstrap->restoreMissingStatuses(1));
        self::assertSame(0, $bootstrap->restoreMissingStatuses(1));

        $statuses = $this->statuses->listForUser(1);
        self::assertCount(5, $statuses);
        self::assertTrue($this->statuses->findForUser(1, $customDefault)?->isDefault);
        self::assertTrue($this->statuses->findForUser(1, $customDone)?->isCompletion);
        self::assertSame(1, count(array_filter($statuses, static fn ($status): bool => $status->isDefault)));
        self::assertSame(1, count(array_filter($statuses, static fn ($status): bool => $status->isCompletion)));
    }

    public function testRestoreCanReestablishMissingRolesWhenNoneExist(): void
    {
        $this->statuses->createForUser(2, 'Custom');
        $bootstrap = new UserBootstrapService($this->statuses, $this->types, $this->translator('ru'));

        self::assertSame(3, $bootstrap->restoreMissingStatuses(2));

        $statuses = $this->statuses->listForUser(2);
        $default = array_values(array_filter($statuses, static fn ($status): bool => $status->isDefault));
        $completion = array_values(array_filter($statuses, static fn ($status): bool => $status->isCompletion));
        self::assertCount(1, $default);
        self::assertCount(1, $completion);
        self::assertSame('Входящие', $default[0]->name);
        self::assertSame('Готово', $completion[0]->name);
    }

    private function translator(string $locale): Translator
    {
        return new Translator(dirname(__DIR__, 2) . '/resources/i18n', $locale);
    }
}
