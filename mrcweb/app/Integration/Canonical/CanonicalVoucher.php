<?php

namespace App\Integration\Canonical;

use App\Integration\Enums\DocumentState;
use Carbon\CarbonInterface;

/**
 * Muhasebe fişi (karar K6/K8). MRC üretir, müşavir onaylar, Luca'ya gider.
 */
final class CanonicalVoucher
{
    /** @param array<int, CanonicalVoucherLine> $lines */
    public function __construct(
        public readonly string $externalRef,
        public readonly string $voucherNumber,
        public readonly CarbonInterface $date,
        public readonly string $currency,
        public readonly array $lines,
        public readonly DocumentState $state = DocumentState::Draft,
        public readonly ?string $taxId = null,
        public readonly ?string $description = null,
        public readonly ?string $ettn = null,
    ) {}

    public function isBalanced(): bool
    {
        $debit = '0';
        $credit = '0';
        foreach ($this->lines as $line) {
            $debit = bcadd($debit, $line->debit, 4);
            $credit = bcadd($credit, $line->credit, 4);
        }

        return bccomp($debit, $credit, 4) === 0;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'external_ref' => $this->externalRef,
            'voucher_number' => $this->voucherNumber,
            'date' => $this->date->toDateString(),
            'currency' => $this->currency,
            'state' => $this->state->value,
            'tax_id' => $this->taxId,
            'description' => $this->description,
            'ettn' => $this->ettn,
            'balanced' => $this->isBalanced(),
            'lines' => array_map(fn (CanonicalVoucherLine $l) => $l->toArray(), $this->lines),
        ];
    }
}
