<?php

namespace App\Console\Commands\Integration;

use App\Integration\Sync\MrcResyncService;
use Illuminate\Console\Command;

/**
 * MRC is verisini Tsoft'tan yeniden kurar (salt okunur dis erisim).
 *
 * Bu komut hici bir rota kaydetmez; yalnizca CLI uzerinden calistirilir.
 */
class MrcResyncFromTsoftCommand extends Command
{
    protected $signature = 'integration:mrc-resync-from-tsoft
        {--company= : Firma ID (zorunlu)}
        {--confirm : Silme ve ice aktarmayi gercekten yapar (gerekli)}
        {--dry-run : Hici bir sey yazmaz; yalnizca plan gosterir (varsayilan)}
        {--skip-backup : Yedek almaz (yalnizca yerel gelistirme icin)}';

    protected $description = 'MRC is verisini Tsofttan yeniden kurar: varsayilan dry-run, silme icin --confirm zorunlu.';

    public function handle(MrcResyncService $service): int
    {
        $companyOption = $this->option('company');
        $companyId = is_scalar($companyOption) ? (int) $companyOption : 0;

        if ($companyId <= 0) {
            $this->error('Geçersiz firma ID. --company= ile bir firma IDsi verin.');

            return self::FAILURE;
        }

        $confirm = (bool) $this->option('confirm');
        $dryRun = (bool) $this->option('dry-run');
        $skipBackup = (bool) $this->option('skip-backup');

        $this->warn(str_repeat('!', 72));
        $this->warn('MRC -> TSOFT YENIDEN KURULUM');
        $this->warn('Bu islem secilen firmanin MRC is verisini SILER ve Tsofttan yeniden olusturur.');
        $this->warn('Tsoft tarafi YALNIZCA OKUNUR; dis sisteme hici bir sey yazilmaz.');
        $this->warn('Kullanici, rol, yetki, ayar, entegrasyon ve migration tablolarina dokunulmaz.');
        if ($confirm && ! $dryRun) {
            $this->error('ONAY VERILDI: silme + ice aktarma GERCEKTEN yapilacak.');
            if ($skipBackup) {
                $this->error('--skip-backup: YEDEK ALINMAYACAK! Sadece yerel gelistirmede kullanin.');
            } else {
                $this->warn('Silmeden once db:dump-php ile yedek alinacak; yedek basarisizsa islem durur.');
            }
        } else {
            $this->info('DRY-RUN: hici bir satir silinmeyecek veya yazilmayacak (net icin --confirm).');
        }
        $this->warn(str_repeat('!', 72));

        $report = $service->run($companyId, $confirm, $dryRun, $skipBackup);

        $this->table(['Alan', 'Deger'], [
            ['Firma', (string) $report['company_id']],
            ['Mod', $report['executed'] ? 'CALISTIRILDI' : ($report['dry_run'] ? 'DRY-RUN' : 'PLAN')],
            ['Onay', $report['confirmed'] ? 'var' : 'yok'],
            ['Yedek', $this->backupLabel($report['backup'] ?? [])],
            ['Silinen', $this->countLabel($report['deleted'] ?? [])],
            ['Urun / Fiyat / Stok', $this->importLabel($report['imported'] ?? [], ['products', 'prices', 'stock'])],
            ['Siparis / Cari', $this->importLabel($report['imported'] ?? [], ['orders', 'parties'])],
            ['Hata', (string) count($report['errors'] ?? [])],
            ['Sure (ms)', (string) ($report['elapsed_ms'] ?? 0)],
        ]);

        if (! $report['executed']) {
            $plan = is_array($report['plan'] ?? null) ? $report['plan'] : [];
            $delete = is_array($plan['delete'] ?? null) ? $plan['delete'] : [];
            $rows = [];
            foreach ($delete as $table => $count) {
                $rows[] = [(string) $table, (string) $count];
            }
            if ($rows !== []) {
                $this->line('');
                $this->line('Silinecek kayit sayilari (plan):');
                $this->table(['Tablo', 'Satir'], $rows);
            }
            if (isset($plan['reason'])) {
                $this->line((string) $plan['reason']);
            }
        }

        foreach ($report['errors'] ?? [] as $error) {
            $this->error('- '.(string) $error);
        }

        if (($report['status'] ?? 'failed') === 'success') {
            $this->info('Islem tamamlandi.');
        } else {
            $this->warn('Islem basarisiz veya kismi tamamlandi.');
        }

        if ($confirm && ($report['errors'] ?? []) !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $backup */
    private function backupLabel(array $backup): string
    {
        if (! empty($backup['skipped'])) {
            return 'atlandi';
        }
        if (! empty($backup['ok'])) {
            return 'alindi: '.(string) ($backup['file'] ?? '');
        }

        return 'alinamadi';
    }

    /** @param array<string,mixed> $counts */
    private function countLabel(array $counts): string
    {
        $parts = [];
        foreach ($counts as $table => $count) {
            if ((int) $count > 0) {
                $parts[] = $table.'='.$count;
            }
        }

        return $parts === [] ? '0' : implode(', ', $parts);
    }

    /**
     * @param  array<string,mixed>  $imported
     * @param  array<int,string>  $keys
     */
    private function importLabel(array $imported, array $keys): string
    {
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = $key.'='.(string) ($imported[$key] ?? 0);
        }

        return implode(', ', $parts);
    }
}
