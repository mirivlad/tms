<?php

declare(strict_types=1);

namespace Tms\Domain\Notification;

use PDO;

final class TelegramSystemSettingsRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function get(): ?TelegramSystemSettingsRecord
    {
        $stmt = $this->db->query(
            'SELECT bot_name, bot_token_ciphertext, webhook_secret_ciphertext,
                    proxy_enabled, proxy_url_ciphertext
             FROM telegram_system_settings WHERE id = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new TelegramSystemSettingsRecord(
            botName: (string) $row['bot_name'],
            botTokenCiphertext: $row['bot_token_ciphertext'] !== null ? (string) $row['bot_token_ciphertext'] : null,
            webhookSecretCiphertext: $row['webhook_secret_ciphertext'] !== null ? (string) $row['webhook_secret_ciphertext'] : null,
            proxyEnabled: (bool) $row['proxy_enabled'],
            proxyUrlCiphertext: $row['proxy_url_ciphertext'] !== null ? (string) $row['proxy_url_ciphertext'] : null,
        );
    }

    public function save(
        string $botName,
        ?string $botTokenCiphertext,
        ?string $webhookSecretCiphertext,
        bool $proxyEnabled,
        ?string $proxyUrlCiphertext,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO telegram_system_settings (
                id, bot_name, bot_token_ciphertext, webhook_secret_ciphertext,
                proxy_enabled, proxy_url_ciphertext
             ) VALUES (1, :bot_name, :bot_token_ciphertext, :webhook_secret_ciphertext,
                :proxy_enabled, :proxy_url_ciphertext)
             ON DUPLICATE KEY UPDATE
                bot_name = VALUES(bot_name),
                bot_token_ciphertext = VALUES(bot_token_ciphertext),
                webhook_secret_ciphertext = VALUES(webhook_secret_ciphertext),
                proxy_enabled = VALUES(proxy_enabled),
                proxy_url_ciphertext = VALUES(proxy_url_ciphertext)'
        );
        $stmt->execute([
            'bot_name' => trim($botName),
            'bot_token_ciphertext' => $botTokenCiphertext,
            'webhook_secret_ciphertext' => $webhookSecretCiphertext,
            'proxy_enabled' => $proxyEnabled ? 1 : 0,
            'proxy_url_ciphertext' => $proxyUrlCiphertext,
        ]);
    }
}
