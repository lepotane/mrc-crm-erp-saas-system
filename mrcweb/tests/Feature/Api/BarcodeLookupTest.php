<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * KAMERA BARKOD ARAMA SÖZLEŞMESİ .
 *
 * Tarayıcı tek çağrıyla ürüne ulaşır (`GET /products?search=<barkod>`):
 * barkod/sku eşleşmesi döner, bilinmeyen kod boş liste verir, kiracı sızdırmaz.
 * Ağır iş istemcidedir (kamera + wasm); sunucu sözleşmesi bu dosyayla kilitli.
 */
class BarcodeLookupTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_barkodla_tek_aramada_urune_ulasir(): void
    {
        $a = $this->register('barkod@firma.test', 'Barkod Firmasi');

        $urunId = $this->apiPost('/api/v1/products', [
            'name' => 'Taranan Ürün',
            'barcode' => '8691234567890',
        ], $a['headers'])->assertCreated()->json('product.id');

        $sonuc = $this->apiGet('/api/v1/products?search=8691234567890', $a['headers'])
            ->assertOk()->json('data');

        $this->assertNotEmpty($sonuc);
        $this->assertSame($urunId, $sonuc[0]['id']);
        $this->assertSame('8691234567890', $sonuc[0]['barcode']);
    }

    public function test_bilinmeyen_barkod_bos_liste_doner(): void
    {
        $a = $this->register('barkod-bos@firma.test', 'Barkod Bos Firmasi');

        $sonuc = $this->apiGet('/api/v1/products?search=0000000000000', $a['headers'])
            ->assertOk()->json('data');

        $this->assertSame([], $sonuc);
    }

    public function test_barkod_baska_firmaya_sizmaz(): void
    {
        $a = $this->register('barkod-a@firma.test', 'Barkod A Firmasi');
        $b = $this->register('barkod-b@firma.test', 'Barkod B Firmasi');

        $this->apiPost('/api/v1/products', [
            'name' => 'A Ürünü',
            'barcode' => '8699999999999',
        ], $a['headers'])->assertCreated();

        $sonuc = $this->apiGet('/api/v1/products?search=8699999999999', $b['headers'])
            ->assertOk()->json('data');

        $this->assertSame([], $sonuc);
    }
}
