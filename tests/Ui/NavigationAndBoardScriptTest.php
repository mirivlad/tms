<?php

declare(strict_types=1);

namespace Tms\Tests\Ui;

use PHPUnit\Framework\TestCase;

final class NavigationAndBoardScriptTest extends TestCase
{
    public function testUserMenuAlertBadgesAreConditionalAndStandaloneTopbarLinksAreGone(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/app_layout.twig');

        self::assertStringNotContainsString('class="nav-invitations', $template);
        self::assertStringContainsString('class="user-menu-alert-link" href="/notifications"', $template);
        self::assertStringContainsString('class="user-menu-alert-link" href="/invitations"', $template);
        self::assertStringContainsString('{% if notification_count > 0 %}<span class="nav-badge nav-badge-notifications"', $template);
        self::assertStringContainsString('{% if team_invitation_count > 0 %}<span class="nav-badge nav-badge-invitations"', $template);
        self::assertStringContainsString('{% if notification_count > 0 or team_invitation_count > 0 %}', $template);
    }

    public function testVerticalWheelDeltaDrivesHorizontalBoardScroll(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/board.js');

        self::assertStringContainsString(
            'const horizontalDelta = Math.abs(event.deltaX) > Math.abs(verticalDelta) ? event.deltaX : verticalDelta;',
            $script
        );
        self::assertStringContainsString('const canScrollVertically = (container, delta) => {', $script);
        self::assertStringContainsString(
            'if (!event.shiftKey && Math.abs(event.deltaX) <= Math.abs(verticalDelta) && canScrollVertically(cards, verticalDelta)) {',
            $script
        );
        self::assertStringContainsString('board.scrollLeft += horizontalDelta;', $script);
    }
    public function testDesktopKanbanAvoidsScrollSnapWhileMobileKeepsIt(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/layout-v2.css');

        self::assertSame(1, preg_match('/\.board\s*\{[^}]*grid-auto-columns:\s*300px;[^}]*\}/s', $css, $desktopBoard));
        self::assertStringNotContainsString('scroll-snap-type', (string) ($desktopBoard[0] ?? ''));
        self::assertStringContainsString('scroll-snap-type: x mandatory;', $css);
        self::assertStringContainsString('scroll-snap-align: start;', $css);
    }

}
