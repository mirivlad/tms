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
use Tms\Http\Middleware\RequireAuthMiddleware;
use Tms\Security\SessionIdRegenerator;
use Tms\Security\SessionManager;

final class RequireAuthMiddlewareTest extends TestCase
{
    private PDO $db;
    private UserRepository $users;
    private SessionManager $sessions;
    private RequireAuthMiddleware $middleware;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            email TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            is_active INTEGER NOT NULL,
            email_verified_at TEXT NULL,
            approved_at TEXT NULL,
            last_activity_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $this->db->exec("INSERT INTO users VALUES (
            1,'member','member@example.test','hash','user',1,
            '2026-01-01','2026-01-01',NULL,'2026-01-01','2026-01-01'
        )");

        $this->users = new UserRepository($this->db);
        $this->sessions = new SessionManager(new class implements SessionIdRegenerator {
            public function regenerate(): void {}
        });
        $this->middleware = new RequireAuthMiddleware($this->sessions, $this->users);
    }

    public function testUnauthenticatedRequestRedirectsToLogin(): void
    {
        $response = $this->middleware->process($this->request(), $this->handler());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testActiveAccountRefreshesStaleIdentityBeforeRequest(): void
    {
        $user = $this->users->findById(1);
        self::assertNotNull($user);
        $this->sessions->establish($user);

        $this->db->exec("UPDATE users SET username='renamed', role='admin' WHERE id=1");
        $response = $this->middleware->process($this->request(), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('renamed', $this->sessions->currentUsername());
        self::assertSame('admin', $this->sessions->currentRole());
    }

    public function testDisabledAccountLosesExistingSessionImmediately(): void
    {
        $user = $this->users->findById(1);
        self::assertNotNull($user);
        $this->sessions->establish($user);

        $this->db->exec('UPDATE users SET is_active=0 WHERE id=1');
        $response = $this->middleware->process($this->request(), $this->handler());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertFalse($this->sessions->isAuthenticated());
    }

    public function testDeletedOrUnapprovedAccountCannotKeepSession(): void
    {
        $user = $this->users->findById(1);
        self::assertNotNull($user);
        $this->sessions->establish($user);
        $this->db->exec('UPDATE users SET approved_at=NULL WHERE id=1');

        $response = $this->middleware->process($this->request(), $this->handler());
        self::assertSame(302, $response->getStatusCode());
        self::assertFalse($this->sessions->isAuthenticated());

        $this->db->exec("UPDATE users SET approved_at='2026-01-01' WHERE id=1");
        $user = $this->users->findById(1);
        self::assertNotNull($user);
        $this->sessions->establish($user);
        $this->db->exec('DELETE FROM users WHERE id=1');

        $response = $this->middleware->process($this->request(), $this->handler());
        self::assertSame(302, $response->getStatusCode());
        self::assertFalse($this->sessions->isAuthenticated());
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
