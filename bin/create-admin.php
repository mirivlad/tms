<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Tms\Domain\User\UserRepository;
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
        fwrite(STDERR, "Missing required environment variable {$name}.\n");
        exit(2);
    }
    return $value;
};

$username = trim((string) ($argv[1] ?? ''));
$email = trim((string) ($argv[2] ?? ''));

if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/D', $username)) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <username> <email>\n");
    fwrite(STDERR, "Username must be 3-64 characters: letters, digits, dot, underscore or hyphen.\n");
    exit(2);
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "A valid email address is required.\n");
    exit(2);
}

$password = $env('TMS_ADMIN_PASSWORD');
if ($password === '') {
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "No interactive terminal. Set TMS_ADMIN_PASSWORD for this one command.\n");
        exit(2);
    }

    fwrite(STDOUT, 'Password: ');
    $hideInput = PHP_OS_FAMILY !== 'Windows';
    if ($hideInput) {
        shell_exec('stty -echo');
    }

    $line = fgets(STDIN);

    if ($hideInput) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    $password = $line === false ? '' : rtrim($line, "\r\n");
}

if (strlen($password) < 12) {
    fwrite(STDERR, "Administrator password must contain at least 12 characters.\n");
    exit(2);
}

try {
    $db = (new Database([
        'host' => $required('DB_HOST'),
        'port' => $env('DB_PORT', '3306'),
        'name' => $required('DB_NAME'),
        'user' => $required('DB_USER'),
        'password' => $required('DB_PASS'),
    ]))->connect();

    $id = (new UserRepository($db))->createAdmin(
        $username,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
    );

    fwrite(STDOUT, "Administrator created with ID {$id}.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Unable to create administrator: {$exception->getMessage()}\n");
    exit(1);
}
