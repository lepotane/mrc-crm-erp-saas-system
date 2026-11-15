<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yazdırma işi : queued → claimed → printed (silinir) | queued (retry) | failed.
 * `claim_token` yalnız güncel talebi kabul eder (çift baskı kilidi).
 */
class PrintJob extends Model
{
    public const KIND_ESCPOS = 'escpos';

    public const KIND_PDF = 'pdf';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_FAILED = 'failed';

    /** Yeniden deneme üst sınırı; aşınca terminal `failed` olur. */
    public const MAX_ATTEMPTS = 5;

    /** Sahipsiz claim'in yeniden dağıtıma düşme süresi (sn). */
    public const CLAIM_TTL_SECONDS = 60;

    protected $fillable = [
        'company_id', 'device_id', 'printer_name', 'kind', 'payload', 'status',
        'attempts', 'claim_token', 'claimed_at', 'error', 'document_id',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
