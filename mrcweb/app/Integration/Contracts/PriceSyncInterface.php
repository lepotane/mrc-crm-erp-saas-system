<?php

namespace App\Integration\Contracts;

use App\Integration\Canonical\CanonicalPrice;
use App\Integration\Canonical\SyncResult;

interface PriceSyncInterface extends ChannelAdapterInterface
{
    public function pushPrice(CanonicalPrice $price): SyncResult;
}
