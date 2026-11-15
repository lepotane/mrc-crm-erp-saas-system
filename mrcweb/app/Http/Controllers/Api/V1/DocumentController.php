<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PresentsPaginator;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Audit;
use App\Support\ExchangeRates;
use App\Support\PlanLimits;
use App\Support\ReportCache;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentController extends Controller
{
    use PresentsPaginator, ResolvesTenant;

    /**
     * Belgeleri listele: teklif, sipariş, satış faturası, alım faturası (durum/tip/cari/ara süzgeçleri, sayfalı)
     *
     * @response array{
     *     data: array<int,
     *     array{id: int, type: string, serial: string, status: string, issue_date: string, due_date: string|null, currency_code: string, exchange_rate: string, subtotal: string, discount_total: string, tax_total: string, grand_total: string, party_id: int, party_name: string, party_code: string, warehouse_id: int, notes: string|null, items_count: int, converted_from_id: int|null, created_at: string}>,
     *     links: array<int,
     *     array{url: string|null, label: string, page: int|null, active: bool}>,
     *     meta: array{current_page: int, from: int, last_page: int, per_page: int, to: int, total: float},
     * }
     */
    public function index(Request $request)
    {
        $companyId = $this->tenantId($request);

        $query = Document::query()
            ->where('documents.company_id', $companyId)
            ->leftJoin('parties', 'parties.id', '=', 'documents.party_id')
            ->selectRaw('documents.*, parties.name AS party_name, parties.code AS party_code')
            ->withCount('items');

        if ($type = $request->query('type')) {
            $query->where('documents.type', in_array($type, Document::TYPES, true) ? $type : '__yok__');
        }
        if ($status = $request->query('status')) {
            $query->where('documents.status', $status);
        }
        if ($partyId = $request->query('party_id')) {
            $query->where('documents.party_id', (int) $partyId);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('documents.issue_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('documents.issue_date', '<=', $to);
        }
        if (($search = trim((string) $request->query('search'))) !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('documents.serial', 'like', "%{$search}%")
                    ->orWhere('parties.name', 'like', "%{$search}%");
            });
        }

        $paginator = $query->orderByDesc('documents.issue_date')
            ->orderByDesc('documents.id')
            ->paginate(min((int) ($request->query('per_page', 20) ?: 20), 100))
            ->withQueryString();

        $paginator->getCollection()->transform(fn (Document $d) => $this->presentRow($d));

        return response()->json($this->paginateJson($paginator));
    }

    /**
     * Yeni taslak belge oluştur (satırlar, iskonto ve KDV ile; seri numarası otomatik alınır)
     */
    #[Response(
        status: 201,
        type: 'array{ document: array{id: int, company_id: int, type: string, serial: string, status: string, currency_code: string, exchange_rate: string, subtotal: string, discount_total: string, tax_total: string, grand_total: string, party_id: int, warehouse_id: int, notes: string, converted_from_id: int|null, created_by: int, created_at: string, updated_at: string, issue_date: string, due_date: string, party_name: string, party_code: string, items_count: int}, items: array<int, array{id: int, document_id: int, product_id: int|null, sort_order: int, name: string, quantity: string, unit_price: string, discount_rate: string, tax_rate: string, line_subtotal: string, line_discount: string, line_tax: string, line_total: string, cogs: string|null}>, }',
    )]
    #[BodyParameter(
        name: 'type',
        description: 'quote | order | invoice | purchase (zorunlu)',
        required: true,
        type: 'string',
    )]
    #[BodyParameter(
        name: 'party_id',
        description: 'Cari kimliği — yalnız kendi firmanın cari seçilebilir',
        required: true,
        type: 'int',
    )]
    #[BodyParameter(
        name: 'items',
        description: 'Belge satırları (en az bir satır). name boşsa ürünün adı ve güncel satış fiyatı sunucudan alınır.',
        required: true,
        type: 'array<int, array{product_id?: int|null, name?: string|null, quantity: float, unit_price: float, discount_rate?: float, tax_rate?: float, sort_order?: int|null}>',
    )]
    #[BodyParameter(
        name: 'issue_date',
        description: 'Belge tarihi (Y-m-d) — boşsa bugün',
        type: 'string',
    )]
    #[BodyParameter(
        name: 'due_date',
        description: 'Vade tarihi (Y-m-d) — boşsa ayarlardaki varsayılan vade',
        type: 'string',
    )]
    #[BodyParameter(
        name: 'currency_code',
        description: 'TRY | USD | EUR — boşsa firmanın para birimi',
        type: 'string',
    )]
    #[BodyParameter(
        name: 'warehouse_id',
        description: 'İşlemin yapılacağı depo (ihraçta gerekli)',
        type: 'int',
    )]
    #[BodyParameter(name: 'notes', description: 'Belge notu', type: 'string')]
    public function store(Request $request)
    {
        $companyId = $this->tenantId($request);

        // Plan limiti : takvim ayı içinde kesilen belge sayısı. Dönüştürme
        // (convert) YENİ bir belgedir ve sayılır; güncelleme sayılmaz.
        PlanLimits::for($this->tenantCompany($request))
            ->assertWithinLimit('documents_per_month', $this->tenantCompany($request));

        $data = $this->validated($request, $companyId, false);

        $document = DB::transaction(function () use ($request, $companyId, $data) {
            $warehouseId = $this->resolveWarehouseId($request, $companyId, $data['warehouse_id'] ?? null);
            [$lines, $totals] = $this->buildLines($companyId, $data['items'], false, $this->defaultTaxRate($request));
            $issueDate = $data['issue_date'] ?? date('Y-m-d');

            // Vade boşsa: yalnız para hareketi doğuran belgelerde (fatura / alım
            // faturası) firmanın varsayılan vadesi uygulanır (Ayarlar → Genel Ayarlar).
            // Kullanıcı vade girdiyse ona dokunulmaz. Teklif/sipariş vadesiz kalabilir.
            $dueDate = $data['due_date'] ?? null;

            if ($dueDate === null && in_array($data['type'], [Document::TYPE_INVOICE, Document::TYPE_PURCHASE], true)) {
                $dueDate = $this->dueDateFromTerm($issueDate, $this->defaultPaymentDays($request));
            }

            $document = Document::create([
                'company_id' => $companyId,
                'type' => $data['type'],
                'serial' => Document::nextSerial($companyId, $data['type'], (int) substr($issueDate, 0, 4)),
                'party_id' => $data['party_id'],
                'warehouse_id' => $warehouseId,
                'status' => 'draft',
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'currency_code' => $currencyCode = ($data['currency_code'] ?? $this->companyCurrency($request)),
                // Kur fotoğrafı : işlem anında yazılır, sonradan değişmez.
                // TRY=1; dövizde o güne ait kur yoksa 1 (iş durmaz, kur gelince
                // YENİ belgeler doğru kesilir; eski belge geriye düzeltilmez).
                'exchange_rate' => ExchangeRates::rateFor($currencyCode, $issueDate) ?? 1,
                'rate_date' => $issueDate,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $this->persistLines($companyId, $document, $lines);

            return $document;
        });

        $document->load(['items', 'party']);

        ReportCache::bump($companyId);
        Audit::fromRequest($request, 'document.created', $companyId, $document, [
            'serial' => $document->serial,
            'type' => $document->type,
            'grand_total' => (float) $document->grand_total,
        ]);

        return response()->json([
            'document' => $this->present($document),
            'items' => $document->items->map(fn ($i) => $this->presentItem($i))->values(),
        ], 201);
    }

    /**
     * Belge detayı (satırlar, özet toplamlar, ödeme geçmişi)
     *
     * @response array{
     *     document: array{id: int, company_id: int, type: string, serial: string, status: string, currency_code: string, exchange_rate: string, subtotal: string, discount_total: string, tax_total: string, grand_total: string, party_id: int, warehouse_id: int, notes: string, converted_from_id: int|null, created_by: int, created_at: string, updated_at: string, issue_date: string, due_date: string, party_name: string, party_code: string, items_count: int},
     *     items: array<int,
     *     array{id: int, document_id: int, product_id: int|null, sort_order: int, name: string, quantity: string, unit_price: string, discount_rate: string, tax_rate: string, line_subtotal: string, line_discount: string, line_tax: string, line_total: string, cogs: string|null}>,
     * }
     */
    public function show(Request $request, Document $document)
    {
        $this->authorizeDocument($request, $document);
        $document->load(['items', 'party']);

        return response()->json([
            'document' => $this->present($document),
            'items' => $document->items->map(fn ($i) => $this->presentItem($i))->values(),
        ]);
    }

    /**
     * Taslak belgeyi düzenle (ihraç edilmiş belge düzenlenemez)
     *
     * @response array{
     *     document: array{id: int, company_id: int, type: string, serial: string, status: string, currency_code: string, exchange_rate: string, subtotal: string, discount_total: string, tax_total: string, grand_total: string, party_id: int, warehouse_id: int, notes: string, converted_from_id: int|null, created_by: int, created_at: string, updated_at: string, issue_date: string, due_date: string, party_name: string, party_code: string, items_count: int},
     *     items: array<int,
     *     array{id: int, document_id: int, product_id: int|null, sort_order: int, name: string, quantity: string, unit_price: string, discount_rate: string, tax_rate: string, line_subtotal: string, line_discount: string, line_tax: string, line_total: string, cogs: string|null}>,
     * }
     */
    #[BodyParameter(name: 'party_id', description: 'Cari kimliği', type: 'int')]
    #[BodyParameter(
        name: 'items',
        description: 'Satırlar gönderilirse tüm satırların yerine geçer',
        type: 'array<int, array{product_id?: int|null, name?: string|null, quantity: float, unit_price: float, discount_rate?: float, tax_rate?: float, sort_order?: int|null}>',
    )]
    #[BodyParameter(name: 'issue_date', description: 'Belge tarihi (Y-m-d)', type: 'string')]
    #[BodyParameter(name: 'due_date', description: 'Vade tarihi (Y-m-d)', type: 'string')]
    #[BodyParameter(name: 'currency_code', description: 'TRY | USD | EUR', type: 'string')]
    #[BodyParameter(name: 'warehouse_id', description: 'Depo kimliği', type: 'int')]
    #[BodyParameter(name: 'notes', description: 'Belge notu', type: 'string')]
    public function update(Request $request, Document $document)
    {
        $this->authorizeDocument($request, $document);

        // Düzenleme stok-nötr durumlarda serbesttir (taslak: hiç etki yok;
        // iptal: etki aynaya düşmüş). Kesilmiş belgede stok uygulanmış olur —
        // önce iptal edilir (stok döner) ya da kopyasıyla yeni belge açılır.
        if (! in_array($document->status, ['draft', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'status' => [__('Yalnız taslak ve iptal edilmiş belgeler düzenlenebilir. Kesilmiş belge için önce iptal edin ya da kopyasıyla yeni belge açın.')],
            ]);
        }

        $companyId = $this->tenantId($request);
        $data = $this->validated($request, $companyId, true);

        DB::transaction(function () use ($request, $companyId, $data, $document) {
            $patch = [];
            foreach (['party_id', 'issue_date', 'due_date', 'currency_code', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $patch[$field] = $data[$field];
                }
            }
            if (array_key_exists('warehouse_id', $data)) {
                $patch['warehouse_id'] = $this->resolveWarehouseId($request, $companyId, $data['warehouse_id']);
            }
            if (array_key_exists('items', $data)) {
                [$lines, $totals] = $this->buildLines($companyId, $data['items'], false, $this->defaultTaxRate($request));
                $patch += $totals;
                $document->items()->delete();
                $this->persistLines($companyId, $document, $lines);
            }
            if ($patch !== []) {
                $document->update($patch);
            }
        });

        $document->load(['items', 'party']);

        ReportCache::bump($companyId);
        Audit::fromRequest($request, 'document.updated', $companyId, $document, [
            'serial' => $document->serial,
            'type' => $document->type,
        ]);

        return response()->json([
            'document' => $this->present($document),
            'items' => $document->items->map(fn ($i) => $this->presentItem($i))->values(),
        ]);
    }

    /**
     * Belgeyi sil — HER durumda silinir .
     *
     * Tek işlemde, sırayla: kesilmişse stok aynaya düşer (`cancel:<id>` izli);
     * bağlı iade belgeleri aynıyla silinir; ödemeler denetim izli düşer
     * (seri+tutar audit'e yazılır, sessiz kayıp yok); dönüştürme bağları kopar
     * (türetilmiş belge DURUR, kaynak bilgisi gider); kuyruk işlerinin belge
     * bağı kopar; satırlar + belgenin kendisi silinir.
     *
     * @response array{message: string}
     */
    public function destroy(Request $request, Document $document)
    {
        $this->authorizeDocument($request, $document);
        $companyId = $this->tenantId($request);

        $serial = $document->serial;
        $type = $document->type;
        $silinen = ['belgeler' => [], 'odemeler' => []];

        DB::transaction(function () use ($request, $companyId, $document, &$silinen) {
            $this->silBelgeAgaci($companyId, $document->refresh(), (int) $request->user()->id, $silinen);
        });

        ReportCache::bump($companyId);
        Audit::fromRequest($request, 'document.deleted', $companyId, null, [
            'serial' => $serial,
            'type' => $type,
            'cascade' => $silinen,
        ]);

        return response()->json(['message' => __('Belge silindi.')]);
    }

    /**
     * Tek belge + alt ağacını sessiz kayıpsız düşürür .
     *
     * @param  array{belgeler: list<string>, odemeler: list<array{id: int, amount: float, method: string|null}>}  $silinen
     */
    protected function silBelgeAgaci(int $companyId, Document $document, int $userId, array &$silinen): void
    {
        // 1) Kesilmişse stok etkisini aynaya düşür (iptal ile aynı kural).
        if (in_array($document->status, ['issued', 'paid', 'partially_paid'], true)
            && in_array($document->type, [Document::TYPE_INVOICE, Document::TYPE_PURCHASE, Document::TYPE_RETURN], true)) {
            $warehouseId = $document->warehouse_id
                ?? $this->resolveWarehouseId(request(), $companyId, null);
            $this->applyCancelEffects($companyId, $document->refresh(), $warehouseId, $userId);
        }

        // 2) Bağlı iadeler aynıyla gider (derinlik en fazla 1: iadenin iadesi yoktur).
        $iadeler = Document::where('company_id', $companyId)
            ->where('source_document_id', $document->id)
            ->get();
        foreach ($iadeler as $iade) {
            $this->silBelgeAgaci($companyId, $iade, $userId, $silinen);
        }

        // 3) Ödemeler denetim iziyle düşer.
        $odemeler = Payment::where('company_id', $companyId)->where('document_id', $document->id)->get();
        foreach ($odemeler as $odeme) {
            $silinen['odemeler'][] = [
                'id' => $odeme->id,
                'amount' => (float) $odeme->amount,
                'method' => $odeme->method,
            ];
            $odeme->delete();
        }

        // 4) Dönüştürme bağları kopar (türetilmiş belge durur).
        Document::where('company_id', $companyId)
            ->where('converted_from_id', $document->id)
            ->update(['converted_from_id' => null]);

        // 5) Kuyruk işlerinin belge bağı kopar (satırlar durur).
        PrintJob::where('document_id', $document->id)->update(['document_id' => null]);

        $silinen['belgeler'][] = $document->serial;

        $document->items()->delete();
        $document->delete();
    }

    /**
     * Belge durumunu değiştir (ihraç / iptal) — ihraçta stok ve cari bir kez işlenir
     *
     * @response array{document: array{id: int, company_id: int, type: string, serial: string, status: string, currency_code: string, exchange_rate: string, subtotal: string, discount_total: string, tax_total: string, grand_total: string, party_id: int, warehouse_id: int, notes: string, converted_from_id: int|null, created_by: int, created_at: string, updated_at: string, issue_date: string, due_date: string, party_name: string, party_code: string, items_count: int}, stock_applied: bool}
     */
    public function changeStatus(Request $request, Document $document)
    {
        $this->authorizeDocument($request, $document);

        $data = $request->validate([
            'status' => ['required', 'string', 'max:20'],
        ]);

        $to = $data['status'];
        $from = $document->status;

        if (! $document->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => [__('Bu belge :from durumundan :to durumuna geçirilemez.', [
                    'from' => Document::statusLabel($document->status),
                    'to' => Document::statusLabel($to),
                ])],
            ]);
        }

        $companyId = $this->tenantId($request);
        $stockApplied = false;

        DB::transaction(function () use ($request, $companyId, $document, $from, $to, &$stockApplied) {
            // Satır kilidi + geçişin işlem İÇİNDE yeniden denetlenmesi: eşzamanlı iki
            // „İhraç et” isteği stok/maliyet etkisini iki kez uygulamamalı.
            Document::where('id', $document->id)->lockForUpdate()->first();
            $document->refresh();

            if (! $document->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => [__('Bu belge :from durumundan :to durumuna geçirilemez.', [
                        // Etiketler kilit İÇİNDE de çevrilmiş okunur adlardır: eşzamanlı iki
                        // „İhraç et”ten ikincisi bu yoldan döner ve kullanıcı ham anahtar
                        // (`draft`, `issued`) görmemeli .
                        'from' => Document::statusLabel($document->status),
                        'to' => Document::statusLabel($to),
                    ])],
                ]);
            }

            $patch = ['status' => $to];

            if ($to === 'issued' && in_array($document->type, [Document::TYPE_INVOICE, Document::TYPE_PURCHASE, Document::TYPE_RETURN], true)) {
                $warehouseId = $document->warehouse_id
                    ?? $this->resolveWarehouseId($request, $companyId, null);
                $patch['warehouse_id'] = $warehouseId;
                $document->update($patch);
                $stockApplied = $this->applyIssueEffects($companyId, $document->refresh(), $warehouseId, (int) $request->user()->id);
            } elseif ($to === 'cancelled' && $from === 'issued' && in_array($document->type, [Document::TYPE_INVOICE, Document::TYPE_PURCHASE, Document::TYPE_RETURN], true)) {
                // İptal, ihraçtaki stok etkisini TERSİNE çevirir : kesilmiş
                // fatura/alış/iade kaydı durur, mal hareketi aynaya düşer. Ödemesi
                // olan belge iptal edilemez — önce ödeme silinir (tahsilatsız
                // iptal, caride sahte alacak bırakırdı).
                if (Payment::where('company_id', $companyId)->where('document_id', $document->id)->exists()) {
                    throw ValidationException::withMessages([
                        'status' => [__('Ödemesi olan belge iptal edilemez. Önce ödemeyi silin.')],
                    ]);
                }

                $warehouseId = $document->warehouse_id
                    ?? $this->resolveWarehouseId($request, $companyId, null);
                $patch['warehouse_id'] = $warehouseId;
                $document->update($patch);
                $stockApplied = $this->applyCancelEffects($companyId, $document->refresh(), $warehouseId, (int) $request->user()->id);
            } else {
                $document->update($patch);
            }
        });

        $document->load(['party']);

        $payload = ['document' => $this->present($document->refresh())];
        if ($to === 'issued') {
            $payload['stock_applied'] = $stockApplied;
        }

        ReportCache::bump($companyId);
        Audit::fromRequest($request, 'document.status', $companyId, $document, [
            'serial' => $document->serial,
            'from' => $from,
            'to' => $to,
        ]);

        return response()->json($payload);
    }

    /**
     * Belgeyi bir üst türe dönüştür: teklif → sipariş → fatura / sipariş → alım faturası
     */
    #[Response(
        status: 201,
        type: 'array{ document: array{id: int, company_id: int, type: string, serial: string, status: string, currency_code: string, exchange_rate: string, subtotal: string, discount_total: string, tax_total: string, grand_total: string, party_id: int, warehouse_id: int, notes: string, converted_from_id: int|null, created_by: int, created_at: string, updated_at: string, issue_date: string, due_date: string, party_name: string, party_code: string, items_count: int}, items: array<int, array{id: int, document_id: int, product_id: int|null, sort_order: int, name: string, quantity: string, unit_price: string, discount_rate: string, tax_rate: string, line_subtotal: string, line_discount: string, line_tax: string, line_total: string, cogs: string|null}>, }',
    )]
    public function convert(Request $request, Document $document)
    {
        $this->authorizeDocument($request, $document);
        $companyId = $this->tenantId($request);

        $data = $request->validate([
            'to' => ['required', 'string', Rule::in([Document::TYPE_SALES_ORDER, Document::TYPE_INVOICE])],
        ]);

        $allowed = [
            Document::TYPE_QUOTE => [Document::TYPE_SALES_ORDER],
            Document::TYPE_SALES_ORDER => [Document::TYPE_INVOICE],
        ];

        if (! in_array($data['to'], $allowed[$document->type] ?? [], true)) {
            $message = $document->type === Document::TYPE_QUOTE && $data['to'] === Document::TYPE_INVOICE
                ? __('Teklif doğrudan faturaya dönüştürülemez; önce siparişe dönüştürün.')
                : __('Bu belge bu türe dönüştürülemez.');
            throw ValidationException::withMessages(['to' => [$message]]);
        }

        // Dönüşüm YENİ bir belgedir (yeni numara) → aylık belge limitine sayılır.
        // Geçersiz dönüşüm talebi önce kendi hatasıyla döner; limit mesajı onu gölgelemesin.
        PlanLimits::for($this->tenantCompany($request))
            ->assertWithinLimit('documents_per_month', $this->tenantCompany($request));

        $fresh = DB::transaction(function () use ($request, $companyId, $document, $data) {
            $document->loadMissing('items');
            $issueDate = date('Y-m-d');

            $fresh = Document::create([
                'company_id' => $companyId,
                'type' => $data['to'],
                'serial' => Document::nextSerial($companyId, $data['to'], (int) date('Y')),
                'party_id' => $document->party_id,
                'warehouse_id' => $document->warehouse_id,
                'status' => 'draft',
                'issue_date' => $issueDate,
                'due_date' => $document->due_date
                    ?? (($data['to'] === Document::TYPE_INVOICE || $data['to'] === Document::TYPE_PURCHASE)
                        // Karar 22: dönüştürme ile doğan fatura/alım da firmanın varsayılan
                        // vadesini alır (kaynak belgede vade yoksa).
                        ? $this->dueDateFromTerm($issueDate, $this->defaultPaymentDays($request))
                        : null),
                'currency_code' => $document->currency_code,
                'exchange_rate' => $document->exchange_rate,
                'notes' => $document->notes,
                'created_by' => $document->created_by,
                'converted_from_id' => $document->id,
            ]);

            $lines = $document->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'name' => $i->name,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'discount_rate' => (float) $i->discount_rate,
                'tax_rate' => (float) $i->tax_rate,
                'sort_order' => (int) $i->sort_order,
            ])->all();

            [$rebuilt, $totals] = $this->buildLines($companyId, $lines, true, $this->defaultTaxRate($request));
            $fresh->update($totals);
            $this->persistLines($companyId, $fresh, $rebuilt);

            return $fresh;
        });

        $fresh->load(['items', 'party']);

        ReportCache::bump($companyId);
        Audit::fromRequest($request, 'document.converted', $companyId, $fresh, [
            'from_serial' => $document->serial,
            'from_type' => $document->type,
            'to_serial' => $fresh->serial,
            'to_type' => $fresh->type,
        ]);

        return response()->json([
            'document' => $this->present($fresh),
            'items' => $fresh->items->map(fn ($i) => $this->presentItem($i))->values(),
        ], 201);
    }

    /**
     * Belgeyi kopyala: aynı içerikle yeni taslak açar (yanlış kesilmiş belgenin
     * doğrusunu üretmek için en hızlı yol: iptal et → kopyala → düzelt → ihraç et).
     *
     * Yeni seri alır, aylık belge limitine sayılır. Her durumda çalışır.
     *
     * @response array{document: array, items: array, message: string}
     */
    public function duplicate(Request $request, Document $document)
    {
        $this->authorizeDocument($request, $document);
        $companyId = $this->tenantId($request);

        PlanLimits::for($this->tenantCompany($request))
            ->assertWithinLimit('documents_per_month', $this->tenantCompany($request));

        $fresh = DB::transaction(function () use ($request, $companyId, $document) {
            $document->loadMissing('items');

            $fresh = Document::create([
                'company_id' => $companyId,
                'type' => $document->type,
                'serial' => Document::nextSerial($companyId, $document->type, (int) date('Y')),
                'party_id' => $document->party_id,
                'warehouse_id' => $document->warehouse_id,
                'status' => 'draft',
                'issue_date' => date('Y-m-d'),
                'due_date' => $document->due_date,
                'currency_code' => $document->currency_code,
                'exchange_rate' => $document->exchange_rate,
                'notes' => $document->notes,
                'created_by' => (int) $request->user()->id,
            ]);

            $lines = $document->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'name' => $i->name,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'discount_rate' => (float) $i->discount_rate,
                'tax_rate' => (float) $i->tax_rate,
                'sort_order' => (int) $i->sort_order,
            ])->all();

            [$rebuilt, $totals] = $this->buildLines($companyId, $lines, true, $this->defaultTaxRate($request));
            $fresh->update($totals);
            $this->persistLines($companyId, $fresh, $rebuilt);

            return $fresh;
        });

        $fresh->load(['items', 'party']);

        ReportCache::bump($companyId);

        Audit::fromRequest($request, 'document.duplicated', $companyId, $fresh, [
            'from_serial' => $document->serial,
            'to_serial' => $fresh->serial,
        ]);

        return response()->json([
            'document' => $this->present($fresh),
            'items' => $fresh->items->map(fn ($i) => $this->presentItem($i))->values(),
            'message' => __('Belge kopyalandı.'),
        ], 201);
    }

    /**
     * Faturadan iade aç: satırlar kaynak faturadan dolar (miktar budanabilir).
     *
     * Kaynak `invoice` (satış iadesi) ya da `purchase` (alış iadesi) olmalı;
     * satır miktarı, o üründe daha önce iade edilmemiş kalanı aşamaz.
     * Kur fotoğrafı kaynaktan aynen alınır (iade orijinal kurdan).
     *
     * @response array{document: array, items: array, message: string}
     */
    #[Response(
        status: 201,
        type: 'array{document: array{id: int, type: string, serial: string, status: string}, items: array<int, array{id: int, name: string, quantity: string}>, message: string}',
    )]
    public function returnFrom(Request $request, Document $document)
    {
        $this->authorizeDocument($request, $document);
        $companyId = $this->tenantId($request);

        if (! in_array($document->type, [Document::TYPE_INVOICE, Document::TYPE_PURCHASE], true)) {
            throw ValidationException::withMessages([
                'document_id' => [__('Yalnız kesilmiş faturadan iade açılır.')],
            ]);
        }

        if ($document->status === 'draft' || $document->status === 'cancelled') {
            throw ValidationException::withMessages([
                'document_id' => [__('Taslak ya da iptal belgeden iade açılmaz.')],
            ]);
        }

        $data = $request->validate([
            'lines' => ['sometimes', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $document->loadMissing('items');
        $wanted = collect($data['lines'] ?? $document->items->map(fn ($i) => [
            'product_id' => $i->product_id,
            'quantity' => (float) $i->quantity,
        ])->all())->keyBy('product_id');

        // Daha önce bu kaynaktan iade edilen miktarlar düşülür.
        $returned = DocumentItem::whereHas('document', fn ($q) => $q
            ->where('company_id', $companyId)
            ->where('source_document_id', $document->id)
            ->whereNull('deleted_at'))
            ->selectRaw('product_id, SUM(quantity) AS qty')
            ->whereIn('product_id', $wanted->keys()->all())
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $lines = [];

        foreach ($document->items as $source) {
            if ($source->product_id === null || ! $wanted->has($source->product_id)) {
                continue;
            }

            $kalan = round((float) $source->quantity - (float) ($returned[$source->product_id] ?? 0), 4);
            $istenen = round((float) $wanted[$source->product_id]['quantity'], 4);

            if ($istenen <= 0 || $kalan <= 0) {
                continue;
            }

            if ($istenen > $kalan) {
                throw ValidationException::withMessages([
                    'lines' => [__('İade miktarı kaynak satırı aşamaz: :name (kalan :qty)', [
                        'name' => $source->name, 'qty' => $kalan,
                    ])],
                ]);
            }

            $lines[] = [
                'product_id' => $source->product_id,
                'name' => $source->name,
                'quantity' => $istenen,
                // Fiyat/iskonto/KDV kaynak satırdan aynen taşınır.
                'unit_price' => (float) $source->unit_price,
                'discount_rate' => (float) $source->discount_rate,
                'tax_rate' => (float) $source->tax_rate,
                'sort_order' => $source->sort_order,
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => [__('İade edilecek satır kalmadı.')],
            ]);
        }

        PlanLimits::for($this->tenantCompany($request))
            ->assertWithinLimit('documents_per_month', $this->tenantCompany($request));

        [$built, $totals] = $this->buildLines($companyId, $lines, true, $this->defaultTaxRate($request));

        $issueDate = date('Y-m-d');

        $iade = DB::transaction(function () use ($request, $companyId, $document, $built, $totals, $issueDate, $data) {
            $warehouseId = $this->resolveWarehouseId($request, $companyId, $document->warehouse_id);

            $fresh = Document::create([
                'company_id' => $companyId,
                'type' => Document::TYPE_RETURN,
                'serial' => Document::nextSerial($companyId, Document::TYPE_RETURN, (int) date('Y')),
                'party_id' => $document->party_id,
                'warehouse_id' => $warehouseId,
                'status' => 'draft',
                'issue_date' => $issueDate,
                'due_date' => null,
                'currency_code' => $document->currency_code,
                'exchange_rate' => $document->exchange_rate,
                'rate_date' => substr((string) $document->rate_date, 0, 10),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
                'source_document_id' => $document->id,
            ]);

            $this->persistLines($companyId, $fresh, $built);

            return $fresh;
        });

        $iade->load(['items', 'party']);
        ReportCache::bump($companyId);
        Audit::fromRequest($request, 'document.created', $companyId, $iade, [
            'serial' => $iade->serial, 'type' => $iade->type, 'via' => 'return',
            'source_serial' => $document->serial,
        ]);

        return response()->json([
            'document' => $this->present($iade),
            'items' => $iade->items->map(fn ($i) => $this->presentItem($i))->values(),
            'message' => __('İade belgesi oluşturuldu.'),
        ], 201);
    }

    protected function authorizeDocument(Request $request, Document $document): void
    {
        abort_if(
            $document->company_id !== $this->tenantId($request),
            404,
            __('Belge bulunamadı.')
        );
    }

    protected function ensureDraft(Document $document, string $message): void
    {
        if (! $document->isDraft()) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    protected function validated(Request $request, int $companyId, bool $partial): array
    {
        $rules = [
            'party_id' => ['required', 'integer',
                Rule::exists('parties', 'id')->where('company_id', $companyId)],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency_code' => ['nullable', 'string', Rule::in(Currency::activeCodes())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.name' => ['nullable', 'string', 'max:190'],
            'items.*.quantity' => ['required', 'numeric'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            // Fiyat sınıfı: kalemin fiyatı hangi para birimi fiyatından geldi.
            'items.*.price_currency_code' => ['nullable', 'string', Rule::in(Currency::activeCodes())],
            'items.*.discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];

        if (! $partial) {
            $rules['type'] = ['required', 'string', Rule::in(Document::TYPES)];
        }

        return $request->validate($partial
            ? array_map(fn ($rule) => array_diff($rule, ['required']), $rules)
            : $rules);
    }

    /**
     * Satırları kurallara göre kurar, toplamları sunucuda hesaplar.
     * $fromConvert=true ise satırlar zaten normalize edilmiştir (ürün adı tekrar yazılmaz).
     * $defaultTaxRate: ürüne bağlı olmayan satırın KDV'si — firmanın varsayılan KDV
     * oranından gelir (Ayarlar → Genel Ayarlar). Null geçilirse %20'ye düşer.
     *
     * @return array{0: array, 1: array{subtotal: float, discount_total: float, tax_total: float, grand_total: float}}
     */
    protected function buildLines(int $companyId, array $items, bool $fromConvert = false, ?float $defaultTaxRate = null): array
    {
        $varsayilanKdv = $defaultTaxRate ?? 20.0;
        $productIds = collect($items)->pluck('product_id')->filter()->unique()->values()->all();
        $products = $productIds === []
            ? collect()
            : Product::where('company_id', $companyId)->whereIn('id', $productIds)->get()->keyBy('id');

        $lines = [];
        $totals = ['subtotal' => 0.0, 'discount_total' => 0.0, 'tax_total' => 0.0, 'grand_total' => 0.0];

        foreach (array_values($items) as $i => $row) {
            $product = ! empty($row['product_id']) ? ($products[$row['product_id']] ?? null) : null;

            $name = $fromConvert || ! $product
                ? trim((string) ($row['name'] ?? ''))
                : $product->name;

            if ($name === '') {
                throw ValidationException::withMessages([
                    "items.{$i}.name" => [__('Satır adı zorunludur.')],
                ]);
            }

            $quantity = (float) $row['quantity'];
            if ($quantity == 0.0) {
                throw ValidationException::withMessages([
                    "items.{$i}.quantity" => [__('Satır miktarı 0 olamaz.')],
                ]);
            }

            // v1’de iade/iskonto (credit note) belgesi yok: negatif satır, ihraçta stok
            // etkisini ters yönde iki kez üretebildiği için kabul edilmez. Miktar
            // düşürmek isteyen yeni belge keser; sayım farkı Stok → Düzeltme ile işlenir.
            if ($quantity < 0) {
                throw ValidationException::withMessages([
                    "items.{$i}.quantity" => [__('Satır miktarı negatif olamaz.')],
                ]);
            }

            $unitPrice = (float) $row['unit_price'];
            $discountRate = (float) ($row['discount_rate'] ?? 0);
            $taxRate = isset($row['tax_rate'])
                ? (float) $row['tax_rate']
                : ($product ? (float) $product->tax_rate : $varsayilanKdv);

            $lineSubtotal = round($quantity * $unitPrice, 4);
            $lineDiscount = round($lineSubtotal * $discountRate / 100, 4);
            $lineTax = round(($lineSubtotal - $lineDiscount) * $taxRate / 100, 4);
            $lineTotal = round($lineSubtotal - $lineDiscount + $lineTax, 4);

            $lines[] = [
                'product_id' => $product?->id,
                'name' => $name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'price_currency_code' => isset($row['price_currency_code'])
                    ? strtoupper((string) $row['price_currency_code'])
                    : null,
                'discount_rate' => $discountRate,
                'tax_rate' => $taxRate,
                'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : $i,
                'line_subtotal' => $lineSubtotal,
                'line_discount' => $lineDiscount,
                'line_tax' => $lineTax,
                'line_total' => $lineTotal,
            ];

            $totals['subtotal'] = round($totals['subtotal'] + $lineSubtotal, 4);
            $totals['discount_total'] = round($totals['discount_total'] + $lineDiscount, 4);
            $totals['tax_total'] = round($totals['tax_total'] + $lineTax, 4);
            $totals['grand_total'] = round($totals['grand_total'] + $lineTotal, 4);
        }

        return [$lines, $totals];
    }

    protected function persistLines(int $companyId, Document $document, array $lines): void
    {
        foreach ($lines as $line) {
            $document->items()->create($line + ['company_id' => $companyId]);
        }
    }

    /**
     * Verilen depo yoksa firmanın varsayılan deposu; hiçbiri yoksa 422 (TR).
     */
    protected function resolveWarehouseId(Request $request, int $companyId, mixed $given): ?int
    {
        if ($given !== null) {
            $exists = Warehouse::where('company_id', $companyId)->where('id', (int) $given)->exists();
            if (! $exists) {
                throw ValidationException::withMessages([
                    'warehouse_id' => [__('Depo bulunamadı.')],
                ]);
            }

            return (int) $given;
        }

        $defaultId = Warehouse::where('company_id', $companyId)
            ->orderByDesc('is_default')->orderBy('id')
            ->value('id');

        if ($defaultId === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => [__('Stok işlemi için depo gerekli; varsayılan depo bulunamadı.')],
            ]);
        }

        return (int) $defaultId;
    }

    /**
     * issued geçişinde stok etkisi (tek sefer, transaction içinde).
     * invoice → out + COGS; purchase → in + ağırlıklı ortalama maliyet.
     */
    protected function applyIssueEffects(int $companyId, Document $document, int $warehouseId, int $userId): bool
    {
        if (! in_array($document->type, [Document::TYPE_INVOICE, Document::TYPE_PURCHASE, Document::TYPE_RETURN], true)) {
            return false;
        }

        $document->loadMissing('items');
        $applied = false;

        foreach ($document->items as $index => $item) {
            if ($item->product_id === null) {
                continue;
            }

            $product = Product::where('company_id', $companyId)->find($item->product_id);
            if (! $product || ! $product->track_inventory) {
                continue;
            }

            $qty = abs((float) $item->quantity);
            if ($qty <= 0) {
                continue;
            }

            // İndirim sonrası net birim fiyat
            $netUnit = round(((float) $item->line_subtotal - (float) $item->line_discount) / $qty, 4);
            $note = "{$document->serial} satır #".($index + 1);

            if ($document->type === Document::TYPE_INVOICE) {
                // Çıkış hareketinin birim maliyeti **satış fiyatı değil, o anki ortalama
                // maliyettir**: stock-movements-summary out_value’si maliyet toplamıdır,
                // ciro değil (ciro zaten raporun satış tarafında var).
                $unitCost = round((float) $product->cost_price, 4);

                StockMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'type' => StockMovement::TYPE_OUT,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'note' => $note,
                    'user_id' => $userId,
                ]);

                // Satış satırına hareket anındaki ortalama maliyet işlenir
                $item->update(['cogs' => $unitCost]);
            } elseif ($document->type === Document::TYPE_RETURN) {
                // İade: mal rafa o anki ortalama maliyetle döner. WAC bilerek
                // çalıştırılmaz (giriş maliyeti = ortalama → ortalama değişmez,
                // kâr raporunda iade cirosu birebir geri alınır).
                $unitCost = round((float) $product->cost_price, 4);

                StockMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'type' => StockMovement::TYPE_IN,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'note' => $note,
                    'reference' => 'return:'.$document->id,
                    'user_id' => $userId,
                ]);

                $item->update(['cogs' => $unitCost]);
            } else {
                // Önce WAC (hareket henüz yazılmadı; eski stok doğru okunur), sonra giriş kaydı
                $product->applyWeightedAverageCost($companyId, $qty, $netUnit);

                StockMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'type' => StockMovement::TYPE_IN,
                    'quantity' => $qty,
                    'unit_cost' => $netUnit,
                    'note' => $note,
                    'user_id' => $userId,
                ]);
            }

            $applied = true;
        }

        return $applied;
    }

    /**
     * İptal ters-stoğu : ihraçta düşen mal aynaya düşer.
     *
     * Miktar birebir geri döner (`cancel:<belge>` iziyle); BİRİM maliyet o anki
     * ortalamadır — WAC yeniden hesaplanmaz (alış iptalinde ortalama hafif
     * kayabilir; miktar her zaman tutar). İade iptali rafa giren malı raftan
     * düşürür. cogs satırları EL DEĞMEZ (tarihsel fotoğraf korunur).
     */
    protected function applyCancelEffects(int $companyId, Document $document, int $warehouseId, int $userId): bool
    {
        if (! in_array($document->type, [Document::TYPE_INVOICE, Document::TYPE_PURCHASE, Document::TYPE_RETURN], true)) {
            return false;
        }

        $document->loadMissing('items');
        $applied = false;

        // Ayna yön: satış çıkışı → giriş; alış/iade girişi → çıkış.
        $mirror = $document->type === Document::TYPE_INVOICE
            ? StockMovement::TYPE_IN
            : StockMovement::TYPE_OUT;

        foreach ($document->items as $index => $item) {
            if ($item->product_id === null) {
                continue;
            }

            $product = Product::where('company_id', $companyId)->find($item->product_id);
            if (! $product || ! $product->track_inventory) {
                continue;
            }

            $qty = abs((float) $item->quantity);
            if ($qty <= 0) {
                continue;
            }

            StockMovement::create([
                'company_id' => $companyId,
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'type' => $mirror,
                'quantity' => $qty,
                'unit_cost' => round((float) $product->cost_price, 4),
                'note' => "{$document->serial} iptal satır #".($index + 1),
                'reference' => 'cancel:'.$document->id,
                'user_id' => $userId,
            ]);

            $applied = true;
        }

        return $applied;
    }

    protected function presentRow(Document $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type,
            'serial' => $document->serial,
            'status' => $document->status,
            'issue_date' => $document->issue_date?->format('Y-m-d'),
            'due_date' => $document->due_date?->format('Y-m-d'),
            'currency_code' => $document->currency_code,
            'exchange_rate' => $document->exchange_rate,
            'subtotal' => $document->subtotal,
            'discount_total' => $document->discount_total,
            'tax_total' => $document->tax_total,
            'grand_total' => $document->grand_total,
            'party_id' => $document->party_id,
            'party_name' => $document->getAttribute('party_name') ?? $document->party?->name,
            'party_code' => $document->getAttribute('party_code') ?? $document->party?->code,
            'warehouse_id' => $document->warehouse_id,
            'notes' => $document->notes,
            'items_count' => $document->getAttribute('items_count') ?? $document->items()->count(),
            'converted_from_id' => $document->converted_from_id,
            'created_at' => $document->created_at,
        ];
    }

    protected function present(Document $document): array
    {
        $data = $document->only([
            'id', 'company_id', 'type', 'serial', 'status',
            'currency_code', 'exchange_rate', 'rate_date',
            'subtotal', 'discount_total', 'tax_total', 'grand_total',
            'party_id', 'warehouse_id', 'notes', 'converted_from_id', 'source_document_id',
            'created_by', 'created_at', 'updated_at',
        ]);

        $data['issue_date'] = $document->issue_date?->format('Y-m-d');
        $data['due_date'] = $document->due_date?->format('Y-m-d');
        $data['rate_date'] = $document->rate_date?->format('Y-m-d');
        $data['party_name'] = $document->party?->name;
        $data['party_code'] = $document->party?->code;
        $data['items_count'] = $document->relationLoaded('items')
            ? $document->items->count()
            : $document->items()->count();

        return $data;
    }

    protected function presentItem($item): array
    {
        return [
            'id' => $item->id,
            'document_id' => $item->document_id,
            'product_id' => $item->product_id,
            'sort_order' => $item->sort_order,
            'name' => $item->name,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'discount_rate' => $item->discount_rate,
            'tax_rate' => $item->tax_rate,
            'line_subtotal' => $item->line_subtotal,
            'line_discount' => $item->line_discount,
            'line_tax' => $item->line_tax,
            'line_total' => $item->line_total,
            'cogs' => $item->cogs,
        ];
    }
}
