<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

use RuntimeException;

final class NotificationSecretBoxFactory
{
    public static function create(string $environmentSecret, string $filePath): SecretBox
    {
        if ($environmentSecret !== '') {
            return new SecretBox($environmentSecret);
        }

        $directory = dirname($filePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create notification secret directory.');
        }

        if (!is_file($filePath)) {
            $generated = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            $previousUmask = umask(0077);
            $handle = @fopen($filePath, 'x');
            umask($previousUmask);
            if ($handle !== false) {
                if (fwrite($handle, $generated . "\n") === false) {
                    fclose($handle);
                    @unlink($filePath);
                    throw new RuntimeException('Unable to write notification encryption key.');
                }
                fclose($handle);
                @chmod($filePath, 0600);
            }
        }

        @chmod($filePath, 0600);
        @chown($filePath, 'www-data');
        @chgrp($filePath, 'www-data');
        $stored = is_file($filePath) ? trim((string) file_get_contents($filePath)) : '';
        if ($stored === '') {
            throw new RuntimeException('Unable to load or create notification encryption key.');
        }
        return new SecretBox($stored);
    }
}
