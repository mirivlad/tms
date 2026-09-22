<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Activity\ActivityRepository;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\Domain\Team\TeamRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class ActivityController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly ActivityRepository $activity,
        private readonly TaskRepository $tasks,
        private readonly ProjectRepository $projects,
        private readonly TeamRepository $teams,
        private readonly Translator $translator,
    ) {
    }

    /** @param array<string, string> $args */
    public function task(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->sessions->currentUserId() ?? 0;
        $taskId = $this->id($args);
        $task = $this->tasks->findForUser($userId, $taskId);
        if ($task === null) {
            return $this->notFound($response, 'validation.task_not_found');
        }

        return $this->view->render($response, 'activity/task.twig', [
            'username' => $this->sessions->currentUsername() ?? '',
            'task' => $task,
            'events' => $this->activity->listForTask($userId, $taskId),
        ]);
    }

    /** @param array<string, string> $args */
    public function project(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->sessions->currentUserId() ?? 0;
        $projectId = $this->id($args);
        $project = $this->projects->findForUser($userId, $projectId);
        if ($project === null) {
            return $this->notFound($response, 'validation.project_not_found');
        }
        $team = $project->ownerTeamId === null
            ? null
            : $this->teams->findForMember($userId, $project->ownerTeamId);

        return $this->view->render($response, 'activity/project.twig', [
            'username' => $this->sessions->currentUsername() ?? '',
            'project' => $project,
            'project_team' => $team,
            'can_manage' => $this->projects->canManageForUser($userId, $projectId),
            'discussion_enabled' => $project->ownerTeamId !== null && $team !== null,
            'project_section' => 'activity',
            'events' => $this->activity->listForProject($userId, $projectId),
        ]);
    }

    /** @param array<string, string> $args */
    private function id(array $args): int
    {
        $value = $args['id'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function notFound(ResponseInterface $response, string $key): ResponseInterface
    {
        $response->getBody()->write($this->translator->trans($key));
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
