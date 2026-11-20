<?php

namespace App\Integration\Contracts;

use App\Integration\Enums\Channel;

/** Tüm kanal adapter'larının ortak sözleşmesi. */
interface ChannelAdapterInterface
{
    public function channel(): Channel;

    /** Bu adapter'ın hangi operasyonları desteklediği (örn. 'fetch_orders'). */
    public function supports(string $operation): bool;
}
