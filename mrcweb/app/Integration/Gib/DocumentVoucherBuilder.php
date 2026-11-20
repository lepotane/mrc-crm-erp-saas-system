<?php

namespace App\Integration\Gib;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Canonical\CanonicalVoucherLine;
use App\Integration\Contracts\VoucherBuilderInterface;
use App\Integration\Enums\Channel;
use App\Integration\Enums\DocumentState;
use App\Models\TenantIntegration;
use RuntimeException;

/**
 * Builds a DRAFT accounting voucher from a canonical order.
 *
 * Account codes are tenant configuration only:
 * tenant_integrations.settings['account_map'] for channel 'gib'.
 * No chart-of-accounts code is hardcoded here. Codes such as 120/600/391 are
 * common in Turkey but are never enforced by this class: a missing mapping is
 * an explicit error, never a silent fallback.
 */
final class DocumentVoucherBuilder implements VoucherBuilderInterface
{
    /** Register this builder as the default for every document type. */
    public static function bootstrap(): void
    {
        VoucherBuilderRegistry::register('*', new self);
    }

    public function build(CanonicalOrder $order): CanonicalVoucher
    {
        // CanonicalOrder has no companyId field: the caller must pass the tenant
        // key in the raw payload.
        $companyId = $order->raw['company_id'] ?? null;
        if ($companyId === null || $companyId === '') {
            throw new RuntimeException(
                'account_map is not configured for company (missing company_id in canonical order raw payload).'
            );
        }
        $companyId = (int) $companyId;

        $documentType = trim((string) ($order->raw['document_type'] ?? 'sale'));
        if ($documentType === '') {
            $documentType = 'sale';
        }

        $mapping = $this->accountMap($companyId);
        if ($mapping === []) {
            throw new RuntimeException(
                'account_map is not configured for company '.$companyId.' (channel gib).'
            );
        }

        $accounts = $this->accountsFor($mapping, $documentType, $companyId);
        $debitAccount = $accounts['debit'];
        $creditAccount = $accounts['credit'];
        $taxAccount = $accounts['tax']; // null: skip the tax line, never invent a code

        $isSale = $this->isSale($documentType);
        $subtotal = '0.0000';
        $taxTotal = '0.0000';
        $lines = [];

        /** @var CanonicalOrderLine $source */
        foreach ($order->lines as $source) {
            $amount = bcmul($this->numeric($source->quantity), $this->numeric($source->unitPrice), 4);
            $subtotal = bcadd($subtotal, $amount, 4);

            if ($taxAccount !== null) {
                $lineTax = bcmul($amount, bcdiv($this->numeric($source->taxRate), '100', 4), 4);
                $taxTotal = bcadd($taxTotal, $lineTax, 4);
            }

            $lines[] = new CanonicalVoucherLine(
                $debitAccount,
                $isSale ? $amount : '0.0000',
                $isSale ? '0.0000' : $amount,
                $source->name,
                $source->taxRate,
                $documentType,
            );
        }

        if ($taxAccount !== null) {
            // Tenant mapping has a tax account, so the tax line is emitted.
            // If the mapping has no tax code we skip it above rather than
            // inventing one (a 391/191 default is never enforced here).
            $lines[] = new CanonicalVoucherLine(
                $taxAccount,
                $isSale ? $taxTotal : '0.0000',
                $isSale ? '0.0000' : $taxTotal,
                'Vergi / tax',
                null,
                $documentType,
            );
        }

        // Balancing counterpart on the tenant-configured opposite account.
        $gross = bcadd($subtotal, $taxTotal, 4);
        $lines[] = new CanonicalVoucherLine(
            $creditAccount,
            $isSale ? '0.0000' : $gross,
            $isSale ? $gross : '0.0000',
            null,
            null,
            $documentType,
        );

        $voucherNumber = $order->orderNumber !== null && $order->orderNumber !== ''
            ? $order->orderNumber
            : $order->externalRef;

        return new CanonicalVoucher(
            $order->externalRef,
            $voucherNumber,
            $order->placedAt ?? now(),
            $order->currency,
            $lines,
            DocumentState::Draft, // always a draft; this builder never sets official state
            $order->buyerTaxId,
            $order->raw['description'] ?? null,
            $order->raw['ettn'] ?? null,
        );
    }

    /** @return array<string,mixed> */
    private function accountMap(int $companyId): array
    {
        $map = TenantIntegration::for($companyId, Channel::Gib)?->settings['account_map'] ?? null;

        return is_array($map) ? $map : [];
    }

    /**
     * @param  array<string,mixed>  $map
     * @return array{debit:string,credit:string,tax:?string}
     */
    private function accountsFor(array $map, string $documentType, int $companyId): array
    {
        $selected = $map[$documentType] ?? $map['default'] ?? null;
        if (! is_array($selected)) {
            // Alternative flat shape: ['debit' => ..., 'credit' => ...]
            $selected = $map;
        }

        $debit = $this->accountCode($selected, $map, 'debit');
        $credit = $this->accountCode($selected, $map, 'credit');

        if ($debit === null) {
            throw new RuntimeException(
                'account_map is missing the "debit" account for document type "'.$documentType.'" (company '.$companyId.').'
            );
        }
        if ($credit === null) {
            throw new RuntimeException(
                'account_map is missing the "credit" account for document type "'.$documentType.'" (company '.$companyId.').'
            );
        }

        return [
            'debit' => $debit,
            'credit' => $credit,
            'tax' => $this->accountCode($selected, $map, 'tax'),
        ];
    }

    /**
     * @param  array<string,mixed>  $selected
     * @param  array<string,mixed>  $map
     */
    private function accountCode(array $selected, array $map, string $role): ?string
    {
        foreach ([$selected, $map] as $source) {
            $value = $source[$role] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function isSale(string $documentType): bool
    {
        return ! in_array(strtolower($documentType), ['purchase', 'purchase_order', 'bill'], true);
    }

    private function numeric(string $value): string
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? $value : '0';
    }
}
