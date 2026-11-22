<?php

namespace Tests\Feature\Integration\Sync;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Sync\SyncDiffService;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\SyncDiff;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class SyncDiffServiceTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('integrations.dry_run', false);
    }

    public function test_scan_detects_new_remote_product_changed_price_and_new_order(): void
    {
        $ctx = $this->register('sync-diff-tara@firma.test', 'Sync Diff Tara Firma');
        $this->createLocalProduct($ctx['company_id']);

        $fake = $this->fakeAdapter();
        $service = new SyncDiffService(fn () => $fake);

        $result = $service->scan($ctx['company_id']);

        $this->assertSame(2, $result['scanned']);
        $this->assertSame(3, $result['new']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['orders']);
        $this->assertArrayNotHasKey('error', $result);

        $newProduct = SyncDiff::query()
            ->where('company_id', $ctx['company_id'])
            ->where('entity_type', SyncDiff::TYPE_PRODUCT)
            ->where('external_key', 'SKU-NEW-FARK')
            ->firstOrFail();
        $this->assertSame(SyncDiff::DIFF_NEW_REMOTE, $newProduct->diff_type);
        $this->assertSame('Yeni Tsoft Urunu', $newProduct->external_snapshot['name']);

        $price = SyncDiff::query()
            ->where('company_id', $ctx['company_id'])
            ->where('entity_type', SyncDiff::TYPE_PRICE)
            ->where('external_key', 'SKU-EXIST-FARK|TRY')
            ->firstOrFail();
        $this->assertSame(SyncDiff::DIFF_CHANGED_REMOTE, $price->diff_type);
        $this->assertSame('10.0000', $price->mrc_snapshot['sale_price']);
        $this->assertSame('25.0000', $price->external_snapshot['sale_price']);
        $this->assertSame('TRY', $price->external_snapshot['currency']);

        $order = SyncDiff::query()
            ->where('company_id', $ctx['company_id'])
            ->where('entity_type', SyncDiff::TYPE_ORDER)
            ->where('external_key', 'ORD-FARK-1')
            ->firstOrFail();
        $this->assertSame(SyncDiff::DIFF_NEW_REMOTE, $order->diff_type);

        // İkinci tarama geçmişi bozmaz, yalnızca tespit anını günceller.
        $before = (string) $newProduct->external_snapshot['name'];
        $again = $service->scan($ctx['company_id']);
        $this->assertSame(0, $again['new']);
        $this->assertSame(3, $again['updated']);
        $this->assertSame($before, $newProduct->fresh()->external_snapshot['name']);

        $summary = $service->summary($ctx['company_id']);
        $this->assertSame(3, $summary['total']);
        $this->assertSame(3, $summary['pending']);
        $this->assertSame(1, $summary['by_type']['product']);
        $this->assertSame(1, $summary['by_type']['price']);
        $this->assertSame(1, $summary['by_type']['order']);
        $this->assertSame(0, $summary['by_type']['stock']);
        $this->assertArrayNotHasKey('snapshots', $summary);
        $this->assertArrayNotHasKey('details', $summary);
    }

    public function test_import_creates_exactly_one_product_and_one_order_and_respects_ownership(): void
    {
        $ctx = $this->register('sync-diff-ice@firma.test', 'Sync Diff İçe Firma');
        $this->createLocalProduct($ctx['company_id']);

        $fake = $this->fakeAdapter();
        $service = new SyncDiffService(fn () => $fake);
        $service->scan($ctx['company_id']);

        $ids = SyncDiff::query()->where('company_id', $ctx['company_id'])->pluck('id')->all();
        $import = $service->import($ctx['company_id'], $ids);

        $this->assertSame(3, $import['total']);
        $this->assertSame(2, $import['imported']);
        $this->assertSame(1, $import['ignored']);
        $this->assertSame(0, $import['failed']);
        $this->assertSame(1, $fake->fetchOrderCalls);

        $this->assertSame(2, Product::query()->where('company_id', $ctx['company_id'])->count());
        $this->assertNotNull(
            Product::query()->where('company_id', $ctx['company_id'])->where('sku', 'SKU-NEW-FARK')->first()
        );
        $this->assertSame(
            1,
            Document::query()
                ->where('company_id', $ctx['company_id'])
                ->where('type', Document::TYPE_SALES_ORDER)
                ->count()
        );

        // Varsayılan sahiplik "mrc" olduğu için fiyat farkı yazılmadı.
        $priceDiff = SyncDiff::query()
            ->where('company_id', $ctx['company_id'])
            ->where('entity_type', SyncDiff::TYPE_PRICE)
            ->firstOrFail();
        $this->assertSame(SyncDiff::STATUS_IGNORED, $priceDiff->status);
        $this->assertSame('10.0000', (string) ProductPrice::query()
            ->where('company_id', $ctx['company_id'])
            ->where('currency_code', 'TRY')
            ->value('sale_price'));
        $this->assertNotNull($priceDiff->resolved_at);

        $summary = $service->summary($ctx['company_id']);
        $this->assertSame(0, $summary['pending']);
        $this->assertSame(2, $summary['imported']);
        $this->assertSame(1, $summary['ignored']);
    }

    public function test_dry_run_scan_reads_but_import_is_skipped(): void
    {
        $ctx = $this->register('sync-diff-dry@firma.test', 'Sync Diff Dry Firma');
        $this->createLocalProduct($ctx['company_id']);

        $fake = $this->fakeAdapter();
        $service = new SyncDiffService(fn () => $fake);
        $result = $service->scan($ctx['company_id']);
        $this->assertSame(3, $result['new']);

        config()->set('integrations.dry_run', true);
        $diff = SyncDiff::query()
            ->where('company_id', $ctx['company_id'])
            ->where('entity_type', SyncDiff::TYPE_PRODUCT)
            ->firstOrFail();

        $import = $service->import($ctx['company_id'], [$diff->id]);

        $this->assertSame(1, $import['skipped']);
        $this->assertSame(0, $import['imported']);
        $this->assertSame(1, Product::query()->where('company_id', $ctx['company_id'])->count());
        $this->assertSame(SyncDiff::STATUS_PENDING, $diff->fresh()->status);
        $this->assertSame(0, $fake->fetchOrderCalls);
    }

    public function test_ignore_marks_diff_without_writing_to_mrc(): void
    {
        $ctx = $this->register('sync-diff-yoksay@firma.test', 'Sync Diff Yoksay Firma');
        $this->createLocalProduct($ctx['company_id']);

        $fake = $this->fakeAdapter();
        $service = new SyncDiffService(fn () => $fake);
        $service->scan($ctx['company_id']);

        $diff = SyncDiff::query()
            ->where('company_id', $ctx['company_id'])
            ->where('entity_type', SyncDiff::TYPE_ORDER)
            ->firstOrFail();

        $result = $service->ignore($ctx['company_id'], [$diff->id]);

        $this->assertSame(1, $result['ignored']);
        $this->assertSame(SyncDiff::STATUS_IGNORED, $diff->fresh()->status);
        $this->assertSame(1, Product::query()->where('company_id', $ctx['company_id'])->count());
        $this->assertSame(0, $fake->fetchOrderCalls);
    }

    private function createLocalProduct(int $companyId): Product
    {
        $product = Product::create([
            'company_id' => $companyId,
            'name' => 'Mevcut Urun',
            'sku' => 'SKU-EXIST-FARK',
            'barcode' => 'BARKOD-EXIST-FARK',
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

        return $product;
    }

    /** Dış adapter yalnızca okuma yöntemleriyle taklit edilir (duck typing). */
    private function fakeAdapter(): object
    {
        $order = new CanonicalOrder(
            'ORD-FARK-1',
            'ORD-FARK-1',
            'TRY',
            '150.0000',
            'new',
            null,
            false,
            now(),
            null,
            null,
            [new CanonicalOrderLine('SKU-EXIST-FARK', 'Mevcut Urun', '1', '10.0000', '0', '0', 'LINE-1')],
            [],
        );

        return new class($order)
        {
            public int $fetchOrderCalls = 0;

            public function __construct(private readonly CanonicalOrder $order) {}

            /** @return array<int,array<string,mixed>> */
            public function fetchAllProducts(): array
            {
                return [
                    [
                        'ProductCode' => 'SKU-NEW-FARK',
                        'ProductName' => 'Yeni Tsoft Urunu',
                        'Barcode' => 'BARKOD-NEW-FARK',
                        'nameSource' => 'tsoft',
                        'SellingPrice' => '55.0000',
                        'Currency' => 'TRY',
                        'Stock' => '3',
                    ],
                    [
                        'ProductCode' => 'SKU-EXIST-FARK',
                        'ProductName' => 'Mevcut Urun',
                        'Barcode' => 'BARKOD-EXIST-FARK',
                        'nameSource' => 'mrc',
                        'SellingPrice' => '25.0000',
                        'Currency' => 'TRY',
                        'Stock' => '0',
                    ],
                ];
            }

            /** @return array<int,CanonicalOrder> */
            public function fetchOrders(?CarbonInterface $since = null, int $limit = 100): array
            {
                return [$this->order];
            }

            public function fetchOrder(string $externalRef): ?CanonicalOrder
            {
                $this->fetchOrderCalls++;

                return $externalRef === $this->order->externalRef ? $this->order : null;
            }
        };
    }
}
