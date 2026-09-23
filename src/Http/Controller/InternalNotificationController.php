<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Notification\InternalNotificationRepository;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class InternalNotificationController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly InternalNotificationRepository $notifications,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $notice = $_SESSION['_internal_notification_notice'] ?? null;
        unset($_SESSION['_internal_notification_notice']);
        return $this->view->render($response, 'notifications/inbox.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'notifications' => $this->notifications->listForUser($this->userId()),
            'notification_notice' => is_string($notice) ? $notice : null,
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

        $userId = $this->userId();
        $this->notifications->markReadForUser($userId, $id);
        if (!$this->targetAvailable($userId, $notification->targetUrl, $notification->projectId, $notification->taskId)) {
            $_SESSION['_internal_notification_notice'] = $this->translator->trans('notifications.context_unavailable');
            return $response->withHeader('Location', '/notifications')->withStatus(302);
        }
        return $response->withHeader('Location', $notification->targetUrl)->withStatus(302);
    }

    public function markAllRead(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->notifications->markAllReadForUser($this->userId());
        return $response->withHeader('Location', '/notifications')->withStatus(302);
    }

    private function targetAvailable(
        int $userId,
        string $targetUrl,
        ?int $projectId,
        ?int $taskId,
    ): bool {
        if ($targetUrl === '/tasks' || $targetUrl === '/calendar') {
            return true;
        }
        if (str_starts_with($targetUrl, '/tasks/')) {
            return $taskId !== null && $this->tasks->findForUser($userId, $taskId) !== null;
        }
        if (str_starts_with($targetUrl, '/projects/')) {
            return $projectId !== null && $this->projects->findForUser($userId, $projectId) !== null;
        }
        return false;
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
