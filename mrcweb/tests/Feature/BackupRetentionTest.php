<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use ZipArchive;

/**
 * Zip yedeklerinin saklama penceresi: günlük alınır, 7 günlük kayan pencere tutulur.
 *
 * Kullanıcı kuralı (31 Eki 2026): "her 7 günün sonunda, 8'inci günde 1'inci günün
 * dosyası silinip yeni günün dosyası yazılacak." Yani diskte her zaman son 7 günün
 * yedeği durur; kademeli arşiv (haftanın biri, ayın biri...) İSTENMİYOR.
 *
 * Testler gerçek `backup:clean` komutunu çalıştırır (stratejiyi paket çağırır),
 * geçici bir disk kökü + test adına yazar; böylece gerçek yedek klasörüne dokunmaz.
 * Zip'ler gerçek zip'tir: Spatie listelerken dosyanın zip sihirli baytlarına bakar.
 */
class BackupRetentionTest extends TestCase
{
    private string $kok;

    private string $ad;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(now());

        $this->kok = storage_path('app/backup-retention-test-'.getmypid());
        $this->ad = 'MRCCleanTest';

        config([
            'filesystems.disks.backups.root' => $this->kok,
            'backup.backup.name' => $this->ad,
            'backup.backup.destination.disks' => ['backups'],
        ]);

