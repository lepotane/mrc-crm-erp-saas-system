<?php

namespace Tests\Feature\Integration\Sync;

use App\Integration\Canonical\CanonicalPrice;
use App\Integration\Canonical\SyncResult;
use App\Integration\Sync\PriceSyncService;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class PriceSyncScopeTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('integrations.dry_run', false);
    }

    public function test_sync_out_with_single_sku_pushes_exactly_one(): void
    {
        $ctx = $this->register('fiyat-kapsam-tek@firma.test', 'Fiyat Kapsam Tek Firma');
        $this->createProducts($ctx['company_id']);

        $fake = $this->fakeAdapter();
        $service = new PriceSyncService(fn () => $fake);

        $summary = $service->sync($ctx['company_id'], 'out', ['SKU-1']);

        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['pushed']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(0, $summary['skipped']);
        $this->assertSame(1, $fake->pushCalls);
        $this->assertSame(['SKU-1'], array_column($fake->pushedSkus, 'sku'));
    }

    public function test_sync_out_with_all_pushes_every_product(): void
    {
        $ctx = $this->register('fiyat-kapsam-tum@firma.test', 'Fiyat Kapsam Tüm Firma');
        $this->createProducts($ctx['company_id']);

        $fake = $this->fakeAdapter();
        $service = new PriceSyncService(fn () => $fake);

        $summary = $service->sync($ctx['company_id'], 'out', [], true);

        $this->assertSame(3, $summary['total']);
        $this->assertSame(3, $summary['pushed']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(3, $fake->pushCalls);
    }

    public function test_sync_in_respects_ownership_unless_forced(): void
    {
        $ctx = $this->register('fiyat-kapsam-ice@firma.test', 'Fiyat Kapsam İçe Firma');
        $products = $this->createProducts($ctx['company_id']);
        $external = [['sku' => 'SKU-2', 'currency' => 'TRY', 'sale_price' => '99.9900']];

        $fake = $this->fakeAdapter($external);
        $service = new PriceSyncService(fn () => $fake);

        $notForced = $service->sync($ctx['company_id'], 'in', ['SKU-2'], false, false);

        $this->assertSame(0, $notForced['pushed']);
        $this->assertSame(1, $notForced['skipped']);
        $this->assertSame('10.0000', $this->priceOf($products['SKU-2']->id));
        $this->assertSame(1, $fake->fetchCalls);

        $forced = $service->sync($ctx['company_id'], 'in', ['SKU-2'], false, true);

        $this->assertSame(1, $forced['pushed']);
        $this->assertSame(0, $forced['skipped']);
        $this->assertSame('99.9900', $this->priceOf($products['SKU-2']->id));
    }

    public function test_dry_run_out_skips_and_never_calls_adapter(): void
    {
        $ctx = $this->register('fiyat-kapsam-dry@firma.test', 'Fiyat Kapsam Dry Firma');
        $this->createProducts($ctx['company_id']);
        config()->set('integrations.dry_run', true);

        $fake = $this->fakeAdapter();
        $service = new PriceSyncService(fn () => $fake);

        $summary = $service->sync($ctx['company_id'], 'out', ['SKU-1']);

        $this->assertSame(0, $summary['pushed']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(0, $fake->pushCalls);
        $this->assertSame(0, $fake->fetchCalls);
    }

    /** @return array<string,Product> */
    private function createProducts(int $companyId): array
    {
        $products = [];

        foreach ([1, 2, 3] as $index) {
            $sku = 'SKU-'.$index;

            $product = Product::create([
                'company_id' => $companyId,
                'name' => 'Fiyat Urunu '.$index,
                'sku' => $sku,
                'barcode' => 'BARKOD-FIYAT-'.$index,
                'currency_code' => 'TRY',
                'sale_price' => '0',
            ]);

            ProductPrice::create([
                'company_id' => $companyId,
                'product_id' => $product->id,
                'currency_code' => 'TRY',
                'sale_price' => '10.0000',
                'cost_price' => '5.0000',
            ]);

            $products[$sku] = $product;
        }

        return $products;
    }

    private function priceOf(int $productId): string
    {
        return (string) ProductPrice::query()
            ->where('product_id', $productId)
            ->where('currency_code', 'TRY')
            ->value('sale_price');
    }

    /** @param array<int,array<string,mixed>> $external */
    private function fakeAdapter(array $external = []): object
    {
        return new class($external)
        {
            public int $pushCalls = 0;

            public int $fetchCalls = 0;

            /** @var array<int,array<string,mixed>> */
            public array $pushedSkus = [];

            /** @param array<int,array<string,mixed>> $external */
            public function __construct(private readonly array $external) {}

            public function pushPrice(mixed $price): SyncResult
            {
                $this->pushCalls++;
                $this->pushedSkus[] = $price instanceof CanonicalPrice
                    ? ['sku' => $price->sku, 'sale_price' => $price->salePrice]
                    : ['sku' => '', 'sale_price' => ''];

                return SyncResult::success('dis-ref');
            }

            /** @param array<int,mixed> $skus @return array<int,array<string,mixed>> */
            public function fetchProducts(array $skus): array
            {
                $this->fetchCalls++;

                return $this->external;
            }
        };
    }
}
