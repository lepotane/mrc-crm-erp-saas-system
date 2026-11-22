<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tsoft ↔ MRC fark kaydı.
 *
 * `mrc_snapshot` / `external_snapshot` yalnızca kanonik, kişisel veri içermeyen
 * alanları taşır (ürün adı/SKU, fiyat, stok, sipariş referansı).
 */
class SyncDiff extends Model
{
    public const TYPE_PRODUCT = 'product';

    public const TYPE_PRICE = 'price';

    public const TYPE_STOCK = 'stock';

    public const TYPE_ORDER = 'order';

    public const TYPES = [self::TYPE_PRODUCT, self::TYPE_PRICE, self::TYPE_STOCK, self::TYPE_ORDER];

    public const DIFF_NEW_REMOTE = 'new_remote';

    public const DIFF_NEW_LOCAL = 'new_local';

    public const DIFF_CHANGED_REMOTE = 'changed_remote';

    public const DIFF_CHANGED_LOCAL = 'changed_local';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_IGNORED = 'ignored';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_IMPORTED, self::STATUS_IGNORED];

    protected $table = 'sync_diffs';

    protected $fillable = [
        'company_id', 'entity_type', 'external_key', 'diff_type',
        'mrc_snapshot', 'external_snapshot', 'summary', 'status',
        'detected_at', 'resolved_at',
    ];

    protected $casts = [
        'mrc_snapshot' => 'array',
        'external_snapshot' => 'array',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @param Builder<self> $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** @param Builder<self> $query */
    public function scopeOfType(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    /** @param Builder<self> $query */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function markImported(?string $summary = null): void
    {
        $attributes = ['status' => self::STATUS_IMPORTED, 'resolved_at' => now()];
        if ($summary !== null && $summary !== '') {
            $attributes['summary'] = mb_substr($summary, 0, 1000);
        }

        $this->forceFill($attributes)->save();
    }

    public function markIgnored(?string $summary = null): void
    {
        $attributes = ['status' => self::STATUS_IGNORED, 'resolved_at' => now()];
        if ($summary !== null && $summary !== '') {
            $attributes['summary'] = mb_substr($summary, 0, 1000);
        }

        $this->forceFill($attributes)->save();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
