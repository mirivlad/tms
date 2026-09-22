<?php

declare(strict_types=1);

namespace Tms\Tests\Ui;

use PHPUnit\Framework\TestCase;

final class TaskFormScriptTest extends TestCase
{
    public function testProjectReloadBlocksSubmissionWithoutDisablingProjectValue(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/task-form.js');

        self::assertStringContainsString("taskForm.addEventListener('submit'", $script);
        self::assertStringContainsString('if (isLoading) event.preventDefault();', $script);
        self::assertStringContainsString('button.disabled = loading;', $script);
        self::assertStringNotContainsString('projectSelect.disabled = true;', $script);
    }
}
