<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Customer\CustomerRepository;
use Tms\Domain\Status\StatusRecord;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRecord;
use Tms\Domain\Task\TaskRepository;
use Tms\Domain\TaskType\TaskTypeRepository;
use Tms\Security\SessionManager;

final class CalendarController
{
    private const PRIORITIES = [
        'low' => 0,
        'medium' => 1,
        'high' => 2,
        'urgent' => 3,
    ];

    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly TaskRepository $tasks,
        private readonly StatusRepository $statuses,
        private readonly TaskTypeRepository $taskTypes,
        private readonly CustomerRepository $customers,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $month = $this->month($query['month'] ?? null);
        $mode = $this->mode($query['mode'] ?? null);
        $statusId = $this->queryInt($query, 'status_id');
        $typeId = $this->queryInt($query, 'type_id');
        $customerId = $this->queryInt($query, 'customer_id');
        $priorityName = is_string($query['priority'] ?? null) ? (string) $query['priority'] : '';
        $priority = self::PRIORITIES[$priorityName] ?? null;

        $firstDay = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        if ($firstDay === false) {
            $firstDay = new DateTimeImmutable('first day of this month midnight');
            $month = $firstDay->format('Y-m');
        }

        $gridStart = $firstDay->modify('-' . ((int) $firstDay->format('N') - 1) . ' days');
        $gridEnd = $gridStart->modify('+42 days');
        $userId = $this->sessions->currentUserId() ?? 0;

        $tasks = $this->tasks->listCalendarForUser(
            $userId,
            $gridStart->format('Y-m-d H:i:s'),
            $gridEnd->format('Y-m-d H:i:s'),
            $mode,
            $statusId,
            $typeId,
            $customerId,
            $priority,
        );

        $tasksByDay = [];
        foreach ($tasks as $task) {
            $date = $this->displayDate($task);
            $tasksByDay[$date] ??= [];
            $tasksByDay[$date][] = $task;
        }

        $days = [];
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        for ($offset = 0; $offset < 42; ++$offset) {
            $date = $gridStart->modify('+' . $offset . ' days');
            $dateKey = $date->format('Y-m-d');
            $days[] = [
                'date' => $dateKey,
                'day' => (int) $date->format('j'),
                'current_month' => $date->format('Y-m') === $month,
                'today' => $dateKey === $today,
                'tasks' => $tasksByDay[$dateKey] ?? [],
            ];
        }

        $filters = [
            'mode' => $mode,
            'status_id' => $statusId,
            'type_id' => $typeId,
            'customer_id' => $customerId,
            'priority' => $priorityName,
        ];

        return $this->view->render($response, 'tasks/calendar.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername(),
            'month' => $month,
            'month_label' => $firstDay->format('F Y'),
            'previous_query' => $this->monthQuery($firstDay->modify('-1 month'), $filters),
            'next_query' => $this->monthQuery($firstDay->modify('+1 month'), $filters),
            'days' => $days,
            'filters' => $filters,
            'statuses' => $this->statuses->listForUser($userId),
            'types' => $this->taskTypes->listForUser($userId),
            'customers' => $this->customers->listForUser($userId, 500),
            'status_map' => $this->statusMap($userId),
            'priority_labels' => [0 => 'Low', 1 => 'Medium', 2 => 'High', 3 => 'Urgent'],
        ]);
    }

    private function month(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(19[7-9][0-9]|20[0-9]{2}|2100)-(0[1-9]|1[0-2])$/D', $value) !== 1) {
            return (new DateTimeImmutable('first day of this month'))->format('Y-m');
        }
        return $value;
    }

    private function mode(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['deadlines_only', 'no_deadlines', 'all'], true)) {
            return 'deadlines_only';
        }
        return $value;
    }

    private function displayDate(TaskRecord $task): string
    {
        $value = $task->deadline ?? $task->createdAt;
        return substr($value, 0, 10);
    }

    /** @param array<string, mixed> $query */
    private function queryInt(array $query, string $key): ?int
    {
        $value = $query[$key] ?? null;
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @param array{mode:string,status_id:?int,type_id:?int,customer_id:?int,priority:string} $filters
     */
    private function monthQuery(DateTimeImmutable $month, array $filters): string
    {
        $params = ['month' => $month->format('Y-m'), 'mode' => $filters['mode']];
        foreach (['status_id', 'type_id', 'customer_id', 'priority'] as $key) {
            if ($filters[$key] !== null && $filters[$key] !== '') {
                $params[$key] = $filters[$key];
            }
        }
        return http_build_query($params);
    }

    /** @return array<int, StatusRecord> */
    private function statusMap(int $userId): array
    {
        $map = [];
        foreach ($this->statuses->listForUser($userId) as $status) {
            $map[$status->id] = $status;
        }
        return $map;
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }
}
