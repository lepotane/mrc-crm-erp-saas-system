<?php

namespace App\Integration\Contracts;

use App\Integration\Canonical\CanonicalGibFile;
use Carbon\CarbonInterface;

interface InboundFileSourceInterface extends ChannelAdapterInterface
{
    /** @return array<int, CanonicalGibFile> */
    public function fetchInboundFiles(?CarbonInterface $since = null): array;
}
