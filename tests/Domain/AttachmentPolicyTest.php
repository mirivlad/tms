<?php

declare(strict_types=1);

namespace Tms\Tests\Domain;

use DomainException;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Attachment\AttachmentPolicy;

final class AttachmentPolicyTest extends TestCase
{
    public function testNormalizesClientPathAndControlCharacters(): void
    {
        $policy = new AttachmentPolicy();

        self::assertSame('report.pdf', $policy->normalizeOriginalName("C:\\fake\\path\\report.pdf\n"));
        self::assertSame('attachment', $policy->normalizeOriginalName("\0\n\r"));
    }

    public function testAcceptsMatchingAllowedType(): void
    {
        $policy = new AttachmentPolicy(1024);
        $policy->assertAllowed('notes.txt', 12, 'text/plain');
        self::assertTrue(true);
    }

    public function testRejectsExtensionMimeMismatch(): void
    {
        $this->expectException(DomainException::class);
        (new AttachmentPolicy())->assertAllowed('image.jpg', 12, 'text/plain');
    }

    public function testRejectsUnknownExtensionAndOversize(): void
    {
        $policy = new AttachmentPolicy(10);

        try {
            $policy->assertAllowed('payload.php', 5, 'text/plain');
            self::fail('Unknown extension should be rejected.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $this->expectException(DomainException::class);
        $policy->assertAllowed('notes.txt', 11, 'text/plain');
    }
}
