<?php

declare(strict_types=1);

namespace Tms\Tests\Security;

use PHPUnit\Framework\TestCase;
use Tms\Security\TaskDescriptionSanitizer;

final class TaskDescriptionSanitizerTest extends TestCase
{
    private TaskDescriptionSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new TaskDescriptionSanitizer();
    }

    public function testKeepsSupportedFormattingAndSafeLinks(): void
    {
        $html = '<h2>Heading</h2><p>Hello <strong>world</strong>.</p>'
            . '<ul><li>One</li><li><em>Two</em></li></ul>'
            . '<p><a href="https://t.me/example" title="Channel">Telegram</a></p>';

        $result = $this->sanitizer->sanitize($html);

        self::assertStringContainsString('<h2>Heading</h2>', $result);
        self::assertStringContainsString('<strong>world</strong>', $result);
        self::assertStringContainsString('<ul><li>One</li><li><em>Two</em></li></ul>', $result);
        self::assertStringContainsString('href="https://t.me/example"', $result);
        self::assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    public function testDropsExecutableElementsAndEventHandlers(): void
    {
        $html = '<p onclick="alert(1)">safe<script>alert(2)</script>'
            . '<img src=x onerror="alert(3)"><span onmouseover="alert(4)">text</span></p>'
            . '<svg onload="alert(5)"><circle></circle></svg>';

        $result = $this->sanitizer->sanitize($html);

        self::assertSame('<p>safetext</p>', $result);
        self::assertStringNotContainsString('alert', $result);
        self::assertStringNotContainsString('onerror', $result);
        self::assertStringNotContainsString('<svg', $result);
    }

    public function testRejectsJavascriptAndDataLinks(): void
    {
        $html = '<p><a href="javascript:alert(1)">bad</a> '
            . '<a href="data:text/html;base64,AAAA">also bad</a> '
            . '<a href="mailto:test@example.com">mail</a></p>';

        $result = $this->sanitizer->sanitize($html);

        self::assertStringNotContainsString('javascript:', $result);
        self::assertStringNotContainsString('data:', $result);
        self::assertStringContainsString('<a>bad</a>', $result);
        self::assertStringContainsString('<a>also bad</a>', $result);
        self::assertStringContainsString('href="mailto:test@example.com"', $result);
    }

    public function testUnknownPresentationWrappersAreUnwrapped(): void
    {
        $result = $this->sanitizer->sanitize(
            '<div class="editor"><span style="color:red">Hello <b>there</b></span></div>',
        );

        self::assertSame('Hello <b>there</b>', $result);
    }

    public function testEmptyInputStaysEmpty(): void
    {
        self::assertSame('', $this->sanitizer->sanitize("  \n  "));
    }
}
