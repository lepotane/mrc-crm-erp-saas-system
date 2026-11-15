<?php

namespace Tests\Feature\Api;

use App\Models\CompanyJoinRequest;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * BENTO KARTLARININ GERÇEK VERİSİ (TaskTrail v3 panosu).
 *
 * 07 Kas öncesi bu kartlar istemcide UYDURMA sayılarla çiziliyordu:
 * nakit akışında "₺418,920 / ₺194,350", ay kapsüllerinde "640 işlem / ₺48,200",
 * yaşlandırmada "₺142,800", kur barında sabit TCMB oranları. Canlıda yanlış
 * rakam göstermemek için veri sunucudan geliyor.
 *
 * Bu test:
 *   1) yeni widget'ların ızgarada bulunduğunu,
 *   2) verinin GERÇEK kayıtlardan türediğini,
 *   3) kayıt yokken sıfır/boş döndüğünü (uydurma değer üretmediğini) kilitler.
 */
class DashboardBentoWidgetsTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('bento@firma.test', 'Bento Firmasi');
    }

    protected function izgara(): array
    {
        return $this->apiGet('/api/v1/dashboard/widgets', $this->a['headers'])
            ->assertOk()->json('data');
    }

    protected function widget(string $key): array
    {
        foreach ($this->izgara() as $satir) {
            if ($satir['key'] === $key) {
                return $satir['data'] ?? [];
            }
        }

        $this->fail("widget bulunamadı: {$key}");
    }

    protected function createParty(string $name = 'Müşteri Ltd'): int
    {
        return $this->apiPost('/api/v1/parties', ['name' => $name], $this->a['headers'])
            ->assertCreated()->json('party.id');
    }

    protected function createProduct(array $overrides = []): array
    {
        return $this->apiPost('/api/v1/products', array_merge([
            'name' => 'Bento Ürünü',
            'cost_price' => 50,
            'sale_price' => 100,
            'tax_rate' => 20,
        ], $overrides), $this->a['headers'])->assertCreated()->json('product');
    }

    public function test_yeni_bento_widgetlari_izgarada_var(): void
    {
        $anahtarlar = array_column($this->izgara(), 'key');

        foreach ([
            'year_finance', 'year_months', 'cash_flow',
            'category_mix', 'debt_aging', 'top_customers', 'hr_applications',
        ] as $beklenen) {
            $this->assertContains($beklenen, $anahtarlar, "{$beklenen} ızgarada olmalı");
        }
    }

    public function test_veri_yokken_sifir_doner_uydurma_deger_uretmez(): void
    {
        $finans = $this->widget('year_finance');
        $this->assertSame(0.0, (float) $finans['avg_transaction']);
        $this->assertSame(0.0, (float) $finans['avg_per_account']);
        $this->assertSame(0, (int) $finans['new_accounts']);

        $aylar = $this->widget('year_months')['months'];
        $this->assertCount(12, $aylar);
        foreach ($aylar as $ay) {
            $this->assertSame(0.0, (float) $ay['revenue']);
            $this->assertSame(0, (int) $ay['documents']);
        }

        $nakit = $this->widget('cash_flow');
        $this->assertCount(6, $nakit['months']);
        $this->assertSame(0.0, (float) $nakit['total_in']);
        $this->assertSame(0.0, (float) $nakit['total_out']);

        $this->assertSame([], $this->widget('category_mix')['items']);
        $this->assertSame([], $this->widget('top_customers')['items']);

        $bucket = $this->widget('debt_aging')['buckets'];
        $this->assertCount(4, $bucket);
        foreach ($bucket as $b) {
            $this->assertSame(0.0, (float) $b['receivable']);
            $this->assertSame(0.0, (float) $b['payable']);
        }

        $ik = $this->widget('hr_applications');
        $this->assertSame(0, (int) $ik['pending']);
        $this->assertSame(0, (int) $ik['with_cv']);
    }

    public function test_fatura_ve_odeme_gercek_veriye_donusur(): void
    {
        $partyId = $this->createParty('Bento Müşteri');
        $product = $this->createProduct();

        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ], $this->a['headers'])->assertCreated()->json('document');

        $this->apiPost("/api/v1/documents/{$belge['id']}/status", ['status' => 'issued'], $this->a['headers'])->assertOk();

        Payment::create([
            'company_id' => $this->a['company_id'],
            'party_id' => $partyId,
            'direction' => Payment::DIRECTION_IN,
            'amount' => 200,
            'currency_code' => 'TRY',
            'method' => Payment::METHOD_CASH,
            'paid_at' => now(),
        ]);

        $finans = $this->widget('year_finance');
        $this->assertSame(200.0, (float) ($finans['revenue'][0]['total'] ?? 0));
        $this->assertSame(200.0, (float) ($finans['collection'][0]['total'] ?? 0));
        $this->assertSame(200.0, (float) $finans['avg_transaction']);
        $this->assertSame(200.0, (float) $finans['avg_per_account']);
        $this->assertSame(1, (int) $finans['account_count']);

        $aylar = $this->widget('year_months')['months'];
        $this->assertSame(200.0, (float) end($aylar)['revenue']);
        $this->assertSame(1, (int) end($aylar)['documents']);

        $nakit = $this->widget('cash_flow');
        $this->assertSame(200.0, (float) $nakit['total_in']);
        $this->assertSame(0.0, (float) $nakit['total_out']);
        $this->assertSame(200.0, (float) end($nakit['months'])['in']);

        $musteriler = $this->widget('top_customers')['items'];
        $this->assertCount(1, $musteriler);
        $this->assertSame('Bento Müşteri', $musteriler[0]['name']);
        $this->assertSame(200.0, (float) $musteriler[0]['volume']);
        $this->assertSame(1, (int) $musteriler[0]['documents']);
    }

    public function test_kategori_dagilimi_urun_kategorisinden_gelir(): void
    {
        $categoryId = $this->apiPost('/api/v1/categories', ['name' => 'Yedek Parça'], $this->a['headers'])
            ->assertCreated()->json('category.id');

        $partyId = $this->createParty('Kategori Müşteri');
        $product = $this->createProduct(['category_id' => $categoryId]);

        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 3, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ], $this->a['headers'])->assertCreated()->json('document');

        $this->apiPost("/api/v1/documents/{$belge['id']}/status", ['status' => 'issued'], $this->a['headers'])->assertOk();

        $mix = $this->widget('category_mix');
        $this->assertCount(1, $mix['items']);
        $this->assertSame('Yedek Parça', $mix['items'][0]['category']);
        $this->assertSame(300.0, (float) $mix['items'][0]['total']);
        $this->assertSame(100.0, (float) $mix['items'][0]['pct']);
    }

    public function test_borc_yaslandirmasi_vade_dilimlerine_gore_hesaplanir(): void
    {
        $partyId = $this->createParty('Vade Müşteri');

        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'due_date' => now()->subDays(75)->format('Y-m-d'),
            'items' => [
                ['name' => 'Gecikmiş Kalem', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 0],
            ],
        ], $this->a['headers'])->assertCreated()->json('document');

        $this->apiPost("/api/v1/documents/{$belge['id']}/status", ['status' => 'issued'], $this->a['headers'])->assertOk();

        $bucket = [];
        foreach ($this->widget('debt_aging')['buckets'] as $b) {
            $bucket[$b['key']] = $b;
        }

        // 75 gün gecikmiş → 61-90 dilimi
        $this->assertSame(500.0, (float) $bucket['61_90']['receivable']);
        $this->assertSame(0.0, (float) $bucket['0_30']['receivable']);
    }

    public function test_ik_basvuru_ozeti_gercek_basarvurulari_tutar(): void
    {
        CompanyJoinRequest::create([
            'company_id' => $this->a['company_id'],
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@ornek.com',
            'password_hash' => bcrypt('parola1234'),
            'requested_class' => 'Personel',
            'status' => 'pending',
            'cv_path' => 'cvs/test.pdf',
        ]);

        $ik = $this->widget('hr_applications');
        $this->assertSame(1, (int) $ik['pending']);
        $this->assertSame(1, (int) $ik['with_cv']);
        $this->assertCount(1, $ik['items']);
        $this->assertSame('Ada Lovelace', $ik['items'][0]['name']);
        $this->assertTrue($ik['items'][0]['has_cv']);
    }
}
