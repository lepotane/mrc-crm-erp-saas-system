<?php

namespace Tests\Feature\Integration\Sync;

use App\Integration\Enums\Channel;
use App\Integration\Enums\Driver;
use App\Integration\Support\CredentialVault;
use App\Integration\Sync\CatalogPullService;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * Tsoft katalog YAPISI (kategori hiyerarşisi, birim, görseller) MRC'ye aynalanır.
 * Tsoft tarafında hiçbir yazma ucu çağrılmaz; her şey salt okunur.
 */
final class CatalogStructureMirrorTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** Ürün yanıtına görsellerin eklenip eklenmeyeceği (ikinci çekimde kapatılır). */
    private bool $includeImages = true;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** Rest1 okuma uçları için dummy kimlik bilgileri (gerçek sır yok). */
    private function credentials(int $companyId): void
    {
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
    }

    /** @return array<string,mixed> */
    private function productRow(bool $withImages): array
    {
        $row = [
            'ProductCode' => 'MIRROR-001',
            'ProductName' => 'Mirror Product',
            // Boş barkod: deterministik yerel barkod üretilmeli.
            'Barcode' => '',
            'DefaultCategoryName' => 'Spark Plug',
            'DefaultCategoryCode' => 'T350',
            'StockUnit' => 'Count',
            'StockUnitId' => '1',
            'Currency' => 'TRY',
            'SellingPrice' => '199.90',
            'Stock' => '5',
            'Vat' => '18',
        ];

        if ($withImages) {
            // Göreli ImageUrl + mutlak ImageUrls birlikte: mutlak olan kazanır.
            $row['ImageUrl'] = 'D27/15851.jpg';
            $row['ImageUrls'] = [
                [
                    'ImageUrl' => 'https://autokeystore.tsoftstatic.com/D27/15851.jpg',
                    'Big' => 'https://autokeystore.tsoftstatic.com/D27/15851_big.jpg',
                    'Medium' => 'https://autokeystore.tsoftstatic.com/D27/15851_medium.jpg',
                    'Small' => 'https://autokeystore.tsoftstatic.com/D27/15851_small.jpg',
                    'ListNo' => 0,
                ],
                [
                    'ImageUrl' => 'https://autokeystore.tsoftstatic.com/D27/15852.jpg',
                    'Big' => 'https://autokeystore.tsoftstatic.com/D27/15852_big.jpg',
                    'Medium' => 'https://autokeystore.tsoftstatic.com/D27/15852_medium.jpg',
                    'Small' => 'https://autokeystore.tsoftstatic.com/D27/15852_small.jpg',
                    'ListNo' => 1,
                ],
            ];
        }

        return $row;
    }

    private function fakeTsoft(): void
    {
        Http::fake([
            'https://magaza.test/rest1/auth/login/*' => Http::response([
                'data' => [['token' => 'dummy-token', 'expirationTime' => time() + 3600]],
            ], 200),
            'https://magaza.test/rest1/category/getCategories' => Http::response([
                'Data' => [
                    [
                        'CategoryId' => '10',
                        'CategoryCode' => 'T3',
                        'CategoryName' => 'Auto Parts',
                        'ParentCode' => '0',
                        'HasChild' => true,
                        'ListNo' => 1,
                    ],
                    [
                        'CategoryId' => '11',
                        'CategoryCode' => 'T350',
                        'CategoryName' => 'Spark Plug',
                        'ParentCode' => 'T3',
                        'HasChild' => false,
                        'ListNo' => 2,
                    ],
                ],
            ], 200),
            'https://magaza.test/rest1/product/getProducts' => fn () => Http::response([
                'Data' => [$this->productRow($this->includeImages)],
            ], 200),
            'https://magaza.test/rest1/customer/getCustomers' => Http::response(['Data' => []], 200),
            '*' => Http::response(['Data' => []], 200),
        ]);
    }

    public function test_kategori_birim_ve_gorseller_aynalanir(): void
    {
        $ctx = $this->register('catalog-structure@firma.test', 'Katalog Yapi Firma');
        $companyId = $ctx['company_id'];
        $this->credentials($companyId);
        $this->fakeTsoft();

        $summary = app(CatalogPullService::class)->pull($companyId);
        $this->assertSame([], $summary['errors']);
        $this->assertSame(1, $summary['products']);

        // Kategori: dış kod + yaprak bilgisi + ParentCode -> parent_id.
        $parent = ProductCategory::query()
            ->where('company_id', $companyId)
            ->where('name', 'Auto Parts')
            ->firstOrFail();
        $child = ProductCategory::query()
            ->where('company_id', $companyId)
            ->where('name', 'Spark Plug')
            ->firstOrFail();

        $this->assertSame('T3', $parent->external_code);
        $this->assertFalse((bool) $parent->is_leaf);
        $this->assertNull($parent->parent_id);

        $this->assertSame('T350', $child->external_code);
        $this->assertTrue((bool) $child->is_leaf);
        $this->assertSame((int) $parent->id, (int) $child->parent_id);
        $this->assertSame((int) $parent->id, (int) $child->parent()->value('id'));
        $this->assertSame((int) $child->id, (int) $parent->children()->value('id'));

        // Birim: StockUnit/StockUnitId -> units satırı ve ürün bağlantısı.
        $unit = Unit::query()
            ->where('company_id', $companyId)
            ->where('name', 'Count')
            ->first();
        $this->assertNotNull($unit);
        $this->assertSame('COUNT', $unit->code);
        $this->assertSame('1', $unit->external_code);

        $product = Product::query()
            ->where('company_id', $companyId)
            ->where('sku', 'MIRROR-001')
            ->firstOrFail();
        $this->assertSame((int) $unit->id, (int) $product->unit_id);
        $this->assertSame((int) $child->id, (int) $product->category_id);

        // Mutlak ImageUrls göreli ImageUrl'i yener; çift önek oluşmaz.
        $this->assertSame(
            'https://autokeystore.tsoftstatic.com/D27/15851.jpg',
            $product->image_url,
        );
        $this->assertIsArray($product->images);
        $this->assertContains('https://autokeystore.tsoftstatic.com/D27/15851.jpg', $product->images);
        $this->assertContains('https://autokeystore.tsoftstatic.com/D27/15852.jpg', $product->images);

        // FetchImageUrls okuma isteğinde gönderildi.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/rest1/product/getProducts')
            && str_contains($request->body(), 'FetchImageUrls'));

        // Hiçbir Tsoft yazma ucu çağrılmadı.
        foreach (['setproducts', 'setorder', 'setprice', 'setstock'] as $forbidden) {
            Http::assertNotSent(
                fn ($request) => str_contains(strtolower($request->url().' '.$request->body()), $forbidden)
            );
        }
    }

    public function test_tsoft_gorsel_vermezse_elle_degistirilen_gorsel_korunur(): void
    {
        $ctx = $this->register('catalog-preserve@firma.test', 'Katalog Koruma Firma');
        $companyId = $ctx['company_id'];
        $this->credentials($companyId);
        $this->fakeTsoft();

        $pull = app(CatalogPullService::class);
        $pull->pull($companyId);

        $product = Product::query()
            ->where('company_id', $companyId)
            ->where('sku', 'MIRROR-001')
            ->firstOrFail();
        $this->assertSame('https://autokeystore.tsoftstatic.com/D27/15851.jpg', $product->image_url);

        // Kullanıcı görseli elle değiştirir.
        $manualUrl = 'https://manual.example.com/custom.jpg';
        $product->update(['image_url' => $manualUrl, 'images' => [$manualUrl]]);

        // İkinci çekimde Tsoft hiç görsel döndürmüyor.
        $this->includeImages = false;
        $summary = $pull->pull($companyId);
        $this->assertSame([], $summary['errors']);

        $product->refresh();
        $this->assertSame($manualUrl, $product->image_url);
        $this->assertSame([$manualUrl], $product->images);

        foreach (['setproducts', 'setorder', 'setprice', 'setstock'] as $forbidden) {
            Http::assertNotSent(
                fn ($request) => str_contains(strtolower($request->url().' '.$request->body()), $forbidden)
            );
        }
    }
}
