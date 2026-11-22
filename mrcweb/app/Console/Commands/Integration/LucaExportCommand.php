<?php

namespace App\Console\Commands\Integration;

use App\Integration\Luca\LucaFisExporter;
use App\Integration\Sync\SyncLog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Luca resmi fiş (Excel Veri Aktarımı) CSV dosyasını üretir.
 *
 * Varsayılan aralık GEÇEN aydır. Dosyaya müşteri kimlik bilgisi yazılmaz;
 * sync_logs.preview yalnızca sayıları ve dosya yolunu tutar.
 */
class LucaExportCommand extends Command
{
    protected $signature = 'integration:luca-export
                            {--company= : Hedef firma (company_id, zorunlu)}
                            {--from= : Başlangıç tarihi (Y-m-d; varsayılan: geçen ayın ilk günü)}
                            {--to= : Bitiş tarihi (Y-m-d; varsayılan: geçen ayın son günü)}';

    protected $description = 'Luca resmi fis CSV dosyasini uretir';

    public function handle(LucaFisExporter $exporter): int
    {
        $companyId = (int) $this->option('company');
        if ($companyId <= 0) {
            $this->error('Hedef firma için --company seçeneği zorunludur.');

            return self::FAILURE;
        }

        $fromOption = (string) ($this->option('from') ?? '');
        $toOption = (string) ($this->option('to') ?? '');

        $from = $fromOption !== '' ? $fromOption : now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $to = $toOption !== '' ? $toOption : now()->subMonthNoOverflow()->endOfMonth()->toDateString();

        if (! $this->validDate($from) || ! $this->validDate($to)) {
            $this->error('Tarih biçimi Y-m-d olmalıdır.');

            return self::FAILURE;
        }

        try {
            $result = $exporter->export($companyId, $from, $to);

            SyncLog::create([
                'company_id' => $companyId,
                'channel' => 'luca',
                'direction' => 'out',
                'entity' => 'luca',
                'status' => ($result['unmapped_rows'] ?? 0) > 0 ? 'failed' : 'ok',
                'preview' => json_encode([
                    'path' => $result['path'],
                    'rows' => (int) $result['rows'],
                    'documents' => (int) $result['documents'],
                    'unmapped_rows' => (int) $result['unmapped_rows'],
                    'from' => $from,
                    'to' => $to,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

            $this->info(sprintf(
                'Luca fiş: %s — %d satır, %d belge, %d eşleşmeyen hesap.',
                $result['path'],
                $result['rows'],
                $result['documents'],
                $result['unmapped_rows'],
            ));

            if (($result['unmapped_rows'] ?? 0) > 0) {
                $this->warn('Hesap eşlemesi eksik; ilgili satırlarda Hesap Kodu boş bırakıldı.');
            }

            return self::SUCCESS;
        } catch (Throwable) {
            SyncLog::create([
                'company_id' => $companyId,
                'channel' => 'luca',
                'direction' => 'out',
                'entity' => 'luca',
                'status' => 'failed',
                'preview' => json_encode(['error' => 'luca_export_failed'], JSON_THROW_ON_ERROR),
            ]);

            $this->error('Luca fiş dosyası üretilemedi.');

            return self::FAILURE;
        }
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $parsed = date_parse($value);

        return $parsed['error_count'] === 0 && $parsed['warning_count'] === 0
            && checkdate((int) $parsed['month'], (int) $parsed['day'], (int) $parsed['year']);
    }
}
