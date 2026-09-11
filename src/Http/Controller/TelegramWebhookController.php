<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Notification\TelegramLinkTokenRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\TelegramSender;

final class TelegramWebhookController
{
    public function __construct(
        private readonly NotificationSettingsRepository $settings,
        private readonly TelegramLinkTokenRepository $tokens,
        private readonly TelegramSender $telegram,
        private readonly Translator $translator,
        private readonly string $webhookSecret,
    ) {
    }

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->webhookSecret === ''
            || !hash_equals(
                $this->webhookSecret,
                $request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token'),
            )) {
            return $response->withStatus(403);
        }

        $payload = json_decode((string) $request->getBody(), true);
        if (!is_array($payload) || !isset($payload['message']) || !is_array($payload['message'])) {
            return $response->withStatus(200);
        }

        $message = $payload['message'];
        $chat = isset($message['chat']) && is_array($message['chat']) ? $message['chat'] : [];
        $chatId = isset($chat['id']) ? (string) $chat['id'] : '';
        $text = isset($message['text']) && is_string($message['text']) ? trim($message['text']) : '';
        $username = isset($chat['username']) && is_string($chat['username']) ? $chat['username'] : null;
        if ($chatId === '') {
            return $response->withStatus(200);
        }

        if ($text === '/start' || $text === '/help') {
            $this->telegram->send(
                $chatId,
                $this->translator->trans('notifications.telegram_bot_help'),
            );
            return $response->withStatus(200);
        }

        if (preg_match('/^\/link_(\d+)_([a-f0-9]{24}\.[a-f0-9]{64})$/D', $text, $matches) === 1) {
            $userId = (int) $matches[1];
            if ($userId > 0 && $this->tokens->consume($userId, $matches[2], new DateTimeImmutable('now'))) {
                $this->settings->attachTelegram($userId, $chatId, $username);
                $this->telegram->send(
                    $chatId,
                    $this->translator->trans('notifications.telegram_bot_linked'),
                );
            } else {
                $this->telegram->send(
                    $chatId,
                    $this->translator->trans('notifications.telegram_bot_invalid_link'),
                );
            }
        }

        return $response->withStatus(200);
    }
}
