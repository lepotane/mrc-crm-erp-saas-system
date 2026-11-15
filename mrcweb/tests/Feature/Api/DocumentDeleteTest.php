<?php

namespace Tests\Feature\Api;

use App\Models\Document;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * BELGE SİLME .
 *
 * Kilitlenen sözleşme: HER durumda silinir (yerel ön-muhasebe kaydıdır).
 * Kesilmişse stok aynaya düşer (`cancel:<id>`); bağlı iadeler aynıyla gider;
 * ödemeler denetim izli düşer; dönüştürme bağları kopar (çocuk durur);
 * kuyruk işlerinin belge bağı kpar.
 */
class DocumentDeleteTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected int $partyId = 0;

    protected int $productId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('silme@firma.test', 'Silme Firmasi');
        $h = $this->a['headers'];

        $this->partyId = $this->apiPost('/api/v1/parties', ['name' => 'Silme Cari', 'type' => 'customer'], $h)
            ->assertCreated()->json('party.id');

        $this->productId = $this->apiPost('/api/v1/products', ['name' => 'Silme Ürünü', 'sale_price' => 100], $h)
            ->assertCreated()->json('product.id');

        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->defaultWarehouseId($this->a),
            'type' => 'in',
            'quantity' => 10,
            'unit_cost' => 10,
        ], $h)->assertCreated();
    }

    protected function stok(): float
    {
        return (float) StockMovement::where('product_id', $this->productId)
            ->selectRaw("SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END) as net")
            ->value('net');
    }

    protected function faturaKes(): int
    {
        $h = $this->a['headers'];

        $id = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $this->partyId,
            'items' => [['product_id' => $this->productId, 'quantity' => 4, 'unit_price' => 100]],
        ], $h)->assertCreated()->json('document.id');

        $this->apiPost("/api/v1/documents/{$id}/status", ['status' => 'issued'], $h)->assertOk();

        return $id;
    }

    public function test_iptal_stogu_ayna_ize_dondurur(): void
    {
        $h = $this->a['headers'];
        $this->assertSame(10.0, $this->stok());

        $id = $this->faturaKes();
        $this->assertSame(6.0, $this->stok());

        $this->apiPost("/api/v1/documents/{$id}/status", ['status' => 'cancelled'], $h)->assertOk();

        $this->assertSame(10.0, $this->stok());
        $this->assertSame(1, StockMovement::where('reference', 'cancel:'.$id)->count());
        $this->assertSame('cancelled', Document::find($id)->status);
    }

    public function test_kesilmis_fatura_dogrudan_silinir_stok_doner(): void
    {
        $h = $this->a['headers'];
        $id = $this->faturaKes();
        $this->assertSame(6.0, $this->stok());

        $this->apiDelete("/api/v1/documents/{$id}", [], $h)->assertOk();

        $this->assertNull(Document::find($id));
        $this->assertSame(10.0, $this->stok());
        $this->assertSame(1, StockMovement::where('reference', 'cancel:'.$id)->count());
    }

    public function test_odemeli_fatura_silinir_odeme_denetime_duser(): void
    {
        $h = $this->a['headers'];
        $id = $this->faturaKes();

        $odeme = $this->apiPost('/api/v1/payments', [
            'document_id' => $id,
            'party_id' => $this->partyId,
            'direction' => 'in',
            'amount' => 100,
            'method' => 'cash',
        ], $h)->assertCreated()->json('payment.id');

        $this->apiDelete("/api/v1/documents/{$id}", [], $h)->assertOk();

        $this->assertNull(Document::find($id));
        $this->assertNull(Payment::find($odeme));
        $this->assertSame(10.0, $this->stok());

        $iz = \App\Models\AuditEvent::where('action', 'document.deleted')->latest('id')->value('meta');
        $this->assertSame($odeme, $iz['cascade']['odemeler'][0]['id']);
    }

    public function test_donusturen_silinince_cocuk_bagi_kopar_cocuk_durur(): void
    {
        $h = $this->a['headers'];

        $teklif = $this->apiPost('/api/v1/documents', [
            'type' => 'quote',
            'party_id' => $this->partyId,
            'items' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 50]],
        ], $h)->assertCreated()->json('document.id');

        $siparis = $this->apiPost("/api/v1/documents/{$teklif}/convert", ['to' => 'sales_order'], $h)
            ->assertCreated()->json('document.id');

        $this->apiDelete("/api/v1/documents/{$teklif}", [], $h)->assertOk();

        $this->assertNull(Document::find($teklif));
        $this->assertNull(Document::find($siparis)->converted_from_id);
    }

    public function test_iptal_edilmis_belge_duzenlenebilir_kesilmis_duzenlenemez(): void
    {
        $h = $this->a['headers'];
        $id = $this->faturaKes();

        $this->apiPatch("/api/v1/documents/{$id}", ['notes' => 'degismez'], $h)->assertStatus(422);

        $this->apiPost("/api/v1/documents/{$id}/status", ['status' => 'cancelled'], $h)->assertOk();

        $this->apiPatch("/api/v1/documents/{$id}", ['notes' => 'iptal sonrasi not'], $h)
            ->assertOk()
            ->assertJsonPath('document.notes', 'iptal sonrasi not');
    }

    public function test_belge_kopyalama_yeni_taslak_acar(): void
    {
        $h = $this->a['headers'];
        $id = $this->faturaKes();

        $kopya = $this->apiPost("/api/v1/documents/{$id}/duplicate", [], $h)
            ->assertCreated()
            ->json('document');

        $this->assertSame('draft', $kopya['status']);
        $this->assertNotSame($id, $kopya['id']);
        $this->assertCount(1, $this->apiGet("/api/v1/documents/{$kopya['id']}", $h)->json('items'));
        // Kopyalama stok oynatmaz (taslak açılır).
        $this->assertSame(6.0, $this->stok());
    }
}
