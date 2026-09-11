<?php

declare(strict_types=1);

namespace Tms\Tests\Domain;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\CustomField\CustomFieldRecord;
use Tms\Domain\CustomField\CustomFieldRepository;
use Tms\Domain\CustomField\TaskCustomFieldValueRepository;

final class CustomFieldRepositoriesTest extends TestCase
{
    private PDO $db;
    private CustomFieldRepository $fields;
    private TaskCustomFieldValueRepository $values;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->createSchema();

        $this->db->exec("INSERT INTO users (id) VALUES (1), (2)");
        $this->db->exec("INSERT INTO tasks (id, created_by) VALUES (10, 1), (20, 2)");

        $this->fields = new CustomFieldRepository($this->db);
        $this->values = new TaskCustomFieldValueRepository($this->db);
    }

    public function testDefinitionsAreStrictlyOwnerScoped(): void
    {
        $ownId = $this->fields->createForUser(1, 'Cost', 'money', [], false);
        $foreignId = $this->fields->createForUser(2, 'Secret', 'text', [], false);

        self::assertSame([$ownId], array_map(
            static fn (CustomFieldRecord $field): int => $field->id,
            $this->fields->listForUser(1),
        ));
        self::assertNotNull($this->fields->findForUser(1, $ownId));
        self::assertNull($this->fields->findForUser(1, $foreignId));
        self::assertFalse($this->fields->deleteForUser(1, $foreignId));
    }

    public function testTaskValuesRejectForeignTaskAndForeignField(): void
    {
        $ownField = $this->fields->createForUser(1, 'Own', 'text', [], false);
        $foreignField = $this->fields->createForUser(2, 'Foreign', 'text', [], false);

        try {
            $this->values->replaceForTask(1, 20, [$ownField => 'nope']);
            self::fail('Foreign task was accepted.');
        } catch (DomainException $error) {
            self::assertSame('Task is unavailable.', $error->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Custom field is unavailable.');
        $this->values->replaceForTask(1, 10, [$foreignField => 'nope']);
    }

    public function testValuesRoundTripWithoutCrossUserLeakage(): void
    {
        $ownField = $this->fields->createForUser(1, 'Own', 'text', [], false);
        $foreignField = $this->fields->createForUser(2, 'Foreign', 'text', [], false);

        $this->values->replaceForTask(1, 10, [$ownField => 'visible']);
        $this->values->replaceForTask(2, 20, [$foreignField => 'hidden']);

        self::assertSame([$ownField => 'visible'], $this->values->listForTask(1, 10));
        self::assertSame([], $this->values->listForTask(1, 20));
        self::assertSame(
            [10 => [$ownField => 'visible']],
            $this->values->listForTasks(1, [10, 20]),
        );
    }

    public function testChangingFieldTypeClearsExistingValues(): void
    {
        $fieldId = $this->fields->createForUser(1, 'Mutable', 'text', [], false);
        $this->values->replaceForTask(1, 10, [$fieldId => 'old text']);
        self::assertSame([$fieldId => 'old text'], $this->values->listForTask(1, 10));

        self::assertTrue($this->fields->updateForUser(1, $fieldId, 'Mutable', 'money', [], false));
        self::assertSame([], $this->values->listForTask(1, 10));
    }

    public function testChangingSelectOptionsClearsValuesThatMayHaveBecomeInvalid(): void
    {
        $fieldId = $this->fields->createForUser(1, 'Environment', 'select', ['Prod', 'Stage'], false);
        $this->values->replaceForTask(1, 10, [$fieldId => 'Stage']);

        self::assertTrue($this->fields->updateForUser(
            1,
            $fieldId,
            'Environment',
            'select',
            ['Prod'],
            false,
        ));
        self::assertSame([], $this->values->listForTask(1, 10));
    }

    public function testReorderingCannotIncludeAnotherUsersField(): void
    {
        $first = $this->fields->createForUser(1, 'First', 'text', [], false);
        $second = $this->fields->createForUser(1, 'Second', 'text', [], false);
        $foreign = $this->fields->createForUser(2, 'Foreign', 'text', [], false);

        self::assertFalse($this->fields->reorderForUser(1, [$second, $foreign]));
        self::assertTrue($this->fields->reorderForUser(1, [$second, $first]));
        self::assertSame(
            [$second, $first],
            array_map(
                static fn (CustomFieldRecord $field): int => $field->id,
                $this->fields->listForUser(1),
            ),
        );
    }

    private function createSchema(): void
    {
        $this->db->exec(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY
);
CREATE TABLE tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_by INTEGER NOT NULL,
    UNIQUE (id, created_by),
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE
);
CREATE TABLE custom_fields (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    field_type TEXT NOT NULL,
    options_json TEXT NULL,
    is_required INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, name),
    UNIQUE (id, user_id),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE TABLE task_custom_field_values (
    task_id INTEGER NOT NULL,
    field_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    value TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id, field_id),
    FOREIGN KEY (task_id, user_id) REFERENCES tasks (id, created_by) ON DELETE CASCADE,
    FOREIGN KEY (field_id, user_id) REFERENCES custom_fields (id, user_id) ON DELETE CASCADE
);
SQL);
    }
}
