<?php

namespace Tests\Feature\Integration\Sync;

use App\Integration\Sync\IntegrationStockState;
use App\Integration\Sync\ParallelStockEngine;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class ParallelStockEngineTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_same_external_ref_creates_exactly_one_movement(): void
    {
        $ctx = $this->register('stok-motor@firma.test', 'Stok Motor Firma');
        $warehouseId = $this->defaultWarehouseId($ctx);
        $unitId = $this->unitId($ctx);

        $response = $this->apiPost('/api/v1/products', [
            'name' => 'Motor Urunu',
            'sku' => 'SKU-STOK-1',
            'barcode' => 'BARKOD-STOK-1',
            'unit_id' => $unitId,
        ], $ctx['headers']);
        $response->assertCreated();

        $productId = (int) $response->json('product.id');
        $this->assertGreaterThan(0, $productId);

        $engine = new ParallelStockEngine;
        $first = $engine->applyInbound(
            $ctx['company_id'], 'SKU-STOK-1', '10', 'tsoft', 'dis-stok-1', $warehouseId
        );
        $second = $engine->applyInbound(
            $ctx['company_id'], 'SKU-STOK-1', '10', 'tsoft', 'dis-stok-1', $warehouseId
        );

        $this->assertSame(
            1,
            StockMovement::query()
                ->where('company_id', $ctx['company_id'])
                ->where('product_id', $productId)
                ->count()
        );
        $this->assertSame('10.0000', $first->quantity);
        $this->assertSame('10.0000', $second->quantity);
        $this->assertSame(1, $first->version);
        $this->assertSame(1, $second->version);

        $state = IntegrationStockState::query()
            ->where('company_id', $ctx['company_id'])
            ->where('sku', 'SKU-STOK-1')
            ->where('warehouse_id', $warehouseId)
            ->first();

        $this->assertNotNull($state);
        $this->assertSame(1, (int) $state->version);
        $this->assertSame('10.0000', (string) $state->quantity);
    }
}
