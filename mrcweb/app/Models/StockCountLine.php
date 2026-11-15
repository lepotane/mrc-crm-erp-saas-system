<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'stock_count_id', 'product_id', 'expected_qty', 'counted_qty',
    ];

    protected $casts = [
        'expected_qty' => 'decimal:4',
        'counted_qty' => 'decimal:4',
    ];

    public function count(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
