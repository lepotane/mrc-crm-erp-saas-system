<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const TYPE_IN = 'in';
    public const TYPE_OUT = 'out';
    public const TYPE_ADJUST = 'adjust';

    protected $fillable = [
        'company_id', 'product_id', 'warehouse_id', 'type', 'quantity',
        'unit_cost', 'note', 'reference', 'user_id',
    ];

    protected $casts = ['quantity' => 'decimal:4', 'unit_cost' => 'decimal:4'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * İşaretli stok değişimi: in → +, out → −, adjust → sütundaki işaret.
     */
    public function signedQuantity(): float
    {
        return match ($this->type) {
            self::TYPE_IN => (float) $this->quantity,
            self::TYPE_OUT => -1 * (float) $this->quantity,
            default => (float) $this->quantity,
        };
    }

    /**
     * Belirtilen kapsamda anlık stok (movements'tan hesap; v1 cache'siz).
     */
    public static function stockFor(int $companyId, ?int $productId = null, ?int $warehouseId = null): float
    {
        $query = static::query()
            ->where('company_id', $companyId)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'in' THEN quantity WHEN type = 'out' THEN -quantity ELSE quantity END), 0) AS stock");

        if ($productId !== null) {
            $query->where('product_id', $productId);
        }
        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        return (float) ($query->first()?->getAttribute('stock') ?? 0);
    }
}
