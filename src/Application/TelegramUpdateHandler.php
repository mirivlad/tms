<?php

declare(strict_types=1);

namespace Tms\Application;

use DateTimeImmutable;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Notification\TelegramLinkTokenRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\TelegramSender;

final class TelegramUpdateHandler
{
    public function __construct(
        private readonly NotificationSettingsRepository $settings,
        private readonly TelegramLinkTokenRepository $tokens,
        private readonly TelegramSender $telegram,
        private readonly Translator $translator,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        $message = isset($update['message']) && is_array($update['message']) ? $update['message'] : null;
        if ($message === null) {
            return;
        }
        $chat = isset($message['chat']) && is_array($message['chat']) ? $message['chat'] : [];
        $chatId = isset($chat['id']) ? (string) $chat['id'] : '';
        $text = isset($message['text']) && is_string($message['text']) ? trim($message['text']) : '';
        $username = isset($chat['username']) && is_string($chat['username']) ? $chat['username'] : null;
        if ($chatId === '') {
            return;
        }

        if ($text === '/start' || $text === '/help') {
            $this->telegram->send($chatId, $this->translator->trans('notifications.telegram_bot_help'));
            return;
        }

        if (preg_match('/^\/link_(\d+)_([a-f0-9]{24}\.[a-f0-9]{64})$/D', $text, $matches) !== 1) {
            return;
        }

        $userId = (int) $matches[1];
        if ($userId > 0 && $this->tokens->consume($userId, $matches[2], new DateTimeImmutable('now'))) {
            $this->settings->attachTelegram($userId, $chatId, $username);
            $this->telegram->send($chatId, $this->translator->trans('notifications.telegram_bot_linked'));
            return;
        }
        $this->telegram->send($chatId, $this->translator->trans('notifications.telegram_bot_invalid_link'));
    }
}
