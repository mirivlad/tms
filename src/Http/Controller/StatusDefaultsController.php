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

        $created = $this->bootstrap->restoreMissingStatuses($userId);
        $_SESSION['metadata_notice'] = $created > 0
            ? ['key' => 'metadata.statuses.restore_created', 'count' => $created]
            : ['key' => 'metadata.statuses.restore_none', 'count' => 0];

        return $response->withHeader('Location', '/metadata#statuses')->withStatus(302);
    }
}
