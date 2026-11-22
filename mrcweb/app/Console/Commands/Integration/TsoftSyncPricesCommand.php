<?php

namespace App\Console\Commands\Integration;

use App\Integration\Enums\Channel;
use App\Integration\Sync\PriceSyncService;
use App\Integration\Sync\SyncLog;
use App\Models\TenantIntegration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Fiyatları yön seçerek MRC <-> Tsoft arasında senkronize eder. */
class TsoftSyncPricesCommand extends Command
{
    protected $signature = 'integration:tsoft-sync-prices {direction : out|in} {--company=} {--sku=*} {--all} {--force}';

    protected $description = 'Fiyatları tek, seçili veya tüm ürünler için MRC <-> Tsoft yönünde senkronize eder';

    public function handle(PriceSyncService $service): int
    {
        $direction = strtolower(trim((string) $this->argument('direction')));
        if (! in_array($direction, ['in', 'out'], true)) {
            $this->error('Geçersiz yön: '.$direction.' (out|in beklenir).');

            return self::FAILURE;
        }

        $all = (bool) $this->option('all');
        $skus = array_values(array_filter(array_map('trim', array_map('strval', (array) $this->option('sku')))));

        if (! $all && $skus === []) {
            $this->error('Ürün seçilmedi. --sku=... veya --all kullanın.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        if ($force) {
            Log::warning('integration.price.sync.force_cli', ['direction' => $direction]);
        }

        $integrationQuery = TenantIntegration::query()
            ->where('channel', Channel::Tsoft->value);

        $companyOption = $this->option('company');
        if ($companyOption !== null && $companyOption !== '') {
            $integrationQuery->where('company_id', (int) $companyOption);
        }

        $companyIds = $integrationQuery->distinct()->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($companyIds === []) {
            $this->warn('Tsoft kanalına bağlı firma bulunamadı.');

            return self::SUCCESS;
        }

        $table = [];
        $anyFailed = false;

        foreach ($companyIds as $companyId) {
            $startedAt = microtime(true);

            try {
                $summary = $service->sync($companyId, $direction, $skus, $all, $force);
            } catch (Throwable) {
                $summary = [
                    'total' => 0, 'pushed' => 0, 'failed' => 1, 'skipped' => 0,
                    'details' => [['sku' => '', 'status' => 'failed', 'message' => 'Senkronizasyon başarısız.']],
                ];
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $failed = (int) ($summary['failed'] ?? 0);
            $pushed = (int) ($summary['pushed'] ?? 0);
            $skipped = (int) ($summary['skipped'] ?? 0);

            if ($failed > 0) {
                $anyFailed = true;
            }

            $status = $this->statusFor($summary);

            SyncLog::create([
                'company_id' => $companyId,
                'channel' => Channel::Tsoft->value,
                'direction' => $direction,
                'entity' => 'price',
                'status' => $status,
                'duration_ms' => $durationMs,
                'preview' => json_encode([
                    'direction' => $direction,
                    'scope' => $all ? 'all' : count($skus),
                    'force' => $force,
                    'total' => (int) ($summary['total'] ?? 0),
                    'pushed' => $pushed,
                    'failed' => $failed,
                    'skipped' => $skipped,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $table[] = [$companyId, $direction, $summary['total'] ?? 0, $pushed, $failed, $skipped, $status];
        }

        $this->table(['company', 'direction', 'total', 'pushed', 'failed', 'skipped', 'status'], $table);

        $this->info(sprintf(
            'Fiyat senkronizasyonu (%s): %d firma, %d gönderildi/güncellendi, %d başarısız, %d atlandı.',
            $direction,
            count($companyIds),
            array_sum(array_column($table, 3)),
            array_sum(array_column($table, 4)),
            array_sum(array_column($table, 5)),
        ));

        return $anyFailed ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string,mixed> $summary */
    private function statusFor(array $summary): string
    {
        if ((int) ($summary['failed'] ?? 0) > 0) {
            return 'failed';
        }

        if ((int) ($summary['skipped'] ?? 0) > 0) {
            return 'skipped';
        }

        return 'success';
    }
}
