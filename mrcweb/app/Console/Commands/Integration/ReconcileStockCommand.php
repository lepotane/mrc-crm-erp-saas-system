<?php

namespace App\Console\Commands\Integration;

use App\Integration\Enums\Channel;
use App\Integration\Sync\DriftDetector;
use App\Integration\Sync\InteractsWithActiveTsoftTenants;
use App\Integration\Sync\SyncLog;
use Illuminate\Console\Command;

/** Stok sapmalarini raporlar; otomatik duzeltme yapmaz. */
class ReconcileStockCommand extends Command
{
    use InteractsWithActiveTsoftTenants;

    protected $signature = 'integration:reconcile-stock {--company= : Yalnızca bu firma}';

    protected $description = 'MRC ve Tsoft stoklarini karsilastirir, sapma raporu verir';

    public function handle(DriftDetector $detector): int
    {
        $companyOption = $this->option('company');
        $companyIds = $this->activeTsoftCompanyIds($companyOption !== null ? (int) $companyOption : null);
        $total = 0;

        foreach ($companyIds as $companyId) {
            $rows = $detector->detect($companyId);

            if ($rows === []) {
                continue;
            }

            $this->table(['sku', 'mrc_qty', 'external_qty', 'diff'], $rows);

            SyncLog::create([
                'company_id' => $companyId,
                'channel' => Channel::Tsoft->value,
                'direction' => 'in',
                'entity' => 'stock',
                'status' => 'drift',
                'preview' => json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE),
            ]);

            $total += count($rows);
        }

        $this->info("Stok sapmasi: {$total} satir. Otomatik duzeltme yapilmadi.");

        return self::SUCCESS;
    }
}
