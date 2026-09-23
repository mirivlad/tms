<?php

declare(strict_types=1);

namespace Tms\Application;

use Tms\Domain\Event\DomainEvent;
use Tms\Domain\Event\DomainEventConsumer;
use Tms\Domain\Notification\InternalNotificationRepository;
use Tms\Domain\Notification\NotificationSettingsRecord;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\TelegramSender;

final class TaskEventNotificationConsumer implements DomainEventConsumer
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly InternalNotificationRepository $notifications,
        private readonly NotificationSettingsRepository $settings,
        private readonly EmailSender $email,
        private readonly TelegramSender $telegram,
        private readonly Translator $translator,
        private readonly string $appUrl,
    ) {
    }

    public function consume(DomainEvent $event): void
    {
        try {
            $this->consumeEvent($event);
        } catch (\Throwable $error) {
            error_log('TMS task event notification consumer failed: ' . $error->getMessage());
        }
    }

    private function consumeEvent(DomainEvent $event): void
    {
        if (!in_array($event->type, ['task.created', 'task.updated'], true)
            || $event->taskId === null) {
            return;
        }

        $title = $event->payload['subject_title'] ?? null;
        $ownerUserId = $this->intOrNull($event->payload['owner_user_id'] ?? null);
        $assigneeUserId = $this->intOrNull($event->payload['assignee_user_id'] ?? null);
        $previousAssigneeUserId = $this->intOrNull($event->payload['previous_assignee_user_id'] ?? null);
        $changes = $event->payload['changes'] ?? [];
        if (!is_string($title) || !is_array($changes)) {
            return;
        }

        if ($assigneeUserId !== null
            && $assigneeUserId !== $event->actorUserId
            && ($event->type === 'task.created' || array_key_exists('assignee', $changes))) {
            $settings = $this->safeSettings($assigneeUserId);
            if ($settings !== null && $settings->notifyTaskAssignments && $this->taskAvailable($assigneeUserId, $event->taskId)) {
                $this->notify(
                    event: $event,
                    recipient: $settings,
                    type: $previousAssigneeUserId === null ? 'task_assigned' : 'task_reassigned',
                    title: $title,
                    preview: '',
                );
            }
        }

        if ($event->type !== 'task.updated') {
            return;
        }

        $recipients = array_values(array_unique(array_filter(
            [$ownerUserId, $assigneeUserId],
            static fn (?int $id): bool => $id !== null && $id !== $event->actorUserId,
        )));

        foreach ($recipients as $recipientUserId) {
            if (!$this->taskAvailable($recipientUserId, $event->taskId)) {
                continue;
            }
            $settings = $this->safeSettings($recipientUserId);
            if ($settings === null) {
                continue;
            }

            if ($settings->notifyTaskDates && isset($changes['scheduled_at']) && is_array($changes['scheduled_at'])) {
                $this->notify(
                    event: $event,
                    recipient: $settings,
                    type: 'task_scheduled_changed',
                    title: $title,
                    preview: $this->changePreview($changes['scheduled_at']),
                );
            }
            if ($settings->notifyTaskDates && isset($changes['deadline']) && is_array($changes['deadline'])) {
                $this->notify(
                    event: $event,
                    recipient: $settings,
                    type: 'task_deadline_changed',
                    title: $title,
                    preview: $this->changePreview($changes['deadline']),
                );
            }
            if ($settings->notifyTaskStatus && isset($changes['status']) && is_array($changes['status'])) {
                $this->notify(
                    event: $event,
                    recipient: $settings,
                    type: 'task_status_changed',
                    title: $title,
                    preview: $this->changePreview($changes['status']),
                );
            }
        }
    }

    private function notify(
        DomainEvent $event,
        NotificationSettingsRecord $recipient,
        string $type,
        string $title,
        string $preview,
    ): void {
        $targetUrl = '/tasks/' . $event->taskId . '/edit';
        $dedupe = hash('sha256', $event->id . ':' . $type . ':' . $recipient->userId);
        $created = $this->notifications->create(
            userId: $recipient->userId,
            actorUserId: $event->actorUserId,
            actorUsername: $event->actorUsername,
            notificationType: $type,
            contextLabel: $title,
            bodyPreview: $preview,
            targetUrl: $targetUrl,
            dedupeKey: $dedupe,
            projectId: $event->projectId,
            taskId: $event->taskId,
        );
        if ($created === null) {
            return;
        }

        $heading = $this->translator->trans('notifications.' . $type, [
            'actor' => $event->actorUsername,
            'context' => $title,
        ]);
        $url = rtrim($this->appUrl, '/') . $targetUrl;
        $open = $this->translator->trans('notifications.collaboration_open');
        $text = $heading . ($preview !== '' ? "\n" . $preview : '') . "\n" . $open . ': ' . $url;
        $subject = $this->translator->trans('notifications.collaboration_subject', ['context' => $title]);
        $html = '<p><strong>' . htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>'
            . ($preview !== '' ? '<p>' . htmlspecialchars($preview, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '')
            . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . htmlspecialchars($open, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>';

        if ($recipient->emailEnabled) {
            try {
                $this->email->send($recipient->deliveryEmail(), $recipient->username, $subject, $html, $text);
            } catch (\Throwable $error) {
                error_log('TMS task event email notification failed: ' . $error->getMessage());
            }
        }
        if ($recipient->telegramEnabled && $recipient->telegramChatId !== null) {
            try {
                $this->telegram->send($recipient->telegramChatId, $text);
            } catch (\Throwable $error) {
                error_log('TMS task event Telegram notification failed: ' . $error->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $change */
    private function changePreview(array $change): string
    {
        $old = isset($change['old']) && $change['old'] !== null && (string) $change['old'] !== ''
            ? (string) $change['old']
            : '—';
        $new = isset($change['new']) && $change['new'] !== null && (string) $change['new'] !== ''
            ? (string) $change['new']
            : '—';
        return $this->translator->trans('notifications.change_preview', ['old' => $old, 'new' => $new]);
    }

    private function safeSettings(int $userId): ?NotificationSettingsRecord
    {
        try {
            return $this->settings->getForUser($userId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function taskAvailable(int $userId, int $taskId): bool
    {
        return $this->tasks->findForUser($userId, $taskId) !== null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;
            return $id > 0 ? $id : null;
        }
        return null;
    }
}
