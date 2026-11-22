<?php

namespace Tests\Feature\Api;

use App\Models\DashboardWidget;
use App\Models\Document;
use App\Models\Invitation;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * DASHBOARD WIDGET IZGARASI .
 *
 * Kilitlenen sözleşme: katalog varsayılanı → firma → rol → kullanıcı cascade'i;
 * yokluk = miras; havuz dışı (katalogda rolüne kapalı) widget kullanıcıya ASLA
 * gösterilmez ve kullanıcı satırı bile yazılamaz; Admin kapatınca Personel'de
 * görünmez, geri açınca tanım korunur.
 */
class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('pano@firma.test', 'Pano Firmasi');
    }

    protected function personelHeaders(): array
    {
        $this->apiPost('/api/v1/invitations', ['email' => 'uye@firma.test', 'role' => 'Personel'], $this->a['headers'])
            ->assertCreated();
        $uye = $this->register('uye@firma.test', 'Uye Firmasi');
        $token = Invitation::where('company_id', $this->a['company_id'])->first()->token;
        $this->apiPost('/api/v1/invitations/accept', ['token' => $token], $uye['headers'])->assertOk();
        $this->apiPost('/api/v1/companies/select', ['company_id' => $this->a['company_id']], $uye['headers'])->assertOk();

        return $uye['headers'];
    }

    protected function anahtarlar(array $izgara): array
    {
        return array_column($izgara, 'key');
    }

    public function test_varsayilan_izgara_katalogdan_gelir_veriyle_birlikte(): void
    {
        $izgara = $this->apiGet('/api/v1/dashboard/widgets', $this->a['headers'])
            ->assertOk()->json('data');

        $anahtarlar = $this->anahtarlar($izgara);

        foreach (['kpi', 'revenue_trend', 'due_calendar', 'fx_rates', 'low_stock', 'recent_activity', 'debt_summary'] as $beklenen) {
            $this->assertContains($beklenen, $anahtarlar, "{$beklenen} varsayılan ızgarada olmalı");
        }

        foreach ($izgara as $satir) {
            $this->assertArrayHasKey('data', $satir);
            $this->assertArrayHasKey('title_key', $satir);
        }
    }

    public function test_admin_kapatinca_personelde_gorunmez_geri_acınca_veri_korunur(): void
    {
        $uyeHeaders = $this->personelHeaders();

        $this->assertContains(
            'due_calendar',
            $this->anahtarlar($this->apiGet('/api/v1/dashboard/widgets', $uyeHeaders)->assertOk()->json('data'))
        );

        // Admin firma varsayılanını kapatır.
        $this->apiPatch('/api/v1/dashboard/scopes', [
            'rows' => [['scope' => 'company', 'widget_key' => 'due_calendar', 'enabled' => false]],
        ], $this->a['headers'])->assertOk();

        $this->assertNotContains(
            'due_calendar',
            $this->anahtarlar($this->apiGet('/api/v1/dashboard/widgets', $uyeHeaders)->assertOk()->json('data'))
        );

        // Geri açınca tanım korunur (satır güncellenir, çoğalmaz).
        $this->apiPatch('/api/v1/dashboard/scopes', [
            'rows' => [['scope' => 'company', 'widget_key' => 'due_calendar', 'enabled' => true]],
        ], $this->a['headers'])->assertOk();

        $this->assertSame(
            1,
            DashboardWidget::where('company_id', $this->a['company_id'])
                ->where('scope', 'company')->where('widget_key', 'due_calendar')->count()
        );
        $this->assertContains(
            'due_calendar',
            $this->anahtarlar($this->apiGet('/api/v1/dashboard/widgets', $uyeHeaders)->assertOk()->json('data'))
        );
    }

    public function test_rol_havuzu_personel_icin_kapatilabilir_admin_etkilenmez(): void
    {
        $uyeHeaders = $this->personelHeaders();

        $this->apiPatch('/api/v1/dashboard/scopes', [
            'rows' => [['scope' => 'role', 'subject' => 'Personel', 'widget_key' => 'low_stock', 'enabled' => false]],
        ], $this->a['headers'])->assertOk();

        $this->assertNotContains(
            'low_stock',
            $this->anahtarlar($this->apiGet('/api/v1/dashboard/widgets', $uyeHeaders)->assertOk()->json('data'))
        );
        $this->assertContains(
            'low_stock',
            $this->anahtarlar($this->apiGet('/api/v1/dashboard/widgets', $this->a['headers'])->assertOk()->json('data'))
        );
    }

    public function test_kullanici_kendi_yerlesimini_yazar_havuz_disi_yazamaz(): void
    {
        $uyeHeaders = $this->personelHeaders();

        // recent_activity katalogda yalnız Admin'e açık → Personel kendine açamaz.
        $this->apiPut('/api/v1/dashboard/layout', [
            'widgets' => [['key' => 'recent_activity', 'enabled' => true]],
        ], $uyeHeaders)->assertStatus(422)->assertJsonValidationErrors('widgets.0.key');

        // Havuz içi yerleşim serbesttir.
        $this->apiPut('/api/v1/dashboard/layout', [
            'widgets' => [
                ['key' => 'kpi', 'enabled' => false],
                ['key' => 'due_calendar', 'enabled' => true, 'sort' => 1],
            ],
        ], $uyeHeaders)->assertOk();

        $anahtarlar = $this->anahtarlar($this->apiGet('/api/v1/dashboard/widgets', $uyeHeaders)->assertOk()->json('data'));
        $this->assertNotContains('kpi', $anahtarlar);
        $this->assertContains('due_calendar', $anahtarlar);

        // Katalog dışı anahtar reddedilir.
        $this->apiPut('/api/v1/dashboard/layout', [
            'widgets' => [['key' => 'ufo-widget', 'enabled' => true]],
        ], $uyeHeaders)->assertStatus(422);
    }

    public function test_katalog_ve_kapsam_uclari(): void
    {
        $uyeHeaders = $this->personelHeaders();

        // Üye kataloğun tanımını görür (kendi yerleşimini düzenlemek için),
        // ama seviye satırlarını (`state`) göremez.
        $uyeKatalog = $this->apiGet('/api/v1/dashboard/widget-catalog', $uyeHeaders)
            ->assertOk()->json('data');

        // 07 Kas: çekirdek 7 widget + 7 bento kartı (TaskTrail v3 panosu) = 14.
        // Katalog LİSTE olarak döner; sayı yerine anahtarlar kilitlenir
        // (yeni kart eklenince test kırılmasın).
        $uyeAnahtarlar = array_column($uyeKatalog, 'key');
        $this->assertContains('kpi', $uyeAnahtarlar);
        $this->assertContains('year_finance', $uyeAnahtarlar);
        $this->assertContains('cash_flow', $uyeAnahtarlar);
        $this->assertContains('category_mix', $uyeAnahtarlar);
        $this->assertContains('debt_aging', $uyeAnahtarlar);
        $this->assertContains('top_customers', $uyeAnahtarlar);
        $this->assertArrayNotHasKey('state', $uyeKatalog[0]);

        // Kapsam yazımı yalnız Admin'e açık.
        $this->apiPatch('/api/v1/dashboard/scopes', ['rows' => []], $uyeHeaders)->assertForbidden();

        $katalog = $this->apiGet('/api/v1/dashboard/widget-catalog', $this->a['headers'])
            ->assertOk()->json('data');

        $this->assertContains('hr_applications', array_column($katalog, 'key'));
        $this->assertArrayHasKey('state', $katalog[0]);
        $this->assertSame(['Admin'], collect($katalog)->firstWhere('key', 'recent_activity')['roles']);
    }

    public function test_uye_katalogunda_key_alani_vardir_yerlesim_kaydedilebilir(): void
    {
        // 07 Kas hatası: üye kataloğunda `key` alanı yoktu → istemci `undefined`
        // gönderiyor, "Yerleşimi Kaydet" 422 veriyordu.
        $uyeHeaders = $this->personelHeaders();

        $katalog = $this->apiGet('/api/v1/dashboard/widget-catalog', $uyeHeaders)
            ->assertOk()->json('data');

        foreach ($katalog as $satir) {
            $this->assertArrayHasKey('key', $satir);
            $this->assertNotSame('', (string) $satir['key']);
        }

        $this->apiPut('/api/v1/dashboard/layout', [
            'widgets' => [
                ['key' => 'kpi', 'enabled' => true, 'sort' => 0],
                ['key' => 'revenue_trend', 'enabled' => true, 'sort' => 1],
            ],
        ], $uyeHeaders)->assertOk();
    }

    public function test_baska_firmanin_izgarasi_karismaz(): void
    {
        $b = $this->register('baska@firma.test', 'Baska Firmasi');

        $this->apiPatch('/api/v1/dashboard/scopes', [
            'rows' => [['scope' => 'company', 'widget_key' => 'kpi', 'enabled' => false]],
        ], $this->a['headers'])->assertOk();

        // B firmasında kpi görünür (A'nın kapatması B'yi etkilemez).
        $this->assertContains(
            'kpi',
            $this->anahtarlar($this->apiGet('/api/v1/dashboard/widgets', $b['headers'])->assertOk()->json('data'))
        );
    }

    public function test_veri_uclari_bos_firmada_bos_doner_hata_vermez(): void
    {
        $izgara = $this->apiGet('/api/v1/dashboard/widgets', $this->a['headers'])
            ->assertOk()->json('data');

        $kpi = collect($izgara)->firstWhere('key', 'kpi');
        $this->assertSame([], $kpi['data']['revenue_month']);
        $this->assertSame([], $kpi['data']['receivable']);

        $takvim = collect($izgara)->firstWhere('key', 'due_calendar');
        $this->assertSame([], $takvim['data']['items']);
    }

    public function test_takvim_gosterilen_aydaki_siparisleri_gosterir(): void
    {
        $party = Party::create([
            'company_id' => $this->a['company_id'],
            'type' => 'customer',
            'code' => 'TS1',
            'name' => 'Takvim Müşterisi',
            'is_active' => true,
        ]);

        // Vadesi olmayan, bugunku tarihli Tsoft siparisi takvimde gorunmeli.
        $bugun = Document::create([
            'company_id' => $this->a['company_id'],
            'type' => Document::TYPE_SALES_ORDER,
            'serial' => 'SIP-TAKVIM-1',
            'party_id' => $party->id,
            'status' => 'confirmed',
            'issue_date' => now()->format('Y-m-d'),
            'currency_code' => 'EUR',
            'grand_total' => 107.1,
        ]);

        // Yeni gelen Tsoft siparisleri taslak olur; takvimde gorunmeli.
        $taslak = Document::create([
            'company_id' => $this->a['company_id'],
            'type' => Document::TYPE_SALES_ORDER,
            'serial' => 'SIP-TAKVIM-TASLAK',
            'party_id' => $party->id,
            'status' => 'draft',
            'issue_date' => now()->format('Y-m-d'),
            'currency_code' => 'EUR',
            'grand_total' => 24.41,
        ]);

        // Ay disinda kalan bir belge takvimde yer almamali.
        Document::create([
            'company_id' => $this->a['company_id'],
            'type' => Document::TYPE_SALES_ORDER,
            'serial' => 'SIP-TAKVIM-ESKI',
            'party_id' => $party->id,
            'status' => 'confirmed',
            'issue_date' => now()->subYears(3)->format('Y-m-d'),
            'currency_code' => 'EUR',
            'grand_total' => 10,
        ]);

        $izgara = $this->apiGet('/api/v1/dashboard/widgets', $this->a['headers'])
            ->assertOk()->json('data');
        $takvim = collect($izgara)->firstWhere('key', 'due_calendar');
        $items = collect($takvim['data']['items']);

        $this->assertTrue($items->contains('document_id', $bugun->id));
        $this->assertTrue($items->contains('document_id', $taslak->id));
        $this->assertFalse($items->contains('serial', 'SIP-TAKVIM-ESKI'));
        $this->assertSame(now()->format('Y-m-d'), $items->firstWhere('document_id', $bugun->id)['due_date']);
    }
}
