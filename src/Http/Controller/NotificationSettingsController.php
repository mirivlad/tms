<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateInterval;
use DateTimeImmutable;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Notification\TelegramLinkTokenRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\TelegramSender;
use Tms\Security\SessionManager;

final class NotificationSettingsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly NotificationSettingsRepository $settings,
        private readonly TelegramLinkTokenRepository $linkTokens,
        private readonly TelegramSender $telegram,
        private readonly Translator $translator,
        private readonly string $botName,
        private readonly string $deploymentTimezone,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        try {
            $this->settings->saveForUser($this->userId(), [
                'email_enabled' => $this->checked($body, 'email_enabled'),
                'email_address' => (string) ($body['email_address'] ?? ''),
                'telegram_enabled' => $this->checked($body, 'telegram_enabled'),
                'notify_tomorrow' => $this->checked($body, 'notify_tomorrow'),
                'tomorrow_time' => (string) ($body['tomorrow_time'] ?? '08:00'),
                'notify_upcoming' => $this->checked($body, 'notify_upcoming'),
                'urgent_minutes' => (string) ($body['urgent_minutes'] ?? '15'),
                'high_minutes' => (string) ($body['high_minutes'] ?? '60'),
                'medium_minutes' => (string) ($body['medium_minutes'] ?? '240'),
                'low_minutes' => (string) ($body['low_minutes'] ?? '1440'),
                'notify_overdue' => $this->checked($body, 'notify_overdue'),
                'overdue_time' => (string) ($body['overdue_time'] ?? '09:00'),
                'notify_digest' => $this->checked($body, 'notify_digest'),
                'digest_time' => (string) ($body['digest_time'] ?? '19:30'),
            ]);
        } catch (DomainException $error) {
            return $this->render($request, $response, $this->translator->trans('notifications.invalid_settings'), $error->getMessage(), 422);
        }
        $_SESSION['_notification_flash'] = $this->translator->trans('notifications.saved');
        return $this->redirect($response);
    }

    public function generateTelegramLink(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->botName === '') {
            return $this->render($request, $response, null, $this->translator->trans('notifications.telegram_not_configured'), 503);
        }
        $token = $this->linkTokens->issue($this->userId(), new DateTimeImmutable('now'), new DateInterval('PT24H'));
        $_SESSION['_telegram_link_command'] = '/link_' . $this->userId() . '_' . $token;
        $_SESSION['_notification_flash'] = $this->translator->trans('notifications.telegram_link_created');
        return $this->redirect($response);
    }

    public function disconnectTelegram(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->settings->detachTelegram($this->userId());
        $_SESSION['_notification_flash'] = $this->translator->trans('notifications.telegram_disconnected');
        return $this->redirect($response);
    }

    public function testTelegram(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $settings = $this->settings->getForUser($this->userId());
        if ($settings->telegramChatId === null || !$this->telegram->send($settings->telegramChatId, 'TMS: Telegram notifications are configured correctly.')) {
            return $this->render($request, $response, null, $this->translator->trans('notifications.telegram_test_failed'), 502);
        }
        $_SESSION['_notification_flash'] = $this->translator->trans('notifications.telegram_test_sent');
        return $this->redirect($response);
    }

    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?string $message = null,
        ?string $error = null,
        int $status = 200,
    ): ResponseInterface {
        $flash = $_SESSION['_notification_flash'] ?? null;
        unset($_SESSION['_notification_flash']);
        $command = $_SESSION['_telegram_link_command'] ?? null;
        unset($_SESSION['_telegram_link_command']);
        return $this->view->render($response, 'notifications/settings.twig', [
            'settings' => $this->settings->getForUser($this->userId()),
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername(),
            'role' => $this->sessions->currentRole(),
            'bot_name' => $this->botName,
            'deployment_timezone' => $this->deploymentTimezone,
            'telegram_link_command' => is_string($command) ? $command : null,
            'message' => $message ?? (is_string($flash) ? $flash : null),
            'error' => $error,
        ])->withStatus($status);
    }

    private function redirect(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Location', '/settings/notifications')->withStatus(302);
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

    private function userId(): int
    {
        return $this->sessions->currentUserId() ?? 0;
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }
}
