<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Project\ProjectStatusRepository;
use Tms\Domain\Status\StatusRecord;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class ProjectStatusController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ProjectRepository $projects,
        private readonly ProjectStatusRepository $statuses,
        private readonly Translator $translator,
    ) {
    }

    /** @param array<string, string> $args */
    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $this->projectId($args);
        if (!$this->projectAvailable($projectId)) {
            return $this->notFound($response);
        }
        $body = $this->body($request);
        try {
            $this->statuses->createForProject(
                $this->userId(),
                $projectId,
                (string) ($body['name'] ?? ''),
                (string) ($body['description'] ?? ''),
                (string) ($body['color'] ?? '#6b7280'),
                $this->checked($body, 'is_default'),
                $this->checked($body, 'is_completion'),
                $this->checked($body, 'show_on_board'),
            );
            $this->notice('success', 'projects.status_saved');
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                $this->notice('error', 'projects.status_duplicate');
            } else {
                throw $error;
            }
        } catch (DomainException) {
            $this->notice('error', 'projects.status_invalid');
        }
        return $this->redirect($response, $projectId);
    }

    /** @param array<string, string> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $this->projectId($args);
        $statusId = $this->statusId($args);
        if (!$this->projectAvailable($projectId)
            || $this->statuses->findForProject($this->userId(), $projectId, $statusId) === null) {
            return $this->notFound($response);
        }

        $body = $this->body($request);
        try {
            $this->statuses->updateForProject(
                $this->userId(),
                $projectId,
                $statusId,
                (string) ($body['name'] ?? ''),
                (string) ($body['description'] ?? ''),
                (string) ($body['color'] ?? '#6b7280'),
                $this->checked($body, 'show_on_board'),
            );
            $this->notice('success', 'projects.status_saved');
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                $this->notice('error', 'projects.status_duplicate');
            } else {
                throw $error;
            }
        } catch (DomainException) {
            $this->notice('error', 'projects.status_invalid');
        }
        return $this->redirect($response, $projectId);
    }

    /** @param array<string, string> $args */
    public function setDefault(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $this->projectId($args);
        if (!$this->statuses->setDefaultForProject($this->userId(), $projectId, $this->statusId($args))) {
            return $this->notFound($response);
        }
        $this->notice('success', 'projects.status_default_set');
        return $this->redirect($response, $projectId);
    }

    /** @param array<string, string> $args */
    public function setCompletion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $this->projectId($args);
        if (!$this->statuses->setCompletionForProject($this->userId(), $projectId, $this->statusId($args))) {
            return $this->notFound($response);
        }
        $this->notice('success', 'projects.status_completion_set');
        return $this->redirect($response, $projectId);
    }

    /** @param array<string, string> $args */
    public function move(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $this->projectId($args);
        if (!$this->projectAvailable($projectId)) {
            return $this->notFound($response);
        }

        $body = $this->body($request);
        $direction = (string) ($body['direction'] ?? '');
        if (!in_array($direction, ['up', 'down'], true)) {
            $this->notice('error', 'projects.status_invalid');
            return $this->redirect($response, $projectId);
        }

        $records = $this->statuses->listForProject($this->userId(), $projectId);
        $ids = array_map(static fn (StatusRecord $status): int => $status->id, $records);
        $index = array_search($this->statusId($args), $ids, true);
        if ($index === false) {
            return $this->notFound($response);
        }
        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target >= 0 && $target < count($ids)) {
            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            $this->statuses->reorderForProject($this->userId(), $projectId, $ids);
        }
        return $this->redirect($response, $projectId);
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $this->projectId($args);
        $statusId = $this->statusId($args);
        if ($this->statuses->findForProject($this->userId(), $projectId, $statusId) === null) {
            return $this->notFound($response);
        }
        if (!$this->statuses->deleteForProject($this->userId(), $projectId, $statusId)) {
            $this->notice('error', 'projects.status_delete_blocked');
        } else {
            $this->notice('success', 'projects.status_deleted');
        }
        return $this->redirect($response, $projectId);
    }

    private function projectAvailable(int $projectId): bool
    {
        return $this->projects->findManageableForUser($this->userId(), $projectId) !== null;
    }

    /** @param array<string, mixed> $body */
    private function checked(array $body, string $key): bool
    {
        return ($body[$key] ?? null) === '1';
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /** @param array<string, string> $args */
    private function projectId(array $args): int
    {
        $value = $args['projectId'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    /** @param array<string, string> $args */
    private function statusId(array $args): int
    {
        $value = $args['statusId'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function userId(): int
    {
        return $this->sessions->currentUserId() ?? 0;
    }

    private function notice(string $kind, string $key): void
    {
        $_SESSION['project_status_notice'] = [
            'kind' => $kind,
            'message' => $this->translator->trans($key),
        ];
    }

    private function redirect(ResponseInterface $response, int $projectId): ResponseInterface
    {
        return $response
            ->withHeader('Location', '/projects/' . $projectId . '#project-statuses')
            ->withStatus(302);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->translator->trans('validation.project_not_found'));
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withStatus(404);
    }
}
