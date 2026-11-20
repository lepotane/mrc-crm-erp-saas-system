<?php

namespace App\Integration\Canonical;

use Carbon\CarbonInterface;

/**
 * Tsoft <-> MRC sipariş kanonik modeli.
 * Dış sistem alan adları ASLA buraya sızmaz; mapper tarafında çevrilir.
 */
final class CanonicalOrder
{
    /** @param array<int, CanonicalOrderLine> $lines */
    public function __construct(
        public readonly string $externalRef,
        public readonly ?string $orderNumber,
        public readonly string $currency,
        public readonly string $grandTotal,
        public readonly string $externalStatus,
        public readonly ?string $paymentRef,
        public readonly bool $isPaid,
        public readonly ?CarbonInterface $placedAt,
        public readonly ?string $buyerTaxId,
        public readonly ?string $buyerRef,
        public readonly array $lines,
        public readonly array $raw = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'external_ref' => $this->externalRef,
            'order_number' => $this->orderNumber,
            'currency' => $this->currency,
            'grand_total' => $this->grandTotal,
            'external_status' => $this->externalStatus,
            'payment_ref' => $this->paymentRef,
            'is_paid' => $this->isPaid,
            'placed_at' => $this->placedAt?->toIso8601String(),
            'buyer_tax_id' => $this->buyerTaxId,
            'buyer_ref' => $this->buyerRef,
            'lines' => array_map(fn (CanonicalOrderLine $l) => $l->toArray(), $this->lines),
        ];
    }
}
