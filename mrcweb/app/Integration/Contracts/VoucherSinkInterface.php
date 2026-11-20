<?php

namespace App\Integration\Contracts;

use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Canonical\SyncResult;

interface VoucherSinkInterface extends ChannelAdapterInterface
{
    public function pushVoucher(CanonicalVoucher $voucher): SyncResult;
}
