<?php

namespace App\Console\Commands\Integration;

use App\Integration\Canonical\CanonicalStock;
use App\Integration\Sync\IntegrationStockState;
use App\Integration\Sync\InteractsWithActiveTsoftTenants;
use App\Integration\Sync\ParallelStockEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Aktif Tsoft firmalarinin stok snapshot'larini dis sisteme aynalar. */
class TsoftPushStockCommand extends Command
{
    use InteractsWithActiveTsoftTenants;

    protected $signature = 'integration:tsoft-push-stock {--company= : Yalnızca bu firma}';

    protected $description = 'MRC stoklarini Tsoft aynasina gonderir';

    public function handle(ParallelStockEngine $engine): int
    {
        $companyOption = $this->option('company');
        $companyIds = $this->activeTsoftCompanyIds($companyOption !== null ? (int) $companyOption : null);
        $pushed = 0;
        $skipped = 0;

        foreach ($companyIds as $companyId) {
            $states = IntegrationStockState::query()
                ->where('company_id', $companyId)
                ->orderBy('sku')
                ->get();

            foreach ($states as $state) {
                $sku = (string) $state->sku;

                // Veritabani cache store kilitlerini destekler; Redis kullanilmaz.
                $lock = Cache::lock("stock:{$companyId}:{$sku}");

                if (! $lock->block(10)) {
                    $skipped++;

                    continue;
                }

                try {
                    $stock = new CanonicalStock(
                        $sku,
                        (string) ($state->warehouse_id ?? 'default'),
                        bcadd((string) $state->quantity, '0', 4),
                        (int) $state->version,
                        (string) $state->source,
                    );

                    $result = $engine->pushOutbound($companyId, $stock);

                    if ($result->ok && ! $result->skipped) {
                        $pushed++;
                    } else {
                        $skipped++;
                    }
                } finally {
                    $lock->release();
                }
            }
        }

        $this->info("Tsoft stok: {$pushed} gonderildi, {$skipped} atlandi.");

        return self::SUCCESS;
    }
}
