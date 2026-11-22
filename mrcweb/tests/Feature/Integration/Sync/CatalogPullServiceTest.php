<?php

namespace Tests\Feature\Integration\Sync;

use App\Integration\Enums\Channel;
use App\Integration\Enums\Driver;
use App\Integration\Support\CredentialVault;
use App\Integration\Sync\CatalogPullService;
use App\Models\Party;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * Zamanlanmis katalog cekimi Tsoft'tan YALNIZCA okur; urun/fiyat/stok/musteri
 * MRC tarafina yazilir. Hicbir setProducts / yazma ucu cagrilmaz.
 */
final class CatalogPullServiceTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_katalog_cekilir_ve_tsofta_yazma_yapilmaz(): void
    {
        $ctx = $this->register('katalog-cek@firma.test', 'Katalog Cek Firma');
        $companyId = $ctx['company_id'];

        // Dummy kimlik bilgileri: gerici sir yok, yalnizca rest1 okuma yolu kurulur.
        (new CredentialVault)->put(
            $companyId,
            Channel::Tsoft,
            ['user' => 'dummy-rest-user', 'pass' => 'dummy-rest-pass'],
            'dummy-webhook-secret',
            [],
            [],
            Driver::Rest1,
            'magaza.test',
            'magaza',
        );

        Http::fake([
            'https://magaza.test/rest1/auth/login/*' => Http::response([
                'data' => [['token' => 'dummy-token', 'expirationTime' => time() + 3600]],
            ], 200),
            'https://magaza.test/rest1/product/getProducts' => Http::response([
                'Data' => [[
                    'ProductCode' => 'CAT-001',
                    'ProductName' => 'Ornek Urun',
                    'SellingPrice' => '199.90',
                    'Stock' => '12',
                    'Currency' => 'TRY',
                    'Barcode' => 'BC-001',
                    'Vat' => '18',
                ]],
            ], 200),
            'https://magaza.test/rest1/customer/getCustomers' => Http::response([
                'Data' => [[
                    'CustomerId' => '1001',
                    'CustomerCode' => 'C-1001',
                    'Name' => 'Ayse',
                    'Surname' => 'Yilmaz',
                    'TaxNo' => '1234567890',
                    'Email' => 'dummy@example.test',
                    'Phone' => '05550000000',
                    'City' => 'Izmir',
                    'Address' => 'Ornek Cadde No:1',
                ]],
            ], 200),
            '*' => Http::response(['Data' => []], 200),
        ]);

        $summary = app(CatalogPullService::class)->pull($companyId);

        $this->assertSame([], $summary['errors']);
        $this->assertGreaterThanOrEqual(1, $summary['products']);
        $this->assertGreaterThanOrEqual(1, $summary['prices']);
        $this->assertGreaterThanOrEqual(1, $summary['customers']);

        $product = Product::query()
            ->where('company_id', $companyId)
            ->where('sku', 'CAT-001')
            ->first();
        $this->assertNotNull($product);
        $this->assertSame('Ornek Urun', $product->name);

        $party = Party::query()
            ->where('company_id', $companyId)
            ->where('name', 'Ayse Yilmaz')
            ->first();
        $this->assertNotNull($party);

        // Okuma uclari cagrildi.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/rest1/product/getProducts'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/rest1/customer/getCustomers'));

        // Hicbir yazma ucu (setProducts / siparis yazimi) cagrilmadi.
        foreach (['setproducts', 'setorder', 'setprice'] as $forbidden) {
            Http::assertNotSent(
                fn ($request) => str_contains(strtolower($request->url().' '.$request->body()), $forbidden)
            );
        }
    }
}
