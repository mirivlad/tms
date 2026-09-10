<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Tms\Infrastructure\Database;
use Tms\Infrastructure\Migrator;

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
        fwrite(STDERR, "Missing required environment variable {$name}.\n");
        exit(2);
    }
    return $value;
};

try {
    $db = (new Database([
        'host' => $required('DB_HOST'),
        'port' => $env('DB_PORT', '3306'),
        'name' => $required('DB_NAME'),
        'user' => $required('DB_USER'),
        'password' => $required('DB_PASS'),
    ]))->connect();

    $applied = (new Migrator($db, dirname(__DIR__) . '/database/migrations'))->migrate();

    if ($applied === []) {
        fwrite(STDOUT, "Database is up to date.\n");
    } else {
        foreach ($applied as $migration) {
            fwrite(STDOUT, "Applied {$migration}\n");
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "Migration failed: {$exception->getMessage()}\n");
    exit(1);
}
