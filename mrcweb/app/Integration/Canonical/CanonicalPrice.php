<?php

namespace App\Integration\Canonical;

/**
 * Para birimi bazlı fiyat sınıfı (karar K2). String decimal zorunlu.
 */
final class CanonicalPrice
{
    public function __construct(
        public readonly string $sku,
        public readonly string $currency,
        public readonly string $salePrice,
        public readonly ?string $costPrice = null,
        public readonly bool $isActive = true,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'currency' => $this->currency,
            'sale_price' => $this->salePrice,
            'cost_price' => $this->costPrice,
            'is_active' => $this->isActive,
        ];
    }
}
