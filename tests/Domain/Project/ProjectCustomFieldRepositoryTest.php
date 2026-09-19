<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Project;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Project\ProjectCustomFieldRepository;

final class ProjectCustomFieldRepositoryTest extends TestCase
{
    private PDO $db;
    private ProjectCustomFieldRepository $fields;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('PRAGMA foreign_keys = ON');

        $this->db->exec('CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            owner_user_id INTEGER NULL,
            owner_team_id INTEGER NULL
        )');
        $this->db->exec('CREATE TABLE custom_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            project_id INTEGER NULL,
            source_field_id INTEGER NULL,
            name TEXT NOT NULL,
            field_type TEXT NOT NULL,
            options_json TEXT NULL,
            is_required INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (project_id, name)
        )');
        $this->db->exec('CREATE TABLE task_custom_field_values (
            task_id INTEGER NOT NULL,
            field_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            value TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (task_id, field_id),
            FOREIGN KEY (field_id) REFERENCES custom_fields (id) ON DELETE CASCADE
        )');

        $this->db->exec('INSERT INTO projects (id, owner_user_id, owner_team_id) VALUES
            (10, 1, NULL), (20, 2, NULL), (30, NULL, 7)');
        $this->db->exec("INSERT INTO custom_fields (
            id, user_id, project_id, source_field_id, name, field_type, options_json, is_required, sort_order
        ) VALUES
            (100, NULL, 10, 1, 'Reference', 'text', NULL, 1, 1),
            (101, NULL, 10, 2, 'Tags', 'checkbox_list', '[\"red\",\"blue\"]', 0, 2),
            (200, NULL, 20, 3, 'Foreign', 'text', NULL, 0, 1),
            (300, NULL, 30, NULL, 'Team reserved', 'text', NULL, 0, 1)");

        $this->fields = new ProjectCustomFieldRepository($this->db);
    }

    public function testProjectFieldsStayInsideOwnedProject(): void
    {
        $records = $this->fields->listForProject(1, 10);
        self::assertSame([100, 101], array_map(static fn ($field): int => $field->id, $records));
        self::assertTrue($records[0]->isProjectScoped());
        self::assertSame(1, $records[0]->sourceFieldId);

        self::assertNotNull($this->fields->findForProject(1, 10, 100));
        self::assertNull($this->fields->findForProject(1, 10, 200));
        self::assertSame([], $this->fields->listForProject(1, 20));
        self::assertSame([], $this->fields->listForProject(1, 30));
    }

    public function testCreateUpdateAndReorderAreProjectScoped(): void
    {
        $new = $this->fields->createForProject(1, 10, 'Budget', 'money', [], false);
        self::assertNotNull($this->fields->findForProject(1, 10, $new));

        self::assertTrue($this->fields->updateForProject(
            1, 10, $new, 'Environment', 'select', ['Prod', 'QA'], true,
        ));
        $updated = $this->fields->findForProject(1, 10, $new);
        self::assertSame('select', $updated?->type);
        self::assertSame(['Prod', 'QA'], $updated?->options);
        self::assertTrue($updated?->isRequired ?? false);

        self::assertFalse($this->fields->reorderForProject(1, 10, [100, $new]));
        self::assertTrue($this->fields->reorderForProject(1, 10, [$new, 101, 100]));
        self::assertSame(
            [$new, 101, 100],
            array_map(static fn ($field): int => $field->id, $this->fields->listForProject(1, 10)),
        );
    }

    public function testFieldChangesCleanOnlyValuesForThatProjectField(): void
    {
        $this->db->exec("INSERT INTO task_custom_field_values (task_id, field_id, user_id, value) VALUES
            (1, 101, 1, '[\"red\",\"blue\"]'),
            (2, 200, 2, 'foreign')");

        self::assertTrue($this->fields->updateForProject(
            1, 10, 101, 'Tags', 'checkbox_list', ['red'], false,
        ));
        self::assertSame('["red"]', $this->valueFor(1, 101));
        self::assertSame('foreign', $this->valueFor(2, 200));

        self::assertTrue($this->fields->deleteForProject(1, 10, 101));
        self::assertNull($this->valueFor(1, 101));
    }

    public function testCannotMutateForeignOrReservedTeamProject(): void
    {
        $this->expectException(DomainException::class);
        $this->fields->createForProject(1, 20, 'Stolen', 'text', [], false);
    }

    private function valueFor(int $taskId, int $fieldId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT value FROM task_custom_field_values WHERE task_id = :task_id AND field_id = :field_id'
        );
        $stmt->execute(['task_id' => $taskId, 'field_id' => $fieldId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }
}
