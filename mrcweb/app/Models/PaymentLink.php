<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Ödeme linki : sağlayıcıda açılan ödeme sayfasının bizdeki karşılığı.
 *
 * Yaşam döngüsü: pending → (paid | failed | cancelled).
 *  - `pending`: link üretildi, ödeme bekleniyor.
 *  - `paid`   : sağlayıcı bildirimi imzası doğrulanarak alındı; `payment_id` üzerinde
 *               Payments satırı doğdu (tek doğruluk kaydı link satırıdır — mükerrer
 *               bildirim ikinci tahsilatı üretmez).
 *  - `failed` : sağlayıcı ödemeyi reddetti (kart/banka); sebep `failure_reason`'da.
 *  - `cancelled`: satıcı linki kapattı; böyle bir linkin gecikmiş bildirimi kayda
 *               alınmaz, yalnız loglanır (para iadesi sağlayıcı panelinden yürür).
 *
 * `expires_at` bilgidir, kesin kapı değildir: süre geçmiş bir linkte ödeme TAMAMLANMIŞSA
 * para gerçektir ve tahsilat kaydedilir (not düşülür). Gerçek kapı satıcının `cancelled`
 * kararıdır.
 */
class PaymentLink extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Bekliyor',
        self::STATUS_PAID => 'Ödendi',
        self::STATUS_FAILED => 'Ödeme alınamadı',
        self::STATUS_CANCELLED => 'İptal edildi',
    ];

    /** Linkin varsayılan geçerlilik süresi (gün). */
    public const DEFAULT_VALID_DAYS = 7;

    protected $fillable = [
        'company_id', 'token', 'token_hash', 'provider', 'party_id', 'document_id',
        'amount', 'currency_code', 'status', 'provider_token', 'provider_token_hash',
        'pay_url', 'reference', 'failure_reason', 'payment_id', 'paid_at', 'expires_at',
        'last_notification_at', 'notification_count', 'note', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        // Bearer token ve sağlayıcı jetonu APP_KEY ile şifrelenir: veritabanı
        // sızsa bile ödeme sayfasına girilemez. Arama token_hash üzerinden.
        'token' => 'encrypted',
        'provider_token' => 'encrypted',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_notification_at' => 'datetime',
        'notification_count' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Arama özetini (token_hash / provider_token_hash) otomatik tutar.
     *
     * Neden: token şifreli saklandığı için sorgu özet üzerinden yapılır. Özet
     * elle yazılmazsa link "bulunamaz" olurdu; bu kural her yazma yolunu
     * (controller, test, konsol) güvenli kılar.
     */
    protected static function booted(): void
    {
        static::saving(function (self $link): void {
            if (! empty($link->token) && empty($link->token_hash)) {
                $link->token_hash = static::hashToken((string) $link->token);
            }

            if (! empty($link->provider_token) && empty($link->provider_token_hash)) {
                $link->provider_token_hash = static::hashProviderToken((string) $link->provider_token);
            }

            // Referans bearer token İÇERMEMELİ: ödeme referansı "token:status:type"
            // biçiminde kuruluyordu ve `payments.reference`/`payment_links.reference`
            // kolonlarında düz metin kalıyordu — veritabanı sızıntısında ödeme
            // sayfası açılabiliyordu. Referans artık link kimliğidir.
            $referans = (string) ($link->reference ?? '');
            if ($referans !== '' && ! empty($link->token) && str_starts_with($referans, (string) $link->token)) {
                $link->reference = 'link-'.$link->id.substr($referans, strlen((string) $link->token));
            }
        });
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Benzersiz işlem anahtarı. PayTR tarafında `merchant_oid`, iyzico tarafında
     * `basketId` olarak aynı değer gider; böylece her bildirim tek satıra bağlanır.
     * (PayTR: en fazla 64, alfanumerik → 32 onaltılık karakter.)
     *
     * Token veritabanında ŞİFRELENİR; teklik kontrolü `token_hash` üzerinden
     * yapılır (şifreli kolon üzerinde sorgu yapılamaz).
     */
    public static function generateToken(): string
    {
        do {
            $token = strtolower(bin2hex(random_bytes(16)));
        } while (static::where('token_hash', static::hashToken($token))->exists());

        return $token;
    }

    /** Token'ın arama/lookup özeti. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Sağlayıcı jetonunun (iyzico token'ı) arama özeti. */
    public static function hashProviderToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function statusLabel(string $status): string
    {
        return __(self::STATUS_LABELS[$status] ?? $status);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** PayTR para birimi kodu: bizde TRY, PayTR'da TL (dokümanın kendi eşleşmesi). */
    public function paytrCurrency(): string
    {
        return $this->currency_code === 'TRY' ? 'TL' : $this->currency_code;
    }

    /**
     * Tahsil edilecek tutarın PayTR biçimi: kuruş/cent cinsinden TAM SAYI (34.56 → 3456).
     * Ondalık kaydırmak float ile değil string üzerinden yapılır — para hesaplarında
     * 34.56 * 100 = 3455.9999... tuzağına düşülmez.
     */
    public function paytrAmount(): int
    {
        return (int) round((float) $this->amount * 100);
    }

    /** Sepet başlığı: ödeme sayfasında müşteri ne gördüğünü bilsin. */
    public function basketLabel(): string
    {
        $serial = $this->document?->serial;
        $party = $this->party?->name ?: __('Müşteri');

        $label = $serial
            ? sprintf('%s · %s', $serial, $party)
            : sprintf('%s · %s', $party, __('Ödeme'));

        return mb_substr($label, 0, 180);
    }

    public function toRow(): array
    {
        return [
            'id' => (int) $this->id,
            'token' => $this->token,
            'provider' => $this->provider,
            'provider_label' => PaymentCredential::label($this->provider),
            'party_id' => (int) $this->party_id,
            'party_name' => $this->party?->name,
            'document_id' => $this->document_id === null ? null : (int) $this->document_id,
            'document_serial' => $this->document?->serial,
            'amount' => (float) $this->amount,
            'currency_code' => $this->currency_code,
            'status' => $this->status,
            'status_label' => self::statusLabel($this->status),
            'pay_url' => $this->pay_url,
            'reference' => $this->reference,
            'failure_reason' => $this->failure_reason,
            'payment_id' => $this->payment_id === null ? null : (int) $this->payment_id,
            'is_expired' => $this->isExpired(),
            'note' => $this->note,
            'paid_at' => $this->paid_at?->format('Y-m-d H:i'),
            'expires_at' => $this->expires_at?->format('Y-m-d H:i'),
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
            'created_by_name' => $this->creator?->name,
        ];
    }
}
