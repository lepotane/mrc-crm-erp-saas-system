<?php

namespace Tests\Feature\Api;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * ÇOK DİLLİ BELGE .
 *
 * Kilit: ürün/birim İngilizce adı girilmişse EN çıktıda o görünür,
 * girilmemişse sessizce Türkçe (boş satır yok); `?lang=` yalnız tr|en
 * kabul eder; irsaliye fiyatsız miktar listesidir (ayrı tip yok).
 */
class MultilingualDocumentTest extends TestCase
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

        $this->a = $this->register('cokdilli@firma.test', 'Çokdilli Firma');
        $h = $this->a['headers'];

        $this->partyId = $this->apiPost('/api/v1/parties', ['name' => 'Çokdilli Cari', 'type' => 'customer'], $h)
            ->assertCreated()->json('party.id');

        $this->productId = $this->apiPost('/api/v1/products', [
            'name' => 'Sinek İlacı',
            'name_en' => 'Fly Spray',
            'sale_price' => 100,
        ], $h)->assertCreated()->json('product.id');

        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->defaultWarehouseId($this->a),
            'type' => 'in',
            'quantity' => 10,
            'unit_cost' => 10,
        ], $h)->assertCreated();
    }

    protected function faturaKes(): int
    {
        $h = $this->a['headers'];

        $id = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $this->partyId,
            'items' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 100]],
        ], $h)->assertCreated()->json('document.id');

        $this->apiPost("/api/v1/documents/{$id}/status", ['status' => 'issued'], $h)->assertOk();

        return $id;
    }

    public function test_ingilizce_ciktida_urun_adi_en_gorunur(): void
    {
        $id = $this->faturaKes();
        $doc = Document::find($id);

        app()->setLocale('en');
        $payload = (new \App\Http\Controllers\Api\V1\DocumentPdfController)->payload($doc);
        app()->setLocale('tr');

        $this->assertSame('Fly Spray', $payload['document']->items->first()->getAttribute('display_name'));
    }

    public function test_ingilizcesi_yoksa_turkceye_duser(): void
    {
        $h = $this->a['headers'];

        $urnId = $this->apiPost('/api/v1/products', ['name' => 'Yalnız Türkçe', 'sale_price' => 10], $h)
            ->assertCreated()->json('product.id');

        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $urnId,
            'warehouse_id' => $this->defaultWarehouseId($this->a),
            'type' => 'in',
            'quantity' => 5,
            'unit_cost' => 5,
        ], $h)->assertCreated();

        $id = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $this->partyId,
            'items' => [['product_id' => $urnId, 'quantity' => 1, 'unit_price' => 10]],
        ], $h)->assertCreated()->json('document.id');
        $this->apiPost("/api/v1/documents/{$id}/status", ['status' => 'issued'], $h)->assertOk();

        app()->setLocale('en');
        $payload = (new \App\Http\Controllers\Api\V1\DocumentPdfController)->payload(Document::find($id));
        app()->setLocale('tr');

        $this->assertSame('Yalnız Türkçe', $payload['document']->items->first()->getAttribute('display_name'));
    }

    public function test_irsaliye_fiyatsiz_miktar_listesidir(): void
    {
        $h = $this->a['headers'];
        $id = $this->faturaKes();

        $res = $this->apiGet("/api/v1/documents/{$id}/delivery-note?lang=en", $h)->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_irsaliye_taslak_ve_gecersiz_dilde_reddedilir(): void
    {
        $h = $this->a['headers'];

        $taslak = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $this->partyId,
            'items' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 100]],
        ], $h)->assertCreated()->json('document.id');

        $this->apiGet("/api/v1/documents/{$taslak}/delivery-note", $h)->assertNotFound();

        $id = $this->faturaKes();
        $this->apiGet("/api/v1/documents/{$id}/delivery-note?lang=de", $h)->assertStatus(422);
    }

    public function test_birim_ingilizce_adi_kaydedilir(): void
    {
        $h = $this->a['headers'];

        $res = $this->apiPost('/api/v1/units', ['name' => 'Adet', 'name_en' => 'Pcs', 'code' => 'ADT'], $h)
            ->assertCreated();

        $this->assertSame('Pcs', $res->json('unit.name_en'));
    }
}
