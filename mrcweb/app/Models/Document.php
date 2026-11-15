<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Document extends Model
{
    use SoftDeletes;

    public const TYPE_QUOTE = 'quote';
    public const TYPE_SALES_ORDER = 'sales_order';
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_RETURN = 'return';

    public const TYPES = [
        self::TYPE_QUOTE,
        self::TYPE_SALES_ORDER,
        self::TYPE_INVOICE,
        self::TYPE_PURCHASE,
        self::TYPE_RETURN,
    ];

    public const PREFIXES = [
        self::TYPE_QUOTE => 'TEK',
        self::TYPE_SALES_ORDER => 'SIP',
        self::TYPE_INVOICE => 'FTR',
        self::TYPE_PURCHASE => 'ALM',
        self::TYPE_RETURN => 'IAD',
    ];

    /**
     * Durum anahtarları → arayüzdeki okunur ad. Hata mesajlarında ham anahtar
     * (`draft`, `issued`) gösterilmez: kullanıcı kendi dilinde ne gördüyse onu okur
     * (kiracı/hata sözleşmesi: mesaj çevrilmiş ve kullanıcı-dostu olmalı).
     */
    public const STATUS_LABELS = [
        'draft' => 'Taslak',
        'sent' => 'Gönderildi',
        'accepted' => 'Kabul edildi',
        'rejected' => 'Reddedildi',
        'confirmed' => 'Onaylandı',
        'issued' => 'İhraç',
        'paid' => 'Ödendi',
        'partially_paid' => 'Kısmen ödendi',
        'cancelled' => 'İptal',
    ];

    public static function statusLabel(string $status): string
    {
        return __(self::STATUS_LABELS[$status] ?? $status);
    }

    /**
     * Belge numarası öneki. Fatura için firma ayarı (`invoice_series_prefix`)
     * esas alınır; ayar yoksa/geçersizse türün sabit öneki kullanılır. Diğer
     * türlerde önek sabittir.
     */
    public static function prefixFor(int $companyId, string $type): string
    {
        $default = self::PREFIXES[$type] ?? 'BLG';

        if ($type !== self::TYPE_INVOICE) {
            return $default;
        }

        $ayar = Company::find($companyId)?->setting('invoice_series_prefix');
        $onek = is_string($ayar) ? mb_strtoupper(trim($ayar), 'UTF-8') : '';

        return preg_match('/^[A-Z0-9]{1,6}$/', $onek) ? $onek : $default;
    }

    /** Durum makinesi: tip → mevcut durum → izinli hedefler. */
    public const TRANSITIONS = [
        self::TYPE_QUOTE => [
            'draft' => ['sent', 'cancelled'],
            'sent' => ['accepted', 'rejected', 'cancelled'],
            'accepted' => [],
            'rejected' => [],
            'cancelled' => [],
        ],
        self::TYPE_SALES_ORDER => [
            'draft' => ['confirmed', 'cancelled'],
            'confirmed' => [],
            'cancelled' => [],
        ],
        self::TYPE_INVOICE => [
            'draft' => ['issued', 'cancelled'],
            // Karar 44: kesilmiş belge iptal edilebilir (iptal stok etkisini
            // tersine çevirir); ödemeli iptal denetleyicide reddedilir.
            'issued' => ['paid', 'partially_paid', 'cancelled'],
            'paid' => [],
            'partially_paid' => ['paid'],
            'cancelled' => [],
        ],
        self::TYPE_PURCHASE => [
            'draft' => ['issued', 'cancelled'],
            'issued' => ['paid', 'partially_paid', 'cancelled'],
            'paid' => [],
            'partially_paid' => ['paid'],
            'cancelled' => [],
        ],
        self::TYPE_RETURN => [
            'draft' => ['issued', 'cancelled'],
            'issued' => ['paid', 'partially_paid', 'cancelled'],
            'paid' => [],
            'partially_paid' => ['paid'],
            'cancelled' => [],
        ],
    ];

    protected $fillable = [
        'company_id', 'type', 'serial', 'party_id', 'warehouse_id', 'status',
        'issue_date', 'due_date', 'currency_code', 'exchange_rate', 'rate_date',
        'subtotal', 'discount_total', 'tax_total', 'grand_total',
        'notes', 'created_by', 'converted_from_id', 'source_document_id',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'rate_date' => 'date',
        'subtotal' => 'decimal:4',
        'discount_total' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'grand_total' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'converted_from_id');
    }

    /** İadenin dayandığı satış/alış faturası . */
    public function returnSource(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    /** Bu belgeye dayanılarak açılmış iadeler. */
    public function returns(): HasMany
    {
        return $this->hasMany(Document::class, 'source_document_id');
    }

    public function convertedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'converted_from_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->type][$this->status] ?? [], true);
    }

    /**
     * Firma+tip+yıl bazlı seri: transaction + lockForUpdate ile atomik.
     * Format: {ÖNEK}-{YY}-{00001} (5 hane sıfır dolgulu).
     */
    public static function nextSerial(int $companyId, string $type, ?int $year = null): string
    {
        $year ??= (int) date('Y');

        return DB::transaction(function () use ($companyId, $type, $year) {
            $series = DocumentSeries::where('company_id', $companyId)
                ->where('type', $type)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $series) {
                $series = DocumentSeries::create([
                    'company_id' => $companyId,
                    'type' => $type,
                    'year' => $year,
                    'next_number' => 2,
                ]);
                $number = 1;
            } else {
                $number = (int) $series->next_number;
                $series->increment('next_number');
            }

            $prefix = self::prefixFor($companyId, $type);

            return sprintf('%s-%02d-%05d', $prefix, $year % 100, $number);
        });
    }
}
