<?php

declare(strict_types=1);

namespace Tms\Domain\Notification;

use DomainException;
use PDO;

final class NotificationSettingsRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function getForUser(int $userId): NotificationSettingsRecord
    {
        $stmt = $this->db->prepare($this->selectSql() . ' WHERE u.id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new DomainException('User not found.');
        }
        return $this->hydrate($row);
    }

    /** @return list<NotificationSettingsRecord> */
    public function listRunnableUsers(): array
    {
        $stmt = $this->db->query(
            $this->selectSql()
            . " WHERE u.is_active = 1 AND u.approved_at IS NOT NULL
                 AND ((COALESCE(ns.email_enabled, 0) = 1)
                   OR (COALESCE(ns.telegram_enabled, 0) = 1 AND ns.telegram_chat_id IS NOT NULL))
                 AND (COALESCE(ns.notify_tomorrow, 0) = 1
                   OR COALESCE(ns.notify_upcoming, 0) = 1
                   OR COALESCE(ns.notify_overdue, 0) = 1
                   OR COALESCE(ns.notify_digest, 0) = 1)
               ORDER BY u.id ASC"
        );
        if ($stmt === false) {
            return [];
        }
        $records = [];
        while (($row = $stmt->fetch()) !== false) {
            if (is_array($row)) {
                $records[] = $this->hydrate($row);
            }
        }
        return $records;
    }

    /** @param array<string, mixed> $values */
    public function saveForUser(int $userId, array $values): void
    {
        $emailAddress = trim((string) ($values['email_address'] ?? ''));
        if ($emailAddress !== '' && filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Invalid notification email address.');
        }

        $times = [
            'tomorrow_time' => $values['tomorrow_time'] ?? '08:00',
            'overdue_time' => $values['overdue_time'] ?? '09:00',
            'digest_time' => $values['digest_time'] ?? '19:30',
        ];
        foreach ($times as $name => $time) {
            if (!is_string($time) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time) !== 1) {
                throw new DomainException("Invalid notification time: {$name}.");
            }
        }

        $minutes = [];
        foreach (['urgent_minutes', 'high_minutes', 'medium_minutes', 'low_minutes'] as $name) {
            $raw = $values[$name] ?? null;
            if (is_int($raw)) {
                $value = $raw;
            } elseif (is_string($raw) && ctype_digit($raw)) {
                $value = (int) $raw;
            } else {
                throw new DomainException("Invalid notification lead time: {$name}.");
            }
            if ($value < 1 || $value > 10080) {
                throw new DomainException("Notification lead time out of range: {$name}.");
            }
            $minutes[$name] = $value;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO notification_settings (
                user_id, email_enabled, email_address, telegram_enabled,
                notify_tomorrow, tomorrow_time, notify_upcoming,
                urgent_minutes, high_minutes, medium_minutes, low_minutes,
                notify_overdue, overdue_time, notify_digest, digest_time
             ) VALUES (
                :user_id, :email_enabled, :email_address, :telegram_enabled,
                :notify_tomorrow, :tomorrow_time, :notify_upcoming,
                :urgent_minutes, :high_minutes, :medium_minutes, :low_minutes,
                :notify_overdue, :overdue_time, :notify_digest, :digest_time
             )
             ON DUPLICATE KEY UPDATE
                email_enabled = VALUES(email_enabled),
                email_address = VALUES(email_address),
                telegram_enabled = VALUES(telegram_enabled),
                notify_tomorrow = VALUES(notify_tomorrow),
                tomorrow_time = VALUES(tomorrow_time),
                notify_upcoming = VALUES(notify_upcoming),
                urgent_minutes = VALUES(urgent_minutes),
                high_minutes = VALUES(high_minutes),
                medium_minutes = VALUES(medium_minutes),
                low_minutes = VALUES(low_minutes),
                notify_overdue = VALUES(notify_overdue),
                overdue_time = VALUES(overdue_time),
                notify_digest = VALUES(notify_digest),
                digest_time = VALUES(digest_time)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'email_enabled' => !empty($values['email_enabled']) ? 1 : 0,
            'email_address' => $emailAddress !== '' ? $emailAddress : null,
            'telegram_enabled' => !empty($values['telegram_enabled']) ? 1 : 0,
            'notify_tomorrow' => !empty($values['notify_tomorrow']) ? 1 : 0,
            'tomorrow_time' => $times['tomorrow_time'],
            'notify_upcoming' => !empty($values['notify_upcoming']) ? 1 : 0,
            'urgent_minutes' => $minutes['urgent_minutes'],
            'high_minutes' => $minutes['high_minutes'],
            'medium_minutes' => $minutes['medium_minutes'],
            'low_minutes' => $minutes['low_minutes'],
            'notify_overdue' => !empty($values['notify_overdue']) ? 1 : 0,
            'overdue_time' => $times['overdue_time'],
            'notify_digest' => !empty($values['notify_digest']) ? 1 : 0,
            'digest_time' => $times['digest_time'],
        ]);
    }

    public function attachTelegram(int $userId, string $chatId, ?string $username): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO notification_settings (user_id, telegram_enabled, telegram_chat_id, telegram_username)
             VALUES (:user_id, 1, :chat_id, :username)
             ON DUPLICATE KEY UPDATE telegram_enabled = 1,
                 telegram_chat_id = VALUES(telegram_chat_id),
                 telegram_username = VALUES(telegram_username)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'chat_id' => $chatId,
            'username' => $username !== null && trim($username) !== '' ? trim($username) : null,
        ]);
    }

    public function detachTelegram(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE notification_settings
             SET telegram_enabled = 0, telegram_chat_id = NULL, telegram_username = NULL
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
    }

    private function selectSql(): string
    {
        return "SELECT u.id AS user_id, u.username, u.email AS account_email,
                       COALESCE(ns.email_enabled, 0) AS email_enabled, ns.email_address,
                       COALESCE(ns.telegram_enabled, 0) AS telegram_enabled,
                       ns.telegram_chat_id, ns.telegram_username,
                       COALESCE(ns.notify_tomorrow, 0) AS notify_tomorrow,
                       COALESCE(TIME_FORMAT(ns.tomorrow_time, '%H:%i'), '08:00') AS tomorrow_time,
                       COALESCE(ns.notify_upcoming, 0) AS notify_upcoming,
                       COALESCE(ns.urgent_minutes, 15) AS urgent_minutes,
                       COALESCE(ns.high_minutes, 60) AS high_minutes,
                       COALESCE(ns.medium_minutes, 240) AS medium_minutes,
                       COALESCE(ns.low_minutes, 1440) AS low_minutes,
                       COALESCE(ns.notify_overdue, 0) AS notify_overdue,
                       COALESCE(TIME_FORMAT(ns.overdue_time, '%H:%i'), '09:00') AS overdue_time,
                       COALESCE(ns.notify_digest, 0) AS notify_digest,
                       COALESCE(TIME_FORMAT(ns.digest_time, '%H:%i'), '19:30') AS digest_time
                FROM users u
                LEFT JOIN notification_settings ns ON ns.user_id = u.id";
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): NotificationSettingsRecord
    {
        return new NotificationSettingsRecord(
            userId: (int) $row['user_id'],
            username: (string) $row['username'],
            accountEmail: (string) $row['account_email'],
            emailEnabled: (bool) $row['email_enabled'],
            emailAddress: $row['email_address'] !== null ? (string) $row['email_address'] : null,
            telegramEnabled: (bool) $row['telegram_enabled'],
            telegramChatId: $row['telegram_chat_id'] !== null ? (string) $row['telegram_chat_id'] : null,
            telegramUsername: $row['telegram_username'] !== null ? (string) $row['telegram_username'] : null,
            notifyTomorrow: (bool) $row['notify_tomorrow'],
            tomorrowTime: (string) $row['tomorrow_time'],
            notifyUpcoming: (bool) $row['notify_upcoming'],
            urgentMinutes: (int) $row['urgent_minutes'],
            highMinutes: (int) $row['high_minutes'],
            mediumMinutes: (int) $row['medium_minutes'],
            lowMinutes: (int) $row['low_minutes'],
            notifyOverdue: (bool) $row['notify_overdue'],
            overdueTime: (string) $row['overdue_time'],
            notifyDigest: (bool) $row['notify_digest'],
            digestTime: (string) $row['digest_time'],
        );
    }
}