        @mkdir($this->kok.DIRECTORY_SEPARATOR.$this->ad, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->kok.DIRECTORY_SEPARATOR.$this->ad.DIRECTORY_SEPARATOR.'*') ?: [] as $dosya) {
            @unlink((string) $dosya);
        }

        @rmdir($this->kok.DIRECTORY_SEPARATOR.$this->ad);
        @rmdir($this->kok);

        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Gerçek bir zip üretir; adı Spatie'nin kalıbına uyar: Y-m-d-H-i-s.zip. */
    private function zip(string $ad, int $bayt = 1024): string
    {
        $yol = $this->kok.DIRECTORY_SEPARATOR.$this->ad.DIRECTORY_SEPARATOR.$ad;

        $zip = new ZipArchive();

        if ($zip->open($yol, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->fail('zip açılamadı: '.$yol);
        }

        // Sıkıştırılamayan veri koy: kota testi MB eşikle çalışsın.
        $zip->addFromString('rastgele.bin', random_bytes(max(64, $bayt)));
        $zip->close();

        return $yol;
    }

    /** $gunOnce gün önceki yedeğin dosya adı. */
    private function ad(int $gunOnce): string
    {
        return now()->subDays($gunOnce)->format('Y-m-d-H-i-s').'.zip';
    }

    private function temizle(): array
    {
        $kod = Artisan::call('backup:clean');

        return [$kod, Artisan::output()];
    }

    private function kalanlar(): array
    {
        $dosyalar = glob($this->kok.DIRECTORY_SEPARATOR.$this->ad.DIRECTORY_SEPARATOR.'*.zip') ?: [];
        sort($dosyalar);

        return array_map('basename', $dosyalar);
    }

    public function test_yedinci_gunden_eski_zip_silinir_pencere_yedi_gunde_kalir(): void
    {
        // 1..6 gün önce: pencerenin içinde. 8 gün önce: pencerenin dışı.
        foreach ([1, 2, 3, 4, 5, 6, 8] as $gun) {
            $this->zip($this->ad($gun));
        }

        $onceki = $this->kalanlar();
        $this->assertCount(7, $onceki);

        [$kod, $cikti] = $this->temizle();

        $this->assertSame(0, $kod, $cikti);

        $kalan = $this->kalanlar();

        $this->assertNotContains($this->ad(8), $kalan, '8. günün dosyası silinmeli');
        $this->assertContains($this->ad(1), $kalan);
        $this->assertContains($this->ad(6), $kalan, 'pencere içindeki yedeğe dokunulmaz');
        $this->assertCount(6, $kalan);
    }

    public function test_yeni_gunun_dosyasi_yazildiktan_sonra_pencere_yine_yedi_dosyadir(): void
    {
        // 1..7 gün önceki yedekler + "bugün"ün yedeği → 8 dosya olur, temizlik
        // 1'inciyi (8 günlük olanı) düşürür: kullanıcının tarif ettiği tam hareket.
        $eskiEnYeni = $this->ad(8);
        $yeni = $this->ad(0);

        foreach ([1, 2, 3, 4, 5, 6, 8] as $gun) {
            $this->zip($this->ad($gun));
        }

        $this->zip($yeni);

        [$kod] = $this->temizle();

        $this->assertSame(0, $kod);

        $kalan = $this->kalanlar();

        $this->assertNotContains($eskiEnYeni, $kalan, '7+1. dosya (en eski) silinmeli');
        $this->assertContains($yeni, $kalan, 'yeni günün yedeği her zaman kalmalı');
        $this->assertCount(7, $kalan, 'pencere 7 dosyada sabitlenmeli');
    }

    public function test_tum_yedekler_eskiyse_bile_en_yeni_zip_silinmez(): void
    {
        // Site bir hafta yedek alamadıysa: hepsi "eski" ama elimizde bir tane kalmalı.
        foreach ([28, 29, 30] as $gun) {
            $this->zip($this->ad($gun));
        }

        [$kod] = $this->temizle();

        $this->assertSame(0, $kod);

        $kalan = $this->kalanlar();

        $this->assertSame([$this->ad(28)], $kalan, 'yalnız en yeni (28 gün önceki) kalmalı');
    }

    public function test_yedegin_tarihi_dosya_adindan_okunur_mtime_degil(): void
    {
        // Spatie ad çözümlenemezse dosya mtimesine düşer (31 Eki 2026'da ölçüldü).
        // Adı ESKİ, mtimesi BUGÜN olan bir yedek: kendi stratejimiz adı okuduğu için
        // bunu SİLER. mtime'a düşseydi "taze" sanıp saklardı.
        $eskiAdli = $this->zip($this->ad(30));
        touch($eskiAdli);                       // mtime = şimdi
        $this->zip($this->ad(1));               // gerçek taze yedek (en yeni)

        [$kod] = $this->temizle();

        $this->assertSame(0, $kod);
        $this->assertFileDoesNotExist($eskiAdli, 'tarih dosya adından okunmalı');
        $this->assertFileExists($this->kok.DIRECTORY_SEPARATOR.$this->ad.DIRECTORY_SEPARATOR.$this->ad(1));
    }

    public function test_disk_kotasini_asan_en_eski_zipler_silinir(): void
    {
        // Yaş kuralı devre dışı (30 gün), kota 1 MB: ~300 KB'lık dört yedeğin
        // en eskisi/ağırı silinip eşik altına inilmeli.
        config([
            'backup.mrc.keep_days' => 30,
            'backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than' => 1,
        ]);

        foreach ([1, 2, 3, 4] as $gun) {
            $this->zip($this->ad($gun), 400_000);
        }

        $this->assertCount(4, $this->kalanlar());

        [$kod, $cikti] = $this->temizle();

        $this->assertSame(0, $kod, $cikti);

        $kalan = $this->kalanlar();

        $this->assertLessThan(4, count($kalan), 'kota aşımı eski yedekleri sildirmeli');
        $this->assertContains($this->ad(1), $kalan, 'en yeni yedek kotalar için bile silinmez');
        $this->assertNotContains($this->ad(4), $kalan, 'en eskiden başlanmalı');
    }

    public function test_keep_days_sifir_ve_kota_kapali_ise_hicbir_zip_silinmez(): void
    {
        config([
            'backup.mrc.keep_days' => 0,
            'backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than' => 0,
        ]);

        $adlar = [];

        foreach ([1, 40, 200] as $gun) {
            $adlar[] = $this->zip($this->ad($gun));
        }

        [$kod] = $this->temizle();

        $this->assertSame(0, $kod);
        $this->assertCount(3, $this->kalanlar(), '--keep=0 / kota kapalı: hiçbiri silinmez');

        foreach ($adlar as $yol) {
            $this->assertFileExists($yol);
        }
    }
}
