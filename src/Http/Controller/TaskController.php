<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use DomainException;
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

final class TaskController
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

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId();
        $query = $request->getQueryParams();
        $statusId = $this->queryInt($query, 'status_id');
        $typeId = $this->queryInt($query, 'type_id');
        $customerId = $this->queryInt($query, 'customer_id');
        $priorityName = is_string($query['priority'] ?? null) ? (string) $query['priority'] : '';
        $priority = self::PRIORITIES[$priorityName] ?? null;
        $search = is_string($query['q'] ?? null) ? (string) $query['q'] : '';
        $overdue = ($query['overdue'] ?? null) === '1';

        $tasks = $this->tasks->listFilteredForUser(
            $userId,
            statusId: $statusId,
            typeId: $typeId,
            customerId: $customerId,
            priority: $priority,
            query: $search,
            overdue: $overdue,
        );

        return $this->view->render($response, 'tasks/index.twig', $this->commonViewData($request) + [
            'tasks' => $tasks,
            'status_map' => $this->statusMap($userId),
            'type_map' => $this->typeMap($userId),
            'customer_map' => $this->customerMap($userId),
            'filters' => [
                'status_id' => $statusId,
                'type_id' => $typeId,
                'customer_id' => $customerId,
                'priority' => $priorityName,
                'q' => $search,
                'overdue' => $overdue,
            ],
            'priority_labels' => $this->priorityLabels(),
        ]);
    }

    public function board(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId();
        $statuses = $this->statuses->listForUser($userId, true);
        $tasksByStatus = [];
        foreach ($statuses as $status) {
            $tasksByStatus[$status->id] = [];
        }

        $cutoff = (new DateTimeImmutable('-7 days'))->getTimestamp();
        $statusById = [];
        foreach ($statuses as $status) {
            $statusById[$status->id] = $status;
        }

        foreach ($this->tasks->listForUser($userId) as $task) {
            if ($task->statusId === null || !isset($statusById[$task->statusId])) {
                continue;
            }
            $status = $statusById[$task->statusId];
            if ($status->isCompletion && strtotime($task->updatedAt) < $cutoff) {
                continue;
            }
            $tasksByStatus[$task->statusId][] = $task;
        }

        return $this->view->render($response, 'tasks/board.twig', $this->commonViewData($request) + [
            'statuses' => $statuses,
            'tasks_by_status' => $tasksByStatus,
            'type_map' => $this->typeMap($userId),
            'customer_map' => $this->customerMap($userId),
            'priority_labels' => $this->priorityLabels(),
        ]);
    }

    public function new(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId();
        $statuses = $this->statuses->listForUser($userId);
        $defaultStatusId = null;
        foreach ($statuses as $status) {
            if ($status->isDefault) {
                $defaultStatusId = $status->id;
                break;
            }
        }

        return $this->renderForm($request, $response, [
            'title' => '',
            'description' => '',
            'deadline' => '',
            'status_id' => $defaultStatusId,
            'type_id' => null,
            'priority' => 'medium',
            'customer' => '',
        ], null, null);
    }

    /** @param array<string, string> $args */
    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId();
        $task = $this->tasks->findForUser($userId, $this->taskId($args));
        if ($task === null) {
            return $this->notFound($response);
        }

        $customer = '';
        if ($task->customerId !== null) {
            $customerRecord = $this->customers->findForUser($userId, $task->customerId);
            if ($customerRecord !== null) {
                $customer = $customerRecord->name;
            }
        }

        return $this->renderForm($request, $response, [
            'title' => $task->title,
            'description' => $task->description,
            'deadline' => $this->deadlineForForm($task->deadline),
            'status_id' => $task->statusId,
            'type_id' => $task->typeId,
            'priority' => array_search($task->priority, self::PRIORITIES, true) ?: 'medium',
            'customer' => $customer,
        ], null, $task);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        try {
            $input = $this->taskInput($body);
            $userId = $this->userId();
            $this->assertMetadataForUser($userId, $input['status_id'], $input['type_id']);
            $customerId = $this->resolveCustomer($userId, $input['customer']);

            $taskId = $this->tasks->createForUser(
                $userId,
                $input['title'],
                $input['description'],
                $input['deadline'],
                $input['status_id'],
                $input['type_id'],
                $input['priority'],
                $customerId,
            );

            return $response->withHeader('Location', '/tasks/' . $taskId . '/edit')->withStatus(302);
        } catch (DomainException $error) {
            return $this->renderForm($request, $response, $body, $error->getMessage(), null, 422);
        }
    }

    /** @param array<string, string> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId();
        $taskId = $this->taskId($args);
        $task = $this->tasks->findForUser($userId, $taskId);
        if ($task === null) {
            return $this->notFound($response);
        }

        $body = $this->body($request);
        try {
            $input = $this->taskInput($body);
            $this->assertMetadataForUser($userId, $input['status_id'], $input['type_id']);
            $customerId = $this->resolveCustomer($userId, $input['customer']);

            $this->tasks->updateForUser(
                $userId,
                $taskId,
                $input['title'],
                $input['description'],
                $input['deadline'],
                $input['status_id'],
                $input['type_id'],
                $input['priority'],
                $customerId,
            );

            return $response->withHeader('Location', '/tasks')->withStatus(302);
        } catch (DomainException $error) {
            return $this->renderForm($request, $response, $body, $error->getMessage(), $task, 422);
        }
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->tasks->deleteForUser($this->userId(), $this->taskId($args));
        return $response->withHeader('Location', '/tasks')->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function move(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $this->body($request);
        $statusId = $this->bodyInt($body, 'status_id');
        if ($statusId !== null) {
            $this->tasks->updateStatusForUser($this->userId(), $this->taskId($args), $statusId);
        }
        return $response->withHeader('Location', '/board')->withStatus(302);
    }

    /**
     * @param array<string, mixed> $formData
     */
    private function renderForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $formData,
        ?string $error,
        ?TaskRecord $task,
        int $status = 200,
    ): ResponseInterface {
        $userId = $this->userId();
        $response = $response->withStatus($status);
        return $this->view->render($response, 'tasks/form.twig', $this->commonViewData($request) + [
            'task' => $task,
            'form' => $formData,
            'error' => $error,
            'statuses' => $this->statuses->listForUser($userId),
            'types' => $this->taskTypes->listForUser($userId),
            'customers' => $this->customers->listForUser($userId, 500),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{title:string,description:string,deadline:?string,status_id:int,type_id:?int,priority:int,customer:string}
     */
    private function taskInput(array $body): array
    {
        $title = is_string($body['title'] ?? null) ? (string) $body['title'] : '';
        $description = is_string($body['description'] ?? null) ? (string) $body['description'] : '';
        $customer = is_string($body['customer'] ?? null) ? trim((string) $body['customer']) : '';
        $statusId = $this->bodyInt($body, 'status_id');
        $typeId = $this->bodyInt($body, 'type_id');
        $priorityName = is_string($body['priority'] ?? null) ? (string) $body['priority'] : 'medium';

        if (trim($title) === '') {
            throw new DomainException($this->translator->trans('validation.task_title_required'));
        }
        if ($statusId === null) {
            throw new DomainException($this->translator->trans('validation.task_status_required'));
        }
        if (!array_key_exists($priorityName, self::PRIORITIES)) {
            throw new DomainException($this->translator->trans('validation.unknown_priority'));
        }
        if ($customer !== '' && mb_strlen($customer) > 255) {
            throw new DomainException($this->translator->trans('validation.customer_name_too_long'));
        }

        return [
            'title' => $title,
            'description' => $description,
            'deadline' => $this->normalizeDeadline($body['deadline'] ?? null),
            'status_id' => $statusId,
            'type_id' => $typeId,
            'priority' => self::PRIORITIES[$priorityName],
            'customer' => $customer,
        ];
    }

    private function assertMetadataForUser(int $userId, int $statusId, ?int $typeId): void
    {
        if ($this->statuses->findForUser($userId, $statusId) === null) {
            throw new DomainException($this->translator->trans('validation.selected_status_unavailable'));
        }
        if ($typeId !== null && $this->taskTypes->findForUser($userId, $typeId) === null) {
            throw new DomainException($this->translator->trans('validation.selected_type_unavailable'));
        }
    }

    private function resolveCustomer(int $userId, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $existing = $this->customers->findByNameForUser($userId, $name);
        if ($existing !== null) {
            return $existing->id;
        }
        return $this->customers->createForUser($userId, $name);
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

    private function deadlineForForm(?string $deadline): string
    {
        if ($deadline === null || $deadline === '') {
            return '';
        }
        $timestamp = strtotime($deadline);
        return $timestamp === false ? '' : date('Y-m-d\\TH:i', $timestamp);
    }

    /** @return array<string, mixed> */
    private function commonViewData(ServerRequestInterface $request): array
    {
        return [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername(),
        ];
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

    /** @return array<int, object> */
    private function typeMap(int $userId): array
    {
        $map = [];
        foreach ($this->taskTypes->listForUser($userId) as $type) {
            $map[$type->id] = $type;
        }
        return $map;
    }

    /** @return array<int, object> */
    private function customerMap(int $userId): array
    {
        $map = [];
        foreach ($this->customers->listForUser($userId, 500) as $customer) {
            $map[$customer->id] = $customer;
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

    /** @param array<string, mixed> $query */
    private function queryInt(array $query, string $key): ?int
    {
        $value = $query[$key] ?? null;
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @param array<string, mixed> $body */
    private function bodyInt(array $body, string $key): ?int
    {
        $value = $body[$key] ?? null;
        if ($value === '' || $value === null) {
            return null;
        }
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /** @param array<string, string> $args */
    private function taskId(array $args): int
    {
        $id = $args['id'] ?? '';
        return ctype_digit($id) ? (int) $id : 0;
    }

    private function userId(): int
    {
        return $this->sessions->currentUserId() ?? 0;
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->translator->trans('validation.task_not_found'));
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
