<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Tms\Domain\User\UserRepository;
use Tms\Infrastructure\Database;
use Tms\Security\PasswordResetTokenRepository;
use Tms\Security\RememberTokenRepository;

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
    fwrite(STDERR, "Usage: php bin/reset-password.php <username-or-email>\n");
    exit(2);
}

$password = $env('TMS_RESET_PASSWORD');
if ($password === '') {
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "No interactive terminal. Set TMS_RESET_PASSWORD for this one command.\n");
        exit(2);
    }
    $hide = PHP_OS_FAMILY !== 'Windows';
    fwrite(STDOUT, 'New password: ');
    if ($hide) { shell_exec('stty -echo'); }
    $first = fgets(STDIN);
    if ($hide) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
    fwrite(STDOUT, 'Repeat new password: ');
    if ($hide) { shell_exec('stty -echo'); }
    $second = fgets(STDIN);
    if ($hide) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
    $password = $first === false ? '' : rtrim($first, "\r\n");
    $confirmation = $second === false ? '' : rtrim($second, "\r\n");
    if (!hash_equals($password, $confirmation)) {
        fwrite(STDERR, "Passwords do not match.\n");
        exit(2);
    }
}

if (strlen($password) < 12) {
    fwrite(STDERR, "Password must contain at least 12 characters.\n");
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
    $users = new UserRepository($db);
    $user = $users->findByIdentifier($identifier);
    if ($user === null) {
        fwrite(STDERR, "User not found.\n");
        exit(1);
    }
    $users->replacePasswordHash($user->id, password_hash($password, PASSWORD_DEFAULT));
    (new RememberTokenRepository($db))->deleteAllForUser($user->id);
    (new PasswordResetTokenRepository($db))->deleteAllForUser($user->id);
    fwrite(STDOUT, "Password changed for {$user->username}; persistent-login and reset tokens revoked.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Unable to reset password: {$error->getMessage()}\n");
    exit(1);
}
