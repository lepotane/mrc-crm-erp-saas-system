<?php

namespace App\Integration\Gib;

use App\Integration\Contracts\VoucherBuilderInterface;

/**
 * Minimal static registry of voucher builders.
 *
 * No container magic: a service provider (or a test) registers builders and
 * ResmilesTirService resolves them by document type. '*' is the wildcard.
 */
final class VoucherBuilderRegistry
{
    /** @var array<string, VoucherBuilderInterface> */
    private static array $builders = [];

    public static function register(string $documentType, VoucherBuilderInterface $builder): void
    {
        self::$builders[$documentType] = $builder;
    }

    public static function resolve(string $documentType): ?VoucherBuilderInterface
    {
        return self::$builders[$documentType] ?? null;
    }

    public static function clear(): void
    {
        self::$builders = [];
    }
}
