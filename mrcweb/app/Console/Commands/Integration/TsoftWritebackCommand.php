<?php

namespace App\Console\Commands\Integration;

use App\Integration\Alerts\SyncAlertService;
use App\Integration\Enums\SyncDirection;
use App\Integration\Orders\IntegrationAudit;
use App\Integration\Sync\InteractsWithActiveTsoftTenants;
use App\Integration\Sync\TsoftWritebackService;
use App\Models\Document;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Throwable;

/**
 * MRC → Tsoft stok yazımı. Yazmalar servis/sürücü tarafından opt-in'dir;
 * komutun çalışması tek başına dış sisteme yazmaz.
 */
class TsoftWritebackCommand extends Command
{
    use InteractsWithActiveTsoftTenants;

    protected $signature = 'integration:tsoft-writeback {--company=} {--document=} {--sku=} {--price=}';

    protected $description = 'MRC kaynaklı stok değişikliklerini Tsoft aynasina yazar (opt-in)';

    /** @var array<int,array{label: string, processed: int, skipped: int, errors: int}> */
    private array $rows = [];

    private int $companyId = 0;

    public function handle(TsoftWritebackService $service): int
    {
        $companyOption = $this->option('company');
        $this->companyId = ($companyOption !== null && $companyOption !== '') ? (int) $companyOption : 0;

        try {
            $documentOption = $this->option('document');
            $sku = $this->option('sku');
            $price = $this->option('price');

            if ($documentOption !== null && $documentOption !== '') {
                $documentId = (int) $documentOption;
                $companyId = $this->companyId;
                if ($companyId === 0) {
                    $companyId = (int) (Document::query()->where('id', $documentId)->value('company_id') ?? 0);
                }

                if ($companyId > 0) {
                    $this->accumulate($service->processDocument($companyId, $documentId), 'belge:'.$documentId);
                } else {
                    $this->note('belge:'.$documentId, 0, 1, 1);
                }

                return $this->finish($companyId);
            }

            if ($sku !== null && $sku !== '' && $price !== null && $price !== '') {
                foreach ($this->activeTsoftCompanyIds($this->companyId > 0 ? $this->companyId : null) as $companyId) {
                    $result = $service->pushPrice($companyId, (string) $sku, (string) $price);
                    $ok = ! empty($result['ok']) && empty($result['skipped']);
                    $this->note(
                        'fiyat:'.$companyId,
                        $ok ? 1 : 0,
                        $ok ? 0 : 1,
                        empty($result['ok']) ? 1 : 0,
                    );
                }

                return $this->finish($this->companyId);
            }

            foreach ($this->activeTsoftCompanyIds($this->companyId > 0 ? $this->companyId : null) as $companyId) {
                foreach ($this->documentIds($companyId) as $documentId) {
                    $this->accumulate($service->processDocument($companyId, $documentId), 'belge:'.$documentId);
                }
            }
        } catch (Throwable) {
            // Komut zamanlayıcıdan da çağrılır; hata fırlatılmaz.
            $this->note('writeback', 0, 0, 1);
        }

        return $this->finish($this->companyId);
    }

    /**
     * origin='mrc' ve issue/cancel/return hareketi olan en eski 200 belge.
     *
     * @return array<int,int>
     */
    private function documentIds(int $companyId): array
    {
        $references = StockMovement::query()
            ->where('company_id', $companyId)
            ->where(function ($query): void {
                $query->where('reference', 'like', 'issue:%')
                    ->orWhere('reference', 'like', 'cancel:%')
                    ->orWhere('reference', 'like', 'return:%');
            })
            ->orderBy('id')
            ->limit(2000)
            ->pluck('reference');

        $ids = [];
        foreach ($references as $reference) {
            $parts = explode(':', (string) $reference, 2);
            if (count($parts) === 2 && ctype_digit($parts[1])) {
                $ids[(int) $parts[1]] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        return Document::query()
            ->where('company_id', $companyId)
            ->where('origin', 'mrc')
            ->whereIn('id', array_keys($ids))
            ->orderBy('id')
            ->limit(200)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @param array<string,mixed> $result */
    private function accumulate(array $result, string $label): void
    {
        $this->note(
            $label,
            (int) ($result['processed'] ?? 0),
            (int) ($result['skipped'] ?? 0),
            count((array) ($result['errors'] ?? [])),
        );
    }

    private function note(string $label, int $processed, int $skipped, int $errors): void
    {
        $this->rows[] = [
            'label' => mb_substr($label, 0, 60),
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /** Yalnızca sayıları gösterir; SKU/fiyat/müşteri verisi yazılmaz. */
    private function finish(int $companyId): int
    {
        $processed = 0;
        $skipped = 0;
        $errors = 0;

        $table = [];
        foreach ($this->rows as $row) {
            $processed += $row['processed'];
            $skipped += $row['skipped'];
            $errors += $row['errors'];
            $table[] = [
                $row['label'],
                (string) $row['processed'],
                (string) $row['skipped'],
                (string) $row['errors'],
            ];
        }

        if ($table !== []) {
            $this->table(['İşlem', 'İşlenen', 'Atlanan', 'Hata'], $table);
        }

        $this->info("Tsoft writeback: {$processed} işlendi, {$skipped} atlandı, {$errors} hata.");

        // Hata varsa kısa bildirim (servis config kapalıyken hiçbir şey göndermez).
        if ($errors > 0) {
            app(SyncAlertService::class)->notify(
                'writeback:'.$companyId,
                [
                    'company' => $companyId,
                    'processed' => $processed,
                    'skipped' => $skipped,
                    'errors' => $errors,
                ],
                'writeback: kayit yaziminda hata olustu.',
            );
        }

        try {
            IntegrationAudit::log(
                $companyId,
                SyncDirection::Out,
                'writeback',
                null,
                $errors > 0 ? 'failed' : 'ok',
                ['processed' => $processed, 'skipped' => $skipped, 'errors' => $errors],
            );
        } catch (Throwable) {
            // Log hatası komutu başarısız saymaz.
        }

        return self::SUCCESS;
    }
}
