<?php

namespace App\Integration\Contracts;

use App\Integration\Canonical\CanonicalOrder;
use Carbon\CarbonInterface;

interface OrderSourceInterface extends ChannelAdapterInterface
{
    /** @return array<int, CanonicalOrder> */
    public function fetchOrders(?CarbonInterface $since = null, int $limit = 100): array;

    public function fetchOrder(string $externalRef): ?CanonicalOrder;
}
