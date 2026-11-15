<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * RAPOR SORGU BÜTÇESİ .
 *
 * Kusur böyle doğdu: `party-balances` cari bakiyeyi SQL'de topluyor, sonra her SATIR için
 * `PartyBalance::for()` ile iki sorgu daha açıyordu (belge + ödeme). 100 satır = 200 sorgu;
 * satır arttıkça rapor doğrusal olarak yavaşlıyordu ve bunu hiçbir test fark etmiyordu.
 *
 * Bu dosya davranışı değil MALİYETİ kilitler: sorgu sayısı satır sayısıyla BÜYÜMEMELİ.
 * (Altı carili koşuyla on iki carili koşunun sorgu sayısı eşitse toplu hesaplama şart.)
 * Sayıların değişmediği ayrıca doğrulanır — toplulaştırma refaktoru sonucu kaydırmasın.
 */
class ReportQueryBudgetTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,int> */
    protected array $ctx = [];

    /** Koşuda çalışan sorgu sayısı (DB::listen sayacı). */
    protected int $sorgu = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::listen(function () {
            $this->sorgu++;
        });

        $this->ctx = $this->register('butcet@firma.test', 'Sorgu Butcet Firmasi');
    }

    /** Verilen sayıda cari + her birine kesilmiş fatura + kısmi tahsilat. */
    protected function cariHazirla(int $adet, int $indexBaslangic = 1): void
    {
        for ($i = $indexBaslangic; $i < $indexBaslangic + $adet; $i++) {
            $party = $this->apiPost('/api/v1/parties', ['name' => sprintf('Cari %03d', $i)], $this->ctx['headers'])
                ->assertCreated()->json('party.id');

            $belge = $this->apiPost('/api/v1/documents', [
                'type' => 'invoice',
                'party_id' => $party,
                'issue_date' => '2026-09-01',
                'due_date' => '2026-09-15',
                'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 1000]],
            ], $this->ctx['headers'])->assertCreated()->json('document');

            $this->apiPost("/api/v1/documents/{$belge['id']}/status", ['status' => 'issued'], $this->ctx['headers'])->assertOk();

            $this->apiPost('/api/v1/payments', [
                'party_id' => $party,
                'document_id' => $belge['id'],
                'direction' => 'in',
                'amount' => 400,
                'method' => 'cash',
            ], $this->ctx['headers'])->assertCreated();
        }
    }

    /** Ucu çağırır ve o çağrının kaçar SORGU ürettiğini sayar. */
    protected function sorguSayisi(string $uri): array
    {
        // Sayaç setUp'ta bir kez bağlandı; burada yalnız fark alınıyor (Listener'ı
        // kaldırmak Connection::forgetListener(imza) istiyor, karışıklık çıkmasın).
        $onceBefore = $this->sorgu;

        $res = $this->apiGet($uri, $this->ctx['headers'])->assertOk();

        return ['queries' => $this->sorgu - $onceBefore, 'response' => $res];
    }

    public function test_sorgu_sayisi_cari_sayisiyla_buyumez(): void
    {
        $this->cariHazirla(6);
        $kucuk = $this->sorguSayisi('/api/v1/reports/party-balances?per_page=100');
        $this->assertSame(6, (int) $kucuk['response']->json('meta.total'), 'altı cari raporlanmalı');

        $this->cariHazirla(6, 7);
        $buyuk = $this->sorguSayisi('/api/v1/reports/party-balances?per_page=100');
        $this->assertSame(12, (int) $buyuk['response']->json('meta.total'), 'on iki cari raporlanmalı');

        // Satır iki katına çıktı ama sorgu sayısı çıkmamalı: döküm artık TOPLU çekiliyor.
        $this->assertSame(
            $kucuk['queries'],
            $buyuk['queries'],
            "cari iki katına çıkınca sorgu sayısı da artıyor: {$kucuk['queries']} → {$buyuk['queries']} (toplu hesaplama bekleniyor)"
        );
    }

    public function test_toplan_hesap_tekil_hesapla_ayni_sayilari_verir(): void
    {
        $this->cariHazirla(3);

        $rapor = $this->apiGet('/api/v1/reports/party-balances?per_page=100&sort=name', $this->ctx['headers'])
            ->assertOk()->json('data');

        $this->assertCount(3, $rapor);

        foreach ($rapor as $satir) {
            // Cari detayı tekil hesaplamadan geliyor (PartyBalance::for) — aynı sayı olmalı.
            $detay = $this->apiGet("/api/v1/parties/{$satir['id']}/balance", $this->ctx['headers'])
                ->assertOk()->json();

            $this->assertSame(
                (float) $satir['balance'],
                (float) $detay['totals']['balance'],
                'rapor bakiyesi ile cari detay bakiyesi ayrıştı: '.$satir['name']
            );
            $this->assertSame(
                (float) $satir['aging']['days_1_30'],
                (float) $detay['aging']['days_1_30'],
                'yaşlandırma kovası ayrıştı: '.$satir['name']
            );
        }
    }

    public function test_rapor_kiraci_disindaki_cariyi_dokenmez(): void
    {
        $b = $this->register('baska@firma.test', 'Baska Firmasi');
        $this->cariHazirla(1);

        $bCari = $this->apiPost('/api/v1/parties', ['name' => 'Baska Firmanin Carisi'], $b['headers'])
            ->assertCreated()->json('party.id');
        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $bCari,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 9999]],
        ], $b['headers'])->assertCreated()->json('document');
        $this->apiPost("/api/v1/documents/{$belge['id']}/status", ['status' => 'issued'], $b['headers'])->assertOk();

        $adlar = array_column($this->apiGet('/api/v1/reports/party-balances?per_page=100', $this->ctx['headers'])
            ->assertOk()->json('data'), 'name');

        $this->assertNotContains('Baska Firmanin Carisi', $adlar, 'toplu döküm kiracı süzgecini atlamamalı');
    }
}
