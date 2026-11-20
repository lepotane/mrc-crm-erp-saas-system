<?php

namespace App\Integration\Accountant;

use App\Models\AccountantTransfer;
use App\Models\Document;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only cross-check of MRC documents, GIB documents and Luca transfers.
 * No external calls: every figure is derived from local tables, and no raw
 * identifier (tax id, ETTN, party name) is included in the result.
 */
final class ReconciliationReport
{
    /**
     * @return array<string,mixed>
     */
    public function generate(int $companyId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        // Sale/invoice types, selected from the model's own type list.
        $saleTypes = array_values(array_intersect(
            [Document::TYPE_SALES_ORDER, Document::TYPE_INVOICE],
            Document::TYPES
        ));

        $mrcQuery = DB::table('documents')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereIn('type', $saleTypes);
        $this->applyPeriod($mrcQuery, 'issue_date', $from, $to);
        $mrcRows = $mrcQuery->get(['grand_total']);

        $mrcTotal = '0.0000';
        foreach ($mrcRows as $row) {
            $mrcTotal = bcadd($mrcTotal, $this->numeric((string) ($row->grand_total ?? '0')), 4);
        }
        $mrcCount = $mrcRows->count();

        $gibQuery = DB::table('gib_documents as g')
            ->leftJoin('documents as d', 'd.id', '=', 'g.document_id')
            ->where('g.company_id', $companyId)
            ->where(function (Builder $query) {
                $query->whereNull('d.id')->orWhereNull('d.deleted_at');
            });
        $this->applyPeriod($gibQuery, 'g.created_at', $from, $to);
        $gibRows = $gibQuery->get(['g.status as status', 'd.grand_total as grand_total']);

        $gibByStatus = [];
        foreach (['draft', 'official_pending', 'official'] as $status) {
            $gibByStatus[$status] = ['count' => 0, 'total' => '0.0000'];
        }

        $gibTotal = '0.0000';
        foreach ($gibRows as $row) {
            $status = (string) ($row->status ?? 'unknown');
            if (! isset($gibByStatus[$status])) {
                $gibByStatus[$status] = ['count' => 0, 'total' => '0.0000'];
            }

            $amount = $this->numeric((string) ($row->grand_total ?? '0'));
            $gibByStatus[$status]['count']++;
            $gibByStatus[$status]['total'] = bcadd($gibByStatus[$status]['total'], $amount, 4);
            $gibTotal = bcadd($gibTotal, $amount, 4);
        }
        $gibCount = $gibRows->count();

        $lucaQuery = AccountantTransfer::query()->where('company_id', $companyId);
        if ($from !== null) {
            $lucaQuery->where('created_at', '>=', $from->copy()->startOfDay());
        }
        if ($to !== null) {
            $lucaQuery->where('created_at', '<=', $to->copy()->endOfDay());
        }
        $successish = ['transferred', 'success', 'succeeded', 'completed', 'partial'];
        $lucaCount = (clone $lucaQuery)->whereIn('status', $successish)->count();
        $lucaByStatus = (clone $lucaQuery)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return [
            'company_id' => $companyId,
            'masked' => true, // no raw sensitive values are returned
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'mrc' => [
                'count' => $mrcCount,
                'total' => $mrcTotal,
                'types' => $saleTypes,
            ],
            'gib' => [
                'count' => $gibCount,
                'total' => $gibTotal,
                'by_status' => $gibByStatus,
            ],
            'luca' => [
                'success_count' => $lucaCount,
                'by_status' => $lucaByStatus,
            ],
            'difference' => ($mrcCount > 0 && $gibCount > 0)
                ? bcsub($mrcTotal, $gibTotal, 4)
                : null,
        ];
    }

    private function applyPeriod(Builder $query, string $column, ?CarbonInterface $from, ?CarbonInterface $to): void
    {
        if ($from !== null) {
            $query->where($column, '>=', $from->copy()->startOfDay()->toDateTimeString());
        }
        if ($to !== null) {
            $query->where($column, '<=', $to->copy()->endOfDay()->toDateTimeString());
        }
    }

    private function numeric(string $value): string
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? $value : '0';
    }
}
