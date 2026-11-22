<?php

namespace App\Console\Commands\Integration;

use App\Integration\Alerts\SyncAlertService;
use App\Integration\Enums\Channel;
use App\Integration\Sync\CatalogPullService;
use App\Integration\Sync\InteractsWithActiveTsoftTenants;
use App\Integration\Sync\SyncLog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tsoft katalogunu (urun / fiyat / stok / musteri) MRC'ye ceker.
 * Tsoft tarafinda hici bir yazma yapilmaz; mevcut veri silinmez.
 */
class TsoftPullCatalogCommand extends Command
{
    use InteractsWithActiveTsoftTenants;

    protected $signature = 'integration:tsoft-pull-catalog {--company= : Yalnızca bu firma}';

    protected $description = 'Tsoft katalogunu (urun/fiyat/stok/musteri) MRC ye ceker';

    public function handle(): int
    {
        $companyOption = $this->option('company');
        $companyIds = $this->activeTsoftCompanyIds($companyOption !== null ? (int) $companyOption : null);

        if ($companyIds === []) {
            $this->warn('Tsoft kanalına bağlı aktif firma bulunamadı.');

            return self::SUCCESS;
        }

        $rows = [];
        $anyFailed = false;

        foreach ($companyIds as $companyId) {
            $startedAt = microtime(true);

            try {
                $summary = app(CatalogPullService::class)->pull($companyId);
            } catch (Throwable) {
                $summary = [
                    'products' => 0,
                    'prices' => 0,
                    'stock' => 0,
                    'customers' => 0,
                    'errors' => ['catalog: cekim basarisiz.'],
                ];
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $errors = is_array($summary['errors'] ?? null) ? $summary['errors'] : [];
            $failed = $errors !== [];
            if ($failed) {
                $anyFailed = true;
            }
            $status = $failed ? 'failed' : 'success';

            SyncLog::create([
                'company_id' => $companyId,
                'channel' => Channel::Tsoft->value,
                'direction' => 'in',
                'entity' => 'catalog',
                'status' => $status,
                'duration_ms' => $durationMs,
                'external_ref' => 'catalog-pull:'.$companyId,
                // Yalnızca sayılar: ham veri / kisisel veri yazilmaz.
                'preview' => json_encode([
                    'products' => (int) ($summary['products'] ?? 0),
                    'prices' => (int) ($summary['prices'] ?? 0),
                    'stock' => (int) ($summary['stock'] ?? 0),
                    'customers' => (int) ($summary['customers'] ?? 0),
                    'errors' => count($errors),
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $rows[] = [
                $companyId,
                (int) ($summary['products'] ?? 0),
                (int) ($summary['prices'] ?? 0),
                (int) ($summary['stock'] ?? 0),
                (int) ($summary['customers'] ?? 0),
                count($errors),
                $status,
                $durationMs.' ms',
            ];
        }

        $this->table(
            ['company', 'products', 'prices', 'stock', 'customers', 'errors', 'status', 'duration'],
            $rows,
        );

        $this->info(sprintf(
            'Tsoft katalog cekimi: %d firma, %d urun, %d fiyat, %d stok, %d musteri.',
            count($companyIds),
            array_sum(array_column($rows, 1)),
            array_sum(array_column($rows, 2)),
            array_sum(array_column($rows, 3)),
            array_sum(array_column($rows, 4)),
        ));

        // Hata varsa kısa bildirim (servis config kapalıyken hiçbir şey göndermez).
        if ($anyFailed) {
            app(SyncAlertService::class)->notify(
                'catalog-pull',
                [
                    'firms' => count($companyIds),
                    'products' => array_sum(array_column($rows, 1)),
                    'prices' => array_sum(array_column($rows, 2)),
                    'stock' => array_sum(array_column($rows, 3)),
                    'customers' => array_sum(array_column($rows, 4)),
                    'errors' => array_sum(array_column($rows, 5)),
                ],
                'catalog: cekimde hata olustu.',
            );
        }

        return $anyFailed ? self::FAILURE : self::SUCCESS;
    }
}
