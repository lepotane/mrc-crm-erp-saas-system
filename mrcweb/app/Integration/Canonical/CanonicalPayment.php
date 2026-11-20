<?php

namespace App\Integration\Canonical;

use Carbon\CarbonInterface;

/**
 * Çift yönlü ödeme (karar K4). `externalRef` mükerrer tahsilat korumasıdır.
 */
final class CanonicalPayment
{
    public function __construct(
        public readonly string $externalRef,
        public readonly string $orderRef,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $method,
        public readonly ?CarbonInterface $paidAt,
        public readonly ?string $transactionRef = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'external_ref' => $this->externalRef,
            'order_ref' => $this->orderRef,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'paid_at' => $this->paidAt?->toIso8601String(),
            'transaction_ref' => $this->transactionRef,
        ];
    }
}
