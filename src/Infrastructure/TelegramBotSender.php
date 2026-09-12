<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

use GuzzleHttp\ClientInterface;
use Throwable;

final class TelegramBotSender implements TelegramSender
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $botToken,
    ) {
    }

    public function send(string $chatId, string $text): bool
    {
        if ($this->botToken === '' || $chatId === '') {
            return false;
        }
        try {
            $response = $this->http->request('POST', $this->endpoint('sendMessage'), [
                'json' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ],
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);
            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (Throwable $error) {
            error_log('TMS Telegram notification failed: ' . $error->getMessage());
            return false;
        }
    }

    public function setWebhook(string $url, string $secret): bool
    {
        if ($this->botToken === '' || $url === '' || $secret === '') {
            return false;
        }
        try {
            $response = $this->http->request('POST', $this->endpoint('setWebhook'), [
                'json' => [
                    'url' => $url,
                    'secret_token' => $secret,
                    'allowed_updates' => ['message'],
                ],
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);
            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (Throwable $error) {
            error_log('TMS Telegram webhook setup failed: ' . $error->getMessage());
            return false;
        }
    }

    private function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot' . rawurlencode($this->botToken) . '/' . $method;
    }
}
