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
use Tms\Application\UserBootstrapService;
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
        private readonly UserBootstrapService $bootstrap,
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

    public function newUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $old = $_SESSION['admin_create_user_old'] ?? null;
        unset($_SESSION['admin_create_user_old']);
        return $this->view->render($response, 'admin/new_user.twig', $this->viewData($request) + [
            'old' => is_array($old) ? $old : [],
        ]);
    }

    public function createUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $username = is_string($body['username'] ?? null) ? trim((string) $body['username']) : '';
        $email = is_string($body['email'] ?? null) ? trim((string) $body['email']) : '';
        $password = is_string($body['password'] ?? null) ? (string) $body['password'] : '';
        $confirm = is_string($body['password_confirm'] ?? null) ? (string) $body['password_confirm'] : '';
        $role = is_string($body['role'] ?? null) ? (string) $body['role'] : 'user';
        $active = ($body['is_active'] ?? null) === '1';
        $approved = ($body['approved'] ?? null) === '1';
        $verified = ($body['email_verified'] ?? null) === '1';

        try {
            if (preg_match('/^[A-Za-z0-9_-]{3,64}$/D', $username) !== 1) {
                throw new DomainException($this->translator->trans('admin.username_invalid'));
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException($this->translator->trans('admin.email_invalid'));
            }
            if (strlen($password) < 12) {
                throw new DomainException($this->translator->trans('admin.password_min'));
            }
            if ($password !== $confirm) {
                throw new DomainException($this->translator->trans('admin.password_mismatch'));
            }
            if (!in_array($role, ['user', 'admin'], true)) {
                throw new DomainException($this->translator->trans('admin.role_invalid'));
            }

            $userId = $this->users->createUser(
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $active,
                $verified,
                $approved,
                $role,
            );
            $this->bootstrap->ensureDefaults($userId);
            $this->notice('success', 'admin.created_notice');
            return $response->withHeader('Location', '/admin/users/' . $userId . '/edit')->withStatus(302);
        } catch (DomainException $error) {
            $this->noticeRaw('error', $this->adminCreateMessage($error));
            $_SESSION['admin_create_user_old'] = [
                'username' => $username, 'email' => $email, 'role' => $role,
                'is_active' => $active, 'approved' => $approved, 'email_verified' => $verified,
            ];
            return $response->withHeader('Location', '/admin/users/new')->withStatus(302);
        }
    }

    public function pending(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'admin/pending_users.twig', $this->viewData($request) + [
            'users' => $this->users->listPending(),
        ]);
    }

    /** @param array<string, string> $args */
    public function detailsJson(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->user($args);
        if ($user === null) {
            return $this->json($response, ['error' => $this->translator->trans('admin.user_not_found')], 404);
        }
        return $this->json($response, [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'active' => $user->isActive,
            'email_verified' => $user->isEmailVerified,
            'approved' => $user->isApproved,
            'created_at' => $user->createdAt,
            'last_activity_at' => $this->formatActivity($user->lastActivityAt),
            'edit_url' => '/admin/users/' . $user->id . '/edit',
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
            $this->notice('success', 'admin.approved_notice');
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
            $this->notice('success', 'admin.email_verified_notice');
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

    private function adminCreateMessage(DomainException $error): string
    {
        return match ($error->getMessage()) {
            'A user with that username or email already exists.' => $this->translator->trans('admin.identity_exists'),
            'Invalid user role.' => $this->translator->trans('admin.role_invalid'),
            default => $error->getMessage(),
        };
    }

    private function formatActivity(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $format = $this->translator->locale() === 'ru' ? 'd.m.Y H:i' : 'Y-m-d H:i';
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format($format);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($json === false ? '{}' : $json);
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
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
