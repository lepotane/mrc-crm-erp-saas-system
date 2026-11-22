<?php

namespace App\Console\Commands\Integration;

use App\Integration\Enums\SyncDirection;
use App\Integration\Orders\IntegrationAudit;
use App\Integration\Sync\InteractsWithActiveTsoftTenants;
use App\Integration\Sync\SyncDiffService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tsoft ↔ MRC fark taraması. Tsoft YALNIZCA OKUNUR.
 *
 * rota kaydetmez; tek seferlik/planlı çalıştırma içindir.
 */
class TsoftScanDiffsCommand extends Command
{
    use InteractsWithActiveTsoftTenants;

    protected $signature = 'integration:tsoft-scan-diffs {--company= : Yalnızca bu firma}';

    protected $description = 'Tsoft ile MRC arasındaki farkları tarar (salt okunur)';

    public function handle(SyncDiffService $service): int
    {
        $option = $this->option('company');
        $companyIds = $this->activeTsoftCompanyIds($option !== null ? (int) $option : null);
        $rows = [];

        foreach ($companyIds as $companyId) {
            try {
                $summary = $service->scan($companyId);
            } catch (Throwable) {
                $summary = [
                    'scanned' => 0,
                    'new' => 0,
                    'updated' => 0,
                    'orders' => 0,
                    'error' => 'Tarama beklenmeyen bir hata nedeniyle tamamlanamadı.',
                ];
            }

            // Snapshot yok; yalnızca sayımlar loglanır.
            IntegrationAudit::log(
                $companyId,
                SyncDirection::In,
                'sync_diff',
                null,
                isset($summary['error']) ? 'failed' : 'ok',
                $summary,
            );

            $rows[] = [
                $companyId,
                (int) ($summary['scanned'] ?? 0),
                (int) ($summary['new'] ?? 0),
                (int) ($summary['updated'] ?? 0),
                (int) ($summary['orders'] ?? 0),
                isset($summary['error']) ? mb_substr((string) $summary['error'], 0, 60) : 'ok',
            ];
        }

        if ($rows !== []) {
            $this->table(['Firma', 'Taranan', 'Yeni', 'Guncel', 'Siparis', 'Durum'], $rows);
        } else {
            $this->info('Taranacak aktif Tsoft firması bulunamadı.');
        }

        return self::SUCCESS;
    }
}
