<?php

namespace App\Integration\Contracts;

use App\Integration\Canonical\CanonicalStock;
use App\Integration\Canonical\SyncResult;

interface StockSyncInterface extends ChannelAdapterInterface
{
    public function fetchStock(string $sku, ?string $warehouseCode = null): ?CanonicalStock;

    /** `expected` alanı varsa iyimser kilitleme uygulanır. */
    public function pushStock(CanonicalStock $stock): SyncResult;
}
