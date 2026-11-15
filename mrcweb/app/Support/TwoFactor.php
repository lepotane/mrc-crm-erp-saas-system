<?php

namespace App\Support;

/**
 * TOTP iki faktörlü giriş — paketsiz, RFC 6238 .
 *
 * Neden paket yok: TOTP ~40 satırlık belirlenmiş matematiktir (HMAC-SHA1 + zaman
 * adımı); dış paketin sürükleyeceği bağımlılık ağacı (otphp/*, bacon/*) bu tek
 * ihtiyaç için ağır kalır. Doğruluk testlerle kilitli: RFC 6238'in bilinen
 * vektörleri değil, ÜRETİP-DOĞRULAMA turu + pencere sınırları test edilir
 * (saat kayması ±1 adım hoş görülür, ±2 reddedilir).
 *
 * Güvenlik notları:
 *  - Sır 160 bit (20 bayt) üretilir; base32'de taşınır (otpauth standardı).
 *  - Kod 6 haneli, adım 30 sn. Yeniden oynatma (aynı kodun 30 sn içinde ikinci
 *    kez kullanılması) ayrı sayaçla engellenmez — çünkü kodun geçtiği her kapı
 *    (giriş sınaması, kurulum onayı) TEK KULLANIMLIKTIR: sınama anahtarı başarıda
 *    silinir, kurulum onayı sır durumunu „onaylandı"ya çevirir.
 *  - Karşılaştırma `hash_equals` ile (zamanlama saldırısına karşı).
 */
class TwoFactor
{
    public const STEP_SECONDS = 30;

    public const DIGITS = 6;

    public const WINDOW_STEPS = 1;

    public const RECOVERY_CODE_COUNT = 8;

    /** 160 bit sır üretir. */
    public static function generateSecret(): string
    {
        return static::base32Encode(random_bytes(20));
    }

    /** Telefon uygulamasına okutulacak adres (QR bu metinden üretilir). */
    public static function otpauthUrl(string $email, string $secret, string $issuer = 'MRC'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($email)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer)
            .'&digits='.static::DIGITS.'&period='.static::STEP_SECONDS;
    }

    /** Yedek kodlar: kullanıcıya bir kez gösterilir, bizde yalnız ÖZETİ durur. */
    public static function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < static::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = static::recoveryChunk().'-'.static::recoveryChunk();
        }

        return $codes;
    }

    /** 4 hex karakterlik yedek kod parçası (okunabilirlik için büyük harf). */
    protected static function recoveryChunk(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }

    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtoupper(str_replace([' ', '-'], '', $code)));
    }

    /** Kod geçerli mi (şu an ±1 adım)? */
    public static function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D+/', '', $code);

        if (strlen($code) !== static::DIGITS) {
            return false;
        }

        $step = (int) floor(time() / static::STEP_SECONDS);

        for ($offset = -static::WINDOW_STEPS; $offset <= static::WINDOW_STEPS; $offset++) {
            if (hash_equals(static::generateCode($secret, $step + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /** Belirli zaman adımının kodu (testler ve imza için açık). */
    public static function generateCode(string $secret, ?int $step = null): string
    {
        $step ??= (int) floor(time() / static::STEP_SECONDS);
        $key = static::base32Decode($secret);

        // Sayaç: 8 bayt, büyük uçlu.
        $counter = pack('N*', 0, $step);
        $hash = hash_hmac('sha1', $counter, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** static::DIGITS)), static::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';

        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($binary, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    public static function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper(rtrim($data, '='));
        $binary = '';

        foreach (str_split($data) as $char) {
            $position = strpos($alphabet, $char);

            if ($position === false) {
                throw new \InvalidArgumentException('Geçersiz base32 karakteri.');
            }

            $binary .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}
