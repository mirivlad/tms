<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\CustomField\CustomFieldRepository;
use Tms\Domain\Discussion\DiscussionRepository;
use Tms\Domain\Project\ProjectAttachmentRepository;
use Tms\Domain\Project\ProjectCustomFieldRepository;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Project\ProjectStatusRepository;
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
        private readonly ProjectAttachmentRepository $attachments,
        private readonly AttachmentStorage $storage,
        private readonly TaskRepository $tasks,
        private readonly ProjectStatusRepository $statuses,
        private readonly ProjectCustomFieldRepository $fields,
        private readonly TeamRepository $teams,
        private readonly DiscussionRepository $discussions,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response);
    }

    /** @param array<string, string> $args */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $userId = $this->userId();
        $project = $this->projects->findForUser($userId, $this->routeId($args));
        if ($project === null) {
            $response->getBody()->write($this->translator->trans('validation.project_not_found'));
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $statusMap = [];
        foreach ($this->statuses->listForProject($userId, $project->id) as $status) {
            $statusMap[$status->id] = $status;
        }

        $statusNotice = $_SESSION['project_status_notice'] ?? null;
        unset($_SESSION['project_status_notice']);
        if (!is_array($statusNotice) || !is_string($statusNotice['message'] ?? null)) {
            $statusNotice = null;
        }
        $fieldNotice = $_SESSION['project_field_notice'] ?? null;
        unset($_SESSION['project_field_notice']);
        if (!is_array($fieldNotice) || !is_string($fieldNotice['message'] ?? null)) {
            $fieldNotice = null;
        }

        $team = $project->ownerTeamId === null
            ? null
            : $this->teams->findForMember($userId, $project->ownerTeamId);
        $discussionNotice = $this->consumeDiscussionNotice();
        $discussionEnabled = $project->ownerTeamId !== null && $team !== null;
        $assigneeMap = [];
        if ($project->ownerTeamId !== null) {
            foreach ($this->teams->listMembers($userId, $project->ownerTeamId) as $member) {
                $assigneeMap[$member->userId] = $member;
            }
        }

        return $this->view->render($response, 'projects/show.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'project' => $project,
            'can_manage' => $this->projects->canManageForUser($userId, $project->id),
            'project_team' => $team,
            'discussion_enabled' => $discussionEnabled,
            'discussion_comments' => $discussionEnabled ? $this->discussions->listForProject($userId, $project->id) : [],
            'discussion_notice' => $discussionNotice,
            'discussion_base_url' => '/projects/' . $project->id . '/discussion',
            'discussion_current_user_id' => $userId,
            'discussion_can_moderate' => $project->ownerTeamId !== null
                && $this->teams->roleForUser($userId, $project->ownerTeamId) === 'lead',
            'tasks' => $this->tasks->listForProjectForUser($userId, $project->id),
            'attachments' => $this->attachments->listForProject($userId, $project->id),
            'statuses' => array_values($statusMap),
            'status_map' => $statusMap,
            'assignee_map' => $assigneeMap,
            'status_notice' => $statusNotice,
            'custom_fields' => $this->fields->listForProject($userId, $project->id),
            'custom_field_types' => CustomFieldRepository::TYPES,
            'field_notice' => $fieldNotice,
        ]);
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
                $this->projects->createForTeam(
                    $userId,
                    (int) $matches[1],
                    (string) ($body['name'] ?? ''),
                    (string) ($body['description'] ?? ''),
                    (string) ($body['lifecycle_status'] ?? 'active'),
                );
            } else {
                $this->projects->createForUser(
                    $userId,
                    (string) ($body['name'] ?? ''),
                    (string) ($body['description'] ?? ''),
                    (string) ($body['lifecycle_status'] ?? 'active'),
                );
            }
        } catch (DomainException $error) {
            return $this->render($request, $response, $this->domainMessage($error), 422, $body);
        }

        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $projectId = $this->routeId($args);
        if ($this->projects->findManageableForUser($this->userId(), $projectId) === null) {
            return $this->render(
                $request,
                $response,
                $this->translator->trans('validation.project_not_found'),
                404,
            );
        }

        $body = $this->body($request);
        try {
            $this->projects->updateForUser(
                $this->userId(),
                $projectId,
                (string) ($body['name'] ?? ''),
                (string) ($body['description'] ?? ''),
                (string) ($body['lifecycle_status'] ?? 'active'),
            );
        } catch (DomainException $error) {
            return $this->render(
                $request,
                $response,
                $this->domainMessage($error),
                422,
                null,
                $projectId,
                $body,
            );
        }

        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $userId = $this->userId();
        $projectId = $this->routeId($args);
        if ($this->projects->findManageableForUser($userId, $projectId) === null) {
            return $this->render(
                $request,
                $response,
                $this->translator->trans('validation.project_not_found'),
                404,
            );
        }

        $stored = $this->attachments->listForProject($userId, $projectId);
        try {
            if (!$this->projects->deleteForUser($userId, $projectId)) {
                return $this->render(
                    $request,
                    $response,
                    $this->translator->trans('validation.project_not_found'),
                    404,
                );
            }
        } catch (DomainException $error) {
            return $this->render(
                $request,
                $response,
                $this->domainMessage($error),
                409,
            );
        }
        foreach ($stored as $attachment) {
            if (!$this->storage->delete($attachment->storageName)) {
                error_log('TMS project attachment cleanup failed after project deletion: ' . $attachment->storageName);
            }
        }

        return $this->redirect($response);
    }

    /**
     * @param array<string, mixed>|null $createForm
     * @param array<string, mixed>|null $editForm
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?string $error = null,
        int $status = 200,
        ?array $createForm = null,
        ?int $editProjectId = null,
        ?array $editForm = null,
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
        $manageable = [];
        foreach ($projects as $project) {
            if ($this->projects->canManageForUser($userId, $project->id)) {
                $manageable[$project->id] = true;
            }
        }

        if ($createForm === null) {
            $owner = $request->getQueryParams()['owner'] ?? null;
            $createForm = is_string($owner) ? ['owner_scope' => $owner] : [];
        }

        return $this->view->render($response, 'projects/index.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'projects' => $projects,
            'lead_teams' => $leadTeams,
            'team_map' => $teamMap,
            'manageable_projects' => $manageable,
            'lifecycle_statuses' => ProjectRepository::LIFECYCLE_STATUSES,
            'error' => $error,
            'create_form' => $createForm,
            'edit_project_id' => $editProjectId,
            'edit_form' => $editForm ?? [],
        ])->withStatus($status);
    }

    private function domainMessage(DomainException $error): string
    {
        return match ($error->getMessage()) {
            'Project name must contain 1-160 characters.' => $this->translator->trans('validation.project_name'),
            'Project description cannot exceed 20000 characters.' => $this->translator->trans('validation.project_description'),
            'Unsupported project lifecycle status.' => $this->translator->trans('validation.project_status'),
            'Only a Team Lead can create a team project.' => $this->translator->trans('projects.team_lead_required'),
            'A team project with tasks cannot be deleted.' => $this->translator->trans('projects.team_delete_with_tasks'),
            default => $this->translator->trans('validation.project_invalid'),
        };
    }

    private function redirect(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Location', '/projects')->withStatus(302);
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
