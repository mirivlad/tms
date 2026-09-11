<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Application\UserBootstrapService;
use Tms\Security\SessionManager;

final class StatusDefaultsController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly UserBootstrapService $bootstrap,
    ) {
    }

    public function restore(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->sessions->currentUserId();
        if ($userId === null) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $this->bootstrap->restoreMissingStatuses($userId);
        return $response->withHeader('Location', '/metadata#statuses')->withStatus(302);
    }
}
