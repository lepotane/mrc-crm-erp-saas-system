<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PresentsPaginator;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Currency;
use App\Models\Document;
use App\Models\Party;
use App\Models\Payment;
use App\Services\DocumentSettlement;
use App\Services\PartyBalance;
use App\Support\Audit;
use App\Support\ExchangeRates;
use App\Support\ReportCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    use ResolvesTenant, PresentsPaginator;

    /**
     * Tahsilat ve ödemeleri listele (cari, belge, yön, tarih süzgeçleri, sayfalı)
     *
     * @response array{
     *     data: array<int,
     *     array{id: int, party_id: int, party_name: string, party_code: string, document_id: int, document_serial: string, document_type: string, bank_account_id: int|null, bank_account_name: string|null, direction: string, amount: string, currency_code: string, exchange_rate: string, method: string, paid_at: string, reference: string, note: string, created_at: string}>,
     *     links: array<int,
     *     array{url: string|null, label: string, page: int|null, active: bool}>,
     *     meta: array{current_page: int, from: int, last_page: int, per_page: int, to: int, total: float},
     * }
     */
    public function index(Request $request)
    {
        $companyId = $this->tenantId($request);

        $query = Payment::query()
            ->where('payments.company_id', $companyId)
            ->leftJoin('parties', 'parties.id', '=', 'payments.party_id')
            ->leftJoin('documents', 'documents.id', '=', 'payments.document_id')
            ->leftJoin('bank_accounts', 'bank_accounts.id', '=', 'payments.bank_account_id')
            ->selectRaw('payments.*,
                parties.name AS party_name, parties.code AS party_code,
                documents.serial AS document_serial, documents.type AS document_type,
                bank_accounts.name AS bank_account_name');

        foreach (['party_id', 'document_id', 'bank_account_id'] as $field) {
            if ($request->query($field) !== null && $request->query($field) !== '') {
                $query->where("payments.{$field}", (int) $request->query($field));
            }
        }
        foreach (['direction', 'method'] as $field) {
            if (($v = trim((string) $request->query($field))) !== '') {
                $query->where("payments.{$field}", $v);
            }
        }
        if (($from = trim((string) $request->query('from'))) !== '') {
            $query->whereDate('payments.paid_at', '>=', $from);
        }
        if (($to = trim((string) $request->query('to'))) !== '') {
            $query->whereDate('payments.paid_at', '<=', $to);
        }
        if (($search = trim((string) $request->query('search'))) !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('parties.name', 'like', "%{$search}%")
                    ->orWhere('payments.reference', 'like', "%{$search}%");
            });
        }

        $paginator = $query->orderByDesc('payments.paid_at')
            ->orderByDesc('payments.id')
            ->paginate(min((int) ($request->query('per_page', 20) ?: 20), 100))
            ->withQueryString();

        $paginator->getCollection()->transform(fn (Payment $p) => $this->presentRow($p));

        return response()->json($this->paginateJson($paginator));
    }

    /**
     * Tahsilat veya ödeme kaydet: belge ve cari eşleşmesi denetlenir, belge durumu yeniden hesaplanır
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 201,
        type: 'array{ payment: array{id: int, party_id: int, party_name: string, party_code: string, document_id: int, document_serial: string, document_type: string, bank_account_id: int|null, bank_account_name: string|null, direction: string, amount: string, currency_code: string, exchange_rate: string, method: string, paid_at: string, reference: string, note: string, created_at: string}, document_status: string, party_balance: float, }',
    )]
    public function store(Request $request)
    {
        $companyId = $this->tenantId($request);

        $data = $request->validate([
            'party_id' => ['required', 'integer',
                Rule::exists('parties', 'id')->where('company_id', $companyId)],
            'direction' => ['required', 'string', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric'],
            'currency_code' => ['nullable', 'string', Rule::in(Currency::activeCodes())],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'method' => ['nullable', 'string', Rule::in(Payment::MANUAL_METHODS)],
            // Kur seçimi : belgeye bağlı tahsilatta hangi kur işlensin?
            // Belirtilmezse BELGE kuru (fotoğraf) kullanılır — eski davranışla aynı.
            'rate_source' => ['nullable', 'string', Rule::in(['document', 'current'])],
            'paid_at' => ['nullable', 'date'],
            'bank_account_id' => ['nullable', 'integer',
                Rule::exists('bank_accounts', 'id')->where('company_id', $companyId)],
            'document_id' => ['nullable', 'integer',
                Rule::exists('documents', 'id')->where('company_id', $companyId)],
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $amount = round((float) $data['amount'], 4);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => [__('Tutar sıfırdan büyük olmalıdır.')],
            ]);
        }

        $party = Party::where('company_id', $companyId)->findOrFail($data['party_id']);

        // Yön kuralı belge tipine göre esner: iade belgesi ters-yönü açar
        // (koşul, belgenin kendisini okumadan yalnız tipine bakılarak çözülür —
        // hata anahtar sırası değişmez: kiracı 404'ten önce yön 422 gelir).
        $isReturn = false;

        if (! empty($data['document_id'])) {
            $isReturn = Document::where('company_id', $companyId)
                ->whereKey($data['document_id'])
                ->value('type') === Document::TYPE_RETURN;
        }

        $this->ensureDirectionMatchesParty($data['direction'], $party, $isReturn);

        $document = null;
        if (! empty($data['document_id'])) {
            $document = Document::where('company_id', $companyId)->findOrFail($data['document_id']);
            $this->ensureDocumentEligible($document, $data['direction'], $data['currency_code'] ?? 'TRY');

            // Belge ile ödeme carisi aynı olmalı: aksi hâlde belge „Ödendi” olur ama
            // PartyBalance ödemeyi başka carinin hanesine sayar (ikisi de yanlış).
            if ((int) $document->party_id !== (int) $party->id) {
                throw ValidationException::withMessages([
                    'document_id' => [__('Seçilen belge bu cari hesaba ait değil.')],
                ]);
            }
        }

        $bankAccount = null;
        if (! empty($data['bank_account_id'])) {
            $bankAccount = BankAccount::where('company_id', $companyId)->findOrFail($data['bank_account_id']);
            if (! $bankAccount->is_active) {
                throw ValidationException::withMessages([
                    'bank_account_id' => [__('Pasif hesaba ödeme kaydedilemez.')],
                ]);
            }

            // v1’de kur çevrimi yok: hesaba işleyen tutar, hesabın para biriminde sayılır.
            $paymentCurrency = $data['currency_code'] ?? 'TRY';
            if ($bankAccount->currency_code !== $paymentCurrency) {
                throw ValidationException::withMessages([
                    'bank_account_id' => [__('Ödeme para birimi hesap para birimiyle aynı olmalıdır.')],
                ]);
            }
        }

        $payment = null;
        $documentStatus = null;

        DB::transaction(function () use ($request, $companyId, $data, $amount, $document, &$payment, &$documentStatus) {
            if ($document) {
                // Aynı belgeye eşzamanlı iki tahsilat: satır kilidi, durum yeniden
                // hesaplamasının sırayla yapılmasını sağlar (MySQL’de FOR UPDATE).
                Document::where('id', $document->id)->lockForUpdate()->first();
            }

            $payment = Payment::create([
                'company_id' => $companyId,
                'party_id' => $data['party_id'],
                'document_id' => $data['document_id'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'direction' => $data['direction'],
                'amount' => $amount,
                'currency_code' => $data['currency_code'] ?? 'TRY',
                'exchange_rate' => isset($data['exchange_rate'])
                    ? round((float) $data['exchange_rate'], 6)
                    : $this->resolvePaymentRate($request, $data, $document, $data['currency_code'] ?? 'TRY'),
                'method' => $data['method'] ?? 'cash',
                'paid_at' => $data['paid_at'] ?? date('Y-m-d'),
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            if ($document) {
                $documentStatus = $this->recalculateDocumentStatus($document->refresh());
            }
        });

        $payment->load(['party', 'document', 'bankAccount']);

        ReportCache::bump($companyId);
        Audit::fromRequest($request, 'payment.created', $companyId, $payment, [
            'amount' => (float) $payment->amount,
            'direction' => $payment->direction,
            'method' => $payment->method,
            'document_serial' => $document?->serial,
            'rate' => (float) $payment->exchange_rate,
            'rate_source' => $data['rate_source'] ?? ($document ? 'document' : null),
        ]);

        return response()->json([
            'payment' => $this->presentRow($payment),
            'document_status' => $documentStatus,
            'party_balance' => PartyBalance::overall($party->refresh()),
        ], 201);
    }

    /**
     * Ödemeyi sil: belgenin durumu ve cari bakiyesi geri alınır
     *
     * @response array{message: string, document_status: string|null}
     */
    public function destroy(Request $request, Payment $payment)
    {
        abort_if($payment->company_id !== $this->tenantId($request), 404, __('Ödeme bulunamadı.'));

        $documentStatus = null;
        $companyId = $this->tenantId($request);
        $snapshot = [
            'amount' => (float) $payment->amount,
            'direction' => $payment->direction,
            'method' => $payment->method,
            'payment_id' => $payment->id,
        ];

        DB::transaction(function () use ($payment, &$documentStatus) {
            $documentId = $payment->document_id;
            $payment->delete();

            if ($documentId !== null) {
                $document = Document::find($documentId);
                if ($document && $document->company_id === $payment->company_id) {
                    $documentStatus = $this->recalculateDocumentStatus($document);
                }
            }
        });

        ReportCache::bump($companyId);
        Audit::fromRequest($request, 'payment.deleted', $companyId, $payment, $snapshot);

        return response()->json([
            'message' => __('Ödeme silindi.'),
            'document_status' => $documentStatus,
        ]);
    }

    /**
     * Cari bakiye ve hareket dökümü (pozitif bakiye = bizim alacağımız)
     *
     * Cari bakiye dökümü.
     *
     * @response array{
     *     party: array{id: int, code: string, name: string, type: string, currency_code: string, opening_balance: string, credit_limit: string|null},
     *     totals: array{sales_total: float, purchases_total: float, receipts_total: float, payments_total: float, balance: float},
     *     by_currency: array{TRY: array{sales: float, purchases: int, receipts: float, payments: int, balance: float}},
     *     aging: array{not_due: int, days_1_30: int, days_31_60: int, days_61_90: int, days_over_90: int},
     *     open_documents: array<int,
     *     array{id: int, type: string, serial: string, issue_date: string, due_date: string|null, grand_total: float, paid_total: float, open_amount: float, days_overdue: int}>,
     *     payments: array<int,
     *     array{id: int, direction: string, amount: string, currency_code: string, method: string, paid_at: string, reference: string, document_id: int, document_serial: string, bank_account_id: int|null, bank_account_name: string|null}>,
     * }
     */
    public function partyBalance(Request $request, Party $party)
    {
        abort_if($party->company_id !== $this->tenantId($request), 404, __('Cari kaydı bulunamadı.'));

        $calc = PartyBalance::for($party);

        $recent = Payment::query()
            ->where('payments.company_id', $party->company_id)
            ->where('payments.party_id', $party->id)
            ->leftJoin('documents', 'documents.id', '=', 'payments.document_id')
            ->leftJoin('bank_accounts', 'bank_accounts.id', '=', 'payments.bank_account_id')
            ->selectRaw('payments.*,
                documents.serial AS document_serial, documents.type AS document_type,
                bank_accounts.name AS bank_account_name')
            ->orderByDesc('payments.paid_at')
            ->orderByDesc('payments.id')
            ->limit(10)
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'direction' => $p->direction,
                'amount' => $p->amount,
                'currency_code' => $p->currency_code,
                'method' => $p->method,
                'paid_at' => $p->paid_at?->format('Y-m-d'),
                'reference' => $p->reference,
                'document_id' => $p->document_id,
                'document_serial' => $p->getAttribute('document_serial'),
                'bank_account_id' => $p->bank_account_id,
                'bank_account_name' => $p->getAttribute('bank_account_name'),
            ])
            ->values();

        return response()->json([
            'party' => $party->only(['id', 'code', 'name', 'type', 'currency_code', 'opening_balance', 'credit_limit']),
            'totals' => $calc['totals'],
            'by_currency' => (object) $calc['by_currency'],
            'aging' => $calc['aging'],
            'open_documents' => $calc['open_documents'],
            'payments' => $recent,
        ]);
    }

    protected function ensureDirectionMatchesParty(string $direction, Party $party, bool $isReturnDoc = false): void
    {
        // İade belgesine bağlı ters-yön ödeme serbesttir (müşteriye iade ödemesi,
        // tedarikçiden iade tahsilatı); belgesiz ters-yön kayıt kapalı kalır.
        if ($isReturnDoc) {
            return;
        }

        $allowed = $direction === 'in'
            ? ['customer', 'both']
            : ['supplier', 'both'];

        if (! in_array($party->type, $allowed, true)) {
            throw ValidationException::withMessages([
                'direction' => [__('Bu cari hesap tipi için bu yönde ödeme kaydedilemez.')],
            ]);
        }
    }

    protected function ensureDocumentEligible(Document $document, string $direction, string $currencyCode): void
    {
        $expectedTypes = $direction === 'in'
            ? [Document::TYPE_INVOICE, Document::TYPE_RETURN]
            : [Document::TYPE_PURCHASE, Document::TYPE_RETURN];

        if (in_array($document->status, ['draft', 'cancelled'], true) || ! in_array($document->type, $expectedTypes, true)) {
            throw ValidationException::withMessages([
                'document_id' => [__('Seçilen belge bu ödeme için uygun değil.')],
            ]);
        }

        if ($document->currency_code !== $currencyCode) {
            throw ValidationException::withMessages([
                'currency_code' => [__('Ödeme para birimi belge para birimiyle aynı olmalıdır.')],
            ]);
        }
    }

    /**
     * Belgenin tahsilat durumunu bağlı ödemelerden yeniden hesaplar.
     *
     * Gövde `DocumentSettlement`'a taşındı : online tahsilat
     * (ödeme linki bildirimi) de AYNI kuralı kullanmak zorunda; iki ayrı hesap
     * bir süre sonra birbirinden sapar.
     */
    protected function recalculateDocumentStatus(Document $document): string
    {
        return DocumentSettlement::recalculate($document);
    }

    /**
     * Ödeme kuru .
     *
     * Öncelik: açıkça verilen `exchange_rate` > `rate_source` seçimi > varsayılan.
     * Varsayılan belgeye bağlıysa BELGE kurudur (fotoğraf — eski davranışla aynı);
     * belgesiz ödemede 1. `document` seçeneği belgesiz istenirse 422: neyin kuru
     * olduğu belirsizken kayıt yazılmaz.
     */
    protected function resolvePaymentRate(Request $request, array $data, ?Document $document, string $currency): float
    {
        $source = $data['rate_source'] ?? null;

        if ($source === 'current') {
            return round((float) (ExchangeRates::rateFor($currency) ?? 1), 6);
        }

        if ($source === 'document' && $document === null) {
            throw ValidationException::withMessages([
                'rate_source' => [__('Belgeye bağlı olmayan ödemede belge kuru seçilemez.')],
            ]);
        }

        if ($document !== null && $document->currency_code === $currency) {
            return round((float) ($document->exchange_rate ?: 1), 6);
        }

        return 1.0;
    }

    protected function presentRow(Payment $payment): array
    {
        $paidAt = $payment->paid_at;
        $paidAt = $paidAt instanceof \DateTimeInterface ? $paidAt->format('Y-m-d') : $paidAt;

        return [
            'id' => $payment->id,
            'party_id' => $payment->party_id,
            'party_name' => $payment->getAttribute('party_name') ?? $payment->party?->name,
            'party_code' => $payment->getAttribute('party_code') ?? $payment->party?->code,
            'document_id' => $payment->document_id,
            'document_serial' => $payment->getAttribute('document_serial') ?? $payment->document?->serial,
            'document_type' => $payment->getAttribute('document_type') ?? $payment->document?->type,
            'bank_account_id' => $payment->bank_account_id,
            'bank_account_name' => $payment->getAttribute('bank_account_name') ?? $payment->bankAccount?->name,
            'direction' => $payment->direction,
            'amount' => $payment->amount,
            'currency_code' => $payment->currency_code,
            'exchange_rate' => $payment->exchange_rate,
            'method' => $payment->method,
            'paid_at' => $paidAt,
            'reference' => $payment->reference,
            'note' => $payment->note,
            'created_at' => $payment->created_at,
        ];
    }
}
