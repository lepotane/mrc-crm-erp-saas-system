<?php

namespace Tests\Unit\Integration\Sync;

use App\Integration\Canonical\SyncResult;
use App\Integration\Sync\FieldOwnership;
use App\Integration\Sync\PriceSyncService;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class PriceSyncServiceTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_dry_run_does_not_call_adapter(): void
    {
        $ctx = $this->register('fiyat-dry@firma.test', 'Fiyat Dry Firma');
        config()->set('integrations.dry_run', true);

        Product::create([
            'company_id' => $ctx['company_id'],
            'name' => 'Dry Urun',
            'sku' => 'SKU-FIYAT-1',
            'currency_code' => 'TRY',
            'sale_price' => '0',
        ]);

        $fake = $this->fakeAdapter();
        $service = new PriceSyncService(fn (int $companyId) => $fake);
        $result = $service->push($ctx['company_id'], 'SKU-FIYAT-1');

        $this->assertTrue($result->ok);
        $this->assertTrue($result->skipped);
        $this->assertSame(0, $fake->calls);
    }

    public function test_pull_maps_decimal_strings(): void
    {
        $ctx = $this->register('fiyat-pull@firma.test', 'Fiyat Pull Firma');

        $product = Product::create([
            'company_id' => $ctx['company_id'],
            'name' => 'Pull Urun',
            'sku' => 'SKU-FIYAT-2',
            'currency_code' => 'TRY',
            'sale_price' => '0',
        ]);

        (new FieldOwnership)->set($ctx['company_id'], 'product', 'price', 'tsoft');

        $service = new PriceSyncService;
        $result = $service->pull($ctx['company_id'], [
            ['sku' => 'SKU-FIYAT-2', 'sale_price' => '19.9900', 'cost_price' => '9.5000', 'currency' => 'TRY'],
            ['sku' => 'SKU-FIYAT-2', 'ProductCode' => 'SKU-FIYAT-2', 'salePrice' => '21.5000', 'Currency' => 'USD'],
        ]);

        $this->assertSame(2, $result['upserted']);

        $try = ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('currency_code', 'TRY')
            ->firstOrFail();
        $this->assertSame('19.9900', (string) $try->sale_price);
        $this->assertSame('9.5000', (string) $try->cost_price);

        $usd = ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('currency_code', 'USD')
            ->firstOrFail();
        $this->assertSame('21.5000', (string) $usd->sale_price);
        $this->assertNull($usd->cost_price);
    }

    /** @return object{calls:int,prices:array<int,array<string,mixed>>} */
    private function fakeAdapter(): object
    {
        return new class
        {
            public int $calls = 0;

            /** @var array<int,array<string,mixed>> */
            public array $prices = [];

            public function pushPrice(mixed $price): SyncResult
            {
                $this->calls++;
                $this->prices[] = is_object($price) && method_exists($price, 'toArray')
                    ? $price->toArray()
                    : (array) $price;

                return SyncResult::success('dis-ref');
            }
        };
    }
}
