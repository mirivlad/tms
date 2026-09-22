<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Activity\ActivityRepository;
use Tms\Application\TaskListSorter;
use Tms\Domain\Attachment\AttachmentRecord;
use Tms\Domain\Attachment\AttachmentRepository;
use Tms\Domain\Checklist\ChecklistRepository;
use Tms\Domain\Customer\CustomerRecord;
use Tms\Domain\Customer\CustomerRepository;
use Tms\Domain\CustomField\CustomFieldRecord;
use Tms\Domain\CustomField\CustomFieldRepository;
use Tms\Domain\CustomField\CustomFieldValueCodec;
use Tms\Domain\CustomField\TaskCustomFieldValueRepository;
use Tms\Domain\Discussion\DiscussionReadRepository;
use Tms\Domain\Project\ProjectCustomFieldRepository;
use Tms\Domain\Project\ProjectRecord;
use Tms\Domain\Project\ProjectStatusRepository;
use Tms\Domain\SavedView\SavedViewRepository;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Status\StatusRecord;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRecord;
use Tms\Domain\Task\TaskRepository;
use Tms\Domain\Team\TeamMemberRecord;
use Tms\Domain\Team\TeamRepository;
use Tms\Domain\TaskType\TaskTypeRecord;
use Tms\Domain\TaskType\TaskTypeRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;
use Tms\Security\TaskDescriptionSanitizer;

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
        private readonly ActivityRepository $activity,
        private readonly ChecklistRepository $checklists,
        private readonly SavedViewRepository $savedViews,
        private readonly TaskRecurrenceRepository $recurrences,
        private readonly AttachmentRepository $attachments,
        private readonly StatusRepository $statuses,
        private readonly TaskTypeRepository $taskTypes,
        private readonly CustomerRepository $customers,
        private readonly ProjectRepository $projects,
        private readonly ProjectStatusRepository $projectStatuses,
        private readonly ProjectCustomFieldRepository $projectCustomFields,
        private readonly TeamRepository $teams,
        private readonly DiscussionReadRepository $discussionReads,
        private readonly CustomFieldRepository $customFields,
        private readonly TaskCustomFieldValueRepository $customValues,
        private readonly CustomFieldValueCodec $customValueCodec,
        private readonly TaskListSorter $taskListSorter,
        private readonly Translator $translator,
        private readonly TaskDescriptionSanitizer $descriptionSanitizer,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId();
        $query = $request->getQueryParams();
        if ($query === []) {
            $defaultView = $this->savedViews->defaultForUser($userId);
            if ($defaultView !== null) {
                return $response
                    ->withHeader('Location', '/tasks/views/' . $defaultView->id)
                    ->withStatus(302);
            }
        }
        $activeSavedViewId = $this->queryInt($query, 'view');
        $activeSavedView = $activeSavedViewId === null
            ? null
            : $this->savedViews->findForUser($userId, $activeSavedViewId);
        [$projectId, $withoutProject, $projectFilter] = $this->projectFilter($query, $userId);
        $filterStatuses = $this->statusesForFilterScope($userId, $projectId, $projectFilter);
        $filterStatusIds = array_fill_keys(
            array_map(static fn (StatusRecord $status): int => $status->id, $filterStatuses),
            true,
        );

        $statusId = $this->queryInt($query, 'status_id');
        if ($statusId !== null && !isset($filterStatusIds[$statusId])) {
            $statusId = null;
        }
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

        $fields = $this->fieldsForScope($userId, $projectId);
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
            projectId: $projectId,
            withoutProject: $withoutProject,
        );

        $taskIds = array_map(static fn (TaskRecord $task): int => $task->id, $tasks);
        $valuesByTask = $this->customValues->listForTasks($userId, $taskIds);
        $tasks = $this->filterByCustomFields($tasks, $fields, $valuesByTask, $customFilters);

        $statusMap = $this->statusMap($userId);
        $typeMap = $this->typeMap($userId);
        $customerMap = $this->customerMap($userId);
        $projectMap = $this->projectMap($userId);
        $assigneeMap = $this->assigneeMapForProjects($userId, array_values($projectMap));
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
        $visibleTaskIds = array_map(static fn (TaskRecord $task): int => $task->id, $tasks);
        $discussionStats = $this->discussionReads->statsForTasks($userId, $visibleTaskIds);
        $checklistProgress = $this->checklists->progressForTasks($userId, $visibleTaskIds);

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
            'project' => $projectFilter,
        ];
        $baseParams = $this->filterQueryParams($filters, $customFilters);
        $viewParams = $baseParams + [
            'sort' => $sortField,
            'order' => $sortOrder,
            'per_page' => $perPage,
        ];

        $bulkNotice = $_SESSION['bulk_notice'] ?? null;
        unset($_SESSION['bulk_notice']);
        $savedViewNotice = $_SESSION['_saved_view_notice'] ?? null;
        unset($_SESSION['_saved_view_notice']);
        if (!is_array($bulkNotice) || !is_string($bulkNotice['message'] ?? null)) {
            $bulkNotice = null;
        }

        return $this->view->render($response, 'tasks/index.twig', $this->commonViewData($request) + [
            'tasks' => $tasks,
            'status_map' => $statusMap,
            'filter_statuses' => $filterStatuses,
            'type_map' => $typeMap,
            'customer_map' => $customerMap,
            'project_map' => $projectMap,
            'assignee_map' => $assigneeMap,
            'projects' => array_values($projectMap),
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
                $projectMap,
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
            'saved_views' => $this->savedViews->listForUser($userId),
            'active_saved_view_id' => $activeSavedView?->id,
            'saved_view_query' => http_build_query($viewParams, '', '&', PHP_QUERY_RFC3986),
            'saved_view_notice' => is_array($savedViewNotice) ? $savedViewNotice : null,
            'discussion_stats' => $discussionStats,
            'checklist_progress' => $checklistProgress,
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
        $project = $task->projectId === null ? null : ($this->projectMap($userId)[$task->projectId] ?? null);
        $assignee = null;
        if ($project?->ownerTeamId !== null && $task->assigneeUserId !== null) {
            foreach ($this->teams->listMembers($userId, $project->ownerTeamId) as $member) {
                if ($member->userId === $task->assigneeUserId) {
                    $assignee = $member;
                    break;
                }
            }
        }
        $fields = $this->fieldsForScope($userId, $task->projectId);
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

        $discussionEnabled = $project !== null && $project->isTeamOwned();
        $discussionStat = $discussionEnabled
            ? ($this->discussionReads->statsForTasks($userId, [$task->id])[$task->id] ?? ['count' => 0, 'unread' => 0])
            : ['count' => 0, 'unread' => 0];

        $checklistItems = $this->checklists->listForTask($userId, $task->id);
        $checklistCompleted = count(array_filter($checklistItems, static fn ($item): bool => $item->isCompleted));

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
            'description_html' => $this->descriptionSanitizer->sanitize($task->description),
            'status' => $status?->name,
            'status_id' => $task->statusId,
            'status_options' => array_map(
                static fn (StatusRecord $option): array => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'color' => $option->color,
                ],
                $this->statusesForScope($userId, $task->projectId),
            ),
            'status_color' => $status?->color,
            'type' => $type?->name,
            'priority' => $this->priorityLabels()[$task->priority] ?? $this->translator->trans('priority.medium'),
            'priority_value' => $task->priority,
            'customer' => $customer?->name,
            'project' => $project?->name,
            'project_id' => $task->projectId,
            'assignee' => $assignee?->username,
            'assignee_id' => $task->assigneeUserId,
            'deadline' => $task->deadline,
            'deadline_input' => $this->deadlineForForm($task->deadline),
            'created_at' => $task->createdAt,
            'updated_at' => $task->updatedAt,
            'custom_fields' => $custom,
            'checklist' => array_map(static fn ($item): array => [
                'id' => $item->id,
                'text' => $item->text,
                'completed' => $item->isCompleted,
            ], $checklistItems),
            'checklist_progress' => ['total' => count($checklistItems), 'completed' => $checklistCompleted],
            'attachments' => $attachments,
            'discussion_enabled' => $discussionEnabled,
            'discussion_count' => $discussionStat['count'],
            'discussion_unread' => $discussionStat['unread'],
            'discussion_url' => $discussionEnabled ? '/tasks/' . $task->id . '/discussion' : null,
            'edit_url' => '/tasks/' . $task->id . '/edit',
            'history_url' => '/tasks/' . $task->id . '/history',
            'quick_update_url' => '/api/tasks/' . $task->id . '/quick-edit',
            'delete_url' => '/tasks/' . $task->id . '/delete',
        ]);
    }

    /** @param array<string, string> $args */
    public function quickUpdate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId();
        $taskId = $this->taskId($args);
        $task = $this->tasks->findForUser($userId, $taskId);
        if ($task === null) {
            return $this->json($response, ['error' => $this->translator->trans('task_preview.not_found')], 404);
        }

        $body = $this->body($request);
        try {
            $statusId = $this->bodyInt($body, 'status_id');
            if ($statusId === null) {
                throw new DomainException($this->translator->trans('validation.task_status_required'));
            }
            $this->assertStatusForScope($userId, $statusId, $task->projectId);
            $description = is_string($body['description'] ?? null) ? (string) $body['description'] : '';
            $deadline = $this->normalizeDeadline($body['deadline'] ?? null);

            if (!$this->tasks->quickUpdateForUser($userId, $taskId, $description, $deadline, $statusId)) {
                return $this->json($response, ['error' => $this->translator->trans('task_preview.not_found')], 404);
            }
            $updated = $this->tasks->findForUser($userId, $taskId);
            if ($updated !== null) {
                $this->activity->recordTaskChanged($userId, $task, $updated);
            }

            return $this->json($response, [
                'success' => true,
                'message' => $this->translator->trans('task_preview.saved'),
            ]);
        } catch (DomainException $error) {
            return $this->json($response, [
                'success' => false,
                'message' => $error->getMessage(),
            ], 422);
        }
    }

    public function customFieldsFragment(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId();
        $query = $request->getQueryParams();
        $projectId = $this->queryInt($query, 'project_id');
        if ($projectId !== null && $this->projects->findForUser($userId, $projectId) === null) {
            $response->getBody()->write($this->translator->trans('validation.selected_project_unavailable'));
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $task = null;
        $taskId = $this->queryInt($query, 'task_id');
        if ($taskId !== null) {
            $task = $this->tasks->findForUser($userId, $taskId);
            if ($task === null) {
                return $this->notFound($response);
            }
        }

        $fields = $this->fieldsForScope($userId, $projectId);
        return $this->view->render($response, 'tasks/_custom_fields.twig', [
            'custom_fields' => $fields,
            'custom_form_values' => $task === null
                ? []
                : $this->mappedCustomFormValues($task, $projectId, $fields),
        ]);
    }

    public function assigneeOptionsJson(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId();
        $projectId = $this->queryInt($request->getQueryParams(), 'project_id');
        if ($projectId === null) {
            return $this->json($response, ['assignees' => []]);
        }

        $project = $this->projects->findForUser($userId, $projectId);
        if ($project === null) {
            return $this->json($response, ['error' => $this->translator->trans('validation.selected_project_unavailable')], 404);
        }

        $members = $this->assigneesForProject($userId, $projectId);
        return $this->json($response, [
            'assignees' => array_map(
                static fn (TeamMemberRecord $member): array => [
                    'id' => $member->userId,
                    'username' => $member->username,
                    'role' => $member->role,
                ],
                $members,
            ),
        ]);
    }

    public function statusOptionsJson(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId();
        $query = $request->getQueryParams();
        $scope = $this->queryString($query, 'scope');
        $projectId = $this->queryInt($query, 'project_id');
        if ($projectId !== null && $this->projects->findForUser($userId, $projectId) === null) {
            return $this->json($response, ['error' => $this->translator->trans('validation.selected_project_unavailable')], 404);
        }

        $statuses = $scope === 'all'
            ? $this->statuses->listAccessibleForUser($userId)
            : $this->statusesForScope($userId, $projectId);
        return $this->json($response, [
            'statuses' => array_map(
                static fn (StatusRecord $status): array => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'color' => $status->color,
                    'default' => $status->isDefault,
                ],
                $statuses,
            ),
            'default_status_id' => $this->defaultStatusId($statuses),
        ]);
    }

    public function board(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId();
        [$projectId, $withoutProject, $projectFilter] = $this->projectFilter($request->getQueryParams(), $userId);
        if ($projectId === null) {
            $withoutProject = true;
            $projectFilter = 'none';
        }
        $statuses = $projectId === null
            ? $this->statuses->listForUser($userId, true)
            : $this->projectStatuses->listForProject($userId, $projectId, true);
        $tasksByStatus = [];
        foreach ($statuses as $status) {
            $tasksByStatus[$status->id] = [];
        }

        $cutoff = (new DateTimeImmutable('-7 days'))->getTimestamp();
        $statusById = [];
        foreach ($statuses as $status) {
            $statusById[$status->id] = $status;
        }

        foreach ($this->tasks->listFilteredForUser($userId, projectId: $projectId, withoutProject: $withoutProject) as $task) {
            if ($task->statusId === null || !isset($statusById[$task->statusId])) {
                continue;
            }
            $status = $statusById[$task->statusId];
            if ($status->isCompletion && strtotime($task->updatedAt) < $cutoff) {
                continue;
            }
            $tasksByStatus[$task->statusId][] = $task;
        }

        $projects = $this->projects->listForUser($userId);
        $boardTaskIds = [];
        foreach ($tasksByStatus as $statusTasks) {
            foreach ($statusTasks as $task) {
                $boardTaskIds[] = $task->id;
            }
        }
        $discussionStats = $this->discussionReads->statsForTasks($userId, $boardTaskIds);

        return $this->view->render($response, 'tasks/board.twig', $this->commonViewData($request) + [
            'statuses' => $statuses,
            'tasks_by_status' => $tasksByStatus,
            'type_map' => $this->typeMap($userId),
            'customer_map' => $this->customerMap($userId),
            'project_map' => $this->projectMap($userId),
            'projects' => $projects,
            'assignee_map' => $this->assigneeMapForProjects($userId, $projects),
            'project_filter' => $projectFilter,
            'priority_labels' => $this->priorityLabels(),
            'discussion_stats' => $discussionStats,
        ]);
    }

    public function new(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId();
        $projectId = $this->queryInt($request->getQueryParams(), 'project_id');
        if ($projectId !== null && $this->projects->findForUser($userId, $projectId) === null) {
            $projectId = null;
        }
        $statuses = $this->statusesForScope($userId, $projectId);
        $defaultStatusId = $this->defaultStatusId($statuses);

        return $this->renderForm($request, $response, [
            'title' => '',
            'description' => '',
            'deadline' => '',
            'status_id' => $defaultStatusId,
            'type_id' => null,
            'priority' => 'medium',
            'customer' => '',
            'project_id' => $projectId,
            'assignee_user_id' => null,
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
            'project_id' => $task->projectId,
            'assignee_user_id' => $task->assigneeUserId,
        ], null, $task);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        try {
            $input = $this->taskInput($body);
            $userId = $this->userId();
            $this->assertProjectForUser($userId, $input['project_id']);
            $this->assertStatusForScope($userId, $input['status_id'], $input['project_id']);
            $project = $input['project_id'] === null
                ? null
                : $this->projects->findForUser($userId, $input['project_id']);
            $teamProject = $project?->isTeamOwned() ?? false;
            if ($teamProject && ($input['type_id'] !== null || $input['customer'] !== '')) {
                throw new DomainException($this->translator->trans('validation.team_project_personal_metadata'));
            }
            $this->assertMetadataForUser($userId, $input['type_id']);
            $this->assertAssigneeForProject($userId, $input['project_id'], $input['assignee_user_id']);
            $customInput = $this->customInput($body, $this->fieldsForScope($userId, $input['project_id']));
            $customerId = $teamProject ? null : $this->resolveCustomer($userId, $input['customer']);

            $taskId = $this->tasks->createForUser(
                $userId,
                $input['title'],
                $input['description'],
                $input['deadline'],
                $input['status_id'],
                $input['type_id'],
                $input['priority'],
                $customerId,
                $input['project_id'],
                $input['assignee_user_id'],
            );
            $this->customValues->replaceForTask($userId, $taskId, $customInput);
            $created = $this->tasks->findForUser($userId, $taskId);
            if ($created !== null) {
                $this->activity->recordTaskCreated($userId, $created);
            }

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
            $this->assertProjectForUser($userId, $input['project_id']);
            $this->assertStatusForScope($userId, $input['status_id'], $input['project_id']);
            $project = $input['project_id'] === null
                ? null
                : $this->projects->findForUser($userId, $input['project_id']);
            $teamProject = $project?->isTeamOwned() ?? false;
            if ($teamProject && ($input['type_id'] !== null || $input['customer'] !== '')) {
                throw new DomainException($this->translator->trans('validation.team_project_personal_metadata'));
            }
            $this->assertMetadataForUser($userId, $input['type_id']);
            $this->assertAssigneeForProject($userId, $input['project_id'], $input['assignee_user_id']);
            $customInput = $this->customInput($body, $this->fieldsForScope($userId, $input['project_id']));
            $customerId = $teamProject ? null : $this->resolveCustomer($userId, $input['customer']);

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
                $input['project_id'],
                $input['assignee_user_id'],
            );
            $this->customValues->replaceForTask($userId, $taskId, $customInput);
            $updated = $this->tasks->findForUser($userId, $taskId);
            if ($updated !== null) {
                $this->activity->recordTaskChanged($userId, $task, $updated);
            }

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
        $projectId = $this->formProjectId($formData, $task);
        $project = $projectId === null ? null : $this->projects->findForUser($userId, $projectId);
        $teamProject = $project?->isTeamOwned() ?? false;
        $scopeLocked = $task !== null && $task->ownerId !== $userId;
        $discussionEnabled = $task !== null
            && $project !== null
            && $project->isTeamOwned()
            && $project->ownerTeamId !== null;
        $discussionStat = $discussionEnabled
            ? ($this->discussionReads->statsForTasks($userId, [$task->id])[$task->id] ?? ['count' => 0, 'unread' => 0])
            : ['count' => 0, 'unread' => 0];
        $fields = $this->fieldsForScope($userId, $projectId);
        $scopeStatuses = $this->statusesForScope($userId, $projectId);
        $checklistItems = $task === null ? [] : $this->checklists->listForTask($userId, $task->id);
        $checklistCompleted = count(array_filter($checklistItems, static fn ($item): bool => $item->isCompleted));
        $checklistNotice = $_SESSION['_checklist_notice'] ?? null;
        unset($_SESSION['_checklist_notice']);
        $canManageRecurrence = $task !== null && $task->ownerId === $userId;
        $recurrence = $canManageRecurrence ? $this->recurrences->findForTask($userId, $task->id) : null;
        $recurrenceNotice = $_SESSION['_recurrence_notice'] ?? null;
        unset($_SESSION['_recurrence_notice']);
        $recurrenceStatuses = array_values(array_filter(
            $scopeStatuses,
            static fn (StatusRecord $status): bool => !$status->isCompletion,
        ));
        $response = $response->withStatus($status);

        return $this->view->render($response, 'tasks/form.twig', $this->commonViewData($request) + [
            'task' => $task,
            'form' => $formData,
            'error' => $error,
            'statuses' => $scopeStatuses,
            'status_options_url' => '/api/task-statuses',
            'custom_fields_url' => '/api/task-custom-fields',
            'assignee_options_url' => '/api/task-assignees',
            'types' => $this->taskTypes->listForUser($userId),
            'projects' => $this->projects->listForUser($userId),
            'team_project' => $teamProject,
            'assignees' => $this->assigneesForProject($userId, $projectId),
            'discussion_enabled' => $discussionEnabled,
            'discussion_stat' => $discussionStat,
            'scope_locked' => $scopeLocked,
            'custom_fields' => $fields,
            'checklist_items' => $checklistItems,
            'checklist_progress' => ['total' => count($checklistItems), 'completed' => $checklistCompleted],
            'checklist_notice' => is_array($checklistNotice) ? $checklistNotice : null,
            'can_manage_recurrence' => $canManageRecurrence,
            'recurrence' => $recurrence,
            'recurrence_statuses' => $recurrenceStatuses,
            'recurrence_notice' => is_array($recurrenceNotice) ? $recurrenceNotice : null,
            'custom_form_values' => $this->customFormValues($formData, $task, $fields, $projectId),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{title:string,description:string,deadline:?string,status_id:int,type_id:?int,priority:int,customer:string,project_id:?int,assignee_user_id:?int}
     */
    private function taskInput(array $body): array
    {
        $title = is_string($body['title'] ?? null) ? (string) $body['title'] : '';
        $description = is_string($body['description'] ?? null) ? (string) $body['description'] : '';
        $customer = is_string($body['customer'] ?? null) ? trim((string) $body['customer']) : '';
        $statusId = $this->bodyInt($body, 'status_id');
        $typeId = $this->bodyInt($body, 'type_id');
        $projectId = $this->bodyInt($body, 'project_id');
        $assigneeUserId = $this->bodyInt($body, 'assignee_user_id');
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
            'project_id' => $projectId,
            'assignee_user_id' => $assigneeUserId,
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
    private function customFormValues(
        array $formData,
        ?TaskRecord $task,
        array $fields,
        ?int $targetProjectId,
    ): array
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

        return $this->mappedCustomFormValues($task, $targetProjectId, $fields);
    }

    /**
     * @param array<string, mixed> $query
     * @param list<CustomFieldRecord> $fields
     * @return array<int, mixed>
     */
    private function customFilters(array $query, array $fields): array
    {
        $raw = is_array($query['custom'] ?? null) ? $query['custom'] : [];
        $fieldsById = [];
        foreach ($fields as $field) {
            $fieldsById[$field->id] = $field;
        }

        $filters = [];
        foreach ($raw as $fieldId => $rawValue) {
            if (!ctype_digit((string) $fieldId)) {
                continue;
            }
            $id = (int) $fieldId;
            $field = $fieldsById[$id] ?? null;
            if (!$field instanceof CustomFieldRecord) {
                continue;
            }

            if (in_array($field->type, ['text', 'textarea'], true)) {
                $value = is_scalar($rawValue) ? trim((string) $rawValue) : '';
                if ($value !== '') {
                    $filters[$id] = $value;
                }
                continue;
            }

            if ($field->type === 'select') {
                $value = is_scalar($rawValue) ? trim((string) $rawValue) : '';
                if ($value !== '' && in_array($value, $field->options, true)) {
                    $filters[$id] = $value;
                }
                continue;
            }

            if ($field->type === 'checkbox') {
                $value = is_scalar($rawValue) ? trim((string) $rawValue) : '';
                if (in_array($value, ['0', '1'], true)) {
                    $filters[$id] = $value;
                }
                continue;
            }

            if ($field->type === 'money') {
                $range = is_array($rawValue) ? $rawValue : ['min' => $rawValue, 'max' => $rawValue];
                $normalized = [];
                foreach (['min', 'max'] as $bound) {
                    try {
                        $value = $this->customValueCodec->normalizeMoneyInput($range[$bound] ?? null);
                    } catch (DomainException) {
                        $value = null;
                    }
                    if ($value !== null) {
                        $normalized[$bound] = $value;
                    }
                }
                if ($normalized !== []) {
                    $filters[$id] = $normalized;
                }
                continue;
            }

            if ($field->type === 'checkbox_list') {
                $payload = is_array($rawValue) ? $rawValue : ['values' => [$rawValue]];
                $rawValues = $payload['values'] ?? [];
                if (is_scalar($rawValues)) {
                    $rawValues = [$rawValues];
                }
                $values = [];
                if (is_array($rawValues)) {
                    foreach ($rawValues as $value) {
                        if (!is_scalar($value)) {
                            continue;
                        }
                        $value = trim((string) $value);
                        if (in_array($value, $field->options, true) && !in_array($value, $values, true)) {
                            $values[] = $value;
                        }
                    }
                }
                if ($values !== []) {
                    $filters[$id] = [
                        'values' => $values,
                        'match' => ($payload['match'] ?? null) === 'all' ? 'all' : 'any',
                    ];
                }
            }
        }
        return $filters;
    }

    /**
     * @param list<TaskRecord> $tasks
     * @param list<CustomFieldRecord> $fields
     * @param array<int, array<int, string>> $valuesByTask
     * @param array<int, mixed> $filters
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
            foreach ($filters as $fieldId => $filter) {
                $field = $fieldsById[$fieldId] ?? null;
                if (!$field instanceof CustomFieldRecord) {
                    return false;
                }
                $stored = $valuesByTask[$task->id][$fieldId] ?? null;
                if (!$this->customValueMatches($field, $stored, $filter)) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function customValueMatches(CustomFieldRecord $field, ?string $stored, mixed $filter): bool
    {
        if ($stored === null) {
            return false;
        }

        if (in_array($field->type, ['text', 'textarea'], true)) {
            return is_string($filter) && mb_stripos($stored, $filter) !== false;
        }
        if (in_array($field->type, ['select', 'checkbox'], true)) {
            return is_string($filter) && $stored === $filter;
        }
        if ($field->type === 'money') {
            if (!is_array($filter)) {
                return false;
            }
            try {
                $storedMinor = $this->customValueCodec->moneyMinorUnits($stored);
                $min = isset($filter['min']) && is_string($filter['min'])
                    ? $this->customValueCodec->moneyMinorUnits($filter['min'])
                    : null;
                $max = isset($filter['max']) && is_string($filter['max'])
                    ? $this->customValueCodec->moneyMinorUnits($filter['max'])
                    : null;
            } catch (DomainException) {
                return false;
            }
            return ($min === null || $storedMinor >= $min) && ($max === null || $storedMinor <= $max);
        }
        if ($field->type === 'checkbox_list') {
            if (!is_array($filter) || !is_array($filter['values'] ?? null)) {
                return false;
            }
            $wanted = array_values(array_filter($filter['values'], 'is_string'));
            if ($wanted === []) {
                return false;
            }
            $selected = $this->customValueCodec->selectedOptions($field, $stored);
            if (($filter['match'] ?? 'any') === 'all') {
                return count(array_diff($wanted, $selected)) === 0;
            }
            return array_intersect($wanted, $selected) !== [];
        }
        return false;
    }

    private function assertAssigneeForProject(
        int $userId,
        ?int $projectId,
        ?int $assigneeUserId,
    ): void {
        if ($assigneeUserId === null) {
            return;
        }
        foreach ($this->assigneesForProject($userId, $projectId) as $member) {
            if ($member->userId === $assigneeUserId) {
                return;
            }
        }
        throw new DomainException($this->translator->trans('validation.selected_assignee_unavailable'));
    }

    private function assertMetadataForUser(int $userId, ?int $typeId): void
    {
        if ($typeId !== null && $this->taskTypes->findForUser($userId, $typeId) === null) {
            throw new DomainException($this->translator->trans('validation.selected_type_unavailable'));
        }
    }

    private function assertStatusForScope(int $userId, int $statusId, ?int $projectId): void
    {
        $status = $projectId === null
            ? $this->statuses->findForUser($userId, $statusId)
            : $this->projectStatuses->findForProject($userId, $projectId, $statusId);
        if ($status === null) {
            throw new DomainException($this->translator->trans('validation.selected_status_unavailable'));
        }
    }

    /** @return list<CustomFieldRecord> */
    private function fieldsForScope(int $userId, ?int $projectId): array
    {
        return $projectId === null
            ? $this->customFields->listForUser($userId)
            : $this->projectCustomFields->listForProject($userId, $projectId);
    }

    /**
     * @param list<CustomFieldRecord> $targetFields
     * @return array<int, mixed>
     */
    private function mappedCustomFormValues(
        TaskRecord $task,
        ?int $targetProjectId,
        array $targetFields,
    ): array {
        $stored = $this->customValues->listForTask($this->userId(), $task->id);
        $currentFields = $this->fieldsForScope($this->userId(), $task->projectId);
        $currentById = [];
        $currentBySource = [];
        foreach ($currentFields as $field) {
            $currentById[$field->id] = $field;
            $source = $this->fieldLineageId($field);
            if ($source !== null) {
                $currentBySource[$source] = $field;
            }
        }

        $sameScope = $task->projectId === $targetProjectId;
        $values = [];
        foreach ($targetFields as $target) {
            $raw = null;
            if ($sameScope) {
                $raw = $stored[$target->id] ?? null;
            } else {
                $source = $this->fieldLineageId($target);
                $current = $source === null ? null : ($currentBySource[$source] ?? null);
                if ($current instanceof CustomFieldRecord && $current->type === $target->type) {
                    $raw = $stored[$current->id] ?? null;
                }
            }
            $values[$target->id] = $this->customValueForForm($target, $raw);
        }
        return $values;
    }

    private function fieldLineageId(CustomFieldRecord $field): ?int
    {
        if ($field->isPersonal()) {
            return $field->id;
        }
        return $field->sourceFieldId;
    }

    private function customValueForForm(CustomFieldRecord $field, ?string $value): mixed
    {
        if ($field->type === 'checkbox') {
            return $value === '1';
        }
        if ($field->type === 'checkbox_list') {
            return $this->customValueCodec->selectedOptions($field, $value);
        }
        return $value ?? '';
    }

    /** @return list<StatusRecord> */
    private function statusesForScope(int $userId, ?int $projectId): array
    {
        return $projectId === null
            ? $this->statuses->listForUser($userId)
            : $this->projectStatuses->listForProject($userId, $projectId);
    }

    /** @param list<StatusRecord> $statuses */
    private function defaultStatusId(array $statuses): ?int
    {
        $fallback = null;
        foreach ($statuses as $status) {
            $fallback ??= $status->id;
            if ($status->isDefault) {
                return $status->id;
            }
        }
        return $fallback;
    }

    /** @param array<string, mixed> $formData */
    private function formProjectId(array $formData, ?TaskRecord $task): ?int
    {
        if (array_key_exists('project_id', $formData)) {
            $raw = $formData['project_id'];
            if ($raw === '' || $raw === null) {
                return null;
            }
            if (is_scalar($raw) && ctype_digit((string) $raw) && (int) $raw > 0) {
                return (int) $raw;
            }
        }
        return $task?->projectId;
    }

    private function assertProjectForUser(int $userId, ?int $projectId): void
    {
        if ($projectId !== null && $this->projects->findForUser($userId, $projectId) === null) {
            throw new DomainException($this->translator->trans('validation.selected_project_unavailable'));
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

    /** @return list<StatusRecord> */
    private function statusesForFilterScope(int $userId, ?int $projectId, string $projectFilter): array
    {
        if ($projectFilter === 'all') {
            return $this->statuses->listAccessibleForUser($userId);
        }
        if ($projectId !== null) {
            return $this->projectStatuses->listForProject($userId, $projectId);
        }
        return $this->statuses->listForUser($userId);
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

    /** @return list<TeamMemberRecord> */
    private function assigneesForProject(int $userId, ?int $projectId): array
    {
        if ($projectId === null) {
            return [];
        }
        $project = $this->projects->findForUser($userId, $projectId);
        if ($project === null || $project->ownerTeamId === null) {
            return [];
        }
        return $this->teams->listMembers($userId, $project->ownerTeamId);
    }

    /**
     * @param list<ProjectRecord> $projects
     * @return array<int, TeamMemberRecord>
     */
    private function assigneeMapForProjects(int $userId, array $projects): array
    {
        $map = [];
        $seenTeams = [];
        foreach ($projects as $project) {
            if ($project->ownerTeamId === null || isset($seenTeams[$project->ownerTeamId])) {
                continue;
            }
            $seenTeams[$project->ownerTeamId] = true;
            foreach ($this->teams->listMembers($userId, $project->ownerTeamId) as $member) {
                $map[$member->userId] = $member;
            }
        }
        return $map;
    }

    /** @return array<int, ProjectRecord> */
    private function projectMap(int $userId): array
    {
        $map = [];
        foreach ($this->projects->listForUser($userId) as $project) {
            $map[$project->id] = $project;
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $baseParams
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
     * @param array{status_id:?int,status_invert:bool,type_id:?int,priority:string,q:string,customer:string,overdue:bool,deadline_from:string,deadline_to:string,created_from:string,created_to:string,project:string} $filters
     * @param array<int, mixed> $customFilters
     * @return array<string, mixed>
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
        if ($filters['project'] !== 'none') {
            $params['project'] = $filters['project'];
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
     * @param array{status_id:?int,status_invert:bool,type_id:?int,priority:string,q:string,customer:string,overdue:bool,deadline_from:string,deadline_to:string,created_from:string,created_to:string,project:string} $filters
     * @param array<int, mixed> $customFilters
     * @param list<CustomFieldRecord> $fields
     * @param array<int, StatusRecord> $statusMap
     * @param array<int, TaskTypeRecord> $typeMap
     * @param array<int, ProjectRecord> $projectMap
     * @param array<string, mixed> $viewParams
     * @return list<array{label:string,value:string,url:string}>
     */
    private function activeFilterChips(
        array $filters,
        array $customFilters,
        array $fields,
        array $statusMap,
        array $typeMap,
        array $projectMap,
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
        if ($filters['project'] !== 'none') {
            $value = $filters['project'] === 'all'
                ? $this->translator->trans('projects.all_projects')
                : ($projectMap[(int) $filters['project']]->name ?? $filters['project']);
            $add('project', $this->translator->trans('projects.task_project'), $value);
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
            $display = $this->customFilterDisplay($field, $value);
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

    private function customFilterDisplay(CustomFieldRecord $field, mixed $filter): string
    {
        if ($field->type === 'checkbox' && is_string($filter)) {
            return $filter === '1'
                ? $this->translator->trans('common.yes')
                : $this->translator->trans('common.no');
        }
        if ($field->type === 'money' && is_array($filter)) {
            $min = isset($filter['min']) && is_string($filter['min']) ? $this->displayMoney($filter['min']) : '';
            $max = isset($filter['max']) && is_string($filter['max']) ? $this->displayMoney($filter['max']) : '';
            if ($min !== '' && $max !== '') {
                return $min . ' – ' . $max;
            }
            return $min !== ''
                ? $this->translator->trans('custom_fields.money_from_value', ['value' => $min])
                : $this->translator->trans('custom_fields.money_to_value', ['value' => $max]);
        }
        if ($field->type === 'checkbox_list' && is_array($filter)) {
            $values = is_array($filter['values'] ?? null)
                ? array_values(array_filter($filter['values'], 'is_string'))
                : [];
            $display = implode(', ', $values);
            if (($filter['match'] ?? 'any') === 'all' && $display !== '') {
                $display .= ' (' . $this->translator->trans('custom_fields.match_all_short') . ')';
            }
            return $display;
        }
        return is_scalar($filter) ? (string) $filter : '';
    }

    private function displayMoney(string $value): string
    {
        try {
            $normalized = $this->customValueCodec->normalizeMoneyInput($value);
        } catch (DomainException) {
            return $value;
        }
        if ($normalized === null) {
            return '';
        }
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole) ?? $whole;
        return $whole . ',' . str_pad($fraction, 2, '0');
    }

    /**
     * @param array<string, mixed> $viewParams
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
     * @param array<string, mixed> $viewParams
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

    /**
     * @param array<string, mixed> $query
     * @return array{0:?int,1:bool,2:string}
     */
    private function projectFilter(array $query, int $userId): array
    {
        $value = $this->queryString($query, 'project');
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
