<?php

declare(strict_types=1);

namespace Tms\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;
use Tms\Domain\Activity\ActivityRepository;
use Tms\Domain\Checklist\ChecklistRepository;
use Tms\Domain\CustomField\TaskCustomFieldValueRepository;
use Tms\Domain\Project\ProjectStatusRepository;
use Tms\Domain\Recurrence\RecurrenceSchedule;
use Tms\Domain\Recurrence\TaskRecurrenceRecord;
use Tms\Domain\Recurrence\TaskRecurrenceRepository;
use Tms\Domain\Status\StatusRecord;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRecord;
use Tms\Domain\Task\TaskRepository;

final class RecurringTaskRunner
{
    public function __construct(
        private readonly PDO $db,
        private readonly TaskRecurrenceRepository $recurrences,
        private readonly RecurrenceSchedule $schedule,
        private readonly TaskRepository $tasks,
        private readonly StatusRepository $statuses,
        private readonly ProjectStatusRepository $projectStatuses,
        private readonly ChecklistRepository $checklists,
        private readonly TaskCustomFieldValueRepository $customValues,
        private readonly DomainEventPublisher $events,
        private readonly ActivityRepository $activity,
        private readonly string $applicationTimezone,
    ) {
    }

    /** @return array{candidates:int,generated:int,paused:int} */
    public function run(?DateTimeImmutable $nowUtc = null): array
    {
        $nowUtc ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $candidateIds = $this->recurrences->candidateIds($nowUtc->format('Y-m-d H:i:s'));
        $stats = ['candidates' => count($candidateIds), 'generated' => 0, 'paused' => 0];

        foreach ($candidateIds as $recurrenceId) {
            try {
                if ($this->generateOne($recurrenceId, $nowUtc)) {
                    $stats['generated']++;
                }
            } catch (DomainException $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $this->recurrences->pause($recurrenceId, $error->getMessage());
                $stats['paused']++;
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }
        }

        return $stats;
    }

    private function generateOne(int $recurrenceId, DateTimeImmutable $nowUtc): bool
    {
        $this->db->beginTransaction();
        $recurrence = $this->recurrences->lockById($recurrenceId);
        if ($recurrence === null || !$recurrence->isActive) {
            $this->db->commit();
            return false;
        }

        $task = $this->tasks->findForUser($recurrence->ownerUserId, $recurrence->currentTaskId);
        if ($task === null || $task->ownerId !== $recurrence->ownerUserId) {
            throw new DomainException('The current recurring task is no longer available to its owner.');
        }

        $spawnStatus = $this->spawnStatus($recurrence, $task);
        $deadline = $this->dueDeadline($recurrence, $task, $nowUtc);
        if ($deadline === null) {
            $this->db->commit();
            return false;
        }

        $taskId = $this->tasks->createForUser(
            $recurrence->ownerUserId,
            $task->title,
            $task->description,
            $deadline->format('Y-m-d H:i:s'),
            $spawnStatus->id,
            $task->typeId,
            $task->priority,
            $task->customerId,
            $task->projectId,
            $task->assigneeUserId,
            $this->occurrenceScheduledAt($task, $deadline),
        );

        $this->customValues->cloneForTask($recurrence->ownerUserId, $task->id, $taskId);
        foreach ($this->checklists->listForTask($recurrence->ownerUserId, $task->id) as $item) {
            $this->checklists->createForTask($recurrence->ownerUserId, $taskId, $item->text);
        }

        $created = $this->tasks->findForUser($recurrence->ownerUserId, $taskId);
        if ($created === null) {
            throw new DomainException('Generated recurring task could not be reloaded.');
        }
        $this->events->taskCreated($recurrence->ownerUserId, $created);

        $sequence = $recurrence->sequence + 1;
        $this->recurrences->recordOccurrence(
            $recurrence->id,
            $sequence,
            $taskId,
            $deadline->format('Y-m-d H:i:s'),
        );

        [$nextDeadline, $nextRunAt] = $this->followingSchedule($recurrence, $deadline);
        $this->recurrences->advance(
            $recurrence->id,
            $taskId,
            $sequence,
            $spawnStatus->id,
            $nextDeadline,
            $nextRunAt,
        );

        $this->db->commit();
        return true;
    }

