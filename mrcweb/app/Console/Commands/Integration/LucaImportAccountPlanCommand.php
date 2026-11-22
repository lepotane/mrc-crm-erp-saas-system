<?php

namespace App\Console\Commands\Integration;

use App\Integration\Accountant\AccountMappingService;
use App\Integration\Accountant\LucaAccountPlanImporter;
use Illuminate\Console\Command;

/**
 * Yerel bir hesap planı dosyasını MRC'ye içe aktarır.
 *
 * Bu komut yalnızca yerel veritabanına yazar; dış sisteme yazma işlemi yoktur.
 * --dry-run seçeneği veritabanı yazımını tamamen atlar.
 */
class LucaImportAccountPlanCommand extends Command
{
    protected $signature = 'integration:luca-import-account-plan
                            {file : Yerel hesap planı dosyası (csv/xml/txt)}
                            {--company= : Hedef firma (company_id)}
                            {--dry-run : Yazmadan yalnızca ayrıştırılmış satırları göster}';

    protected $description = 'Luca hesap planını yerel dosyadan içe aktarır';

    private const MAX_FILE_SIZE = 2097152; // 2 MB

    public function handle(): int
    {
        $companyId = (int) $this->option('company');
        if ($companyId <= 0) {
            $this->error('Hedef firma için --company seçeneği zorunludur.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error('Dosya bulunamadı: '.$path);

            return self::FAILURE;
        }

        $size = @filesize($path);
        if ($size === false || $size > self::MAX_FILE_SIZE) {
            $this->error('Dosya okunamadı veya 2 MB sınırını aşıyor: '.$path);

            return self::FAILURE;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            $this->error('Dosya içeriği okunamadı: '.$path);

            return self::FAILURE;
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $parsed = (new LucaAccountPlanImporter)->parse($content, $extension);

        foreach ($parsed['errors'] as $error) {
            $this->error((string) $error);
        }

        if ($parsed['rows'] === []) {
            $this->error('İçe aktarılabilir hesap satırı bulunamadı.');

            return self::FAILURE;
        }

        $this->line(sprintf('Firma %d: %d satır ayrıştırıldı.', $companyId, count($parsed['rows'])));

        if ($this->option('dry-run')) {
            $this->warn('Dry-run: veritabanına yazılmadı.');
            foreach ($parsed['rows'] as $row) {
                $this->line('  '.$row['code'].' - '.$row['name']);
            }

            return self::SUCCESS;
        }

        $summary = (new AccountMappingService)->importPlan($companyId, $parsed['rows']);

        foreach ($summary['errors'] as $error) {
            $this->error('Satır '.$error['row'].': '.$error['message']);
        }

        $this->info(sprintf(
            'Firma %d: toplam=%d, içe aktarılan=%d, atlanan=%d, hata=%d.',
            $companyId,
            $summary['total'],
            $summary['imported'],
            $summary['skipped'],
            count($summary['errors']),
        ));

        return $summary['imported'] > 0 ? self::SUCCESS : self::FAILURE;
    }
}
