<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Notification\SmtpSettingsRepository;
use Tms\Domain\User\UserRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\SecretBox;
use Tms\Infrastructure\TelegramBotSender;
use Tms\Security\SessionManager;

final class NotificationAdminController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly UserRepository $users,
        private readonly SmtpSettingsRepository $smtp,
        private readonly EmailSender $email,
        private readonly TelegramBotSender $telegram,
        private readonly ?SecretBox $secretBox,
        private readonly Translator $translator,
        private readonly string $appUrl,
        private readonly string $webhookSecret,
        private readonly string $botToken,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response);
    }

    public function saveSmtp(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $existing = $this->smtp->get();
        $ciphertext = $existing?->passwordCiphertext;
        $password = (string) ($body['password'] ?? '');
        if ($password !== '') {
            if ($this->secretBox === null) {
                return $this->render(
                    $request,
                    $response,
                    null,
                    $this->translator->trans('notifications.secret_required'),
                    503,
                );
            }
            $ciphertext = $this->secretBox->encrypt($password);
        }
        try {
            $this->smtp->save(
                enabled: $this->checked($body, 'enabled'),
                host: (string) ($body['host'] ?? ''),
                port: (int) ($body['port'] ?? 587),
                username: (string) ($body['smtp_username'] ?? ''),
                passwordCiphertext: $ciphertext,
                encryption: (string) ($body['encryption'] ?? 'tls'),
                fromEmail: (string) ($body['from_email'] ?? ''),
                fromName: (string) ($body['from_name'] ?? 'TMS'),
            );
        } catch (DomainException $error) {
            return $this->render($request, $response, null, $error->getMessage(), 422);
        }
        $_SESSION['_notification_admin_flash'] = $this->translator->trans('notifications.smtp_saved');
        return $this->redirect($response);
    }

    public function testEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->users->findById($this->sessions->currentUserId() ?? 0);
        if ($user === null || !$this->email->send(
            $user->email,
            $user->username,
            'TMS SMTP test',
            '<p>TMS SMTP transport is configured correctly.</p>',
            'TMS SMTP transport is configured correctly.',
        )) {
            return $this->render(
                $request,
                $response,
                null,
                $this->translator->trans('notifications.smtp_test_failed'),
                502,
            );
        }
        $_SESSION['_notification_admin_flash'] = $this->translator->trans('notifications.smtp_test_sent');
        return $this->redirect($response);
    }

    public function setupTelegramWebhook(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->botToken === '' || $this->webhookSecret === '') {
            return $this->render(
                $request,
                $response,
                null,
                $this->translator->trans('notifications.telegram_deployment_incomplete'),
                503,
            );
        }
        if (!$this->telegram->setWebhook(rtrim($this->appUrl, '/') . '/telegram/webhook', $this->webhookSecret)) {
            return $this->render(
                $request,
                $response,
                null,
                $this->translator->trans('notifications.telegram_webhook_failed'),
                502,
            );
        }
        $_SESSION['_notification_admin_flash'] = $this->translator->trans('notifications.telegram_webhook_set');
        return $this->redirect($response);
    }

    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?string $message = null,
        ?string $error = null,
        int $status = 200,
    ): ResponseInterface {
        $flash = $_SESSION['_notification_admin_flash'] ?? null;
        unset($_SESSION['_notification_admin_flash']);
        $smtp = $this->smtp->get();

        return $this->view->render($response, 'notifications/admin.twig', [
            'smtp' => $smtp,
            'smtp_has_password' => $smtp !== null
                && $smtp->passwordCiphertext !== null
                && $smtp->passwordCiphertext !== '',
            'secret_configured' => $this->secretBox !== null,
            'telegram_configured' => $this->botToken !== '' && $this->webhookSecret !== '',
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername(),
            'role' => $this->sessions->currentRole(),
            'message' => $message ?? (is_string($flash) ? $flash : null),
            'error' => $error,
        ])->withStatus($status);
    }

    private function redirect(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Location', '/admin/notifications')->withStatus(302);
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $body */
    private function checked(array $body, string $key): bool
    {
        return isset($body[$key]) && in_array((string) $body[$key], ['1', 'on', 'true'], true);
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }
}
