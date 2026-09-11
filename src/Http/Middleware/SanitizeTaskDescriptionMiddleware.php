<?php

declare(strict_types=1);

namespace Tms\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tms\Security\TaskDescriptionSanitizer;

final class SanitizeTaskDescriptionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly TaskDescriptionSanitizer $sanitizer)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (is_array($body) && is_string($body['description'] ?? null)) {
            $body['description'] = $this->sanitizer->sanitize((string) $body['description']);
            $request = $request->withParsedBody($body);
        }

        return $handler->handle($request);
    }
}
