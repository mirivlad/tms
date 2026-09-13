<?php

declare(strict_types=1);

namespace Tms\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tms\Domain\UserPreference\UserPreferenceRepository;
use Tms\Security\SessionManager;

final class UserTimezoneMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly UserPreferenceRepository $preferences,
        private readonly string $fallbackTimezone,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $original = date_default_timezone_get();
        $userId = $this->sessions->currentUserId();
        $timezone = $userId === null ? $this->fallbackTimezone : $this->preferences->getForUser($userId)->timezone;
        date_default_timezone_set($timezone);

        try {
            return $handler->handle($request);
        } finally {
            date_default_timezone_set($original);
        }
    }
}
