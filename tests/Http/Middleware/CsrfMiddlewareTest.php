<?php

declare(strict_types=1);

namespace Tms\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tms\Http\Middleware\CsrfMiddleware;
use Tms\I18n\Translator;

final class CsrfMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testSafeRequestCreatesTokenAndPassesItAsAttribute(): void
    {
        $middleware = new CsrfMiddleware(new ResponseFactory(), $this->translator());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');

        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return (new ResponseFactory())->createResponse(200);
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($_SESSION['_csrf_token'] ?? null);
        self::assertSame($_SESSION['_csrf_token'], $handler->request?->getAttribute('csrf_token'));
    }

    public function testMutationWithoutTokenIsRejected(): void
    {
        $middleware = new CsrfMiddleware(new ResponseFactory(), $this->translator());
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/tasks');

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('Handler must not be called for invalid CSRF requests.');
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Invalid CSRF token.', (string) $response->getBody());
    }

    public function testMutationWithSessionTokenIsAccepted(): void
    {
        $_SESSION['_csrf_token'] = str_repeat('a', 64);

        $middleware = new CsrfMiddleware(new ResponseFactory(), $this->translator());
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks')
            ->withParsedBody(['_csrf' => str_repeat('a', 64)]);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testExplicitWebhookPathCanUseItsOwnAuthenticationInsteadOfCsrf(): void
    {
        $middleware = new CsrfMiddleware(
            new ResponseFactory(),
            $this->translator(),
            ['/telegram/webhook'],
        );
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/telegram/webhook');

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(202);
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame(202, $response->getStatusCode());
    }

    private function translator(): Translator
    {
        return new Translator(dirname(__DIR__, 3) . '/resources/i18n', 'en');
    }
}
