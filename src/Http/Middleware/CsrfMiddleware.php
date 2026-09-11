<?php

declare(strict_types=1);

namespace Tms\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tms\I18n\Translator;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const SESSION_KEY = '_csrf_token';

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly Translator $translator,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->token();

        if ($this->requiresValidation($request) && !$this->isValid($request, $token)) {
            $response = $this->responseFactory->createResponse(403);
            $response->getBody()->write($this->translator->trans('validation.invalid_csrf'));

            return $response;
        }

        return $handler->handle($request->withAttribute('csrf_token', $token));
    }

    private function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    private function requiresValidation(ServerRequestInterface $request): bool
    {
        return in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function isValid(ServerRequestInterface $request, string $expected): bool
    {
        $provided = $request->getHeaderLine('X-CSRF-Token');

        if ($provided === '') {
            $body = $request->getParsedBody();
            if (is_array($body) && isset($body['_csrf']) && is_string($body['_csrf'])) {
                $provided = $body['_csrf'];
            }
        }

        return $provided !== '' && hash_equals($expected, $provided);
    }
}
