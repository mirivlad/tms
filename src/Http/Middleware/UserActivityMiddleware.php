<?php

declare(strict_types=1);

namespace Tms\Http\Middleware;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tms\Domain\User\UserRepository;
use Tms\Security\SessionManager;

final class UserActivityMiddleware implements MiddlewareInterface
{
    private const SESSION_KEY = '_last_activity_touch';

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly UserRepository $users,
        private readonly int $minimumIntervalSeconds = 60,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = $this->sessions->isImpersonating()
            ? $this->sessions->originalAdminId()
            : $this->sessions->currentUserId();

        if ($userId !== null && $this->shouldTouch()) {
            if ($this->users->touchActivity($userId, new DateTimeImmutable('now', new DateTimeZone('UTC')))) {
                $_SESSION[self::SESSION_KEY] = time();
            }
        }

        return $handler->handle($request);
    }

    private function shouldTouch(): bool
    {
        $lastTouch = $_SESSION[self::SESSION_KEY] ?? null;
        return !is_int($lastTouch) || $lastTouch <= time() - max(1, $this->minimumIntervalSeconds);
    }
}
