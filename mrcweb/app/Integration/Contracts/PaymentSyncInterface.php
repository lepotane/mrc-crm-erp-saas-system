<?php

namespace App\Integration\Contracts;

use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Canonical\SyncResult;

interface PaymentSyncInterface extends ChannelAdapterInterface
{
    public function pushPayment(CanonicalPayment $payment): SyncResult;
}
