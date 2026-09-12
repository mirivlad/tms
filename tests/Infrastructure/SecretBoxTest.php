<?php

declare(strict_types=1);

namespace Tms\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tms\Infrastructure\SecretBox;

final class SecretBoxTest extends TestCase
{
    public function testRoundTripAndRandomNonce(): void
    {
        $box = new SecretBox(base64_encode(str_repeat('k', 32)));
        $first = $box->encrypt('smtp-secret');
        $second = $box->encrypt('smtp-secret');
        self::assertNotSame($first, $second);
        self::assertSame('smtp-secret', $box->decrypt($first));
        self::assertSame('smtp-secret', $box->decrypt($second));
    }

    public function testRejectsInvalidKey(): void
    {
        $this->expectException(RuntimeException::class);
        new SecretBox('not-a-valid-key');
    }
}
