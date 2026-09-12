<?php

declare(strict_types=1);

namespace Tms\Domain\Notification;

use DomainException;
use PDO;

final class SmtpSettingsRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function get(): ?SmtpSettingsRecord
    {
        $stmt = $this->db->query(
            'SELECT enabled, host, port, username, password_ciphertext, encryption, from_email, from_name
             FROM smtp_settings WHERE id = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return new SmtpSettingsRecord(
            enabled: (bool) $row['enabled'],
            host: (string) $row['host'],
            port: (int) $row['port'],
            username: (string) $row['username'],
            passwordCiphertext: $row['password_ciphertext'] !== null ? (string) $row['password_ciphertext'] : null,
            encryption: (string) $row['encryption'],
            fromEmail: (string) $row['from_email'],
            fromName: (string) $row['from_name'],
        );
    }

    public function save(
        bool $enabled,
        string $host,
        int $port,
        string $username,
        ?string $passwordCiphertext,
        string $encryption,
        string $fromEmail,
        string $fromName,
    ): void {
        $host = trim($host);
        $username = trim($username);
        $fromEmail = trim($fromEmail);
        $fromName = trim($fromName);
        if ($host === '' || strlen($host) > 255) {
            throw new DomainException('Invalid SMTP host.');
        }
        if ($port < 1 || $port > 65535) {
            throw new DomainException('Invalid SMTP port.');
        }
        if (!in_array($encryption, ['', 'tls', 'ssl'], true)) {
            throw new DomainException('Invalid SMTP encryption mode.');
        }
        if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Invalid SMTP sender email.');
        }
        if ($fromName === '' || mb_strlen($fromName) > 255) {
            throw new DomainException('Invalid SMTP sender name.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO smtp_settings (
                id, enabled, host, port, username, password_ciphertext, encryption, from_email, from_name
             ) VALUES (1, :enabled, :host, :port, :username, :password_ciphertext, :encryption, :from_email, :from_name)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), host = VALUES(host), port = VALUES(port),
                username = VALUES(username), password_ciphertext = VALUES(password_ciphertext),
                encryption = VALUES(encryption), from_email = VALUES(from_email), from_name = VALUES(from_name)'
        );
        $stmt->execute([
            'enabled' => $enabled ? 1 : 0,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password_ciphertext' => $passwordCiphertext,
            'encryption' => $encryption,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ]);
    }
}
