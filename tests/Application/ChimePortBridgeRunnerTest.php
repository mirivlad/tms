<?php

declare(strict_types=1);

namespace Tms\Tests\Application;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Application\ChimePortBridgeRunner;

final class ChimePortBridgeRunnerTest extends TestCase
{
    public function testOnlyNewInboxMessagesAreForwardedToIsolatedRecipientsAndFailuresRetry(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE chimeport_bridge_state (id INTEGER PRIMARY KEY, last_id INTEGER NOT NULL)');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, is_active INTEGER, approved_at TEXT)');
        $db->exec('CREATE TABLE internal_notifications (
            id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL,
            actor_username TEXT, notification_type TEXT,
            context_label TEXT, body_preview TEXT, target_url TEXT
        )');
        $db->exec("INSERT INTO users(id,is_active,approved_at) VALUES
            (41,1,'2026-01-01'),(42,1,'2026-01-01'),(43,0,NULL)");
        $db->exec("INSERT INTO internal_notifications VALUES
            (1,41,'old','task_assigned','Old task','','/tasks/1/edit')");

        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(503, [], '{"error":"retry"}'),
            new Response(201, [], '{"stored":true}'),
            new Response(201, [], '{"stored":true}'),
        ]));
        $handler->push(Middleware::history($history));
        $runner = new ChimePortBridgeRunner(
            $db, new Client(['handler' => $handler]),
            'https://chime.example.org', 'cp_a_private-secret',
            'https://tasks.example.org',
        );

        self::assertSame(['attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0], $runner->run());
        $db->exec("INSERT INTO internal_notifications VALUES
            (2,41,'manager','task_assigned','Task A','','/tasks/2/edit'),
            (3,42,'manager','task_mentioned','Task B','Ping','/tasks/3/edit'),
            (4,43,'manager','task_assigned','Disabled user','','/tasks/4/edit')");

        self::assertSame(['attempted' => 1, 'sent' => 0, 'failed' => 1, 'skipped' => 0], $runner->run());
        self::assertSame(1, (int) $db->query('SELECT last_id FROM chimeport_bridge_state')->fetchColumn());

        self::assertSame(['attempted' => 2, 'sent' => 2, 'failed' => 0, 'skipped' => 1], $runner->run());
        self::assertSame(4, (int) $db->query('SELECT last_id FROM chimeport_bridge_state')->fetchColumn());
        self::assertSame(3, count($history));

        $first = json_decode((string) $history[1]['request']->getBody(), true);
        $second = json_decode((string) $history[2]['request']->getBody(), true);
        self::assertSame('41', $first['recipient']);
        self::assertSame('42', $second['recipient']);
        self::assertSame('https://tasks.example.org/tasks/3/edit', $second['url']);
        self::assertSame('high', $second['priority']);
        self::assertSame('tms-inbox-2', $history[1]['request']->getHeaderLine('Idempotency-Key'));
        self::assertSame('tms-inbox-2', $history[0]['request']->getHeaderLine('Idempotency-Key'));
        self::assertSame('Bearer cp_a_private-secret', $history[1]['request']->getHeaderLine('Authorization'));
        self::assertSame(['attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0], $runner->run());
    }

    public function testInsecureDestinationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChimePortBridgeRunner(
            new PDO('sqlite::memory:'), new Client(),
            'http://untrusted.example.org', 'token', 'https://tasks.example.org',
        );
    }
}
