<?php

declare(strict_types=1);

namespace Tms\Tests\Infrastructure;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tms\Infrastructure\ChimePortPushBridge;

final class ChimePortPushBridgeTest extends TestCase
{
    public function testUserIsMappedByIdViaOneTimePkceTicketNotPassword(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"public_key":"vapid-sample"}'),
            new Response(201, [], '{"ticket":"cp_k_example"}'),
            new Response(200, [], '{"access_token":"cp_e_scoped"}'),
            new Response(200, [], '{"subscribed":true}'),
        ]));
        $handler->push(Middleware::history($history));
        $bridge = new ChimePortPushBridge(
            new Client(['handler' => $handler]), 'https://chime.example.org', 'cp_a_secret',
        );
        self::assertSame('vapid-sample', $bridge->publicKey());
        $bridge->subscribe(42, str_repeat('a', 32), [
            'endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/abc',
            'keys' => ['p256dh' => 'public', 'auth' => 'auth'],
        ]);

        self::assertCount(4, $history);
        $ticket = json_decode((string) $history[1]['request']->getBody(), true);
        $exchange = json_decode((string) $history[2]['request']->getBody(), true);
        self::assertSame('42', $ticket['recipient']);
        self::assertSame(str_repeat('a',32), $ticket['device_id']);
        self::assertArrayNotHasKey('password', $ticket);
        $verifier = $exchange['code_verifier'];
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        self::assertSame($challenge, $ticket['code_challenge']);
        self::assertSame('Bearer cp_a_secret', $history[1]['request']->getHeaderLine('Authorization'));
        self::assertSame('', $history[2]['request']->getHeaderLine('Authorization'));
        self::assertSame('Bearer cp_e_scoped', $history[3]['request']->getHeaderLine('Authorization'));
    }

    public function testHttpTargetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChimePortPushBridge(new Client(), 'http://insecure.example.org', 'cp_a_secret');
    }
}
