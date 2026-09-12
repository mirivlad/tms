<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use PHPUnit\Framework\TestCase;
use Tms\Application\TaskListSorter;
use Tms\Domain\CustomField\CustomFieldRecord;
use Tms\Domain\CustomField\CustomFieldValueCodec;
use Tms\Domain\Task\TaskRecord;

final class TaskListSorterTest extends TestCase
{
    private TaskListSorter $sorter;

    protected function setUp(): void
    {
        $this->sorter = new TaskListSorter(new CustomFieldValueCodec());
    }

    public function testMoneyCustomFieldSortsNumericallyAndKeepsMissingValuesLast(): void
    {
        $tasks = [$this->task(1, 'Ten'), $this->task(2, 'Two'), $this->task(3, 'Missing')];
        $field = $this->field(10, 'Budget', 'money');
        $values = [1 => [10 => '10'], 2 => [10 => '2.50']];

        $asc = $this->sorter->sort($tasks, 'custom_10', 'asc', [], [], [], [$field], $values);
        $desc = $this->sorter->sort($tasks, 'custom_10', 'desc', [], [], [], [$field], $values);

        self::assertSame([2, 1, 3], array_map(static fn (TaskRecord $task): int => $task->id, $asc));
        self::assertSame([1, 2, 3], array_map(static fn (TaskRecord $task): int => $task->id, $desc));
    }

    public function testStandardAndSelectSortingUseDisplayedValues(): void
    {
        $tasks = [
            $this->task(1, 'One', statusId: 2),
            $this->task(2, 'Two', statusId: 1),
            $this->task(3, 'Three'),
        ];
        $field = $this->field(11, 'Region', 'select', ['Zulu', 'Alpha']);
        $values = [1 => [11 => 'Zulu'], 2 => [11 => 'Alpha']];

        $byStatus = $this->sorter->sort($tasks, 'status_name', 'asc', [1 => 'Doing', 2 => 'Open'], [], [], [$field], $values);
        $byRegion = $this->sorter->sort($tasks, 'custom_11', 'asc', [], [], [], [$field], $values);

        self::assertSame([2, 1, 3], array_map(static fn (TaskRecord $task): int => $task->id, $byStatus));
        self::assertSame([2, 1, 3], array_map(static fn (TaskRecord $task): int => $task->id, $byRegion));
    }

    public function testForeignCustomSortKeyIsRejected(): void
    {
        $tasks = [$this->task(1, 'B'), $this->task(2, 'A')];
        $field = $this->field(10, 'Owned', 'text');

        self::assertFalse($this->sorter->supports('custom_999', [$field]));
        self::assertSame($tasks, $this->sorter->sort($tasks, 'custom_999', 'asc', [], [], [], [$field], []));
    }

    /** @param list<string> $options */
    private function field(int $id, string $name, string $type, array $options = []): CustomFieldRecord
    {
        return new CustomFieldRecord($id, 1, $name, $type, $options, false, $id);
    }

    private function task(int $id, string $title, ?int $statusId = null): TaskRecord
    {
        return new TaskRecord(
            id: $id,
            ownerId: 1,
            title: $title,
            description: '',
            deadline: null,
            statusId: $statusId,
            typeId: null,
            priority: 1,
            customerId: null,
            createdAt: '2026-09-12 10:00:00',
            updatedAt: '2026-09-12 10:00:00',
        );
    }
}
