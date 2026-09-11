<?php

declare(strict_types=1);

namespace Tms\Tests\Domain;

use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tms\Domain\CustomField\CustomFieldRecord;
use Tms\Domain\CustomField\CustomFieldValueCodec;

final class CustomFieldValueCodecTest extends TestCase
{
    private CustomFieldValueCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new CustomFieldValueCodec();
    }

    public function testNormalizesMoneyAndCheckboxValues(): void
    {
        self::assertSame('1200.50', $this->codec->encode($this->field('money'), '1 200,5'));
        self::assertSame('0', $this->codec->encode($this->field('checkbox'), null));
        self::assertSame('1', $this->codec->encode($this->field('checkbox'), 'on'));
    }

    public function testSelectAndCheckboxListAcceptOnlyConfiguredOptions(): void
    {
        $select = $this->field('select', ['one', 'two']);
        self::assertSame('two', $this->codec->encode($select, 'two'));

        $list = $this->field('checkbox_list', ['one', 'two', 'three']);
        $stored = $this->codec->encode($list, ['three', 'one', 'three']);
        self::assertSame('["three","one"]', $stored);
        self::assertSame(['three', 'one'], $this->codec->selectedOptions($list, $stored));
        self::assertSame('three, one', $this->codec->display($list, $stored));

        $this->expectException(DomainException::class);
        $this->codec->encode($select, 'foreign');
    }

    #[DataProvider('requiredFieldProvider')]
    public function testRequiredFieldsRejectMissingValues(string $type, mixed $input): void
    {
        $this->expectException(DomainException::class);
        $this->codec->encode($this->field($type, ['one'], true), $input);
    }

    /** @return array<string, array{string, mixed}> */
    public static function requiredFieldProvider(): array
    {
        return [
            'text' => ['text', ''],
            'textarea' => ['textarea', null],
            'select' => ['select', ''],
            'money' => ['money', ''],
            'checkbox' => ['checkbox', null],
            'checkbox list' => ['checkbox_list', []],
        ];
    }

    public function testRejectsInvalidMoneyAndOverlongText(): void
    {
        try {
            $this->codec->encode($this->field('money'), '-1');
            self::fail('Negative money value was accepted.');
        } catch (DomainException) {
        }

        $this->expectException(DomainException::class);
        $this->codec->encode($this->field('text'), str_repeat('x', 1001));
    }

    /** @param list<string> $options */
    private function field(string $type, array $options = [], bool $required = false): CustomFieldRecord
    {
        return new CustomFieldRecord(
            id: 10,
            userId: 1,
            name: 'Field',
            type: $type,
            options: $options,
            isRequired: $required,
            sortOrder: 1,
        );
    }
}
