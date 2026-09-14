<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

use Tms\Domain\Notification\TelegramSystemSettingsRepository;

final class TelegramConfigurationProvider
{
    public function __construct(
        private readonly TelegramSystemSettingsRepository $settings,
        private readonly SecretBox $secretBox,
        private readonly string $fallbackBotName = '',
        private readonly string $fallbackBotToken = '',
        private readonly string $fallbackWebhookSecret = '',
        private readonly bool $fallbackProxyEnabled = false,
        private readonly string $fallbackProxyUrl = '',
    ) {}

    public function get(): TelegramConfiguration
    {
        $stored = $this->settings->get();
        $botName = $stored !== null && $stored->botName !== '' ? $stored->botName : $this->fallbackBotName;
        $botToken = $this->decryptOrFallback($stored?->botTokenCiphertext, $this->fallbackBotToken);
        $webhookSecret = $this->decryptOrFallback($stored?->webhookSecretCiphertext, $this->fallbackWebhookSecret);
        $proxyUrl = $this->decryptOrFallback($stored?->proxyUrlCiphertext, $this->fallbackProxyUrl);
        $proxyEnabled = $stored !== null ? $stored->proxyEnabled : $this->fallbackProxyEnabled;
        $deliveryMode = $stored !== null ? $stored->deliveryMode : 'webhook';

        return new TelegramConfiguration($botName, $botToken, $webhookSecret, $deliveryMode, $proxyEnabled, $proxyUrl);
    }

    private function decryptOrFallback(?string $ciphertext, string $fallback): string
    {
        return $ciphertext !== null && $ciphertext !== '' ? $this->secretBox->decrypt($ciphertext) : $fallback;
    }
}
