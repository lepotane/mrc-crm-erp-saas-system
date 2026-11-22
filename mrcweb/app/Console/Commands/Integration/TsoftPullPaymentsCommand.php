<?php

namespace App\Console\Commands\Integration;

use App\Integration\Payments\TsoftPaymentImportService;
use App\Integration\Sync\InteractsWithActiveTsoftTenants;
use App\Integration\Sync\SyncLog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tsoft siparişlerinin ONAYLANMIŞ ödemelerini MRC tahsilatı olarak içe alır.
 *
 * Onaysız ödemeler asla tahsilat yaratmaz. Kişisel veri loglara yazılmaz;
 * sync_logs.preview yalnızca sayıları tutar.
 */
class TsoftPullPaymentsCommand extends Command
{
    use InteractsWithActiveTsoftTenants;

    protected $signature = 'integration:tsoft-pull-payments
                            {--company= : Yalnızca bu firma}
                            {--limit=500 : Firma başına taranacak belge sayısı}';

    protected $description = 'Tsoft onayli odemelerini MRC tahsilati olarak ice alir';

    public function handle(TsoftPaymentImportService $service): int
    {
        $companyOption = $this->option('company');
        $companyIds = $this->activeTsoftCompanyIds($companyOption !== null ? (int) $companyOption : null);
        $limit = max(1, (int) ($this->option('limit') ?: 500));

        if ($companyIds === []) {
            $this->info('Tsoft tahsilat: aktif firma yok.');

            return self::SUCCESS;
        }

        $totals = ['scanned' => 0, 'total' => 0, 'imported' => 0,
            'skipped_not_approved' => 0, 'skipped_mapped' => 0,
            'skipped_no_ref' => 0, 'failed' => 0];

        foreach ($companyIds as $companyId) {
            try {
                $rows = $service->scanDocuments($companyId, $limit);
                $result = $service->import($companyId, $rows);

                $totals['scanned'] += count($rows);
                foreach (['total', 'imported', 'skipped_not_approved', 'skipped_mapped',
                    'skipped_no_ref', 'failed'] as $key) {
                    $totals[$key] += (int) ($result[$key] ?? 0);
                }

                SyncLog::create([
                    'company_id' => $companyId,
                    'channel' => 'tsoft',
                    'direction' => 'in',
                    'entity' => 'payment',
                    'status' => ($result['failed'] ?? 0) > 0 ? 'failed' : 'ok',
                    // Yalnızca sayılar — kişisel veri veya ham ödeme satırı YOK.
                    'preview' => json_encode([
                        'scanned' => count($rows),
                        'imported' => (int) ($result['imported'] ?? 0),
                        'skipped' => (int) ($result['skipped_not_approved'] ?? 0)
                            + (int) ($result['skipped_mapped'] ?? 0)
                            + (int) ($result['skipped_no_ref'] ?? 0),
                        'failed' => (int) ($result['failed'] ?? 0),
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            } catch (Throwable) {
                $totals['failed']++;

                SyncLog::create([
                    'company_id' => $companyId,
                    'channel' => 'tsoft',
                    'direction' => 'in',
                    'entity' => 'payment',
                    'status' => 'failed',
                    'preview' => json_encode(['error' => 'payment_import_failed'], JSON_THROW_ON_ERROR),
                ]);

                $this->error("Firma {$companyId}: tahsilat içe aktarımı başarısız.");
            }
        }

        $this->info(sprintf(
            'Tsoft tahsilat: %d tarandı, %d içe alındı, %d onaysız atlandı, %d eşli atlandı, %d hata.',
            $totals['scanned'],
            $totals['imported'],
            $totals['skipped_not_approved'],
            $totals['skipped_mapped'],
            $totals['failed'],
        ));

        return self::SUCCESS;
    }
}
