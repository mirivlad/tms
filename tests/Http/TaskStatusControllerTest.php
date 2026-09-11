<?php

declare(strict_types=1);

namespace Tms\Tests\Http;

use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\Http\Controller\TaskStatusController;
use Tms\I18n\Translator;
use Tms\Security\SessionIdRegenerator;
use Tms\Security\SessionManager;

final class TaskStatusControllerTest extends TestCase
{
    private PDO $db;
    private TaskStatusController $controller;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE statuses (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            color TEXT NOT NULL DEFAULT "#6b7280",
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_default INTEGER NOT NULL DEFAULT 0,
            is_completion INTEGER NOT NULL DEFAULT 0,
            show_on_board INTEGER NOT NULL DEFAULT 1
        )');
        $this->db->exec('CREATE TABLE tasks (
            id INTEGER PRIMARY KEY,
            created_by INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            deadline TEXT NULL,
            status_id INTEGER NULL,
            type_id INTEGER NULL,
            priority INTEGER NOT NULL DEFAULT 0,
            customer_id INTEGER NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $this->db->exec("INSERT INTO statuses (id,user_id,name) VALUES (10,1,'Inbox'),(11,1,'Doing'),(20,2,'Foreign')");
        $this->db->exec("INSERT INTO tasks (id,created_by,title,status_id,created_at,updated_at) VALUES
            (100,1,'Owned',10,'2026-09-01 00:00:00','2026-09-01 00:00:00'),
            (200,2,'Foreign',20,'2026-09-01 00:00:00','2026-09-01 00:00:00')");

        $_SESSION = [
            'logged_in' => true,
            'user_id' => 1,
            'username' => 'tester',
            'role' => 'user',
        ];

        $regenerator = new class implements SessionIdRegenerator {
            public function regenerate(): void
            {
            }
        };
        $sessions = new SessionManager($regenerator);
        $this->controller = new TaskStatusController(
            $sessions,
            new TaskRepository($this->db),
            new StatusRepository($this->db),
            new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'en'),
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testJsonRejectsForeignStatusWithoutMutation(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks/100/status')
            ->withHeader('Accept', 'application/json')
            ->withParsedBody(['status_id' => '20']);
        $response = $this->controller->move($request, (new ResponseFactory())->createResponse(), ['id' => '100']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(10, $this->statusOf(100));
    }

    public function testJsonReturnsNotFoundForAnotherUsersTask(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks/200/status')
            ->withHeader('Accept', 'application/json')
            ->withParsedBody(['status_id' => '11']);
        $response = $this->controller->move($request, (new ResponseFactory())->createResponse(), ['id' => '200']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(20, $this->statusOf(200));
    }

    public function testJsonSameStatusIsIdempotent(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks/100/status')
            ->withHeader('Accept', 'application/json')
            ->withParsedBody(['status_id' => '10']);
        $response = $this->controller->move($request, (new ResponseFactory())->createResponse(), ['id' => '100']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
    }

    public function testHtmlFallbackMovesAndRedirects(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks/100/status')
            ->withParsedBody(['status_id' => '11']);
        $response = $this->controller->move($request, (new ResponseFactory())->createResponse(), ['id' => '100']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/board', $response->getHeaderLine('Location'));
        self::assertSame(11, $this->statusOf(100));
    }

    private function statusOf(int $taskId): int
    {
        $stmt = $this->db->prepare('SELECT status_id FROM tasks WHERE id = :id');
        $stmt->execute(['id' => $taskId]);
        return (int) $stmt->fetchColumn();
    }
}
