<?php

namespace Tests\Feature\Integration\Sync;

use App\Integration\Enums\Channel;
use App\Integration\Enums\Driver;
use App\Integration\Support\CredentialVault;
use App\Integration\Sync\CatalogPullService;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * Tsoft katalog veri kalitesi: İngilizce ad, eksik barkod ve kategori eşlemesi.
 * Tsoft tarafına (setProducts / setOrder / vb.) ASLA yazılmaz.
 */
final class CatalogDataQualityTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_ingilizce_urun_ve_kategori_verisi_temiz_bicimde_ice_alinir(): void
    {
        $ctx = $this->register('catalog-quality@firma.test', 'Katalog Kalite Firma');
        $companyId = $ctx['company_id'];

        // Yalnızca rest1 okuma yolu için dummy kimlik bilgileri (gerçek sır yok).
        (new CredentialVault)->put(
            $companyId,
            Channel::Tsoft,
            ['user' => 'dummy-rest-user', 'pass' => 'dummy-rest-pass'],
            'dummy-webhook-secret',
            [],
            [],
            Driver::Rest1,
            'magaza.test',
            'magaza',
        );

        Http::fake([
            'https://magaza.test/rest1/auth/login/*' => Http::response([
                'data' => [['token' => 'dummy-token', 'expirationTime' => time() + 3600]],
            ], 200),
            'https://magaza.test/rest1/category/getCategories' => Http::response([
                'Data' => [[
                    'CategoryId' => '101',
                    'CategoryCode' => 'CAT-RFID',
                    'CategoryName' => 'RFID Tags',
                    'ParentCode' => '',
                    'HasChild' => false,
                    'IsActive' => true,
                    'ListNo' => 1,
                    'SeoLink' => 'rfid-tags',
                ]],
            ], 200),
            'https://magaza.test/rest1/product/getProducts' => Http::response([
                'Data' => [[
                    'ProductCode' => 'SMART-KEY-1',
                    'ProductName' => 'Smart Key 433MHz ID46',
                    'Barcode' => '',
                    'DefaultCategoryName' => 'RFID Tags',
                    'DefaultCategoryCode' => 'CAT-RFID',
                    'StockUnit' => 'Count',
                    'Currency' => 'TRY',
                    'SellingPrice' => '199.90',
                    'Stock' => '5',
                    'Vat' => '18',
                ]],
            ], 200),
            'https://magaza.test/rest1/customer/getCustomers' => Http::response(['Data' => []], 200),
            '*' => Http::response(['Data' => []], 200),
        ]);

        $pull = app(CatalogPullService::class);
        $summary = $pull->pull($companyId);
        $this->assertSame([], $summary['errors']);

        // Kategori yerel tabloya yazildi.
        $category = ProductCategory::query()
            ->where('company_id', $companyId)
            ->where('name', 'RFID Tags')
            ->first();
        $this->assertNotNull($category);

        $product = Product::query()
            ->where('company_id', $companyId)
            ->where('sku', 'SMART-KEY-1')
            ->first();
        $this->assertNotNull($product);

        // Orijinal Ingilizce ad KORUNUR; Turkce ad best-effort cevrilir.
        $this->assertSame('Smart Key 433MHz ID46', $product->name_en);
        $this->assertStringContainsString('Akıllı Anahtar', (string) $product->name);
        // Model kodu / frekans bozulmaz.
        $this->assertStringContainsString('433MHz ID46', (string) $product->name);

        // Bos barkod yerine SKU'ya gore deterministik yerel barkod uretilir.
        $expectedBarcode = 'MRC'.strtoupper(substr(md5('SMART-KEY-1'), 0, 10));
        $this->assertSame($expectedBarcode, $product->barcode);

        // DefaultCategoryName -> yerel kategori id eslemesi kuruldu.
        $this->assertSame((int) $category->id, (int) $product->category_id);

        // Kategori okuma ucu cagrildi; hici bir yazma ucu cagrilmadi.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/rest1/category/getCategories'));
        foreach (['setproducts', 'setorder', 'setprice', 'setstock'] as $forbidden) {
            Http::assertNotSent(
                fn ($request) => str_contains(strtolower($request->url().' '.$request->body()), $forbidden)
            );
        }

        // Idempotenti: ikinci cekim ayni barkodu korur, satir/categori cogalmaz.
        $pull->pull($companyId);
        $product = Product::query()
            ->where('company_id', $companyId)
            ->where('sku', 'SMART-KEY-1')
            ->firstOrFail();
        $this->assertSame($expectedBarcode, $product->barcode);
        $this->assertSame(1, Product::query()
            ->where('company_id', $companyId)
            ->where('sku', 'SMART-KEY-1')
            ->count());
        $this->assertSame(1, ProductCategory::query()
            ->where('company_id', $companyId)
            ->where('name', 'RFID Tags')
            ->count());

        // Tsoft barkodu bos kalsa bile mevcut (elle girilmis) barkod ezilmez.
        $product->update(['barcode' => 'MANUAL-BARCODE']);
        $pull->pull($companyId);
        $this->assertSame(
            'MANUAL-BARCODE',
            Product::query()->where('company_id', $companyId)->where('sku', 'SMART-KEY-1')->value('barcode'),
        );
    }
}
