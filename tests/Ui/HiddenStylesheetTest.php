<?php

declare(strict_types=1);

namespace Tms\Tests\Ui;

use PHPUnit\Framework\TestCase;

final class HiddenStylesheetTest extends TestCase
{
    public function testHiddenAttributeAlwaysRemovesElementsFromLayout(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.css');

        self::assertMatchesRegularExpression(
            '/\[hidden\]\s*\{\s*display\s*:\s*none\s*!important\s*;?\s*\}/',
            $css,
        );
    }
}
