<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PresentsPaginator;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Party;
use App\Services\PartyBalance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Faz 3-C — Rapor API uçları.
 *
 * Ortak kurallar:
 * - Yalnız issued/partially_paid/paid belgeler sayılır (draft/cancelled hariç).
 * - Tarih filtresi issue_date üzerinden (varsayılan: yılbaşı → bugün).
 * - Toplamlar by_currency haritasında; karışık toplam da verilir (çevirim V2-9).
 * - document_items.cogs = issue anındaki **birim** ortalama maliyettir (dondurulur);
 *   maliyet toplamları bu yüzden cogs × quantity ile alınır (kâr hesabı bundan türer).
 * - Ondalıklar round(..., 4); boş aralık 200 + sıfırlar.
 * - Tarih gruplama PHP tarafında (sqlite + MySQL taşınabilirliği için
 *   DATE()/strftime/YEAR()/MONTH() kullanılmaz).
 */
class ReportController extends Controller
{
    use ResolvesTenant, PresentsPaginator;

    public const OPEN_STATUSES = ['issued', 'partially_paid', 'paid'];

    public const CURRENCY_NOTE = "Tutarlar para birimi bazında ayrıdır; para birimi çevirimi sonraki bir sürümde eklenecektir.";

    protected function currencyNote(): string
    {
        return __(self::CURRENCY_NOTE);
    }

