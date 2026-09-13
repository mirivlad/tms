<?php

declare(strict_types=1);

namespace Tms\Tests\Ui;

use PHPUnit\Framework\TestCase;

final class ThemeStylesheetTest extends TestCase
{
    private const THEMES = ['graphite', 'midnight', 'warm-dark', 'paper', 'frost'];

    public function testPublishedThemesKeepReadableCoreContrast(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/themes.css');
        $base = $this->variables($this->block($css, ':root'));

        foreach (self::THEMES as $theme) {
            $variables = $base;
            if ($theme !== 'graphite') {
                $variables = array_replace($variables, $this->variables($this->block($css, 'body[data-theme="' . $theme . '"]')));
            }
            foreach ([
                ['text', 'surface'], ['muted', 'surface'], ['primary-contrast', 'primary'],
                ['success-fg', 'success-bg'], ['danger-fg', 'danger-bg'], ['warning-fg', 'warning-bg'],
                ['priority-medium-fg', 'priority-medium-bg'], ['priority-high-fg', 'priority-high-bg'],
                ['priority-urgent-fg', 'priority-urgent-bg'],
            ] as [$foreground, $background]) {
                self::assertGreaterThanOrEqual(
                    4.5,
                    $this->contrast($variables[$foreground] ?? '', $variables[$background] ?? ''),
                    $theme . ': ' . $foreground . ' on ' . $background,
                );
            }
        }
    }

    public function testComponentStylesUseThemeTokensInsteadOfRawColors(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/public/assets/*.css') ?: [] as $file) {
            if (basename($file) === 'themes.css') {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $lineNumber => $line) {
                if (basename($file) === 'app.css' && str_contains($line, '.theme-option-')) {
                    continue; // Theme swatches intentionally display literal palette samples.
                }
                self::assertDoesNotMatchRegularExpression(
                    '/#[0-9a-f]{3,8}\b|rgba?\s*\(/i',
                    $line,
                    basename($file) . ':' . ($lineNumber + 1) . ' bypasses theme tokens',
                );
            }
        }
    }

    /** @return array<string, string> */
    private function variables(string $block): array
    {
        preg_match_all('/--([a-z0-9-]+)\s*:\s*(#[0-9a-f]{6})/i', $block, $matches, PREG_SET_ORDER);
        $result = [];
        foreach ($matches as $match) {
            $result[$match[1]] = strtolower($match[2]);
        }
        return $result;
    }

    private function block(string $css, string $selector): string
    {
        $pattern = '/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/s';
        self::assertSame(1, preg_match($pattern, $css, $match), 'Missing theme selector: ' . $selector);
        return (string) ($match[1] ?? '');
    }

    private function contrast(string $foreground, string $background): float
    {
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $foreground);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $background);
        $first = $this->luminance($foreground);
        $second = $this->luminance($background);
        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
