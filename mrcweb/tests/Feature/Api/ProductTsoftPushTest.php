<?php

namespace Tests\Feature\Api;

use App\Integration\Enums\Channel;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TenantIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * MRC → Tsoft ürün yazımı varsayılan olarak KAPALIDIR (dry_run + kanal flag'i).
 * Yalnızca sahte kimlik bilgileri ve sahte HTTP yanıtları kullanılır.
 */
final class ProductTsoftPushTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    private const SKU = 'MRC-TSOFT-1';

    private const IMAGE = 'https://dummy-shop.test/images/urun.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config()->set('integrations.dry_run', true);
        config()->set('integrations.channels.tsoft.catalog_write_enabled', false);
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

    /** @param array{token: string, headers: array<string,string>, company_id: int, user_id: int} $ctx */
    private function makeProduct(array $ctx, bool $leaf = true): Product
    {
        $category = ProductCategory::create([
            'company_id' => $ctx['company_id'],
            'name' => 'Tsoft Kategori',
        ]);
        // Yeni kolonlar $fillable'da olmayabilir; test verisi için forceFill.
        $category->forceFill(['external_code' => 'T350', 'is_leaf' => $leaf])->save();

        $product = Product::create([
            'company_id' => $ctx['company_id'],
            'name' => 'Tsoft Ürün',
            'name_en' => 'Tsoft Product EN',
            'sku' => self::SKU,
            'category_id' => $category->id,
            'unit_id' => $this->unitId($ctx, 'adet'),
            'cost_price' => 40,
            'sale_price' => 100,
            'currency_code' => 'TRY',
            'tax_rate' => 20,
        ]);
        $product->forceFill(['image_url' => self::IMAGE])->save();

        return $product;
    }

    public function test_dry_run_onizleme_doner_ve_hic_http_gonderilmez(): void
    {
        $ctx = $this->setupTenant('tp-dry@firma.test', 'TP Dry');
        $product = $this->makeProduct($ctx);
        Http::fake();

        $response = $this->apiPost("/api/v1/products/{$product->id}/tsoft-push", [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('preview', true)
            ->assertJsonPath('would_write', false)
            ->assertJsonPath('payload.ProductCode', self::SKU)
            ->assertJsonPath('payload.DefaultCategoryCode', 'T350')
            ->assertJsonPath('payload.ImageUrl', self::IMAGE)
            ->assertJsonPath('payload.IsActive', true);

        $this->assertTrue($response->json('payload.IsLeaf'));
        Http::assertNothingSent();
    }

    public function test_confirm_dry_run_kapaliyken_surucu_skips_ve_hata_firlatmaz(): void
    {
        $ctx = $this->setupTenant('tp-skip@firma.test', 'TP Skip');
        $product = $this->makeProduct($ctx);
        Http::fake();

        $this->apiPost("/api/v1/products/{$product->id}/tsoft-push", ['confirm' => true], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('preview', false)
            ->assertJsonPath('would_write', false)
            ->assertJsonPath('status', 'skipped')
            ->assertJsonPath('result.skipped', true);

        Http::assertNothingSent();
    }

    public function test_confirm_canli_yazma_set_products_cagirir(): void
    {
        $ctx = $this->setupTenant('tp-live@firma.test', 'TP Live');
        $product = $this->makeProduct($ctx);

        config()->set('integrations.dry_run', false);
        config()->set('integrations.channels.tsoft.catalog_write_enabled', true);

        $writes = [];
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

            if (str_contains($url, '/product/setProducts')) {
                $writes[] = $request->data();

                return Http::response(['success' => true], 200);
            }

            return Http::response(['success' => false], 404);
        });

        $this->apiPost("/api/v1/products/{$product->id}/tsoft-push", ['confirm' => true], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('preview', false)
            ->assertJsonPath('would_write', true)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('result.ok', true);

        $this->assertCount(1, $writes);
        $payload = json_decode((string) ($writes[0]['data'] ?? ''), true);
        $this->assertIsArray($payload);
        $this->assertSame(self::SKU, $payload[0]['ProductCode'] ?? null);
        $this->assertSame('Tsoft Product EN', $payload[0]['ProductName'] ?? null);
        $this->assertSame('T350', $payload[0]['DefaultCategoryCode'] ?? null);
        $this->assertSame(self::IMAGE, $payload[0]['ImageUrl'] ?? null);
        $this->assertTrue((bool) ($payload[0]['IsActive'] ?? false));
    }

    public function test_yaprak_olmayan_kategori_422_doner_ve_http_gonderilmez(): void
    {
        $ctx = $this->setupTenant('tp-leaf@firma.test', 'TP Leaf');
        $product = $this->makeProduct($ctx, false);
        Http::fake();

        $response = $this->apiPost("/api/v1/products/{$product->id}/tsoft-push", ['confirm' => true], $ctx['headers'])
            ->assertStatus(422);

        $this->assertStringContainsString('yaprak', (string) $response->json('message'));
        Http::assertNothingSent();
    }
}
