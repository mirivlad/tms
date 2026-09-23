<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Application\DomainEventPublisher;
use Tms\Domain\CustomField\CustomFieldRepository;
use Tms\Domain\Discussion\DiscussionReadRepository;
use Tms\Domain\Discussion\DiscussionRepository;
use Tms\Domain\Project\ProjectAttachmentRepository;
use Tms\Domain\Project\ProjectCustomFieldRepository;
use Tms\Domain\Project\ProjectRecord;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Project\ProjectStatusRepository;
use Tms\Domain\Status\StatusRecord;
use Tms\Domain\Task\TaskRepository;
use Tms\Domain\Team\TeamRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\AttachmentStorage;
use Tms\Security\SessionManager;

final class ProjectController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly ProjectRepository $projects,
        private readonly DomainEventPublisher $events,
        private readonly ProjectAttachmentRepository $attachments,
        private readonly AttachmentStorage $storage,
        private readonly TaskRepository $tasks,
        private readonly ProjectStatusRepository $statuses,
        private readonly ProjectCustomFieldRepository $fields,
        private readonly TeamRepository $teams,
        private readonly DiscussionRepository $discussions,
        private readonly DiscussionReadRepository $discussionReads,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderIndex($request, $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $userId = $this->userId();
            $ownerScope = is_string($body['owner_scope'] ?? null)
                ? (string) $body['owner_scope']
                : 'personal';

            if (preg_match('/^team:([0-9]+)$/D', $ownerScope, $matches) === 1) {
                $projectId = $this->projects->createForTeam(
                    $userId,
                    (int) $matches[1],
                    (string) ($body['name'] ?? ''),
                    (string) ($body['description'] ?? ''),
                    (string) ($body['lifecycle_status'] ?? 'active'),
                );
            } else {
                $projectId = $this->projects->createForUser(
                    $userId,
                    (string) ($body['name'] ?? ''),
                    (string) ($body['description'] ?? ''),
                    (string) ($body['lifecycle_status'] ?? 'active'),
                );
            }
            $created = $this->projects->findForUser($userId, $projectId);
            if ($created !== null) {
                $this->events->projectCreated($userId, $created);
            }
        } catch (DomainException $error) {
            return $this->renderIndex(
                $request,
                $response,
                $this->domainMessage($error),
                422,
                $body,
                true,
            );
        }

        return $this->redirect($response, '/projects');
    }

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $project = $this->projectForUser($response, $args);
        if (!$project instanceof ProjectRecord) {
            return $project;
        }

        $userId = $this->userId();
        $statuses = $this->statuses->listForProject($userId, $project->id);
        $tasks = $this->tasks->listForProjectForUser($userId, $project->id);
        $statusMap = [];
        $statusCounts = [];
        foreach ($statuses as $status) {
            $statusMap[$status->id] = $status;
            $statusCounts[$status->id] = 0;
        }

        $completedTasks = 0;
        foreach ($tasks as $task) {
            $statusCounts[$task->statusId] = ($statusCounts[$task->statusId] ?? 0) + 1;
            $status = $statusMap[$task->statusId] ?? null;
            if ($status instanceof StatusRecord && $status->isCompletion) {
                ++$completedTasks;
            }
        }

        $discussionComments = $project->ownerTeamId !== null
            ? $this->discussions->listForProject($userId, $project->id)
            : [];
        $taskDiscussionComments = $project->ownerTeamId !== null
            ? $this->discussions->listTaskCommentsForProject($userId, $project->id)
            : [];
        $taskTitles = [];
        foreach ($tasks as $task) {
            $taskTitles[$task->id] = $task->title;
        }
        $recentDiscussion = [];
        foreach ($discussionComments as $comment) {
            if ($comment->deletedAt === null) {
                $recentDiscussion[] = ['comment' => $comment, 'task_title' => null];
            }
        }
        foreach ($taskDiscussionComments as $taskId => $comments) {
            foreach ($comments as $comment) {
                if ($comment->deletedAt === null) {
                    $recentDiscussion[] = [
                        'comment' => $comment,
                        'task_title' => $taskTitles[$taskId] ?? null,
                    ];
                }
            }
        }
        usort(
            $recentDiscussion,
            static fn (array $left, array $right): int =>
                strcmp($right['comment']->updatedAt, $left['comment']->updatedAt)
                ?: ($right['comment']->id <=> $left['comment']->id),
        );
        $recentDiscussion = array_slice($recentDiscussion, 0, 5);
        $discussionStat = $project->ownerTeamId !== null
            ? ($this->discussionReads->statsForProjects($userId, [$project->id])[$project->id] ?? ['count' => 0, 'unread' => 0])
            : ['count' => 0, 'unread' => 0];

        $statusSummary = [];
        foreach ($statuses as $status) {
            $statusSummary[] = [
                'status' => $status,
                'count' => $statusCounts[$status->id] ?? 0,
            ];
        }

        return $this->view->render($response, 'projects/show.twig', $this->baseProjectView(
            $request,
            $project,
            [
                'statuses' => $statuses,
                'status_summary' => $statusSummary,
                'custom_fields' => $this->fields->listForProject($userId, $project->id),
                'task_count' => count($tasks),
                'open_task_count' => count($tasks) - $completedTasks,
                'completed_task_count' => $completedTasks,
                'attachment_count' => count($this->attachments->listForProject($userId, $project->id)),
                'discussion_count' => $discussionStat['count'],
                'discussion_unread' => $discussionStat['unread'],
                'recent_discussion' => $recentDiscussion,
            ],
        ));
    }

    /** @param array<string, string> $args */
    public function settings(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $project = $this->manageableProject($response, $args);
        if (!$project instanceof ProjectRecord) {
            return $project;
        }

        return $this->view->render($response, 'projects/settings.twig', $this->baseProjectView(
            $request,
            $project,
            [
                'lead_teams' => $this->leadTeams(),
                'lifecycle_statuses' => ProjectRepository::LIFECYCLE_STATUSES,
                'settings_notice' => $this->consumeSessionNotice('project_settings_notice'),
            ],
        ));
    }

    /** @param array<string, string> $args */
    public function statuses(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $project = $this->manageableProject($response, $args);
        if (!$project instanceof ProjectRecord) {
            return $project;
        }

        return $this->view->render($response, 'projects/statuses.twig', $this->baseProjectView(
            $request,
            $project,
            [
                'statuses' => $this->statuses->listForProject($this->userId(), $project->id),
                'status_notice' => $this->consumeSessionNotice('project_status_notice'),
            ],
        ));
    }

    /** @param array<string, string> $args */
    public function fields(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $project = $this->manageableProject($response, $args);
        if (!$project instanceof ProjectRecord) {
            return $project;
        }

        return $this->view->render($response, 'projects/fields.twig', $this->baseProjectView(
            $request,
            $project,
            [
                'custom_fields' => $this->fields->listForProject($this->userId(), $project->id),
                'custom_field_types' => CustomFieldRepository::TYPES,
                'field_notice' => $this->consumeSessionNotice('project_field_notice'),
            ],
        ));
    }

    /** @param array<string, string> $args */
    public function files(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $project = $this->projectForUser($response, $args);
        if (!$project instanceof ProjectRecord) {
            return $project;
        }

        return $this->view->render($response, 'projects/files.twig', $this->baseProjectView(
            $request,
            $project,
            ['attachments' => $this->attachments->listForProject($this->userId(), $project->id)],
        ));
    }

    /** @param array<string, string> $args */
    public function discussion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $project = $this->projectForUser($response, $args);
        if (!$project instanceof ProjectRecord) {
            return $project;
        }
        if ($project->ownerTeamId === null) {
            $response->getBody()->write($this->translator->trans('discussions.unavailable'));
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $userId = $this->userId();
        $projectComments = $this->discussions->listForProject($userId, $project->id);
        $taskComments = $this->discussions->listTaskCommentsForProject($userId, $project->id);
        $tasks = $this->tasks->listForProjectForUser($userId, $project->id);
        $taskStats = $this->discussionReads->statsForTasks(
            $userId,
            array_map(static fn ($task): int => $task->id, $tasks),
        );
        $projectStat = $this->discussionReads->statsForProjects($userId, [$project->id])[$project->id]
            ?? ['count' => 0, 'unread' => 0];

        $taskThreads = [];
        foreach ($tasks as $task) {
            $comments = $taskComments[$task->id] ?? [];
            $stat = $taskStats[$task->id] ?? ['count' => 0, 'unread' => 0];
            if ($stat['count'] === 0) {
                continue;
            }
            $taskThreads[] = [
                'task' => $task,
                'comments' => $comments,
                'stat' => $stat,
            ];
        }

        $this->discussionReads->markProjectRead($userId, $project->id);

        return $this->view->render($response, 'projects/discussion.twig', $this->baseProjectView(
            $request,
            $project,
            [
                'discussion_comments' => $projectComments,
                'discussion_notice' => $this->consumeDiscussionNotice(),
                'discussion_base_url' => '/projects/' . $project->id . '/discussion',
                'discussion_current_user_id' => $userId,
                'discussion_can_moderate' => $this->teams->roleForUser(
                    $userId,
                    (int) $project->ownerTeamId,
                ) === 'lead',
                'discussion_project_stat' => $projectStat,
                'discussion_task_threads' => $taskThreads,
            ],
        ));
    }

    /** @param array<string, string> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $this->routeId($args);
        $project = $this->projects->findManageableForUser($this->userId(), $projectId);
        if ($project === null) {
            return $this->notFound($response);
        }

        $body = $this->body($request);
        try {
            $targetTeamId = $this->targetTeamId($body, $project);
            if ($targetTeamId !== null && $this->teams->findForLead($this->userId(), $targetTeamId) === null) {
                throw new DomainException('Only a Team Lead can assign a project to that team.');
            }

            $this->projects->updateForUser(
                $this->userId(),
                $projectId,
                (string) ($body['name'] ?? ''),
                (string) ($body['description'] ?? ''),
                (string) ($body['lifecycle_status'] ?? 'active'),
            );
            $this->projects->changeOwnershipForUser($this->userId(), $projectId, $targetTeamId);
            $updated = $this->projects->findForUser($this->userId(), $projectId);
            if ($updated !== null) {
                $this->events->projectChanged($this->userId(), $project, $updated);
            }
            $this->sessionNotice('project_settings_notice', 'success', $this->translator->trans('projects.saved'));
        } catch (DomainException $error) {
            $this->sessionNotice('project_settings_notice', 'error', $this->domainMessage($error));
        }

        return $this->redirect($response, '/projects/' . $projectId . '/settings');
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId();
        $projectId = $this->routeId($args);
        $project = $this->projects->findManageableForUser($userId, $projectId);
        if ($project === null) {
            return $this->notFound($response);
        }

        $stored = $this->attachments->listForProject($userId, $projectId);
        try {
            if (!$this->projects->deleteForUser($userId, $projectId)) {
                return $this->notFound($response);
            }
        } catch (DomainException $error) {
            $this->sessionNotice('project_settings_notice', 'error', $this->domainMessage($error));
            return $this->redirect($response, '/projects/' . $projectId . '/settings');
        }

        $this->events->projectEvent($userId, $project, 'project.deleted');

        foreach ($stored as $attachment) {
            if (!$this->storage->delete($attachment->storageName)) {
                error_log('TMS project attachment cleanup failed after project deletion: ' . $attachment->storageName);
            }
        }

        return $this->redirect($response, '/projects');
    }

    /**
     * @param array<string, mixed>|null $createForm
     */
    private function renderIndex(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?string $error = null,
        int $status = 200,
        ?array $createForm = null,
        bool $openCreateDialog = false,
    ): ResponseInterface {
        $userId = $this->userId();
        $teams = $this->teams->listForUser($userId);
        $leadTeams = [];
        $teamMap = [];
        foreach ($teams as $team) {
            $teamMap[$team->id] = $team;
            if ($team->currentUserIsLead()) {
                $leadTeams[] = $team;
            }
        }

        $projects = $this->projects->listForUser($userId);
        $discussionStats = $this->discussionReads->statsForProjects(
            $userId,
            array_map(static fn (ProjectRecord $project): int => $project->id, $projects),
        );
        $manageable = [];
        foreach ($projects as $project) {
            $manageable[$project->id] = $this->projects->canManageForUser($userId, $project->id);
        }

        if ($createForm === null) {
            $owner = $request->getQueryParams()['owner'] ?? null;
            $createForm = is_string($owner) ? ['owner_scope' => $owner] : [];
            if (is_string($owner) && $owner !== '') {
                $openCreateDialog = true;
            }
        }

        return $this->view->render($response, 'projects/index.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'projects' => $projects,
            'lead_teams' => $leadTeams,
            'team_map' => $teamMap,
            'manageable_projects' => $manageable,
            'discussion_stats' => $discussionStats,
            'lifecycle_statuses' => ProjectRepository::LIFECYCLE_STATUSES,
            'error' => $error,
            'create_form' => $createForm,
            'open_create_dialog' => $openCreateDialog,
        ])->withStatus($status);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function baseProjectView(
        ServerRequestInterface $request,
        ProjectRecord $project,
        array $extra = [],
    ): array {
        $team = $project->ownerTeamId === null
            ? null
            : $this->teams->findForMember($this->userId(), $project->ownerTeamId);

        return $extra + [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'project' => $project,
            'project_team' => $team,
            'can_manage' => $this->projects->canManageForUser($this->userId(), $project->id),
            'discussion_enabled' => $project->ownerTeamId !== null && $team !== null,
        ];
    }

    /** @param array<string, string> $args */
    private function projectForUser(ResponseInterface $response, array $args): ProjectRecord|ResponseInterface
    {
        $project = $this->projects->findForUser($this->userId(), $this->routeId($args));
        return $project ?? $this->notFound($response);
    }

    /** @param array<string, string> $args */
    private function manageableProject(ResponseInterface $response, array $args): ProjectRecord|ResponseInterface
    {
        $project = $this->projects->findManageableForUser($this->userId(), $this->routeId($args));
        return $project ?? $this->notFound($response);
    }

    /** @return list<object> */
    private function leadTeams(): array
    {
        return array_values(array_filter(
            $this->teams->listForUser($this->userId()),
            static fn ($team): bool => $team->currentUserIsLead(),
        ));
    }

    /** @param array<string, mixed> $body */
    private function targetTeamId(array $body, ProjectRecord $project): ?int
    {
        if (!array_key_exists('owner_scope', $body)) {
            return $project->ownerTeamId;
        }
        $ownerScope = is_string($body['owner_scope'] ?? null) ? (string) $body['owner_scope'] : '';
        if ($ownerScope === 'personal') {
            return null;
        }
        if (preg_match('/^team:([0-9]+)$/D', $ownerScope, $matches) !== 1) {
            throw new DomainException('Invalid project owner.');
        }
        return (int) $matches[1];
    }

    private function domainMessage(DomainException $error): string
    {
        return match ($error->getMessage()) {
            'Project name must contain 1-160 characters.' => $this->translator->trans('validation.project_name'),
            'Project description cannot exceed 20000 characters.' => $this->translator->trans('validation.project_description'),
            'Unsupported project lifecycle status.' => $this->translator->trans('validation.project_status'),
            'Only a Team Lead can create a team project.',
            'Only a Team Lead can assign a project to that team.' => $this->translator->trans('projects.team_lead_required'),
            'A team project with tasks cannot be deleted.' => $this->translator->trans('projects.team_delete_with_tasks'),
            'Invalid project owner.' => $this->translator->trans('projects.owner_invalid'),
            default => $this->translator->trans('validation.project_invalid'),
        };
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->translator->trans('validation.project_not_found'));
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    private function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /** @param array<string, string> $args */
    private function routeId(array $args): int
    {
        $value = $args['id'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function sessionNotice(string $key, string $kind, string $message): void
    {
        $_SESSION[$key] = ['kind' => $kind, 'message' => $message];
    }

    /** @return array{kind:string,message:string}|null */
    private function consumeSessionNotice(string $key): ?array
    {
        $notice = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        if (!is_array($notice)
            || !is_string($notice['kind'] ?? null)
            || !is_string($notice['message'] ?? null)) {
            return null;
        }
        return ['kind' => $notice['kind'], 'message' => $notice['message']];
    }

    /** @return array{kind:string,message:string}|null */
    private function consumeDiscussionNotice(): ?array
    {
        return $this->consumeSessionNotice('_discussion_notice');
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
