<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Notification\SmtpSettingsRepository;
use Tms\Domain\Notification\TelegramSystemSettingsRepository;
use Tms\Domain\User\UserRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\SecretBox;
use Tms\Infrastructure\TelegramBotSender;
use Tms\Infrastructure\TelegramConfigurationProvider;
use Tms\Infrastructure\TelegramOperationResult;
use Tms\Security\SessionManager;

final class NotificationAdminController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly UserRepository $users,
        private readonly SmtpSettingsRepository $smtp,
        private readonly EmailSender $email,
        private readonly TelegramSystemSettingsRepository $telegramSettings,
        private readonly TelegramConfigurationProvider $telegramConfiguration,
        private readonly TelegramBotSender $telegram,
        private readonly SecretBox $secretBox,
        private readonly Translator $translator,
        private readonly string $appUrl,
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
        $body = $this->translator->trans('notifications.smtp_test_body');
        if ($user === null || !$this->email->send(
            $user->email,
            $user->username,
            $this->translator->trans('notifications.smtp_test_subject'),
            '<p>' . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
            $body,
        )) {
            return $this->render($request, $response, null, $this->translator->trans('notifications.smtp_test_failed'), 502);
        }
        $_SESSION['_notification_admin_flash'] = $this->translator->trans('notifications.smtp_test_sent');
        return $this->redirect($response);
    }

    public function saveTelegram(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        if (($body['section'] ?? '') === 'proxy') {
            return $this->saveTelegramProxy($request, $response, $body);
        }

        $existing = $this->telegramSettings->get();
        $current = $this->telegramConfiguration->get();
        $botTokenCiphertext = $existing?->botTokenCiphertext;
        $webhookSecretCiphertext = $existing?->webhookSecretCiphertext;

        $botToken = trim((string) ($body['bot_token'] ?? ''));
        $webhookSecret = trim((string) ($body['webhook_secret'] ?? ''));

        if ($this->checked($body, 'generate_webhook_secret')) {
            $webhookSecret = bin2hex(random_bytes(24));
        }
        if ($botToken !== '') {
            $botTokenCiphertext = $this->secretBox->encrypt($botToken);
        }
        if ($webhookSecret !== '') {
            if (!$this->validWebhookSecret($webhookSecret)) {
                return $this->render($request, $response, null, $this->translator->trans('notifications.telegram_webhook_secret_invalid'), 422);
            }
            $webhookSecretCiphertext = $this->secretBox->encrypt($webhookSecret);
        }

        $this->telegramSettings->save(
            botName: trim((string) ($body['bot_name'] ?? '')),
            botTokenCiphertext: $botTokenCiphertext,
            webhookSecretCiphertext: $webhookSecretCiphertext,
            proxyEnabled: $existing !== null ? $existing->proxyEnabled : $current->proxyEnabled,
            proxyUrlCiphertext: $existing?->proxyUrlCiphertext,
        );

        $_SESSION['_notification_admin_flash'] = $this->translator->trans('notifications.telegram_settings_saved');
        return $this->redirect($response);
    }

    /** @param array<string, mixed> $body */
    private function saveTelegramProxy(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $body,
    ): ResponseInterface {
        $existing = $this->telegramSettings->get();
        $current = $this->telegramConfiguration->get();
        $proxyUrlCiphertext = $existing?->proxyUrlCiphertext;
        $proxyUrl = trim((string) ($body['proxy_url'] ?? ''));
        $proxyEnabled = $this->checked($body, 'proxy_enabled');

        if ($proxyUrl !== '') {
            if (!$this->validProxyUrl($proxyUrl)) {
                return $this->render($request, $response, null, $this->translator->trans('notifications.telegram_proxy_invalid'), 422);
            }
            $proxyUrlCiphertext = $this->secretBox->encrypt($proxyUrl);
        }

        $effectiveProxyUrl = $proxyUrl !== '' ? $proxyUrl : $current->proxyUrl;
        if ($proxyEnabled && $effectiveProxyUrl === '') {
            return $this->render($request, $response, null, $this->translator->trans('notifications.telegram_proxy_required'), 422);
        }

        $this->telegramSettings->save(
            botName: $existing?->botName ?? '',
            botTokenCiphertext: $existing?->botTokenCiphertext,
            webhookSecretCiphertext: $existing?->webhookSecretCiphertext,
            proxyEnabled: $proxyEnabled,
            proxyUrlCiphertext: $proxyUrlCiphertext,
        );

        $_SESSION['_notification_admin_flash'] = $this->translator->trans('notifications.telegram_proxy_saved');
        return $this->redirect($response);
    }

    public function testTelegram(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->telegram->probe();
        if (!$result->success) {
            return $this->render($request, $response, null, $this->telegramError($result), 502);
        }
        $_SESSION['_notification_admin_flash'] = $this->translator->trans('notifications.telegram_connection_ok');
        return $this->redirect($response);
    }

    public function setupTelegramWebhook(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $config = $this->telegramConfiguration->get();
        if ($config->botToken === '' || $config->webhookSecret === '') {
            return $this->render($request, $response, null, $this->translator->trans('notifications.telegram_deployment_incomplete'), 503);
        }
        $result = $this->telegram->setWebhook(rtrim($this->appUrl, '/') . '/telegram/webhook');
        if (!$result->success) {
            return $this->render($request, $response, null, $this->telegramError($result), 502);
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
        $telegram = $this->telegramConfiguration->get();

        return $this->view->render($response, 'notifications/admin.twig', [
            'smtp' => $smtp,
            'smtp_has_password' => $smtp !== null && $smtp->passwordCiphertext !== null && $smtp->passwordCiphertext !== '',
            'telegram_bot_name' => $telegram->botName,
            'telegram_token_configured' => $telegram->botToken !== '',
            'telegram_webhook_secret_configured' => $telegram->webhookSecret !== '',
            'telegram_proxy_enabled' => $telegram->proxyEnabled,
            'telegram_proxy_configured' => $telegram->proxyUrl !== '',
            'telegram_configured' => $telegram->botToken !== '' && $telegram->webhookSecret !== '',
            'telegram_webhook_url' => rtrim($this->appUrl, '/') . '/telegram/webhook',
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername(),
            'role' => $this->sessions->currentRole(),
            'message' => $message ?? (is_string($flash) ? $flash : null),
            'error' => $error,
        ])->withStatus($status);
    }

    private function telegramError(TelegramOperationResult $result): string
    {
        if ($result->code === 'transport_error') {
            return $this->translator->trans('notifications.telegram_transport_failed');
        }
        if ($result->code === 'missing_proxy_url') {
            return $this->translator->trans('notifications.telegram_proxy_required');
        }
        if ($result->code === 'missing_bot_token') {
            return $this->translator->trans('notifications.telegram_token_required');
        }
        if ($result->code === 'missing_webhook_secret') {
            return $this->translator->trans('notifications.telegram_webhook_secret_required');
        }
        if ($result->code === 'telegram_http_error') {
            return $this->translator->trans('notifications.telegram_api_error', [
                'status' => (string) ($result->httpStatus ?? 0),
                'description' => $result->description ?? $this->translator->trans('notifications.telegram_api_error_unknown'),
            ]);
        }
        return $this->translator->trans('notifications.telegram_webhook_failed');
    }

    private function validWebhookSecret(string $secret): bool
    {
        return strlen($secret) <= 256 && preg_match('/^[A-Za-z0-9_-]+$/D', $secret) === 1;
    }

    private function validProxyUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https', 'socks5', 'socks5h'], true)) {
            return false;
        }
        return true;
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
