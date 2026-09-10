<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\Security\SessionManager;

final class TaskStatusController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly TaskRepository $tasks,
        private readonly StatusRepository $statuses,
    ) {
    }

    /** @param array<string, string> $args */
    public function move(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->sessions->currentUserId() ?? 0;
        $taskId = $this->taskId($args);
        $task = $this->tasks->findForUser($userId, $taskId);
        if ($task === null) {
            return $this->result($request, $response, 404, 'Task not found.');
        }

        $statusId = $this->statusId($request);
        if ($statusId === null || $this->statuses->findForUser($userId, $statusId) === null) {
            return $this->result($request, $response, 422, 'Selected status is unavailable.');
        }

        if ($task->statusId !== $statusId && !$this->tasks->updateStatusForUser($userId, $taskId, $statusId)) {
            return $this->result($request, $response, 409, 'Task status was not changed.');
        }

        if ($this->wantsJson($request)) {
            $response->getBody()->write('{"status":"ok"}');
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $response->withHeader('Location', '/board')->withStatus(302);
    }

    private function statusId(ServerRequestInterface $request): ?int
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['status_id'] ?? null) : null;
        if (!is_scalar($value) || !ctype_digit((string) $value) || (int) $value < 1) {
            return null;
        }
        return (int) $value;
    }

    /** @param array<string, string> $args */
    private function taskId(array $args): int
    {
        $value = $args['id'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    private function result(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $status,
        string $message,
    ): ResponseInterface {
        if ($this->wantsJson($request)) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => $message], JSON_THROW_ON_ERROR));
            return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        }

        if ($status === 404) {
            $response->getBody()->write($message);
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $response->withHeader('Location', '/board')->withStatus(302);
    }
}
