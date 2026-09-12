<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;
use Tms\Security\TaskDescriptionSanitizer;

final class QuickTaskController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly TaskRepository $tasks,
        private readonly StatusRepository $statuses,
        private readonly TaskDescriptionSanitizer $sanitizer,
        private readonly Translator $translator,
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $title = is_string($body['title'] ?? null) ? trim((string) $body['title']) : '';
        $description = is_string($body['description'] ?? null) ? trim((string) $body['description']) : '';

        try {
            if ($title === '') {
                throw new DomainException($this->translator->trans('validation.task_title_required'));
            }

            $userId = $this->sessions->currentUserId() ?? 0;
            $statusId = $this->defaultStatusId($userId);
            if ($statusId === null) {
                throw new DomainException($this->translator->trans('validation.task_status_required'));
            }

            $deadline = $this->normalizeDeadline($body['deadline'] ?? null);
            $safeDescription = $description === ''
                ? ''
                : $this->sanitizer->sanitize(nl2br(htmlspecialchars(
                    $description,
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    'UTF-8',
                ), false));

            $taskId = $this->tasks->createForUser(
                $userId,
                $title,
                $safeDescription,
                $deadline,
                $statusId,
                null,
                1,
                null,
            );

            $message = $this->translator->trans('quick_add.created');
            if ($this->wantsJson($request)) {
                return $this->json($response, [
                    'success' => true,
                    'message' => $message,
                    'task_id' => $taskId,
                ], 201);
            }

            $_SESSION['quick_add_notice'] = [
                'kind' => 'success',
                'message' => $message,
                'task_id' => $taskId,
            ];
        } catch (DomainException $error) {
            if ($this->wantsJson($request)) {
                return $this->json($response, [
                    'success' => false,
                    'message' => $error->getMessage(),
                ], 400);
            }

            $_SESSION['quick_add_notice'] = [
                'kind' => 'error',
                'message' => $error->getMessage(),
            ];
        }

        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($encoded === false ? '{"success":false}' : $encoded);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    private function defaultStatusId(int $userId): ?int
    {
        $fallback = null;
        foreach ($this->statuses->listForUser($userId) as $status) {
            $fallback ??= $status->id;
            if ($status->isDefault) {
                return $status->id;
            }
        }
        return $fallback;
    }

    private function normalizeDeadline(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        foreach (['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        throw new DomainException($this->translator->trans('validation.deadline_invalid'));
    }
}
