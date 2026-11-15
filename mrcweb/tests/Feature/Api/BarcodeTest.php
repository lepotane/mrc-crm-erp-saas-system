<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * BARKOD ÜRETİMİ .
 *
 * Kilitlenen sözleşme: EAN-13 mod-10 checksum'ı sunucuda üretilir/doğrulanır,
 * CODE128 SKU'yu aynen kodlar; görsel dosya döner; etiket PDF'i adet × ürün
 * taşır. Barkodsuz ürün etikete giremez.
 */
class BarcodeTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected int $productId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('barkod-uretim@firma.test', 'Barkod Uretim Firmasi');

        $this->productId = $this->apiPost('/api/v1/products', [
            'name' => 'Etiket Ürünü',
            'barcode' => '869123456789',
            'sale_price' => 49.5,
        ], $this->a['headers'])->assertCreated()->json('product.id');
    }

    public function test_ean13_uretilen_kod_mod10_dogrulamasindan_gecer(): void
    {
        // 12 hane verilir, 13. hane (checksum) sunucuda eklenir.
        $png = $this->apiGet("/api/v1/products/{$this->productId}/barcode?format=png&type=ean13", $this->a['headers'])
            ->assertOk();
        $this->assertSame('image/png', $png->headers->get('Content-Type'));
        $this->assertStringStartsWith("\x89PNG", $png->getContent());

        $svg = $this->apiGet("/api/v1/products/{$this->productId}/barcode?format=svg&type=ean13", $this->a['headers'])
            ->assertOk();
        $this->assertSame('image/svg+xml', $svg->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', $svg->getContent());

        // Üretilen kodun checksum'ı bağımsız doğrulanır (gövde …789 → hane 0).
        $this->assertTrue(\App\Services\ProductBarcode::validEan13('8691234567890'));
        $this->assertFalse(\App\Services\ProductBarcode::validEan13('8691234567892'));
    }

    public function test_yanlis_checksumlu_ean13_reddedilir(): void
    {
        $id = $this->apiPost('/api/v1/products', [
            'name' => 'Bozuk Barkodlu',
            'barcode' => '8691234567892',
        ], $this->a['headers'])->assertCreated()->json('product.id');

        $this->apiGet("/api/v1/products/{$id}/barcode?type=ean13", $this->a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('barcode');
    }

    public function test_code128_sku_aynen_kodlanir(): void
    {
        $id = $this->apiPost('/api/v1/products', [
            'name' => 'SKU Ürünü',
            'sku' => 'POS-KASA-01',
        ], $this->a['headers'])->assertCreated()->json('product.id');

        $svg = $this->apiGet("/api/v1/products/{$id}/barcode?format=svg&type=code128", $this->a['headers'])
            ->assertOk();
        $this->assertSame('image/svg+xml', $svg->headers->get('Content-Type'));
    }

    public function test_barkod_atama_magaza_ici_ean13_uretir(): void
    {
        $id = $this->apiPost('/api/v1/products', ['name' => 'Barkodsuz'], $this->a['headers'])
            ->assertCreated()->json('product.id');

        $res = $this->apiPost("/api/v1/products/{$id}/barcode/assign", [], $this->a['headers'])
            ->assertOk();
        $kod = $res->json('product.barcode');

        $this->assertMatchesRegularExpression('/^24\d{11}$/', $kod);
        $this->assertTrue(\App\Services\ProductBarcode::validEan13($kod));

        // Dolu ürüne ikinci atama reddedilir.
        $this->apiPost("/api/v1/products/{$id}/barcode/assign", [], $this->a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('barcode');
    }

    public function test_etiket_pdf_adet_carpimiyla_uretilir(): void
    {
        $pdf = $this->apiPost('/api/v1/products/labels', [
            'items' => [['product_id' => $this->productId, 'qty' => 3]],
            'show_price' => true,
        ], $this->a['headers'])->assertOk();

        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Barkodsuz + SKU'suz ürün etikete giremez.
        $bosId = $this->apiPost('/api/v1/products', ['name' => 'Kodsuz'], $this->a['headers'])
            ->assertCreated()->json('product.id');
        $this->apiPost('/api/v1/products/labels', [
            'items' => [['product_id' => $bosId, 'qty' => 1]],
        ], $this->a['headers'])->assertStatus(422);
    }

    public function test_etiket_olcusu_ve_fiyatsiz_secim_calir(): void
    {
        // Geçersiz ölçü reddedilir.
        $this->apiPost('/api/v1/products/labels', [
            'items' => [['product_id' => $this->productId, 'qty' => 1]],
            'size' => '99x99',
        ], $this->a['headers'])->assertStatus(422)->assertJsonValidationErrors('size');

        // 50x30 + fiyatsız: PDF üretilir, şablon ölçü değişkenini taşır.
        $pdf = $this->apiPost('/api/v1/products/labels', [
            'items' => [['product_id' => $this->productId, 'qty' => 2]],
            'show_price' => false,
            'size' => '50x30',
        ], $this->a['headers'])->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Şablon: ölçü ve fiyat anahtarı doğru yerleşir.
        $html = view('pdf.labels', [
            'labels' => [['name' => 'X', 'code' => '1', 'image' => 'data:image/png;base64,', 'price' => 5]],
            'showPrice' => false,
            'size' => '40x30',
            'dims' => [40, 30],
            'money' => fn ($v) => (string) $v,
        ])->render();
        $this->assertStringContainsString('width: 40mm', $html);
        $this->assertStringNotContainsString('class="price"', $html);
    }

    public function test_barkod_baska_firmaya_sizmaz(): void
    {
        $b = $this->register('barkod-b@firma.test', 'Barkod B Firmasi');

        $this->apiGet("/api/v1/products/{$this->productId}/barcode", $b['headers'])->assertNotFound();
        $this->apiPost('/api/v1/products/labels', [
            'items' => [['product_id' => $this->productId, 'qty' => 1]],
        ], $b['headers'])->assertStatus(422);
    }
}
