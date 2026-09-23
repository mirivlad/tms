#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use Tms\Application\ActivityEventConsumer;
use Tms\Application\DomainEventBus;
use Tms\Application\DomainEventPublisher;
use Tms\Application\RecurringTaskRunner;
use Tms\Application\TaskEventNotificationConsumer;
use Tms\Domain\Activity\ActivityRepository;
use Tms\Domain\Event\DomainEventRepository;
use Tms\Domain\Checklist\ChecklistRepository;
use Tms\Domain\Notification\InternalNotificationRepository;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Notification\SmtpSettingsRepository;
use Tms\Domain\Notification\TelegramSystemSettingsRepository;
use Tms\Domain\CustomField\TaskCustomFieldValueRepository;
use Tms\Domain\Project\ProjectStatusRepository;
use Tms\Domain\Recurrence\RecurrenceSchedule;
use Tms\Domain\Recurrence\TaskRecurrenceRepository;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\Database;
use Tms\Infrastructure\NotificationSecretBoxFactory;
use Tms\Infrastructure\SmtpEmailSender;
use Tms\Infrastructure\TelegramBotSender;
use Tms\Infrastructure\TelegramConfigurationProvider;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$env = static function (string $name, string $default = ''): string {
    $value = getenv($name);
    if ($value === false) {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? $default;
    }
    return is_string($value) ? $value : $default;
};
$bool = static function (string $name, bool $default = false) use ($env): bool {
    return in_array(strtolower($env($name, $default ? 'true' : 'false')), ['1', 'true', 'yes', 'on'], true);
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

    $activity = new ActivityRepository($db);
    $tasks = new TaskRepository($db);
    $secretBox = NotificationSecretBoxFactory::create(
        $env('NOTIFICATION_SECRET'),
        $env('NOTIFICATION_SECRET_FILE', dirname(__DIR__) . '/var/secrets/notification.key'),
    );
    $telegramConfiguration = new TelegramConfigurationProvider(
        new TelegramSystemSettingsRepository($db),
        $secretBox,
        $env('TELEGRAM_BOT_NAME'),
        $env('TELEGRAM_BOT_TOKEN'),
        $env('TELEGRAM_WEBHOOK_SECRET'),
        $bool('TELEGRAM_PROXY_ENABLED'),
        $env('TELEGRAM_PROXY_URL'),
    );
    $taskNotifications = new TaskEventNotificationConsumer(
        $tasks,
        new InternalNotificationRepository($db),
        new NotificationSettingsRepository($db),
        new SmtpEmailSender(new SmtpSettingsRepository($db), $secretBox),
        new TelegramBotSender(new Client(), $telegramConfiguration),
        new Translator(dirname(__DIR__) . '/resources/i18n', $env('APP_LOCALE', 'en')),
        $required('APP_URL'),
    );
    $eventPublisher = new DomainEventPublisher(
        $db,
        new DomainEventBus(
            new DomainEventRepository($db),
            [new ActivityEventConsumer($activity), $taskNotifications],
        ),
        $appTimezone,
    );

    $runner = new RecurringTaskRunner(
        $db,
        new TaskRecurrenceRepository($db),
        new RecurrenceSchedule(),
        $tasks,
        new StatusRepository($db),
        new ProjectStatusRepository($db),
        new ChecklistRepository($db),
        new TaskCustomFieldValueRepository($db),
        $eventPublisher,
        $activity,
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
