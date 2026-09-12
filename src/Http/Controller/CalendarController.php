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
        private readonly Translator $translator,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $month = $this->month($query['month'] ?? null);
        $mode = $this->mode($query['mode'] ?? null);
        $statusIds = $this->queryInts($query, 'status_id');
        $statusInvert = $statusIds !== [] && ($query['status_invert'] ?? null) === '1';
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
            $statusIds,
            $typeIds,
            $customerId,
            $priorities,
            $statusInvert,
            $typeInvert,
            $priorityInvert,
            $customerQuery,
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
            'status_id' => $statusIds,
            'status_invert' => $statusInvert,
            'type_id' => $typeIds,
            'type_invert' => $typeInvert,
            'customer_id' => $customerId,
            'customer' => $customerQuery,
            'priority' => $priorityNames,
            'priority_invert' => $priorityInvert,
        ];

        $monthLabel = $this->translator->trans('month.' . $firstDay->format('m')) . ' ' . $firstDay->format('Y');

        return $this->view->render($response, 'tasks/calendar.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername(),
            'month' => $month,
            'month_label' => $monthLabel,
            'previous_query' => $this->monthQuery($firstDay->modify('-1 month'), $filters),
            'next_query' => $this->monthQuery($firstDay->modify('+1 month'), $filters),
            'days' => $days,
            'filters' => $filters,
            'statuses' => $this->statuses->listForUser($userId),
            'types' => $this->taskTypes->listForUser($userId),
            'customers' => $this->customers->listForUser($userId, 500),
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
     * @param array{mode:string,status_id:list<int>,status_invert:bool,type_id:list<int>,type_invert:bool,customer_id:?int,customer:string,priority:list<string>,priority_invert:bool} $filters
     */
    private function monthQuery(DateTimeImmutable $month, array $filters): string
    {
        $params = ['month' => $month->format('Y-m'), 'mode' => $filters['mode']];
        foreach (['status_id', 'type_id', 'priority'] as $key) {
            if ($filters[$key] !== []) {
                $params[$key] = $filters[$key];
            }
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

    /** @return array<int, StatusRecord> */
    private function statusMap(int $userId): array
    {
        $map = [];
        foreach ($this->statuses->listForUser($userId) as $status) {
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
