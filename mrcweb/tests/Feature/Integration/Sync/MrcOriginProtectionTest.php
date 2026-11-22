<?php

namespace Tests\Feature\Integration\Sync;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Orders\OrderSyncService;
use App\Integration\Sync\MrcResyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * MRC'de üretilen belgeler Tsoft yeniden kurulumunda yok edilmemelidir.
 *
 * Not: resync kapsam dışı olarak company_id'ye ait tüm product/party kayıtlarını
 * da siler ve bu tabloların FK'ları cascade uygular. Testte korunan satırların
 * ebeveynleri ikinci bir kiracıya ait kurulur; böylece yalnızca bu değişikliğin
 * koruma davranışı ölçülür (ürün/cari silme politikası değiştirilmez).
 */
final class MrcOriginProtectionTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_resync_preserves_mrc_document_items_and_stock_movement(): void
    {
        $ctx = $this->register('mrc-origin-protect@firma.test', 'Mrc Origin Protect');
        $companyId = $ctx['company_id'];

        $keeper = $this->register('mrc-origin-keeper@firma.test', 'Mrc Origin Keeper');
        $keeperCompanyId = $keeper['company_id'];

        $partyId = DB::table('parties')->insertGetId([
            'company_id' => $keeperCompanyId,
            'type' => 'customer',
            'code' => 'KEEPER-1',
            'name' => 'Korunan Ebeveyn Cari',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('products')->insertGetId([
            'company_id' => $keeperCompanyId,
            'name' => 'Korunan Ebeveyn Urun',
            'sku' => 'MRC-ORIGIN-KEEPER-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseId = DB::table('warehouses')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Ana Depo',
            'is_default' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mrcId = DB::table('documents')->insertGetId([
            'company_id' => $companyId,
            'origin' => 'mrc',
            'type' => 'sales_order',
            'serial' => 'SIP-MRC-00001',
            'party_id' => $partyId,
            'status' => 'draft',
            'issue_date' => date('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tsoftId = DB::table('documents')->insertGetId([
            'company_id' => $companyId,
            'origin' => 'tsoft',
            'type' => 'sales_order',
            'serial' => 'SIP-TSOFT-00001',
            'party_id' => $partyId,
            'status' => 'draft',
            'issue_date' => date('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mrcItemId = DB::table('document_items')->insertGetId([
            'company_id' => $companyId,
            'document_id' => $mrcId,
            'product_id' => $productId,
            'name' => 'MRC satiri',
            'quantity' => 1,
            'unit_price' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tsoftItemId = DB::table('document_items')->insertGetId([
            'company_id' => $companyId,
            'document_id' => $tsoftId,
            'product_id' => $productId,
            'name' => 'Tsoft satiri',
            'quantity' => 1,
            'unit_price' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mrcMovementId = DB::table('stock_movements')->insertGetId([
            'company_id' => $companyId,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'type' => 'out',
            'quantity' => -1,
            'unit_cost' => 50,
            'reference' => 'issue:'.$mrcId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_movements')->insert([
            'company_id' => $companyId,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'type' => 'out',
            'quantity' => -1,
            'unit_cost' => 50,
            'reference' => 'issue:'.$tsoftId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new MrcResyncService(
            adapterFactory: fn () => new class
            {
                public function fetchAllProducts(): array
                {
                    return [];
                }

                public function fetchAllCustomers(): array
                {
                    return [];
                }

                public function fetchOrders(): array
                {
                    return [];
                }
            },
            backupRunner: fn () => 0,
        );

        $report = $service->run($companyId, true, false, true);

        $this->assertTrue($report['executed']);
        $this->assertSame('success', $report['status']);
        $this->assertSame(1, $report['preserved_mrc_documents']);
        $this->assertSame(1, $report['deleted']['documents'], 'Yalnizca Tsoft belgesi silinmelidir.');

        $this->assertDatabaseHas('documents', ['id' => $mrcId, 'origin' => 'mrc']);
        $this->assertDatabaseMissing('documents', ['id' => $tsoftId]);
        $this->assertDatabaseHas('document_items', ['id' => $mrcItemId, 'document_id' => $mrcId]);
        $this->assertDatabaseMissing('document_items', ['id' => $tsoftItemId]);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $mrcMovementId,
            'reference' => 'issue:'.$mrcId,
        ]);
        $this->assertDatabaseMissing('stock_movements', ['reference' => 'issue:'.$tsoftId]);
    }

    public function test_ingested_tsoft_order_is_marked_with_tsoft_origin(): void
    {
        $ctx = $this->register('mrc-origin-ingest@firma.test', 'Mrc Origin Ingest');

        $document = (new OrderSyncService)->ingest($ctx['company_id'], new CanonicalOrder(
            'MRC-ORIGIN-ORDER-1',
            'MRC-ORIGIN-ORDER-1',
            'TRY',
            '100.0000',
            '12',
            null,
            false,
            Carbon::parse('2026-11-22 12:00:00'),
            null,
            null,
            [],
        ));

        $this->assertSame('tsoft', $document->origin);
        $this->assertSame('tsoft', DB::table('documents')->where('id', $document->id)->value('origin'));
        $this->assertSame(0, DB::table('documents')
            ->where('company_id', $ctx['company_id'])
            ->where('origin', 'mrc')
            ->count());
    }
}
