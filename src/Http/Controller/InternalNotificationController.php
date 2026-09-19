<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Notification\InternalNotificationRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class InternalNotificationController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly InternalNotificationRepository $notifications,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'notifications/inbox.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'notifications' => $this->notifications->listForUser($this->userId()),
        ]);
    }

    /** @param array<string, string> $args */
    public function open(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $id = $this->id($args);
        $notification = $this->notifications->findForUser($this->userId(), $id);
        if ($notification === null) {
            $response->getBody()->write($this->translator->trans('notifications.inbox_not_found'));
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $this->notifications->markReadForUser($this->userId(), $id);
        return $response->withHeader('Location', $notification->targetUrl)->withStatus(302);
    }

    public function markAllRead(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->notifications->markAllReadForUser($this->userId());
        return $response->withHeader('Location', '/notifications')->withStatus(302);
    }

    /** @param array<string, string> $args */
    private function id(array $args): int
    {
        $value = $args['id'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function userId(): int
    {
        return $this->sessions->currentUserId() ?? 0;
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }
}
