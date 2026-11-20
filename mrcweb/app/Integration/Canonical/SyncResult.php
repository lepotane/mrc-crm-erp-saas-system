<?php

namespace App\Integration\Canonical;

/**
 * Her dış yazımın sonucu. `externalRef` idempotency eşlemesi için kullanılır.
 */
final class SyncResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $externalRef = null,
        public readonly ?string $message = null,
        public readonly bool $skipped = false,
    ) {}

    public static function success(?string $externalRef = null, ?string $message = null): self
    {
        return new self(true, $externalRef, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, null, $message);
    }

    public static function skipped(string $reason): self
    {
        return new self(true, null, $reason, true);
    }
}
