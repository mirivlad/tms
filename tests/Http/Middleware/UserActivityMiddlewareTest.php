<?php

declare(strict_types=1);

namespace Tms\Tests\Http\Middleware;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tms\Domain\User\UserRepository;
use Tms\Http\Middleware\UserActivityMiddleware;
use Tms\Security\SessionIdRegenerator;
use Tms\Security\SessionManager;

final class UserActivityMiddlewareTest extends TestCase
{
    private PDO $db;
    private UserRepository $users;
    private SessionManager $sessions;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, password_hash TEXT, role TEXT, is_active INTEGER, email_verified_at TEXT NULL, approved_at TEXT NULL, last_activity_at TEXT NULL, created_at TEXT, updated_at TEXT)');
        $this->db->exec("INSERT INTO users VALUES (1,'admin','admin@example.test','hash','admin',1,'2026-01-01','2026-01-01',NULL,'2026-01-01','2026-01-01'),(2,'user','user@example.test','hash','user',1,'2026-01-01','2026-01-01',NULL,'2026-01-01','2026-01-01')");
        $this->users = new UserRepository($this->db);
        $this->sessions = new SessionManager(new class implements SessionIdRegenerator {
            public function regenerate(): void {}
        });
    }

    public function testAuthenticatedActivityIsRecordedAndThrottled(): void
    {
        $user = $this->users->findById(2);
        self::assertNotNull($user);
        $this->sessions->establish($user);
        $middleware = new UserActivityMiddleware($this->sessions, $this->users, 60);

        $middleware->process($this->request(), $this->handler());
        $first = $this->users->findById(2)?->lastActivityAt;
        self::assertNotNull($first);

        $this->db->exec("UPDATE users SET last_activity_at='2000-01-01 00:00:00' WHERE id=2");
        $middleware->process($this->request(), $this->handler());
        self::assertSame('2000-01-01 00:00:00', $this->users->findById(2)?->lastActivityAt);
    }

    public function testImpersonationRecordsAdministratorNotTargetUser(): void
    {
        $admin = $this->users->findById(1);
        $user = $this->users->findById(2);
        self::assertNotNull($admin);
        self::assertNotNull($user);
        $this->sessions->establish($admin);
        self::assertTrue($this->sessions->startImpersonation($user));

        (new UserActivityMiddleware($this->sessions, $this->users, 60))->process($this->request(), $this->handler());

        self::assertNotNull($this->users->findById(1)?->lastActivityAt);
        self::assertNull($this->users->findById(2)?->lastActivityAt);
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/dashboard');
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }
}
