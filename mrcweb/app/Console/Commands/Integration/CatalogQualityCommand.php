<?php

namespace App\Console\Commands\Integration;

use App\Integration\Enums\Channel;
use App\Integration\Quality\CatalogQualityService;
use App\Integration\Sync\SyncLog;
use App\Models\Product;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tsoft -> MRC katalogunun yerel veri kalitesini raporlar / onarir.
 *
 * Yinelenen SKU/barkod yalnizca raporlanir; otomatik silme veya birlestirme
 * yapilmaz. Tsoft tarafina hicbir yazma cagrilmaz. SyncLog'a yalnizca sayilar
 * yazilir (urun adi/SKU/barkod degerleri ve kisisel veri loglanmaz).
 */
final class CatalogQualityCommand extends Command
{
    protected $signature = 'integration:catalog-quality
                            {--company= : Yalnizca bu firma}
                            {--repair : Eksik kategori/gorseli yerel kurallarla onar}
                            {--dry-run : Hicbir sey yazma, yalnizca raporla}';

    protected $description = 'Katalog veri kalitesini tarar (eksik kategori/gorsel, yinelenen SKU/barkod)';

    public function handle(CatalogQualityService $quality): int
    {
        $companyOption = $this->option('company');
        $companyIds = $companyOption !== null
            ? [(int) $companyOption]
            : Product::query()
                ->distinct()
                ->orderBy('company_id')
                ->pluck('company_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values()
                ->all();

        if ($companyIds === []) {
            $this->warn('Katalog urunu olan firma bulunamadi.');

            return self::SUCCESS;
        }

        $repair = (bool) $this->option('repair');
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN: veritabanina yazim yapilmayacak.');
        }

        $rows = [];

        foreach ($companyIds as $companyId) {
            $startedAt = microtime(true);

            try {
                $scan = $quality->scan($companyId);
                $fixed = ($repair && ! $dryRun) ? $quality->repair($companyId) : null;
            } catch (Throwable) {
                $this->warn('Firma '.$companyId.' katalog kalitesi okunamadi.');

                continue;
            }

            $duplicateSku = count($scan['duplicate_sku'] ?? []);
            $duplicateBarcode = count($scan['duplicate_barcode'] ?? []);
            $fixedCategory = (int) ($fixed['no_category'] ?? 0);
            $fixedImage = (int) ($fixed['no_image'] ?? 0);

            if (! $dryRun) {
                try {
                    SyncLog::create([
                        'company_id' => $companyId,
                        'channel' => Channel::Tsoft->value,
                        'direction' => 'in',
                        'entity' => 'catalog_quality',
                        'status' => 'success',
                        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                        'external_ref' => 'catalog-quality:'.$companyId,
                        // YALNIZCA sayilar: SKU/barkod degerleri veya kisisel veri yazilmaz.
                        'preview' => json_encode([
                            'total' => (int) ($scan['total'] ?? 0),
                            'no_category' => (int) ($scan['no_category'] ?? 0),
                            'no_image' => (int) ($scan['no_image'] ?? 0),
                            'duplicate_sku' => $duplicateSku,
                            'duplicate_barcode' => $duplicateBarcode,
                            'fixed_category' => $fixedCategory,
                            'fixed_image' => $fixedImage,
                        ], JSON_UNESCAPED_UNICODE),
                    ]);
                } catch (Throwable) {
                    // Log yazilamadiysa tarama sonucu yine de gosterilir.
                }
            }

            $rows[] = [
                $companyId,
                (int) ($scan['total'] ?? 0),
                (int) ($scan['no_category'] ?? 0),
                (int) ($scan['no_image'] ?? 0),
                $duplicateSku,
                $duplicateBarcode,
                $fixedCategory,
                $fixedImage,
            ];
        }

        $this->table(
            ['company', 'total', 'no_category', 'no_image', 'dup_sku', 'dup_barcode', 'fixed_category', 'fixed_image'],
            $rows,
        );

        $this->info(sprintf(
            'Katalog kalitesi: %d firma, %d urun, %d kategorisiz, %d gorselsiz, %d yinelenen SKU, %d yinelenen barkod.',
            count($rows),
            array_sum(array_column($rows, 1)),
            array_sum(array_column($rows, 2)),
            array_sum(array_column($rows, 3)),
            array_sum(array_column($rows, 4)),
            array_sum(array_column($rows, 5)),
        ));

        return self::SUCCESS;
    }
}
