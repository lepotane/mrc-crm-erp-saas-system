<?php

namespace App\Dashboard;

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyJoinRequest;
use App\Models\Currency;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\ExchangeRate;
use App\Models\Party;
use App\Models\Payment;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Widget kataloğu + veri çözümleyiciler .
 *
 * Katalog SUNUCUDA kayıtlıdır: yeni widget (çekirdek ya da V2-8 plugin'i) buraya
 * bir satırla kaydolur; istemci katalog dışı anahtar uyduramaz. Her widget'ın
 * veri üreticisi (`data`) aynı dosyadadır — rapor mantığının ikinci kopyası değil,
 * pano-ölçekli ÖZET sorgulardır (limitli, sayfasız).
 *
 * V2-8: açık eklentilerin widget'ları kataloğa eklenir (`catalog($company)`).
 * Eklenti widget verisi `ModuleRegistry::pluginWidgetData`tan gelir (modül
 * config'i; ayrı tablo yok). Firma verilmezse yalnız çekirdek katalog döner.
 *
 * Para birimi notu: tutarlar para birimine göre AYRILIR, toplanmaz (USD+TRY
 * toplanırsa anlamsız sayı çıkar). Tüketici (SPA) birimi seçer.
 */
class WidgetRegistry
{
    /**
     * @return array<string, array{title_key: string, description_key: string, default_enabled: bool, roles: array<int,string>, size: string, data: callable}>
     */
    public static function catalog(?Company $company = null): array
    {
        return array_merge(static::coreCatalog(), $company === null ? [] : ModuleRegistry::pluginWidgets($company));
    }

    /** Çekirdek 7 widget (firmadan bağımsız, her zaman aynı). */
    protected static function coreCatalog(): array
    {
        return [
            'kpi' => [
                'title_key' => 'KPI Hapları',
                'description_key' => 'Bu ay ciro, açık alacak/borç özeti',
                'module' => 'pano',
                'default_enabled' => true,
                'roles' => ['Admin', 'Personel'],
                'size' => 'wide',
                'data' => [static::class, 'kpi'],
            ],
            'revenue_trend' => [
                'title_key' => 'Ciro Trendi',
                'description_key' => 'Son 6 ay satış grafiği',
                'module' => 'belgeler',
                'default_enabled' => true,
                'roles' => ['Admin', 'Personel'],
                'size' => 'wide',
                'data' => [static::class, 'revenueTrend'],
            ],
            'due_calendar' => [
                'title_key' => 'Vade Takvimi',
                'description_key' => 'Önümüzdeki 30 günün vadeleri',
                'module' => 'belgeler',
                'default_enabled' => true,
                'roles' => ['Admin', 'Personel'],
                'size' => 'half',
                'data' => [static::class, 'dueCalendar'],
            ],
            'fx_rates' => [
                'title_key' => 'Kur Göstergesi',
                'description_key' => 'Seçili paraların güncel kuru ',
                'module' => 'doviz',
                'default_enabled' => true,
                'roles' => ['Admin', 'Personel'],
                'size' => 'half',
                'data' => [static::class, 'fxRates'],
            ],
            'low_stock' => [
                'title_key' => 'Kritik Stok',
                'description_key' => 'Minimumun altına düşen ürünler',
                'module' => 'stok',
                'default_enabled' => true,
                'roles' => ['Admin', 'Personel'],
                'size' => 'half',
                'data' => [static::class, 'lowStock'],
            ],
            'recent_activity' => [
                'title_key' => 'Son Hareketler',
                'description_key' => 'Denetim kaydından son olaylar',
                'module' => 'pano',
                'default_enabled' => true,
                'roles' => ['Admin'],
                'size' => 'half',
                'data' => [static::class, 'recentActivity'],
            ],
            'debt_summary' => [
                'title_key' => 'Borç/Alacak Özeti',
                'description_key' => 'Müşteri/tedarikçi açık toplamları',
                'module' => 'borc-alacak',
                'default_enabled' => true,
                'roles' => ['Admin', 'Personel'],
                'size' => 'half',
                'data' => [static::class, 'debtSummary'],
            ],
            // --- Panodaki bento kartları (07 Kas TaskTrail v3 tasarımı) ---
            // Bu kartlar daha önce istemcide UYDURMA sayılarla çiziliyordu
            // (₺418,920 nakit akışı, 780 işlem/ay, 89,3 cari başına ortalama).
            // Canlıda yanlış rakam göstermemek için veri sunucudan gelir.
            'year_finance' => [
                'title_key' => 'Bu Yılki Finans',
                'description_key' => 'Yıllık ciro, tahsilat ve cari metrikleri',
                'module' => 'pano',
                'default_enabled' => true,
                'roles' => ['Admin', 'Personel'],
                'size' => 'half',
                'data' => [static::class, 'yearFinance'],
            ],
            'year_months' => [
                'title_key' => 'Yıllık Kâr Analizi',
                'description_key' => 'Son 12 ayın ciro/tahsilat/kâr kırılımı',
                'module' => 'pano',
                'default_enabled' => true,
                'roles' => ['Admin', 'Personel'],
                'size' => 'wide',
                'data' => [static::class, 'yearMonths'],
            ],
            'cash_flow' => [
                'title_key' => 'Nakit Akışı Dalga Grafiği',
                'description_key' => 'Son 6 ay giren/çıkan nakit',
                'module' => 'pano',
                'default_enabled' => true,
                'roles' => ['Admin', 'Personel'],
                'size' => 'wide',
                'data' => [static::class, 'cashFlow'],
            ],
            'category_mix' => [
                'title_key' => 'Kategori Pasta Grafiği',
                'description_key' => 'Ciro dağılımı (ürün kategorileri)',
                'module' => 'pano',
                'default_enabled' => true,
                'roles' => ['Admin', 'Personel'],
                'size' => 'half',
                'data' => [static::class, 'categoryMix'],
            ],
            'debt_aging' => [
                'title_key' => 'Alacak & Borç Yaşlandırma',
                'description_key' => 'Vade dilimlerine göre açık bakiye',
                'module' => 'borc-alacak',
                'default_enabled' => true,
                'roles' => ['Admin', 'Personel'],
                'size' => 'half',
                'data' => [static::class, 'debtAging'],
            ],
            'top_customers' => [
                'title_key' => 'Lider Müşteriler',
                'description_key' => 'En yüksek hacimli cariler',
                'module' => 'pano',
                'default_enabled' => true,
                'roles' => ['Admin', 'Personel'],
                'size' => 'half',
                'data' => [static::class, 'topCustomers'],
            ],
            'hr_applications' => [
                'title_key' => 'İK & Katılım Başvuruları',
                'description_key' => 'Başvuru ve CV havuzu özeti',
                'module' => 'pano',
                'default_enabled' => true,
                'roles' => ['Admin'],
                'size' => 'half',
                'data' => [static::class, 'hrApplications'],
            ],
        ];
    }

    public static function keys(?Company $company = null): array
    {
        return array_keys(static::catalog($company));
    }

    public static function exists(string $key): bool
    {
        return isset(static::coreCatalog()[$key]);
    }

    /** @return array<string, mixed> */
    public static function data(string $key, Company $company): array
    {
        $entry = static::catalog($company)[$key] ?? null;

        if ($entry === null) {
            return [];
        }

        if (! isset($entry['data'])) {
            // Eklenti widget'ı: veri modül config'inden gelir .
            return ModuleRegistry::pluginWidgetData($key, $company) ?? [];
        }

        return (array) call_user_func($entry['data'], $company);
    }

    /** Bu ay kesilmiş ihraç faturaları + açık alacak/borç (para birimi bazında). */
    protected static function kpi(Company $company): array
    {
        $ayBasi = now()->startOfMonth()->format('Y-m-d');

        // Yalnızca kesinleşmiş nihai satış faturaları ciro sayılır (sipariş mükerrerliği önlenir)
        $ciro = Document::where('company_id', $company->id)
            ->where('type', Document::TYPE_INVOICE)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereNull('deleted_at')
            ->whereDate('issue_date', '>=', $ayBasi)
            ->selectRaw('currency_code, COUNT(*) AS belge, SUM(grand_total) AS toplam')
            ->groupBy('currency_code')
            ->get();

        // Eğer henüz kesilmiş fatura yoksa ancak onaylı sipariş varsa siparişi göster
        if ($ciro->isEmpty()) {
            $ciro = Document::where('company_id', $company->id)
                ->where('type', Document::TYPE_SALES_ORDER)
                ->whereIn('status', ['confirmed'])
                ->whereNull('deleted_at')
                ->whereDate('issue_date', '>=', $ayBasi)
                ->selectRaw('currency_code, COUNT(*) AS belge, SUM(grand_total) AS toplam')
                ->groupBy('currency_code')
                ->get();
        }

        $acik = static::openTotals($company);

        return [
            'revenue_month' => $ciro->map(fn ($r) => [
                'currency' => $r->currency_code,
                'documents' => (int) $r->belge,
                'total' => round((float) $r->toplam, 2),
            ])->values()->all(),
            'receivable' => $acik['receivable'],
            'payable' => $acik['payable'],
        ];
    }

    /** Son 6 ay satış (para birimi + ay kırılımı). Gruplama PHP tarafında yapılır:
     *  DATE_FORMAT/strftime sürücüye göredir, sqlite+MySQL taşınabilirliği bozulur
     *  (rapor sözleşmesi kuralı — bkz. ReportController başlığı). */
    protected static function revenueTrend(Company $company): array
    {
        $baslangic = now()->startOfMonth()->subMonths(5)->format('Y-m-d');

        $rows = Document::where('company_id', $company->id)
            ->where('type', Document::TYPE_INVOICE)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereNull('deleted_at')
            ->where('issue_date', '>=', $baslangic)
            ->get(['issue_date', 'currency_code', 'grand_total']);

        if ($rows->isEmpty()) {
            $rows = Document::where('company_id', $company->id)
                ->where('type', Document::TYPE_SALES_ORDER)
                ->whereIn('status', ['confirmed'])
                ->whereNull('deleted_at')
                ->where('issue_date', '>=', $baslangic)
                ->get(['issue_date', 'currency_code', 'grand_total']);
        }

        $groups = [];

        foreach ($rows as $d) {
            $ay = substr((string) $d->issue_date, 0, 7);
            $key = $ay.'|'.$d->currency_code;
            $groups[$key] = round(($groups[$key] ?? 0) + (float) $d->grand_total, 2);
        }

        ksort($groups);

        $points = [];

        foreach ($groups as $key => $total) {
            [$ay, $ccy] = explode('|', $key);
            $points[] = ['month' => $ay, 'currency' => $ccy, 'total' => $total];
        }

        return ['points' => $points];
    }

    /** 30 gün içindeki vadeler ve açık siparişler/faturalar. */
    protected static function dueCalendar(Company $company): array
    {
        $bugun = now()->subDays(60)->format('Y-m-d');
        $bitis = now()->addDays(60)->format('Y-m-d');

        // Açık belgeleri çek: Eğer faturası kesilmişse siparişi hariç tut
        $invoiceConvertedIds = Document::where('company_id', $company->id)
            ->where('type', Document::TYPE_INVOICE)
            ->whereNotNull('converted_from_id')
            ->pluck('converted_from_id')
            ->all();

        $docs = Document::where('company_id', $company->id)
            ->whereIn('type', [Document::TYPE_INVOICE, Document::TYPE_SALES_ORDER])
            ->whereIn('status', ['confirmed', 'issued', 'partially_paid', 'paid', 'sent'])
            ->whereNotIn('id', $invoiceConvertedIds)
            ->whereNull('deleted_at')
            ->with('party:id,name')
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'serial', 'type', 'status', 'party_id', 'grand_total', 'issue_date', 'due_date', 'currency_code']);

        $paidMap = static::paidMap($company, $docs->pluck('id')->all());

        return [
            'items' => $docs->map(function (Document $d) use ($paidMap) {
                $dueDate = $d->due_date?->format('Y-m-d') ?: ($d->issue_date?->format('Y-m-d') ?: now()->format('Y-m-d'));
                $paid = $paidMap[$d->id] ?? 0;
                $openAmount = in_array($d->status, ['paid'], true)
                    ? (float) $d->grand_total
                    : round(max(0, (float) $d->grand_total - $paid), 2);
                if ($openAmount <= 0) {
                    $openAmount = (float) $d->grand_total;
                }

                return [
                    'document_id' => $d->id,
                    'serial' => $d->serial,
                    'type' => $d->type,
                    'status' => $d->status,
                    'party_name' => $d->party?->name ?: 'Kayıtlı Müşteri',
                    'due_date' => $dueDate,
                    'currency' => $d->currency_code,
                    'open_amount' => $openAmount,
                ];
            })->all(),
        ];
    }

    /** V2-9 kurlarının pano özeti. */
    protected static function fxRates(Company $company): array
    {
        $today = now()->format('Y-m-d');

        return [
            'items' => Currency::active()
                ->where('code', '!=', 'TRY')
                ->orderBy('sort_order')
                ->get(['code', 'symbol'])
                ->map(function ($c) use ($today) {
                    $row = ExchangeRate::where('currency_code', $c->code)
                        ->where('rate_date', '<=', $today)
                        ->orderByDesc('rate_date')
                        ->first();

                    return [
                        'currency' => $c->code,
                        'symbol' => $c->symbol,
                        'rate' => $row === null ? null : (float) $row->rate,
                        'rate_date' => $row === null ? null : substr((string) $row->rate_date, 0, 10),
                        'source' => $row?->source,
                    ];
                })->all(),
        ];
    }

    /** Minimumun altındaki ürünler (kritik stok). Stok hareketlerden hesaplanır. */
    protected static function lowStock(Company $company): array
    {
        // Stok saklanmaz, hareketlerden toplanır (stock-valuation ile aynı tanım).
        $stockSql = '(SELECT COALESCE(SUM(CASE WHEN sm.type = \'in\' THEN sm.quantity'
            .' WHEN sm.type = \'out\' THEN -sm.quantity ELSE sm.quantity END), 0)'
            .' FROM stock_movements sm WHERE sm.product_id = products.id'
            .' AND sm.company_id = products.company_id)';

        // HAVING toplu-sorgusuz çalışmaz (sqlite): koşul whereRaw ile subquery'nin
        // tekrarıdır — MySQL/SQLite ikisinde de geçerli.
        $rows = DB::table('products')
            ->where('products.company_id', $company->id)
            ->where('products.track_inventory', true)
            ->selectRaw("products.id, products.name, products.min_stock, {$stockSql} AS stock_quantity")
            ->whereRaw("({$stockSql}) < products.min_stock")
            ->orderBy('stock_quantity')
            ->limit(8)
            ->get();

        return [
            'items' => $rows->map(fn ($r) => [
                'product_id' => (int) $r->id,
                'name' => $r->name,
                'stock' => round((float) $r->stock_quantity, 4),
                'minimum' => round((float) $r->min_stock, 4),
            ])->all(),
        ];
    }

    /** Son denetim olayları (yalnız Admin havuzunda). */
    protected static function recentActivity(Company $company): array
    {
        $rows = AuditEvent::where('company_id', $company->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return [
            'items' => $rows->map(fn (AuditEvent $e) => [
                'action' => $e->action,
                'action_label' => AuditEvent::label($e->action),
                'user_name' => $e->user?->name,
                'created_at' => $e->created_at?->format('Y-m-d H:i'),
            ])->all(),
        ];
    }

    /** Açık alacak/borç toplamları (para birimi bazında). */
    protected static function debtSummary(Company $company): array
    {
        return static::openTotals($company);
    }

    /**
     * Açık belge toplamları: belge - bağlı tahsilat/ödeme.
     * İki sorgu (belgeler + ödemeler) — satır başına sorgu yok .
     *
     * @return array{receivable: array, payable: array}
     */
    protected static function openTotals(Company $company): array
    {
        $docs = Document::where('company_id', $company->id)
            ->whereIn('type', [Document::TYPE_INVOICE, Document::TYPE_PURCHASE])
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereNull('deleted_at')
            ->get(['id', 'type', 'currency_code', 'grand_total']);

        $paidMap = static::paidMap($company, $docs->pluck('id')->all());

        $receivable = [];
        $payable = [];

        foreach ($docs as $d) {
            $open = max(0, round((float) $d->grand_total - ($paidMap[$d->id] ?? 0), 2));

            if ($open <= 0) {
                continue;
            }

            if ($d->type === Document::TYPE_INVOICE) {
                $receivable[$d->currency_code] = round(($receivable[$d->currency_code] ?? 0) + $open, 2);
            } else {
                $payable[$d->currency_code] = round(($payable[$d->currency_code] ?? 0) + $open, 2);
            }
        }

        $map = fn (array $m) => collect($m)->map(fn ($v, $k) => ['currency' => $k, 'total' => $v])->values()->all();

        return ['receivable' => $map($receivable), 'payable' => $map($payable)];
    }

    /** Belge id → bağlı ödeme toplamı (tek sorgu). */
    protected static function paidMap(Company $company, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Payment::where('company_id', $company->id)
            ->whereIn('document_id', $ids)
            ->selectRaw('document_id, SUM(amount) AS toplam')
            ->groupBy('document_id')
            ->pluck('toplam', 'document_id')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    // ------------------------------------------------------------------
    // BENTO KARTLARI (TaskTrail v3) — gerçek veri üreticileri
    // ------------------------------------------------------------------

    /**
     * Bu yılki finans metrikleri (ciro, tahsilat, ortalama işlem, yeni cari).
     *
     * @return array<string, mixed>
     */
    protected static function yearFinance(Company $company): array
    {
        $yilBasi = now()->startOfYear()->format('Y-m-d');
        $gecenYilBasi = now()->subYear()->startOfYear()->format('Y-m-d');
        $gecenYilSonu = now()->subYear()->endOfYear()->format('Y-m-d');

        $faturalar = Document::where('company_id', $company->id)
            ->where('type', Document::TYPE_INVOICE)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereNull('deleted_at')
            ->whereDate('issue_date', '>=', $yilBasi)
            ->get(['id', 'party_id', 'currency_code', 'grand_total', 'issue_date']);

        $gecenYilFaturalar = Document::where('company_id', $company->id)
            ->where('type', Document::TYPE_INVOICE)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereNull('deleted_at')
            ->whereBetween('issue_date', [$gecenYilBasi, $gecenYilSonu])
            ->get(['party_id', 'currency_code', 'grand_total']);

        $tahsilatlar = Payment::where('company_id', $company->id)
            ->where('direction', Payment::DIRECTION_IN)
            ->whereDate('paid_at', '>=', $yilBasi)
            ->get(['amount', 'currency_code']);

        // Ciro para birimi bazında (farklı birimler toplanmaz).
        $ciro = [];
        $belgeSayisi = [];
        foreach ($faturalar as $d) {
            $ccy = $d->currency_code ?: 'TRY';
            $ciro[$ccy] = round(($ciro[$ccy] ?? 0) + (float) $d->grand_total, 2);
            $belgeSayisi[$ccy] = ($belgeSayisi[$ccy] ?? 0) + 1;
        }

        $tahsilat = [];
        foreach ($tahsilatlar as $p) {
            $ccy = $p->currency_code ?: 'TRY';
            $tahsilat[$ccy] = round(($tahsilat[$ccy] ?? 0) + (float) $p->amount, 2);
        }

        $gecenYilCiro = [];
        foreach ($gecenYilFaturalar as $d) {
            $ccy = $d->currency_code ?: 'TRY';
            $gecenYilCiro[$ccy] = round(($gecenYilCiro[$ccy] ?? 0) + (float) $d->grand_total, 2);
        }

        $cariSayisi = $faturalar->pluck('party_id')->filter()->unique()->count();
        $gecenYilCariSayisi = $gecenYilFaturalar->pluck('party_id')->filter()->unique()->count();
        $gecenYilBelgeSayisi = $gecenYilFaturalar->count();

        $yeniCariler = Party::where('company_id', $company->id)
            ->whereDate('created_at', '>=', $yilBasi)
            ->count();

        $gecenYilYeniCariler = Party::where('company_id', $company->id)
            ->whereBetween('created_at', [$gecenYilBasi, $gecenYilSonu])
            ->count();

        $liste = static::paraListesi($ciro);
        $ana = $liste[0] ?? ['currency' => 'TRY', 'total' => 0.0];
        $anaBelge = $belgeSayisi[$ana['currency']] ?? 0;

        $gecenYilListe = static::paraListesi($gecenYilCiro);
        $gecenYilAna = $gecenYilListe[0] ?? ['currency' => 'TRY', 'total' => 0.0];

        return [
            'revenue' => $liste,
            'collection' => static::paraListesi($tahsilat),
            'last_year_revenue' => $gecenYilListe,
            'documents' => $belgeSayisi,
            'avg_transaction' => $anaBelge > 0 ? round($ana['total'] / $anaBelge, 2) : 0.0,
            'avg_per_account' => $cariSayisi > 0 ? round($ana['total'] / $cariSayisi, 2) : 0.0,
            'account_count' => $cariSayisi,
            'new_accounts' => $yeniCariler,
            'last_year_avg_transaction' => $gecenYilBelgeSayisi > 0
                ? round($gecenYilAna['total'] / $gecenYilBelgeSayisi, 2)
                : 0.0,
            'last_year_avg_per_account' => $gecenYilCariSayisi > 0
                ? round($gecenYilAna['total'] / $gecenYilCariSayisi, 2)
                : 0.0,
            'last_year_new_accounts' => $gecenYilYeniCariler,
        ];
    }

    /** Son 12 ayın ciro/tahsilat/kâr kırılımı (ay kapsülleri için). */
    protected static function yearMonths(Company $company): array
    {
        $baslangic = now()->startOfMonth()->subMonths(11)->format('Y-m-d');

        $faturalar = Document::where('company_id', $company->id)
            ->where('type', Document::TYPE_INVOICE)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereNull('deleted_at')
            ->whereDate('issue_date', '>=', $baslangic)
            ->get(['id', 'issue_date', 'currency_code', 'grand_total']);

        $tahsilatlar = Payment::where('company_id', $company->id)
            ->where('direction', Payment::DIRECTION_IN)
            ->whereDate('paid_at', '>=', $baslangic)
            ->get(['paid_at', 'amount', 'currency_code']);

        // Satış maliyeti (COGS) — belge kalemlerinden.
        $cogs = [];
        $belgeIds = $faturalar->pluck('id')->all();
        if ($belgeIds !== []) {
            $cogs = DocumentItem::whereIn('document_id', $belgeIds)
                ->selectRaw('document_id, SUM(cogs) AS toplam')
                ->groupBy('document_id')
                ->pluck('toplam', 'document_id')
                ->map(fn ($v) => (float) $v)
                ->all();
        }

        $belgeAylari = [];
        $ciroAylari = [];
        $cogsAylari = [];
        foreach ($faturalar as $d) {
            $ay = substr((string) $d->issue_date, 0, 7);
            $ciroAylari[$ay] = round(($ciroAylari[$ay] ?? 0) + (float) $d->grand_total, 2);
            $belgeAylari[$ay] = ($belgeAylari[$ay] ?? 0) + 1;
            $cogsAylari[$ay] = round(($cogsAylari[$ay] ?? 0) + ($cogs[$d->id] ?? 0), 2);
        }

        $tahsilatAylari = [];
        foreach ($tahsilatlar as $p) {
            $ay = substr((string) $p->paid_at, 0, 7);
            $tahsilatAylari[$ay] = round(($tahsilatAylari[$ay] ?? 0) + (float) $p->amount, 2);
        }

        $adlar = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
        $aylar = [];

        for ($i = 11; $i >= 0; $i--) {
            $t = now()->startOfMonth()->subMonths($i);
            $kod = $t->format('Y-m');
            $ciro = $ciroAylari[$kod] ?? 0.0;
            $maliyet = $cogsAylari[$kod] ?? 0.0;
            $aylar[] = [
                'month' => $kod,
                'label' => $adlar[(int) $t->format('n') - 1],
                'revenue' => $ciro,
                'collection' => $tahsilatAylari[$kod] ?? 0.0,
                'profit' => round($ciro - $maliyet, 2),
                'documents' => $belgeAylari[$kod] ?? 0,
            ];
        }

        return ['months' => $aylar];
    }

    /** Son 6 ay giren/çıkan nakit (dalga grafiği). */
    protected static function cashFlow(Company $company): array
    {
        $baslangic = now()->startOfMonth()->subMonths(5)->format('Y-m-d');

        $odemeler = Payment::where('company_id', $company->id)
            ->whereDate('paid_at', '>=', $baslangic)
            ->get(['paid_at', 'direction', 'amount', 'currency_code']);

        $aylar = [];
        for ($i = 5; $i >= 0; $i--) {
            $t = now()->startOfMonth()->subMonths($i);
            $aylar[$t->format('Y-m')] = ['month' => $t->format('Y-m'), 'label' => $t->format('M'), 'in' => 0.0, 'out' => 0.0];
        }

        $toplamIn = 0.0;
        $toplamOut = 0.0;

        foreach ($odemeler as $p) {
            $kod = substr((string) $p->paid_at, 0, 7);
            if (! isset($aylar[$kod])) {
                continue;
            }
            $tutar = (float) $p->amount;
            if ($p->direction === Payment::DIRECTION_IN) {
                $aylar[$kod]['in'] = round($aylar[$kod]['in'] + $tutar, 2);
                $toplamIn += $tutar;
            } else {
                $aylar[$kod]['out'] = round($aylar[$kod]['out'] + $tutar, 2);
                $toplamOut += $tutar;
            }
        }

        $liste = array_values($aylar);
        foreach ($liste as &$ay) {
            $ay['net'] = round($ay['in'] - $ay['out'], 2);
        }
        unset($ay);

        return [
            'months' => $liste,
            'total_in' => round($toplamIn, 2),
            'total_out' => round($toplamOut, 2),
        ];
    }

    /** Ciro dağılımı (ürün kategorileri) — son 12 ay. */
    protected static function categoryMix(Company $company): array
    {
        $baslangic = now()->startOfMonth()->subMonths(11)->format('Y-m-d');

        $satirlar = DB::table('document_items')
            ->join('documents', 'documents.id', '=', 'document_items.document_id')
            ->leftJoin('products', 'products.id', '=', 'document_items.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->where('documents.company_id', $company->id)
            ->where('documents.type', Document::TYPE_INVOICE)
            ->whereIn('documents.status', ['issued', 'partially_paid', 'paid'])
            ->whereNull('documents.deleted_at')
            ->whereDate('documents.issue_date', '>=', $baslangic)
            ->selectRaw("COALESCE(product_categories.name, 'Kategorisiz') AS kategori,"
                .' SUM(document_items.line_total) AS toplam')
            ->groupBy('kategori')
            ->orderByDesc('toplam')
            ->limit(6)
            ->get();

        $toplam = (float) $satirlar->sum('toplam');

        return [
            'items' => $satirlar->map(fn ($r) => [
                'category' => (string) $r->kategori,
                'total' => round((float) $r->toplam, 2),
                'pct' => $toplam > 0 ? round(((float) $r->toplam / $toplam) * 100, 1) : 0.0,
            ])->all(),
            'total' => round($toplam, 2),
        ];
    }

    /** Alacak/borç yaşlandırma (vade dilimleri). */
    protected static function debtAging(Company $company): array
    {
        $bugun = now()->startOfDay();

        $docs = Document::where('company_id', $company->id)
            ->whereIn('type', [Document::TYPE_INVOICE, Document::TYPE_PURCHASE])
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereNull('deleted_at')
            ->get(['id', 'type', 'currency_code', 'grand_total', 'due_date', 'issue_date']);

        $paidMap = static::paidMap($company, $docs->pluck('id')->all());

        $dilimler = [
            ['key' => '0_30', 'label_key' => '0 - 30 Gün (Cari Vade)'],
            ['key' => '31_60', 'label_key' => '31 - 60 Gün (Yaklaşan)'],
            ['key' => '61_90', 'label_key' => '61 - 90 Gün (Takipte)'],
            ['key' => '90_plus', 'label_key' => '90+ Gün (Gecikmiş)'],
        ];

        $buckets = [];
        foreach ($dilimler as $d) {
            $buckets[$d['key']] = [
                'key' => $d['key'],
                'label_key' => $d['label_key'],
                'receivable' => 0.0,
                'payable' => 0.0,
            ];
        }

        foreach ($docs as $doc) {
            $open = max(0, round((float) $doc->grand_total - ($paidMap[$doc->id] ?? 0), 2));
            if ($open <= 0) {
                continue;
            }

            $vade = $doc->due_date ?? $doc->issue_date;
            // Kalan gün: vade gelecekteyse pozitif, geçmişteyse negatif.
            $kalan = $vade
                ? (int) floor(($vade->getTimestamp() - $bugun->getTimestamp()) / 86400)
                : 0;

            $key = match (true) {
                $kalan <= -90 => '90_plus',
                $kalan <= -60 => '61_90',
                $kalan <= -30 => '31_60',
                default => '0_30',
            };

            if ($doc->type === Document::TYPE_INVOICE) {
                $buckets[$key]['receivable'] = round($buckets[$key]['receivable'] + $open, 2);
            } else {
                $buckets[$key]['payable'] = round($buckets[$key]['payable'] + $open, 2);
            }
        }

        return ['buckets' => array_values($buckets)];
    }

    /** En yüksek hacimli cariler (bu yıl). */
    protected static function topCustomers(Company $company): array
    {
        $baslangic = now()->startOfYear()->format('Y-m-d');

        $satirlar = Document::where('company_id', $company->id)
            ->where('type', Document::TYPE_INVOICE)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereNull('deleted_at')
            ->whereDate('issue_date', '>=', $baslangic)
            ->whereNotNull('party_id')
            ->selectRaw('party_id, currency_code, COUNT(*) AS belge, SUM(grand_total) AS toplam')
            ->groupBy('party_id', 'currency_code')
            ->orderByDesc('toplam')
            ->limit(5)
            ->get();

        if ($satirlar->isEmpty()) {
            return ['items' => []];
        }

        $cariler = Party::whereIn('id', $satirlar->pluck('party_id')->unique())
            ->get(['id', 'name', 'city'])
            ->keyBy('id');

        return [
            'items' => $satirlar->map(fn ($r) => [
                'party_id' => (int) $r->party_id,
                'name' => $cariler[$r->party_id]->name ?? '—',
                'city' => $cariler[$r->party_id]->city ?? null,
                'documents' => (int) $r->belge,
                'volume' => round((float) $r->toplam, 2),
                'currency' => $r->currency_code ?: 'TRY',
            ])->all(),
        ];
    }

    /** İK & katılım başvuru özeti (CV havuzu). */
    protected static function hrApplications(Company $company): array
    {
        $base = CompanyJoinRequest::where('company_id', $company->id);

        $bekleyen = (clone $base)->where('status', 'pending')->count();
        $kabul = (clone $base)->where('status', 'approved')->count();
        $reddedilen = (clone $base)->where('status', 'rejected')->count();
        $cvlu = (clone $base)->whereNotNull('cv_path')->count();

        $sonBasvurular = (clone $base)
            ->orderByDesc('created_at')
            ->limit(4)
            ->get(['id', 'first_name', 'last_name', 'requested_class', 'status', 'cv_path', 'created_at']);

        return [
            'pending' => $bekleyen,
            'approved' => $kabul,
            'rejected' => $reddedilen,
            'with_cv' => $cvlu,
            'items' => $sonBasvurular->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->fullName(),
                'position' => $r->requested_class,
                'status' => $r->status,
                'has_cv' => $r->cv_path !== null,
                'created_at' => $r->created_at?->format('Y-m-d'),
            ])->all(),
        ];
    }

    /**
     * Para birimi → tutar haritasını sözleşme biçimine çevirir.
     *
     * @param  array<string, float>  $map
     * @return array<int, array{currency: string, total: float}>
     */
    protected static function paraListesi(array $map): array
    {
        $liste = [];
        foreach ($map as $ccy => $total) {
            $liste[] = ['currency' => (string) $ccy, 'total' => round((float) $total, 2)];
        }

        usort($liste, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $liste;
    }
}
