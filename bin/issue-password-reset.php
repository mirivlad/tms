<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Tms\Domain\User\UserRepository;
use Tms\Infrastructure\Database;
use Tms\Security\PasswordResetTokenRepository;

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

$identifier = trim((string) ($argv[1] ?? ''));
if ($identifier === '') {
    fwrite(STDERR, "Usage: php bin/issue-password-reset.php <username-or-email>\n");
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
    $user = (new UserRepository($db))->findByIdentifier($identifier);
    if ($user === null) {
        fwrite(STDERR, "User not found.\n");
        exit(1);
    }
    $token = (new PasswordResetTokenRepository($db))->issue(
        $user->id,
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
        new DateInterval('PT1H'),
    );
    $url = rtrim($required('APP_URL'), '/') . '/reset-password?token=' . rawurlencode($token->value());
    fwrite(STDOUT, "One-time reset URL for {$user->username} (valid 1 hour):\n{$url}\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Unable to issue reset URL: {$error->getMessage()}\n");
    exit(1);
}
