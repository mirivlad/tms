#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use Tms\Application\NotificationRunner;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Notification\NotificationTaskRepository;
use Tms\Domain\Notification\SentNotificationRepository;
use Tms\Domain\Notification\SmtpSettingsRepository;
use Tms\Infrastructure\Database;
use Tms\Infrastructure\SecretBox;
use Tms\Infrastructure\SmtpEmailSender;
use Tms\Infrastructure\TelegramBotSender;

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
    $timezone = $env('APP_TIMEZONE', 'UTC');
    date_default_timezone_set((new DateTimeZone($timezone))->getName());
    $db = (new Database([
        'host' => $required('DB_HOST'),
        'port' => $env('DB_PORT', '3306'),
        'name' => $required('DB_NAME'),
        'user' => $required('DB_USER'),
        'password' => $required('DB_PASS'),
    ]))->connect();
    $offset = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('P');
    $db->exec("SET time_zone = " . $db->quote($offset));

    $secretRaw = $env('NOTIFICATION_SECRET');
    $secretBox = $secretRaw !== '' ? new SecretBox($secretRaw) : null;
    $smtp = new SmtpSettingsRepository($db);
    $runner = new NotificationRunner(
        new NotificationSettingsRepository($db),
        new NotificationTaskRepository($db),
        new SentNotificationRepository($db),
        new SmtpEmailSender($smtp, $secretBox),
        new TelegramBotSender(new Client(), $env('TELEGRAM_BOT_TOKEN')),
        $required('APP_URL'),
    );
    $stats = $runner->run();
    fwrite(STDOUT, sprintf("Notification run: users=%d attempted=%d sent=%d\n", $stats['users'], $stats['attempted'], $stats['sent']));
} catch (Throwable $error) {
    fwrite(STDERR, 'Notification run failed: ' . $error->getMessage() . "\n");
    exit(1);
}
