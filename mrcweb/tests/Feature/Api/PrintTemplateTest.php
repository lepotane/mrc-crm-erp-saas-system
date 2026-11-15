<?php

namespace Tests\Feature\Api;

use App\Models\PrintTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * YAZDIRMA ŞABLONLARI .
 *
 * Kilitler: türde tek varsayılan, şema v1 dışı 422, kiracı izolasyonu,
 * modül kapalıysa 403, önizleme kayıt oluşturmaz, şablonlu çıktı basılır.
 */
class PrintTemplateTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('sablon@firma.test', 'Sablon Firmasi');
    }

    protected function docDuzen(): array
    {
        return [
            'version' => 1,
            'blocks' => [
                ['key' => 'toplam', 'visible' => true],
                ['key' => 'parti', 'visible' => true],
                ['key' => 'kalemler', 'visible' => true],
                ['key' => 'notlar', 'visible' => false],
            ],
            'style' => ['logoPos' => 'right', 'headerColor' => '#0f766e', 'footerText' => 'Örnek alt bilgi'],
        ];
    }

    protected function etiketDuzen(): array
    {
        return [
            'version' => 1,
            'size' => '50x30',
            'showPrice' => true,
            'fields' => [
                ['key' => 'name', 'x' => 4, 'y' => 3, 'w' => 92, 'h' => 0, 'font' => 9, 'align' => 'left', 'visible' => true],
                ['key' => 'barcode', 'x' => 6, 'y' => 30, 'w' => 88, 'h' => 0, 'font' => 8, 'align' => 'left', 'visible' => true],
                ['key' => 'code', 'x' => 4, 'y' => 78, 'w' => 45, 'h' => 0, 'font' => 7, 'align' => 'left', 'visible' => true],
                ['key' => 'price', 'x' => 55, 'y' => 78, 'w' => 41, 'h' => 0, 'font' => 10, 'align' => 'right', 'visible' => true],
            ],
        ];
    }

    public function test_sablon_olusturma_ilk_kayit_varsayilan_olur(): void
    {
        $h = $this->a['headers'];

        $bir = $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'Sade', 'layout' => $this->docDuzen(),
        ], $h)->assertCreated()->json('data');
        $this->assertTrue($bir['is_default']);

        $iki = $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'Logolu', 'layout' => $this->docDuzen(),
        ], $h)->assertCreated()->json('data');
        $this->assertFalse($iki['is_default']);

        // Farklı türün varsayılanı ayrıdır.
        $uc = $this->apiPost('/api/v1/print-templates', [
            'kind' => 'delivery', 'name' => 'İrsaliye', 'layout' => [
                'version' => 1,
                'blocks' => [['key' => 'parti'], ['key' => 'kalemler'], ['key' => 'imza'], ['key' => 'notlar']],
            ],
        ], $h)->assertCreated()->json('data');
        $this->assertTrue($uc['is_default']);
    }

    public function test_varsayilan_degisince_tek_kalir(): void
    {
        $h = $this->a['headers'];

        $bir = $this->apiPost('/api/v1/print-templates', [
            'kind' => 'quote', 'name' => 'A', 'layout' => $this->docDuzen(),
        ], $h)->assertCreated()->json('data.id');

        $iki = $this->apiPost('/api/v1/print-templates', [
            'kind' => 'quote', 'name' => 'B', 'layout' => $this->docDuzen(),
        ], $h)->assertCreated()->json('data.id');

        $this->apiPost("/api/v1/print-templates/{$iki}/default", [], $h)->assertOk();

        $this->assertFalse(PrintTemplate::find($bir)->is_default);
        $this->assertTrue(PrintTemplate::find($iki)->is_default);
    }

    public function test_gecersiz_duzen_422(): void
    {
        $h = $this->a['headers'];

        // Sürümsüz.
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'X', 'layout' => ['blocks' => []],
        ], $h)->assertStatus(422);

        // Bilinmeyen blok.
        $kot = $this->docDuzen();
        $kot['blocks'][] = ['key' => 'roket', 'visible' => true];
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'X', 'layout' => $kot,
        ], $h)->assertStatus(422)->assertJsonValidationErrors('layout.blocks.4.key');

        // Yinelenen blok.
        $kot = $this->docDuzen();
        $kot['blocks'][] = ['key' => 'parti', 'visible' => true];
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'X', 'layout' => $kot,
        ], $h)->assertStatus(422);

        // Kötü renk + kötü logo konumu.
        $kot = $this->docDuzen();
        $kot['style'] = ['logoPos' => 'orta', 'headerColor' => 'kirmizi'];
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'X', 'layout' => $kot,
        ], $h)->assertStatus(422);

        // Etiket: bilinmeyen alan + aralık dışı koordinat.
        $etk = $this->etiketDuzen();
        $etk['fields'][0]['x'] = 120;
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'label', 'name' => 'E', 'layout' => $etk,
        ], $h)->assertStatus(422);
    }

    public function test_silme_ve_guncelleme(): void
    {
        $h = $this->a['headers'];

        $id = $this->apiPost('/api/v1/print-templates', [
            'kind' => 'receipt', 'name' => 'Fiş', 'layout' => [
                'version' => 1,
                'blocks' => [['key' => 'kalemler'], ['key' => 'toplam'], ['key' => 'odeme'], ['key' => 'altbilgi']],
            ],
        ], $h)->assertCreated()->json('data.id');

        $this->apiPatch("/api/v1/print-templates/{$id}", ['name' => 'Fiş Dar'], $h)
            ->assertOk()->assertJsonPath('data.name', 'Fiş Dar');

        $this->apiDelete("/api/v1/print-templates/{$id}", [], $h)->assertOk();
        $this->assertNull(PrintTemplate::find($id));

        // Varsayılan silinince türün en eskisi devralır.
        $a1 = $this->apiPost('/api/v1/print-templates', [
            'kind' => 'label', 'name' => 'L1', 'layout' => $this->etiketDuzen(),
        ], $h)->assertCreated()->json('data.id');
        $a2 = $this->apiPost('/api/v1/print-templates', [
            'kind' => 'label', 'name' => 'L2', 'layout' => $this->etiketDuzen(),
        ], $h)->assertCreated()->json('data.id');
        $this->apiPost("/api/v1/print-templates/{$a2}/default", [], $h)->assertOk();
        $this->apiDelete("/api/v1/print-templates/{$a2}", [], $h)->assertOk();
        $this->assertTrue(PrintTemplate::find($a1)->is_default);
    }

    public function test_baska_firmanin_sablonu_gorunmez(): void
    {
        $b = $this->register('sablon-b@firma.test', 'Sablon B');

        $id = $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'A', 'layout' => $this->docDuzen(),
        ], $this->a['headers'])->assertCreated()->json('data.id');

        $this->apiGet("/api/v1/print-templates/{$id}", $b['headers'])->assertNotFound();
        $this->apiGet('/api/v1/print-templates', $b['headers'])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_belgeler_modulu_kapalinca_uc_kapanir(): void
    {
        $this->apiPatch('/api/v1/modules/belgeler', ['enabled' => false], $this->a['headers'])->assertOk();

        $this->apiGet('/api/v1/print-templates', $this->a['headers'])
            ->assertForbidden()->assertJsonPath('code', 'module_disabled');
    }

    public function test_onizleme_kayit_olusturmaz_pdf_doner(): void
    {
        $h = $this->a['headers'];

        $pdf = $this->apiPost('/api/v1/print-templates/preview', [
            'kind' => 'invoice', 'layout' => $this->docDuzen(), 'lang' => 'tr',
        ], $h)->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(0, PrintTemplate::where('company_id', $this->a['company_id'])->count());

        $etk = $this->apiPost('/api/v1/print-templates/preview', [
            'kind' => 'label', 'layout' => $this->etiketDuzen(), 'lang' => 'en',
        ], $h)->assertOk();
        $this->assertSame('application/pdf', $etk->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $etk->getContent());
    }

    public function test_sablonlu_fatura_ciktisinda_sira_ve_renk_uygulanir(): void
    {        $h = $this->a['headers'];

        $party = $this->apiPost('/api/v1/parties', ['name' => 'Şablon Müşteri'], $h)
            ->assertCreated()->json('party.id');

        $doc = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $party,
            'items' => [['name' => 'Şablon Kalemi', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 20]],
            'notes' => 'Gizli not',
        ], $h)->assertCreated()->json('document.id');
        $this->apiPost("/api/v1/documents/{$doc}/status", ['status' => 'issued'], $h)->assertOk();

        // Varsayılan şablon: notlar kapalı, toplam parti öncesinde, yeşil başlık.
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'Sade', 'layout' => $this->docDuzen(),
        ], $h)->assertCreated();

        $html = view('pdf.document', (new \App\Http\Controllers\Api\V1\DocumentPdfController)->payload(
            \App\Models\Document::with('items')->find($doc)
        ))->render();

        // notlar kapalı → not metni çıkmaz; başlık rengi yeşil.
        $this->assertStringNotContainsString('Gizli not', $html);
        $this->assertStringContainsString('#0f766e', $html);
    }

    public function test_sablonlu_etiket_bestelenir(): void
    {
        if (! \App\Services\LabelRenderer::available()) {
            $this->markTestSkipped('GD FreeType yok.');
        }
        $h = $this->a['headers'];

        $urun = $this->apiPost('/api/v1/products', [
            'name' => 'Beste Ürünü', 'barcode' => '869123456789', 'sale_price' => 250,
        ], $h)->assertCreated()->json('product.id');

        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'label', 'name' => 'L', 'layout' => $this->etiketDuzen(),
        ], $h)->assertCreated();

        $pdf = $this->apiPost('/api/v1/products/labels', [
            'items' => [['product_id' => $urun, 'qty' => 1]], 'size' => '38x25',
        ], $h)->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        // Beste tek resimdir: PDF'te gömülü 1 PNG olur.
        $this->assertSame(1, substr_count($pdf->getContent(), '/Subtype /Image'));

        // Şablon ölçüsü isteği ezer (50x30).
        $this->assertStringContainsString('width: 50mm', view('pdf.labels', [
            'labels' => [['name' => 'X', 'code' => '1', 'image' => 'data:image/png;base64,', 'price' => 5, 'composed' => true]],
            'showPrice' => true,
            'size' => '50x30',
            'dims' => [50, 30],
            'money' => fn ($v) => (string) $v,
        ])->render());
    }

    public function test_ozel_metin_blogu_sira_ve_stil_detaylari(): void
    {
        $h = $this->a['headers'];

        $party = $this->apiPost('/api/v1/parties', ['name' => 'Detay Cari'], $h)
            ->assertCreated()->json('party.id');

        $doc = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $party,
            'items' => [['name' => 'Kalem', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 20]],
        ], $h)->assertCreated()->json('document.id');
        $this->apiPost("/api/v1/documents/{$doc}/status", ['status' => 'issued'], $h)->assertOk();

        $duzen = $this->docDuzen();
        $duzen['blocks'] = [
            ['key' => 'ozel', 'visible' => true, 'text' => "Banka: TR00 0000\nAçıklama satırı"],
            ['key' => 'kalemler', 'visible' => true],
            ['key' => 'toplam', 'visible' => true],
            ['key' => 'parti', 'visible' => true],
        ];
        $duzen['style'] = array_merge($duzen['style'], [
            'fontScale' => 'large', 'logoVisible' => false,
            'columns' => ['iskonto' => false],
            'totals' => ['iskonto' => false, 'kdv' => false],
        ]);

        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'Detay', 'layout' => $duzen,
        ], $h)->assertCreated();

        $html = view('pdf.document', (new \App\Http\Controllers\Api\V1\DocumentPdfController)->payload(
            \App\Models\Document::with('items')->find($doc)
        ))->render();

        // Özel blok metni kaçışlı + satır sonlu basılır.
        $this->assertStringContainsString('Banka: TR00 0000', $html);
        $this->assertStringContainsString('<br', $html);
        $this->assertStringNotContainsString('<script', $html);
        // İskonto sütunu gizli.
        $this->assertStringNotContainsString('İskonto', $html);
        // KDV toplam satırı gizli ama genel toplam durur.
        $this->assertStringNotContainsString('>KDV:<', $html);
        $this->assertStringContainsString('Genel toplam', $html);
        // Büyük yazı (12px gövde).
        $this->assertStringContainsString('font-size: 12px', $html);
    }

    public function test_detay_dogrulama_kurallari(): void
    {
        $h = $this->a['headers'];

        // Metinsiz özel blok.
        $kot = $this->docDuzen();
        $kot['blocks'][] = ['key' => 'ozel', 'visible' => true];
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'X', 'layout' => $kot,
        ], $h)->assertStatus(422)->assertJsonValidationErrors('layout.blocks.4.text');

        // Bilinmeyen sütun + toplam + ölçek.
        $kot = $this->docDuzen();
        $kot['style']['columns'] = ['roket' => true];
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'X', 'layout' => $kot,
        ], $h)->assertStatus(422);

        $kot = $this->docDuzen();
        $kot['style']['totals'] = ['roket' => true];
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'X', 'layout' => $kot,
        ], $h)->assertStatus(422);

        $kot = $this->docDuzen();
        $kot['style']['fontScale'] = 'devasa';
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'X', 'layout' => $kot,
        ], $h)->assertStatus(422);

        // Etiket satır sayısı aralığı.
        $etk = $this->etiketDuzen();
        $etk['fields'][0]['maxLines'] = 9;
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'label', 'name' => 'X', 'layout' => $etk,
        ], $h)->assertStatus(422);
    }

    public function test_etiket_satir_sayisi_besteyi_degistirir(): void
    {
        if (! \App\Services\LabelRenderer::available()) {
            $this->markTestSkipped('GD FreeType yok.');
        }

        $duzen = $this->etiketDuzen();
        $alanlar = function (int $satir) use ($duzen) {
            $d = $duzen;
            $d['fields'][0]['maxLines'] = $satir;
            return $d;
        };
        $veri = [
            'name' => 'Çok Uzun Ürün Adı Test Satırı 12345', 'code' => '240000000001',
            'price' => 1750.0, 'barcode' => '240000000001', 'showPrice' => true, 'lang' => 'tr',
        ];
        $bir = \App\Services\LabelRenderer::compose([50, 30], $alanlar(1)['fields'], $veri);
        $uc = \App\Services\LabelRenderer::compose([50, 30], $alanlar(3)['fields'], $veri);
        $this->assertNotSame(md5($bir), md5($uc));
    }

    public function test_onizleme_gercek_veriyle_belge_ve_urun(): void
    {
        $h = $this->a['headers'];

        $party = $this->apiPost('/api/v1/parties', ['name' => 'Gerçek Cari'], $h)
            ->assertCreated()->json('party.id');
        $urun = $this->apiPost('/api/v1/products', [
            'name' => 'Çok Uzun Gerçek Ürün Adı Test 12345', 'barcode' => '869123456789', 'sale_price' => 999,
        ], $h)->assertCreated()->json('product.id');
        $doc = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $party,
            'items' => [['product_id' => $urun, 'quantity' => 1, 'unit_price' => 999, 'tax_rate' => 20]],
        ], $h)->assertCreated()->json('document.id');

        // Belge HTML önizlemede gerçek cari + ürün adı görünür.
        $html = $this->apiPost('/api/v1/print-templates/preview-html', [
            'kind' => 'invoice', 'layout' => $this->docDuzen(), 'lang' => 'tr', 'document_id' => $doc,
        ], $h)->assertOk()->json('html');
        $this->assertStringContainsString('Gerçek Cari', $html);
        $this->assertStringContainsString('Çok Uzun Gerçek Ürün Adı', $html);

        // Etiket PDF önizlemede gerçek ürün adı bestelenir.
        $pdf = $this->apiPost('/api/v1/print-templates/preview', [
            'kind' => 'label', 'layout' => $this->etiketDuzen(), 'lang' => 'tr', 'product_id' => $urun,
        ], $h)->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Başka firmanın belgesi/ürünü 422.
        $b = $this->register('gercek-b@firma.test', 'Gercek B');
        $this->apiPost('/api/v1/print-templates/preview-html', [
            'kind' => 'invoice', 'layout' => $this->docDuzen(), 'document_id' => $doc,
        ], $b['headers'])->assertStatus(422);
        $this->apiPost('/api/v1/print-templates/preview', [
            'kind' => 'label', 'layout' => $this->etiketDuzen(), 'product_id' => $urun,
        ], $b['headers'])->assertStatus(422);
    }

    public function test_ozel_tip_dogrulama_ve_html_onizleme(): void
    {
        $h = $this->a['headers'];

        // Geçersiz tip + aralık dışı boşluk.
        $kot = $this->docDuzen();
        $kot['blocks'][] = ['key' => 'ozel', 'tip' => 'roket', 'visible' => true];
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'X', 'layout' => $kot,
        ], $h)->assertStatus(422)->assertJsonValidationErrors('layout.blocks.4.tip');

        $kot = $this->docDuzen();
        $kot['blocks'][] = ['key' => 'ozel', 'tip' => 'bosluk', 'yukseklik' => 99, 'visible' => true];
        $this->apiPost('/api/v1/print-templates', [
            'kind' => 'invoice', 'name' => 'X', 'layout' => $kot,
        ], $h)->assertStatus(422);

        // HTML önizleme: cizgi + bosluk + metin basılır, data-blok işaretlidir.
        $duzen = $this->docDuzen();
        $duzen['blocks'] = [
            ['key' => 'ozel', 'tip' => 'cizgi', 'visible' => true],
            ['key' => 'ozel', 'tip' => 'bosluk', 'yukseklik' => 10, 'visible' => true],
            ['key' => 'ozel', 'tip' => 'metin', 'text' => 'Kaşe', 'visible' => true],
            ['key' => 'kalemler', 'visible' => true],
        ];
        unset($duzen['style']['columns'], $duzen['style']['totals']);
        $res = $this->apiPost('/api/v1/print-templates/preview-html', [
            'kind' => 'invoice', 'layout' => $duzen, 'lang' => 'tr',
        ], $h)->assertOk();
        $html = $res->json('html');
        $this->assertStringContainsString('data-blok="ozel"', $html);
        $this->assertStringContainsString('data-blok="kalemler"', $html);
        $this->assertStringContainsString('Kaşe', $html);
        $this->assertStringContainsString('height: 10mm', $html);

        // Etiket türü HTML önizlemeye kapalı.
        $this->apiPost('/api/v1/print-templates/preview-html', [
            'kind' => 'label', 'layout' => $this->etiketDuzen(), 'lang' => 'tr',
        ], $h)->assertStatus(422);
    }
}
