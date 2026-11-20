<?php

namespace App\Integration\Canonical;

final class CanonicalVoucherLine
{
    public function __construct(
        public readonly string $accountCode,
        public readonly string $debit,
        public readonly string $credit,
        public readonly ?string $description = null,
        public readonly ?string $taxRate = null,
        public readonly ?string $documentType = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'account_code' => $this->accountCode,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'description' => $this->description,
            'tax_rate' => $this->taxRate,
            'document_type' => $this->documentType,
        ];
    }
}
