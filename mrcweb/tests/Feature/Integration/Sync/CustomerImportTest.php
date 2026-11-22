<?php

namespace Tests\Feature\Integration\Sync;

use App\Integration\Sync\IntegrationIdMap;
use App\Integration\Sync\MrcResyncService;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * Tsoft customer/getCustomers çıktısı gerçek cari kayıtlarına dönüşmeli;
 * "Tsoft Müşteri" placeholder'ı yalnızca eşlenemeyen siparişler için kalır.
 */
class CustomerImportTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_gercek_musteriler_ice_aktarilir_ve_ikinci_calistirmada_cogalmaz(): void
    {
        $ctx = $this->register('musteri-ice@firma.test', 'Musteri Ice');
        $companyId = $ctx['company_id'];

        $fake = $this->fakeAdapter($this->rows());
        $service = new MrcResyncService(
            adapterFactory: fn () => $fake,
            backupRunner: fn () => 0,
        );

        $report = $service->run($companyId, true, false, true);

        $this->assertTrue($report['executed']);
        $this->assertSame('success', $report['status']);
        $this->assertSame(3, $report['imported']['customers']);
        $this->assertSame([], $report['errors']);

        $parties = Party::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $parties);
        $this->assertSame('Anadolu Ticaret A.Ş.', $parties[0]->name);
        $this->assertSame('1111111111', $parties[0]->tax_number);
        $this->assertSame('muhasebe@anadolu.test', $parties[0]->email);
        $this->assertSame('Ayşe Yılmaz', $parties[1]->name);
        $this->assertSame('22222222222', $parties[1]->tax_number);
        $this->assertSame('Marmara Ltd.', $parties[2]->name);
        $this->assertSame('3333333333', $parties[2]->tax_number);
        $this->assertStringContainsString('Konak', (string) $parties[0]->address);
        $this->assertStringContainsString('İzmir', (string) $parties[0]->address);
        $this->assertNull($parties->firstWhere('name', 'Tsoft Müşteri'));

        $maps = IntegrationIdMap::query()
            ->where('company_id', $companyId)
            ->where('mrc_type', 'party')
            ->where('external_type', 'customer')
            ->get();

        $this->assertCount(3, $maps);
        $map = $maps->firstWhere('external_key', '1002');
        $this->assertNotNull($map);
        $this->assertSame((int) $parties[1]->id, (int) $map->mrc_id);

        // İkinci tam resync silip yeniden kurar; aynı 3 cari ve 3 eşleme kalır.
        $second = $service->run($companyId, true, false, true);

        $this->assertSame('success', $second['status']);
        $this->assertSame(3, $second['imported']['customers']);
        $this->assertSame(3, Party::query()->where('company_id', $companyId)->count());
        $this->assertSame(3, IntegrationIdMap::query()
            ->where('company_id', $companyId)
            ->where('external_type', 'customer')
            ->count());
    }

    public function test_dry_run_musteri_olusturmaz_ve_adapteri_cagirmadan_doner(): void
    {
        $ctx = $this->register('musteri-dry@firma.test', 'Musteri Dry');
        $companyId = $ctx['company_id'];

        $calls = 0;
        $fake = $this->fakeAdapter($this->rows());
        $service = new MrcResyncService(
            adapterFactory: function () use (&$calls, $fake) {
                $calls++;

                return $fake;
            },
            backupRunner: fn () => 0,
        );

        $report = $service->run($companyId, true, true, true);

        $this->assertTrue($report['dry_run']);
        $this->assertFalse($report['executed']);
        $this->assertSame(0, $calls);
        $this->assertSame(0, $report['imported']['customers']);
        $this->assertArrayHasKey('customers', $report['plan']['import']);
        $this->assertSame(0, Party::query()->where('company_id', $companyId)->count());
        $this->assertSame(0, IntegrationIdMap::query()->where('company_id', $companyId)->count());
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(): array
    {
        return [
            [
                'CustomerId' => '1001',
                'CustomerCode' => 'C-1001',
                'CompanyName' => 'Anadolu Ticaret A.Ş.',
                'Name' => '',
                'Surname' => '',
                'TaxNo' => '1111111111',
                'Email' => 'muhasebe@anadolu.test',
                'Mobile' => '05550000001',
                'City' => 'İzmir',
                'Town' => 'Konak',
                'District' => 'Merkez',
                'Address' => 'Atatürk Caddesi No:1',
            ],
            [
                'CustomerId' => '1002',
                'CustomerCode' => 'C-1002',
                'CompanyName' => '',
                'Name' => 'Ayşe',
                'Surname' => 'Yılmaz',
                'TaxNo' => '',
                'IdentityNo' => '22222222222',
                'Email' => 'ayse@ornek.test',
                'Phone' => '02120000002',
                'City' => 'Ankara',
                'Address' => 'Cumhuriyet Sokak No:2',
            ],
            [
                'CustomerId' => '1003',
                'CustomerCode' => 'C-1003',
                'CompanyName' => 'Marmara Ltd.',
                'TaxNo' => '3333333333',
                'Email' => 'info@marmara.test',
                'City' => 'İstanbul',
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function fakeAdapter(array $rows): object
    {
        // Duck-typed adapter: gerçek HTTP çağrısı yapılmaz.
        return new class($rows)
        {
            /** @param array<int,array<string,mixed>> $rows */
            public function __construct(public readonly array $rows) {}

            public function fetchAllProducts(): array
            {
                return [];
            }

            public function fetchProducts(array $skus = []): array
            {
                return [];
            }

            public function fetchOrders(mixed $since = null, int $limit = 100): array
            {
                return [];
            }

            public function fetchAllCustomers(int $limit = 10000): array
            {
                return $this->rows;
            }
        };
    }
}
