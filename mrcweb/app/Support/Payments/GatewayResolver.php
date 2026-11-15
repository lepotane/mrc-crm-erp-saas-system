<?php

namespace App\Support\Payments;

use App\Models\Company;
use App\Models\PaymentCredential;
use App\Models\PaymentLink;
use Illuminate\Validation\ValidationException;

/**
 * Sağlayıcı çözümleyici : firmanın hesap satırını bulur ve ona
 * uygun istemciyi kurar. Tek yerde durmalı: "hangi firma hangi hesapla tahsilat yapıyor"
 * sorusunun cevabı iki yerde (link ucu + bildirim ucu) ayrı ayrı okunursa sapma kaçınılmaz.
 */
class GatewayResolver
{
    /** Sır/alan adlarının kullanıcıya okunur hâli (çeviri anahtarı olarak da kullanılır). */
    public const FIELD_LABELS = [
        'merchant_id' => 'Mağaza numarası',
        'merchant_key' => 'Mağaza anahtarı',
        'merchant_salt' => 'Güvenlik parolası (salt)',
        'api_key' => 'API anahtarı',
        'secret_key' => 'Gizli anahtar',
    ];

    public static function credentialFor(Company $company, string $provider): ?PaymentCredential
    {
        if (! in_array($provider, PaymentCredential::PROVIDERS, true)) {
            return null;
        }

        return PaymentCredential::where('company_id', $company->id)
            ->where('provider', $provider)
            ->first();
    }

    /**
     * Link ÜRETİRKEN kullanılacak hesap: eksiksiz ve etkin olmak zorunda.
     * Hata 422'dir (kayıt yaratılmaz), mesaj çevrilir ve neyin eksik olduğunu söyler —
     * "ödeme açılamadı" tek başına kullanıcıya hangi alanı dolduracağını söylemez.
     */
    public static function usableCredential(Company $company, string $provider): PaymentCredential
    {
        if (! in_array($provider, PaymentCredential::PROVIDERS, true)) {
            throw ValidationException::withMessages([
                'provider' => [__('Desteklenmeyen ödeme sağlayıcısı: :provider', ['provider' => $provider])],
            ]);
        }

        $credential = static::credentialFor($company, $provider);

        if ($credential === null) {
            throw ValidationException::withMessages([
                'provider' => [__(
                    ':provider ödeme hesabı tanımlı değil. Ayarlar → Ödeme Ayarları bölümünden hesap bilgilerinizi girin.',
                    ['provider' => PaymentCredential::label($provider)]
                )],
            ]);
        }

        $missing = $credential->missingFields();

        if ($missing !== []) {
            $labels = array_map(
                static fn (string $key): string => __(self::FIELD_LABELS[$key] ?? $key),
                $missing
            );

            throw ValidationException::withMessages([
                'credentials' => [__(
                    ':provider ödeme hesabı eksik: :fields. Ayarlar → Ödeme Ayarları bölümünü tamamlayın.',
                    ['provider' => PaymentCredential::label($provider), 'fields' => implode(', ', $labels)]
                )],
            ]);
        }

        if (! $credential->is_active) {
            throw ValidationException::withMessages([
                'provider' => [__(
                    ':provider ödeme hesabı etkin değil. Ayarlar → Ödeme Ayarları bölümünden etkinleştirin.',
                    ['provider' => PaymentCredential::label($provider)]
                )],
            ]);
        }

        return $credential;
    }

    /**
     * BİLDİRİM tarafı: imzayı doğrulamak için hesap satırı gerekir. Etkinlik/eksiklik
     * aranmaz — kapanmış hesapta bile bekleyen bir ödeme gecikerek dönebilir ve para
     * gerçektir.
     */
    public static function credentialForLink(PaymentLink $link): ?PaymentCredential
    {
        return PaymentCredential::where('company_id', $link->company_id)
            ->where('provider', $link->provider)
            ->first();
    }

    public static function gateway(PaymentCredential $credential): PaymentGateway
    {
        return match ($credential->provider) {
            PaymentCredential::PROVIDER_PAYTR => new PaytrGateway($credential),
            PaymentCredential::PROVIDER_IYZICO => new IyzicoGateway($credential),
        };
    }

    public static function gatewayForLink(PaymentLink $link): ?PaymentGateway
    {
        $credential = static::credentialForLink($link);

        return $credential === null ? null : static::gateway($credential);
    }
}
