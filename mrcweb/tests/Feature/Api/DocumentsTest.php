<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function registerUser(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Sahip Kullanıcı',
            'email' => 'sahip@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Firma A',
        ], $overrides);

        $response = $this->apiPost('/api/v1/auth/register', $payload);

        return ['response' => $response, 'token' => $response->json('token')];
    }

    protected function headersFrom(array $registration): array
    {
        return ['Authorization' => "Bearer {$registration['token']}"];
    }

    protected function createParty(array $headers, string $name = 'Müşteri Ltd'): int
    {
        return $this->apiPost('/api/v1/parties', ['name' => $name], $headers)
            ->assertCreated()->json('party.id');
    }

    protected function createProduct(array $headers, array $overrides = []): array
    {
        return $this->apiPost('/api/v1/products', array_merge([
            'name' => 'Test Ürünü',
            'cost_price' => 50,
            'sale_price' => 100,
            'tax_rate' => 20,
        ], $overrides), $headers)->assertCreated()->json('product');
    }

    protected function docPayload(int $partyId, array $items, array $overrides = []): array
    {
        return array_merge([
            'type' => 'quote',
            'party_id' => $partyId,
            'items' => $items,
        ], $overrides);
    }

    public function test_teklif_duzenli_olusturulur_ve_toplamlar_dogrulanir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);
        $product = $this->createProduct($headers);

        // 1. satır ürün bağlamalı (%10 indirim, ürün KDV'si %20), 2. satır serbest metin (%10 KDV)
        $created = $this->apiPost('/api/v1/documents', $this->docPayload($partyId, [
            ['product_id' => $product['id'], 'name' => 'EZİLECEK AD', 'quantity' => 2, 'unit_price' => 100, 'discount_rate' => 10],
            ['name' => 'Hizmet Bedeli', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 10],
        ]), $headers)->assertCreated();

        $document = $created->json('document');
        $items = $created->json('items');

        // ürün adı istemci adını ezer; KDV ürünün güncel değerinden gelir
        $this->assertSame('Test Ürünü', $items[0]['name']);
        $this->assertSame(20.0, (float) $items[0]['tax_rate']);

        // satır 1: 200 − 20 + 36 = 216 ; satır 2: 50 − 0 + 5 = 55
        $this->assertSame(200.0, round((float) $items[0]['line_subtotal'], 4));
        $this->assertSame(20.0, round((float) $items[0]['line_discount'], 4));
        $this->assertSame(36.0, round((float) $items[0]['line_tax'], 4));
        $this->assertSame(216.0, round((float) $items[0]['line_total'], 4));
        $this->assertSame(55.0, round((float) $items[1]['line_total'], 4));

        $this->assertSame(250.0, round((float) $document['subtotal'], 4));
        $this->assertSame(20.0, round((float) $document['discount_total'], 4));
        $this->assertSame(41.0, round((float) $document['tax_total'], 4));
        $this->assertSame(271.0, round((float) $document['grand_total'], 4));
        $this->assertSame('draft', $document['status']);

        $yy = date('y');
        $this->assertSame("TEK-{$yy}-00001", $document['serial']);

        // ikinci belge seri sayacını artırır
        $second = $this->apiPost('/api/v1/documents', $this->docPayload($partyId, [
            ['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10],
        ]), $headers)->assertCreated()->json('document');
        $this->assertSame("TEK-{$yy}-00002", $second['serial']);
    }

    public function test_belge_tenant_izolasyonu(): void
    {
        $a = $this->registerUser(['company_name' => 'A']);
        $headersA = $this->headersFrom($a);
        $partyA = $this->createParty($headersA);
        $docId = $this->apiPost('/api/v1/documents', $this->docPayload($partyA, [
            ['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10],
        ]), $headersA)->assertCreated()->json('document.id');

        $b = $this->registerUser(['email' => 'sahip-b@firma-b.test', 'company_name' => 'B']);
        $headersB = $this->headersFrom($b);

        $this->apiGet("/api/v1/documents/{$docId}", $headersB)->assertNotFound();
        $this->apiPatch("/api/v1/documents/{$docId}", ['notes' => 'x'], $headersB)->assertNotFound();
        $this->apiDelete("/api/v1/documents/{$docId}", [], $headersB)->assertNotFound();
        $this->assertSame(0, $this->apiGet('/api/v1/documents', $headersB)->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->apiGet('/api/v1/documents', $headersA)->assertOk()->json('meta.total'));
    }

    public function test_durum_makinesi_gecersiz_gecisleri_reddeder(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);

        $invoiceId = $this->apiPost('/api/v1/documents', $this->docPayload($partyId, [
            ['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10],
        ], ['type' => 'invoice']), $headers)->assertCreated()->json('document.id');

        // draft → issued geçerli
        $this->apiPost("/api/v1/documents/{$invoiceId}/status", ['status' => 'issued'], $headers)
            ->assertOk()
            ->assertJsonPath('document.status', 'issued')
            ->assertJsonPath('stock_applied', false);

        // draft → paid geçersiz (ayrı taslak belgede)
        $draftId = $this->apiPost('/api/v1/documents', $this->docPayload($partyId, [
            ['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10],
        ], ['type' => 'invoice']), $headers)->assertCreated()->json('document.id');

        $this->apiPost("/api/v1/documents/{$draftId}/status", ['status' => 'paid'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // draft olmayan belgede PATCH yasak; DELETE her durumda çalışır
        $this->apiPatch("/api/v1/documents/{$invoiceId}", ['notes' => 'değişiklik'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
        $this->apiDelete("/api/v1/documents/{$invoiceId}", [], $headers)->assertOk();
        $this->apiGet("/api/v1/documents/{$invoiceId}", $headers)->assertNotFound();
    }

    public function test_issue_gecisi_stok_cogs_ve_maliyet_gunceller(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);

        // satış: maliyet 20, stok 10 → 5 adet satış sonrası stok 5, cogs 20
        $product = $this->createProduct($headers, ['cost_price' => 20]);
        $warehouseId = $this->apiGet('/api/v1/warehouses', $headers)->assertOk()->json('warehouses.0.id');
        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $product['id'], 'warehouse_id' => $warehouseId, 'type' => 'in', 'quantity' => 10,
        ], $headers)->assertCreated();

        $invoiceId = $this->apiPost('/api/v1/documents', $this->docPayload($partyId, [
            ['product_id' => $product['id'], 'quantity' => 5, 'unit_price' => 100],
        ], ['type' => 'invoice']), $headers)->assertCreated()->json('document.id');

        $this->apiPost("/api/v1/documents/{$invoiceId}/status", ['status' => 'issued'], $headers)
            ->assertOk()
            ->assertJsonPath('stock_applied', true);

        $afterSale = $this->apiGet("/api/v1/products/{$product['id']}", $headers)->assertOk()->json('product');
        $this->assertSame(5.0, round((float) $afterSale['stock_quantity'], 4));

        $soldItems = $this->apiGet("/api/v1/documents/{$invoiceId}", $headers)->assertOk()->json('items');
        $this->assertSame(20.0, round((float) $soldItems[0]['cogs'], 4));

        // alım: 10 adet @20 varken 10 adet @30 alınır → stok 20, maliyet 25
        $product2 = $this->createProduct($headers, ['name' => 'Alım Ürünü', 'cost_price' => 20]);
        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $product2['id'], 'warehouse_id' => $warehouseId, 'type' => 'in', 'quantity' => 10,
        ], $headers)->assertCreated();

        $purchaseId = $this->apiPost('/api/v1/documents', $this->docPayload($partyId, [
            ['product_id' => $product2['id'], 'quantity' => 10, 'unit_price' => 30],
        ], ['type' => 'purchase']), $headers)->assertCreated()->json('document.id');

        $this->apiPost("/api/v1/documents/{$purchaseId}/status", ['status' => 'issued'], $headers)->assertOk();

        $afterPurchase = $this->apiGet("/api/v1/products/{$product2['id']}", $headers)->assertOk()->json('product');
        $this->assertSame(20.0, round((float) $afterPurchase['stock_quantity'], 4));
        $this->assertSame(25.0, round((float) $afterPurchase['cost_price'], 4));
    }

    public function test_donusum_zinciri_korunur(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);

        $quote = $this->apiPost('/api/v1/documents', $this->docPayload($partyId, [
            ['name' => 'Kurulum', 'quantity' => 2, 'unit_price' => 100, 'discount_rate' => 10],
            ['name' => 'Bakım', 'quantity' => 1, 'unit_price' => 50],
        ]), $headers)->assertCreated();
        $quoteId = $quote->json('document.id');

        // quote → sales_order geçerli
        $order = $this->apiPost("/api/v1/documents/{$quoteId}/convert", ['to' => 'sales_order'], $headers)
            ->assertCreated();
        $this->assertSame($quoteId, $order->json('document.converted_from_id'));
        $this->assertSame('sales_order', $order->json('document.type'));
        $this->assertSame('draft', $order->json('document.status'));
        $this->assertCount(2, $order->json('items'));
        $this->assertSame(
            round((float) $quote->json('document.grand_total'), 4),
            round((float) $order->json('document.grand_total'), 4)
        );
        $yy = date('y');
        $this->assertSame("SIP-{$yy}-00001", $order->json('document.serial'));

        // quote → invoice doğrudan yasak
        $this->apiPost("/api/v1/documents/{$quoteId}/convert", ['to' => 'invoice'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_satis_cikis_hareketinin_birim_maliyeti_satis_fiyati_degil_maliyettir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);
        $warehouseId = $this->apiGet('/api/v1/warehouses', $headers)->assertOk()->json('warehouses.0.id');

        $product = $this->createProduct($headers, ['name' => 'Maliyet Ürünü', 'cost_price' => 20, 'sale_price' => 100]);
        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $product['id'], 'warehouse_id' => $warehouseId, 'type' => 'in',
            'quantity' => 10, 'unit_cost' => 20,
        ], $headers)->assertCreated();

        $invoiceId = $this->apiPost('/api/v1/documents', $this->docPayload($partyId, [
            ['product_id' => $product['id'], 'quantity' => 4, 'unit_price' => 100, 'tax_rate' => 0],
        ], ['type' => 'invoice']), $headers)->assertCreated()->json('document.id');
        $this->apiPost("/api/v1/documents/{$invoiceId}/status", ['status' => 'issued'], $headers)->assertOk();

        $moves = $this->apiGet("/api/v1/stock-movements?product_id={$product['id']}&type=out", $headers)
            ->assertOk()->json('data');
        $this->assertCount(1, $moves);
        // hareketin maliyeti = ortalama maliyet (20), satış fiyatı (100) değil
        $this->assertSame(20.0, round((float) $moves[0]['unit_cost'], 4));

        // stok hareketi özeti: çıkış değeri maliyet üzerinden (4 × 20 = 80)
        $summary = $this->apiGet('/api/v1/reports/stock-movements-summary?group_by=type', $headers)->assertOk();
        $byKey = [];
        foreach ($summary->json('data') as $row) {
            $byKey[$row['key']] = $row;
        }
        $this->assertSame(80.0, round((float) $byKey['out']['out_value'], 4));
        $this->assertSame(200.0, round((float) $byKey['in']['in_value'], 4));
    }

    public function test_negatif_satir_miktari_reddedilir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);

        $response = $this->apiPost('/api/v1/documents', $this->docPayload($partyId, [
            ['name' => 'İade Kalemi', 'quantity' => -5, 'unit_price' => 100],
        ], ['type' => 'purchase']), $headers)->assertStatus(422);

        // iş kuralı hatası düz alan anahtarıyla gelir: errors → "items.0.quantity"
        $errors = $response->json('errors');
        $this->assertSame('Satır miktarı negatif olamaz.', $errors['items.0.quantity'][0] ?? null);
    }
}
