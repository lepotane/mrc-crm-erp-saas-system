<?php

namespace Tests\Feature\Integration\Finance;

use App\Integration\Reports\ProductProfitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/** Ürün kârlılığı: net ciro − (birim maliyet × miktar), ondalık hassasiyet korunur. */
final class ProductProfitServiceTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_urun_bazinda_kar_ve_marj_dogru_hesaplanir(): void
    {
        $ctx = $this->register('fin-profit@firma.test', 'Finans Kar');
        $headers = $ctx['headers'];
        $companyId = $ctx['company_id'];

        $party = $this->apiPost('/api/v1/parties', ['name' => 'Musteri'], $headers)
            ->assertCreated()->json('party');
        $product = $this->apiPost('/api/v1/products', [
            'name' => 'Kar Urunu',
            'sku' => 'KAR-1',
            'cost_price' => 60,
            'sale_price' => 100,
            'tax_rate' => 20,
        ], $headers)->assertCreated()->json('product');

        // 10 × 100 − %10 iskonto = 900 net ciro; maliyet 10 × 60 = 600; kâr 300.
        $doc = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $party['id'],
            'issue_date' => '2026-11-20',
            'items' => [[
                'product_id' => $product['id'],
                'quantity' => 10,
                'unit_price' => 100,
                'discount_rate' => 10,
            ]],
        ], $headers)->assertCreated()->json('document');
        $this->apiPost("/api/v1/documents/{$doc['id']}/status", ['status' => 'issued'], $headers)->assertOk();

        $report = (new ProductProfitService)->profit($companyId, '2026-11-01', '2026-11-30');

        $this->assertCount(1, $report['rows']);
        $row = $report['rows'][0];
        $this->assertSame('KAR-1', $row['sku']);
        $this->assertSame('Kar Urunu', $row['name']);
        $this->assertSame('10.0000', $row['sold_qty']);
        $this->assertSame('900.0000', $row['revenue']);
        $this->assertSame('600.0000', $row['cogs']);
        $this->assertSame('300.0000', $row['profit']);
        $this->assertSame('0.3333', $row['margin']);

        $this->assertSame('900.0000', $report['totals']['revenue']);
        $this->assertSame('300.0000', $report['totals']['profit']);
    }

    public function test_baska_firmanin_verisi_karismiyor(): void
    {
        $a = $this->register('fin-profit-a@firma.test', 'Firma A')['company_id'];
        $b = $this->register('fin-profit-b@firma.test', 'Firma B')['company_id'];
        $this->assertNotSame($a, $b);

        $empty = (new ProductProfitService)->profit($b, '2026-11-01', '2026-11-30');
        $this->assertSame([], $empty['rows']);
        $this->assertSame('0.0000', $empty['totals']['revenue']);
    }
}
