<?php

namespace App\Integration\Gib;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Contracts\VoucherBuilderInterface;
use App\Integration\Support\CredentialVault;
use App\Models\Document;
use App\Models\GibDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Moves an informal MRC voucher to "official_pending" by generating the UBL-TR
 * file. Status "official" is only ever set by the inbound feedback job.
 */
final class ResmilesTirService
{
    /**
     * @param  array<string, VoucherBuilderInterface>  $voucherBuilders  document type => builder
     */
    public function __construct(
        private readonly CredentialVault $vault,
        private readonly array $voucherBuilders = [],
    ) {}

    public function resmilesTir(int $companyId, int $voucherModelId): GibDocument
    {
        $document = $this->loadDocument($companyId, $voucherModelId);

        $gib = GibDocument::where('company_id', $companyId)
            ->where('document_id', $document->id)
            ->first();
        if ($gib !== null && $gib->status !== 'draft') {
            throw new RuntimeException(
                'Only draft vouchers can be formalized. Current status: '.$gib->status.'.'
            );
        }

        $voucher = $this->toCanonicalVoucher($companyId, $voucherModelId);

        if (! $voucher->isBalanced()) {
            throw new RuntimeException('Voucher is not balanced; formalization refused.');
        }

        if (trim((string) ($voucher->taxId ?? '')) === '') {
            throw new RuntimeException('Voucher tax id is required for formalization.');
        }

        $ettn = trim((string) ($voucher->ettn ?? ''));
        if ($ettn === '') {
            // The ETTN is generated here and persisted with the GIB document row
            // by the adapter, so a retry cannot silently create a second one.
            $ettn = Str::uuid()->toString();
            $voucher = $this->withEttn($voucher, $ettn);
        }

        $warnings = [];
        if ($this->accountMap($companyId) === []) {
            // Account codes are tenant configuration. Missing mapping is a
            // warning only; no default chart-of-accounts code is enforced.
            $warnings[] = 'account_map is not configured for this tenant; no account codes were enforced.';
            Log::warning('GIB formalization without account mapping', [
                'company_id' => $companyId,
                'document_id' => $document->id,
            ]);
        }

        $result = (new GibAdapter($companyId, $this->vault))->pushVoucher($voucher);
        if (! $result->ok) {
            throw new RuntimeException('GIB file generation failed: '.$result->message);
        }

        $gib = GibDocument::where('company_id', $companyId)
            ->where('ettN', $ettn)
            ->firstOrFail();

        $response = is_array($gib->response) ? $gib->response : [];
        $gib->update([
            'document_id' => $document->id,
            'document_type' => $this->documentType($voucher),
            'status' => 'official_pending',
            'official_at' => null,
            'response' => array_merge($response, [
                'formalized_at' => now()->toIso8601String(),
                'warnings' => $warnings,
            ]),
        ]);

        // Deliberately never 'official': only the inbound feedback job may set it.
        return $gib->refresh();
    }

    /**
     * Converts an MRC Document into the canonical voucher via a registered
     * VoucherBuilderInterface implementation for that document type.
     */
    public function toCanonicalVoucher(int $companyId, int $documentId): CanonicalVoucher
    {
        $document = $this->loadDocument($companyId, $documentId);
        $builder = $this->builderFor((string) $document->type);

        $lines = [];
        foreach ($document->items as $item) {
            $lines[] = new CanonicalOrderLine(
                $item->product_id !== null ? (string) $item->product_id : null,
                $item->name,
                (string) ($item->quantity ?? '1'),
                (string) ($item->unit_price ?? '0'),
                (string) ($item->tax_rate ?? '0'),
                (string) ($item->discount_rate ?? '0'),
                (string) $item->id,
            );
        }

        $order = new CanonicalOrder(
            (string) $document->id,
            $document->serial,
            (string) ($document->currency_code ?: 'TRY'),
            (string) ($document->grand_total ?? '0'),
            (string) $document->status,
            null,
            $document->status === 'paid',
            $document->issue_date,
            $document->party?->tax_number,
            $document->party_id !== null ? (string) $document->party_id : null,
            $lines,
            ['document_id' => $document->id, 'document_type' => $document->type],
        );

        $voucher = $builder->build($order);
        $taxId = trim((string) ($voucher->taxId
            ?? $document->party?->tax_number
            ?? $document->company?->tax_number
            ?? ''));

        if ($voucher->taxId !== $taxId) {
            $voucher = new CanonicalVoucher(
                $voucher->externalRef,
                $voucher->voucherNumber,
                $voucher->date,
                $voucher->currency,
                $voucher->lines,
                $voucher->state,
                $taxId !== '' ? $taxId : null,
                $voucher->description,
                $voucher->ettn,
            );
        }

        return $voucher;
    }

    private function loadDocument(int $companyId, int $documentId): Document
    {
        $document = Document::where('company_id', $companyId)->find($documentId);
        if ($document === null) {
            throw new RuntimeException('Document not found for this company.');
        }

        return $document;
    }

    private function builderFor(string $type): VoucherBuilderInterface
    {
        $builder = $this->voucherBuilders[$type] ?? $this->voucherBuilders['*'] ?? null;
        if (! $builder instanceof VoucherBuilderInterface) {
            throw new RuntimeException('No voucher builder registered for document type: '.$type.'.');
        }

        return $builder;
    }

    private function withEttn(CanonicalVoucher $voucher, string $ettn): CanonicalVoucher
    {
        return new CanonicalVoucher(
            $voucher->externalRef,
            $voucher->voucherNumber,
            $voucher->date,
            $voucher->currency,
            $voucher->lines,
            $voucher->state,
            $voucher->taxId,
            $voucher->description,
            $ettn,
        );
    }

    /** @return array<string,mixed> Tenant-configured account_map (never hardcoded). */
    private function accountMap(int $companyId): array
    {
        $rows = DB::table('tenant_integrations')
            ->where('company_id', $companyId)
            ->whereNotNull('settings')
            ->pluck('settings');

        foreach ($rows as $raw) {
            $settings = is_string($raw) ? json_decode($raw, true) : (array) $raw;
            if (is_array($settings) && ! empty($settings['account_map']) && is_array($settings['account_map'])) {
                return $settings['account_map'];
            }
        }

        return [];
    }

    private function documentType(CanonicalVoucher $voucher): string
    {
        foreach ($voucher->lines as $line) {
            $type = trim((string) ($line->documentType ?? ''));
            if ($type !== '') {
                return mb_substr($type, 0, 40);
            }
        }

        return 'invoice';
    }
}
