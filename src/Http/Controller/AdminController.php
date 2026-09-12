<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Application\RegistrationService;
use Tms\Domain\Attachment\AttachmentRepository;
use Tms\Domain\User\UserRecord;
use Tms\Domain\User\UserRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\AttachmentStorage;
use Tms\Security\RememberTokenRepository;
use Tms\Security\SessionManager;

final class AdminController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly UserRepository $users,
        private readonly RememberTokenRepository $rememberTokens,
        private readonly RegistrationService $registration,
        private readonly AttachmentRepository $attachments,
        private readonly AttachmentStorage $storage,
        private readonly Translator $translator,
    ) {
    }

    public function dashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $users = $this->users->listAll();
        return $this->view->render($response, 'admin/dashboard.twig', $this->viewData($request) + [
            'total_users' => count($users),
            'active_users' => count(array_filter($users, static fn (UserRecord $user): bool => $user->isActive)),
            'pending_users' => count($this->users->listPending()),
        ]);
    }

    public function users(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'admin/users.twig', $this->viewData($request) + [
            'users' => $this->users->listAll(),
            'pending_count' => count($this->users->listPending()),
            'current_user_id' => $this->sessions->currentUserId(),
        ]);
    }

    public function pending(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'admin/pending_users.twig', $this->viewData($request) + [
            'users' => $this->users->listPending(),
        ]);
    }

    /** @param array<string, string> $args */
    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->user($args);
        if ($user === null) {
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }
        return $this->view->render($response, 'admin/edit_user.twig', $this->viewData($request) + [
            'user' => $user,
            'current_user_id' => $this->sessions->currentUserId(),
        ]);
    }

    /** @param array<string, string> $args */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->user($args);
        if ($user === null) {
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }
        $body = $this->body($request);
        try {
            $username = is_string($body['username'] ?? null) ? trim((string) $body['username']) : '';
            $email = is_string($body['email'] ?? null) ? trim((string) $body['email']) : '';
            $role = is_string($body['role'] ?? null) ? (string) $body['role'] : 'user';
            $isActive = ($body['is_active'] ?? null) === '1';
            if (preg_match('/^[A-Za-z0-9_-]{3,64}$/D', $username) !== 1) {
                throw new DomainException($this->translator->trans('admin.username_invalid'));
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException($this->translator->trans('admin.email_invalid'));
            }
            if ($user->id === $this->sessions->currentUserId() && (!$isActive || $role !== 'admin')) {
                throw new DomainException($this->translator->trans('admin.cannot_disable_self'));
            }
            $emailChanged = $email !== $user->email;
            $this->users->updateByAdmin($user->id, $username, $email, $role, $isActive);
            if ($emailChanged) {
                $this->registration->invalidateVerification($user->id);
            }
            if (!$isActive) {
                $this->rememberTokens->deleteAllForUser($user->id);
            }
            $updated = $this->users->findById($user->id);
            if ($updated !== null && $updated->id === $this->sessions->currentUserId()) {
                $this->sessions->refreshIdentity($updated);
            }
            $this->notice('success', 'admin.saved');
            return $response->withHeader('Location', '/admin/users/' . $user->id . '/edit')->withStatus(302);
        } catch (DomainException $error) {
            $this->noticeRaw('error', $error->getMessage());
            return $response->withHeader('Location', '/admin/users/' . $user->id . '/edit')->withStatus(302);
        }
    }

    /** @param array<string, string> $args */
    public function approve(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->user($args);
        if ($user !== null) {
            $this->users->setApproved($user->id, true);
            $this->notice('success', 'admin.approved');
        }
        return $response->withHeader('Location', $this->returnTo($request, '/admin/pending-users'))->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function verifyEmail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->user($args);
        if ($user !== null) {
            $this->users->setEmailVerified($user->id, true);
            $this->registration->invalidateVerification($user->id);
            $this->notice('success', 'admin.email_verified');
        }
        return $response->withHeader('Location', $this->returnTo($request, '/admin/users'))->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function resendVerification(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->user($args);
        if ($user === null) {
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }
        $sent = $this->registration->sendVerification($user, $this->now());
        $this->notice($sent ? 'success' : 'error', $sent ? 'admin.verification_sent' : 'admin.verification_send_failed');
        return $response->withHeader('Location', $this->returnTo($request, '/admin/users'))->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->user($args);
        if ($user === null) {
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }
        if ($user->id === $this->sessions->currentUserId()) {
            $this->notice('error', 'admin.cannot_delete_self');
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }
        $stored = $this->attachments->listForUser($user->id);
        try {
            if ($this->users->deleteByAdmin($user->id)) {
                foreach ($stored as $attachment) {
                    if (!$this->storage->delete($attachment->storageName)) {
                        error_log('TMS attachment cleanup failed after admin user deletion: ' . $attachment->storageName);
                    }
                }
                $this->notice('success', 'admin.deleted');
            }
        } catch (DomainException $error) {
            $this->noticeRaw('error', $error->getMessage());
        }
        return $response->withHeader('Location', '/admin/users')->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function impersonate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->user($args);
        if ($user === null || !$user->isActive || !$user->isApproved || !$this->sessions->startImpersonation($user)) {
            $this->notice('error', 'admin.impersonation_failed');
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }
        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    public function stopImpersonation(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $adminId = $this->sessions->originalAdminId();
        $admin = $adminId === null ? null : $this->users->findById($adminId);
        if ($admin === null || !$this->sessions->stopImpersonation($admin)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        return $response->withHeader('Location', '/admin/users')->withStatus(302);
    }

    /** @param array<string, string> $args */
    private function user(array $args): ?UserRecord
    {
        $raw = $args['id'] ?? '';
        return ctype_digit($raw) ? $this->users->findById((int) $raw) : null;
    }

    /** @return array<string, mixed> */
    private function viewData(ServerRequestInterface $request): array
    {
        $notice = $_SESSION['admin_notice'] ?? null;
        unset($_SESSION['admin_notice']);
        return [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername(),
            'notice' => is_array($notice) ? $notice : null,
        ];
    }

    private function notice(string $kind, string $key): void
    {
        $this->noticeRaw($kind, $this->translator->trans($key));
    }

    private function noticeRaw(string $kind, string $message): void
    {
        $_SESSION['admin_notice'] = ['kind' => $kind, 'message' => $message];
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function returnTo(ServerRequestInterface $request, string $fallback): string
    {
        $body = $this->body($request);
        $return = is_string($body['return_to'] ?? null) ? (string) $body['return_to'] : '';
        return str_starts_with($return, '/') && !str_starts_with($return, '//') ? $return : $fallback;
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
