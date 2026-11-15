<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GÜVENLİK KİLİDİ: depoda hardcoded sır kalmaz.
 *
 * `scripts/secret-scan.mjs` kuralları:
 *   A) uygulama kodunda parola/anahtar alanına atanan düz metin
 *   B) Hash::make('...') / bcrypt('...') içinde düz metin
 *   C) dokümanda parola değeri
 *   D) git'e takip edilmiş .env
 *   E) gerçek APP_KEY
 *
 * Test hem (1) mevcut ağacın temiz olduğunu hem de (2) tarayıcının gerçekten
 * sır yakaladığını kanıtlar — ikincisi olmazsa tarayıcı boşa çalışıyor demektir.
 */
class SecretScanTest extends TestCase
{
    protected string $kok;

    protected function setUp(): void
    {
        parent::setUp();

        // Repo kökü: mrcweb/tests/Feature → ../.. = mrcweb → ../../.. = repo kökü
        $this->kok = dirname(__DIR__, 3);
    }

    protected function tara(): array
    {
        $desen = $this->kok.'/scripts/secret-scan.mjs';

        if (! is_file($desen)) {
            $this->markTestSkipped('secret-scan.mjs yok');
        }

        $cmd = 'node '.escapeshellarg($desen).' 2>&1';
        exec($cmd, $cikti, $kod);

        return ['cikti' => implode("\n", $cikti), 'kod' => $kod];
    }

    public function test_depoda_hardcoded_sir_yok(): void
    {
        $sonuc = $this->tara();

        $this->assertSame(
            0,
            $sonuc['kod'],
            "secret-scan sır buldu:\n".$sonuc['cikti']
        );
    }

    public function test_tarayici_gercekten_sir_yakalıyor(): void
    {
        $gecici = $this->kok.'/mrcweb/app/Support/GeciciSIRSinifi.php';

        file_put_contents($gecici, <<<'PHP'
<?php

namespace App\Support;

class GeciciSIRSinifi
{
    public const PASSWORD = 'BuGizliParola2026X';
}
PHP);

        try {
            $sonuc = $this->tara();

            $this->assertSame(
                1,
                $sonuc['kod'],
                'Tarayıcı bilerek konulan sırı yakalamadı — kural bozuk.'
            );
        } finally {
            @unlink($gecici);
        }
    }

    public function test_geri_alindiktan_sonra_tekrar_temiz(): void
    {
        $sonuc = $this->tara();

        $this->assertSame(0, $sonuc['kod'], $sonuc['cikti']);
    }
}
