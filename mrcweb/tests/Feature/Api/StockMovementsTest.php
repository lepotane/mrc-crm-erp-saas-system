<?php

namespace Tests\Feature\Api;

use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementsTest extends TestCase
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

    protected function tenantCompanyId(array $registration): int
    {
        return (int) $registration['response']->json('company.id');
    }

    /**
     * Kayıt akışında varsayılan depo + 'Adet' birimi hazır gelir;
     * ürün + varsayılan depo kimliği döner.
     */
    protected function preparedProduct(array $registration): array
    {
        $headers = $this->headersFrom($registration);
        $productId = $this->apiPost('/api/v1/products', ['name' => 'Test Ürün'], $headers)->assertCreated()->json('product.id');
        $warehouseId = (int) Warehouse::where('company_id', $this->tenantCompanyId($registration))
            ->where('is_default', true)->value('id');

        return ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'headers' => $headers];
    }

    public function test_giris_hareketi_stogu_yukseltir(): void
    {
        $owner = $this->registerUser();
        $p = $this->preparedProduct($owner);

        $result = $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $p['product_id'],
            'warehouse_id' => $p['warehouse_id'],
            'type' => 'in',
            'quantity' => 10,
            'unit_cost' => 25.5,
            'note' => 'Açılış girişi',
        ], $p['headers'])->assertCreated()->json();

        $this->assertSame(10.0, (float) $result['stock_quantity']);
        $this->assertSame(25.5, (float) $result['movement']['unit_cost']);
        $this->assertSame('in', $result['movement']['type']);
    }

    public function test_giris_cikis_ve_duzeltme_stogu_birlikte_hesaplar(): void
    {
        $owner = $this->registerUser();
        $p = $this->preparedProduct($owner);
        $base = '/api/v1/stock-movements';
        $payload = ['product_id' => $p['product_id'], 'warehouse_id' => $p['warehouse_id']];

        $this->apiPost($base, $payload + ['type' => 'in', 'quantity' => 10], $p['headers'])->assertCreated();
        $this->apiPost($base, $payload + ['type' => 'out', 'quantity' => 4], $p['headers'])->assertCreated();

        // 10 - 4 - 1 = 5
        $adjust = $this->apiPost($base, $payload + ['type' => 'adjust', 'quantity' => -1, 'note' => 'Kırık ürün'], $p['headers'])
            ->assertCreated()->json('stock_quantity');
        $this->assertSame(5.0, (float) $adjust);

        // v1'de negatif stok engellenmez 
        $this->apiPost($base, $payload + ['type' => 'out', 'quantity' => 6], $p['headers'])->assertCreated();
        $finalStock = $this->apiGet("/api/v1/products/{$p['product_id']}", $p['headers'])->assertOk()->json('product.stock_quantity');
        $this->assertSame(-1.0, (float) $finalStock);
    }

    public function test_gecersiz_miktarlar_ve_firma_disi_depo_reddedilir(): void
    {
        $owner = $this->registerUser();
        $p = $this->preparedProduct($owner);
        $base = '/api/v1/stock-movements';

        // out pozitif olmak zorunda
        $this->apiPost($base, ['product_id' => $p['product_id'], 'warehouse_id' => $p['warehouse_id'],
            'type' => 'out', 'quantity' => -5], $p['headers'])->assertStatus(422);

        // adjust sıfır olamaz
        $this->apiPost($base, ['product_id' => $p['product_id'], 'warehouse_id' => $p['warehouse_id'],
            'type' => 'adjust', 'quantity' => 0], $p['headers'])->assertStatus(422);

        // iş kuralı 422'si standart errors.quantity formatındadır (SPA alan hatası okur)
        $this->apiPost($base, ['product_id' => $p['product_id'], 'warehouse_id' => $p['warehouse_id'],
            'type' => 'out', 'quantity' => 0], $p['headers'])
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Giriş/çıkış miktarı sıfırdan büyük olmalıdır.');

        // diğer firmanın deposuyla hareket reddedilir (422 — doğrulama)
        $other = $this->registerUser(['email' => 'sahip-b@firma-b.test', 'company_name' => 'B']);
        $otherWarehouseId = (int) Warehouse::where('company_id', $this->tenantCompanyId($other))->value('id');
        $this->assertNotSame($otherWarehouseId, $p['warehouse_id']);

        $this->apiPost($base, ['product_id' => $p['product_id'], 'warehouse_id' => $otherWarehouseId,
            'type' => 'in', 'quantity' => 1], $p['headers'])->assertStatus(422);
    }

    public function test_hareket_listesi_filtre_alir_ve_tenant_izolasyonlu(): void
    {
        $owner = $this->registerUser();
        $p = $this->preparedProduct($owner);
        $base = '/api/v1/stock-movements';
        $payload = ['product_id' => $p['product_id'], 'warehouse_id' => $p['warehouse_id']];

        $this->apiPost($base, $payload + ['type' => 'in', 'quantity' => 8], $p['headers'])->assertCreated();
        $this->apiPost($base, $payload + ['type' => 'out', 'quantity' => 2], $p['headers'])->assertCreated();

        // tip filtresi
        $ins = $this->apiGet($base.'?type=in', $p['headers'])->assertOk()->json('data');
        $this->assertCount(1, $ins);
        $this->assertSame(8.0, (float) $ins[0]['quantity']);

        // ürün adı listede taşınır (en yeni hareket üstte)
        $all = $this->apiGet($base, $p['headers'])->assertOk()->json('data');
        $this->assertCount(2, $all);
        $this->assertSame('Test Ürün', $all[0]['product_name']);

        // diğer firma kendi hareketlerini görür, A'nınkileri göremez
        $other = $this->registerUser(['email' => 'sahip-b@firma-b.test', 'company_name' => 'B']);
        $pB = $this->preparedProduct($other);
        $this->apiPost($base, ['product_id' => $pB['product_id'], 'warehouse_id' => $pB['warehouse_id'],
            'type' => 'in', 'quantity' => 5], $pB['headers'])->assertCreated();

        $listB = $this->apiGet($base, $pB['headers'])->assertOk()->json('data');
        $this->assertCount(1, $listB);
        $this->assertSame(5.0, (float) $listB[0]['quantity']);
        $this->assertSame(1, $this->apiGet($base, $pB['headers'])->assertOk()->json('meta.total'));
    }

    public function test_depo_hareketliyken_silinemez_bos_depo_silinir(): void
    {
        $owner = $this->registerUser();
        $p = $this->preparedProduct($owner);
        $warehouseId = $p['warehouse_id'];

        $this->apiPost('/api/v1/stock-movements', ['product_id' => $p['product_id'], 'warehouse_id' => $warehouseId,
            'type' => 'in', 'quantity' => 3], $p['headers'])->assertCreated();

        // hareketi olan depo silinemez
        $this->apiDelete("/api/v1/warehouses/{$warehouseId}", [], $p['headers'])->assertStatus(422);

        // boş depo silinebilir
        $empty = (int) $this->apiPost('/api/v1/warehouses', ['name' => 'Boş Depo'], $p['headers'])->assertCreated()->json('warehouse.id');
        $this->apiDelete("/api/v1/warehouses/{$empty}", [], $p['headers'])->assertOk();
    }
}
