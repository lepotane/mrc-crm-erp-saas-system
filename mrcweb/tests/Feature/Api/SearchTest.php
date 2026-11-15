<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * HIZLI ARAMA (üst bar): cari + belge + ürün tek uçta, kiracı izole.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('arama@firma.test', 'Arama Firmasi');
    }

    public function test_kisa_sorgu_reddedilir(): void
    {
        $this->apiGet('/api/v1/search?q=x', $this->a['headers'])->assertStatus(422);
        $this->apiGet('/api/v1/search', $this->a['headers'])->assertStatus(422);
    }

    public function test_uc_kaynaktan_eslesen_doner(): void
    {
        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Aranacak Cari'], $this->a['headers'])
            ->assertCreated()->json('party.id');

        $unitId = $this->unitId($this->a);
        $this->apiPost('/api/v1/products', [
            'name' => 'Aranacak Urun', 'unit_id' => $unitId, 'sale_price' => 5,
        ], $this->a['headers'])->assertCreated();

        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0]],
        ], $this->a['headers'])->assertCreated()->json('document');

        $res = $this->apiGet('/api/v1/search?q=Aranacak', $this->a['headers'])->assertOk()->json();

        $this->assertCount(1, $res['parties']);
        $this->assertSame('Aranacak Cari', $res['parties'][0]['name']);
        $this->assertCount(1, $res['products']);

        $res2 = $this->apiGet('/api/v1/search?q='.substr($belge['serial'], 0, 8), $this->a['headers'])
            ->assertOk()->json();

        $this->assertCount(1, $res2['documents']);
        $this->assertSame($belge['serial'], $res2['documents'][0]['serial']);
        $this->assertSame('Aranacak Cari', $res2['documents'][0]['party_name']);
    }

    public function test_baska_firmanin_kaydi_sizmaz(): void
    {
        $this->apiPost('/api/v1/parties', ['name' => 'Gizli Cari'], $this->a['headers'])->assertCreated();

        $b = $this->register('baska@firma.test', 'Baska Firmasi');

        $res = $this->apiGet('/api/v1/search?q=Gizli', $b['headers'])->assertOk()->json();

        $this->assertSame([], $res['parties']);
        $this->assertSame([], $res['documents']);
        $this->assertSame([], $res['products']);
    }
}
