<?php

namespace App\Integration\Orders;

/**
 * Kanonik para tutarları için bcmath yardımcıları.
 *
 * Tutarlar float'a çevrilmeden, 4 ondalıklı string olarak taşınır ve saklanır.
 */
final class Money
{
    public const SCALE = 4;

    /** Herhangi bir sayısal değeri 4 ondalıklı string decimal'e normalize eder. */
    public static function dec(mixed $value): string
    {
        if (! is_scalar($value) || ! is_numeric((string) $value)) {
            return '0.0000';
        }

        return bcadd((string) $value, '0', self::SCALE);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd(self::dec($a), self::dec($b), self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::dec($a), self::dec($b), self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::dec($a), $b, self::SCALE);
    }

    /** Yüzde: taban * (oran / 100). */
    public static function percent(string $base, string $rate): string
    {
        $factor = bcdiv(self::dec($rate), '100', 8);

        return bcmul(self::dec($base), $factor, self::SCALE);
    }
}
