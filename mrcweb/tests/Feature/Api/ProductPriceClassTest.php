<?php

namespace Tests\Feature\Api;

use App\Models\Currency;
use App\Models\Document;
use App\Models\ExchangeRate;
use App\Models\Party;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * ÇOKLU PARA BİRİMİ FİYATI (fiyat sınıfları).
 *
 * Kural:
 *  - Bir ürünün TL / USD / EUR fiyatı FARKLI olabilir (product_prices).
 *  - Belge oluşturulurken hangi fiyat sınıfının kullanılacağı seçilir.
 *  - Çıktı alırken kalem bazlı veya toplu olarak fiyat sınıfı değiştirilebilir.
 *  - "Kur bazlı eşitleme" isteğe bağlı: TL ve USD fiyatı eşit olmak ZORUNDA
 *    değildir; istenirse biri diğerine kurla çevrilerek eşitlenebilir.
 */
class ProductPriceClassTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('fiyat@firma.test', 'Fiyat Firmasi');

        // 1 USD = 50 TRY
        ExchangeRate::create([
            'currency_code' => 'USD',
            'rate_date' => now()->format('Y-m-d'),
            'rate' => 50,
            'source' => 'test',
        ]);
    }

    protected function urun(array $ek = []): Product
    {
        return Product::create(array_merge([
            'company_id' => $this->a['company_id'],
            'name' => 'Test Ürünü',
            'cost_price' => 40,
            'sale_price' => 100,
            'currency_code' => 'TRY',
            'tax_rate' => 20,
        ], $ek));
    }

    protected function belge(array $ek = []): array
    {
        $cari = Party::create(['company_id' => $this->a['company_id'], 'name' => 'Fiyat Cari']);
        $urun = $ek['urun'] ?? $this->urun();

        $res = $this->apiPost('/api/v1/documents', array_merge([
            'type' => 'quote',
            'party_id' => $cari->id,
            'items' => [
                ['product_id' => $urun->id, 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ], array_diff_key($ek, ['urun' => null])), $this->a['headers'])->assertCreated();

        return ['id' => $res->json('document.id'), 'urun' => $urun, 'cari' => $cari];
    }

    public function test_urun_fiyat_listesi_kaydedilir_ve_okunur(): void
    {
        $urun = $this->urun();

        $this->apiPut("/api/v1/products/{$urun->id}/prices", [
            'prices' => [
                ['currency_code' => 'TRY', 'sale_price' => 100, 'cost_price' => 40],
                ['currency_code' => 'USD', 'sale_price' => 2, 'cost_price' => 0.8],
                ['currency_code' => 'EUR', 'sale_price' => 1.8, 'cost_price' => 0.7],
            ],
        ], $this->a['headers'])->assertOk()
            ->assertJsonPath('prices.1.currency_code', 'TRY')
            ->assertJsonPath('prices.2.currency_code', 'USD');

        // Ürün yanıtında fiyat sınıfları görünür (alfabetik sıra: EUR, TRY, USD).
        $this->apiGet("/api/v1/products/{$urun->id}", $this->a['headers'])->assertOk()
            ->assertJsonCount(3, 'product.prices')
            ->assertJsonPath('product.prices.0.currency_code', 'EUR')
            ->assertJsonPath('product.prices.0.sale_price', '1.8000');
    }

    public function test_urun_ana_fiyati_listedeki_ana_para_birimiyle_guncellenir(): void
    {
        $urun = $this->urun();

        $this->apiPut("/api/v1/products/{$urun->id}/prices", [
            'prices' => [['currency_code' => 'TRY', 'sale_price' => 125]],
        ], $this->a['headers'])->assertOk();

        $this->assertSame(125.0, (float) $urun->fresh()->sale_price);
    }

    public function test_kur_bazli_esitleme_hedef_fiyati_kurla_yazar(): void
    {
        $urun = $this->urun();

        $this->apiPut("/api/v1/products/{$urun->id}/prices", [
            'prices' => [
                ['currency_code' => 'TRY', 'sale_price' => 100],
                ['currency_code' => 'USD', 'sale_price' => 999],
            ],
        ], $this->a['headers'])->assertOk();

        // TL 100 → kur 50 → USD 2 olmalı
        $this->apiPost("/api/v1/products/{$urun->id}/prices/sync", [
            'from_currency' => 'TRY',
            'to_currency' => 'USD',
        ], $this->a['headers'])->assertOk()
            ->assertJsonPath('prices.1.currency_code', 'USD')
            ->assertJsonPath('prices.1.sale_price', '2.0000');
    }

    public function test_kur_bazli_esitleme_istenmezse_fiyatlar_farkli_kalir(): void
    {
        $urun = $this->urun();

        $this->apiPut("/api/v1/products/{$urun->id}/prices", [
            'prices' => [
                ['currency_code' => 'TRY', 'sale_price' => 100],
                ['currency_code' => 'USD', 'sale_price' => 3],
            ],
        ], $this->a['headers'])->assertOk();

        $usd = DB::table('product_prices')
            ->where('product_id', $urun->id)
            ->where('currency_code', 'USD')
            ->value('sale_price');

        $this->assertSame(3.0, (float) $usd, 'Fiyatlar kendiliğinden eşitlenmemeli.');
    }

    public function test_belgede_fiyat_sinifi_toplu_degistirilir(): void
    {
        $urun = $this->urun();
        $this->apiPut("/api/v1/products/{$urun->id}/prices", [
            'prices' => [
                ['currency_code' => 'TRY', 'sale_price' => 100],
                ['currency_code' => 'USD', 'sale_price' => 2],
            ],
        ], $this->a['headers'])->assertOk();

        $belge = $this->belge(['urun' => $urun]);

        $this->apiPost("/api/v1/documents/{$belge['id']}/price-class", [
            'currency_code' => 'USD',
        ], $this->a['headers'])->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('items.0.unit_price', '2.0000')
            ->assertJsonPath('items.0.price_currency_code', 'USD');

        // Belge toplamları kalemden yeniden hesaplandı (KDV'siz kalem).
        $doc = Document::find($belge['id']);
        $this->assertSame(4.0, (float) $doc->grand_total);
    }

    public function test_belgede_fiyat_sinifi_kalem_bazli_degistirilir(): void
    {
        $urun = $this->urun();
        $this->apiPut("/api/v1/products/{$urun->id}/prices", [
            'prices' => [
                ['currency_code' => 'TRY', 'sale_price' => 100],
                ['currency_code' => 'USD', 'sale_price' => 2],
            ],
        ], $this->a['headers'])->assertOk();

        $belge = $this->belge(['urun' => $urun]);
        $itemId = DB::table('document_items')->where('document_id', $belge['id'])->value('id');

        $this->apiPost("/api/v1/documents/{$belge['id']}/price-class", [
            'currency_code' => 'USD',
            'item_ids' => [$itemId],
        ], $this->a['headers'])->assertOk()->assertJsonPath('updated', 1);

        $item = DB::table('document_items')->where('id', $itemId)->first();
        $this->assertSame(2.0, (float) $item->unit_price);
        $this->assertSame('USD', $item->price_currency_code);
    }

    public function test_fiyat_sinifi_degisimi_kesilmis_belgede_yapilamaz(): void
    {
        $urun = $this->urun();
        $belge = $this->belge(['urun' => $urun]);

        $doc = Document::find($belge['id']);
        $doc->update(['status' => 'issued']);

        $this->apiPost("/api/v1/documents/{$belge['id']}/price-class", [
            'currency_code' => 'USD',
        ], $this->a['headers'])->assertStatus(422);
    }

    public function test_baska_firmanin_urun_fiyatina_dokunulamaz(): void
    {
        $b = $this->register('baska@firma.test', 'Baska Firmasi');
        $urun = $this->urun();

        $this->apiPut("/api/v1/products/{$urun->id}/prices", [
            'prices' => [['currency_code' => 'USD', 'sale_price' => 5]],
        ], $b['headers'])->assertNotFound();
    }

    public function test_anahtar_para_birimleri_aktif(): void
    {
        $this->assertContains('TRY', Currency::activeCodes());
    }
}
