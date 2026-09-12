<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Status\StatusRecord;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRecord;
use Tms\Domain\Task\TaskRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class DashboardController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly TaskRepository $tasks,
        private readonly StatusRepository $statuses,
        private readonly Translator $translator,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->sessions->currentUserId() ?? 0;
        $tasks = $this->tasks->listForUser($userId);
        $statuses = $this->statuses->listForUser($userId);

        $completionIds = [];
        $statusMap = [];
        $statusCounts = [];
        foreach ($statuses as $status) {
            $statusMap[$status->id] = $status;
            $statusCounts[$status->id] = 0;
            if ($status->isCompletion) {
                $completionIds[$status->id] = true;
            }
        }

        $now = new DateTimeImmutable();
        $today = $now->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');
        $weekEnd = $today->modify('+8 days');
        $staleCutoff = $now->modify('-5 days');

        $openTasks = [];
        $urgent = 0;
        $todayCount = 0;
        $weekCount = 0;
        $overdue = 0;
        $stuckTasks = [];

        foreach ($tasks as $task) {
            if ($this->isCompleted($task, $completionIds)) {
                continue;
            }

            $openTasks[] = $task;
            if ($task->priority === 3) {
                ++$urgent;
            }
            if ($task->statusId !== null && array_key_exists($task->statusId, $statusCounts)) {
                ++$statusCounts[$task->statusId];
            }

            if ($task->deadline !== null) {
                $deadline = new DateTimeImmutable($task->deadline);
                if ($deadline < $now) {
                    ++$overdue;
                }
                if ($deadline >= $today && $deadline < $tomorrow) {
                    ++$todayCount;
                } elseif ($deadline >= $tomorrow && $deadline < $weekEnd) {
                    ++$weekCount;
                }
            }

            if (new DateTimeImmutable($task->updatedAt) < $staleCutoff) {
                $stuckTasks[] = $task;
            }
        }

        usort($stuckTasks, static fn (TaskRecord $a, TaskRecord $b): int => strcmp($a->updatedAt, $b->updatedAt));
        $statusBreakdown = $this->statusBreakdown($statuses, $statusCounts);

        return $this->view->render($response, 'dashboard.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername(),
            'open_tasks' => count($openTasks),
            'today_tasks' => $todayCount,
            'week_tasks' => $weekCount,
            'urgent_tasks' => $urgent,
            'overdue_tasks' => $overdue,
            'today_date' => $today->format('Y-m-d'),
            'tomorrow_date' => $tomorrow->format('Y-m-d'),
            'week_end_date' => $weekEnd->modify('-1 day')->format('Y-m-d'),
            'next_tasks' => array_slice($openTasks, 0, 8),
            'stuck_tasks' => array_slice($stuckTasks, 0, 8),
            'status_breakdown' => $statusBreakdown,
            'status_chart_gradient' => $this->statusChartGradient($statusBreakdown),
            'status_map' => $statusMap,
            'priority_labels' => $this->priorityLabels(),
        ]);
    }

    /** @param array<int, true> $completionIds */
    private function isCompleted(TaskRecord $task, array $completionIds): bool
    {
        return $task->statusId !== null && isset($completionIds[$task->statusId]);
    }

    /**
     * @param list<StatusRecord> $statuses
     * @param array<int, int> $counts
     * @return list<array{id: int, name: string, color: string, count: int}>
     */
    private function statusBreakdown(array $statuses, array $counts): array
    {
        $result = [];
        foreach ($statuses as $status) {
            $count = $counts[$status->id] ?? 0;
            if ($count < 1) {
                continue;
            }
            $result[] = [
                'id' => $status->id,
                'name' => $status->name,
                'color' => $status->color,
                'count' => $count,
            ];
        }
        return $result;
    }

    /** @param list<array{id: int, name: string, color: string, count: int}> $breakdown */
    private function statusChartGradient(array $breakdown): string
    {
        $total = array_sum(array_column($breakdown, 'count'));
        if ($total < 1) {
            return '#252c35 0 100%';
        }

        $segments = [];
        $start = 0.0;
        foreach ($breakdown as $item) {
            $end = $start + ($item['count'] / $total * 100);
            $segments[] = sprintf('%s %.4F%% %.4F%%', $item['color'], $start, $end);
            $start = $end;
        }
        return implode(', ', $segments);
    }

    /** @return array<int, string> */
    private function priorityLabels(): array
    {
        return [
            0 => $this->translator->trans('priority.low'),
            1 => $this->translator->trans('priority.medium'),
            2 => $this->translator->trans('priority.high'),
            3 => $this->translator->trans('priority.urgent'),
        ];
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }
}
