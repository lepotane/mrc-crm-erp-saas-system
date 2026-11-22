<?php

namespace Tests\Feature\Integration\Quality;

use App\Integration\Enums\Channel;
use App\Integration\Enums\Driver;
use App\Integration\Quality\CatalogQualityService;
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
 * Tsoft -> MRC katalog veri kalitesi: kategorisiz/gorselsiz urun onarimi ve
 * yinelenen SKU/barkod raporu. Tsoft'a (setProducts vb.) ASLA yazilmaz.
 */
final class CatalogQualityTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('app.url', 'https://mrc.test');
    }

    public function test_repair_assigns_uncategorized_category_and_placeholder_image(): void
    {
        Http::fake();

        $ctx = $this->register('catalog-quality-repair@firma.test', 'Quality Repair Firma');
        $companyId = $ctx['company_id'];

        $product = Product::create([
            'company_id' => $companyId,
            'name' => 'Kategorisiz Urun',
            'sku' => 'NO-CATEGORY-1',
            'category_id' => null,
            'image_url' => null,
            'images' => null,
        ]);

        $quality = app(CatalogQualityService::class);

        $scan = $quality->scan($companyId);
        $this->assertSame(1, $scan['total']);
        $this->assertSame(1, $scan['no_category']);
        $this->assertSame(1, $scan['no_image']);

        $fixed = $quality->repair($companyId);
        $this->assertSame(1, $fixed['no_category']);
        $this->assertSame(1, $fixed['no_image']);

        $category = ProductCategory::query()
            ->where('company_id', $companyId)
            ->where('external_code', CatalogQualityService::UNCATEGORIZED_CODE)
            ->firstOrFail();
        $this->assertSame(CatalogQualityService::UNCATEGORIZED_NAME, $category->name);
        $this->assertTrue((bool) $category->is_leaf);
        $this->assertNull($category->parent_id);

        $product->refresh();
        $this->assertSame((int) $category->id, (int) $product->category_id);
        $this->assertSame('https://mrc.test/icons/icon-192.png', $product->image_url);

        // Idempotent: ikinci onarim hicbir satiri degistirmez.
        $again = $quality->repair($companyId);
        $this->assertSame(0, $again['no_category']);
        $this->assertSame(0, $again['no_image']);

        // Gercek Tsoft gorseli asla placeholder ile ezilmez.
        $real = Product::create([
            'company_id' => $companyId,
            'name' => 'Gorselli Urun',
            'sku' => 'HAS-IMAGE-1',
            'category_id' => (int) $category->id,
            'image_url' => 'https://autokeystore.tsoftstatic.com/D27/15851.jpg',
        ]);
        $quality->repair($companyId);
        $this->assertSame(
            'https://autokeystore.tsoftstatic.com/D27/15851.jpg',
            $real->fresh()->image_url,
        );

        Http::assertNothingSent();
    }

    public function test_scan_reports_duplicate_sku_and_barcode_without_deleting_products(): void
    {
        Http::fake();

        $ctx = $this->register('catalog-quality-dup@firma.test', 'Quality Duplicate Firma');
        $companyId = $ctx['company_id'];

        foreach ([
            ['DUPSKU', 'BARCODE-A'],
            ['DUPSKU', 'BARCODE-B'],
            ['OTHERSKU', 'BARCODE-B'],
        ] as $index => [$sku, $barcode]) {
            Product::create([
                'company_id' => $companyId,
                'name' => 'Urun '.$index,
                'sku' => $sku,
                'barcode' => $barcode,
                'image_url' => 'https://cdn.example.test/'.$index.'.jpg',
            ]);
        }

        $scan = app(CatalogQualityService::class)->scan($companyId);

        $this->assertSame(3, $scan['total']);
        $this->assertSame(['DUPSKU' => 2], $scan['duplicate_sku']);
        $this->assertSame(['BARCODE-B' => 2], $scan['duplicate_barcode']);

        // Tarama salt okunur: hicbir urun silinmez.
        $this->assertSame(3, Product::query()->where('company_id', $companyId)->count());

        // Hicbir Tsoft yazma ucu cagrilmadi (hatta hicbir HTTP istegi yapilmadi).
        Http::assertNothingSent();
        foreach (['setproducts', 'setorder', 'setprice', 'setstock'] as $forbidden) {
            Http::assertNotSent(
                fn ($request) => str_contains(strtolower($request->url().' '.$request->body()), $forbidden)
            );
        }
    }

    public function test_catalog_pull_repairs_missing_category_and_image_without_writing_to_tsoft(): void
    {
        $ctx = $this->register('catalog-quality-pull@firma.test', 'Quality Pull Firma');
        $companyId = $ctx['company_id'];

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
            'https://magaza.test/rest1/product/getProducts' => Http::response([
                'Data' => [[
                    'ProductCode' => 'NO-CAT-PULL',
                    'ProductName' => 'Kategorisiz Cekilen Urun',
                    'SellingPrice' => '10.00',
                    'Stock' => '2',
                    'Currency' => 'TRY',
                    'Barcode' => 'PULL-BC',
                ]],
            ], 200),
            '*' => Http::response(['Data' => []], 200),
        ]);

        $summary = app(CatalogPullService::class)->pull($companyId);
        $this->assertSame([], $summary['errors']);

        $product = Product::query()
            ->where('company_id', $companyId)
            ->where('sku', 'NO-CAT-PULL')
            ->firstOrFail();
        $category = ProductCategory::query()
            ->where('company_id', $companyId)
            ->where('external_code', CatalogQualityService::UNCATEGORIZED_CODE)
            ->firstOrFail();

        $this->assertSame((int) $category->id, (int) $product->category_id);
        $this->assertSame('https://mrc.test/icons/icon-192.png', $product->image_url);

        foreach (['setproducts', 'setorder', 'setprice', 'setstock'] as $forbidden) {
            Http::assertNotSent(
                fn ($request) => str_contains(strtolower($request->url().' '.$request->body()), $forbidden)
            );
        }
    }
}
