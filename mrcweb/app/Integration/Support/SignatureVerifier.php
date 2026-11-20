<?php

namespace App\Integration\Support;

/**
 * Webhook imza doğrulayıcı.
 *
 * Tsoftadmin (2026-01) imzaları: X-Tsoft-Signature: t=<unix>,v1=base64(HMAC-SHA256(secret, "<t>.<rawBody>"))
 * Eski rest1 hub imzaları: X-Hub-Signature: sha1=<HMAC-SHA1(secret, rawBody)>
 *
 * Ham gövde (raw body) kullanılır: JSON yeniden serileştirmek imzayı bozar.
 */
final class SignatureVerifier
{
    /** Tsoft admin webhook imzası (5 dakikalık varsayılan tolerans). */
    public static function verifyTsoft(
        string $rawBody,
        ?string $header,
        string $secret,
        int $toleranceSeconds = 300,
    ): bool {
        if ($secret === '' || $header === null || $header === '') {
            return false;
        }

        if (! preg_match('/t=(\d+),v1=([^,]+)/', $header, $matches)) {
            return false;
        }

        $timestamp = (int) $matches[1];
        $signature = $matches[2];

        // Replay saldırısına karşı zaman penceresi (client/server saat sapmasına izin verir).
        if (abs(time() - $timestamp) > max(0, $toleranceSeconds)) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret, true));

        return hash_equals($expected, $signature);
    }

    /** Eski rest1 "hub" webhook imzası (sha1=... biçimi). */
    public static function verifyHub(string $rawBody, ?string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        if (! preg_match('/^sha1=([a-f0-9]+)$/i', trim($signature), $matches)) {
            return false;
        }

        $expected = hash_hmac('sha1', $rawBody, $secret);

        return hash_equals($expected, $matches[1]);
    }
}
