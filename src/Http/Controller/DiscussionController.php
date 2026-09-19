<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Application\DiscussionNotificationService;
use Tms\Domain\Discussion\DiscussionRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;
use Tms\Security\TaskDescriptionSanitizer;

final class DiscussionController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly DiscussionRepository $discussions,
        private readonly DiscussionNotificationService $notifications,
        private readonly TaskDescriptionSanitizer $sanitizer,
        private readonly Translator $translator,
    ) {
    }

    /** @param array<string, string> $args */
    public function createProject(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->create(
            $request,
            $response,
            projectId: $this->id($args, 'projectId'),
            taskId: null,
            parentCommentId: null,
        );
    }

    /** @param array<string, string> $args */
    public function replyProject(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->create(
            $request,
            $response,
            projectId: $this->id($args, 'projectId'),
            taskId: null,
            parentCommentId: $this->id($args, 'commentId'),
        );
    }

    /** @param array<string, string> $args */
    public function updateProject(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->update(
            $request,
            $response,
            projectId: $this->id($args, 'projectId'),
            taskId: null,
            commentId: $this->id($args, 'commentId'),
        );
    }

    /** @param array<string, string> $args */
    public function deleteProject(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->delete(
            $response,
            projectId: $this->id($args, 'projectId'),
            taskId: null,
            commentId: $this->id($args, 'commentId'),
        );
    }

    /** @param array<string, string> $args */
    public function createTask(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->create(
            $request,
            $response,
            projectId: null,
            taskId: $this->id($args, 'taskId'),
            parentCommentId: null,
        );
    }

    /** @param array<string, string> $args */
    public function replyTask(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->create(
            $request,
            $response,
            projectId: null,
            taskId: $this->id($args, 'taskId'),
            parentCommentId: $this->id($args, 'commentId'),
        );
    }

    /** @param array<string, string> $args */
    public function updateTask(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->update(
            $request,
            $response,
            projectId: null,
            taskId: $this->id($args, 'taskId'),
            commentId: $this->id($args, 'commentId'),
        );
    }

    /** @param array<string, string> $args */
    public function deleteTask(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->delete(
            $response,
            projectId: null,
            taskId: $this->id($args, 'taskId'),
            commentId: $this->id($args, 'commentId'),
        );
    }

    private function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?int $projectId,
        ?int $taskId,
        ?int $parentCommentId,
    ): ResponseInterface {
        $body = $this->body($request);
        $bodyHtml = $this->sanitizer->sanitize(
            is_string($body['body'] ?? null) ? (string) $body['body'] : ''
        );

        try {
            $userId = $this->userId();
            $commentId = null;
            if ($projectId !== null) {
                $commentId = $this->discussions->createForProject(
                    $userId,
                    $projectId,
                    $bodyHtml,
                    $parentCommentId,
                );
            } elseif ($taskId !== null) {
                $commentId = $this->discussions->createForTask(
                    $userId,
                    $taskId,
                    $bodyHtml,
                    $parentCommentId,
                );
            }
            if ($commentId !== null) {
                $this->processNotifications($userId, $commentId);
            }
            $this->notice('success', 'discussions.saved');
        } catch (DomainException $error) {
            $this->notice('error', $this->errorKey($error));
        }

        return $this->redirect($response, $projectId, $taskId);
    }

    private function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?int $projectId,
        ?int $taskId,
        int $commentId,
    ): ResponseInterface {
        $body = $this->body($request);
        $bodyHtml = $this->sanitizer->sanitize(
            is_string($body['body'] ?? null) ? (string) $body['body'] : ''
        );

        try {
            $userId = $this->userId();
            $updated = $projectId !== null
                ? $this->discussions->updateForProject($userId, $projectId, $commentId, $bodyHtml)
                : ($taskId !== null
                    ? $this->discussions->updateForTask($userId, $taskId, $commentId, $bodyHtml)
                    : false);
            if ($updated) {
                $this->processNotifications($userId, $commentId);
            }
            $this->notice($updated ? 'success' : 'error', $updated ? 'discussions.saved' : 'discussions.unavailable');
        } catch (DomainException $error) {
            $this->notice('error', $this->errorKey($error));
        }

        return $this->redirect($response, $projectId, $taskId);
    }

    private function delete(
        ResponseInterface $response,
        ?int $projectId,
        ?int $taskId,
        int $commentId,
    ): ResponseInterface {
        $deleted = $projectId !== null
            ? $this->discussions->deleteForProject($this->userId(), $projectId, $commentId)
            : ($taskId !== null
                ? $this->discussions->deleteForTask($this->userId(), $taskId, $commentId)
                : false);

        $this->notice($deleted ? 'success' : 'error', $deleted ? 'discussions.deleted' : 'discussions.unavailable');
        return $this->redirect($response, $projectId, $taskId);
    }

    private function processNotifications(int $userId, int $commentId): void
    {
        try {
            $this->notifications->processComment($userId, $commentId);
        } catch (\Throwable $error) {
            error_log('TMS discussion notification processing failed: ' . $error->getMessage());
        }
    }

    private function errorKey(DomainException $error): string
    {
        return match ($error->getMessage()) {
            'Discussion comment must contain 1-20000 characters.',
            'Discussion comment cannot be empty.' => 'discussions.validation_body',
            'Replies can only be one level deep.' => 'discussions.validation_reply_depth',
            'Reply target is unavailable.',
            'Discussion is unavailable.' => 'discussions.unavailable',
            default => 'discussions.operation_failed',
        };
    }

    private function notice(string $kind, string $key): void
    {
        $_SESSION['_discussion_notice'] = [
            'kind' => $kind,
            'message' => $this->translator->trans($key),
        ];
    }

    private function redirect(
        ResponseInterface $response,
        ?int $projectId,
        ?int $taskId,
    ): ResponseInterface {
        $location = $projectId !== null
            ? '/projects/' . $projectId . '/discussion#discussion'
            : '/tasks/' . (int) $taskId . '/edit#discussion';
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /** @param array<string, string> $args */
    private function id(array $args, string $key): int
    {
        $value = $args[$key] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function userId(): int
    {
        return $this->sessions->currentUserId() ?? 0;
    }
}
