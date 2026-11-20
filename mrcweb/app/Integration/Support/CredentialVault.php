<?php

namespace App\Integration\Support;

use App\Integration\Enums\Channel;
use App\Integration\Enums\Driver;
use App\Models\TenantIntegration;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Firma/kanal bazlı sırlara tek giriş noktası.
 *
 * Şifreleme model cast'lerinde yapılır; servis Crypt'e doğrudan dokunmaz ve
 * ham sırrı ne loglar ne de döndürür.
 */
class CredentialVault
{
    /** Tek bir kimlik bilgisini okur; çözümlenemezse null döner, fırlatmaz. */
    public function get(int $companyId, Channel $channel, string $key): ?string
    {
        $row = TenantIntegration::for($companyId, $channel);

        if ($row === null) {
            return null;
        }

        try {
            $value = $row->credentials[$key] ?? null;
        } catch (DecryptException $e) {
            // Bozuk şifreli veri servisi düşürmesin; hata kaydı tutulur.
            $row->last_error = 'credentials_decrypt_failed';
            $row->save();

            return null;
        }

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * Bağlantı kaydını upsert eder. Şifreleme model cast'leri üzerinden olur.
     * webhookSecret null ise mevcut sır korunur (yanlışlıkla silinmesin).
     */
    public function put(
        int $companyId,
        Channel $channel,
        array $credentials,
        ?string $webhookSecret = null,
        array $scopes = [],
        array $settings = [],
        ?Driver $driver = null,
        ?string $domain = null,
        ?string $store = null,
    ): TenantIntegration {
        $existing = TenantIntegration::for($companyId, $channel);

        $values = [
            'driver' => $driver?->value ?? $existing?->driver ?? $channel->value,
            'credentials' => $credentials,
            'scopes' => $scopes,
            'settings' => $settings,
        ];

        if ($domain !== null) {
            $values['domain'] = $domain;
        }
        if ($store !== null) {
            $values['store'] = $store;
        }

        if ($webhookSecret !== null) {
            $values['webhook_secret'] = $webhookSecret;
        }

        return TenantIntegration::updateOrCreate(
            ['company_id' => $companyId, 'channel' => $channel->value],
            $values
        );
    }

    /** Maskelenmiş görünüm; düz sır içermez. */
    public function masked(int $companyId, Channel $channel): array
    {
        return TenantIntegration::for($companyId, $channel)?->masked() ?? [];
    }
}
