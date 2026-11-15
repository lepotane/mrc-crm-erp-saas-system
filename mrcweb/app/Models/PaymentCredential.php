<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Firmanın ödeme sağlayıcı hesabı (PayTR mağaza no/anahtarı, iyzico api/secret key).
 *
 * . İki kapı birlikte açıldı: Türkiye'de sanal POS pazarında firmaların
 * bir kısmı PayTR, bir kısmı iyzico kullanır; "tek sağlayıcı seç" demek, diğer yarıyı
 * üründen dışlamak olurdu. Bu yüzden sağlayıcı firma tercihidir ve her firmanın kendi
 * hesabı satır olarak burada durur.
 *
 * Güvenlik sözleşmesi:
 *  - `credentials` şifreli tutulur (`encrypted:array`); veritabanı sızıntısında okunamaz.
 *  - Bu model asla düz anahtar BASMAZ; arayüz yalnız `masked()` görür.
 *  - `is_sandbox` gerçek para hareketi ile deneme hareketini ayıran tek anahtardır:
 *    sandbox hesabıyla canlı para toplanmaz, sandbox bildirimi de gerçek tahsilat sayılmaz.
 */
class PaymentCredential extends Model
{
    public const PROVIDER_PAYTR = 'paytr';

    public const PROVIDER_IYZICO = 'iyzico';

    public const PROVIDERS = [self::PROVIDER_PAYTR, self::PROVIDER_IYZICO];

    /** Sağlayıcının kasayı açan alanları (yanıtta asla düz dönmez). */
    public const SECRET_FIELDS = ['merchant_key', 'merchant_salt', 'secret_key', 'api_key'];

    /** Her sağlayıcının zorunlu alan seti — eksik alanla link üretilmez. */
    public const REQUIRED_FIELDS = [
        self::PROVIDER_PAYTR => ['merchant_id', 'merchant_key', 'merchant_salt'],
        self::PROVIDER_IYZICO => ['api_key', 'secret_key'],
    ];

    protected $fillable = [
        'company_id', 'provider', 'credentials',
        'is_sandbox', 'is_active', 'bank_account_id', 'created_by',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'is_sandbox' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public static function label(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_PAYTR => 'PayTR',
            self::PROVIDER_IYZICO => 'iyzico',
            default => $provider,
        };
    }

    public function field(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /** Eksik zorunlu alanların adlarını döner (mesaj kurmak için, değerleri değil). */
    public function missingFields(): array
    {
        $missing = [];

        foreach (self::REQUIRED_FIELDS[$this->provider] ?? [] as $key) {
            if ($this->field($key) === null) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->missingFields() === [];
    }

    /**
     * Arayüze gösterilecek hâli: sırlar son 4 karakterle maskelenir.
     * Tam değer bir daha asla okunmaz — API anahtarlarındaki (PAT) davranışla aynı.
     */
    public function masked(): array
    {
        $out = [];

        foreach ((array) $this->credentials as $key => $value) {
            $value = (string) $value;

            if ($value === '') {
                $out[$key] = null;
                continue;
            }

            $out[$key] = in_array($key, self::SECRET_FIELDS, true)
                ? str_repeat('•', 4).mb_substr($value, -4)
                : $value;
        }

        return $out;
    }
}
