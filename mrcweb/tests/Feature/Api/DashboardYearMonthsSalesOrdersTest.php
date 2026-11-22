<?php

namespace Tests\Feature\Api;

use App\Models\Document;
use App\Models\DocumentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * YILLIK KÂR ANALİZİ — TSOFT SATIŞ SİPARİŞLERİ.
 *
 * Tsoft aktarımı fatura üretmez; satışlar `sales_order` / `confirmed` olarak gelir.
 * year_months yalnız faturaları saydığı için pano "₺0,00 / 0 işlem" gösteriyordu.
 * Bu test gerçekleşen siparişlerin ciro/kâra dahil edildiğini, taslağın ise
 * hariç tutulduğunu ve para birimi kırılımının (EUR) korunduğunu kilitler.
 */
class DashboardYearMonthsSalesOrdersTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('yillik-satis@firma.test', 'Yillik Satis Firmasi');
    }

    protected function yearMonths(): array
    {
        $grid = $this->apiGet('/api/v1/dashboard/widgets', $this->a['headers'])
            ->assertOk()->json('data');

        foreach ($grid as $satir) {
            if ($satir['key'] === 'year_months') {
                return $satir['data']['months'] ?? [];
            }
        }

        $this->fail('year_months widget bulunamadı');
    }

    protected function createParty(): int
    {
        return (int) $this->apiPost('/api/v1/parties', ['name' => 'Tsoft Müşteri'], $this->a['headers'])
            ->assertCreated()->json('party.id');
    }

    protected function createSalesOrder(int $partyId, string $status, float $grandTotal, string $serial): Document
    {
        $belge = Document::create([
            'company_id' => $this->a['company_id'],
            'type' => Document::TYPE_SALES_ORDER,
            'serial' => $serial,
            'party_id' => $partyId,
            'status' => $status,
            'issue_date' => now()->format('Y-m-d'),
            'currency_code' => 'EUR',
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
        ]);

        DocumentItem::create([
            'document_id' => $belge->id,
            'company_id' => $this->a['company_id'],
            'name' => 'Tsoft Kalemi',
            'quantity' => 1,
            'unit_price' => $grandTotal,
            'line_total' => $grandTotal,
            'cogs' => 15,
        ]);

        return $belge;
    }

    public function test_onaylanmis_satis_siparisi_ciro_ve_kara_dahil_edilir_taslak_haric_tutulur(): void
    {
        $partyId = $this->createParty();

        $this->createSalesOrder($partyId, 'confirmed', 107.1, 'SIP-26-00001');
        // Taslak gerçekleşmemiş satıştır: ciro/kâra girmemelidir.
        $this->createSalesOrder($partyId, 'draft', 500.0, 'SIP-26-00002');

        $aylar = $this->yearMonths();
        $this->assertCount(12, $aylar);

        $kod = now()->format('Y-m');
        $ay = null;
        foreach ($aylar as $satir) {
            if ($satir['month'] === $kod) {
                $ay = $satir;
                break;
            }
        }
        $this->assertNotNull($ay, 'İçinde bulunulan ay seride olmalı');

        $this->assertGreaterThanOrEqual(1, (int) $ay['documents']);
        $this->assertEquals(1, (int) $ay['documents'], 'Yalnız onaylanmış sipariş sayılmalı');
        $this->assertEqualsWithDelta(107.1, (float) $ay['revenue'], 0.001);
        $this->assertEqualsWithDelta(92.1, (float) $ay['profit'], 0.001); // 107.1 - 15

        $this->assertArrayHasKey('EUR', $ay['currencies']);
        $this->assertEqualsWithDelta(107.1, (float) $ay['currencies']['EUR']['revenue'], 0.001);
        $this->assertEqualsWithDelta(92.1, (float) $ay['currencies']['EUR']['profit'], 0.001);
    }
}
