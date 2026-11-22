<?php

namespace App\Integration\Reports;

use App\Models\Document;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Ürün bazında kârlılık verisi.
 *
 * Kurallar:
 * - yalnızca issued/partially_paid/paid satış belgeleri (invoice + sales_order)
 * - sales_order, faturalanmışsa çift sayılmamak için dışlanır (converted_from_id)
 * - ciro = line_subtotal - line_discount (KDV hariç net satış)
 * - maliyet = COALESCE(document_items.cogs, products.cost_price) × quantity
 *   (satırda dondurulmuş birim maliyet varsa o, yoksa ürünün güncel maliyeti)
 * - para tutarları 4 ondalıklı DECIMAL string olarak döner (float round-trip yok)
 *
 * KVKK: müşteri/cari verisi sorguya katılmaz; çıktı yalnızca ürün verisidir.
 */
final class ProductProfitService
{
    private const SCALE = 4;

    /**
     * @return array{
     *     rows: array<int,array{product_id:int,sku:string,name:string,sold_qty:string,revenue:string,cogs:string,profit:string,margin:string|null}>,
     *     totals: array{sold_qty:string,revenue:string,cogs:string,profit:string,margin:string|null}
     * }
     */
    public function profit(int $companyId, string $from, string $to): array
    {
        $rows = DB::table('document_items as di')
            ->join('documents as d', 'd.id', '=', 'di.document_id')
            ->leftJoin('products as p', 'p.id', '=', 'di.product_id')
            ->leftJoin('documents as converted', function (JoinClause $join): void {
                $join->on('converted.converted_from_id', '=', 'd.id')
                    ->where('converted.type', '=', Document::TYPE_INVOICE)
                    ->whereNull('converted.deleted_at')
                    ->where('d.type', '=', Document::TYPE_SALES_ORDER);
            })
            ->where('di.company_id', $companyId)
            ->where('d.company_id', $companyId)
            ->whereNull('d.deleted_at')
            ->whereNull('converted.id')
            ->whereIn('d.type', [Document::TYPE_INVOICE, Document::TYPE_SALES_ORDER])
            ->whereIn('d.status', ['issued', 'partially_paid', 'paid'])
            ->whereDate('d.issue_date', '>=', $from)
            ->whereDate('d.issue_date', '<=', $to)
            ->whereNotNull('di.product_id')
            ->selectRaw('di.product_id AS product_id,
                p.sku AS p_sku, p.name AS p_name, di.name AS i_name,
                SUM(di.quantity) AS sold_qty,
                SUM(di.line_subtotal - di.line_discount) AS revenue,
                SUM(COALESCE(di.cogs, p.cost_price, 0) * di.quantity) AS cogs')
            ->groupBy('di.product_id', 'p.sku', 'p.name', 'di.name')
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $qty = $this->dec($row->sold_qty);
            $revenue = $this->dec($row->revenue);
            $cogs = $this->dec($row->cogs);
            $profit = bcsub($revenue, $cogs, self::SCALE);
            $margin = bccomp($revenue, '0', self::SCALE) !== 0
                ? bcdiv($profit, $revenue, self::SCALE)
                : null;

            $data[] = [
                'product_id' => (int) $row->product_id,
                'sku' => (string) ($row->p_sku ?? ''),
                'name' => (string) ($row->p_name ?? $row->i_name ?? ''),
                'sold_qty' => $qty,
                'revenue' => $revenue,
                'cogs' => $cogs,
                'profit' => $profit,
                'margin' => $margin,
            ];
        }

        usort($data, static fn (array $a, array $b): int => bccomp($b['profit'], $a['profit'], self::SCALE)
            ?: $a['product_id'] <=> $b['product_id']);

        $totals = [
            'sold_qty' => '0.0000',
            'revenue' => '0.0000',
            'cogs' => '0.0000',
            'profit' => '0.0000',
            'margin' => null,
        ];
        foreach ($data as $row) {
            foreach (['sold_qty', 'revenue', 'cogs', 'profit'] as $field) {
                $totals[$field] = bcadd($totals[$field], $row[$field], self::SCALE);
            }
        }
        $totals['margin'] = bccomp($totals['revenue'], '0', self::SCALE) !== 0
            ? bcdiv($totals['profit'], $totals['revenue'], self::SCALE)
            : null;

        return ['rows' => $data, 'totals' => $totals];
    }

    private function dec(mixed $value): string
    {
        if (! is_scalar($value) || ! is_numeric((string) $value)) {
            return '0.0000';
        }

        return bcadd((string) $value, '0', self::SCALE);
    }
}
