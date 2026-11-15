<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * RAPOR ÖNBELLEĞİ .
 *
 * Kilitlenen davranış: aynı rapor + aynı filtre ikinci kez DB'ye gitmez (HIT);
 * yazıdan sonra sürüm artar ve rapor TAZE veriyi gösterir (MISS). Önbellek
 * „göstermelik hız" değil, tutarlılık sözleşmesidir: bayat rapor kabul edilmez.
 */
class ReportCacheTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $ctx = [];

    protected int $sorgu = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::listen(function () {
            $this->sorgu++;
        });

        $this->ctx = $this->register('onbellek@firma.test', 'Onbellek Firmasi');
    }

    protected function faturaKes(int $partyId, float $tutar = 100.0): void
    {
        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => $tutar, 'tax_rate' => 0]],
        ], $this->ctx['headers'])->assertCreated()->json('document');

        $this->apiPost("/api/v1/documents/{$belge['id']}/status", ['status' => 'issued'], $this->ctx['headers'])->assertOk();
    }

    protected function raporCek(): array
    {
        $once = $this->sorgu;
        $res = $this->apiGet('/api/v1/reports/sales-summary?group_by=month', $this->ctx['headers'])->assertOk();

        return ['queries' => $this->sorgu - $once, 'response' => $res];
    }

    public function test_ikinci_ayni_istek_onbellegi_kullanir(): void
    {
        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Onbellek Carisi'], $this->ctx['headers'])
            ->assertCreated()->json('party.id');
        $this->faturaKes($partyId);

        $ilk = $this->raporCek();
        $this->assertSame('MISS', $ilk['response']->headers->get('X-Report-Cache'));

        $ikinci = $this->raporCek();
        $this->assertSame('HIT', $ikinci['response']->headers->get('X-Report-Cache'));

        $this->assertLessThan(
            $ilk['queries'],
            $ikinci['queries'],
            "ikinci çağrı DB'ye gitmemeli: ilk={$ilk['queries']} ikinci={$ikinci['queries']}"
        );

        $this->assertSame($ilk['response']->json('totals'), $ikinci['response']->json('totals'));
    }

    public function test_yazidan_sonra_rapor_taze_veriyi_gosterir(): void
    {
        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Onbellek Carisi'], $this->ctx['headers'])
            ->assertCreated()->json('party.id');
        $this->faturaKes($partyId, 100);

        $once = $this->raporCek();
        $this->assertSame(100.0, round((float) $once['response']->json('totals.gross_total'), 2));

        // Yeni fatura = yazma → sürüm artar → önbellek düşer.
        $this->faturaKes($partyId, 50);

        $sonra = $this->raporCek();
        $this->assertSame('MISS', $sonra['response']->headers->get('X-Report-Cache'));
        $this->assertSame(150.0, round((float) $sonra['response']->json('totals.gross_total'), 2));
    }

    public function test_farkli_filtre_ayri_anahtardir(): void
    {
        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Onbellek Carisi'], $this->ctx['headers'])
            ->assertCreated()->json('party.id');
        $this->faturaKes($partyId);

        $aylik = $this->apiGet('/api/v1/reports/sales-summary?group_by=month', $this->ctx['headers'])
            ->assertOk();
        $gunluk = $this->apiGet('/api/v1/reports/sales-summary?group_by=day', $this->ctx['headers'])
            ->assertOk();

        $this->assertSame('MISS', $aylik->headers->get('X-Report-Cache'));
        $this->assertSame('MISS', $gunluk->headers->get('X-Report-Cache'));

        $tekrar = $this->apiGet('/api/v1/reports/sales-summary?group_by=day', $this->ctx['headers'])
            ->assertOk();
        $this->assertSame('HIT', $tekrar->headers->get('X-Report-Cache'));
    }

    public function test_baska_firmanin_onbellegi_karismaz(): void
    {
        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Onbellek Carisi'], $this->ctx['headers'])
            ->assertCreated()->json('party.id');
        $this->faturaKes($partyId, 100);

        $this->raporCek(); // A'nın önbelleği dolar.

        $b = $this->register('baska-onb@firma.test', 'Baska Onb Firmasi');
        $bos = $this->apiGet('/api/v1/reports/sales-summary?group_by=month', $b['headers'])->assertOk();

        $this->assertSame('MISS', $bos->headers->get('X-Report-Cache'));
        $this->assertSame(0.0, round((float) $bos->json('totals.gross_total'), 2));
        $this->assertSame([], $bos->json('data'));
    }
}
