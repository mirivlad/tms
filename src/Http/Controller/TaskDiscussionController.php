<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Discussion\DiscussionReadRepository;
use Tms\Domain\Discussion\DiscussionRepository;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\Domain\Team\TeamRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class TaskDiscussionController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly TaskRepository $tasks,
        private readonly ProjectRepository $projects,
        private readonly TeamRepository $teams,
        private readonly DiscussionRepository $discussions,
        private readonly DiscussionReadRepository $reads,
        private readonly Translator $translator,
    ) {
    }

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->sessions->currentUserId() ?? 0;
        $taskId = $this->id($args, 'id');
        $task = $this->tasks->findForUser($userId, $taskId);
        if ($task === null || $task->projectId === null) {
            return $this->notFound($response);
        }

        $project = $this->projects->findForUser($userId, $task->projectId);
        if ($project === null || !$project->isTeamOwned() || $project->ownerTeamId === null) {
            return $this->notFound($response);
        }

        $stats = $this->reads->statsForTasks($userId, [$taskId]);
        $stat = $stats[$taskId] ?? ['count' => 0, 'unread' => 0];
        $comments = $this->discussions->listForTask($userId, $taskId);
        $this->reads->markTaskRead($userId, $taskId);

        return $this->view->render($response, 'tasks/discussion.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'task' => $task,
            'project' => $project,
            'discussion_comments' => $comments,
            'discussion_notice' => $this->consumeDiscussionNotice(),
            'discussion_base_url' => '/tasks/' . $taskId . '/discussion',
            'discussion_current_user_id' => $userId,
            'discussion_can_moderate' => $this->teams->roleForUser($userId, $project->ownerTeamId) === 'lead',
            'discussion_stat' => $stat,
        ]);
    }

    /** @param array<string, string> $args */
    private function id(array $args, string $key): int
    {
        $value = $args[$key] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    /** @return array{kind:string,message:string}|null */
    private function consumeDiscussionNotice(): ?array
    {
        $notice = $_SESSION['_discussion_notice'] ?? null;
        unset($_SESSION['_discussion_notice']);
        if (!is_array($notice)
            || !is_string($notice['kind'] ?? null)
            || !is_string($notice['message'] ?? null)) {
            return null;
        }
        return ['kind' => $notice['kind'], 'message' => $notice['message']];
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->translator->trans('discussions.unavailable'));
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }
}
