#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use Tms\Application\TelegramUpdateHandler;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Notification\TelegramLinkTokenRepository;
use Tms\Domain\Notification\TelegramSystemSettingsRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\Database;
use Tms\Infrastructure\NotificationSecretBoxFactory;
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
$bool = static fn (string $name, bool $default = false): bool => in_array(
    strtolower($env($name, $default ? 'true' : 'false')),
    ['1', 'true', 'yes', 'on'],
    true,
);
$required = static function (string $name) use ($env): string {
    $value = $env($name);
    if ($value === '') {
        throw new RuntimeException("Required environment variable {$name} is not set.");
    }
    return $value;
};

try {
    $timezone = new DateTimeZone($env('APP_TIMEZONE', 'UTC'));
    date_default_timezone_set($timezone->getName());
    $db = (new Database([
        'host' => $required('DB_HOST'),
        'port' => $env('DB_PORT', '3306'),
        'name' => $required('DB_NAME'),
        'user' => $required('DB_USER'),
        'password' => $required('DB_PASS'),
    ]))->connect();
    $lock = $db->query("SELECT GET_LOCK('tms_telegram_polling', 0)");
    if ($lock === false || (int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Another Telegram polling worker already owns the polling lock.');
    }

    $secretBox = NotificationSecretBoxFactory::create(
        $env('NOTIFICATION_SECRET'),
        $env('NOTIFICATION_SECRET_FILE', dirname(__DIR__) . '/var/secrets/notification.key'),
    );
    $systemSettings = new TelegramSystemSettingsRepository($db);
    $configuration = new TelegramConfigurationProvider(
        $systemSettings,
        $secretBox,
        $env('TELEGRAM_BOT_NAME'),
        $env('TELEGRAM_BOT_TOKEN'),
        $env('TELEGRAM_WEBHOOK_SECRET'),
        $bool('TELEGRAM_PROXY_ENABLED'),
        $env('TELEGRAM_PROXY_URL'),
    );
    $telegram = new TelegramBotSender(new Client(), $configuration);
    $handler = new TelegramUpdateHandler(
        new NotificationSettingsRepository($db),
        new TelegramLinkTokenRepository($db),
        $telegram,
        new Translator(dirname(__DIR__) . '/resources/i18n', $env('APP_LOCALE', 'en')),
    );

    $webhookCleared = false;
    while (true) {
        $stored = $systemSettings->get();
        if ($stored === null || $stored->deliveryMode !== 'polling') {
            $webhookCleared = false;
            sleep(3);
            continue;
        }
        if (!$webhookCleared) {
            $deleted = $telegram->deleteWebhook(false);
            if (!$deleted->success) {
                fwrite(STDERR, "Telegram polling: unable to remove webhook; retrying.\n");
                sleep(5);
                continue;
            }
            $webhookCleared = true;
        }

        $result = $telegram->getUpdates($stored->pollingOffset, 25);
        if (!$result->success || !is_array($result->result)) {
            fwrite(STDERR, "Telegram polling: API request failed; retrying.\n");
            sleep(3);
            continue;
        }
        foreach ($result->result as $update) {
            if (!is_array($update) || !isset($update['update_id']) || !is_int($update['update_id'])) {
                continue;
            }
            $handler->handle($update);
            $systemSettings->advancePollingOffset($update['update_id'] + 1);
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Telegram polling worker failed: ' . $error->getMessage() . "\n");
    exit(1);
}
