<?php

namespace Tests\Feature\Integration\Warehouse;

use App\Integration\Canonical\CanonicalStock;
use App\Integration\Canonical\SyncResult;
use App\Integration\Enums\Channel;
use App\Integration\Support\TsoftWarehouse;
use App\Integration\Sync\IntegrationIdMap;
use App\Integration\Sync\IntegrationStockState;
use App\Integration\Sync\ParallelStockEngine;
use App\Integration\Sync\TsoftWritebackService;
use App\Models\Document;
use App\Models\Party;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\TenantIntegration;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * "Mobil Araç Deposu" gibi yeni depolar Tsoft senkronundan izole edilmelidir.
 * Yalnızca yapılandırılmış (yoksa varsayılan) depo dışarıya aynalanır.
 */
final class TsoftWarehouseIsolationTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    private const SKU = 'ISO-SKU-1';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('integrations.dry_run', false);
        config()->set('integrations.channels.tsoft.stock_write_enabled', true);
    }

    /**
     * @return array{company_id: int, tsoft_warehouse_id: int, mobile_warehouse_id: int, product_id: int}
     */
    private function setupWarehouses(): array
    {
        $ctx = $this->register('depo-izolasyon@firma.test', 'Depo Izolasyon Firma');
        $tsoftWarehouseId = $this->defaultWarehouseId($ctx);

        $mobile = Warehouse::create([
            'company_id' => $ctx['company_id'],
            'name' => 'Mobil Araç Deposu',
            'is_default' => false,
        ]);

        // Üretimde olduğu gibi hedef depo yapılandırmadan gelir (string).
        config()->set('integrations.channels.tsoft.warehouse_id', (string) $tsoftWarehouseId);

        $product = Product::create([
            'company_id' => $ctx['company_id'],
            'name' => 'Izolasyon Test Urunu',
            'sku' => self::SKU,
        ]);

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

        return [
            'company_id' => $ctx['company_id'],
            'tsoft_warehouse_id' => $tsoftWarehouseId,
            'mobile_warehouse_id' => (int) $mobile->id,
            'product_id' => (int) $product->id,
        ];
    }

    private function makeState(int $companyId, int $warehouseId): IntegrationStockState
    {
        return IntegrationStockState::create([
            'company_id' => $companyId,
            'sku' => self::SKU,
            'warehouse_id' => $warehouseId,
            'quantity' => '5.0000',
            'version' => 1,
            'source' => 'mrc',
            'synced_at' => null,
        ]);
    }

    private function makeDocument(int $companyId, int $warehouseId, string $origin = 'mrc'): Document
    {
        $party = Party::create([
            'company_id' => $companyId,
            'name' => 'Izolasyon Carisi',
        ]);

        $document = Document::create([
            'company_id' => $companyId,
            'type' => Document::TYPE_SALES_ORDER,
            'serial' => 'ISO-'.$companyId.'-'.$warehouseId.'-'.$origin,
            'party_id' => $party->id,
            'warehouse_id' => $warehouseId,
            'status' => 'draft',
            'issue_date' => date('Y-m-d'),
        ]);

        // `origin` modelin $fillable listesinde olmayabilir.
        $document->forceFill(['origin' => $origin])->save();

        return $document;
    }

    private function makeMovement(int $companyId, int $warehouseId, Document $document, int $productId): void
    {
        StockMovement::create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'type' => 'in',
            'quantity' => 2,
            'reference' => 'return:'.$document->id,
        ]);
    }

    public function test_push_outbound_yalnizca_tsoft_deposunun_synced_at_degerini_gunceller(): void
    {
        $ctx = $this->setupWarehouses();
        $tsoftState = $this->makeState($ctx['company_id'], $ctx['tsoft_warehouse_id']);
        $mobileState = $this->makeState($ctx['company_id'], $ctx['mobile_warehouse_id']);

        // Mobil depoda hem state hem hareket var; ikisi de izole kalmalı.
        StockMovement::create([
            'company_id' => $ctx['company_id'],
            'product_id' => $ctx['product_id'],
            'warehouse_id' => $ctx['mobile_warehouse_id'],
            'type' => 'out',
            'quantity' => 1,
            'reference' => 'issue:iso-mobile',
        ]);

        $this->assertSame($ctx['tsoft_warehouse_id'], TsoftWarehouse::targetId($ctx['company_id']));
        $this->assertTrue(TsoftWarehouse::isTsoftWarehouse($ctx['company_id'], $ctx['tsoft_warehouse_id']));
        $this->assertFalse(TsoftWarehouse::isTsoftWarehouse($ctx['company_id'], $ctx['mobile_warehouse_id']));

        // Hedef çözümlenemezse fail-closed.
        $this->assertNull(TsoftWarehouse::targetId(987654321));
        $this->assertFalse(TsoftWarehouse::isTsoftWarehouse(987654321, $ctx['tsoft_warehouse_id']));

        $engine = new ParallelStockEngine(
            adapterFactory: fn () => new class
            {
                public function pushStock(CanonicalStock $stock): SyncResult
                {
                    return SyncResult::success('ext-iso-1');
                }
            },
        );

        $result = $engine->pushOutbound($ctx['company_id'], new CanonicalStock(
            self::SKU,
            (string) $ctx['tsoft_warehouse_id'],
            '5.0000',
            1,
            'mrc',
        ));

        $this->assertTrue($result->ok);

        $this->assertNull($mobileState->fresh()->synced_at, 'Mobil depo asla senkronize işaretlenmemeli.');
        $this->assertNotNull($tsoftState->fresh()->synced_at, 'Tsoft deposu senkronize işaretlenmeli.');

        // Mobil depo outbound duruma dahil edilmez; Tsoft deposu dahildir.
        $this->assertNull($engine->readMrc($ctx['company_id'], self::SKU, $ctx['mobile_warehouse_id']));
        $this->assertNotNull($engine->readMrc($ctx['company_id'], self::SKU, $ctx['tsoft_warehouse_id']));
    }

    public function test_yazma_servisi_mobil_depo_belgesini_atlar(): void
    {
        $ctx = $this->setupWarehouses();
        $document = $this->makeDocument($ctx['company_id'], $ctx['mobile_warehouse_id']);
        $this->makeMovement($ctx['company_id'], $ctx['mobile_warehouse_id'], $document, $ctx['product_id']);

        Http::fake();

        $result = app(TsoftWritebackService::class)
            ->processDocument($ctx['company_id'], (int) $document->id);

        $this->assertSame(0, $result['processed']);
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
        Http::assertNothingSent();
        $this->assertSame(0, IntegrationIdMap::query()->where('company_id', $ctx['company_id'])->count());
    }

    public function test_yazma_servisi_tsoft_deposu_belgesini_isler(): void
    {
        $ctx = $this->setupWarehouses();
        $document = $this->makeDocument($ctx['company_id'], $ctx['tsoft_warehouse_id']);
        $this->makeMovement($ctx['company_id'], $ctx['tsoft_warehouse_id'], $document, $ctx['product_id']);

        $writes = [];
        Http::fake(function (Request $request) use (&$writes): mixed {
            $url = $request->url();

            if (str_contains($url, '/auth/login/')) {
                return Http::response([
                    'data' => [[
                        'token' => 'dummy-token',
                        'expirationTime' => time() + 3600,
                    ]],
                ], 200);
            }

            if (str_contains($url, '/product/getProductByCode/')) {
                return Http::response([
                    'data' => [[
                        'ProductCode' => self::SKU,
                        'Stock' => '10',
                        'SellingPrice' => '100',
                        'BuyingPrice' => '50',
                        'ProductName' => 'Izolasyon Test Urunu',
                        'DefaultCategoryCode' => 'DUMMY-CAT',
                        'IsActive' => true,
                    ]],
                ], 200);
            }

            if (str_contains($url, '/product/updateStock')) {
                $writes[] = $request->data();

                return Http::response(['success' => true], 200);
            }

            return Http::response(['success' => false], 404);
        });

        $result = app(TsoftWritebackService::class)
            ->processDocument($ctx['company_id'], (int) $document->id);

        $this->assertSame(1, $result['processed']);
        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $writes);
        $this->assertSame(1, IntegrationIdMap::query()
            ->where('company_id', $ctx['company_id'])
            ->where('mrc_type', 'stock_writeback')
            ->count());
    }
}
