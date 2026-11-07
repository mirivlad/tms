<?php

declare(strict_types=1);

namespace Tms\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tms\Infrastructure\ChimePortBridgeConfig;

final class ChimePortBridgeConfigTest extends TestCase
{
    public function testDisabledWhenNoFilesOrEnvironmentProvided(): void
    {
        $cfg = ChimePortBridgeConfig::resolve(static fn(string $key): string => '');
        self::assertSame(['url' => '', 'token' => '', 'enabled' => false], $cfg);
    }

    public function testReadsPrivateCredentialFiles(): void
    {
        $dir = sys_get_temp_dir() . '/tms-chime-bridge-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            file_put_contents($dir . '/issuer', "https://chime.example.org\n");
            file_put_contents($dir . '/token', "cp_a_test-private-value\n");
            chmod($dir . '/issuer', 0640);
            chmod($dir . '/token', 0640);
            $values = [
                'CHIMEPORT_BRIDGE_URL_FILE' => $dir . '/issuer',
                'CHIMEPORT_BRIDGE_TOKEN_FILE' => $dir . '/token',
            ];
            $cfg = ChimePortBridgeConfig::resolve(static fn(string $key): string => $values[$key] ?? '');
            self::assertTrue($cfg['enabled']);
            self::assertSame('https://chime.example.org', $cfg['url']);
            self::assertSame('cp_a_test-private-value', $cfg['token']);
            chmod($dir . '/token', 0644);
            clearstatcache(true, $dir . '/token');
            $this->expectException(RuntimeException::class);
            ChimePortBridgeConfig::resolve(static fn(string $key): string => $values[$key] ?? '');
        } finally {
            @unlink($dir . '/issuer');
            @unlink($dir . '/token');
            @rmdir($dir);
        }
    }

    public function testRejectsIncompleteConfiguration(): void
    {
        $this->expectException(RuntimeException::class);
        ChimePortBridgeConfig::resolve(static fn(string $key): string =>
            $key === 'CHIMEPORT_BRIDGE_TOKEN' ? 'cp_a_secret' : '');
    }
}
