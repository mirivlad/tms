<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\Attachment\AttachmentRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\Infrastructure\AttachmentStorage;
use Tms\Security\SessionManager;

final class TaskDeleteController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly TaskRepository $tasks,
        private readonly AttachmentRepository $attachments,
        private readonly AttachmentStorage $storage,
    ) {
    }

    /** @param array<string, string> $args */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $userId = $this->sessions->currentUserId() ?? 0;
        $rawTaskId = $args['id'] ?? '';
        $taskId = ctype_digit($rawTaskId) ? (int) $rawTaskId : 0;
        if ($taskId < 1 || $this->tasks->findForUser($userId, $taskId) === null) {
            return $response->withHeader('Location', '/tasks')->withStatus(302);
        }

        $stored = $this->attachments->listForTask($userId, $taskId);
        if (!$this->tasks->deleteForUser($userId, $taskId)) {
            return $response->withHeader('Location', '/tasks')->withStatus(302);
        }

        foreach ($stored as $attachment) {
            if (!$this->storage->delete($attachment->storageName)) {
                error_log('TMS attachment cleanup failed after task deletion for storage key ' . $attachment->storageName);
            }
        }

        return $response->withHeader('Location', '/tasks')->withStatus(302);
    }
}
