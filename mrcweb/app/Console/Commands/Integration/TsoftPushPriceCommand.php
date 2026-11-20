<?php

namespace App\Console\Commands\Integration;

use App\Integration\Sync\InteractsWithActiveTsoftTenants;
use App\Integration\Sync\PriceSyncService;
use App\Models\Product;
use Illuminate\Console\Command;

/** Fiyati tanimli urunleri Tsoft'a gonderir (dry_run servis tarafinda). */
class TsoftPushPriceCommand extends Command
{
    use InteractsWithActiveTsoftTenants;

    protected $signature = 'integration:tsoft-push-price {--company= : Yalnızca bu firma}';

    protected $description = 'MRC fiyatlarini Tsoft aynasina gonderir';

    public function handle(PriceSyncService $service): int
    {
        $companyOption = $this->option('company');
        $companyIds = $this->activeTsoftCompanyIds($companyOption !== null ? (int) $companyOption : null);
        $pushed = 0;
        $skipped = 0;

        foreach ($companyIds as $companyId) {
            $products = Product::query()
                ->where('company_id', $companyId)
                ->whereHas('prices')
                ->get(['id', 'company_id', 'sku']);

            foreach ($products as $product) {
                $result = $service->push($companyId, (string) $product->sku);

                if ($result->ok && ! $result->skipped) {
                    $pushed++;
                } else {
                    $skipped++;
                }
            }
        }

        $this->info("Tsoft fiyat: {$pushed} gonderildi, {$skipped} atlandi.");

        return self::SUCCESS;
    }
}
