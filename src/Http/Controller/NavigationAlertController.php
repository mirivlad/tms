<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\Notification\InternalNotificationRepository;
use Tms\Domain\Team\TeamInvitationRepository;
use Tms\Security\SessionManager;

final class NavigationAlertController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly InternalNotificationRepository $notifications,
        private readonly TeamInvitationRepository $invitations,
    ) {
    }

    public function counts(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->sessions->currentUserId() ?? 0;
        $payload = [
            'notification_count' => $this->notifications->countUnreadForUser($userId),
            'team_invitation_count' => $this->invitations->countPendingForUser($userId),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($json === false ? '{}' : $json);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withStatus(200);
    }
}
