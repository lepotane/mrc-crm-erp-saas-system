<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductsTest extends TestCase
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

    protected function createCategory(array $headers): int
    {
        return $this->apiPost('/api/v1/categories', ['name' => 'Kategori X', 'color' => '#BC5FCF'], $headers)
            ->assertCreated()->json('category.id');
    }

    public function test_urun_olusturulurken_kategori_ve_birim_baglanir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $categoryId = $this->createCategory($headers);
        $unitId = $this->apiPost('/api/v1/units', ['name' => 'Kilogram', 'code' => 'kg'], $headers)
            ->assertCreated()->json('unit.id');

        $product = $this->apiPost('/api/v1/products', [
            'name' => 'PEST CONTROL DUMAN 30g',
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'PC-30',
            'barcode' => '8690123456784',
            'cost_price' => 40, 'sale_price' => 75, 'tax_rate' => 10,
            'min_stock' => 5,
        ], $headers)->assertCreated()->json('product');

        $this->assertSame('PC-30', $product['sku']);
        $this->assertSame(75.0, (float) $product['sale_price']);
        $this->assertSame('Kategori X', $product['category']['name']);
        $this->assertSame('kg', $product['unit']['code']);
        $this->assertSame(10.0, (float) $product['tax_rate']);

        // izlenmeyen stok başlangıcı 0'dır (henüz hareket yok)
        $this->assertSame(0.0, (float) $product['stock_quantity']);
    }

    public function test_urun_arama_ve_tenant_izolasyonu(): void
    {
        $a = $this->registerUser(['company_name' => 'A']);
        $headersA = $this->headersFrom($a);
        $this->apiPost('/api/v1/products', ['name' => 'ARMUT JEL 500ml', 'barcode' => '100'], $headersA)->assertCreated();

        $b = $this->registerUser(['email' => 'sahip-b@firma-b.test', 'company_name' => 'B']);
        $headersB = $this->headersFrom($b);

        // B'nin listesi boş; 'JEL' araması A'daki ürünü getirmemeli
        $listB = $this->apiGet('/api/v1/products?search=JEL', $headersB)->assertOk()->json('data');
        $this->assertSame([], $listB);

        // A aramasından ürün alır
        $listA = $this->apiGet('/api/v1/products?search=JEL', $headersA)->assertOk()->json('data');
        $this->assertCount(1, $listA);
        $this->assertSame(1, $this->apiGet('/api/v1/products', $headersA)->assertOk()->json('meta.total'));

        // barkod eşleşmesi birebir çalışır
        $byBarcode = $this->apiGet('/api/v1/products?barcode=100', $headersA)->assertOk()->json('data');
        $this->assertCount(1, $byBarcode);
        $this->assertSame('ARMUT JEL 500ml', $byBarcode[0]['name']);
    }

    public function test_kategori_adi_firma_bazli_tektir(): void
    {
        $a = $this->registerUser(['company_name' => 'A']);
        $headersA = $this->headersFrom($a);
        $b = $this->registerUser(['email' => 'sahip-b@firma-b.test', 'company_name' => 'B']);
        $headersB = $this->headersFrom($b);

        $this->apiPost('/api/v1/categories', ['name' => 'Gıda'], $headersA)->assertCreated();

        // B firmasında aynı isim geçerli (firma kapsamlı unique)
        $this->apiPost('/api/v1/categories', ['name' => 'Gıda'], $headersB)->assertCreated();

        // A firmasında aynı isim reddedilir
        $this->apiPost('/api/v1/categories', ['name' => 'Gıda'], $headersA)->assertStatus(422);
    }

    public function test_urun_guncelleme_fiyat_ve_kdv_guncellenir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $productId = $this->apiPost('/api/v1/products', ['name' => 'Dock Kelezi'], $headers)->assertCreated()->json('product.id');

        $this->apiPatch("/api/v1/products/{$productId}", ['sale_price' => 100, 'tax_rate' => 20], $headers)
            ->assertOk()
            ->assertJsonPath('product.sale_price', '100.0000')
            ->assertJsonPath('product.tax_rate', '20.00');
    }

    public function test_hareket_goren_urun_silinemez_kritik_filtresi_calisir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $warehouseId = $this->apiGet('/api/v1/warehouses', $headers)->assertOk()->json('warehouses.0.id');

        $kritikId = $this->apiPost('/api/v1/products', ['name' => 'Kritik Ürün', 'min_stock' => 5], $headers)
            ->assertCreated()->json('product.id');
        $saglamId = $this->apiPost('/api/v1/products', ['name' => 'Sağlam Ürün', 'min_stock' => 5], $headers)
            ->assertCreated()->json('product.id');

        // Kritik ürüne 2 adet giriş (min_stock 5'in altında kalır)
        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $kritikId, 'warehouse_id' => $warehouseId, 'type' => 'in', 'quantity' => 2,
        ], $headers)->assertCreated();
        // Sağlam ürüne 10 adet giriş
        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $saglamId, 'warehouse_id' => $warehouseId, 'type' => 'in', 'quantity' => 10,
        ], $headers)->assertCreated();

        // critical=1 yalnız kritik ürünü döndürür
        $kritikler = $this->apiGet('/api/v1/products?critical=1', $headers)->assertOk();
        $this->assertSame(1, $kritikler->json('meta.total'));
        $this->assertSame('Kritik Ürün', $kritikler->json('data.0.name'));

        // hareket gören ürün silinemez (422)
        $this->apiDelete("/api/v1/products/{$kritikId}", [], $headers)->assertStatus(422);

        // hareketsiz ürün silinebilir
        $bosId = $this->apiPost('/api/v1/products', ['name' => 'Boş Ürün'], $headers)
            ->assertCreated()->json('product.id');
        $this->apiDelete("/api/v1/products/{$bosId}", [], $headers)->assertOk();
    }
}
