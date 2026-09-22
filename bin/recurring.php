#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Tms\Application\RecurringTaskRunner;
use Tms\Domain\Activity\ActivityRepository;
use Tms\Domain\Checklist\ChecklistRepository;
use Tms\Domain\CustomField\TaskCustomFieldValueRepository;
use Tms\Domain\Project\ProjectStatusRepository;
use Tms\Domain\Recurrence\RecurrenceSchedule;
use Tms\Domain\Recurrence\TaskRecurrenceRepository;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\Infrastructure\Database;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$env = static function (string $name, string $default = ''): string {
    $value = getenv($name);
    if ($value === false) {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? $default;
    }
    return is_string($value) ? $value : $default;
};
$required = static function (string $name) use ($env): string {
    $value = $env($name);
    if ($value === '') {
        throw new RuntimeException("Required environment variable {$name} is not set.");
    }
    return $value;
};

try {
    $appTimezone = $env('APP_TIMEZONE', 'UTC');
    date_default_timezone_set((new DateTimeZone($appTimezone))->getName());
    $db = (new Database([
        'host' => $required('DB_HOST'),
        'port' => $env('DB_PORT', '3306'),
        'name' => $required('DB_NAME'),
        'user' => $required('DB_USER'),
        'password' => $required('DB_PASS'),
    ]))->connect();

    $offset = (new DateTimeImmutable('now', new DateTimeZone($appTimezone)))->format('P');
    $db->exec('SET time_zone = ' . $db->quote($offset));

    $runner = new RecurringTaskRunner(
        $db,
        new TaskRecurrenceRepository($db),
        new RecurrenceSchedule(),
        new TaskRepository($db),
        new StatusRepository($db),
        new ProjectStatusRepository($db),
        new ChecklistRepository($db),
        new TaskCustomFieldValueRepository($db),
        new ActivityRepository($db),
        $appTimezone,
    );
    $stats = $runner->run();
    fwrite(STDOUT, sprintf(
        "Recurring task run: candidates=%d generated=%d paused=%d\n",
        $stats['candidates'],
        $stats['generated'],
        $stats['paused'],
    ));
} catch (Throwable $error) {
    fwrite(STDERR, 'Recurring task run failed: ' . $error->getMessage() . "\n");
    exit(1);
}
