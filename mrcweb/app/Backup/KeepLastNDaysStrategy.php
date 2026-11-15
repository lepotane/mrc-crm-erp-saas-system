<?php

namespace App\Backup;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupCollection;
use Spatie\Backup\Tasks\Cleanup\CleanupStrategy;

/**
 * MRC saklama stratejisi: günlük yedek al, YEDİ GÜNLÜK kayan pencereyi sakla.
 *
 * Kullanıcı kuralı (31 Eki 2026): "yedek günlük olacak, haftalık saklanacak —
 * 8'inci gün 1'inci günün dosyası silinir, yeni günün dosyası yazılır."
 * Yani diskte her zaman son 7 günün yedeği durur; daha eskisi silinir.
 *
 * Neden Spatie'nin DefaultStrategy'si değil: o strateji 7 gün hepsi → 16 gün
 * günde bir → 8 hafta haftada bir → ay → yıl şeklinde KADEMELİ bir arşiv tutar;
 * yani 7. günden eski yedekleri de (haftanın en eskisi olarak) saklamaya devam
 * eder. Biz kademeli arşiv istemiyoruz: kota dostu, tahmin edilebilir bir pencere.
 *
 * İkinci koruma disk kotasıdır (paylaşımlı host): yaş kuralı yetersiz kalırsa
 * (yedekler büyümüşse) en eskiden başlayarak eşik altına inilir.
 *
 * En yeni yedek HİÇBİR koşulda silinmez: tüm yedekler "eski" sayılsa bile (ör.
 * site bir hafta kapalı kaldı) elde bir tane kalmalı; yedeksiz kalmak, eski
 * yedek taşımaktan kötüdür.
 */
class KeepLastNDaysStrategy extends CleanupStrategy
{
    /**
     * @param  BackupCollection<Backup>  $backups  eskiden yeniye değil, YENİDEN ESKİYE sıralı gelir
     */
    public function deleteOldBackups(BackupCollection $backups): void
    {
        $gun = $this->gun();

        if ($gun > 0) {
            $sinir = Carbon::now()->subDays($gun);
            $enYeni = $backups->newest();

            foreach ($backups as $yedek) {
                // En yeni dosya her zaman kalır (yukarıdaki gerekçe).
                if ($enYeni !== null && $yedek->path() === $enYeni->path()) {
                    continue;
                }

                if ($this->tarih($yedek)->lt($sinir)) {
                    $yedek->delete();
                }
            }
        }

        $this->kotayaIn($backups);
    }

    /**
     * Yaş kuralından sonra hâlâ kota aşılmışsa en eskiden başlayarak siler.
     * Eşik 0/null ise kota denetimi kapalıdır (yalnız yaş kuralı işler).
     */
    private function kotayaIn(BackupCollection $backups): void
    {
        $mb = (int) $this->config->get(
            'backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than',
            0
        );

        if ($mb <= 0) {
            return;
        }

        $tavan = $mb * 1024 * 1024;

        // Diskteki gerçek listeyi yeniden oku: az önce silinen dosyalar da sayılsın.
        $kalan = $this->backupDestination()->fresh()->backups();

        while ($kalan->count() > 1 && $kalan->size() > $tavan) {
            $eski = $kalan->oldest();

            if ($eski === null) {
                return;
            }

            $eski->delete();
            $kalan = $this->backupDestination()->fresh()->backups();
        }
    }

    /**
     * Yedeğin tarihi. ÖNCE dosya adından okunur (`2026-10-31-02-30-00.zip`),
     * ad yoksa paketin `date()` değerine düşülür.
     *
     * Neden ad önce: Spatie dosya adını `Y-m-d-H-i-s.zip` biçiminde çözümler; adın
     * başına önek (ör. `mrc-`) konursa çözümleme BAŞARISIZ OLUR ve paket dosya
     * mtimesine düşer (31 Eki 2026'da ölçüldü: `mrc-2026-10-24-02-30-00.zip` →
     * 24 Ekim değil, dosyanın bugünkü mtimesi). Kopyalanmış/taşınmış bir yedeğin
     * mtimesi gerçek yedek tarihini taşımaz; ad ise taşır.
     */
    private function tarih(Backup $yedek): CarbonInterface
    {
        $ad = basename($yedek->path());

        if (preg_match('/(\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})/', $ad, $altKume) === 1) {
            try {
                return Carbon::createFromFormat('Y-m-d-H-i-s', $altKume[1]);
            } catch (\Exception) {
                // ad bozuksa paketin değeriyle devam et
            }
        }

        return $yedek->date();
    }

    private function gun(): int
    {
        return (int) $this->config->get('backup.mrc.keep_days', 7);
    }
}
