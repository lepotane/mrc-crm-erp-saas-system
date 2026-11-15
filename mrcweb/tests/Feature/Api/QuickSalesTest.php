<?php

namespace Tests\Feature\Api;

use App\Models\Document;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * HIZLI SATIŞ — POS .
 *
 * Kilitlenen sözleşme: tek çağrıda fatura (issued) + stok çıkışı + tam tahsilat
 * (belge paid) + para üstü; fiş PDF'i satır toplamlarını taşır. Kuralların hiçbiri
 * esnetilmez: satır matematiği, stok/maliyet ve tahsilat normal akışlarla aynıdır.
 */
class QuickSalesTest extends TestCase
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

        $this->a = $this->register('pos@firma.test', 'Pos Firmasi');
        $h = $this->a['headers'];

        $this->partyId = $this->apiPost('/api/v1/parties', ['name' => 'Perakende Müşteri', 'type' => 'customer'], $h)
            ->assertCreated()->json('party.id');

        $this->productId = $this->apiPost('/api/v1/products', ['name' => 'POS Ürünü', 'sale_price' => 100], $h)
            ->assertCreated()->json('product.id');

        $this->warehouseId = $this->defaultWarehouseId($this->a);

        // Açılış stoğu: 10 adet, maliyet 40.
        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'type' => 'in',
            'quantity' => 10,
            'unit_cost' => 40,
        ], $h)->assertCreated();
    }

    /** @return array<string,mixed> */
    protected function satis(array $ek = []): array
    {
        return array_merge([
            'party_id' => $this->partyId,
            'items' => [['product_id' => $this->productId, 'quantity' => 2, 'unit_price' => 100]],
        ], $ek);
    }

    public function test_hizli_satis_fatura_stok_tahsilat_ve_para_ustu_uretir(): void
    {
        $res = $this->apiPost('/api/v1/quick-sales', $this->satis([
            'payment_method' => 'cash',
            'amount_tendered' => 250,
        ]), $this->a['headers'])->assertCreated();

        $sale = $res->json('sale');
        $this->assertSame('paid', $sale['document']['status']);
        $this->assertSame('paid', $sale['payment']['document_status']);
        $this->assertSame('cash', $sale['payment']['method']);
        $this->assertSame(10.0, (float) $res->json('change'));

        // Satırlar: 2x100 + %20 KDV (ürün KDV'si yoksa varsayılan) → toplam tutarlılığı.
        $doc = $sale['document'];
        $this->assertEqualsWithDelta(
            (float) $doc['subtotal'] - (float) $doc['discount_total'] + (float) $doc['tax_total'],
            (float) $doc['grand_total'],
            0.01
        );

        // Stok düştü: 10 → 8 (ürün kartındaki hesaplanan stok).
        $stok = $this->apiGet("/api/v1/products/{$this->productId}", $this->a['headers'])
            ->assertOk()->json('product.stock_quantity');
        $this->assertSame(8.0, (float) $stok);

        // Tahsilat belgeye bağlı, tam tutar.
        $odeme = Payment::where('document_id', $doc['id'])->first();
        $this->assertNotNull($odeme);
        $this->assertSame($doc['grand_total'], (string) $odeme->amount);
        $this->assertSame('in', $odeme->direction);

        // Fiş URL'si belgeye işaret eder.
        $this->assertSame("/api/v1/quick-sales/{$doc['id']}/receipt.pdf", $res->json('receipt_pdf_url'));
    }

    public function test_alinan_tutar_yetersizse_satis_olmaz(): void
    {
        $this->apiPost('/api/v1/quick-sales', $this->satis(['amount_tendered' => 10]), $this->a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('amount_tendered');

        // Yarı satış kalmadı: belge de tahsilat da yok.
        $this->assertSame(0, Document::where('company_id', $this->a['company_id'])->count());
        $this->assertSame(0, Payment::where('company_id', $this->a['company_id'])->count());
    }

    public function test_fis_pdf_boyutlara_gore_uretilir(): void
    {
        $res = $this->apiPost('/api/v1/quick-sales', $this->satis(), $this->a['headers'])->assertCreated();
        $id = $res->json('sale.document.id');

        foreach (['80', 'a5'] as $boyut) {
            $pdf = $this->apiGet("/api/v1/quick-sales/{$id}/receipt.pdf?size={$boyut}&inline=1", $this->a['headers'])
                ->assertOk();
            $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
        }

        $this->apiGet("/api/v1/quick-sales/{$id}/receipt.pdf?size=99", $this->a['headers'])->assertStatus(422);
    }

    public function test_baska_firmanin_fisi_gorunmez(): void
    {
        $res = $this->apiPost('/api/v1/quick-sales', $this->satis(), $this->a['headers'])->assertCreated();
        $id = $res->json('sale.document.id');

        $b = $this->register('pos-b@firma.test', 'Pos B Firmasi');
        $this->apiGet("/api/v1/quick-sales/{$id}/receipt.pdf", $b['headers'])->assertNotFound();
    }

    public function test_pos_modulu_kapalinca_uc_kapanir(): void
    {
        $this->apiPatch('/api/v1/modules/pos', ['enabled' => false], $this->a['headers'])->assertOk();

        $this->apiPost('/api/v1/quick-sales', $this->satis(), $this->a['headers'])
            ->assertForbidden()->assertJsonPath('code', 'module_disabled');
    }

    public function test_odeme_yontemi_ve_hesap_baglanir(): void
    {
        $hesapId = $this->apiPost('/api/v1/bank-accounts', [
            'name' => 'POS Kasası', 'type' => 'cash',
        ], $this->a['headers'])->assertCreated()->json('bank_account.id');

        $res = $this->apiPost('/api/v1/quick-sales', $this->satis([
            'payment_method' => 'card',
            'bank_account_id' => $hesapId,
        ]), $this->a['headers'])->assertCreated();

        $this->assertSame('card', $res->json('sale.payment.method'));
        $this->assertSame(0.0, (float) $res->json('change'));

        $odeme = Payment::where('document_id', $res->json('sale.document.id'))->first();
        $this->assertSame($hesapId, (int) $odeme->bank_account_id);
    }

    public function test_pos_fiyat_sinifi_kaleme_islenir(): void
    {
        $res = $this->apiPost('/api/v1/quick-sales', $this->satis([
            'items' => [[
                'product_id' => $this->productId,
                'quantity' => 1,
                'unit_price' => 100,
                'price_currency_code' => 'USD',
            ]],
        ]), $this->a['headers'])->assertCreated();

        $kalem = Document::findOrFail($res->json('sale.document.id'))->items()->first();
        $this->assertSame('USD', $kalem->price_currency_code);

        // Geçersiz sınıf kodu 422 verir (sessizce yutulmaz).
        $this->apiPost('/api/v1/quick-sales', $this->satis([
            'items' => [[
                'product_id' => $this->productId,
                'quantity' => 1,
                'unit_price' => 100,
                'price_currency_code' => 'XXX',
            ]],
        ]), $this->a['headers'])->assertUnprocessable();
    }
}
