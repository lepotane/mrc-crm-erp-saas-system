<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `php artisan db:dump-php` — veritabanı dökümü .
 *
 * Testler sqlite ile çalıştığı için gerçek MySQL dökümü burada denenemez (canlı
 * prova . Kilitlenen davranışlar:
 * - sqlite gibi desteklenmeyen sürücülerde döküm YAPMAZ ama cron zincirini de
 *   kırmaz (uyarı + exit 0),
 * - eski dökümleri `--keep` süresiyle siler (kota),
 * - disk doluysa / dizin yazılamıyorsa yarım dosya bırakmadan durur,
 * - bozuk bağlantıda exit 1 ve okunabilir hata.
 */
class DbDumpCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Geçici döküm dizini (test sonrası silinir). */
    private string $gecici;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gecici = storage_path('app/db-dumps-test-'.getmypid());
        @mkdir($this->gecici, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->gecici.DIRECTORY_SEPARATOR.'*') ?: [] as $dosya) {
            @unlink((string) $dosya);
        }

        @rmdir($this->gecici);

        parent::tearDown();
    }

    /**
     * @param  array<string, string|int>  $secenekler
     * @return array{0: int, 1: string}
     */
    private function calistir(array $secenekler = []): array
    {
        $kod = Artisan::call('db:dump-php', $secenekler);

        return [$kod, Artisan::output()];
    }

    /**
     * Döküm adı taşıyan sahte dosya üretir; istenirse eski tarihli yapılır.
     */
    private function dokumDosyasi(string $ad, ?int $gunOnce = null): string
    {
        $yol = $this->gecici.DIRECTORY_SEPARATOR.$ad;
        file_put_contents($yol, 'sahte-dokum');

        if ($gunOnce !== null) {
            touch($yol, time() - ($gunOnce * 86400));
        }

        return $yol;
    }

    public function test_sqlite_surucusunde_dokum_yapmaz_ama_zinciri_kirmaz(): void
    {
        [$kod, $cikti] = $this->calistir(['--dir' => $this->gecici]);

        // cron zinciri (02:00 → 02:30) uyarı yüzünden kırılmamalı: exit 0
        $this->assertSame(0, $kod, $cikti);
        $this->assertStringContainsString('atlandı', $cikti);
        $this->assertStringContainsString('mysql', $cikti);

        // Ama ortada sahte bir .sql.gz de bırakmamalı
        $this->assertSame([], glob($this->gecici.DIRECTORY_SEPARATOR.'*.sql.gz'));
    }

    public function test_keep_degerinden_eski_dokumler_silinir(): void
    {
        $eski1 = $this->dokumDosyasi('mrc-2026-01-01-020000.sql.gz', 40);
        $eski2 = $this->dokumDosyasi('mrc-2026-01-02-020000.sql.gz', 20);
        $yeni = $this->dokumDosyasi('mrc-2026-10-31-020000.sql.gz', 2);

        [$kod, $cikti] = $this->calistir(['--dir' => $this->gecici, '--keep' => 7]);

        $this->assertSame(0, $kod, $cikti);
        $this->assertStringContainsString('2 eski döküm silindi', $cikti);

        $this->assertFileDoesNotExist($eski1);
        $this->assertFileDoesNotExist($eski2);
        $this->assertFileExists($yeni, 'keep içindeki döküme dokunulmaz');
    }

    public function test_keep_sifir_hicbir_dokumu_silmez(): void
    {
        $eski = $this->dokumDosyasi('mrc-2020-01-01-020000.sql.gz', 400);

        [$kod] = $this->calistir(['--dir' => $this->gecici, '--keep' => 0]);

        $this->assertSame(0, $kod);
        $this->assertFileExists($eski, '--keep=0 hiçbir dökümü silmez');
    }

    public function test_bilinmeyen_baglanti_hata_verir(): void
    {
        [$kod, $cikti] = $this->calistir(['--connection' => 'boyle_bir_baglanti_yok', '--dir' => $this->gecici]);

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('Bilinmeyen veritabanı bağlantısı', $cikti);
    }

    public function test_disk_doluyken_yarim_dokum_uretilmez(): void
    {
        // Sahte mysql bağlantısı: sürücü kontrolünü geçsin ama gerçek bağlantı
        // kurulmasın; disk kontrolü dökümden ÖNCE çalıştığını kanıtlasın.
        config([
            'database.connections.mrc_disk_testi' => [
                'driver' => 'mysql',
                'url' => null,
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'mrc_boyle_bir_veritabani_yok',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
            ],
            'backup.mrc.db_dump.min_free_mb' => 10_000_000, // imkânsız eşik
        ]);

        [$kod, $cikti] = $this->calistir([
            '--connection' => 'mrc_disk_testi',
            '--dir' => $this->gecici,
        ]);

        $this->assertSame(1, $kod, $cikti);
        $this->assertStringContainsString('yeterli yer yok', $cikti);
        $this->assertSame([], glob($this->gecici.DIRECTORY_SEPARATOR.'*.sql.gz'), 'yarım dosya bırakılmamalı');
    }

    public function test_dokum_basarisizsa_yarim_dosya_birakilmaz(): void
    {
        config([
            'database.connections.mrc_dokum_testi' => [
                'driver' => 'mysql',
                'url' => null,
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'mrc_boyle_bir_veritabani_yok',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
            ],
            'backup.mrc.db_dump.min_free_mb' => 0, // disk kontrolü devre dışı
        ]);

        [$kod, $cikti] = $this->calistir([
            '--connection' => 'mrc_dokum_testi',
            '--dir' => $this->gecici,
        ]);

        $this->assertSame(1, $kod, $cikti);
        $this->assertStringContainsString('Döküm başarısız', $cikti);
        $this->assertSame([], glob($this->gecici.DIRECTORY_SEPARATOR.'*.sql.gz'));
    }
}
