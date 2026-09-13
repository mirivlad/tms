<?php

declare(strict_types=1);

namespace Tms\Tests\Domain;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\UserPreference\UserPreferenceRepository;

final class UserPreferenceRepositoryTest extends TestCase
{
    public function testPreferencesHaveDefaultsAndCanBeUpdated(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE user_preferences (user_id INTEGER PRIMARY KEY, timezone TEXT, theme TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $repository = new UserPreferenceRepository($db, 'Europe/Helsinki');

        $default = $repository->getForUser(7);
        self::assertSame('Europe/Helsinki', $default->timezone);
        self::assertSame('dark', $default->theme);

        $repository->saveForUser(7, 'Asia/Irkutsk', 'system');
        $repository->saveForUser(7, 'UTC', 'light');
        $saved = $repository->getForUser(7);
        self::assertSame('UTC', $saved->timezone);
        self::assertSame('light', $saved->theme);
    }

    public function testInvalidThemeIsRejected(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE user_preferences (user_id INTEGER PRIMARY KEY, timezone TEXT, theme TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $repository = new UserPreferenceRepository($db);
        $this->expectException(DomainException::class);
        $repository->saveForUser(1, 'UTC', 'neon');
    }
}
