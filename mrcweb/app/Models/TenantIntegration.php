<?php

namespace App\Models;

use App\Integration\Enums\Channel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Firma başına kanal bağlantısı (Tsoft / GİB / Luca).
 *
 * `credentials` ve `webhook_secret` şifreli kolonlardır (encrypted cast).
 * Model düz sırrı ASLA döndürmez; arayüz yalnız `masked()` görür.
 */
class TenantIntegration extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'tenant_integrations';

    protected $fillable = [
        'company_id', 'channel', 'driver', 'domain', 'store', 'credentials',
        'webhook_secret', 'scopes', 'settings', 'status', 'last_success_at', 'last_error',
    ];

    // Laravel 12'de `encrypted:string` cast'i yoktur; `encrypted` ile aynı sonuç
    // (string şifreleme) model cast'i üzerinden alınır.
    protected $casts = [
        'credentials' => 'encrypted:array',
        'webhook_secret' => 'encrypted',
        'scopes' => 'array',
        'settings' => 'array',
        'last_success_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Arayüz için güvenli görünüm: tüm credential değerleri ve webhook sırrı maskelenir.
     * Tam değer hiçbir koşulda dönmez.
     */
    public function masked(): array
    {
        $credentials = [];

        foreach ((array) $this->credentials as $key => $value) {
            $credentials[$key] = $this->maskValue($value);
        }

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'channel' => $this->channel,
            'driver' => $this->driver,
            'status' => $this->status,
            'credentials' => $credentials,
            'webhook_secret' => $this->webhook_secret === null
                ? null
                : $this->maskValue($this->webhook_secret),
            'scopes' => (array) $this->scopes,
            'settings' => (array) $this->settings,
            'last_success_at' => $this->last_success_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }

    /** Son 4 karakter dışındaki her şey gizlenir; 4 karakterden kısa değer tamamen gizlenir. */
    private function maskValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        if (mb_strlen($value) <= 4) {
            return str_repeat('•', 4);
        }

        return str_repeat('•', 4).mb_substr($value, -4);
    }

    /** Aktif ve kimlik bilgisi dolu bağlantı kullanılabilir sayılır. */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! empty($this->credentials);
    }

    public static function for(Company|int $company, Channel $channel): ?self
    {
        $companyId = $company instanceof Company ? $company->id : $company;

        return static::query()
            ->where('company_id', $companyId)
            ->where('channel', $channel->value)
            ->first();
    }
}
