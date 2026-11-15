<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Depolar arası ürün transferi : tek işlemde out (kaynak) + in (hedef)
 * `stock_movements` çifti; iki hareket `reference = transfer:<id>` ile bağlıdır.
 * Toplam stok değişmez (kabul testi bunu kilitler).
 */
class WarehouseTransfer extends Model
{
    protected $fillable = [
        'company_id', 'product_id', 'from_warehouse_id', 'to_warehouse_id',
        'quantity', 'note', 'user_id',
    ];

    protected $casts = ['quantity' => 'decimal:4'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }
}
