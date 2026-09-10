<?php

declare(strict_types=1);

namespace Tms\Http\Middleware;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tms\Domain\User\UserRepository;
use Tms\Http\CookiePolicy;
use Tms\Security\PersistentLoginService;
use Tms\Security\SessionManager;

final class PersistentLoginMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly PersistentLoginService $persistentLogin,
        private readonly UserRepository $users,
        private readonly CookiePolicy $cookiePolicy,
        private readonly DateInterval $rememberLifetime,
        private readonly string $rememberCookieName,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->sessions->isAuthenticated()) {
            return $handler->handle($request);
        }

        $cookie = $_COOKIE[$this->rememberCookieName] ?? null;
        if (!is_string($cookie) || $cookie === '') {
            return $handler->handle($request);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $result = $this->persistentLogin->consume($cookie, $now);
        if ($result === null) {
            setcookie($this->rememberCookieName, '', $this->cookiePolicy->expired());
            return $handler->handle($request);
        }

        $user = $this->users->findById($result->userId);
        if ($user === null || !$user->isActive || !$user->isApproved) {
            $this->persistentLogin->revokeAllForUser($result->userId);
            setcookie($this->rememberCookieName, '', $this->cookiePolicy->expired());
            return $handler->handle($request);
        }

        $this->sessions->establish($user);
        setcookie(
            $this->rememberCookieName,
            $result->rotatedToken->cookieValue(),
            $this->cookiePolicy->persistent($now->add($this->rememberLifetime)),
        );

        return $handler->handle($request);
    }
}
