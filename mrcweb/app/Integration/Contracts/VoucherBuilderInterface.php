<?php

namespace App\Integration\Contracts;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalVoucher;

interface VoucherBuilderInterface
{
    /** Kanonik siparişten muhasebe fişi taslağı üretir (henüz resmi değil). */
    public function build(CanonicalOrder $order): CanonicalVoucher;
}
