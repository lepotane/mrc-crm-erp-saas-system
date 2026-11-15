<?php

namespace Tests\Feature\Api;

use App\Models\Document;
use App\Models\Payment;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * İADE FATURASI .
 *
 * Kilitlenen sözleşme: iade birinci sınıf belgedir (IAD serisi); faturadan
 * tek tuşla açılır (kaynak satır miktarını aşamaz); ihraçta stok geri girer
 * (ortalama maliyet, WAC nötr); ödeme iade belgesine bağlanabilir (müşteriye
 * iade ödemesi); kâr raporu iadeyi netler.
 */
class ReturnTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected int $partyId = 0;

    protected int $productId = 0;

    protected int $warehouseId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('iade@firma.test', 'Iade Firmasi');
        $h = $this->a['headers'];

        $this->partyId = $this->apiPost('/api/v1/parties', ['name' => 'Iade Müşteri', 'type' => 'customer'], $h)
            ->assertCreated()->json('party.id');

        $this->productId = $this->apiPost('/api/v1/products', ['name' => 'İadeli Ürün', 'sale_price' => 100], $h)
            ->assertCreated()->json('product.id');

        // Ortalama maliyet (alım faturasından gelmiş gibi).
        $this->apiPatch("/api/v1/products/{$this->productId}", ['cost_price' => 40], $h)->assertOk();

        $this->warehouseId = $this->defaultWarehouseId($this->a);

        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'type' => 'in',
            'quantity' => 10,
            'unit_cost' => 40,
        ], $h)->assertCreated();
    }

    /** @return array{headers: array<string,string>, company_id: int} */
    protected function ctx(): array
    {
        return $this->a;
    }

    protected function faturaKes(int $adet = 2): int
    {
        $h = $this->a['headers'];

        $id = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $this->partyId,
            'items' => [['product_id' => $this->productId, 'quantity' => $adet, 'unit_price' => 100]],
        ], $h)->assertCreated()->json('document.id');

        $this->apiPost("/api/v1/documents/{$id}/status", ['status' => 'issued'], $h)->assertOk();

        return $id;
    }

    public function test_faturadan_iade_acilir_stok_geri_girer(): void
    {
        $h = $this->a['headers'];
        $fatura = $this->faturaKes(2);

        // Satış sonrası stok: 10 − 2 = 8.
        $this->assertSame(8.0, StockMovement::stockFor($this->a['company_id'], $this->productId));

        $res = $this->apiPost("/api/v1/documents/{$fatura}/return", [
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
        ], $h)->assertCreated();

        $iade = $res->json('document');
        $this->assertSame('return', $iade['type']);
        $this->assertSame($fatura, $iade['source_document_id']);
        $this->assertStringStartsWith('IAD-', $iade['serial']);

        // Satır fiyatı/kdvisi kaynaktan aynen taşınır.
        $this->assertSame(100.0, (float) $res->json('items.0.unit_price'));

        // İhraç: stok geri girer (8 + 1) ve satır COGS'si ortalamadır (40).
        $this->apiPost("/api/v1/documents/{$iade['id']}/status", ['status' => 'issued'], $h)->assertOk();
        $this->assertSame(9.0, StockMovement::stockFor($this->a['company_id'], $this->productId));

        $cogs = \App\Models\DocumentItem::where('document_id', $iade['id'])->value('cogs');
        $this->assertSame(40.0, (float) $cogs);
    }

    public function test_iade_kaynagi_asamaz(): void
    {
        $h = $this->a['headers'];
        $fatura = $this->faturaKes(2);

        $this->apiPost("/api/v1/documents/{$fatura}/return", [
            'lines' => [['product_id' => $this->productId, 'quantity' => 3]],
        ], $h)->assertStatus(422)->assertJsonValidationErrors('lines');

        // Tamamı iade edildikten sonra ikinci iade boş döner.
        $this->apiPost("/api/v1/documents/{$fatura}/return", [
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
        ], $h)->assertCreated();

        $this->apiPost("/api/v1/documents/{$fatura}/return", [
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
        ], $h)->assertStatus(422);
    }

    public function test_iade_tahsilati_belgeyi_kapatir(): void
    {
        $h = $this->a['headers'];
        $fatura = $this->faturaKes(1);

        $iadeId = $this->apiPost("/api/v1/documents/{$fatura}/return", [], $h)
            ->assertCreated()->json('document.id');

        $this->apiPost("/api/v1/documents/{$iadeId}/status", ['status' => 'issued'], $h)->assertOk();

        // Müşteriye iade ödemesi (ters yön): iade belgesine bağlı.
        $res = $this->apiPost('/api/v1/payments', [
            'party_id' => $this->partyId,
            'direction' => 'out',
            'amount' => 120,
            'document_id' => $iadeId,
        ], $h)->assertCreated();

        $this->assertSame('paid', $res->json('document_status'));

        $odeme = Payment::where('document_id', $iadeId)->first();
        $this->assertNotNull($odeme);
        $this->assertSame('out', $odeme->direction);
    }

    public function test_kar_raporu_iadeyi_netler(): void
    {
        $h = $this->a['headers'];
        $fatura = $this->faturaKes(2);

        $once = $this->apiGet('/api/v1/reports/profit?group_by=day', $h)->assertOk()->json();
        $brutOnce = (float) $once['totals']['gross_total'];
        $this->assertGreaterThan(0, $brutOnce);

        $iadeId = $this->apiPost("/api/v1/documents/{$fatura}/return", [
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
        ], $h)->assertCreated()->json('document.id');
        $this->apiPost("/api/v1/documents/{$iadeId}/status", ['status' => 'issued'], $h)->assertOk();

        $sonra = $this->apiGet('/api/v1/reports/profit?group_by=day', $h)->assertOk()->json();
        $brutSonra = (float) $sonra['totals']['gross_total'];

        // 1 adetlik iade brütü düşürür (2×120 → 1×120).
        $this->assertEqualsWithDelta($brutOnce - 120, $brutSonra, 0.01);

        // Marj satırı ve toplamı tutarlıdır.
        $satir = $sonra['data'][0];
        $this->assertNotNull($satir['margin']);
        $this->assertEqualsWithDelta($satir['profit'] / $satir['net_total'], (float) $satir['margin'], 0.0001);
    }

    public function test_iade_baska_firmaya_sizmaz(): void
    {
        $h = $this->a['headers'];
        $fatura = $this->faturaKes(1);

        $b = $this->register('iade-b@firma.test', 'Iade B Firmasi');

        $this->apiPost("/api/v1/documents/{$fatura}/return", [], $b['headers'])->assertNotFound();
    }
}
