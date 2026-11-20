<?php

namespace App\Integration\Sync;

use Illuminate\Database\Eloquent\Model;

/** SKU + depo bazında stok anlık görüntüsü ve sürümü. */
class IntegrationStockState extends Model
{
    protected $table = 'integration_stock_state';

    protected $fillable = [
        'company_id', 'sku', 'warehouse_id', 'quantity', 'version', 'source', 'synced_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'synced_at' => 'datetime',
    ];
}