    private function dueDeadline(
        TaskRecurrenceRecord $recurrence,
        TaskRecord $task,
        DateTimeImmutable $nowUtc,
    ): ?DateTimeImmutable {
        $timezone = $this->recurrenceTimezone($recurrence->timezone);

        if ($recurrence->mode === 'after_completion') {
            $status = $task->statusId === null
                ? null
                : $this->statuses->findAccessibleForUser($recurrence->ownerUserId, $task->statusId);
            if ($status === null || !$status->isCompletion) {
                return null;
            }
            $completionTimestamp = $this->activity->latestTaskStatusChangeAt($task->id) ?? $task->updatedAt;
            $completedAt = new DateTimeImmutable(
                $completionTimestamp,
                new DateTimeZone($this->applicationTimezone),
            );
            return $this->schedule->afterCompletionDeadline(
                $completedAt->setTimezone($timezone),
                $recurrence->intervalValue,
            );
        }

        if ($recurrence->nextDeadline === null || $recurrence->nextRunAt === null) {
            throw new DomainException('Calendar recurrence has no next occurrence.');
        }
        $runAt = new DateTimeImmutable($recurrence->nextRunAt, new DateTimeZone('UTC'));
        if ($runAt > $nowUtc) {
            return null;
        }
        return new DateTimeImmutable($recurrence->nextDeadline, $timezone);
    }

    private function occurrenceScheduledAt(TaskRecord $source, DateTimeImmutable $generatedDeadline): ?string
    {
        if ($source->scheduledAt === null || $source->scheduledAt === '') {
            return null;
        }
        if ($source->deadline === null || $source->deadline === '') {
            return $generatedDeadline->format('Y-m-d H:i:s');
        }

        $zone = $generatedDeadline->getTimezone();
        $sourceDeadline = new DateTimeImmutable($source->deadline, $zone);
        $sourceScheduledAt = new DateTimeImmutable($source->scheduledAt, $zone);
        $offsetSeconds = $sourceScheduledAt->getTimestamp() - $sourceDeadline->getTimestamp();

        return $generatedDeadline
            ->modify(($offsetSeconds >= 0 ? '+' : '') . $offsetSeconds . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    /** @return array{0:?string,1:?string} */
    private function followingSchedule(
        TaskRecurrenceRecord $recurrence,
        DateTimeImmutable $generatedDeadline,
    ): array {
        if ($recurrence->mode === 'after_completion') {
            return [null, null];
        }

        $next = $this->schedule->nextCalendarDeadline(
            $recurrence->mode,
            $recurrence->intervalValue,
            $generatedDeadline,
            $recurrence->anchorDay ?? (int) $generatedDeadline->format('j'),
        );
        return [
            $next->format('Y-m-d H:i:s'),
            $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }

    private function recurrenceTimezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new DomainException('Recurring task timezone is invalid.');
        }
    }

    private function spawnStatus(TaskRecurrenceRecord $recurrence, TaskRecord $task): StatusRecord
    {
        if ($recurrence->spawnStatusId !== null) {
            $candidate = $this->statuses->findAccessibleForUser(
                $recurrence->ownerUserId,
                $recurrence->spawnStatusId,
            );
            if ($candidate !== null && !$candidate->isCompletion && $this->statusMatchesTask($candidate, $task)) {
                return $candidate;
            }
        }

        $available = $task->projectId === null
            ? $this->statuses->listForUser($recurrence->ownerUserId)
            : $this->projectStatuses->listForProject($recurrence->ownerUserId, $task->projectId);

        foreach ($available as $status) {
            if ($status->isDefault && !$status->isCompletion) {
                return $status;
            }
        }
        foreach ($available as $status) {
            if (!$status->isCompletion) {
                return $status;
            }
        }

        throw new DomainException('No non-completion status is available for the next occurrence.');
    }

    private function statusMatchesTask(StatusRecord $status, TaskRecord $task): bool
    {
        if ($task->projectId === null) {
            return $status->projectId === null && $status->userId === $task->ownerId;
        }
        return $status->projectId === $task->projectId && $status->userId === null;
    }
}
