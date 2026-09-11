<?php

declare(strict_types=1);

namespace Tms\Application;

use DateTimeImmutable;
use Tms\Domain\Notification\NotificationSettingsRecord;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Notification\NotificationTaskRepository;
use Tms\Domain\Notification\SentNotificationRepository;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\TelegramSender;

final class NotificationRunner
{
    public function __construct(
        private readonly NotificationSettingsRepository $settings,
        private readonly NotificationTaskRepository $tasks,
        private readonly SentNotificationRepository $sent,
        private readonly EmailSender $email,
        private readonly TelegramSender $telegram,
        private readonly string $appUrl,
    ) {
    }

    /** @return array{users:int,attempted:int,sent:int} */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $stats = ['users' => 0, 'attempted' => 0, 'sent' => 0];
        foreach ($this->settings->listRunnableUsers() as $settings) {
            $stats['users']++;
            $this->runForUser($settings, $now, $stats);
        }
        return $stats;
    }

    /** @param array{users:int,attempted:int,sent:int} $stats */
    private function runForUser(NotificationSettingsRecord $settings, DateTimeImmutable $now, array &$stats): void
    {
        if ($settings->notifyTomorrow && $this->timeReached($now, $settings->tomorrowTime)) {
            $start = $now->modify('tomorrow')->setTime(0, 0);
            $end = $start->modify('+1 day');
            $tasks = $this->tasks->dueBetween($settings->userId, $this->sql($start), $this->sql($end));
            if ($tasks !== []) {
                $this->deliverBatch(
                    $settings,
                    'tomorrow',
                    'tomorrow:' . $start->format('Y-m-d'),
                    'Tasks for tomorrow',
                    $tasks,
                    $stats,
                );
            }
        }

        if ($settings->notifyOverdue && $this->timeReached($now, $settings->overdueTime)) {
            $tasks = $this->tasks->overdue($settings->userId, $this->sql($now));
            if ($tasks !== []) {
                $this->deliverBatch(
                    $settings,
                    'overdue',
                    'overdue:' . $now->format('Y-m-d'),
                    'Overdue tasks',
                    $tasks,
                    $stats,
                );
            }
        }

        if ($settings->notifyDigest && $this->timeReached($now, $settings->digestTime)) {
            $tomorrow = $now->modify('tomorrow')->setTime(0, 0);
            $tasks = array_merge(
                $this->tasks->overdue($settings->userId, $this->sql($now)),
                $this->tasks->dueBetween(
                    $settings->userId,
                    $this->sql($tomorrow),
                    $this->sql($tomorrow->modify('+1 day')),
                ),
            );
            $this->deliverBatch(
                $settings,
                'digest',
                'digest:' . $now->format('Y-m-d'),
                'Task digest',
                $tasks,
                $stats,
                true,
            );
        }

        if ($settings->notifyUpcoming) {
            for ($priority = 3; $priority >= 0; $priority--) {
                $lead = $settings->leadMinutesForPriority($priority);
                $tasks = $this->tasks->dueBetween(
                    $settings->userId,
                    $this->sql($now),
                    $this->sql($now->modify('+' . $lead . ' minutes')),
                    $priority,
                );
                foreach ($tasks as $task) {
                    $key = 'upcoming:' . $priority . ':' . $task['id'] . ':' . $task['deadline'];
                    $this->deliverBatch(
                        $settings,
                        'upcoming',
                        $key,
                        'Upcoming task',
                        [$task],
                        $stats,
                        false,
                        $task['id'],
                    );
                }
            }
        }
    }

    /**
     * @param list<array{id:int,title:string,deadline:string,priority:int}> $tasks
     * @param array{users:int,attempted:int,sent:int} $stats
     */
    private function deliverBatch(
        NotificationSettingsRecord $settings,
        string $type,
        string $dedupeKey,
        string $subject,
        array $tasks,
        array &$stats,
        bool $allowEmpty = false,
        ?int $taskId = null,
    ): void {
        if ($tasks === [] && !$allowEmpty) {
            return;
        }
        $text = $this->renderText($subject, $tasks);
        $html = $this->renderHtml($subject, $tasks);

        if ($settings->emailEnabled && !$this->sent->wasSent($settings->userId, 'email', $dedupeKey)) {
            $stats['attempted']++;
            if ($this->email->send(
                $settings->deliveryEmail(),
                $settings->username,
                $subject,
                $html,
                $text,
            )) {
                $this->sent->markSent($settings->userId, 'email', $type, $dedupeKey, $taskId);
                $stats['sent']++;
            }
        }
        if ($settings->telegramEnabled
            && $settings->telegramChatId !== null
            && !$this->sent->wasSent($settings->userId, 'telegram', $dedupeKey)) {
            $stats['attempted']++;
            if ($this->telegram->send($settings->telegramChatId, $text)) {
                $this->sent->markSent($settings->userId, 'telegram', $type, $dedupeKey, $taskId);
                $stats['sent']++;
            }
        }
    }

    private function timeReached(DateTimeImmutable $now, string $time): bool
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $target = $now->setTime($hour, $minute);
        return $now >= $target;
    }

    /** @param list<array{id:int,title:string,deadline:string,priority:int}> $tasks */
    private function renderText(string $heading, array $tasks): string
    {
        $lines = [$heading];
        if ($tasks === []) {
            $lines[] = 'No matching tasks.';
        }
        foreach ($tasks as $task) {
            $lines[] = '- ' . $task['title'] . ' — ' . $task['deadline'] . ' — '
                . rtrim($this->appUrl, '/') . '/tasks/' . $task['id'] . '/edit';
        }
        return implode("\n", $lines);
    }

    /** @param list<array{id:int,title:string,deadline:string,priority:int}> $tasks */
    private function renderHtml(string $heading, array $tasks): string
    {
        $html = '<h3>' . htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>';
        if ($tasks === []) {
            return $html . '<p>No matching tasks.</p>';
        }
        $html .= '<ul>';
        foreach ($tasks as $task) {
            $url = rtrim($this->appUrl, '/') . '/tasks/' . $task['id'] . '/edit';
            $html .= '<li><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($task['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</a> — ' . htmlspecialchars($task['deadline'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }
        return $html . '</ul>';
    }

    private function sql(DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }
}
