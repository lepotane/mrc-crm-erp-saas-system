<?php

namespace App\Integration\Canonical;

/**
 * Sipariş kalemi. Fiyatlar string decimal olarak taşınır (Tsoft float döndürür,
 * yuvarlama hatası olmasın diye burada da string kullanırız).
 */
final class CanonicalOrderLine
{
    public function __construct(
        public readonly ?string $sku,
        public readonly ?string $name,
        public readonly string $quantity,
        public readonly string $unitPrice,
        public readonly string $taxRate,
        public readonly string $discountRate = '0',
        public readonly ?string $externalRef = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'tax_rate' => $this->taxRate,
            'discount_rate' => $this->discountRate,
            'external_ref' => $this->externalRef,
        ];
    }
}
