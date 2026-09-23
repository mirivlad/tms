<?php

declare(strict_types=1);

namespace Tms\Tests\Ui;

use PHPUnit\Framework\TestCase;

final class NotificationBeaconScriptTest extends TestCase
{
    public function testLayoutLoadsRealtimeAlertBeaconAndMarksDynamicTargets(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/app_layout.twig');

        self::assertStringContainsString('data-notification-beacon', $template);
        self::assertStringContainsString('data-alert-summary', $template);
        self::assertStringContainsString('data-alert-kind="notifications"', $template);
        self::assertStringContainsString('data-alert-kind="invitations"', $template);
        self::assertStringContainsString('/assets/notification-beacon.js', $template);
    }

    public function testBeaconPollsOnlyVisibleTabsAndRefreshesOnVisibilityChange(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/notification-beacon.js');

        self::assertStringContainsString("const endpoint = '/api/navigation-alerts';", $script);
        self::assertStringContainsString('const pollDelayMs = 20000;', $script);
        self::assertStringContainsString('document.hidden', $script);
        self::assertStringContainsString("document.addEventListener('visibilitychange'", $script);
        self::assertStringContainsString('window.setTimeout(refresh, pollDelayMs)', $script);
        self::assertStringNotContainsString('setInterval(', $script);
        self::assertStringContainsString("cache: 'no-store'", $script);
    }

    public function testAuthenticatedNavigationAlertRouteIsRegistered(): void
    {
        $factory = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/ApplicationFactory.php');

        self::assertStringContainsString(
            "\$app->get('/api/navigation-alerts', [\$navigationAlertController, 'counts'])->add(\$requireAuth);",
            $factory
        );
    }
}
