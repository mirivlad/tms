<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\CustomField\CustomFieldRecord;
use Tms\Domain\CustomField\CustomFieldRepository;
use Tms\Domain\Project\ProjectCustomFieldRepository;
use Tms\Domain\Project\ProjectRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class ProjectCustomFieldController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ProjectRepository $projects,
        private readonly ProjectCustomFieldRepository $fields,
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
            $this->fields->createForProject(
                $this->userId(),
                $projectId,
                (string) ($body['name'] ?? ''),
                (string) ($body['field_type'] ?? 'text'),
                $this->options($body),
                $this->checked($body, 'is_required'),
            );
            $this->notice('success', 'projects.field_saved');
        } catch (DomainException $error) {
            $this->notice('error', $this->domainMessageKey($error));
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                $this->notice('error', 'projects.field_duplicate');
            } else {
                throw $error;
            }
        }

        return $this->redirect($response, $projectId);
    }

    /** @param array<string, string> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $this->projectId($args);
        $fieldId = $this->fieldId($args);
        if ($this->fields->findForProject($this->userId(), $projectId, $fieldId) === null) {
            return $this->notFound($response);
        }

        $body = $this->body($request);
        try {
            $this->fields->updateForProject(
                $this->userId(),
                $projectId,
                $fieldId,
                (string) ($body['name'] ?? ''),
                (string) ($body['field_type'] ?? 'text'),
                $this->options($body),
                $this->checked($body, 'is_required'),
            );
            $this->notice('success', 'projects.field_saved');
        } catch (DomainException $error) {
            $this->notice('error', $this->domainMessageKey($error));
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                $this->notice('error', 'projects.field_duplicate');
            } else {
                throw $error;
            }
        }

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
            $this->notice('error', 'validation.custom_field_move_invalid');
            return $this->redirect($response, $projectId);
        }

        $records = $this->fields->listForProject($this->userId(), $projectId);
        $ids = array_map(static fn (CustomFieldRecord $field): int => $field->id, $records);
        $index = array_search($this->fieldId($args), $ids, true);
        if ($index === false) {
            return $this->notFound($response);
        }
        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target >= 0 && $target < count($ids)) {
            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            $this->fields->reorderForProject($this->userId(), $projectId, $ids);
        }

        return $this->redirect($response, $projectId);
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $this->projectId($args);
        if (!$this->fields->deleteForProject($this->userId(), $projectId, $this->fieldId($args))) {
            return $this->notFound($response);
        }
        $this->notice('success', 'projects.field_deleted');
        return $this->redirect($response, $projectId);
    }

    /** @param array<string, mixed> $body */
    private function options(array $body): array
    {
        $raw = is_string($body['options'] ?? null) ? (string) $body['options'] : '';
        $lines = preg_split('/\R/u', $raw) ?: [];
        return array_map('trim', $lines);
    }

    /** @param array<string, mixed> $body */
    private function checked(array $body, string $key): bool
    {
        return isset($body[$key]) && in_array((string) $body[$key], ['1', 'on', 'true'], true);
    }

    private function domainMessageKey(DomainException $error): string
    {
        return match ($error->getMessage()) {
            'Custom field name must contain 1-96 characters.' => 'validation.custom_field_name',
            'Unsupported custom field type.' => 'validation.custom_field_type',
            'Custom field options cannot exceed 128 characters.' => 'validation.custom_field_option_length',
            'Select and checkbox-list fields require at least one option.' => 'validation.custom_field_options_required',
            'Custom fields cannot contain more than 50 options.' => 'validation.custom_field_options_count',
            default => 'validation.custom_field_invalid',
        };
    }

    private function projectAvailable(int $projectId): bool
    {
        return $this->projects->findForUser($this->userId(), $projectId) !== null;
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
    private function fieldId(array $args): int
    {
        $value = $args['fieldId'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function userId(): int
    {
        return $this->sessions->currentUserId() ?? 0;
    }

    private function notice(string $kind, string $key): void
    {
        $_SESSION['project_field_notice'] = [
            'kind' => $kind,
            'message' => $this->translator->trans($key),
        ];
    }

    private function redirect(ResponseInterface $response, int $projectId): ResponseInterface
    {
        return $response
            ->withHeader('Location', '/projects/' . $projectId . '#project-fields')
            ->withStatus(302);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->translator->trans('validation.project_not_found'));
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withStatus(404);
    }
}
