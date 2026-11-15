<?php

namespace Tests\Feature\Api;

use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * DEPO PRO .
 *
 * Kilitlenen sözleşme: transfer toplam stoğu değiştirmez (out+in çifti, tek işlem);
 * sayım onayı farkları düzeltme hareketine çevirir (yalnız Admin); tedarik/ABC/devir
 * raporları sözleşmesindeki alanlarla döner.
 */
class StockProTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected int $productId = 0;

    protected int $depoA = 0;

    protected int $depoB = 0;

    protected int $partyId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('depopro@firma.test', 'Depo Pro Firmasi');
        $h = $this->a['headers'];

        $this->productId = $this->apiPost('/api/v1/products', [
            'name' => 'Depo Pro Ürünü',
            'min_stock' => 5,
            'max_stock' => 30,
        ], $h)->assertCreated()->json('product.id');

        $this->depoA = $this->defaultWarehouseId($this->a);
        $this->depoB = $this->apiPost('/api/v1/warehouses', ['name' => 'Şube Depo'], $h)
            ->assertCreated()->json('warehouse.id');

        // A deposuna 20 giriş.
        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->depoA,
            'type' => 'in',
            'quantity' => 20,
            'unit_cost' => 10,
        ], $h)->assertCreated();

        $this->partyId = $this->apiPost('/api/v1/parties', ['name' => 'Depo Cari', 'type' => 'customer'], $h)
            ->assertCreated()->json('party.id');
    }

    public function test_transfer_toplam_stogu_degistirmez(): void
    {
        $h = $this->a['headers'];

        $res = $this->apiPost('/api/v1/stock-transfers', [
            'product_id' => $this->productId,
            'from_warehouse_id' => $this->depoA,
            'to_warehouse_id' => $this->depoB,
            'quantity' => 8,
        ], $h)->assertCreated();

        $this->assertSame(12.0, (float) $res->json('source_stock'));
        $this->assertSame(8.0, (float) $res->json('dest_stock'));

        // Toplam korunur: 20.
        $toplam = StockMovement::stockFor($this->a['company_id'], $this->productId);
        $this->assertSame(20.0, $toplam);

        // Çift kayıt izi taşır.
        $cikis = StockMovement::where('company_id', $this->a['company_id'])
            ->where('type', 'out')->where('warehouse_id', $this->depoA)->first();
        $giris = StockMovement::where('company_id', $this->a['company_id'])
            ->where('type', 'in')->where('warehouse_id', $this->depoB)
            ->where('quantity', 8)->first();
        $this->assertNotNull($cikis);
        $this->assertSame($cikis->reference, $giris->reference);
        $this->assertStringStartsWith('transfer:', (string) $cikis->reference);
    }

    public function test_transfer_kurallari_yetersiz_stok_ve_ayni_depo(): void
    {
        $h = $this->a['headers'];
        $govde = [
            'product_id' => $this->productId,
            'from_warehouse_id' => $this->depoA,
            'to_warehouse_id' => $this->depoB,
            'quantity' => 999,
        ];

        $this->apiPost('/api/v1/stock-transfers', $govde, $h)
            ->assertStatus(422)->assertJsonValidationErrors('quantity');

        $govde['quantity'] = 1;
        $govde['to_warehouse_id'] = $this->depoA;
        $this->apiPost('/api/v1/stock-transfers', $govde, $h)
            ->assertStatus(422)->assertJsonValidationErrors('to_warehouse_id');

        // Başarısız denemeler kayıt üretmez.
        $this->assertSame(1, StockMovement::where('company_id', $this->a['company_id'])->count());
    }

    public function test_sayim_onayi_farklari_duzeltme_hareketine_cevirir(): void
    {
        $h = $this->a['headers'];

        // Rafta 17 sayıldı (kayıt 20) → fark -3.
        $res = $this->apiPost('/api/v1/stock-counts', [
            'warehouse_id' => $this->depoA,
            'lines' => [['product_id' => $this->productId, 'counted_qty' => 17]],
        ], $h)->assertCreated();

        $this->assertSame('draft', $res->json('count.status'));
        $this->assertSame(20.0, (float) $res->json('lines.0.expected_qty'));
        $this->assertSame(-3.0, (float) $res->json('lines.0.diff'));

        $id = $res->json('count.id');
        $onay = $this->apiPost("/api/v1/stock-counts/{$id}/approve", [], $h)->assertOk();
        $this->assertSame(1, $onay->json('adjustments'));

        // Stok 17'ye indi.
        $this->assertSame(17.0, StockMovement::stockFor($this->a['company_id'], $this->productId, $this->depoA));

        // İkinci onay reddedilir.
        $this->apiPost("/api/v1/stock-counts/{$id}/approve", [], $h)
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_sayim_onayi_yalniz_admin(): void
    {
        $uye = $this->register('sayim-uye@firma.test', 'Uye Firmasi');
        $this->apiPost('/api/v1/invitations', ['email' => 'sayim-uye@firma.test', 'role' => 'Personel'], $this->a['headers'])->assertCreated();
        $token = \App\Models\Invitation::where('company_id', $this->a['company_id'])->first()->token;
        $this->apiPost('/api/v1/invitations/accept', ['token' => $token], $uye['headers'])->assertOk();
        $this->apiPost('/api/v1/companies/select', ['company_id' => $this->a['company_id']], $uye['headers'])->assertOk();

        $id = $this->apiPost('/api/v1/stock-counts', [
            'warehouse_id' => $this->depoA,
            'lines' => [['product_id' => $this->productId, 'counted_qty' => 20]],
        ], $this->a['headers'])->assertCreated()->json('count.id');

        $this->apiPost("/api/v1/stock-counts/{$id}/approve", [], $uye['headers'])->assertForbidden();
    }

    public function test_depo_urunleri_listesi(): void
    {
        $liste = $this->apiGet("/api/v1/warehouses/{$this->depoA}/products", $this->a['headers'])
            ->assertOk()->json('data');

        $this->assertSame($this->productId, $liste[0]['product_id']);
        $this->assertSame(20.0, (float) $liste[0]['stock']);
    }

    public function test_tedarik_onerisi_min_alti_urunleri_soyler(): void
    {
        // 20 → 3'e düşür (satışla değil, düzeltmeyle: test sadeliği).
        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->depoA,
            'type' => 'adjust',
            'quantity' => -17,
        ], $this->a['headers'])->assertCreated();

        $data = $this->apiGet('/api/v1/reports/stock-replenishment', $this->a['headers'])
            ->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($this->productId, $data[0]['product_id']);
        // max(30) − stok(3) = 27 öneri.
        $this->assertSame(27.0, (float) $data[0]['suggested_qty']);
    }

    public function test_abc_ve_devir_raporlari(): void
    {
        $h = $this->a['headers'];

        // Ciro üret: 2 adetlik fatura kes + ihraç et.
        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $this->partyId,
            'items' => [['product_id' => $this->productId, 'quantity' => 2, 'unit_price' => 100]],
        ], $h)->assertCreated()->json('document.id');
        $this->apiPost("/api/v1/documents/{$belge}/status", ['status' => 'issued'], $h)->assertOk();

        $abc = $this->apiGet('/api/v1/reports/stock-abc?days=30', $h)->assertOk()->json('data');
        $this->assertNotEmpty($abc);
        $this->assertSame('A', $abc[0]['class']);

        $devir = $this->apiGet('/api/v1/reports/stock-turnover?days=30&stale_days=90', $h)
            ->assertOk()->json('data');
        $satir = collect($devir)->firstWhere('product_id', $this->productId);
        $this->assertNotNull($satir);
        $this->assertSame(2.0, (float) $satir['out_qty']);
        $this->assertFalse((bool) $satir['stale']);
    }
}
