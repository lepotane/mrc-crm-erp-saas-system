<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sayım oturumu : `draft` açılır, satırlar sayılanla dolar, `approved`
 * olunca farklar `adjust` hareketi olur. Onay YALNIZ Admin (yetkili rol kuralı).
 */
class StockCount extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'warehouse_id', 'status', 'note', 'user_id',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    /** Satır farkları (sayılan − beklenen), sıfır olanlar elenir. */
    public function diffs(): array
    {
        $out = [];

        foreach ($this->lines as $line) {
            $diff = round((float) $line->counted_qty - (float) $line->expected_qty, 4);

            if ($diff != 0.0) {
                $out[] = ['line' => $line, 'diff' => $diff];
            }
        }

        return $out;
    }
}
