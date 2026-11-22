<?php

namespace Tests\Feature\Api;

use App\Integration\Enums\Channel;
use App\Integration\Sync\IntegrationIdMap;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\TenantIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * MRC → Tsoft toplu ürün gönderimi varsayılan olarak KAPALIDIR.
 * Yalnızca sahte kimlik bilgileri ve sahte HTTP yanıtları kullanılır.
 */
final class ProductBulkPushTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    private const SKU = 'MRC-BULK-1';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config()->set('integrations.dry_run', true);
        config()->set('integrations.channels.tsoft.catalog_write_enabled', false);
        config()->set('integrations.channels.tsoft.stock_write_enabled', false);
    }

    /** @return array{token: string, headers: array<string,string>, company_id: int, user_id: int} */
    private function setupTenant(string $email, string $companyName): array
    {
        $ctx = $this->register($email, $companyName);

        TenantIntegration::create([
            'company_id' => $ctx['company_id'],
            'channel' => Channel::Tsoft->value,
            'driver' => 'rest1',
            'domain' => 'https://dummy-shop.test',
            'credentials' => [
                'username' => 'dummy-rest-user',
                'password' => 'dummy-rest-pass',
            ],
            'status' => TenantIntegration::STATUS_ACTIVE,
        ]);

        return $ctx;
    }

    /** @param array{company_id: int} $ctx */
    private function makeProduct(array $ctx, bool $withCategory = true): Product
    {
        $categoryId = null;
        if ($withCategory) {
            $category = ProductCategory::create([
                'company_id' => $ctx['company_id'],
                'name' => 'Toplu Tsoft Kategori',
            ]);
            $category->forceFill(['external_code' => 'T350', 'is_leaf' => true])->save();
            $categoryId = $category->id;
        }

        $product = Product::create([
            'company_id' => $ctx['company_id'],
            'name' => 'Toplu Tsoft Ürünü',
            'name_en' => 'Bulk Tsoft Product',
            'sku' => self::SKU,
            'category_id' => $categoryId,
            'unit_id' => $this->unitId($ctx, 'adet'),
            'cost_price' => 40,
            'sale_price' => 100,
            'currency_code' => 'TRY',
            'tax_rate' => 20,
        ]);

        StockMovement::create([
            'company_id' => $ctx['company_id'],
            'product_id' => $product->id,
            'warehouse_id' => $this->defaultWarehouseId($ctx),
            'type' => StockMovement::TYPE_IN,
            'quantity' => 7,
            'reference' => 'bulk-test-opening',
        ]);

        return $product;
    }

    /**
     * Canlı modda kullanılan sahte Tsoft uçları. Yazım isteklerini yola göre toplar.
     *
     * @param  array<string,array<int,array<string,mixed>>>  $writes
     */
    private function fakeTsoft(array &$writes): void
    {
        Http::fake(function (Request $request) use (&$writes) {
            $url = $request->url();

            if (str_contains($url, '/auth/login/')) {
                return Http::response([
                    'data' => [[
                        'token' => 'dummy-token',
                        'expirationTime' => time() + 3600,
                    ]],
                ], 200);
            }

            foreach (['setProducts', 'updatePrice', 'updateStock'] as $endpoint) {
                if (str_contains($url, '/product/'.$endpoint)) {
                    $writes[$endpoint][] = $request->data();

                    return Http::response(['success' => true], 200);
                }
            }

            return Http::response(['success' => false], 404);
        });
    }

    public function test_dry_run_onizleme_yazma_yapmaz_ve_satir_detayi_doner(): void
    {
        $ctx = $this->setupTenant('tpb-dry@firma.test', 'TPB Dry');
        $product = $this->makeProduct($ctx);
        Http::fake();

        $this->apiPost('/api/v1/products/tsoft-bulk-push', [
            'product_ids' => [$product->id],
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('preview', true)
            ->assertJsonPath('would_write', false)
            ->assertJsonPath('counts.total', 1)
            ->assertJsonPath('rows.0.id', $product->id)
            ->assertJsonPath('rows.0.kind', 'create')
            ->assertJsonPath('rows.0.reasons', [])
            ->assertJsonPath('rows.0.would_write', false)
            ->assertJsonPath('rows.0.payload.ProductCode', self::SKU)
            ->assertJsonPath('rows.0.payload.DefaultCategoryCode', 'T350')
            ->assertJsonPath('rows.0.payload.Stock', 7);

        Http::assertNothingSent();
        $this->assertSame(0, IntegrationIdMap::query()->where('company_id', $ctx['company_id'])->count());
    }

    public function test_confirm_fiyat_ve_stok_flagleri_kapaliyken_hic_yazma_yapmaz(): void
    {
        $ctx = $this->setupTenant('tpb-noflag@firma.test', 'TPB NoFlag');
        $product = $this->makeProduct($ctx);
        Http::fake();

        $this->apiPost('/api/v1/products/tsoft-bulk-push', [
            'product_ids' => [$product->id],
            'confirm' => true,
            'include_price' => false,
            'include_stock' => false,
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('preview', false)
            ->assertJsonPath('would_write', false)
            ->assertJsonPath('rows.0.status', 'skipped')
            ->assertJsonPath('counts.skipped', 1);

        Http::assertNothingSent();
        $this->assertSame(0, IntegrationIdMap::query()->where('company_id', $ctx['company_id'])->count());
    }

    public function test_canli_onay_urun_fiyat_ve_stok_yazar_ve_idempotency_kaydi_olusturur(): void
    {
        $ctx = $this->setupTenant('tpb-live@firma.test', 'TPB Live');
        $product = $this->makeProduct($ctx);

        config()->set('integrations.dry_run', false);
        config()->set('integrations.channels.tsoft.catalog_write_enabled', true);
        config()->set('integrations.channels.tsoft.stock_write_enabled', true);

        $writes = [];
        $this->fakeTsoft($writes);

        $this->apiPost('/api/v1/products/tsoft-bulk-push', [
            'product_ids' => [$product->id],
            'confirm' => true,
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('preview', false)
            ->assertJsonPath('would_write', true)
            ->assertJsonPath('rows.0.status', 'ok')
            ->assertJsonPath('counts.ok', 1)
            ->assertJsonPath('counts.failed', 0);

        foreach (['setProducts', 'updatePrice', 'updateStock'] as $endpoint) {
            $this->assertCount(1, $writes[$endpoint] ?? [], $endpoint.' beklenen tek yazımı yapmadı.');
        }

        $payload = json_decode((string) ($writes['setProducts'][0]['data'] ?? ''), true);
        $this->assertSame(self::SKU, $payload[0]['ProductCode'] ?? null);

        $price = json_decode((string) ($writes['updatePrice'][0]['data'] ?? ''), true);
        $this->assertSame(self::SKU, $price[0]['ProductCode'] ?? null);
        $this->assertEquals(100, (float) ($price[0]['SellingPrice'] ?? 0));
        $this->assertSame('TRY', $price[0]['Currency'] ?? null);

        $stock = json_decode((string) ($writes['updateStock'][0]['data'] ?? ''), true);
        $this->assertSame(self::SKU, $stock[0]['ProductCode'] ?? null);
        $this->assertSame(7, (int) ($stock[0]['Stock'] ?? 0));

        $this->assertSame(1, IntegrationIdMap::query()
            ->where('company_id', $ctx['company_id'])
            ->where('mrc_type', 'product_bulk_push')
            ->where('external_key', 'bulk:'.$product->id.':create')
            ->count());
    }

    public function test_ikinci_ayni_onay_tekrar_yazma_gondermez(): void
    {
        $ctx = $this->setupTenant('tpb-again@firma.test', 'TPB Again');
        $product = $this->makeProduct($ctx);

        config()->set('integrations.dry_run', false);
        config()->set('integrations.channels.tsoft.catalog_write_enabled', true);
        config()->set('integrations.channels.tsoft.stock_write_enabled', true);

        $writes = [];
        $this->fakeTsoft($writes);

        $payload = ['product_ids' => [$product->id], 'confirm' => true];
        $this->apiPost('/api/v1/products/tsoft-bulk-push', $payload, $ctx['headers'])->assertOk();
        $this->assertCount(1, $writes['setProducts'] ?? []);

        $this->apiPost('/api/v1/products/tsoft-bulk-push', $payload, $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('rows.0.status', 'already_pushed')
            ->assertJsonPath('counts.already_pushed', 1);

        foreach (['setProducts', 'updatePrice', 'updateStock'] as $endpoint) {
            $this->assertCount(1, $writes[$endpoint] ?? [], $endpoint.' tekrar yazıldı.');
        }
        $this->assertSame(1, IntegrationIdMap::query()->where('company_id', $ctx['company_id'])->count());
    }

    public function test_kategorisiz_urun_atlanir_ve_sebep_doner(): void
    {
        $ctx = $this->setupTenant('tpb-nocat@firma.test', 'TPB NoCat');
        $product = $this->makeProduct($ctx, false);
        Http::fake();

        $this->apiPost('/api/v1/products/tsoft-bulk-push', [
            'product_ids' => [$product->id],
            'confirm' => true,
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('rows.0.kind', 'skip')
            ->assertJsonPath('rows.0.status', 'invalid')
            ->assertJsonPath('counts.skip', 1);

        $reasons = $this->apiPost('/api/v1/products/tsoft-bulk-push', [
            'product_ids' => [$product->id],
        ], $ctx['headers'])->json('rows.0.reasons');

        $this->assertContains('kategori', $reasons);
        Http::assertNothingSent();
        $this->assertSame(0, IntegrationIdMap::query()->where('company_id', $ctx['company_id'])->count());
    }
}
