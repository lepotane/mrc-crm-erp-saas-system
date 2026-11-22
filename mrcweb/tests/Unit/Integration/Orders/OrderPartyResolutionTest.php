<?php

namespace Tests\Unit\Integration\Orders;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Orders\OrderSyncService;
use App\Integration\Sync\IntegrationIdMap;
use App\Models\Document;
use App\Models\Party;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * Sipariş, customer/getCustomers eşlemesi varsa placeholder yerine gerçek
 * cariye bağlanır (vergi numarası aramasından önce).
 */
class OrderPartyResolutionTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_customer_code_eslemesi_gercek_cariyi_bulur(): void
    {
        $ctx = $this->register('sip-cari@firma.test', 'Sip Cari');
        $companyId = $ctx['company_id'];

        $party = Party::create([
            'company_id' => $companyId,
            'type' => 'customer',
            'code' => '00001',
            'name' => 'Gerçek Müşteri',
            'tax_number' => '1234567890',
            'is_active' => true,
        ]);

        IntegrationIdMap::create([
            'company_id' => $companyId,
            'mrc_type' => 'party',
            'mrc_id' => (int) $party->id,
            'external_type' => 'customer',
            'external_key' => 'CUST-777',
        ]);

        $order = new CanonicalOrder(
            'ORD-777',
            'SIP-777',
            'TRY',
            '10.0000',
            '',
            null,
            false,
            Carbon::parse('2026-11-20 10:00:00'),
            null,
            null,
            [new CanonicalOrderLine('SKU-1', 'Ürün 1', '1.0000', '10.0000', '0', '0')],
            ['CustomerCode' => 'CUST-777'],
        );

        $document = (new OrderSyncService)->ingest($companyId, $order);

        $this->assertSame((int) $party->id, (int) $document->party_id);
        $this->assertSame(1, Party::query()->where('company_id', $companyId)->count());
        $this->assertSame(1, Document::query()->where('company_id', $companyId)->count());
    }
}
