<?php

namespace App\Integration\Sync;

use App\Integration\Tsoft\TsoftAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * MRC stok anlık görüntüsü ile dış sistem stoklarını karşılaştırır.
 * Yalnızca uyarı üretir; otomatik düzeltme YAPMAZ (alarm + elle kuyruk kararı).
 */
final class DriftDetector
{
    public function __construct(private readonly mixed $adapterFactory = null) {}

    /**
     * @return array<int,array{sku:string,mrc_qty:string,external_qty:string,diff:string}>
     */
    public function detect(int $companyId): array
    {
        $limit = max(1, (int) config('integrations.channels.tsoft.drift_limit', 100));
        $states = DB::table('integration_stock_state')
            ->where('company_id', $companyId)
            ->orderBy('sku')
            ->limit($limit)
            ->get();

        try {
            $adapter = $this->adapter($companyId);
        } catch (Throwable) {
            Log::warning('integration.stock.drift.adapter_failed', ['company_id' => $companyId]);

            return [];
        }

        if (! is_object($adapter) || ! method_exists($adapter, 'fetchStock')) {
            return [];
        }

        $rows = [];

        foreach ($states as $state) {
            try {
                $sku = (string) $state->sku;
                $external = $adapter->fetchStock($sku, $this->warehouseCode($companyId, $state->warehouse_id));

                if ($external === null) {
                    continue;
                }

                $mrcQty = bcadd((string) $state->quantity, '0', 4);
                $externalQty = bcadd((string) $external->quantity, '0', 4);

                if (bccomp($mrcQty, $externalQty, 4) === 0) {
                    continue;
                }

                $rows[] = [
                    'sku' => $sku,
                    'mrc_qty' => $mrcQty,
                    'external_qty' => $externalQty,
                    'diff' => bcsub($mrcQty, $externalQty, 4),
                ];
            } catch (Throwable) {
                Log::warning('integration.stock.drift.sku_failed', [
                    'company_id' => $companyId,
                    'sku' => (string) $state->sku,
                ]);

                continue;
            }
        }

        return $rows;
    }

    private function adapter(int $companyId): mixed
    {
        $factory = $this->adapterFactory;

        if (is_callable($factory)) {
            return $factory($companyId);
        }

        return app(TsoftAdapter::class, ['company' => $companyId]);
    }

    private function warehouseCode(int $companyId, mixed $warehouseId): ?string
    {
        if ($warehouseId === null) {
            return null;
        }

        if (Schema::hasColumn('warehouses', 'code')) {
            $code = DB::table('warehouses')
                ->where('company_id', $companyId)
                ->where('id', $warehouseId)
                ->value('code');

            if ($code !== null && $code !== '') {
                return (string) $code;
            }
        }

        return (string) $warehouseId;
    }
}
