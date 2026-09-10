<?php

declare(strict_types=1);

namespace Tms\Tests\Security;

use PHPUnit\Framework\TestCase;
use Tms\Domain\User\UserRecord;
use Tms\Security\SessionIdRegenerator;
use Tms\Security\SessionManager;

final class SessionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['attacker_controlled' => 'must disappear', '_csrf_token' => 'old'];
    }

    public function testEstablishClearsOldStateAndRotatesSessionId(): void
    {
        $regenerator = new RecordingSessionIdRegenerator();
        $manager = new SessionManager($regenerator);
        $user = new UserRecord(7, 'alice', 'alice@example.test', 'hash', 'user', true, true);

        $manager->establish($user);

        self::assertSame(1, $regenerator->calls);
        self::assertArrayNotHasKey('attacker_controlled', $_SESSION);
        self::assertArrayNotHasKey('_csrf_token', $_SESSION);
        self::assertSame(7, $_SESSION['user_id']);
        self::assertSame('alice', $_SESSION['username']);
        self::assertSame('user', $_SESSION['role']);
        self::assertTrue($_SESSION['logged_in']);
    }

    public function testClearRemovesIdentityAndRotatesAgain(): void
    {
        $regenerator = new RecordingSessionIdRegenerator();
        $manager = new SessionManager($regenerator);
        $user = new UserRecord(7, 'alice', 'alice@example.test', 'hash', 'user', true, true);

        $manager->establish($user);
        $manager->clear();

        self::assertSame(2, $regenerator->calls);
        self::assertSame([], $_SESSION);
    }
}

final class RecordingSessionIdRegenerator implements SessionIdRegenerator
{
    public int $calls = 0;

    public function regenerate(): void
    {
        ++$this->calls;
    }
}
