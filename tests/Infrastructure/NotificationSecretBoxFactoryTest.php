<?php

declare(strict_types=1);

namespace Tms\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use Tms\Infrastructure\NotificationSecretBoxFactory;

final class NotificationSecretBoxFactoryTest extends TestCase
{
    public function testGeneratesAndReusesPersistentKey(): void
    {
        $directory = sys_get_temp_dir() . '/tms-secret-' . bin2hex(random_bytes(6));
        $path = $directory . '/notification.key';

        try {
            $first = NotificationSecretBoxFactory::create('', $path);
            $ciphertext = $first->encrypt('stored-credential');
            self::assertFileExists($path);
            self::assertSame(0600, fileperms($path) & 0777);

            $second = NotificationSecretBoxFactory::create('', $path);
            self::assertSame('stored-credential', $second->decrypt($ciphertext));
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testEnvironmentKeyTakesPrecedenceWithoutCreatingAFile(): void
    {
        $directory = sys_get_temp_dir() . '/tms-secret-' . bin2hex(random_bytes(6));
        $path = $directory . '/notification.key';
        $key = base64_encode(str_repeat('e', 32));

        $box = NotificationSecretBoxFactory::create($key, $path);
        self::assertSame('value', $box->decrypt($box->encrypt('value')));
        self::assertFileDoesNotExist($path);
    }
}
