<?php

declare(strict_types=1);

namespace Tms\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class RequireAdminMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly Translator $translator,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->sessions->currentRole() !== 'admin') {
            $response = $this->responseFactory->createResponse(403);
            $response->getBody()->write($this->translator->trans('validation.admin_required'));
            return $response;
        }
        return $handler->handle($request);
    }
}
