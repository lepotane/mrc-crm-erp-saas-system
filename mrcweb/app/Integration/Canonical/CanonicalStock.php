<?php

namespace App\Integration\Canonical;

/**
 * Paralel tek stok (karar K1). `version` iyimser kilitleme içindir.
 */
final class CanonicalStock
{
    public function __construct(
        public readonly string $sku,
        public readonly string $warehouseCode,
        public readonly string $quantity,
        public readonly int $version,
        public readonly string $source,
        public readonly ?string $externalRef = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'warehouse_code' => $this->warehouseCode,
            'quantity' => $this->quantity,
            'version' => $this->version,
            'source' => $this->source,
            'external_ref' => $this->externalRef,
        ];
    }
}
