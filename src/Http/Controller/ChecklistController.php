<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\Activity\ActivityRepository;
use Tms\Domain\Checklist\ChecklistRepository;
use Tms\Domain\Task\TaskRecord;
use Tms\Domain\Task\TaskRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class ChecklistController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly TaskRepository $tasks,
        private readonly ChecklistRepository $checklists,
        private readonly ActivityRepository $activity,
        private readonly Translator $translator,
    ) {
    }

    /** @param array<string, string> $args */
    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $task = $this->task($args);
        if ($task === null) {
            return $this->notFound($response);
        }

        $body = $this->body($request);
        try {
            $itemId = $this->checklists->createForTask(
                $this->userId(),
                $task->id,
                is_string($body['text'] ?? null) ? (string) $body['text'] : '',
            );
            $item = $this->checklists->findForTask($this->userId(), $task->id, $itemId);
            if ($item !== null) {
                $this->activity->recordTaskEvent(
                    $this->userId(),
                    $task,
                    'task.checklist_added',
                    ['checklist_item' => ['old' => null, 'new' => $item->text]],
                );
            }
            $this->notice('success', 'checklist.added');
        } catch (DomainException $error) {
            $this->noticeRaw('error', $this->message($error));
        }
        return $this->redirect($response, $task->id);
    }

    /** @param array<string, string> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $task = $this->task($args);
        $itemId = $this->itemId($args);
        if ($task === null || $itemId === 0) {
            return $this->notFound($response);
        }
        $before = $this->checklists->findForTask($this->userId(), $task->id, $itemId);
        if ($before === null) {
            return $this->notFound($response);
        }

        $body = $this->body($request);
        try {
            $this->checklists->updateText(
                $this->userId(),
                $task->id,
                $itemId,
                is_string($body['text'] ?? null) ? (string) $body['text'] : '',
            );
            $after = $this->checklists->findForTask($this->userId(), $task->id, $itemId);
            if ($after !== null && $after->text !== $before->text) {
                $this->activity->recordTaskEvent(
                    $this->userId(),
                    $task,
                    'task.checklist_updated',
                    ['checklist_item' => ['old' => $before->text, 'new' => $after->text]],
                );
            }
            $this->notice('success', 'checklist.updated');
        } catch (DomainException $error) {
            $this->noticeRaw('error', $this->message($error));
        }
        return $this->redirect($response, $task->id);
    }

    /** @param array<string, string> $args */
    public function toggle(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $task = $this->task($args);
        $itemId = $this->itemId($args);
        if ($task === null || $itemId === 0) {
            return $this->notFound($response);
        }
        $item = $this->checklists->findForTask($this->userId(), $task->id, $itemId);
        if ($item === null) {
            return $this->notFound($response);
        }

        $completed = !$item->isCompleted;
        $this->checklists->setCompleted($this->userId(), $task->id, $itemId, $completed);
        $this->activity->recordTaskEvent(
            $this->userId(),
            $task,
            $completed ? 'task.checklist_completed' : 'task.checklist_reopened',
            ['checklist_item' => ['old' => null, 'new' => $item->text]],
        );
        return $this->redirect($response, $task->id);
    }

    /** @param array<string, string> $args */
    public function move(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $task = $this->task($args);
        $itemId = $this->itemId($args);
        if ($task === null || $itemId === 0) {
            return $this->notFound($response);
        }
        $item = $this->checklists->findForTask($this->userId(), $task->id, $itemId);
        if ($item === null) {
            return $this->notFound($response);
        }

        $body = $this->body($request);
        $direction = is_string($body['direction'] ?? null) ? (string) $body['direction'] : '';
        try {
            if ($this->checklists->move($this->userId(), $task->id, $itemId, $direction)) {
                $this->activity->recordTaskEvent(
                    $this->userId(),
                    $task,
                    'task.checklist_reordered',
                    ['checklist_item' => ['old' => null, 'new' => $item->text]],
                );
            }
        } catch (DomainException $error) {
            $this->noticeRaw('error', $this->message($error));
        }
        return $this->redirect($response, $task->id);
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $task = $this->task($args);
        $itemId = $this->itemId($args);
        if ($task === null || $itemId === 0) {
            return $this->notFound($response);
        }
        $item = $this->checklists->findForTask($this->userId(), $task->id, $itemId);
        if ($item === null) {
            return $this->notFound($response);
        }

        if ($this->checklists->delete($this->userId(), $task->id, $itemId)) {
            $this->activity->recordTaskEvent(
                $this->userId(),
                $task,
                'task.checklist_deleted',
                ['checklist_item' => ['old' => $item->text, 'new' => null]],
            );
            $this->notice('success', 'checklist.deleted');
        }
        return $this->redirect($response, $task->id);
    }

    /** @param array<string, string> $args */
    private function task(array $args): ?TaskRecord
    {
        return $this->tasks->findForUser($this->userId(), $this->taskId($args));
    }

    private function userId(): int
    {
        return $this->sessions->currentUserId() ?? 0;
    }

    /** @param array<string, string> $args */
    private function taskId(array $args): int
    {
        $value = $args['taskId'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    /** @param array<string, string> $args */
    private function itemId(array $args): int
    {
        $value = $args['itemId'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function redirect(ResponseInterface $response, int $taskId): ResponseInterface
    {
        return $response
            ->withHeader('Location', '/tasks/' . $taskId . '/edit#checklist')
            ->withStatus(302);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->translator->trans('validation.task_not_found'));
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    private function notice(string $kind, string $key): void
    {
        $this->noticeRaw($kind, $this->translator->trans($key));
    }

    private function noticeRaw(string $kind, string $message): void
    {
        $_SESSION['_checklist_notice'] = ['kind' => $kind, 'message' => $message];
    }

    private function message(DomainException $error): string
    {
        return match ($error->getMessage()) {
            'Checklist item must contain 1-500 characters.' => $this->translator->trans('checklist.validation_text'),
            'Unsupported checklist move direction.' => $this->translator->trans('checklist.validation_move'),
            default => $this->translator->trans('validation.task_not_found'),
        };
    }
}
