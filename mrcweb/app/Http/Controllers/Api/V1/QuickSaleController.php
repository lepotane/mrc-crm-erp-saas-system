<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BankAccount;
use App\Models\Currency;
use App\Models\Document;
use App\Models\Payment;
use App\Services\DocumentSettlement;
use App\Support\Audit;
use App\Support\ExchangeRates;
use App\Support\PlanLimits;
use App\Support\ReportCache;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Hızlı satış — POS .
 *
 * Tek çağrıda: fatura (doğrudan `issued`) + stok çıkışı + tahsilat (belge
 * `paid` olur) + para üstü hesabı. Uzun belge akışının (taslak → ihraç →
 * tahsilat) kısayoludur; kuralların hiçbiri esnetilmez: satırlar
 * `DocumentController::buildLines` ile kurulur, stok/maliyet
 * `applyIssueEffects` ile düşer, tahsilat `Payment` doğrulamasıyla yazılır.
 *
 * - `POST /quick-sales` → `{sale: {document, payment}, change, receipt_pdf_url}`
 * - `GET /quick-sales/{document}/receipt.pdf?size=58|80|a5&inline=1` — fiş PDF'i
 *   (yalnız bu uçtan kesilmiş faturalar; `type=invoice` zorunlu).
 */
class QuickSaleController extends DocumentController
{
    /**
     * Hızlı satış: tek çağrıda fatura + stok + tahsilat + para üstü.
     *
     * @response array{sale: array{document: array, payment: array}, change: float, receipt_pdf_url: string}
     */
    public function store(Request $request)
    {
        $company = $this->tenantCompany($request);
        $companyId = $this->tenantId($request);

        PlanLimits::for($company)->assertWithinLimit('documents_per_month', $company);

        $data = $request->validate([
            'party_id' => ['required', 'integer',
                Rule::exists('parties', 'id')->where('company_id', $companyId)],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['nullable', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.name' => ['nullable', 'string', 'max:190'],
            'items.*.quantity' => ['required', 'numeric'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            // Fiyat sınıfı: POS sepetindeki fiyatın hangi para birimi
            // listesinden geldiği (DocumentController ile aynı kural;
            // yoksa buildLines kalemindeki bilgi kaybolurdu).
            'items.*.price_currency_code' => ['nullable', 'string', Rule::in(Currency::activeCodes())],
            'items.*.discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency_code' => ['nullable', 'string', Rule::in(Currency::activeCodes())],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['nullable', 'string', Rule::in(Payment::MANUAL_METHODS)],
            'bank_account_id' => ['nullable', 'integer',
                Rule::exists('bank_accounts', 'id')->where('company_id', $companyId)],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
        ]);

        $currencyCode = $data['currency_code'] ?? $this->companyCurrency($request);
        $issueDate = date('Y-m-d');

        // Toplam işlem ÖNCESİ bilinir (buildLines salt okunur): para üstü kuralı
        // 422'si stoğu/ödemiği yazmadan döner — yarım satış kalmaz.
        $warehouseId = $this->resolveWarehouseId($request, $companyId, $data['warehouse_id'] ?? null);
        [$previewLines, $previewTotals] = $this->buildLines($companyId, $data['items'], false, $this->defaultTaxRate($request));
        $grand = round((float) $previewTotals['grand_total'], 4);

        $tendered = isset($data['amount_tendered']) ? round((float) $data['amount_tendered'], 2) : null;

        if ($tendered !== null && $tendered < $grand) {
            throw ValidationException::withMessages([
                'amount_tendered' => [__('Alınan tutar toplamdan az olamaz.')],
            ]);
        }

        if (! empty($data['bank_account_id'])) {
            $bankAccount = BankAccount::where('company_id', $companyId)->findOrFail($data['bank_account_id']);

            if (! $bankAccount->is_active) {
                throw ValidationException::withMessages([
                    'bank_account_id' => [__('Pasif hesaba ödeme kaydedilemez.')],
                ]);
            }

            if ($bankAccount->currency_code !== $currencyCode) {
                throw ValidationException::withMessages([
                    'bank_account_id' => [__('Ödeme para birimi hesap para birimiyle aynı olmalıdır.')],
                ]);
            }
        }

        $result = DB::transaction(function () use ($request, $companyId, $data, $currencyCode, $issueDate, $warehouseId, $previewLines, $previewTotals, $tendered) {
            [$lines, $totals] = [$previewLines, $previewTotals];

            $document = Document::create([
                'company_id' => $companyId,
                'origin' => 'mrc',
                'type' => Document::TYPE_INVOICE,
                'serial' => Document::nextSerial($companyId, Document::TYPE_INVOICE, (int) date('Y')),
                'party_id' => $data['party_id'],
                'warehouse_id' => $warehouseId,
                'status' => 'draft',
                'issue_date' => $issueDate,
                'due_date' => $issueDate,
                'currency_code' => $currencyCode,
                'exchange_rate' => ExchangeRates::rateFor($currencyCode, $issueDate) ?? 1,
                'rate_date' => $issueDate,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $this->forceMrcOrigin($document);

            $this->persistLines($companyId, $document, $lines);

            // draft → issued (satır kilidi + işlem-içi yeniden denetim — changeStatus deseni).
            Document::where('id', $document->id)->lockForUpdate()->first();
            $document->refresh();

            if (! $document->canTransitionTo('issued')) {
                throw ValidationException::withMessages([
                    'status' => [__('Hızlı satış faturalandırılamadı.')],
                ]);
            }

            $document->update(['status' => 'issued']);
            $this->applyIssueEffects($companyId, $document->refresh(), $warehouseId, (int) $request->user()->id);

            $grand = round((float) $document->grand_total, 4);

            $payment = Payment::create([
                'company_id' => $companyId,
                'party_id' => $data['party_id'],
                'document_id' => $document->id,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'direction' => Payment::DIRECTION_IN,
                'amount' => $grand,
                'currency_code' => $currencyCode,
                'exchange_rate' => $document->exchange_rate,
                'method' => $data['payment_method'] ?? Payment::METHOD_CASH,
                'paid_at' => $issueDate,
                'note' => null,
                'created_by' => $request->user()->id,
                'tendered_amount' => $tendered,
                'change_amount' => $tendered === null ? null : round($tendered - $grand, 4),
            ]);

            $documentStatus = DocumentSettlement::recalculate($document->refresh());

            return [$document->refresh(), $payment, $documentStatus];
        });

        [$document, $payment, $documentStatus] = $result;

        // Fatura ihraç + tahsilat transaction'ı commit edildi; writeback opsiyonel.
        $this->dispatchTsoftWriteback($companyId, (int) $document->id);

        $change = $tendered === null ? 0.0 : round($tendered - $grand, 2);

        ReportCache::bump($companyId);

        Audit::fromRequest($request, 'document.created', $companyId, $document, [
            'serial' => $document->serial, 'type' => $document->type, 'via' => 'quick-sale',
        ]);
        Audit::fromRequest($request, 'document.status', $companyId, $document, [
            'serial' => $document->serial, 'from' => 'draft', 'to' => 'issued', 'via' => 'quick-sale',
        ]);
        Audit::fromRequest($request, 'payment.created', $companyId, $payment, [
            'amount' => (float) $payment->amount, 'direction' => 'in',
            'method' => $payment->method, 'document_serial' => $document->serial,
        ]);

        $document->load(['items', 'party']);

        return response()->json([
            'sale' => [
                'document' => $this->present($document),
                'payment' => [
                    'id' => $payment->id,
                    'method' => $payment->method,
                    'amount' => (float) $payment->amount,
                    'document_status' => $documentStatus,
                ],
            ],
            'change' => $change,
            'receipt_pdf_url' => "/api/v1/quick-sales/{$document->id}/receipt.pdf",
        ], 201);
    }

    /**
     * Satış fişi PDF'i (termal 58/80 mm + A5).
     *
     * Bu uç JSON DEĞİL dosya döndürür (`DocumentPdfController` deseni):
     * `application/pdf`, `Cache-Control: no-store`. Hatalar `{message}` JSON'dur.
     */
    public function receipt(Request $request, Document $document)
    {
        abort_if(
            $document->company_id !== $this->tenantId($request)
                || $document->type !== Document::TYPE_INVOICE,
            404,
            __('Belge bulunamadı.')
        );

        $data = $request->validate([
            'size' => ['nullable', 'string', Rule::in(['58', '80', 'a5'])],
            'lang' => ['nullable', 'string', Rule::in(['tr', 'en'])],
        ]);

        $size = $data['size'] ?? '80';

        if (isset($data['lang'])) {
            app()->setLocale($data['lang']);
        }

        $payload = (new DocumentPdfController)->payload($document, 'receipt');
        $payload['isReceipt'] = true;
        $payload['size'] = $size;

        $payment = Payment::where('company_id', $document->company_id)
            ->where('document_id', $document->id)
            ->orderByDesc('id')
            ->first();

        $methodLabels = [
            Payment::METHOD_CASH => __('Nakit'),
            Payment::METHOD_BANK => __('Banka'),
            Payment::METHOD_CARD => __('Kart'),
            Payment::METHOD_TRANSFER => __('Havale / EFT'),
        ];

        $payload['paymentMethodLabel'] = $methodLabels[$payment?->method] ?? ($payment?->method ?? '—');
        $payload['tendered'] = $payment?->tendered_amount === null ? null : (float) $payment->tendered_amount;
        $payload['change'] = $payment?->change_amount === null ? null : (float) $payment->change_amount;

        $pdf = Pdf::loadView('pdf.receipt', $payload);

        $pdf->setPaper($size === 'a5' ? 'a5' : [0, 0, ($size === '58' ? 164.4 : 226.8), 1000]);

        $disposition = $request->boolean('inline')
            ? 'inline'
            : 'attachment; filename="fis-'.$document->serial.'.pdf"';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
