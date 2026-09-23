<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Customer\CustomerRepository;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Status\StatusRecord;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRecord;
use Tms\Domain\Task\TaskRepository;
use Tms\Domain\TaskType\TaskTypeRepository;
use Tms\I18n\Translator;
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
        private readonly ProjectRepository $projects,
        private readonly Translator $translator,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $month = $this->month($query['month'] ?? null);
        $mode = $this->mode($query['mode'] ?? null);
        $statusIds = $this->queryInts($query, 'status_id');
        $typeIds = $this->queryInts($query, 'type_id');
        $typeInvert = $typeIds !== [] && ($query['type_invert'] ?? null) === '1';
        $customerId = $this->queryInt($query, 'customer_id');
        $customerQuery = is_string($query['customer'] ?? null) ? trim((string) $query['customer']) : '';
        $priorityNames = $this->queryStrings($query, 'priority');
        $priorities = [];
        foreach ($priorityNames as $name) {
            if (isset(self::PRIORITIES[$name])) {
                $priorities[] = self::PRIORITIES[$name];
            }
        }
        $priorityNames = array_values(array_filter(
            array_unique($priorityNames),
            static fn (string $name): bool => isset(self::PRIORITIES[$name]),
        ));
        $priorityInvert = $priorities !== [] && ($query['priority_invert'] ?? null) === '1';

        $userId = $this->sessions->currentUserId() ?? 0;
        [$projectId, $withoutProject, $projectFilter] = $this->projectFilter($query, $userId);
        $filterStatuses = $this->statusesForFilterScope($userId, $projectId, $projectFilter);
        $filterStatusIds = array_fill_keys(
            array_map(static fn (StatusRecord $status): int => $status->id, $filterStatuses),
            true,
        );
        $statusIds = array_values(array_filter(
            $statusIds,
            static fn (int $statusId): bool => isset($filterStatusIds[$statusId]),
        ));
        $statusInvert = $statusIds !== [] && ($query['status_invert'] ?? null) === '1';

        $firstDay = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        if ($firstDay === false) {
            $firstDay = new DateTimeImmutable('first day of this month midnight');
            $month = $firstDay->format('Y-m');
        }

        $gridStart = $firstDay->modify('-' . ((int) $firstDay->format('N') - 1) . ' days');
        $gridEnd = $gridStart->modify('+42 days');
        $tasks = $this->tasks->listCalendarForUser(
            $userId,
            $gridStart->format('Y-m-d H:i:s'),
            $gridEnd->format('Y-m-d H:i:s'),
            $mode,
            $statusIds,
            $typeIds,
            $customerId,
            $priorities,
            $statusInvert,
            $typeInvert,
            $priorityInvert,
            $customerQuery,
            $projectId,
            $withoutProject,
        );

        $rangeStart = $gridStart->format('Y-m-d H:i:s');
        $rangeEnd = $gridEnd->format('Y-m-d H:i:s');
        $eventsByDay = [];
        foreach ($tasks as $task) {
            foreach ($this->calendarEvents($task, $mode, $rangeStart, $rangeEnd) as $event) {
                $date = substr($event['timestamp'], 0, 10);
                $eventsByDay[$date] ??= [];
                $eventsByDay[$date][] = $event;
            }
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
                'events' => $eventsByDay[$dateKey] ?? [],
                'task_query' => $this->taskListDayQuery($dateKey, $mode, $projectFilter),
            ];
        }

        $filters = [
            'mode' => $mode,
            'status_id' => $statusIds,
            'status_invert' => $statusInvert,
            'type_id' => $typeIds,
            'type_invert' => $typeInvert,
            'customer_id' => $customerId,
            'customer' => $customerQuery,
            'priority' => $priorityNames,
            'priority_invert' => $priorityInvert,
            'project' => $projectFilter,
        ];

        $monthLabel = $this->translator->trans('month.' . $firstDay->format('m')) . ' ' . $firstDay->format('Y');

        return $this->view->render($response, 'tasks/calendar.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername(),
            'month' => $month,
            'month_label' => $monthLabel,
            'previous_query' => $this->monthQuery($firstDay->modify('-1 month'), $filters),
            'next_query' => $this->monthQuery($firstDay->modify('+1 month'), $filters),
            'today_query' => $this->monthQuery(new DateTimeImmutable('first day of this month'), $filters),
            'days' => $days,
            'filters' => $filters,
            'statuses' => $filterStatuses,
            'types' => $this->taskTypes->listForUser($userId),
            'customers' => $this->customers->listForUser($userId, 500),
            'projects' => $this->projects->listForUser($userId),
            'status_map' => $this->statusMap($userId),
            'priority_labels' => $this->priorityLabels(),
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
        if ($value === 'no_deadlines') {
            return 'no_dates';
        }
        if (!is_string($value) || !in_array($value, ['planned_only', 'deadlines_only', 'no_dates', 'all'], true)) {
            return 'deadlines_only';
        }
        return $value;
    }

    /**
     * @return list<array{task:TaskRecord,kind:string,timestamp:string}>
     */
    private function calendarEvents(TaskRecord $task, string $mode, string $rangeStart, string $rangeEnd): array
    {
        $inRange = static fn (?string $value): bool => $value !== null && $value >= $rangeStart && $value < $rangeEnd;
        $events = [];

        if ($mode === 'planned_only') {
            if ($inRange($task->scheduledAt)) {
                $events[] = ['task' => $task, 'kind' => 'planned', 'timestamp' => (string) $task->scheduledAt];
            }
            return $events;
        }

        if ($mode === 'deadlines_only') {
            if ($inRange($task->deadline)) {
                $events[] = ['task' => $task, 'kind' => 'deadline', 'timestamp' => (string) $task->deadline];
            }
            return $events;
        }

        if ($mode === 'no_dates') {
            if ($task->scheduledAt === null && $task->deadline === null && $inRange($task->createdAt)) {
                $events[] = ['task' => $task, 'kind' => 'created', 'timestamp' => $task->createdAt];
            }
            return $events;
        }

        if ($task->scheduledAt !== null && $task->deadline !== null && $task->scheduledAt === $task->deadline && $inRange($task->scheduledAt)) {
            return [['task' => $task, 'kind' => 'planned_deadline', 'timestamp' => $task->scheduledAt]];
        }
        if ($inRange($task->scheduledAt)) {
            $events[] = ['task' => $task, 'kind' => 'planned', 'timestamp' => (string) $task->scheduledAt];
        }
        if ($inRange($task->deadline)) {
            $events[] = ['task' => $task, 'kind' => 'deadline', 'timestamp' => (string) $task->deadline];
        }
        if ($task->scheduledAt === null && $task->deadline === null && $inRange($task->createdAt)) {
            $events[] = ['task' => $task, 'kind' => 'created', 'timestamp' => $task->createdAt];
        }
        return $events;
    }

    private function taskListDayQuery(string $date, string $mode, string $projectFilter): string
    {
        $params = [];
        if ($mode === 'planned_only') {
            $params['scheduled_from'] = $date;
            $params['scheduled_to'] = $date;
        } elseif ($mode === 'deadlines_only') {
            $params['deadline_from'] = $date;
            $params['deadline_to'] = $date;
        }
        if ($projectFilter !== 'none') {
            $params['project'] = $projectFilter;
        }
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, mixed> $query */
    private function queryInt(array $query, string $key): ?int
    {
        $value = $query[$key] ?? null;
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<int>
     */
    private function queryInts(array $query, string $key): array
    {
        $value = $query[$key] ?? [];
        $values = is_array($value) ? $value : [$value];
        $ids = [];
        foreach ($values as $raw) {
            if (is_scalar($raw) && ctype_digit((string) $raw) && (int) $raw > 0) {
                $ids[] = (int) $raw;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $query
     * @return list<string>
     */
    private function queryStrings(array $query, string $key): array
    {
        $value = $query[$key] ?? [];
        $values = is_array($value) ? $value : [$value];
        $strings = [];
        foreach ($values as $raw) {
            if (is_scalar($raw)) {
                $string = trim((string) $raw);
                if ($string !== '') {
                    $strings[] = $string;
                }
            }
        }
        return array_values(array_unique($strings));
    }

    /**
     * @param array{mode:string,status_id:list<int>,status_invert:bool,type_id:list<int>,type_invert:bool,customer_id:?int,customer:string,priority:list<string>,priority_invert:bool,project:string} $filters
     */
    private function monthQuery(DateTimeImmutable $month, array $filters): string
    {
        $params = ['month' => $month->format('Y-m'), 'mode' => $filters['mode']];
        foreach (['status_id', 'type_id', 'priority'] as $key) {
            if ($filters[$key] !== []) {
                $params[$key] = $filters[$key];
            }
        }
        if ($filters['project'] !== 'none') {
            $params['project'] = $filters['project'];
        }
        foreach (['customer_id', 'customer'] as $key) {
            if ($filters[$key] !== null && $filters[$key] !== '') {
                $params[$key] = $filters[$key];
            }
        }
        if ($filters['status_invert']) { $params['status_invert'] = '1'; }
        if ($filters['type_invert']) { $params['type_invert'] = '1'; }
        if ($filters['priority_invert']) { $params['priority_invert'] = '1'; }
        return http_build_query($params);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0:?int,1:bool,2:string}
     */
    private function projectFilter(array $query, int $userId): array
    {
        $value = is_scalar($query['project'] ?? null) ? trim((string) $query['project']) : '';
        if ($value === 'all') {
            return [null, false, 'all'];
        }
        if ($value === 'none' || $value === '') {
            return [null, true, 'none'];
        }
        if (ctype_digit($value) && (int) $value > 0) {
            $projectId = (int) $value;
            if ($this->projects->findForUser($userId, $projectId) !== null) {
                return [$projectId, false, (string) $projectId];
            }
        }
        return [null, true, 'none'];
    }

    /** @return list<StatusRecord> */
    private function statusesForFilterScope(int $userId, ?int $projectId, string $projectFilter): array
    {
        $statuses = $this->statuses->listAccessibleForUser($userId);
        if ($projectFilter === 'all') {
            return $statuses;
        }

        return array_values(array_filter(
            $statuses,
            static fn (StatusRecord $status): bool => $status->projectId === $projectId,
        ));
    }

    /** @return array<int, StatusRecord> */
    private function statusMap(int $userId): array
    {
        $map = [];
        foreach ($this->statuses->listAccessibleForUser($userId) as $status) {
            $map[$status->id] = $status;
        }
        return $map;
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
