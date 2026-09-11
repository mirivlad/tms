<?php

declare(strict_types=1);

namespace Tms\Tests\I18n;

use PHPUnit\Framework\TestCase;
use Tms\I18n\Translator;

final class TranslatorTest extends TestCase
{
    private string $catalogDirectory;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->catalogDirectory = dirname(__DIR__, 2) . '/resources/i18n';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testRussianAndEnglishCatalogsHaveExactlyTheSameKeys(): void
    {
        $english = $this->loadCompleteCatalog('en');
        $russian = $this->loadCompleteCatalog('ru');

        $englishKeys = array_keys($english);
        $russianKeys = array_keys($russian);
        sort($englishKeys);
        sort($russianKeys);

        self::assertSame($englishKeys, $russianKeys);
        foreach ($english as $key => $value) {
            self::assertIsString($key);
            self::assertIsString($value);
            self::assertNotSame('', trim($value), 'Empty English translation: ' . $key);
        }
        foreach ($russian as $key => $value) {
            self::assertIsString($key);
            self::assertIsString($value);
            self::assertNotSame('', trim($value), 'Empty Russian translation: ' . $key);
        }
    }

    public function testSessionLocaleOverridesApplicationDefault(): void
    {
        $translator = new Translator($this->catalogDirectory, 'en');

        self::assertSame('Sign in', $translator->trans('auth.title'));
        self::assertSame('Custom fields', $translator->trans('custom_fields.title'));
        self::assertTrue($translator->setLocale('ru'));
        self::assertSame('ru', $translator->locale());
        self::assertSame('Вход', $translator->trans('auth.title'));
        self::assertSame('Дополнительные поля', $translator->trans('custom_fields.title'));
    }

    public function testInvalidLocaleIsRejectedWithoutChangingCurrentLocale(): void
    {
        $translator = new Translator($this->catalogDirectory, 'ru');

        self::assertFalse($translator->setLocale('de'));
        self::assertSame('ru', $translator->locale());
    }

    public function testInterpolationAndMissingKeyFallback(): void
    {
        $translator = new Translator($this->catalogDirectory, 'en');

        self::assertSame('Calendar for September 2026', $translator->trans('calendar.aria', ['month' => 'September 2026']));
        self::assertSame('Invalid value for custom field: Cost.', $translator->trans(
            'validation.custom_field_value_invalid',
            ['field' => 'Cost'],
        ));
        self::assertSame('missing.translation.key', $translator->trans('missing.translation.key'));
    }

    /** @return array<string, string> */
    private function loadCompleteCatalog(string $locale): array
    {
        $catalog = require $this->catalogDirectory . '/' . $locale . '.php';
        self::assertIsArray($catalog);

        $fragmentDirectory = $this->catalogDirectory . '/' . $locale;
        $fragmentFiles = glob($fragmentDirectory . '/*.php') ?: [];
        sort($fragmentFiles, SORT_STRING);
        foreach ($fragmentFiles as $fragmentFile) {
            $fragment = require $fragmentFile;
            self::assertIsArray($fragment);
            foreach ($fragment as $key => $value) {
                self::assertArrayNotHasKey($key, $catalog, 'Duplicate translation key: ' . $key);
                $catalog[$key] = $value;
            }
        }

        return $catalog;
    }
}
