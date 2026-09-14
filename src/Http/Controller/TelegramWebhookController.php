<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Application\TelegramUpdateHandler;
use Tms\Infrastructure\TelegramConfigurationProvider;

final class TelegramWebhookController
{
    public function __construct(
        private readonly TelegramUpdateHandler $updates,
        private readonly TelegramConfigurationProvider $telegramConfiguration,
    ) {}

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $config = $this->telegramConfiguration->get();
        if ($config->deliveryMode !== 'webhook') {
            return $response->withStatus(200);
        }
        if ($config->webhookSecret === ''
            || !hash_equals(
                $config->webhookSecret,
                $request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token'),
            )) {
            return $response->withStatus(403);
        }

        $payload = json_decode((string) $request->getBody(), true);
        if (is_array($payload)) {
            $this->updates->handle($payload);
        }
        return $response->withStatus(200);
    }
}
