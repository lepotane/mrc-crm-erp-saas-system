<?php

namespace App\Support;

use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

/**
 * Oturum cihaz kaydı .
 *
 * Token üretilen HER kapıda (giriş, kayıt, sınama-sonrası oturum, API anahtarı)
 * IP + tarayıcı damgası vurulur; liste („bu hesap nerelerde açık") buradan okunur.
 * Damga sonradan DEĞİŞTİRİLMEZ — IP değişimi (mobil ağ) yeni satır değil, yeni
 * oturum demektir; eski satırın damgası tarihtir.
 */
class SessionDevice
{
    public static function stamp(NewAccessToken $created, Request $request): void
    {
        $created->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
        ])->save();
    }

    /**
     * Kullanıcı ajanından kısa cihaz etiketi („Chrome · Windows").
     * Tanınmayan ajan uydurulmaz: „Bilinmeyen cihaz" döner.
     */
    public static function label(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return __('Bilinmeyen cihaz');
        }

        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') && ! str_contains($userAgent, 'Chrome') => 'Safari',
            str_contains($userAgent, 'Symfony') => 'Test istemcisi',
            default => null,
        };

        if ($os === null && $browser === null) {
            return __('Bilinmeyen cihaz');
        }

        return trim(($browser ?? __('Tarayıcı')).' · '.($os ?? __('Bilinmeyen sistem')));
    }
}