    protected function r4(mixed $v): float
    {
        return round((float) $v, 4);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function dateRange(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = $request->query('from') ?: date('Y-01-01');
        $to = $request->query('to') ?: date('Y-m-d');

        return [$from, $to];
    }

    protected function emptyMoneyRow(): array
    {
        return [
            'document_count' => 0,
            'gross_total' => 0.0,
            'net_total' => 0.0,
            'discount_total' => 0.0,
            'tax_total' => 0.0,
            'cogs_total' => 0.0,
            'profit' => 0.0,
            'cogs_missing_rows' => 0,
        ];
    }

    /**
     * Satış özeti raporu: ciro, kâr, KDV ve iskonto (gün/ay/kategori/ürün/cari kırılımı)
     *
     * Tek SQL (satır kalemleri + belge filtresi, GROUP BY) + belge sayısı için
     * küçük bir COUNT(DISTINCT) sorgusu.
     *
     * @response array{
     *     data: array<int,
     *     array{key: string, label: string, document_count: int, gross_total: float, net_total: float, discount_total: float, tax_total: float, cogs_total: float, profit: float, currency_code: string, month_name: string}>,
     *     totals: array{document_count: int, gross_total: float, net_total: float, discount_total: float, tax_total: float, cogs_total: float, profit: float, cogs_missing_rows: float},
     *     by_currency: array{TRY: array{document_count: int, gross_total: float, net_total: float, discount_total: float, tax_total: float, cogs_total: float, profit: float, cogs_missing_rows: float}},
     *     meta: array{from: string, to: string, group_by: string, type: string, cogs_missing_rows: float, currency_note: string},
     * }
     */
    public function salesSummary(Request $request)
    {
        $companyId = $this->tenantId($request);
        [$from, $to] = $this->dateRange($request);

        $groupBy = (string) $request->query('group_by', 'month');
        if (! in_array($groupBy, ['day', 'month', 'category', 'product', 'party'], true)) {
            $groupBy = 'month';
        }
        $type = (string) $request->query('type', Document::TYPE_INVOICE);
        if (! in_array($type, [Document::TYPE_INVOICE, Document::TYPE_PURCHASE], true)) {
            $type = Document::TYPE_INVOICE;
        }

        $agg = $this->aggregateByType($companyId, $from, $to, $type, $groupBy);
        $grouped = $agg['grouped'];
        $docCounts = $agg['counts'];

        $data = [];
        $byCurrency = [];
        foreach ($grouped as $g) {
            $net = $g['sub'] - $g['discount'];
            $profit = $net - $g['cogs'];
            $row = [
                'key' => $g['key'],
                'label' => $g['label'],
                'document_count' => $g['document_count'],
                'gross_total' => $this->r4($g['gross']),
                'net_total' => $this->r4($net),
                'discount_total' => $this->r4($g['discount']),
                'tax_total' => $this->r4($g['tax']),
                'cogs_total' => $this->r4($g['cogs']),
                'profit' => $this->r4($profit),
                'currency_code' => $g['currency_code'],
            ];
            if ($groupBy === 'month') {
                $row['month_name'] = $g['month_name'];
            }
            $data[] = $row;

            $ccy = $g['currency_code'];
            $byCurrency[$ccy] ??= $this->emptyMoneyRow();
            $byCurrency[$ccy]['gross_total'] = $this->r4($byCurrency[$ccy]['gross_total'] + $g['gross']);
            $byCurrency[$ccy]['net_total'] = $this->r4($byCurrency[$ccy]['net_total'] + $net);
            $byCurrency[$ccy]['discount_total'] = $this->r4($byCurrency[$ccy]['discount_total'] + $g['discount']);
            $byCurrency[$ccy]['tax_total'] = $this->r4($byCurrency[$ccy]['tax_total'] + $g['tax']);
            $byCurrency[$ccy]['cogs_total'] = $this->r4($byCurrency[$ccy]['cogs_total'] + $g['cogs']);
            $byCurrency[$ccy]['profit'] = $this->r4($byCurrency[$ccy]['profit'] + $profit);
            $byCurrency[$ccy]['cogs_missing_rows'] += $g['missing'];
        }
        foreach ($byCurrency as $ccy => $box) {
            $byCurrency[$ccy]['document_count'] = (int) ($docCounts[$ccy] ?? 0);
        }

        usort($data, fn ($a, $b) => ($a['key'] ?? "\xff") <=> ($b['key'] ?? "\xff")
            ?: $a['currency_code'] <=> $b['currency_code']);

        $totals = $this->emptyMoneyRow();
        foreach ($byCurrency as $box) {
            foreach (['gross_total', 'net_total', 'discount_total', 'tax_total', 'cogs_total', 'profit'] as $f) {
                $totals[$f] = $this->r4($totals[$f] + $box[$f]);
            }
            $totals['document_count'] += $box['document_count'];
            $totals['cogs_missing_rows'] += $box['cogs_missing_rows'];
        }
        ksort($byCurrency);

        return response()->json([
            'data' => array_values($data),
            'totals' => $totals,
            // boş harita bile JSON'da nesne olarak döner ({}) — istemci tek tip okur
            'by_currency' => (object) $byCurrency,
            'meta' => [
                'from' => $from,
                'to' => $to,
                'group_by' => $groupBy,
                'type' => $type,
                'cogs_missing_rows' => $totals['cogs_missing_rows'],
                'currency_note' => $this->currencyNote(),
            ],
        ]);
    }

    /**
     * Tek tipin kırılımlı toplamları (satış özeti + kâr raporu ortak çekirdeği).
     *
     * @return array{grouped: array, counts: array<string,int>}
     */
    protected function aggregateByType(int $companyId, string $from, string $to, string $type, string $groupBy): array
    {
        $query = DB::table('document_items as di')
            ->join('documents as d', 'd.id', '=', 'di.document_id')
            ->leftJoin('products as p', 'p.id', '=', 'di.product_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('parties as pt', 'pt.id', '=', 'd.party_id')
            ->where('di.company_id', $companyId)
            ->where('d.company_id', $companyId)
            ->whereNull('d.deleted_at')
            ->where('d.type', $type)
            ->whereIn('d.status', self::OPEN_STATUSES)
            ->whereDate('d.issue_date', '>=', $from)
            ->whereDate('d.issue_date', '<=', $to)
            ->selectRaw('d.currency_code AS currency_code,
                COUNT(DISTINCT d.id) AS document_count,
                SUM(di.line_subtotal) AS sub_total,
                SUM(di.line_discount) AS discount_total,
                SUM(di.line_tax) AS tax_total,
                SUM(di.line_total) AS gross_total,
                SUM(COALESCE(di.cogs, 0) * di.quantity) AS cogs_total,
                SUM(CASE WHEN di.cogs IS NULL THEN 1 ELSE 0 END) AS missing_rows');

        // strict SQL kipi (ONLY_FULL_GROUP_BY) güvenli: seçilen her sütun GROUP BY'da.
        switch ($groupBy) {
            case 'day':
            case 'month':
                $query->addSelect('d.issue_date AS g_date');
                $query->groupBy('d.issue_date', 'd.currency_code');
                break;
            case 'category':
                $query->addSelect('p.category_id AS g_cat', 'c.name AS g_cat_name');
                $query->groupBy('p.category_id', 'c.name', 'd.currency_code');
                break;
            case 'product':
                $query->addSelect('di.product_id AS g_prod', 'p.name AS g_pname', 'di.name AS g_iname');
                $query->groupBy('di.product_id', 'p.name', 'di.name', 'd.currency_code');
                break;
            case 'party':
                $query->addSelect('d.party_id AS g_party', 'pt.name AS g_party_name');
                $query->groupBy('d.party_id', 'pt.name', 'd.currency_code');
                break;
        }

        $grouped = [];
        foreach ($query->get() as $row) {
            $key = null;
            $label = null;
            $monthName = null;

            switch ($groupBy) {
                case 'day':
                    $key = substr((string) $row->g_date, 0, 10);
                    $label = $key;
                    break;
                case 'month':
                    $key = substr((string) $row->g_date, 0, 7);
                    $label = $key;
                    $monthName = $this->monthName($key);
                    break;
                case 'category':
                    $key = $row->g_cat !== null ? (int) $row->g_cat : null;
                    $label = $row->g_cat_name ?? __('Bağlantısız');
                    break;
                case 'product':
                    $key = $row->g_prod !== null ? (int) $row->g_prod : null;
                    $label = $row->g_pname ?? $row->g_iname;
                    break;
                case 'party':
                    $key = (int) $row->g_party;
                    $label = $row->g_party_name;
                    break;
            }

            $slot = json_encode([$key, $row->currency_code]);
            $grouped[$slot] ??= [
                'key' => $key, 'label' => $label, 'month_name' => $monthName,
                'currency_code' => $row->currency_code,
                'document_count' => 0, 'sub' => 0.0, 'discount' => 0.0,
                'tax' => 0.0, 'gross' => 0.0, 'cogs' => 0.0, 'missing' => 0,
            ];

            $grouped[$slot]['document_count'] += (int) $row->document_count;
            $grouped[$slot]['sub'] += (float) $row->sub_total;
            $grouped[$slot]['discount'] += (float) $row->discount_total;
            $grouped[$slot]['tax'] += (float) $row->tax_total;
            $grouped[$slot]['gross'] += (float) $row->gross_total;
            $grouped[$slot]['cogs'] += (float) $row->cogs_total;
            $grouped[$slot]['missing'] += (int) $row->missing_rows;
        }

        // Belge sayısı grup kesişimlerinde çift sayılabileceğinden para birimi
        // başına kesin sayı ayrı COUNT(DISTINCT) ile alınır.
        $counts = DB::table('documents')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('type', $type)
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereDate('issue_date', '>=', $from)
            ->whereDate('issue_date', '<=', $to)
            ->groupBy('currency_code')
            ->selectRaw('currency_code, COUNT(*) AS c')
            ->pluck('c', 'currency_code')->all();

        return ['grouped' => $grouped, 'counts' => $counts];
    }

    /**
     * Kâr raporu : ciro − COGS; iadeler netlenir.
     *
     * Satış (`invoice`) eksi iade (`return`): aynı kırılımda ciro da maliyet de
     * düşer, böylece iade sonrası kâr gerçeği verir. Marj = kâr / net (satır bazında).
     *
     * @response array{data: array<int, array{key: string, label: string, document_count: int, gross_total: float, net_total: float, cogs_total: float, profit: float, margin: float|null, currency_code: string, month_name: string}>, totals: array, meta: array{from: string, to: string, group_by: string, currency_note: string}}
     */
    public function profit(Request $request)
    {
        $companyId = $this->tenantId($request);
        [$from, $to] = $this->dateRange($request);

        $groupBy = (string) $request->query('group_by', 'day');
        if (! in_array($groupBy, ['day', 'product', 'category'], true)) {
            $groupBy = 'day';
        }

        $satis = $this->aggregateByType($companyId, $from, $to, Document::TYPE_INVOICE, $groupBy === 'category' ? 'category' : ($groupBy === 'product' ? 'product' : 'day'));
        $iade = $this->aggregateByType($companyId, $from, $to, Document::TYPE_RETURN, $groupBy === 'category' ? 'category' : ($groupBy === 'product' ? 'product' : 'day'));

        $merged = $satis['grouped'];

        foreach ($iade['grouped'] as $slot => $g) {
            if (! isset($merged[$slot])) {
                $merged[$slot] = $g;
                $merged[$slot]['document_count'] = 0;
                foreach (['sub', 'discount', 'tax', 'gross', 'cogs', 'missing'] as $f) {
                    $merged[$slot][$f] = 0.0;
                }
            }

            $merged[$slot]['document_count'] += $g['document_count'];
            foreach (['sub', 'discount', 'tax', 'gross', 'cogs'] as $f) {
                $merged[$slot][$f] = round($merged[$slot][$f] - $g[$f], 4);
            }
            $merged[$slot]['missing'] += $g['missing'];
        }

        $data = [];
        $totals = ['document_count' => 0, 'gross_total' => 0.0, 'net_total' => 0.0, 'cogs_total' => 0.0, 'profit' => 0.0];

        foreach ($merged as $g) {
            $net = $this->r4($g['sub'] - $g['discount']);
            $profit = $this->r4($net - $g['cogs']);

            $row = [
                'key' => $g['key'],
                'label' => $g['label'],
                'document_count' => $g['document_count'],
                'gross_total' => $this->r4($g['gross']),
                'net_total' => $net,
                'cogs_total' => $this->r4($g['cogs']),
                'profit' => $profit,
                'margin' => $net > 0 ? round($profit / $net, 4) : null,
                'currency_code' => $g['currency_code'],
            ];

            if ($groupBy === 'month' || isset($g['month_name'])) {
                $row['month_name'] = $g['month_name'];
            }

            $data[] = $row;
            $totals['document_count'] += $g['document_count'];
            $totals['gross_total'] = $this->r4($totals['gross_total'] + $g['gross']);
            $totals['net_total'] = $this->r4($totals['net_total'] + $net);
            $totals['cogs_total'] = $this->r4($totals['cogs_total'] + $g['cogs']);
            $totals['profit'] = $this->r4($totals['profit'] + $profit);
        }

        $totals['margin'] = $totals['net_total'] > 0 ? round($totals['profit'] / $totals['net_total'], 4) : null;

        usort($data, fn ($a, $b) => ($a['key'] ?? "\xff") <=> ($b['key'] ?? "\xff")
            ?: $a['currency_code'] <=> $b['currency_code']);

        return response()->json([
            'data' => array_values($data),
            'totals' => $totals,
            'meta' => [
                'from' => $from,
                'to' => $to,
                'group_by' => $groupBy,
                'currency_note' => $this->currencyNote(),
            ],
        ]);
    }
    protected function monthName(string $key): string
    {
        try {
            $pretty = Carbon::createFromFormat('Y-m', $key)
                ->locale(app()->getLocale())
                ->translatedFormat('F Y');
            if (is_string($pretty) && $pretty !== '') {
                return $pretty;
            }
        } catch (\Throwable) {
            // yedek: anahtar
        }

        return $key;
    }

    /**
     * KDV özeti raporu: vergi oranı ve para birimi bazında matrah, KDV ve brüt toplam
     *
     * @response array{
     *     data: array<int,
     *     array{tax_rate: float, base_total: float, tax_total: float, gross_total: float, line_count: int, currency_code: string}>,
     *     totals: array{base_total: float, tax_total: float, gross_total: float},
     *     by_currency: array{TRY: array{base_total: float, tax_total: float, gross_total: float, line_count: int}},
     *     meta: array{from: string, to: string, type: string, currency_note: string},
     * }
     */
    public function taxSummary(Request $request)
    {
        $companyId = $this->tenantId($request);
        [$from, $to] = $this->dateRange($request);

        $type = (string) $request->query('type', Document::TYPE_INVOICE);
        if (! in_array($type, [Document::TYPE_INVOICE, Document::TYPE_PURCHASE], true)) {
            $type = Document::TYPE_INVOICE;
        }

        $rows = DB::table('document_items as di')
            ->join('documents as d', 'd.id', '=', 'di.document_id')
            ->where('di.company_id', $companyId)
            ->where('d.company_id', $companyId)
            ->whereNull('d.deleted_at')
            ->where('d.type', $type)
            ->whereIn('d.status', self::OPEN_STATUSES)
            ->whereDate('d.issue_date', '>=', $from)
            ->whereDate('d.issue_date', '<=', $to)
            ->groupBy('di.tax_rate', 'd.currency_code')
            ->selectRaw('di.tax_rate AS tax_rate, d.currency_code AS currency_code,
                COUNT(*) AS line_count,
                SUM(di.line_subtotal - di.line_discount) AS base_total,
                SUM(di.line_tax) AS tax_total,
                SUM(di.line_total) AS gross_total')
            ->orderBy('di.tax_rate')
            ->orderBy('d.currency_code')
            ->get();

        $data = [];
        $byCurrency = [];
        $totals = ['base_total' => 0.0, 'tax_total' => 0.0, 'gross_total' => 0.0];
        foreach ($rows as $row) {
            $data[] = [
                'tax_rate' => $this->r4($row->tax_rate),
                'base_total' => $this->r4($row->base_total),
                'tax_total' => $this->r4($row->tax_total),
                'gross_total' => $this->r4($row->gross_total),
                'line_count' => (int) $row->line_count,
                'currency_code' => $row->currency_code,
            ];

            $ccy = $row->currency_code;
            $byCurrency[$ccy] ??= ['base_total' => 0.0, 'tax_total' => 0.0, 'gross_total' => 0.0, 'line_count' => 0];
            $byCurrency[$ccy]['base_total'] = $this->r4($byCurrency[$ccy]['base_total'] + (float) $row->base_total);
            $byCurrency[$ccy]['tax_total'] = $this->r4($byCurrency[$ccy]['tax_total'] + (float) $row->tax_total);
            $byCurrency[$ccy]['gross_total'] = $this->r4($byCurrency[$ccy]['gross_total'] + (float) $row->gross_total);
            $byCurrency[$ccy]['line_count'] += (int) $row->line_count;

            foreach (['base_total', 'tax_total', 'gross_total'] as $f) {
                $totals[$f] = $this->r4($totals[$f] + (float) $row->{$f});
            }
        }
        ksort($byCurrency);

        return response()->json([
            'data' => $data,
            'totals' => $totals,
            // boş harita bile JSON'da nesne olarak döner ({}) — istemci tek tip okur
            'by_currency' => (object) $byCurrency,
            'meta' => [
                'from' => $from,
                'to' => $to,
                'type' => $type,
                'currency_note' => $this->currencyNote(),
            ],
        ]);
    }

    /**
     * Cari bakiye raporu: alacak/borç, açık belge sayısı ve en eski vade (sayfalı)
     *
     * sorgulu aggregates), sayfadaki cariler için PartyBalance servisi
     * (aging + by_currency). Totals tüm süzülmüş küme üzerinden.
     *
     * @response array{
     *     data: array<int,
     *     array{id: int, code: string, name: string, type: string, currency_code: string, opening_balance: float, balance: float, receivable: int, payable: int, open_document_count: int, oldest_due_date: string|null, aging: array{not_due: int, days_1_30: int, days_31_60: int, days_61_90: int, days_over_90: int}}>,
     *     links: array<int,
     *     array{url: string|null, label: string, page: int|null, active: bool}>,
     *     meta: array{current_page: int, from: int, last_page: int, per_page: int, to: int, total: float},
     *     totals: array{receivable_sum: float, payable_sum: float, net_balance: float},
     *     currency_note: string,
     * }
     */
    public function partyBalances(Request $request)
    {
        $companyId = $this->tenantId($request);

        $request->validate([
            'type' => ['nullable', 'string', 'in:customer,supplier,both,all'],
            'sort' => ['nullable', 'string', 'in:balance_desc,balance_asc,name,oldest_due'],
            'search' => ['nullable', 'string', 'max:190'],
        ]);

        $type = (string) ($request->query('type') ?: 'all');
        $sort = (string) ($request->query('sort') ?: 'balance_desc');
        $search = trim((string) $request->query('search', ''));

        $sales = DB::table('documents')
            ->selectRaw('party_id, SUM(grand_total) AS total')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('type', Document::TYPE_INVOICE)->whereIn('status', self::OPEN_STATUSES)
            ->groupBy('party_id');
        $purchases = DB::table('documents')
            ->selectRaw('party_id, SUM(grand_total) AS total')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('type', Document::TYPE_PURCHASE)->whereIn('status', self::OPEN_STATUSES)
            ->groupBy('party_id');
        $receipts = DB::table('payments')
            ->selectRaw('party_id, SUM(amount) AS total')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('direction', 'in')
            ->groupBy('party_id');
        $payouts = DB::table('payments')
            ->selectRaw('party_id, SUM(amount) AS total')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('direction', 'out')
            ->groupBy('party_id');
        $paidPerDoc = DB::table('payments')
            ->selectRaw('document_id, SUM(amount) AS paid_total')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull('document_id')
            ->groupBy('document_id');
        $open = DB::table('documents as d')
            ->leftJoinSub($paidPerDoc, 'pm', 'pm.document_id', '=', 'd.id')
            ->selectRaw('d.party_id,
                SUM(CASE WHEN d.grand_total - COALESCE(pm.paid_total, 0) > 0 THEN 1 ELSE 0 END) AS open_count,
                MIN(CASE WHEN d.grand_total - COALESCE(pm.paid_total, 0) > 0 THEN d.due_date END) AS oldest_due')
            ->where('d.company_id', $companyId)->whereNull('d.deleted_at')
            ->whereIn('d.type', [Document::TYPE_INVOICE, Document::TYPE_PURCHASE])
            ->whereIn('d.status', self::OPEN_STATUSES)
            ->groupBy('d.party_id');

        $query = Party::query()
            ->where('parties.company_id', $companyId)
            ->leftJoinSub($sales, 's', 's.party_id', '=', 'parties.id')
            ->leftJoinSub($purchases, 'pu', 'pu.party_id', '=', 'parties.id')
            ->leftJoinSub($receipts, 'r', 'r.party_id', '=', 'parties.id')
            ->leftJoinSub($payouts, 'po', 'po.party_id', '=', 'parties.id')
            ->leftJoinSub($open, 'o', 'o.party_id', '=', 'parties.id')
            ->selectRaw('parties.*,
                parties.opening_balance + COALESCE(s.total, 0) - COALESCE(r.total, 0)
                    - COALESCE(pu.total, 0) + COALESCE(po.total, 0) AS report_balance,
                COALESCE(o.open_count, 0) AS report_open_count,
                o.oldest_due AS report_oldest_due');

        if ($type === 'customer') {
            $query->whereIn('parties.type', ['customer', 'both']);
        } elseif ($type === 'supplier') {
            $query->whereIn('parties.type', ['supplier', 'both']);
        } elseif ($type === 'both') {
            $query->where('parties.type', 'both');
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('parties.name', 'like', "%{$search}%")
                    ->orWhere('parties.code', 'like', "%{$search}%");
            });
        }

        $all = $query->get();

        $rows = [];
        $receivable = 0.0;
        $payable = 0.0;
        $net = 0.0;
        foreach ($all as $party) {
            $balance = $this->r4($party->getAttribute('report_balance'));
            $receivable = $this->r4($receivable + max($balance, 0.0));
            $payable = $this->r4($payable + max(-$balance, 0.0));
            $net = $this->r4($net + $balance);
            $rows[] = ['party' => $party, 'balance' => $balance];
        }

        usort($rows, function ($a, $b) use ($sort) {
            $pa = $a['party'];
            $pb = $b['party'];
            return match ($sort) {
                'balance_asc' => $a['balance'] <=> $b['balance']
                    ?: strcmp((string) $pa->name, (string) $pb->name),
                'name' => strcmp(mb_strtolower((string) $pa->name), mb_strtolower((string) $pb->name))
                    ?: $pa->id <=> $pb->id,
                'oldest_due' => (($pa->getAttribute('report_oldest_due') ?? '9999-12-31')
                        <=> ($pb->getAttribute('report_oldest_due') ?? '9999-12-31'))
                    ?: strcmp((string) $pa->name, (string) $pb->name),
                default => $b['balance'] <=> $a['balance']
                    ?: strcmp((string) $pa->name, (string) $pb->name),
            };
        });

        $perPage = min((int) ($request->query('per_page', 20) ?: 20), 100);
        $page = max(1, (int) ($request->query('page', 1) ?: 1));
        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        $data = [];
        // Yaşlandırma + bakiye tek iki sorguda (PartyBalance::batch): eskiden her satır
        // için iki sorgu koşuyordu (100 satır = 200 sorgu). Karar 24.
        $calculations = PartyBalance::batch(array_map(
            static fn ($entry) => $entry['party'],
            $slice
        ));

        foreach ($slice as $entry) {
            /** @var Party $party */
            $party = $entry['party'];
            $calc = $calculations[(int) $party->id]
                ?? ['totals' => ['balance' => 0], 'aging' => []];
            $balance = $this->r4($calc['totals']['balance']);

            $data[] = [
                'id' => $party->id,
                'code' => $party->code,
                'name' => $party->name,
                'type' => $party->type,
                'currency_code' => $party->currency_code,
                'opening_balance' => $this->r4($party->opening_balance),
                'balance' => $balance,
                'receivable' => $this->r4(max($balance, 0.0)),
                'payable' => $this->r4(max(-$balance, 0.0)),
                'open_document_count' => (int) $party->getAttribute('report_open_count'),
                'oldest_due_date' => $party->getAttribute('report_oldest_due')
                    ? substr((string) $party->getAttribute('report_oldest_due'), 0, 10)
                    : null,
                'aging' => [
                    'not_due' => $this->r4($calc['aging']['not_due'] ?? 0),
                    'days_1_30' => $this->r4($calc['aging']['days_1_30'] ?? 0),
                    'days_31_60' => $this->r4($calc['aging']['days_31_60'] ?? 0),
                    'days_61_90' => $this->r4($calc['aging']['days_61_90'] ?? 0),
                    'days_over_90' => $this->r4($calc['aging']['days_over_90'] ?? 0),
                ],
            ];
        }

        $paginator = new LengthAwarePaginator($data, $total, $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return response()->json(array_merge($this->paginateJson($paginator), [
            'totals' => [
                'receivable_sum' => $receivable,
                'payable_sum' => $payable,
                'net_balance' => $net,
            ],
            'currency_note' => $this->currencyNote(),
        ]));
    }

    /**
     * Alacak yaşlandırma raporu: vadesi gelmemiş ve gecikme kovaları
     *
     * alt sorgusu + cari adı join'i); kova hesabı PartyBalance ile aynı formülle.
     *
     * @response array{
     *     buckets: array{not_due: int, days_1_30: int, days_31_60: int, days_61_90: int, days_over_90: int},
     *     total_open: float,
     *     document_count: int,
     *     by_currency: array{TRY: int},
     *     top_parties: array<int,
     *     array{party_id: int, name: string, open_total: float, oldest_due_date: string|null}>,
     *     meta: array{as_of: string, type: string, currency_note: string},
     * }
     */
    public function receivablesAging(Request $request)
    {
        return $this->aging($request, Document::TYPE_INVOICE);
    }

    /**
     * Borç yaşlandırma raporu: vadesi gelmemiş ve gecikme kovaları
     *
     * @response array{
     *     buckets: array{not_due: int, days_1_30: int, days_31_60: int, days_61_90: int, days_over_90: int},
     *     total_open: float,
     *     document_count: int,
     *     by_currency: array,
     *     top_parties: array,
     *     meta: array{as_of: string, type: string, currency_note: string},
     * }
     */
    public function payablesAging(Request $request)
    {
        return $this->aging($request, Document::TYPE_PURCHASE);
    }

    protected function aging(Request $request, string $docType)
    {
        $companyId = $this->tenantId($request);

        $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $asOfStr = $request->query('as_of') ?: date('Y-m-d');
        $asOf = Carbon::parse($asOfStr)->startOfDay();

        $paidPerDoc = DB::table('payments')
            ->selectRaw('document_id, SUM(amount) AS paid_total')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull('document_id')
            ->groupBy('document_id');

        $docs = DB::table('documents as d')
            ->leftJoinSub($paidPerDoc, 'pm', 'pm.document_id', '=', 'd.id')
            ->leftJoin('parties as pt', 'pt.id', '=', 'd.party_id')
            ->where('d.company_id', $companyId)->whereNull('d.deleted_at')
            ->where('d.type', $docType)
            ->whereIn('d.status', self::OPEN_STATUSES)
            ->selectRaw('d.id, d.party_id, pt.name AS party_name,
                d.grand_total, d.currency_code, d.due_date,
                COALESCE(pm.paid_total, 0) AS paid_total')
            ->orderBy('d.due_date')
            ->orderBy('d.id')
            ->get();

        $buckets = ['not_due' => 0.0, 'days_1_30' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'days_over_90' => 0.0];
        $byCurrency = [];
        $perParty = [];
        $totalOpen = 0.0;
        $docCount = 0;

        foreach ($docs as $doc) {
            $open = $this->r4((float) $doc->grand_total - (float) $doc->paid_total);
            if ($open <= 0) {
                continue;
            }

            // PartyBalance ile aynı formül (tutarlılık için birebir).
            $due = $doc->due_date ? Carbon::parse(substr((string) $doc->due_date, 0, 10))->startOfDay() : null;
            $overdue = $due ? (int) $asOf->diffInDays($due, false) * -1 : 0;

            $bucket = match (true) {
                $overdue <= 0 => 'not_due',
                $overdue <= 30 => 'days_1_30',
                $overdue <= 60 => 'days_31_60',
                $overdue <= 90 => 'days_61_90',
                default => 'days_over_90',
            };

            $buckets[$bucket] = $this->r4($buckets[$bucket] + $open);
            $totalOpen = $this->r4($totalOpen + $open);
            $docCount++;

            $ccy = $doc->currency_code;
            $byCurrency[$ccy] = $this->r4(($byCurrency[$ccy] ?? 0) + $open);

            $pid = (int) $doc->party_id;
            $perParty[$pid] ??= ['party_id' => $pid, 'name' => $doc->party_name, 'open_total' => 0.0, 'oldest_due_date' => null];
            $perParty[$pid]['open_total'] = $this->r4($perParty[$pid]['open_total'] + $open);
            $dueStr = $doc->due_date ? substr((string) $doc->due_date, 0, 10) : null;
            if ($dueStr !== null && ($perParty[$pid]['oldest_due_date'] === null || $dueStr < $perParty[$pid]['oldest_due_date'])) {
                $perParty[$pid]['oldest_due_date'] = $dueStr;
            }
        }
        ksort($byCurrency);

        usort($perParty, fn ($a, $b) => $b['open_total'] <=> $a['open_total'] ?: $a['party_id'] <=> $b['party_id']);
        $topParties = array_values(array_slice($perParty, 0, 10));

        return response()->json([
            'buckets' => $buckets,
            'total_open' => $totalOpen,
            'document_count' => $docCount,
            // boş harita bile JSON'da nesne olarak döner ({}) — istemci tek tip okur
            'by_currency' => (object) $byCurrency,
            'top_parties' => $topParties,
            'meta' => [
                'as_of' => $asOfStr,
                'type' => $docType,
                'currency_note' => $this->currencyNote(),
            ],
        ]);
    }

    /**
     * Stok değeri raporu: miktar, birim maliyet, stok ve satış değeri, kritik stok işaretleri
     *
     * join'i + kapsamlı stok alt sorgusu). Yalnız track_inventory ürünler.
     *
     * @response array{
     *     data: array<int,
     *     array{product_id: int, name: string, sku: string, unit: string, category: string|null, quantity: float, avg_cost: float, sale_price: float, min_stock: int, stock_value: float, sale_value: float, critical: bool, currency_code: string}>,
     *     totals: array{product_count: int, stock_value: float, sale_value: float, critical_count: int},
     *     by_currency: array{TRY: array{stock_value: float, sale_value: float, product_count: int}},
     *     meta: array{warehouse_id: int|null, category_id: int|null, currency_note: string},
     * }
     */
    public function stockValuation(Request $request)
    {
        $companyId = $this->tenantId($request);

        $request->validate([
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'category_id' => ['nullable', 'integer',
                Rule::exists('product_categories', 'id')->where('company_id', $companyId)],
        ]);

        $warehouseId = $request->query('warehouse_id') !== null && $request->query('warehouse_id') !== ''
            ? (int) $request->query('warehouse_id')
            : null;
        $categoryId = $request->query('category_id') !== null && $request->query('category_id') !== ''
            ? (int) $request->query('category_id')
            : null;

        $stockSql = '(SELECT COALESCE(SUM(CASE WHEN sm.type = \'in\' THEN sm.quantity'
            .' WHEN sm.type = \'out\' THEN -sm.quantity ELSE sm.quantity END), 0)'
            .' FROM stock_movements sm WHERE sm.product_id = products.id'
            .' AND sm.company_id = products.company_id'
            .($warehouseId !== null ? ' AND sm.warehouse_id = '.$warehouseId : '').')';

        $rows = DB::table('products')
            ->leftJoin('product_categories as c', 'c.id', '=', 'products.category_id')
            ->leftJoin('units as u', 'u.id', '=', 'products.unit_id')
            ->where('products.company_id', $companyId)
            ->where('products.track_inventory', true)
            ->when($categoryId !== null, fn ($q) => $q->where('products.category_id', $categoryId))
            ->selectRaw("products.id, products.name, products.sku,
                products.cost_price, products.sale_price, products.currency_code, products.min_stock,
                c.name AS category_name, u.code AS unit_code, u.name AS unit_name,
                {$stockSql} AS stock_quantity")
            ->orderBy('products.name')
            ->get();

        $data = [];
        $stockValue = 0.0;
        $saleValue = 0.0;
        $criticalCount = 0;
        $byCurrency = [];
        foreach ($rows as $row) {
            $qty = $this->r4($row->stock_quantity);
            $avgCost = $this->r4($row->cost_price);
            $stockVal = $this->r4($qty * $avgCost);
            $saleVal = $this->r4($qty * (float) $row->sale_price);
            $critical = $qty < (float) $row->min_stock;
            if ($critical) {
                $criticalCount++;
            }

            $data[] = [
                'product_id' => (int) $row->id,
                'name' => $row->name,
                'sku' => $row->sku,
                'unit' => $row->unit_code ?? $row->unit_name,
                'category' => $row->category_name,
                'quantity' => $qty,
                'avg_cost' => $avgCost,
                // etiket fiyatı ve kritik stok eşiği de satırda durur: arayüz
                // „satış fiyatı / min. stok / durum” sütunlarını bunlardan kurar
                'sale_price' => $this->r4($row->sale_price),
                'min_stock' => $this->r4($row->min_stock),
                'stock_value' => $stockVal,
                'sale_value' => $saleVal,
                'critical' => $critical,
                'currency_code' => $row->currency_code,
            ];

            $stockValue = $this->r4($stockValue + $stockVal);
            $saleValue = $this->r4($saleValue + $saleVal);
            $ccy = $row->currency_code;
            $byCurrency[$ccy] ??= ['stock_value' => 0.0, 'sale_value' => 0.0, 'product_count' => 0];
            $byCurrency[$ccy]['stock_value'] = $this->r4($byCurrency[$ccy]['stock_value'] + $stockVal);
            $byCurrency[$ccy]['sale_value'] = $this->r4($byCurrency[$ccy]['sale_value'] + $saleVal);
            $byCurrency[$ccy]['product_count']++;
        }
        ksort($byCurrency);

        return response()->json([
            'data' => $data,
            'totals' => [
                'product_count' => count($data),
                'stock_value' => $stockValue,
                'sale_value' => $saleValue,
                'critical_count' => $criticalCount,
            ],
            // boş harita bile JSON'da nesne olarak döner ({}) — istemci tek tip okur
            'by_currency' => (object) $byCurrency,
            'meta' => [
                'warehouse_id' => $warehouseId,
                'category_id' => $categoryId,
                'currency_note' => $this->currencyNote(),
            ],
        ]);
    }

    /**
     * Stok hareketleri raporu: giriş/çıkış/düzeltme miktarları ve değerleri (ürün/depo/tip/gün kırılımı)
     *
     * GROUP BY). Gün grubu SUBSTR ile taşınabilir biçimde alınır.
     *
     * @response array{
     *     data: array<int,
     *     array{key: int, label: string, in_qty: float, out_qty: float, adjust_qty: float, net_qty: float, movement_count: int, in_value: float, out_value: float}>,
     *     totals: array{in_qty: float, out_qty: float, adjust_qty: float, net_qty: float, movement_count: int, in_value: float, out_value: float},
     *     by_currency: array{TRY: array{in_value: float, out_value: float, movement_count: int}},
     *     meta: array{from: string, to: string, group_by: string, warehouse_id: int|null, product_id: int|null, currency_note: string},
     * }
     */
    public function stockMovementsSummary(Request $request)
    {
        $companyId = $this->tenantId($request);
        [$from, $to] = $this->dateRange($request);

        $request->validate([
            'warehouse_id' => ['nullable', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'product_id' => ['nullable', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId)],
        ]);

        $groupBy = (string) $request->query('group_by', 'type');
        if (! in_array($groupBy, ['product', 'warehouse', 'type', 'day'], true)) {
            $groupBy = 'type';
        }

        $warehouseId = $request->query('warehouse_id') !== null && $request->query('warehouse_id') !== ''
            ? (int) $request->query('warehouse_id') : null;
        $productId = $request->query('product_id') !== null && $request->query('product_id') !== ''
            ? (int) $request->query('product_id') : null;

        $query = DB::table('stock_movements as sm')
            ->where('sm.company_id', $companyId)
            ->whereDate('sm.created_at', '>=', $from)
            ->whereDate('sm.created_at', '<=', $to)
            ->when($warehouseId !== null, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
            ->when($productId !== null, fn ($q) => $q->where('sm.product_id', $productId))
            ->selectRaw("SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE 0 END) AS in_qty,
                SUM(CASE WHEN sm.type = 'out' THEN sm.quantity ELSE 0 END) AS out_qty,
                SUM(CASE WHEN sm.type = 'adjust' THEN sm.quantity ELSE 0 END) AS adjust_qty,
                SUM(CASE WHEN sm.type = 'in' THEN sm.quantity
                    WHEN sm.type = 'out' THEN -sm.quantity ELSE sm.quantity END) AS net_qty,
                COUNT(*) AS movement_count,
                SUM(CASE WHEN sm.type = 'in' THEN sm.quantity * COALESCE(sm.unit_cost, 0) ELSE 0 END) AS in_value,
                SUM(CASE WHEN sm.type = 'out' THEN sm.quantity * COALESCE(sm.unit_cost, 0) ELSE 0 END) AS out_value");

        switch ($groupBy) {
            // leftJoin: bağlı ürün/depo silinmiş olsa bile hareket rapordan düşmez
            // (iç join olsa toplam, özetiyle tutarsız olurdu).
            case 'product':
                $query->leftJoin('products as p', 'p.id', '=', 'sm.product_id')
                    ->addSelect('sm.product_id AS gkey', 'p.name AS glabel')
                    ->groupBy('sm.product_id', 'p.name');
                break;
            case 'warehouse':
                $query->leftJoin('warehouses as w', 'w.id', '=', 'sm.warehouse_id')
                    ->addSelect('sm.warehouse_id AS gkey', 'w.name AS glabel')
                    ->groupBy('sm.warehouse_id', 'w.name');
                break;
            case 'day':
                $query->addSelect(DB::raw('SUBSTR(sm.created_at, 1, 10) AS gkey'));
                $query->groupBy(DB::raw('SUBSTR(sm.created_at, 1, 10)'));
                break;
            default:
                $query->addSelect('sm.type AS gkey');
                $query->groupBy('sm.type');
                break;
        }

        $rows = $query->get();

        $typeLabel = fn (string $t) => match ($t) {
            'in' => __('Giriş'),
            'out' => __('Çıkış'),
            default => __('Düzeltme'),
        };

        $data = [];
        $totals = ['in_qty' => 0.0, 'out_qty' => 0.0, 'adjust_qty' => 0.0, 'net_qty' => 0.0,
            'movement_count' => 0, 'in_value' => 0.0, 'out_value' => 0.0];
        foreach ($rows as $row) {
            $key = $groupBy === 'product' || $groupBy === 'warehouse' ? (int) $row->gkey : (string) $row->gkey;
            $label = match ($groupBy) {
                // etiket yoksa bağlı kayıt silinmiş demektir: kullanıcıya taşınabilir bir etiket gösterilir
                'product' => $row->glabel ?? __('Silinmiş ürün'),
                'warehouse' => $row->glabel ?? __('Silinmiş depo'),
                'day' => (string) $row->gkey,
                default => $typeLabel((string) $row->gkey),
            };

            $item = [
                'key' => $key,
                'label' => $label,
                'in_qty' => $this->r4($row->in_qty),
                'out_qty' => $this->r4($row->out_qty),
                'adjust_qty' => $this->r4($row->adjust_qty),
                'net_qty' => $this->r4($row->net_qty),
                'movement_count' => (int) $row->movement_count,
                'in_value' => $this->r4($row->in_value),
                'out_value' => $this->r4($row->out_value),
            ];
            $data[] = $item;

            foreach (['in_qty', 'out_qty', 'adjust_qty', 'net_qty', 'in_value', 'out_value'] as $f) {
                $totals[$f] = $this->r4($totals[$f] + $item[$f]);
            }
            $totals['movement_count'] += $item['movement_count'];
        }

        // sayısal anahtarlar (ürün/depo id) sözel değil tamsayı gibi sıralansın
        usort($data, function ($a, $b) {
            if (is_int($a['key']) && is_int($b['key'])) {
                return $a['key'] <=> $b['key'];
            }

            return (string) $a['key'] <=> (string) $b['key'];
        });

        // Para birimi kırılımı: stock_movements’in kendi para birimi kolonu YOK; tutarlar
        // ürünün para birimindedir (stok değeri raporu da ürünün para birimini kullanır).
        // Karışık kurulu firmada in_value/out_value tek sayıda toplanmasın diye ayrıca verilir.
        $byCurrency = [];
        $ccyRows = DB::table('stock_movements as sm')
            ->leftJoin('products as p', 'p.id', '=', 'sm.product_id')
            ->where('sm.company_id', $companyId)
            ->whereDate('sm.created_at', '>=', $from)
            ->whereDate('sm.created_at', '<=', $to)
            ->when($warehouseId !== null, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
            ->when($productId !== null, fn ($q) => $q->where('sm.product_id', $productId))
            ->selectRaw("COALESCE(p.currency_code, 'TRY') AS currency_code,
                SUM(CASE WHEN sm.type = 'in' THEN sm.quantity * COALESCE(sm.unit_cost, 0) ELSE 0 END) AS in_value,
                SUM(CASE WHEN sm.type = 'out' THEN sm.quantity * COALESCE(sm.unit_cost, 0) ELSE 0 END) AS out_value,
                COUNT(*) AS movement_count")
            ->groupBy(DB::raw("COALESCE(p.currency_code, 'TRY')"))
            ->get();

        foreach ($ccyRows as $row) {
            $ccy = (string) $row->currency_code;
            $byCurrency[$ccy] = [
                'in_value' => $this->r4($row->in_value),
                'out_value' => $this->r4($row->out_value),
                'movement_count' => (int) $row->movement_count,
            ];
        }

        return response()->json([
            'data' => array_values($data),
            'totals' => $totals,
            'by_currency' => (object) $byCurrency,
            'meta' => [
                'from' => $from,
                'to' => $to,
                'group_by' => $groupBy,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'currency_note' => $this->currencyNote(),
            ],
        ]);
    }

    /**
     * Tedarik önerisi : minimumun altına düşen ürünler + önerilen miktar.
     *
     * Öneri = max(0, üst_sınır − stok); üst_sınır = max_stock (ayarlıysa) yoksa
     * min_stock × 2. Yalnız stok takibi açık ürünler; kritik sıralı, en fazla 200 satır.
     *
     * @response array{data: array<int, array{product_id: int, name: string, sku: string|null, stock: float, min_stock: float, max_stock: float|null, suggested_qty: float, currency_code: string}>, meta: array{count: int, currency_note: string}}
     */
    public function stockReplenishment(Request $request)
    {
        $companyId = $this->tenantId($request);

        $stockSql = '(SELECT COALESCE(SUM(CASE WHEN sm.type = \'in\' THEN sm.quantity'
            .' WHEN sm.type = \'out\' THEN -sm.quantity ELSE sm.quantity END), 0)'
            .' FROM stock_movements sm WHERE sm.product_id = products.id'
            .' AND sm.company_id = products.company_id)';

        // HAVING toplu-sorgusuz çalışmaz (sqlite): koşul whereRaw ile subquery'nin
        // tekrarıdır — MySQL/SQLite ikisinde de geçerli .
        $rows = DB::table('products')
            ->where('products.company_id', $companyId)
            ->where('products.track_inventory', true)
            ->selectRaw("products.id, products.name, products.sku, products.currency_code,
                products.min_stock, products.max_stock, {$stockSql} AS stock_quantity")
            ->whereRaw("({$stockSql}) < products.min_stock")
            ->orderBy('products.name')
            ->limit(200)
            ->get();

        $data = [];

        foreach ($rows as $row) {
            $stock = $this->r4($row->stock_quantity);
            $min = $this->r4($row->min_stock);
            $cap = $row->max_stock === null ? $min * 2 : (float) $row->max_stock;

            $data[] = [
                'product_id' => (int) $row->id,
                'name' => $row->name,
                'sku' => $row->sku,
                'stock' => $stock,
                'min_stock' => $min,
                'max_stock' => $row->max_stock === null ? null : $this->r4($row->max_stock),
                'suggested_qty' => $this->r4(max(0, $cap - $stock)),
                'currency_code' => $row->currency_code,
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => ['count' => count($data), 'currency_note' => $this->currencyNote()],
        ]);
    }

    /**
     * ABC analizi : verilen gündeki kesilmiş fatura cirosuna göre sınıflar.
     *
     * A = kümülatif %80'e kadar, B = %95'e kadar, kalan C. Kategori renk paleti
     * istemcidedir; sınıf harfi sunucudan gelir.
     *
     * @response array{data: array<int, array{product_id: int, name: string, revenue: float, share: float, cumulative: float, class: string}>, meta: array{days: int, total_revenue: float, currency_note: string}}
     */
    public function stockAbc(Request $request)
    {
        $companyId = $this->tenantId($request);

        $days = min(365, max(1, (int) ($request->query('days', 90) ?: 90)));
        $since = date('Y-m-d', strtotime("-{$days} days"));

        $rows = DB::table('document_items as di')
            ->join('documents as d', 'd.id', '=', 'di.document_id')
            ->join('products as p', 'p.id', '=', 'di.product_id')
            ->where('d.company_id', $companyId)
            ->where('d.type', 'invoice')
            ->whereIn('d.status', self::OPEN_STATUSES)
            ->whereNull('d.deleted_at')
            ->whereDate('d.issue_date', '>=', $since)
            ->whereNotNull('di.product_id')
            ->groupBy('di.product_id', 'p.name')
            ->selectRaw('di.product_id, p.name AS product_name, SUM(di.line_total) AS revenue')
            ->orderByDesc('revenue')
            ->get();

        $total = 0.0;

        foreach ($rows as $row) {
            $total += (float) $row->revenue;
        }

        $data = [];
        $cum = 0.0;

        foreach ($rows as $row) {
            $revenue = $this->r4($row->revenue);
            $share = $total > 0 ? $revenue / $total : 0;

            // Sınıf, satır EKLENMEDEN ÖNCEKİ kümülatife göre: ilk %80 A, sonraki
            // %15 B, kalan C (tek ürün bile A'dan başlar).
            $class = $cum < 0.80 ? 'A' : ($cum < 0.95 ? 'B' : 'C');
            $cum += $share;

            $data[] = [
                'product_id' => (int) $row->product_id,
                'name' => $row->product_name,
                'revenue' => $revenue,
                'share' => round($share, 4),
                'cumulative' => round($cum, 4),
                'class' => $class,
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'days' => $days,
                'total_revenue' => $this->r4($total),
                'currency_note' => $this->currencyNote(),
            ],
        ]);
    }

    /**
     * Devir hızı + bayat uyarısı .
     *
     * turnover = dönem çıkışı / ortalama stok; gün stoğu = gün / turnover.
     * Son çıkışı `stale_days` günü aşan ya da hiç çıkışı olmayan ürün bayattır.
     *
     * @response array{data: array<int, array{product_id: int, name: string, out_qty: float, avg_stock: float, turnover: float|null, days_of_stock: float|null, last_out_at: string|null, stale: bool}>, meta: array{days: int, stale_days: int, currency_note: string}}
     */
    public function stockTurnover(Request $request)
    {
        $companyId = $this->tenantId($request);

        $days = min(365, max(1, (int) ($request->query('days', 90) ?: 90)));
        $staleDays = min(365, max(1, (int) ($request->query('stale_days', 90) ?: 90)));
        $since = date('Y-m-d', strtotime("-{$days} days"));
        $staleBefore = date('Y-m-d', strtotime("-{$staleDays} days"));

        $signedSql = '(SELECT COALESCE(SUM(CASE WHEN sm.type = \'in\' THEN sm.quantity'
            .' WHEN sm.type = \'out\' THEN -sm.quantity ELSE sm.quantity END), 0)'
            .' FROM stock_movements sm WHERE sm.product_id = products.id'
            .' AND sm.company_id = products.company_id)';

        $periodNetSql = '(SELECT COALESCE(SUM(CASE WHEN sm.type = \'in\' THEN sm.quantity'
            .' WHEN sm.type = \'out\' THEN -sm.quantity ELSE sm.quantity END), 0)'
            .' FROM stock_movements sm WHERE sm.product_id = products.id'
            .' AND sm.company_id = products.company_id'
            ." AND DATE(sm.created_at) >= '{$since}')";

        $periodOutSql = '(SELECT COALESCE(SUM(CASE WHEN sm.type = \'out\' THEN sm.quantity ELSE 0 END), 0)'
            .' FROM stock_movements sm WHERE sm.product_id = products.id'
            .' AND sm.company_id = products.company_id'
            ." AND DATE(sm.created_at) >= '{$since}' )"
            .' AS period_out';

        // SQLite/MySQL taşınabilirliği için tarih karşılaştırması PHP tarafında
        // da doğrulanır; alt sorgular sürücüden bağımsız SUM kümeleridir.
        $rows = DB::table('products')
            ->where('products.company_id', $companyId)
            ->where('products.track_inventory', true)
            ->selectRaw("products.id, products.name, {$signedSql} AS closing,
                {$periodNetSql} AS period_net")
            ->orderBy('products.name')
            ->get();

        $outRows = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('type', 'out')
            ->whereDate('created_at', '>=', $since)
            ->selectRaw('product_id, SUM(quantity) AS out_qty, MAX(DATE(created_at)) AS last_out')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $data = [];

        foreach ($rows as $row) {
            $closing = $this->r4($row->closing);
            $opening = $this->r4($closing - (float) $row->period_net);
            $avg = $this->r4(($opening + $closing) / 2);
            $out = isset($outRows[$row->id]) ? $this->r4($outRows[$row->id]->out_qty) : 0.0;
            $lastOut = isset($outRows[$row->id]) ? (string) $outRows[$row->id]->last_out : null;

            $turnover = $avg > 0 && $out > 0 ? round($out / $avg, 4) : null;

            $data[] = [
                'product_id' => (int) $row->id,
                'name' => $row->name,
                'out_qty' => $out,
                'avg_stock' => $avg,
                'turnover' => $turnover,
                'days_of_stock' => $turnover !== null && $turnover > 0 ? round($days / $turnover, 1) : null,
                'last_out_at' => $lastOut,
                'stale' => $lastOut === null || $lastOut < $staleBefore,
            ];
        }

        usort($data, fn ($a, $b) => ($a['turnover'] ?? PHP_FLOAT_MAX) <=> ($b['turnover'] ?? PHP_FLOAT_MAX));

        return response()->json([
            'data' => $data,
            'meta' => [
                'days' => $days,
                'stale_days' => $staleDays,
                'currency_note' => $this->currencyNote(),
            ],
        ]);
    }
}
