<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * PLAN LİMİTLERİ .
 *
 * Limitler config/plans.php'de durur; burada denetimin GERÇEKTEN o sayıya baktığı,
 * sayımın neyi kapsadığı ve mesajın çevrildiği kilitlenir. Kilitlenmeyen bir limit,
 * sonradan "neden 3 kullanıcı alamıyorum" sorusunu üretir — bu yüzden sayım kuralları
 * (bekleyen davet dahil, takvim ayı, dönüşümün yeni belge sayılması) testte yazılıdır.
 */
class CompanyPlanLimitsTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('plan@firma.test', 'Plan Firmasi');
        $this->planDurumu('testplan');

        config([
            'plans.plans.testplan' => [
                'label' => 'Test Planı',
                'limits' => [
                    'users' => 2,
                    'products' => 2,
                    'documents_per_month' => 2,
                    'payment_links_per_month' => 1,
                ],
            ],
        ]);
    }

    protected function planDurumu(string $plan): void
    {
        Company::where('id', $this->a['company_id'])->update(['plan' => $plan]);
    }

    protected function belgeOlustur(int $adet = 1): void
    {
        $cari = $this->apiPost('/api/v1/parties', ['name' => 'Limit Carisi'], $this->a['headers'])
            ->assertCreated()->json('party.id');

        for ($i = 0; $i < $adet; $i++) {
            $this->apiPost('/api/v1/documents', [
                'type' => 'invoice', 'party_id' => $cari,
                'items' => [['name' => 'Kalem '.$i, 'quantity' => 1, 'unit_price' => 10]],
            ], $this->a['headers'])->assertCreated();
        }
    }

    public function test_aylik_belge_limasi_asilinca_422_ve_cevrilmis_mesaj(): void
    {
        $this->belgeOlustur(2);

        $cari = $this->apiPost('/api/v1/parties', ['name' => 'Limit Carisi 2'], $this->a['headers'])
            ->assertCreated()->json('party.id');

        $res = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $cari,
            'items' => [['name' => 'Kalem 3', 'quantity' => 1, 'unit_price' => 10]],
        ], $this->a['headers'])->assertStatus(422);

        $res->assertJsonValidationErrors('plan_limit');
        $mesaj = $res->json('errors.plan_limit.0');
        $this->assertStringContainsString('2/2', $mesaj, 'kullanıcı kaç/kaç gördüğünü bilmeli: '.$mesaj);
        $this->assertStringContainsString('limit', $mesaj);
    }

    public function test_limit_mesaji_ingilizce_tercihte_ingilizce(): void
    {
        $this->apiPatch('/api/v1/me/locale', ['locale' => 'en'], $this->a['headers'])->assertOk();
        $this->belgeOlustur(2);

        $cari = $this->apiPost('/api/v1/parties', ['name' => 'Limit Carisi 2'], $this->a['headers'])
            ->assertCreated()->json('party.id');

        $mesaj = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $cari,
            'items' => [['name' => 'Kalem 3', 'quantity' => 1, 'unit_price' => 10]],
        ], $this->a['headers'])->assertStatus(422)->json('errors.plan_limit.0');

        $this->assertStringContainsString('document limit', $mesaj, 'EN cevap İngilizce olmalı: '.$mesaj);
        $this->assertStringNotContainsString('ulaştınız', $mesaj);
    }

    public function test_limisiz_plan_hicbir_yerde_durdurmaz(): void
    {
        $this->planDurumu('sinirsiz');

        $this->belgeOlustur(5);
        $this->assertSame(5, Document::where('company_id', $this->a['company_id'])->count());
    }

    public function test_bilinenmeyen_plan_adi_varsayilana_duser_limitsiz_sayilmaz(): void
    {
        // Sessiz "limitsiz" olmak en tehlikeli hâli: plan adı bozuksa varsayılan plan okunur.
        $this->planDurumu('yok-boyle-bir-plan');

        $snap = $this->apiGet('/api/v1/company/plan', $this->a['headers'])->assertOk()->json();
        $this->assertSame((string) config('plans.default'), $snap['plan'], 'bilinmeyen plan varsayılana düşmeli');
    }

    public function test_kullanici_limaci_bekleyen_davetleri_de_sayar(): void
    {
        // users = 2: sahibi + 1 davet. İlk davet geçer, ikincisi davet OLUŞURKEN engellenir
        // (kabul beklenmez; aksi hâlde limit davetlerle aşındırılabilir).
        $this->apiPost('/api/v1/invitations', ['email' => 'bir@firma.test', 'role' => 'Personel'], $this->a['headers'])
            ->assertCreated();

        $this->apiPost('/api/v1/invitations', ['email' => 'iki@firma.test', 'role' => 'Personel'], $this->a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('plan_limit');
    }

    public function test_urun_limaci(): void
    {
        $unit = $this->unitId($this->a);
        foreach (['A', 'B'] as $ad) {
            $this->apiPost('/api/v1/products', [
                'name' => 'Ürün '.$ad, 'unit_id' => $unit, 'cost_price' => 1, 'sale_price' => 2,
            ], $this->a['headers'])->assertCreated();
        }

        $this->apiPost('/api/v1/products', [
            'name' => 'Ürün C', 'unit_id' => $unit, 'cost_price' => 1, 'sale_price' => 2,
        ], $this->a['headers'])->assertStatus(422)->assertJsonValidationErrors('plan_limit');
    }

    public function test_diger_ayda_kesilen_belge_bu_ayin_limani_saymaz(): void
    {
        $this->belgeOlustur(2);

        // Bu ayın duası doldu; eski tarihli iki belgeyi bu aya taşı, sonra limitin boşaldığını gör.
        // (subMonth() 31 Ekim'de taşarak 1 Ekim'e döner — o yüzden ayın son günü kullanılır.)
        Document::where('company_id', $this->a['company_id'])
            ->update(['created_at' => now()->startOfMonth()->subDay()->format('Y-m-d H:i:s')]);

        $cari = $this->apiPost('/api/v1/parties', ['name' => 'Yeni Ay Carisi'], $this->a['headers'])
            ->assertCreated()->json('party.id');

        $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $cari,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
        ], $this->a['headers'])->assertCreated();
    }

    public function test_donusturulen_belge_de_limana_tabidir(): void
    {
        $cari = $this->apiPost('/api/v1/parties', ['name' => 'Donusum Carisi'], $this->a['headers'])
            ->assertCreated()->json('party.id');

        $teklif = $this->apiPost('/api/v1/documents', [
            'type' => 'quote', 'party_id' => $cari,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
        ], $this->a['headers'])->assertCreated()->json('document');

        // 2. belge: teklif → sipariş dönüşümü (yeni numara = yeni belge).
        $siparis = $this->apiPost("/api/v1/documents/{$teklif['id']}/convert", ['to' => 'sales_order'], $this->a['headers'])
            ->assertCreated()->json('document');

        // 3. belge hakkı yok: limit 2. (Teklif doğrudan faturaya dönüştürülemez; siparişten denenir.)
        $this->apiPost("/api/v1/documents/{$siparis['id']}/convert", ['to' => 'invoice'], $this->a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('plan_limit');
    }

    public function test_plan_kullanimi_uygulada_gorulebilir_hale_rapor_ediliyor(): void
    {
        $this->belgeOlustur(1);

        $snap = $this->apiGet('/api/v1/company/plan', $this->a['headers'])->assertOk()->json();

        $this->assertSame('testplan', $snap['plan']);
        $this->assertSame('Test Planı', $snap['label']);
        $this->assertNotEmpty($snap['limits']);

        $satirlar = collect($snap['limits'])->keyBy('resource');
        $this->assertSame(1, $satirlar['documents_per_month']['used']);
        $this->assertSame(2, $satirlar['documents_per_month']['limit']);
        $this->assertSame(1, $satirlar['documents_per_month']['remaining']);
        $this->assertSame(1, $satirlar['users']['used'], 'sahip zaten bir kullanıcı');
        $this->assertArrayHasKey('period', $snap);
    }
}
