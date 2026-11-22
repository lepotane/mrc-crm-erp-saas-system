<?php

namespace Tests\Feature\Integration\Sync;

use App\Integration\Enums\Channel;
use App\Integration\Sync\IntegrationIdMap;
use App\Integration\Sync\TsoftWritebackService;
use App\Models\Document;
use App\Models\Party;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\TenantIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * MRC → Tsoft stok yazımı varsayılan olarak KAPALIDIR.
 * Yalnızca sahte kimlik bilgileri kullanılır; gerçek sır yoktur.
 */
final class TsoftWritebackServiceTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    private const SKU = 'WB-SKU-1';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config()->set('integrations.dry_run', true);
        config()->set('integrations.channels.tsoft.stock_write_enabled', false);
        config()->set('integrations.channels.tsoft.catalog_write_enabled', false);
        config()->set('integrations.channels.tsoft.warehouse_id', null);
    }

    /** @return array{company_id: int, warehouse_id: int} */
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

        return [
            'company_id' => $ctx['company_id'],
            'warehouse_id' => $this->defaultWarehouseId($ctx),
        ];
    }

    private function makeDocument(int $companyId, int $warehouseId, string $origin = 'mrc'): Document
    {
        $party = Party::create([
            'company_id' => $companyId,
            'name' => 'Writeback Test Carisi',
        ]);

        $document = Document::create([
            'company_id' => $companyId,
            'type' => Document::TYPE_SALES_ORDER,
            'serial' => 'SIP-26-TEST-'.$companyId.'-'.$origin,
            'party_id' => $party->id,
            'warehouse_id' => $warehouseId,
            'status' => 'draft',
            'issue_date' => date('Y-m-d'),
        ]);

        // `origin` henüz modelin $fillable listesinde olmayabilir.
        $document->forceFill(['origin' => $origin])->save();

        return $document;
    }

    private function makeMovement(int $companyId, int $warehouseId, string $reference, string $type = 'in'): void
    {
        $product = Product::create([
            'company_id' => $companyId,
            'name' => 'Writeback Test Ürünü',
            'sku' => self::SKU,
        ]);

        StockMovement::create([
            'company_id' => $companyId,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
            'type' => $type,
            'quantity' => 2,
            'reference' => $reference,
        ]);
    }

    public function test_dry_run_kapaliyken_hic_http_cagrisi_ve_esleme_kaydi_olmaz(): void
    {
        $tenant = $this->setupTenant('wb-dry@firma.test', 'WB Dry');
        $document = $this->makeDocument($tenant['company_id'], $tenant['warehouse_id']);
        $this->makeMovement($tenant['company_id'], $tenant['warehouse_id'], 'issue:'.$document->id, 'out');

        config()->set('integrations.channels.tsoft.stock_write_enabled', true);
        Http::fake();

        $result = app(TsoftWritebackService::class)->processDocument($tenant['company_id'], (int) $document->id);

        $this->assertSame(0, $result['processed']);
        Http::assertNothingSent();
        $this->assertSame(0, IntegrationIdMap::query()->where('company_id', $tenant['company_id'])->count());
    }

    public function test_iade_hareketi_tsoft_stogunu_arttirir_ve_ikinci_cagri_tekrar_yazmaz(): void
    {
        $tenant = $this->setupTenant('wb-live@firma.test', 'WB Live');
        config()->set('integrations.dry_run', false);
        config()->set('integrations.channels.tsoft.stock_write_enabled', true);
        config()->set('integrations.channels.tsoft.warehouse_id', $tenant['warehouse_id']);

        $document = $this->makeDocument($tenant['company_id'], $tenant['warehouse_id']);
        $this->makeMovement($tenant['company_id'], $tenant['warehouse_id'], 'return:'.$document->id);

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
                        'ProductName' => 'Writeback Test Ürünü',
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

        $service = app(TsoftWritebackService::class);
        $first = $service->processDocument($tenant['company_id'], (int) $document->id);

        $this->assertSame(1, $first['processed']);
        $this->assertSame([], $first['errors']);
        $this->assertCount(1, $writes);

        $payload = json_decode((string) ($writes[0]['data'] ?? ''), true);
        $this->assertSame(self::SKU, $payload[0]['ProductCode'] ?? null);
        $this->assertSame('12', (string) ($payload[0]['Stock'] ?? ''));

        $map = IntegrationIdMap::query()
            ->where('company_id', $tenant['company_id'])
            ->where('mrc_type', 'stock_writeback')
            ->where('external_key', 'document:'.$document->id.':stock:'.self::SKU.':return:'.$document->id)
            ->first();
        $this->assertNotNull($map);

        $second = $service->processDocument($tenant['company_id'], (int) $document->id);

        $this->assertSame(0, $second['processed']);
        $this->assertCount(1, $writes);
        $this->assertSame(1, IntegrationIdMap::query()->where('company_id', $tenant['company_id'])->count());
    }

    public function test_tsoft_kokenli_belge_geri_yazilmaz(): void
    {
        $tenant = $this->setupTenant('wb-tsoft@firma.test', 'WB Tsoft');
        config()->set('integrations.dry_run', false);
        config()->set('integrations.channels.tsoft.stock_write_enabled', true);
        config()->set('integrations.channels.tsoft.warehouse_id', $tenant['warehouse_id']);

        $document = $this->makeDocument($tenant['company_id'], $tenant['warehouse_id'], 'tsoft');
        $this->makeMovement($tenant['company_id'], $tenant['warehouse_id'], 'return:'.$document->id);

        Http::fake();

        $result = app(TsoftWritebackService::class)->processDocument($tenant['company_id'], (int) $document->id);

        $this->assertSame(0, $result['processed']);
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
        Http::assertNothingSent();
        $this->assertSame(0, IntegrationIdMap::query()->where('company_id', $tenant['company_id'])->count());
    }
}
