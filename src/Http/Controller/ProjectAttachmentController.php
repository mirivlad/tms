<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;
use Tms\Domain\Attachment\AttachmentPolicy;
use Tms\Domain\Project\ProjectAttachmentRepository;
use Tms\Domain\Project\ProjectRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\AttachmentStorage;
use Tms\Security\SessionManager;

final class ProjectAttachmentController
{
    private const MAX_FILES_PER_REQUEST = 10;

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ProjectRepository $projects,
        private readonly ProjectAttachmentRepository $attachments,
        private readonly AttachmentPolicy $policy,
        private readonly AttachmentStorage $storage,
        private readonly Translator $translator,
    ) {
    }

    /** @param array<string, string> $args */
    public function upload(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId();
        $projectId = $this->routeId($args, 'projectId');
        if ($this->projects->findForUser($userId, $projectId) === null) {
            return $this->notFound($response);
        }

        $files = $this->uploadedFiles($request);
        if ($files === []) {
            return $this->error($response, 'validation.attachment_missing', 422);
        }
        if (count($files) > self::MAX_FILES_PER_REQUEST) {
            return $this->error($response, 'validation.attachment_too_many', 422);
        }

        $prepared = [];
        $storedNames = [];
        try {
            foreach ($files as $file) {
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    throw new DomainException('Attachment upload failed.');
                }
                $reportedSize = $file->getSize();
                if ($reportedSize === null) {
                    throw new DomainException('Attachment size is unavailable.');
                }
                $this->policy->assertSize($reportedSize);
                $originalName = $this->policy->normalizeOriginalName($file->getClientFilename() ?? 'attachment');
                $stored = $this->storage->store($file);
                $storedNames[] = $stored->storageName;
                $this->policy->assertAllowed($originalName, $stored->fileSize, $stored->mimeType);
                $prepared[] = [
                    'storage_name' => $stored->storageName,
                    'original_name' => $originalName,
                    'mime_type' => $stored->mimeType,
                    'file_size' => $stored->fileSize,
                    'sha256' => $stored->sha256,
                ];
            }
            $this->attachments->createManyForProject($userId, $projectId, $prepared);
        } catch (DomainException $error) {
            $this->discardStored($storedNames);
            return $this->uploadError($response, $error);
        } catch (\Throwable $error) {
            $this->discardStored($storedNames);
            throw $error;
        }

        return $this->redirect($response, $projectId);
    }

    /** @param array<string, string> $args */
    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId();
        $projectId = $this->routeId($args, 'projectId');
        if ($this->projects->findForUser($userId, $projectId) === null) {
            return $this->notFound($response);
        }
        $attachment = $this->attachments->findForProject(
            $userId,
            $projectId,
            $this->routeId($args, 'attachmentId'),
        );
        if ($attachment === null) {
            return $this->notFound($response);
        }

        $path = $this->storage->pathFor($attachment->storageName);
        if (!is_file($path)) {
            return $this->notFound($response);
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return $this->notFound($response);
        }
        $size = filesize($path);
        if ($size === false) {
            fclose($handle);
            return $this->notFound($response);
        }

        return $response
            ->withHeader('Content-Type', $attachment->mimeType)
            ->withHeader('Content-Disposition', $this->contentDisposition($attachment->originalName))
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody(new Stream($handle));
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId();
        $projectId = $this->routeId($args, 'projectId');
        $attachmentId = $this->routeId($args, 'attachmentId');
        if ($this->projects->findForUser($userId, $projectId) === null) {
            return $this->notFound($response);
        }
        $attachment = $this->attachments->findForProject($userId, $projectId, $attachmentId);
        if ($attachment === null || !$this->attachments->deleteForProject($userId, $projectId, $attachmentId)) {
            return $this->notFound($response);
        }
        if (!$this->storage->delete($attachment->storageName)) {
            error_log('TMS project attachment cleanup failed for storage key ' . $attachment->storageName);
        }
        return $this->redirect($response, $projectId);
    }

    /** @return list<UploadedFileInterface> */
    private function uploadedFiles(ServerRequestInterface $request): array
    {
        $input = $request->getUploadedFiles()['attachments'] ?? null;
        if ($input instanceof UploadedFileInterface) {
            return [$input];
        }
        if (!is_array($input)) {
            return [];
        }
        $files = [];
        foreach ($input as $file) {
            if ($file instanceof UploadedFileInterface) {
                $files[] = $file;
            }
        }
        return $files;
    }

    /** @param list<string> $storageNames */
    private function discardStored(array $storageNames): void
    {
        foreach ($storageNames as $storageName) {
            $this->storage->delete($storageName);
        }
    }

    private function uploadError(ResponseInterface $response, DomainException $error): ResponseInterface
    {
        $message = $error->getMessage();
        $key = match ($message) {
            'Attachment exceeds the configured size limit.' => 'validation.attachment_too_large',
            'Attachment is empty.' => 'validation.attachment_empty',
            'Attachment file extension is not allowed.' => 'validation.attachment_extension',
            'Attachment content type does not match an allowed file type.' => 'validation.attachment_mime',
            default => 'validation.attachment_upload_failed',
        };
        return $this->error(
            $response,
            $key,
            $message === 'Attachment exceeds the configured size limit.' ? 413 : 422,
        );
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return $this->error($response, 'validation.attachment_not_found', 404);
    }

    private function error(ResponseInterface $response, string $key, int $status): ResponseInterface
    {
        $response->getBody()->write($this->translator->trans($key));
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withStatus($status);
    }

    private function redirect(ResponseInterface $response, int $projectId): ResponseInterface
    {
        return $response->withHeader('Location', '/projects/' . $projectId . '/files')->withStatus(302);
    }

    private function contentDisposition(string $name): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'attachment';
        $fallback = trim($fallback);
        if ($fallback === '') {
            $fallback = 'attachment';
        }
        $fallback = str_replace(['"', '\\'], '_', $fallback);
        return 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    /** @param array<string, string> $args */
    private function routeId(array $args, string $key): int
    {
        $value = $args[$key] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function userId(): int
    {
        return $this->sessions->currentUserId() ?? 0;
    }
}
