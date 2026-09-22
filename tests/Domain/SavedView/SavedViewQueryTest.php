<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\SavedView;

use PHPUnit\Framework\TestCase;
use Tms\Domain\SavedView\SavedViewQuery;

final class SavedViewQueryTest extends TestCase
{
    public function testNormalizerKeepsOnlySupportedTaskListState(): void
    {
        $query = (new SavedViewQuery())->normalize([
            'status_id' => '12',
            'status_invert' => '1',
            'type_id' => '7',
            'project' => 'all',
            'priority' => 'urgent',
            'q' => '  outage  ',
            'customer' => 'Acme',
            'overdue' => '1',
            'deadline_from' => '2026-09-01',
            'deadline_to' => '2026-09-30',
            'sort' => 'custom_44',
            'order' => 'ASC',
            'per_page' => '50',
            'custom' => [
                '44' => ['values' => ['A', 'B', 'A'], 'match' => 'all'],
                '45' => ['min' => '10', 'max' => '20'],
                '46' => 'yes',
                'bad' => 'drop',
            ],
            'page' => '99',
            'view' => '77',
            'evil' => '<script>',
        ]);

        self::assertSame(12, $query['status_id']);
        self::assertSame('1', $query['status_invert']);
        self::assertSame(7, $query['type_id']);
        self::assertSame('all', $query['project']);
        self::assertSame('urgent', $query['priority']);
        self::assertSame('outage', $query['q']);
        self::assertSame('Acme', $query['customer']);
        self::assertSame('1', $query['overdue']);
        self::assertSame('2026-09-01', $query['deadline_from']);
        self::assertSame('2026-09-30', $query['deadline_to']);
        self::assertSame('custom_44', $query['sort']);
        self::assertSame('asc', $query['order']);
        self::assertSame(50, $query['per_page']);
        self::assertSame(['values' => ['A', 'B'], 'match' => 'all'], $query['custom'][44]);
        self::assertSame(['min' => '10', 'max' => '20'], $query['custom'][45]);
        self::assertSame('yes', $query['custom'][46]);
        self::assertArrayNotHasKey('page', $query);
        self::assertArrayNotHasKey('view', $query);
        self::assertArrayNotHasKey('evil', $query);
    }

    public function testInvalidValuesAreDroppedInsteadOfStored(): void
    {
        $query = (new SavedViewQuery())->normalize([
            'status_id' => '-1',
            'status_invert' => '1',
            'project' => '../foreign',
            'priority' => 'critical',
            'deadline_from' => '2026-02-31',
            'sort' => 'DROP TABLE tasks',
            'order' => 'sideways',
            'per_page' => '5000',
            'custom' => ['x' => ['values' => ['secret']]],
        ]);

        self::assertSame([], $query);
    }
}
