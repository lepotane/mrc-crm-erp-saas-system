<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentItem extends Model
{
    protected $fillable = [
        'document_id', 'company_id', 'product_id', 'sort_order', 'name',
        'quantity', 'unit_price', 'price_currency_code', 'discount_rate', 'tax_rate',
        'line_subtotal', 'line_discount', 'line_tax', 'line_total', 'cogs',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount_rate' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'line_subtotal' => 'decimal:4',
        'line_discount' => 'decimal:4',
        'line_tax' => 'decimal:4',
        'line_total' => 'decimal:4',
        'cogs' => 'decimal:4',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
