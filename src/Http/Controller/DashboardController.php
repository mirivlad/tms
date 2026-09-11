<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Status\StatusRepository;
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
        $completionIds = [];
        $statusMap = [];
        foreach ($this->statuses->listForUser($userId) as $status) {
            $statusMap[$status->id] = $status;
            if ($status->isCompletion) {
                $completionIds[$status->id] = true;
            }
        }

        $open = 0;
        $urgent = 0;
        $overdue = 0;
        $now = new DateTimeImmutable();
        foreach ($tasks as $task) {
            $completed = $task->statusId !== null && isset($completionIds[$task->statusId]);
            if (!$completed) {
                ++$open;
                if ($task->priority === 3) {
                    ++$urgent;
                }
                if ($task->deadline !== null) {
                    $deadline = new DateTimeImmutable($task->deadline);
                    if ($deadline < $now) {
                        ++$overdue;
                    }
                }
            }
        }

        return $this->view->render($response, 'dashboard.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername(),
            'total_tasks' => count($tasks),
            'open_tasks' => $open,
            'urgent_tasks' => $urgent,
            'overdue_tasks' => $overdue,
            'next_tasks' => array_slice($tasks, 0, 8),
            'status_map' => $statusMap,
            'priority_labels' => $this->priorityLabels(),
        ]);
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
