<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

use GuzzleHttp\ClientInterface;
use Throwable;

final class TelegramBotSender implements TelegramSender
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly TelegramConfigurationProvider $configuration,
    ) {
    }

    public function send(string $chatId, string $text): bool
    {
        if ($chatId === '') {
            return false;
        }

        return $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ])->success;
    }

    public function probe(): TelegramOperationResult
    {
        return $this->request('getMe', []);
    }

    public function setWebhook(string $url): TelegramOperationResult
    {
        $config = $this->configuration->get();
        if ($config->webhookSecret === '') {
            return new TelegramOperationResult(false, 'missing_webhook_secret');
        }

        return $this->request('setWebhook', [
            'url' => $url,
            'secret_token' => $config->webhookSecret,
            'allowed_updates' => ['message'],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function request(string $method, array $payload): TelegramOperationResult
    {
        $config = $this->configuration->get();
        if ($config->botToken === '') {
            return new TelegramOperationResult(false, 'missing_bot_token');
        }
        if ($config->proxyEnabled && $config->proxyUrl === '') {
            return new TelegramOperationResult(false, 'missing_proxy_url');
        }

        $options = [
            'json' => $payload,
            'timeout' => 15,
            'connect_timeout' => 7,
            'http_errors' => false,
        ];
        if ($config->proxyEnabled) {
            $options['proxy'] = $config->proxyUrl;
        }

        try {
            $response = $this->http->request('POST', $this->endpoint($config->botToken, $method), $options);
            $status = $response->getStatusCode();
            $decoded = json_decode((string) $response->getBody(), true);
            $ok = is_array($decoded) && ($decoded['ok'] ?? false) === true;
            $description = is_array($decoded) && isset($decoded['description']) && is_string($decoded['description'])
                ? $decoded['description']
                : null;

            if ($ok && $status >= 200 && $status < 300) {
                return new TelegramOperationResult(true, 'ok', $status, $description);
            }

            return new TelegramOperationResult(false, 'telegram_http_error', $status, $description);
        } catch (Throwable) {
            // Never log the exception itself: Guzzle error messages can contain the bot token
            // and proxy credentials as part of request URLs.
            error_log('TMS Telegram request failed: transport error.');
            return new TelegramOperationResult(false, 'transport_error');
        }
    }

    private function endpoint(string $botToken, string $method): string
    {
        return 'https://api.telegram.org/bot' . rawurlencode($botToken) . '/' . $method;
    }
}
