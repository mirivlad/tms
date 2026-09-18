<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Project\ProjectAttachmentRepository;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRepository;
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
        private readonly StatusRepository $statuses,
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
        foreach ($this->statuses->listForUser($userId) as $status) {
            $statusMap[$status->id] = $status;
        }

        return $this->view->render($response, 'projects/show.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'project' => $project,
            'tasks' => $this->tasks->listForProjectForUser($userId, $project->id),
            'attachments' => $this->attachments->listForProject($userId, $project->id),
            'status_map' => $statusMap,
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $this->projects->createForUser(
                $this->userId(),
                (string) ($body['name'] ?? ''),
                (string) ($body['description'] ?? ''),
                (string) ($body['lifecycle_status'] ?? 'active'),
            );
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
        if ($this->projects->findForUser($this->userId(), $projectId) === null) {
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
        if ($this->projects->findForUser($userId, $projectId) === null) {
            return $this->render(
                $request,
                $response,
                $this->translator->trans('validation.project_not_found'),
                404,
            );
        }

        $stored = $this->attachments->listForProject($userId, $projectId);
        if (!$this->projects->deleteForUser($userId, $projectId)) {
            return $this->render(
                $request,
                $response,
                $this->translator->trans('validation.project_not_found'),
                404,
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
        return $this->view->render($response, 'projects/index.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'projects' => $this->projects->listForUser($this->userId()),
            'lifecycle_statuses' => ProjectRepository::LIFECYCLE_STATUSES,
            'error' => $error,
            'create_form' => $createForm ?? [],
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
