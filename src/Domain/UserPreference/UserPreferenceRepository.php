<?php

declare(strict_types=1);

namespace Tms\Domain\UserPreference;

use DateTimeZone;
use DomainException;
use PDO;

final class UserPreferenceRepository
{
    public const THEMES = ['dark', 'light', 'system'];

    public function __construct(private readonly PDO $db, private readonly string $defaultTimezone = 'UTC')
    {
    }

    public function getForUser(int $userId): UserPreferenceRecord
    {
        $stmt = $this->db->prepare('SELECT user_id, timezone, theme FROM user_preferences WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            return new UserPreferenceRecord((int) $row['user_id'], (string) $row['timezone'], (string) $row['theme']);
        }
        return new UserPreferenceRecord($userId, $this->defaultTimezone, 'dark');
    }

    public function saveForUser(int $userId, string $timezone, string $theme): void
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new DomainException('Invalid timezone.');
        }
        if (!in_array($theme, self::THEMES, true)) {
            throw new DomainException('Invalid theme.');
        }
        $exists = $this->db->prepare('SELECT 1 FROM user_preferences WHERE user_id = :user_id LIMIT 1');
        $exists->execute(['user_id' => $userId]);
        if ($exists->fetchColumn() !== false) {
            $stmt = $this->db->prepare(
                'UPDATE user_preferences SET timezone = :timezone, theme = :theme, updated_at = CURRENT_TIMESTAMP
                 WHERE user_id = :user_id'
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO user_preferences (user_id, timezone, theme, updated_at)
                 VALUES (:user_id, :timezone, :theme, CURRENT_TIMESTAMP)'
            );
        }
        $stmt->execute(['user_id' => $userId, 'timezone' => $timezone, 'theme' => $theme]);
    }
}
