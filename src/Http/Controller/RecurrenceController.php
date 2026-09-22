<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\Project\ProjectStatusRepository;
use Tms\Domain\Recurrence\RecurrenceSchedule;
use Tms\Domain\Recurrence\TaskRecurrenceRepository;
use Tms\Domain\Status\StatusRecord;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRecord;
use Tms\Domain\Task\TaskRepository;
use Tms\Domain\UserPreference\UserPreferenceRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class RecurrenceController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly TaskRepository $tasks,
        private readonly TaskRecurrenceRepository $recurrences,
        private readonly RecurrenceSchedule $schedule,
        private readonly StatusRepository $statuses,
        private readonly ProjectStatusRepository $projectStatuses,
        private readonly UserPreferenceRepository $preferences,
        private readonly Translator $translator,
    ) {
    }

    /** @param array<string, string> $args */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->sessions->currentUserId() ?? 0;
        $taskId = $this->taskId($args);
        $task = $this->tasks->findForUser($userId, $taskId);
        if ($task === null || $task->ownerId !== $userId) {
            return $this->notFound($response);
        }

        $body = $this->body($request);
        try {
            $mode = is_string($body['mode'] ?? null) ? trim((string) $body['mode']) : '';
            $interval = $this->schedule->normalizeInterval($mode, (int) ($body['interval_value'] ?? 1));
            $spawnStatusId = $this->positiveInt($body['spawn_status_id'] ?? null);
            if ($spawnStatusId === null) {
                throw new DomainException('A status for new occurrences is required.');
            }
            $this->assertSpawnStatus($userId, $task, $spawnStatusId);

            $timezone = $this->preferences->getForUser($userId)->timezone;
            $anchorDay = null;
            $nextDeadline = null;
            $nextRunAt = null;

            if ($mode !== 'after_completion') {
                if ($task->deadline === null || $task->deadline === '') {
                    throw new DomainException('Calendar recurrence requires a task deadline.');
                }
                $zone = new DateTimeZone($timezone);
                $seed = new DateTimeImmutable($task->deadline, $zone);
                $anchorDay = (int) $seed->format('j');
                $next = $this->schedule->nextCalendarDeadline($mode, $interval, $seed, $anchorDay);
                $nextDeadline = $next->format('Y-m-d H:i:s');
                $nextRunAt = $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }

            $this->recurrences->saveForTask(
                $userId,
                $taskId,
                $mode,
                $interval,
                $spawnStatusId,
                $timezone,
                $anchorDay,
                $nextDeadline,
                $nextRunAt,
            );
            $this->notice('success', 'recurrence.saved');
        } catch (DomainException $error) {
            $this->noticeRaw('error', $this->domainMessage($error));
        }

        return $response->withHeader('Location', '/tasks/' . $taskId . '/edit#recurrence')->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->sessions->currentUserId() ?? 0;
        $taskId = $this->taskId($args);
        $task = $this->tasks->findForUser($userId, $taskId);
        if ($task === null || $task->ownerId !== $userId) {
            return $this->notFound($response);
        }
        if ($this->recurrences->deleteForTask($userId, $taskId)) {
            $this->notice('success', 'recurrence.deleted');
        }
        return $response->withHeader('Location', '/tasks/' . $taskId . '/edit#recurrence')->withStatus(302);
    }

    private function assertSpawnStatus(int $userId, TaskRecord $task, int $statusId): void
    {
        $status = $this->statuses->findAccessibleForUser($userId, $statusId);
        if ($status === null || $status->isCompletion || !$this->statusMatchesTask($status, $task)) {
            throw new DomainException('Selected recurrence status is unavailable.');
        }
    }

    private function statusMatchesTask(StatusRecord $status, TaskRecord $task): bool
    {
        if ($task->projectId === null) {
            return $status->projectId === null && $status->userId === $task->ownerId;
        }
        return $status->userId === null && $status->projectId === $task->projectId;
    }

    /** @param array<string, string> $args */
    private function taskId(array $args): int
    {
        $value = $args['id'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0
            ? (int) $value
            : null;
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->translator->trans('recurrence.not_found'));
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    private function notice(string $kind, string $key): void
    {
        $this->noticeRaw($kind, $this->translator->trans($key));
    }

    private function noticeRaw(string $kind, string $message): void
    {
        $_SESSION['_recurrence_notice'] = ['kind' => $kind, 'message' => $message];
    }

    private function domainMessage(DomainException $error): string
    {
        return match ($error->getMessage()) {
            'Unsupported recurrence mode.' => $this->translator->trans('recurrence.invalid_mode'),
            'Recurrence interval must be between 1 and 3650 days.' => $this->translator->trans('recurrence.invalid_interval'),
            'A status for new occurrences is required.',
            'Selected recurrence status is unavailable.' => $this->translator->trans('recurrence.invalid_status'),
            'Calendar recurrence requires a task deadline.' => $this->translator->trans('recurrence.deadline_required'),
            default => $this->translator->trans('recurrence.invalid'),
        };
    }
}
