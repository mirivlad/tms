<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Application\TaskListSorter;
use Tms\Domain\Attachment\AttachmentRecord;
use Tms\Domain\Attachment\AttachmentRepository;
use Tms\Domain\Customer\CustomerRecord;
use Tms\Domain\Customer\CustomerRepository;
use Tms\Domain\CustomField\CustomFieldRecord;
use Tms\Domain\CustomField\CustomFieldRepository;
use Tms\Domain\CustomField\CustomFieldValueCodec;
use Tms\Domain\CustomField\TaskCustomFieldValueRepository;
use Tms\Domain\Status\StatusRecord;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRecord;
use Tms\Domain\Task\TaskRepository;
use Tms\Domain\TaskType\TaskTypeRecord;
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
        private readonly AttachmentRepository $attachments,
        private readonly StatusRepository $statuses,
        private readonly TaskTypeRepository $taskTypes,
        private readonly CustomerRepository $customers,
        private readonly CustomFieldRepository $customFields,
        private readonly TaskCustomFieldValueRepository $customValues,
        private readonly CustomFieldValueCodec $customValueCodec,
        private readonly TaskListSorter $taskListSorter,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId();
        $query = $request->getQueryParams();
        $statusId = $this->queryInt($query, 'status_id');
        $statusInvert = $statusId !== null && ($query['status_invert'] ?? null) === '1';
        $typeId = $this->queryInt($query, 'type_id');
        $priorityName = is_string($query['priority'] ?? null) ? trim((string) $query['priority']) : '';
        $priority = self::PRIORITIES[$priorityName] ?? null;
        if ($priority === null) {
            $priorityName = '';
        }
        $search = $this->queryString($query, 'q');
        $customerQuery = $this->queryString($query, 'customer');
        $overdue = ($query['overdue'] ?? null) === '1';
        $deadlineFrom = $this->queryDate($query, 'deadline_from');
        $deadlineTo = $this->queryDate($query, 'deadline_to');
        $createdFrom = $this->queryDate($query, 'created_from');
        $createdTo = $this->queryDate($query, 'created_to');

        $fields = $this->customFields->listForUser($userId);
        $customFilters = $this->customFilters($query, $fields);
        $tasks = $this->tasks->listFilteredForUser(
            $userId,
            statusId: $statusId,
            statusInvert: $statusInvert,
            typeId: $typeId,
            customerQuery: $customerQuery,
            priority: $priority,
            query: $search,
            overdue: $overdue,
            deadlineFrom: $deadlineFrom,
            deadlineTo: $deadlineTo,
            createdFrom: $createdFrom,
            createdTo: $createdTo,
        );

        $taskIds = array_map(static fn (TaskRecord $task): int => $task->id, $tasks);
        $valuesByTask = $this->customValues->listForTasks($userId, $taskIds);
        $tasks = $this->filterByCustomFields($tasks, $fields, $valuesByTask, $customFilters);

        $statusMap = $this->statusMap($userId);
        $typeMap = $this->typeMap($userId);
        $customerMap = $this->customerMap($userId);
        $statusNames = [];
        foreach ($statusMap as $id => $status) {
            $statusNames[$id] = $status->name;
        }
        $typeNames = [];
        foreach ($typeMap as $id => $type) {
            $typeNames[$id] = $type->name;
        }
        $customerNames = [];
        foreach ($customerMap as $id => $customer) {
            $customerNames[$id] = $customer->name;
        }

        $sortField = is_string($query['sort'] ?? null) ? trim((string) $query['sort']) : 'deadline';
        $sortOrder = is_string($query['order'] ?? null) && strtolower((string) $query['order']) === 'asc'
            ? 'asc'
            : 'desc';
        if (!$this->taskListSorter->supports($sortField, $fields)) {
            $sortField = 'deadline';
            $sortOrder = 'desc';
        }
        $tasks = $this->taskListSorter->sort(
            $tasks,
            $sortField,
            $sortOrder,
            $statusNames,
            $typeNames,
            $customerNames,
            $fields,
            $valuesByTask,
        );

        $perPage = $this->perPage($query);
        $totalTasks = count($tasks);
        $totalPages = max(1, (int) ceil($totalTasks / $perPage));
        $page = min($this->page($query), $totalPages);
        $tasks = array_slice($tasks, ($page - 1) * $perPage, $perPage);

        $filters = [
            'status_id' => $statusId,
            'status_invert' => $statusInvert,
            'type_id' => $typeId,
            'priority' => $priorityName,
            'q' => $search,
            'customer' => $customerQuery,
            'overdue' => $overdue,
            'deadline_from' => $deadlineFrom,
            'deadline_to' => $deadlineTo,
            'created_from' => $createdFrom,
            'created_to' => $createdTo,
        ];
        $baseParams = $this->filterQueryParams($filters, $customFilters);
        $viewParams = $baseParams + [
            'sort' => $sortField,
            'order' => $sortOrder,
            'per_page' => $perPage,
        ];

        $bulkNotice = $_SESSION['bulk_notice'] ?? null;
        unset($_SESSION['bulk_notice']);
        if (!is_array($bulkNotice) || !is_string($bulkNotice['message'] ?? null)) {
            $bulkNotice = null;
        }

        return $this->view->render($response, 'tasks/index.twig', $this->commonViewData($request) + [
            'tasks' => $tasks,
            'status_map' => $statusMap,
            'type_map' => $typeMap,
            'customer_map' => $customerMap,
            'custom_fields' => $fields,
            'custom_values' => $valuesByTask,
            'custom_filters' => $customFilters,
            'filters' => $filters,
            'active_filters' => $this->activeFilterChips(
                $filters,
                $customFilters,
                $fields,
                $statusMap,
                $typeMap,
                $viewParams,
            ),
            'sort_field' => $sortField,
            'sort_order' => $sortOrder,
            'sort_links' => $this->sortLinks($baseParams, $fields, $sortField, $sortOrder, $perPage),
            'priority_labels' => $this->priorityLabels(),
            'total_tasks' => $totalTasks,
            'current_page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'pagination' => $this->pagination($viewParams, $page, $totalPages),
            'per_page_links' => $this->perPageLinks($viewParams, $perPage),
            'bulk_notice' => $bulkNotice,
        ]);
    }

    /** @param array<string, string> $args */
    public function showJson(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId();
        $task = $this->tasks->findForUser($userId, $this->taskId($args));
        if ($task === null) {
            return $this->json($response, ['error' => $this->translator->trans('task_preview.not_found')], 404);
        }

        $status = $task->statusId === null ? null : ($this->statusMap($userId)[$task->statusId] ?? null);
        $type = $task->typeId === null ? null : ($this->typeMap($userId)[$task->typeId] ?? null);
        $customer = $task->customerId === null ? null : ($this->customerMap($userId)[$task->customerId] ?? null);
        $fields = $this->customFields->listForUser($userId);
        $values = $this->customValues->listForTasks($userId, [$task->id])[$task->id] ?? [];
        $custom = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field->id, $values)) {
                continue;
            }
            $custom[] = [
                'name' => $field->name,
                'value' => $this->customValueCodec->display($field, $values[$field->id]),
            ];
        }

        $attachments = array_map(
            static fn (AttachmentRecord $attachment): array => [
                'id' => $attachment->id,
                'name' => $attachment->originalName,
                'mime_type' => $attachment->mimeType,
                'file_size' => $attachment->fileSize,
                'download_url' => '/tasks/' . $task->id . '/attachments/' . $attachment->id,
            ],
            $this->attachments->listForTask($userId, $task->id),
        );

        return $this->json($response, [
            'id' => $task->id,
            'title' => $task->title,
            'description' => trim(strip_tags($task->description)),
            'status' => $status?->name,
            'status_color' => $status?->color,
            'type' => $type?->name,
            'priority' => $this->priorityLabels()[$task->priority] ?? $this->translator->trans('priority.medium'),
            'customer' => $customer?->name,
            'deadline' => $task->deadline,
            'created_at' => $task->createdAt,
            'updated_at' => $task->updatedAt,
            'custom_fields' => $custom,
            'attachments' => $attachments,
            'edit_url' => '/tasks/' . $task->id . '/edit',
            'delete_url' => '/tasks/' . $task->id . '/delete',
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
            $customInput = $this->customInput($body, $this->customFields->listForUser($userId));
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
            $this->customValues->replaceForTask($userId, $taskId, $customInput);

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
            $customInput = $this->customInput($body, $this->customFields->listForUser($userId));
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
            $this->customValues->replaceForTask($userId, $taskId, $customInput);

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

    /** @param array<string, mixed> $formData */
    private function renderForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $formData,
        ?string $error,
        ?TaskRecord $task,
        int $status = 200,
    ): ResponseInterface {
        $userId = $this->userId();
        $fields = $this->customFields->listForUser($userId);
        $response = $response->withStatus($status);

        return $this->view->render($response, 'tasks/form.twig', $this->commonViewData($request) + [
            'task' => $task,
            'form' => $formData,
            'error' => $error,
            'statuses' => $this->statuses->listForUser($userId),
            'types' => $this->taskTypes->listForUser($userId),
            'custom_fields' => $fields,
            'custom_form_values' => $this->customFormValues($formData, $task, $fields),
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

    /**
     * @param array<string, mixed> $body
     * @param list<CustomFieldRecord> $fields
     * @return array<int, string|null>
     */
    private function customInput(array $body, array $fields): array
    {
        $submitted = is_array($body['custom_fields'] ?? null) ? $body['custom_fields'] : [];
        $values = [];
        foreach ($fields as $field) {
            $raw = $submitted[$field->id] ?? $submitted[(string) $field->id] ?? null;
            try {
                $values[$field->id] = $this->customValueCodec->encode($field, $raw);
            } catch (DomainException) {
                throw new DomainException($this->translator->trans(
                    'validation.custom_field_value_invalid',
                    ['field' => $field->name],
                ));
            }
        }
        return $values;
    }

    /**
     * @param array<string, mixed> $formData
     * @param list<CustomFieldRecord> $fields
     * @return array<int, mixed>
     */
    private function customFormValues(array $formData, ?TaskRecord $task, array $fields): array
    {
        if (is_array($formData['custom_fields'] ?? null)) {
            $values = [];
            foreach ($formData['custom_fields'] as $fieldId => $value) {
                if (ctype_digit((string) $fieldId)) {
                    $values[(int) $fieldId] = $value;
                }
            }
            return $values;
        }

        if ($task === null) {
            return [];
        }

        $stored = $this->customValues->listForTask($this->userId(), $task->id);
        $values = [];
        foreach ($fields as $field) {
            $value = $stored[$field->id] ?? null;
            if ($field->type === 'checkbox') {
                $values[$field->id] = $value === '1';
            } elseif ($field->type === 'checkbox_list') {
                $values[$field->id] = $this->customValueCodec->selectedOptions($field, $value);
            } else {
                $values[$field->id] = $value ?? '';
            }
        }
        return $values;
    }

    /**
     * @param array<string, mixed> $query
     * @param list<CustomFieldRecord> $fields
     * @return array<int, string>
     */
    private function customFilters(array $query, array $fields): array
    {
        $raw = is_array($query['custom'] ?? null) ? $query['custom'] : [];
        $owned = [];
        foreach ($fields as $field) {
            $owned[$field->id] = true;
        }

        $filters = [];
        foreach ($raw as $fieldId => $value) {
            if (!ctype_digit((string) $fieldId) || !is_scalar($value)) {
                continue;
            }
            $id = (int) $fieldId;
            $value = trim((string) $value);
            if ($value !== '' && isset($owned[$id])) {
                $filters[$id] = $value;
            }
        }
        return $filters;
    }

    /**
     * @param list<TaskRecord> $tasks
     * @param list<CustomFieldRecord> $fields
     * @param array<int, array<int, string>> $valuesByTask
     * @param array<int, string> $filters
     * @return list<TaskRecord>
     */
    private function filterByCustomFields(
        array $tasks,
        array $fields,
        array $valuesByTask,
        array $filters,
    ): array {
        if ($filters === []) {
            return $tasks;
        }

        $fieldsById = [];
        foreach ($fields as $field) {
            $fieldsById[$field->id] = $field;
        }

        return array_values(array_filter($tasks, function (TaskRecord $task) use ($fieldsById, $valuesByTask, $filters): bool {
            foreach ($filters as $fieldId => $needle) {
                $field = $fieldsById[$fieldId] ?? null;
                if (!$field instanceof CustomFieldRecord) {
                    return false;
                }
                $stored = $valuesByTask[$task->id][$fieldId] ?? null;
                if (!$this->customValueMatches($field, $stored, $needle)) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function customValueMatches(CustomFieldRecord $field, ?string $stored, string $needle): bool
    {
        if ($stored === null) {
            return false;
        }

        return match ($field->type) {
            'text', 'textarea' => mb_stripos($stored, $needle) !== false,
            'checkbox_list' => in_array($needle, $this->customValueCodec->selectedOptions($field, $stored), true),
            default => $stored === $needle,
        };
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

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($json === false ? '{}': $json);
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
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

    /** @return array<int, TaskTypeRecord> */
    private function typeMap(int $userId): array
    {
        $map = [];
        foreach ($this->taskTypes->listForUser($userId) as $type) {
            $map[$type->id] = $type;
        }
        return $map;
    }

    /** @return array<int, CustomerRecord> */
    private function customerMap(int $userId): array
    {
        $map = [];
        foreach ($this->customers->listAllForUser($userId) as $customer) {
            $map[$customer->id] = $customer;
        }
        return $map;
    }

    /**
     * @param array<string, scalar|array<int, string>> $baseParams
     * @param list<CustomFieldRecord> $fields
     * @return array<string, array{url:string,active:bool,order:string}>
     */
    private function sortLinks(
        array $baseParams,
        array $fields,
        string $currentField,
        string $currentOrder,
        int $perPage,
    ): array {
        $keys = ['title', 'status_name', 'type_name', 'priority', 'customer', 'created_at', 'deadline'];
        foreach ($fields as $field) {
            $keys[] = 'custom_' . $field->id;
        }

        $links = [];
        foreach ($keys as $key) {
            $active = $currentField === $key;
            $nextOrder = $active && $currentOrder === 'asc' ? 'desc' : 'asc';
            $query = $baseParams + [
                'sort' => $key,
                'order' => $nextOrder,
                'per_page' => $perPage,
            ];
            $links[$key] = [
                'url' => $this->tasksUrl($query),
                'active' => $active,
                'order' => $active ? $currentOrder : '',
            ];
        }
        return $links;
    }

    /**
     * @param array{status_id:?int,status_invert:bool,type_id:?int,priority:string,q:string,customer:string,overdue:bool,deadline_from:string,deadline_to:string,created_from:string,created_to:string} $filters
     * @param array<int, string> $customFilters
     * @return array<string, scalar|array<int, string>>
     */
    private function filterQueryParams(array $filters, array $customFilters): array
    {
        $params = [];
        if ($filters['status_id'] !== null) {
            $params['status_id'] = $filters['status_id'];
            if ($filters['status_invert']) {
                $params['status_invert'] = '1';
            }
        }
        if ($filters['type_id'] !== null) {
            $params['type_id'] = $filters['type_id'];
        }
        foreach (['priority', 'q', 'customer', 'deadline_from', 'deadline_to', 'created_from', 'created_to'] as $key) {
            if ($filters[$key] !== '') {
                $params[$key] = $filters[$key];
            }
        }
        if ($filters['overdue']) {
            $params['overdue'] = '1';
        }
        if ($customFilters !== []) {
            $params['custom'] = $customFilters;
        }
        return $params;
    }

    /**
     * @param array{status_id:?int,status_invert:bool,type_id:?int,priority:string,q:string,customer:string,overdue:bool,deadline_from:string,deadline_to:string,created_from:string,created_to:string} $filters
     * @param array<int, string> $customFilters
     * @param list<CustomFieldRecord> $fields
     * @param array<int, StatusRecord> $statusMap
     * @param array<int, TaskTypeRecord> $typeMap
     * @param array<string, scalar|array<int, string>> $viewParams
     * @return list<array{label:string,value:string,url:string}>
     */
    private function activeFilterChips(
        array $filters,
        array $customFilters,
        array $fields,
        array $statusMap,
        array $typeMap,
        array $viewParams,
    ): array {
        $chips = [];
        $add = function (string $key, string $label, string $value = '') use (&$chips, $viewParams): void {
            $params = $viewParams;
            unset($params[$key]);
            if ($key === 'status_id') {
                unset($params['status_invert']);
            }
            $chips[] = ['label' => $label, 'value' => $value, 'url' => $this->tasksUrl($params)];
        };

        if ($filters['q'] !== '') {
            $add('q', $this->translator->trans('tasks.search'), $filters['q']);
        }
        if ($filters['status_id'] !== null) {
            $name = $statusMap[$filters['status_id']]->name ?? (string) $filters['status_id'];
            $add('status_id', $this->translator->trans('tasks.status'), ($filters['status_invert'] ? '≠ ' : '') . $name);
        }
        if ($filters['type_id'] !== null) {
            $name = $typeMap[$filters['type_id']]->name ?? (string) $filters['type_id'];
            $add('type_id', $this->translator->trans('tasks.type'), $name);
        }
        if ($filters['priority'] !== '') {
            $priority = self::PRIORITIES[$filters['priority']] ?? 1;
            $add('priority', $this->translator->trans('tasks.priority'), $this->priorityLabels()[$priority]);
        }
        if ($filters['customer'] !== '') {
            $add('customer', $this->translator->trans('tasks.customer'), $filters['customer']);
        }
        if ($filters['overdue']) {
            $add('overdue', $this->translator->trans('tasks.overdue_only'));
        }
        foreach ([
            'deadline_from' => 'tasks.deadline_from',
            'deadline_to' => 'tasks.deadline_to',
            'created_from' => 'tasks.created_from',
            'created_to' => 'tasks.created_to',
        ] as $key => $translationKey) {
            if ($filters[$key] !== '') {
                $add($key, $this->translator->trans($translationKey), $filters[$key]);
            }
        }

        $fieldsById = [];
        foreach ($fields as $field) {
            $fieldsById[$field->id] = $field;
        }
        foreach ($customFilters as $fieldId => $value) {
            $field = $fieldsById[$fieldId] ?? null;
            if (!$field instanceof CustomFieldRecord) {
                continue;
            }
            $display = $value;
            if ($field->type === 'checkbox') {
                $display = $value === '1'
                    ? $this->translator->trans('common.yes')
                    : $this->translator->trans('common.no');
            }
            $params = $viewParams;
            $custom = is_array($params['custom'] ?? null) ? $params['custom'] : [];
            unset($custom[$fieldId]);
            if ($custom === []) {
                unset($params['custom']);
            } else {
                $params['custom'] = $custom;
            }
            $chips[] = [
                'label' => $field->name,
                'value' => $display,
                'url' => $this->tasksUrl($params),
            ];
        }
        return $chips;
    }

    /**
     * @param array<string, scalar|array<int, string>> $viewParams
     * @return array{previous:?string,next:?string,items:list<array{label:string,url:?string,active:bool}>}
     */
    private function pagination(array $viewParams, int $page, int $totalPages): array
    {
        if ($totalPages <= 1) {
            return ['previous' => null, 'next' => null, 'items' => []];
        }

        $pages = [1, $totalPages];
        for ($candidate = max(1, $page - 2); $candidate <= min($totalPages, $page + 2); $candidate++) {
            $pages[] = $candidate;
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        $items = [];
        $previousPage = 0;
        foreach ($pages as $candidate) {
            if ($previousPage !== 0 && $candidate > $previousPage + 1) {
                $items[] = ['label' => '…', 'url' => null, 'active' => false];
            }
            $params = $viewParams;
            $params['page'] = $candidate;
            $items[] = [
                'label' => (string) $candidate,
                'url' => $this->tasksUrl($params),
                'active' => $candidate === $page,
            ];
            $previousPage = $candidate;
        }

        $previous = null;
        if ($page > 1) {
            $params = $viewParams;
            $params['page'] = $page - 1;
            $previous = $this->tasksUrl($params);
        }
        $next = null;
        if ($page < $totalPages) {
            $params = $viewParams;
            $params['page'] = $page + 1;
            $next = $this->tasksUrl($params);
        }

        return ['previous' => $previous, 'next' => $next, 'items' => $items];
    }

    /**
     * @param array<string, scalar|array<int, string>> $viewParams
     * @return list<array{value:int,url:string,active:bool}>
     */
    private function perPageLinks(array $viewParams, int $current): array
    {
        $links = [];
        foreach ([10, 25, 50, 100] as $value) {
            $params = $viewParams;
            unset($params['page']);
            $params['per_page'] = $value;
            $links[] = [
                'value' => $value,
                'url' => $this->tasksUrl($params),
                'active' => $value === $current,
            ];
        }
        return $links;
    }

    /** @param array<string, scalar|array<int, string>> $params */
    private function tasksUrl(array $params): string
    {
        return $params === []
            ? '/tasks'
            : '/tasks?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
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

    /** @param array<string, mixed> $query */
    private function queryString(array $query, string $key): string
    {
        $value = $query[$key] ?? null;
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $query */
    private function queryDate(array $query, string $key): string
    {
        $value = $this->queryString($query, $key);
        if ($value === '') {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    /** @param array<string, mixed> $query */
    private function page(array $query): int
    {
        $value = $query['page'] ?? null;
        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : 1;
    }

    /** @param array<string, mixed> $query */
    private function perPage(array $query): int
    {
        $value = $query['per_page'] ?? null;
        $value = is_scalar($value) && ctype_digit((string) $value) ? (int) $value : 25;
        return in_array($value, [10, 25, 50, 100], true) ? $value : 25;
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
