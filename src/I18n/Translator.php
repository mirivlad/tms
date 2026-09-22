<?php

declare(strict_types=1);

namespace Tms\I18n;

use RuntimeException;

final class Translator
{
    private const SESSION_KEY = '_locale';

    /** @var array<string, array<string, string>> */
    private array $catalogs = [];

    /**
     * @param list<string> $supportedLocales
     */
    public function __construct(
        private readonly string $catalogDirectory,
        private readonly string $defaultLocale = 'en',
        private readonly array $supportedLocales = ['en', 'ru'],
    ) {
        if ($supportedLocales === []) {
            throw new RuntimeException('At least one locale must be supported.');
        }
        if (!in_array($defaultLocale, $supportedLocales, true)) {
            throw new RuntimeException('APP_LOCALE must be one of: ' . implode(', ', $supportedLocales));
        }
    }

    public function locale(): string
    {
        $sessionLocale = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($sessionLocale) && $this->isSupported($sessionLocale)) {
            return $sessionLocale;
        }

        return $this->defaultLocale;
    }

    public function setLocale(string $locale): bool
    {
        if (!$this->isSupported($locale)) {
            return false;
        }

        $_SESSION[self::SESSION_KEY] = $locale;
        return true;
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->supportedLocales, true);
    }

    /** @return list<string> */
    public function supportedLocales(): array
    {
        return $this->supportedLocales;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function trans(string $key, array $parameters = []): string
    {
        return $this->interpolate($this->message($key) ?? $key, $parameters);
    }

    /**
     * Translate a count-dependent message.
     *
     * Catalogs may provide `.one`, `.few` and `.many` variants. Russian
     * uses all three CLDR-style integer forms; other supported locales use
     * `.one` for 1 and `.many` otherwise.
     *
     * @param array<string, scalar|null> $parameters
     */
    public function transPlural(string $key, int $count, array $parameters = []): string
    {
        $form = $this->pluralForm($count, $this->locale());
        $candidateKeys = [$key . '.' . $form];

        if ($form === 'few') {
            $candidateKeys[] = $key . '.many';
        }
        $candidateKeys[] = $key;

        $message = null;
        foreach ($candidateKeys as $candidateKey) {
            $message = $this->message($candidateKey);
            if ($message !== null) {
                break;
            }
        }

        $parameters = ['count' => $count] + $parameters;
        return $this->interpolate($message ?? $key, $parameters);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function interpolate(string $message, array $parameters): string
    {
        if ($parameters === []) {
            return $message;
        }

        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements['{' . $name . '}'] = $value === null ? '' : (string) $value;
        }

        return strtr($message, $replacements);
    }

    private function message(string $key): ?string
    {
        $locale = $this->locale();
        return $this->catalog($locale)[$key]
            ?? $this->catalog($this->defaultLocale)[$key]
            ?? ($this->isSupported('en') ? ($this->catalog('en')[$key] ?? null) : null);
    }

    private function pluralForm(int $count, string $locale): string
    {
        $number = abs($count);
        if ($locale === 'ru') {
            $mod100 = $number % 100;
            if ($mod100 >= 11 && $mod100 <= 14) {
                return 'many';
            }

            return match ($number % 10) {
                1 => 'one',
                2, 3, 4 => 'few',
                default => 'many',
            };
        }

        return $number === 1 ? 'one' : 'many';
    }

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        if (isset($this->catalogs[$locale])) {
            return $this->catalogs[$locale];
        }

        $root = rtrim($this->catalogDirectory, '/\\');
        $baseFile = $root . DIRECTORY_SEPARATOR . $locale . '.php';
        if (!is_file($baseFile)) {
            throw new RuntimeException('Translation catalog not found for locale: ' . $locale);
        }

        $catalog = $this->loadCatalogFile($baseFile, $locale);
        $fragmentDirectory = $root . DIRECTORY_SEPARATOR . $locale;
        if (is_dir($fragmentDirectory)) {
            $fragmentFiles = glob($fragmentDirectory . DIRECTORY_SEPARATOR . '*.php') ?: [];
            sort($fragmentFiles, SORT_STRING);
            foreach ($fragmentFiles as $fragmentFile) {
                foreach ($this->loadCatalogFile($fragmentFile, $locale) as $key => $value) {
                    if (array_key_exists($key, $catalog)) {
                        throw new RuntimeException(sprintf(
                            'Duplicate translation key "%s" in locale %s.',
                            $key,
                            $locale,
                        ));
                    }
                    $catalog[$key] = $value;
                }
            }
        }

        $this->catalogs[$locale] = $catalog;
        return $catalog;
    }

    /** @return array<string, string> */
    private function loadCatalogFile(string $file, string $locale): array
    {
        $catalog = require $file;
        if (!is_array($catalog)) {
            throw new RuntimeException('Translation catalog must return an array: ' . $file);
        }

        $validated = [];
        foreach ($catalog as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new RuntimeException('Translation catalog entries must be string => string: ' . $locale);
            }
            if (trim($value) === '') {
                throw new RuntimeException('Translation catalog values cannot be empty: ' . $key);
            }
            $validated[$key] = $value;
        }

        return $validated;
    }
}
