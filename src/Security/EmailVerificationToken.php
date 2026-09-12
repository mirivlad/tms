<?php

declare(strict_types=1);

namespace Tms\Security;

final readonly class EmailVerificationToken
{
    private const SELECTOR_BYTES = 12;
    private const VERIFIER_BYTES = 32;

    private function __construct(public string $selector, private string $verifier)
    {
    }

    public static function issue(): self
    {
        return new self(bin2hex(random_bytes(self::SELECTOR_BYTES)), bin2hex(random_bytes(self::VERIFIER_BYTES)));
    }

    public static function fromValue(string $value): ?self
    {
        if (!preg_match('/^([0-9a-f]{24})\.([0-9a-f]{64})$/D', $value, $matches)) {
            return null;
        }
        return new self($matches[1], $matches[2]);
    }

    public function value(): string
    {
        return $this->selector . '.' . $this->verifier;
    }

    public function verifierHash(): string
    {
        return hash('sha256', $this->verifier);
    }

    public function matchesHash(string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->verifierHash());
    }
}
