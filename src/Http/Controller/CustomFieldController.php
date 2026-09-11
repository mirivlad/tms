<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\CustomField\CustomFieldRecord;
use Tms\Domain\CustomField\CustomFieldRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class CustomFieldController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly CustomFieldRepository $fields,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        try {
            $this->fields->createForUser(
                $this->userId(),
                (string) ($body['name'] ?? ''),
                (string) ($body['field_type'] ?? 'text'),
                $this->options($body),
                $this->checked($body, 'is_required'),
            );
        } catch (DomainException $error) {
            return $this->render($request, $response, $this->domainMessage($error), 422);
        } catch (PDOException $error) {
            if ($this->isUniqueViolation($error)) {
                return $this->render(
                    $request,
                    $response,
                    $this->translator->trans('validation.custom_field_duplicate_name'),
                    422,
                );
            }
            throw $error;
        }

        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $fieldId = $this->routeId($args);
        if ($this->fields->findForUser($this->userId(), $fieldId) === null) {
            return $this->render(
                $request,
                $response,
                $this->translator->trans('validation.custom_field_not_found'),
                404,
            );
        }

        $body = $this->body($request);
        try {
            $this->fields->updateForUser(
                $this->userId(),
                $fieldId,
                (string) ($body['name'] ?? ''),
                (string) ($body['field_type'] ?? 'text'),
                $this->options($body),
                $this->checked($body, 'is_required'),
            );
        } catch (DomainException $error) {
            return $this->render($request, $response, $this->domainMessage($error), 422);
        } catch (PDOException $error) {
            if ($this->isUniqueViolation($error)) {
                return $this->render(
                    $request,
                    $response,
                    $this->translator->trans('validation.custom_field_duplicate_name'),
                    422,
                );
            }
            throw $error;
        }

        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    public function move(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $body = $this->body($request);
        $direction = (string) ($body['direction'] ?? '');
        if (!in_array($direction, ['up', 'down'], true)) {
            return $this->render(
                $request,
                $response,
                $this->translator->trans('validation.custom_field_move_invalid'),
                422,
            );
        }

        $records = $this->fields->listForUser($this->userId());
        if (!$this->moveRecord($records, $this->routeId($args), $direction)) {
            return $this->render(
                $request,
                $response,
                $this->translator->trans('validation.custom_field_not_found'),
                404,
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
        if (!$this->fields->deleteForUser($this->userId(), $this->routeId($args))) {
            return $this->render(
                $request,
                $response,
                $this->translator->trans('validation.custom_field_not_found'),
                404,
            );
        }
        return $this->redirect($response);
    }

    /**
     * @param list<CustomFieldRecord> $records
     */
    private function moveRecord(array $records, int $fieldId, string $direction): bool
    {
        $ids = array_map(static fn (CustomFieldRecord $field): int => $field->id, $records);
        $index = array_search($fieldId, $ids, true);
        if ($index === false) {
            return false;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if (!isset($ids[$target])) {
            return true;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        return $this->fields->reorderForUser($this->userId(), $ids);
    }

    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?string $error = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($response, 'custom_fields/index.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'fields' => $this->fields->listForUser($this->userId()),
            'field_types' => CustomFieldRepository::TYPES,
            'error' => $error,
        ])->withStatus($status);
    }

    private function redirect(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Location', '/custom-fields')->withStatus(302);
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $body */
    private function checked(array $body, string $key): bool
    {
        return isset($body[$key]) && in_array((string) $body[$key], ['1', 'on', 'true'], true);
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private function options(array $body): array
    {
        $raw = is_string($body['options'] ?? null) ? (string) $body['options'] : '';
        $lines = preg_split('/\R/u', $raw) ?: [];
        return array_values(array_map('trim', $lines));
    }

    private function domainMessage(DomainException $error): string
    {
        return match ($error->getMessage()) {
            'Custom field name must contain 1-96 characters.' => $this->translator->trans('validation.custom_field_name'),
            'Unsupported custom field type.' => $this->translator->trans('validation.custom_field_type'),
            'Custom field options cannot exceed 128 characters.' => $this->translator->trans('validation.custom_field_option_length'),
            'Select and checkbox-list fields require at least one option.' => $this->translator->trans('validation.custom_field_options_required'),
            'Custom fields cannot contain more than 50 options.' => $this->translator->trans('validation.custom_field_options_count'),
            default => $this->translator->trans('validation.custom_field_invalid'),
        };
    }

    private function isUniqueViolation(PDOException $error): bool
    {
        return $error->getCode() === '23000'
            && isset($error->errorInfo[1])
            && in_array((int) $error->errorInfo[1], [1062, 1557, 1586], true);
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
