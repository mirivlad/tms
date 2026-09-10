<?php

declare(strict_types=1);

namespace Tms\Tests\Domain;

use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Application\UserBootstrapService;
use Tms\Domain\Customer\CustomerRepository;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\TaskType\TaskTypeRepository;

final class TaskMetadataRepositoriesTest extends TestCase
{
    private PDO $db;
    private StatusRepository $statuses;
    private TaskTypeRepository $types;
    private CustomerRepository $customers;

    protected function setUp(): void
    {
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
            'CREATE TABLE customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
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
                description TEXT NOT NULL DEFAULT "",
                deadline TEXT NULL,
                status_id INTEGER NULL,
                type_id INTEGER NULL,
                priority INTEGER NOT NULL DEFAULT 0,
                customer_id INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $this->statuses = new StatusRepository($this->db);
        $this->types = new TaskTypeRepository($this->db);
        $this->customers = new CustomerRepository($this->db);
    }

    public function testUserBootstrapCreatesNeutralDefaultsExactlyOnce(): void
    {
        $bootstrap = new UserBootstrapService($this->statuses, $this->types);
        $bootstrap->ensureDefaults(1);
        $bootstrap->ensureDefaults(1);

        $statuses = $this->statuses->listForUser(1);
        self::assertCount(3, $statuses);
        self::assertSame(['Inbox', 'In progress', 'Done'], array_map(static fn ($status): string => $status->name, $statuses));
        self::assertTrue($statuses[0]->isDefault);
        self::assertTrue($statuses[2]->isCompletion);

        $types = $this->types->listForUser(1);
        self::assertCount(1, $types);
        self::assertSame('General', $types[0]->name);
    }

    public function testStatusOperationsNeverCrossUserBoundary(): void
    {
        $one = $this->statuses->createForUser(1, 'One', isDefault: true);
        $two = $this->statuses->createForUser(1, 'Two', isCompletion: true);
        $foreign = $this->statuses->createForUser(2, 'Foreign', isDefault: true, isCompletion: true);

        self::assertNull($this->statuses->findForUser(1, $foreign));
        self::assertFalse($this->statuses->updateForUser(1, $foreign, 'Stolen', '', '#111111', true));
        self::assertFalse($this->statuses->setDefaultForUser(1, $foreign));
        self::assertFalse($this->statuses->setCompletionForUser(1, $foreign));
        self::assertFalse($this->statuses->deleteForUser(1, $foreign));
        self::assertFalse($this->statuses->reorderForUser(1, [$two, $foreign]));

        self::assertSame('Foreign', $this->statuses->findForUser(2, $foreign)?->name);
        self::assertSame([$one, $two], array_map(static fn ($status): int => $status->id, $this->statuses->listForUser(1)));
    }

    public function testStatusRolesAreExclusiveAndProtectedFromDeletion(): void
    {
        $first = $this->statuses->createForUser(1, 'First', isDefault: true, isCompletion: true);
        $second = $this->statuses->createForUser(1, 'Second');

        self::assertTrue($this->statuses->setDefaultForUser(1, $second));
        self::assertTrue($this->statuses->setCompletionForUser(1, $second));

        self::assertFalse($this->statuses->findForUser(1, $first)?->isDefault);
        self::assertFalse($this->statuses->findForUser(1, $first)?->isCompletion);
        self::assertTrue($this->statuses->findForUser(1, $second)?->isDefault);
        self::assertTrue($this->statuses->findForUser(1, $second)?->isCompletion);
        self::assertFalse($this->statuses->deleteForUser(1, $second));
        self::assertTrue($this->statuses->deleteForUser(1, $first));
    }

    public function testTaskTypeOperationsNeverCrossUserBoundary(): void
    {
        $one = $this->types->createForUser(1, 'General');
        $two = $this->types->createForUser(1, 'Incident');
        $foreign = $this->types->createForUser(2, 'Private');

        self::assertNull($this->types->findForUser(1, $foreign));
        self::assertFalse($this->types->updateForUser(1, $foreign, 'Stolen', ''));
        self::assertFalse($this->types->deleteForUser(1, $foreign));
        self::assertFalse($this->types->reorderForUser(1, [$two, $foreign]));
        self::assertTrue($this->types->reorderForUser(1, [$two, $one]));

        self::assertSame([$two, $one], array_map(static fn ($type): int => $type->id, $this->types->listForUser(1)));
        self::assertSame('Private', $this->types->findForUser(2, $foreign)?->name);
    }

    public function testCustomerLookupSearchAndMutationAreAlwaysOwnerScoped(): void
    {
        $own = $this->customers->createForUser(1, 'Acme 100%');
        $foreign = $this->customers->createForUser(2, 'Acme 100%');

        self::assertNull($this->customers->findForUser(1, $foreign));
        self::assertFalse($this->customers->updateForUser(1, $foreign, 'Stolen'));
        self::assertFalse($this->customers->deleteForUser(1, $foreign));

        $matches = $this->customers->searchForUser(1, '100%');
        self::assertCount(1, $matches);
        self::assertSame($own, $matches[0]->id);
        self::assertSame(1, $matches[0]->userId);
    }

    public function testReferencedMetadataCannotBeDeleted(): void
    {
        $status = $this->statuses->createForUser(1, 'Working');
        $type = $this->types->createForUser(1, 'General');
        $customer = $this->customers->createForUser(1, 'Customer');

        $stmt = $this->db->prepare(
            'INSERT INTO tasks (created_by, title, status_id, type_id, customer_id)
             VALUES (:user_id, :title, :status_id, :type_id, :customer_id)'
        );
        $stmt->execute([
            'user_id' => 1,
            'title' => 'Protected references',
            'status_id' => $status,
            'type_id' => $type,
            'customer_id' => $customer,
        ]);

        self::assertFalse($this->statuses->deleteForUser(1, $status));
        self::assertFalse($this->types->deleteForUser(1, $type));
        self::assertFalse($this->customers->deleteForUser(1, $customer));
    }
}
