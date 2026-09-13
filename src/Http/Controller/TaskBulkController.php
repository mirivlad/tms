<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\Attachment\AttachmentRepository;
use Tms\Domain\Task\TaskRecord;
use Tms\Domain\Task\TaskRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\AttachmentStorage;
use Tms\Security\SessionManager;

final class TaskBulkController
{
    private const PRIORITIES = [
        'low' => 0,
        'medium' => 1,
        'high' => 2,
        'urgent' => 3,
    ];

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly TaskRepository $tasks,
        private readonly AttachmentRepository $attachments,
        private readonly AttachmentStorage $storage,
        private readonly Translator $translator,
    ) {
    }

    public function apply(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $userId = $this->sessions->currentUserId() ?? 0;

        try {
            $taskIds = $this->taskIds($body['task_ids'] ?? null);
            $tasks = $this->ownedTasks($userId, $taskIds);
            if ($tasks === []) {
                throw new DomainException($this->translator->trans('bulk.no_tasks'));
            }

            $action = is_string($body['action'] ?? null) ? (string) $body['action'] : '';
            $changed = match ($action) {
                'delete' => $this->deleteTasks($userId, $tasks),
                'update_status' => $this->updateStatus($userId, $tasks, $body),
                'update_type' => $this->updateType($userId, $tasks, $body),
                'update_priority' => $this->updatePriority($userId, $tasks, $body),
                'update_deadline' => $this->updateDeadline($userId, $tasks, $body),
                default => throw new DomainException($this->translator->trans('bulk.action_required')),
            };

            $_SESSION['bulk_notice'] = [
                'kind' => 'success',
                'message' => $this->translator->trans('bulk.updated', ['count' => $changed]),
            ];
        } catch (DomainException $error) {
            $_SESSION['bulk_notice'] = ['kind' => 'error', 'message' => $error->getMessage()];
        }

        return $response->withHeader('Location', '/tasks')->withStatus(302);
    }

    /** @return list<int> */
    private function taskIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $raw) {
            if (!is_scalar($raw) || !ctype_digit((string) $raw)) {
                continue;
            }
            $id = (int) $raw;
            if ($id > 0) {
                $ids[$id] = $id;
            }
            if (count($ids) >= 500) {
                break;
            }
        }
        return array_values($ids);
    }

    /**
     * @param list<int> $taskIds
     * @return list<TaskRecord>
     */
    private function ownedTasks(int $userId, array $taskIds): array
    {
        $owned = [];
        foreach ($taskIds as $taskId) {
            $task = $this->tasks->findForUser($userId, $taskId);
            if ($task !== null) {
                $owned[] = $task;
            }
        }
        return $owned;
    }

    /** @param list<TaskRecord> $tasks */
    private function deleteTasks(int $userId, array $tasks): int
    {
        $deleted = 0;
        foreach ($tasks as $task) {
            $stored = $this->attachments->listForTask($userId, $task->id);
            if (!$this->tasks->deleteForUser($userId, $task->id)) {
                continue;
            }
            ++$deleted;
            foreach ($stored as $attachment) {
                if (!$this->storage->delete($attachment->storageName)) {
                    error_log('TMS attachment cleanup failed after bulk task deletion for storage key ' . $attachment->storageName);
                }
            }
        }
        return $deleted;
    }

    /**
     * @param list<TaskRecord> $tasks
     * @param array<string, mixed> $body
     */
    private function updateStatus(int $userId, array $tasks, array $body): int
    {
        $statusId = $this->positiveInt($body['status_id'] ?? null);
        if ($statusId === null) {
            throw new DomainException($this->translator->trans('validation.selected_status_unavailable'));
        }
        try {
            return $this->tasks->bulkUpdateStatusForUser($userId, $this->ids($tasks), $statusId);
        } catch (DomainException) {
            throw new DomainException($this->translator->trans('validation.selected_status_unavailable'));
        }
    }

    /**
     * @param list<TaskRecord> $tasks
     * @param array<string, mixed> $body
     */
    private function updateType(int $userId, array $tasks, array $body): int
    {
        $raw = $body['type_id'] ?? null;
        $typeId = $raw === '' || $raw === null ? null : $this->positiveInt($raw);
        if ($raw !== '' && $raw !== null && $typeId === null) {
            throw new DomainException($this->translator->trans('validation.selected_type_unavailable'));
        }
        try {
            return $this->tasks->bulkUpdateTypeForUser($userId, $this->ids($tasks), $typeId);
        } catch (DomainException) {
            throw new DomainException($this->translator->trans('validation.selected_type_unavailable'));
        }
    }

    /**
     * @param list<TaskRecord> $tasks
     * @param array<string, mixed> $body
     */
    private function updatePriority(int $userId, array $tasks, array $body): int
    {
        $name = is_string($body['priority'] ?? null) ? (string) $body['priority'] : '';
        if (!array_key_exists($name, self::PRIORITIES)) {
            throw new DomainException($this->translator->trans('validation.unknown_priority'));
        }
        return $this->tasks->bulkUpdatePriorityForUser($userId, $this->ids($tasks), self::PRIORITIES[$name]);
    }

    /**
     * @param list<TaskRecord> $tasks
     * @param array<string, mixed> $body
     */
    private function updateDeadline(int $userId, array $tasks, array $body): int
    {
        return $this->tasks->bulkUpdateDeadlineForUser($userId, $this->ids($tasks), $this->deadline($body));
    }

    /**
     * @param list<TaskRecord> $tasks
     * @return list<int>
     */
    private function ids(array $tasks): array
    {
        return array_map(static fn (TaskRecord $task): int => $task->id, $tasks);
    }

    /** @param array<string, mixed> $body */
    private function deadline(array $body): ?string
    {
        $type = is_string($body['deadline_type'] ?? null) ? (string) $body['deadline_type'] : '';
        $now = new DateTimeImmutable();
        return match ($type) {
            'today' => $now->format('Y-m-d H:i:s'),
            'tomorrow' => $now->modify('+1 day')->format('Y-m-d H:i:s'),
            'week' => $now->modify('+7 days')->format('Y-m-d H:i:s'),
            'month' => $now->modify('+30 days')->format('Y-m-d H:i:s'),
            'clear' => null,
            'custom' => $this->customDeadline($body['custom_deadline'] ?? null),
            default => throw new DomainException($this->translator->trans('bulk.deadline_required')),
        };
    }

    private function customDeadline(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new DomainException($this->translator->trans('bulk.deadline_required'));
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

    private function positiveInt(mixed $value): ?int
    {
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }
}
