<?php

declare(strict_types=1);

namespace Tms\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tms\Domain\User\UserRepository;
use Tms\Security\SessionManager;

final class RequireAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly UserRepository $users,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->sessions->isAuthenticated()) {
            return $this->loginRedirect();
        }

        $userId = $this->sessions->currentUserId();
        $user = $userId === null ? null : $this->users->findById($userId);
        if ($user === null || !$user->isActive || !$user->isApproved) {
            $this->sessions->clear();
            return $this->loginRedirect();
        }

        $this->sessions->refreshIdentity($user);
        return $handler->handle($request);
    }

    private function loginRedirect(): ResponseInterface
    {
        $response = new \Slim\Psr7\Response();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
