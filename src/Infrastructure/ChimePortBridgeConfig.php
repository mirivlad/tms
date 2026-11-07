<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

use RuntimeException;

/**
 * Reads an optional ChimePort trusted-app configuration without copying its
 * long-lived application secret into Docker container environment metadata.
 *
 * The file path is supplied by the operator, and must be a private, regular,
 * non-world-accessible file. No secret value is ever logged or returned to
 * the browser. The old environment-based configuration remains compatible.
 */
final class ChimePortBridgeConfig
{
    /**
     * @param callable(string):string $env
     * @return array{url:string,token:string,enabled:bool}
     */
    public static function resolve(callable $env): array
    {
        $url = self::read($env, 'CHIMEPORT_BRIDGE_URL', 'CHIMEPORT_BRIDGE_URL_FILE');
        $token = self::read($env, 'CHIMEPORT_BRIDGE_TOKEN', 'CHIMEPORT_BRIDGE_TOKEN_FILE');

        if ($url !== '' && !str_starts_with($url, 'https://')) {
            throw new RuntimeException('ChimePort trusted-app URL must be HTTPS.');
        }
        if ($token !== '' && !str_starts_with($token, 'cp_a_')) {
            throw new RuntimeException('Invalid trusted-app token format.');
        }
        if (($url === '') !== ($token === '')) {
            throw new RuntimeException('Both ChimePort bridge URL and token must be configured.');
        }
        return ['url' => $url, 'token' => $token, 'enabled' => $url !== ''];
    }

    /** @param callable(string):string $env */
    private static function read(callable $env, string $valueEnv, string $pathEnv): string
    {
        $value = trim($env($valueEnv));
        if ($value !== '') {
            return $value;
        }
        $file = trim($env($pathEnv));
        if ($file === '' || !file_exists($file)) {
            return '';
        }
        if (!is_file($file) || is_link($file) || filesize($file) === false ||
            filesize($file) > 4096 || filesize($file) < 1) {
            throw new RuntimeException('Invalid ChimePort bridge configuration file.');
        }
        $perms = fileperms($file);
        if ($perms === false || ($perms & 0x7) !== 0) {
            throw new RuntimeException('ChimePort bridge configuration file must not be world-readable.');
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Cannot read ChimePort bridge configuration file.');
        }
        return trim($raw);
    }
}
