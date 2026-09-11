<?php

declare(strict_types=1);

namespace Tms\Domain\CustomField;

use DomainException;
use JsonException;

final class CustomFieldValueCodec
{
    public function encode(CustomFieldRecord $field, mixed $input): ?string
    {
        return match ($field->type) {
            'text' => $this->encodeText($field, $input, 1000),
            'textarea' => $this->encodeText($field, $input, 20000),
            'select' => $this->encodeSelect($field, $input),
            'money' => $this->encodeMoney($field, $input),
            'checkbox' => $this->encodeCheckbox($field, $input),
            'checkbox_list' => $this->encodeCheckboxList($field, $input),
            default => throw new DomainException('Unsupported custom field type.'),
        };
    }

    public function display(CustomFieldRecord $field, ?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        return match ($field->type) {
            'checkbox' => $stored === '1' ? '1' : '0',
            'checkbox_list' => implode(', ', $this->decodeList($stored)),
            default => $stored,
        };
    }

    /** @return list<string> */
    public function selectedOptions(CustomFieldRecord $field, ?string $stored): array
    {
        if ($field->type !== 'checkbox_list' || $stored === null || $stored === '') {
            return [];
        }
        return $this->decodeList($stored);
    }

    private function encodeText(CustomFieldRecord $field, mixed $input, int $maxLength): ?string
    {
        $value = is_scalar($input) ? trim((string) $input) : '';
        if ($value === '') {
            return $this->missing($field);
        }
        if (mb_strlen($value) > $maxLength) {
            throw new DomainException('Custom field value is too long.');
        }
        return $value;
    }

    private function encodeSelect(CustomFieldRecord $field, mixed $input): ?string
    {
        $value = is_scalar($input) ? trim((string) $input) : '';
        if ($value === '') {
            return $this->missing($field);
        }
        if (!in_array($value, $field->options, true)) {
            throw new DomainException('Custom field contains an unavailable option.');
        }
        return $value;
    }

    private function encodeMoney(CustomFieldRecord $field, mixed $input): ?string
    {
        $value = is_scalar($input) ? trim((string) $input) : '';
        if ($value === '') {
            return $this->missing($field);
        }

        $value = str_replace([' ', ','], ['', '.'], $value);
        if (preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new DomainException('Money value must be a non-negative amount with at most two decimals.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        if ($whole === '') {
            $whole = '0';
        }
        return $fraction === '' ? $whole : $whole . '.' . str_pad($fraction, 2, '0');
    }

    private function encodeCheckbox(CustomFieldRecord $field, mixed $input): string
    {
        $checked = in_array($input, [1, '1', true, 'true', 'on', 'yes'], true);
        if ($field->isRequired && !$checked) {
            throw new DomainException('Required checkbox must be checked.');
        }
        return $checked ? '1' : '0';
    }

    private function encodeCheckboxList(CustomFieldRecord $field, mixed $input): ?string
    {
        $values = is_array($input) ? $input : [];
        $selected = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if (!in_array($value, $field->options, true)) {
                throw new DomainException('Custom field contains an unavailable option.');
            }
            if (!in_array($value, $selected, true)) {
                $selected[] = $value;
            }
        }

        if ($selected === []) {
            return $this->missing($field);
        }

        return json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function missing(CustomFieldRecord $field): ?string
    {
        if ($field->isRequired) {
            throw new DomainException('A required custom field is empty.');
        }
        return null;
    }

    /** @return list<string> */
    private function decodeList(string $stored): array
    {
        try {
            $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $values = [];
        foreach ($decoded as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }
        return $values;
    }
}
