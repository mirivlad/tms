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
$restoreAccess = in_array('--restore-access', array_slice($argv, 2), true);
if ($identifier === '' || str_starts_with($identifier, '--')) {
    fwrite(STDERR, "Usage: php bin/reset-password.php <username-or-email> [--restore-access]\n");
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

    if ((!$user->isActive || !$user->isApproved) && !$restoreAccess) {
        $state = [];
        if (!$user->isActive) {
            $state[] = 'inactive';
        }
        if (!$user->isApproved) {
            $state[] = 'not approved';
        }
        fwrite(
            STDERR,
            "Account {$user->username} cannot sign in (" . implode(', ', $state) . "). "
            . "Password was not changed. Re-run with --restore-access to explicitly restore login access while resetting the password.\n"
        );
        exit(3);
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

    $db->beginTransaction();
    $users->replacePasswordHash($user->id, password_hash($password, PASSWORD_DEFAULT));
    if ($restoreAccess && (!$user->isActive || !$user->isApproved)) {
        $restore = $db->prepare(
            'UPDATE users
             SET is_active = 1,
                 approved_at = COALESCE(approved_at, UTC_TIMESTAMP()),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $restore->execute(['id' => $user->id]);
    }
    (new RememberTokenRepository($db))->deleteAllForUser($user->id);
    (new PasswordResetTokenRepository($db))->deleteAllForUser($user->id);
    $db->commit();

    $suffix = $restoreAccess && (!$user->isActive || !$user->isApproved)
        ? '; login access restored'
        : '';
    fwrite(
        STDOUT,
        "Password changed for {$user->username}{$suffix}; persistent-login and reset tokens revoked.\n"
    );
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Unable to reset password: {$error->getMessage()}\n");
    exit(1);
}
