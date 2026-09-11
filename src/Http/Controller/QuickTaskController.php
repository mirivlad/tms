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

            $_SESSION['quick_add_notice'] = [
                'kind' => 'success',
                'message' => $this->translator->trans('quick_add.created'),
                'task_id' => $taskId,
            ];
        } catch (DomainException $error) {
            $_SESSION['quick_add_notice'] = [
                'kind' => 'error',
                'message' => $error->getMessage(),
            ];
        }

        return $response->withHeader('Location', '/dashboard#quick-add')->withStatus(302);
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
