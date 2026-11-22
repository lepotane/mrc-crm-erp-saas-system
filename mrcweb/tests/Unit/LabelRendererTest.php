<?php

namespace Tests\Unit;

use App\Services\LabelRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Etiket metin sığdırma : kutuya sığmayan yazı küçültülür,
 * en kötü küçük basılır — metin asla "yok olmaz".
 */
class LabelRendererTest extends TestCase
{
    protected string $font;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagettfbbox')) {
            $this->markTestSkipped('GD FreeType yok.');
        }
        $this->font = dirname(__DIR__, 2) . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
        if (! is_file($this->font)) {
            $this->markTestSkipped('DejaVu fontu yok.');
        }
    }

    public function test_sigmayan_yazi_kucultulerek_sigdirilir(): void
    {
        // 92% 38mm etiket ≈ 419px kutu; uzun isim 9pt (38px) ile 2 satıra sığmaz.
        $r = LabelRenderer::sigdir('Ornek Urun Adi 433 MHz 2016 Plus 8A', $this->font, 38.0, 419.0, 2);
        $this->assertTrue($r['tam']);
        $this->assertLessThan(38.0, $r['px']);
        $this->assertLessThanOrEqual(2, count($r['lines']));
        $this->assertSame('Ornek Urun Adi 433 MHz 2016 Plus 8A',
            implode(' ', array_map(fn ($s) => trim($s), $r['lines'])));
    }

    public function test_kisa_metin_ayni_boyutta_kalir(): void
    {
        $r = LabelRenderer::sigdir('Ornek Urun', $this->font, 38.0, 419.0, 2);
        $this->assertTrue($r['tam']);
        $this->assertSame(38.0, $r['px']);
        $this->assertSame(['Ornek Urun'], $r['lines']);
    }

    public function test_tek_satir_kod_kutuya_sigdirilir(): void
    {
        $r = LabelRenderer::sigdir('1234567890123', $this->font, 22.0, 200.0, 1);
        $this->assertTrue($r['tam']);
        $this->assertCount(1, $r['lines']);
    }
}
