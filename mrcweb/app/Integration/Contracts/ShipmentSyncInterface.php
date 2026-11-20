<?php

namespace App\Integration\Contracts;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\SyncResult;

interface ShipmentSyncInterface extends ChannelAdapterInterface
{
    public function pushShipment(CanonicalOrder $order): SyncResult;

    public function pushStatus(string $externalRef, string $mrcStatus): SyncResult;
}
