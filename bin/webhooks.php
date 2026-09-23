#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use Tms\Application\WebhookDeliveryRunner;
use Tms\Domain\Event\DomainEventRepository;
use Tms\Domain\Webhook\WebhookDeliveryRepository;
use Tms\Domain\Webhook\WebhookSubscriptionRepository;
use Tms\Infrastructure\Database;
use Tms\Infrastructure\GuzzleWebhookSender;
use Tms\Infrastructure\NotificationSecretBoxFactory;

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

    $secretBox = NotificationSecretBoxFactory::create(
        $env('NOTIFICATION_SECRET'),
        $env('NOTIFICATION_SECRET_FILE', dirname(__DIR__) . '/var/secrets/notification.key'),
    );
    $subscriptions = new WebhookSubscriptionRepository($db, $secretBox);
    $deliveries = new WebhookDeliveryRepository($db);
    $runner = new WebhookDeliveryRunner(
        $subscriptions,
        $deliveries,
        new DomainEventRepository($db),
        new GuzzleWebhookSender(new Client()),
    );

    $stats = $runner->run();
    fwrite(STDOUT, sprintf(
        "Webhook run: subscriptions=%d recovered=%d attempted=%d delivered=%d retried=%d failed=%d\n",
        $stats['subscriptions'],
        $stats['recovered'],
        $stats['attempted'],
        $stats['delivered'],
        $stats['retried'],
        $stats['failed'],
    ));
} catch (Throwable $error) {
    fwrite(STDERR, 'Webhook run failed: ' . $error->getMessage() . "\n");
    exit(1);
}
