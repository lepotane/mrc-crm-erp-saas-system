<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * DENETİM KAYDI .
 *
 * Kilitlenen sözleşme: her yazma işlemi iz bırakır (eylem + kim + hangi kayıt + özet);
 * kayıtlar salt okunurdur ve kiracı dışına sızmaz; sırlar (ödeme anahtarları) meta'ya
 * ASLA girmez; Personel listeyi göremez.
 */
class AuditTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('denetim@firma.test', 'Denetim Firmasi');
    }

    protected function cariAc(): int
    {
        return $this->apiPost('/api/v1/parties', ['name' => 'Denetim Carisi'], $this->a['headers'])
            ->assertCreated()->json('party.id');
    }

    public function test_belge_olusturma_iz_birakir(): void
    {
        $partyId = $this->cariAc();

        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ], $this->a['headers'])->assertCreated()->json('document');

        $olay = AuditEvent::where('action', 'document.created')->first();

        $this->assertNotNull($olay, 'belge oluşturma denetime düşmeli');
        $this->assertSame($this->a['company_id'], $olay->company_id);
        $this->assertSame($this->a['user_id'], $olay->user_id);
        $this->assertSame($belge['serial'], $olay->meta['serial']);

        // Liste YALNIZ Admin'e açık, kiracı süzgeçli, etiketi çevrilmiş.
        $satir = $this->apiGet('/api/v1/audit?action=document.created', $this->a['headers'])
            ->assertOk()->json('data.0');

        $this->assertSame('document.created', $satir['action']);
        $this->assertSame('Belge oluşturdu', $satir['action_label']);
        $this->assertSame('denetim@firma.test', $satir['user']['email']);
        $this->assertSame($belge['serial'], $satir['auditable']['label']);
    }

    public function test_personel_denetime_bakabilir_ama_listeyi_goremez_degildir(): void
    {
        // Personel davet → kabul → firma seçimi.
        $this->apiPost('/api/v1/invitations', ['email' => 'uye@firma.test', 'role' => 'Personel'], $this->a['headers'])
            ->assertCreated();
        $uye = $this->register('uye@firma.test', 'Uye Firmasi');
        $token = \App\Models\Invitation::where('company_id', $this->a['company_id'])->first()->token;
        $this->apiPost('/api/v1/invitations/accept', ['token' => $token], $uye['headers'])->assertOk();
        $this->apiPost('/api/v1/companies/select', ['company_id' => $this->a['company_id']], $uye['headers'])->assertOk();

        $this->apiGet('/api/v1/audit', $uye['headers'])->assertForbidden();
    }

    public function test_baska_firmanin_izi_gorunmez(): void
    {
        $this->cariAc();

        $b = $this->register('baska@firma.test', 'Baska Firmasi');

        $this->assertSame(0, $this->apiGet('/api/v1/audit', $b['headers'])->assertOk()->json('meta.total'));

        // B'nin kaydı A'nın listesine sızmaz.
        $this->apiPost('/api/v1/parties', ['name' => 'B Carisi'], $b['headers'])->assertCreated();
        $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $this->apiPost('/api/v1/parties', ['name' => 'B2'], $b['headers'])->assertCreated()->json('party.id'),
            'items' => [['name' => 'K', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0]],
        ], $b['headers'])->assertCreated();

        $eylemler = array_column(
            $this->apiGet('/api/v1/audit', $this->a['headers'])->assertOk()->json('data'),
            'action'
        );

        $this->assertNotContains('auth.login', $eylemler, 'B firmasının girişi A listesinde olmamalı');
    }

    public function test_durum_ve_odeme_akisi_iz_birakir(): void
    {
        $partyId = $this->cariAc();
        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => 0]],
        ], $this->a['headers'])->assertCreated()->json('document');

        $this->apiPost("/api/v1/documents/{$belge['id']}/status", ['status' => 'issued'], $this->a['headers'])->assertOk();

        $odeme = $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 200,
            'document_id' => $belge['id'], 'method' => 'cash',
        ], $this->a['headers'])->assertCreated()->json('payment');

        $this->apiDelete("/api/v1/payments/{$odeme['id']}", [], $this->a['headers'])->assertOk();

        $eylemler = AuditEvent::orderBy('id')->pluck('action')->all();

        foreach (['document.created', 'document.status', 'payment.created', 'payment.deleted'] as $beklenen) {
            $this->assertContains($beklenen, $eylemler, "{$beklenen} izi eksik");
        }

        $durum = AuditEvent::where('action', 'document.status')->first();
        $this->assertSame(['serial' => $belge['serial'], 'from' => 'draft', 'to' => 'issued'], $durum->meta);

        // Belirli kaydın geçmişi: auditable süzgeci.
        $gecmis = $this->apiGet(
            '/api/v1/audit?auditable_type=document&auditable_id='.$belge['id'],
            $this->a['headers']
        )->assertOk()->json('data');

        $this->assertNotEmpty($gecmis);
        foreach ($gecmis as $satir) {
            $this->assertSame($belge['id'], $satir['auditable']['id']);
        }
    }

    public function test_silinen_belgenin_izi_meta_ile_okunur(): void
    {
        $partyId = $this->cariAc();
        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'quote',
            'party_id' => $partyId,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0]],
        ], $this->a['headers'])->assertCreated()->json('document');

        $this->apiDelete("/api/v1/documents/{$belge['id']}", [], $this->a['headers'])->assertOk();

        $satir = $this->apiGet('/api/v1/audit?action=document.deleted', $this->a['headers'])
            ->assertOk()->json('data.0');

        // Kayıt silindi; iz meta'dan okunur (ölü kayıt yok, hedef null).
        $this->assertSame($belge['serial'], $satir['meta']['serial']);
        $this->assertNull($satir['auditable']);
        $this->assertSame([$belge['serial']], $satir['meta']['cascade']['belgeler']);
    }

    public function test_odeme_hesabi_izi_sir_tasimaz(): void
    {
        $this->apiPut('/api/v1/payment-settings/paytr', [
            'credentials' => [
                'merchant_id' => '100001',
                'merchant_key' => 'gizli-anahtar-degeri',
                'merchant_salt' => 'gizli-salt-degeri',
            ],
            'is_active' => true,
        ], $this->a['headers'])->assertOk();

        $olay = AuditEvent::where('action', 'payment_settings.updated')->first();
        $this->assertNotNull($olay);

        $ham = json_encode($olay->meta);
        $this->assertStringNotContainsString('gizli-anahtar-degeri', $ham, 'sır meta\'ya sızmamalı');
        $this->assertStringNotContainsString('gizli-salt-degeri', $ham);
        $this->assertSame('paytr', $olay->meta['provider']);
    }

    public function test_giris_cikis_iz_birakir(): void
    {
        $this->apiPost('/api/v1/auth/login', [
            'email' => 'denetim@firma.test',
            'password' => 'parola1234',
        ])->assertOk();

        $this->assertSame(1, AuditEvent::where('action', 'auth.login')->count());

        $this->apiPost('/api/v1/auth/logout', [], $this->a['headers'])->assertOk();

        $this->assertSame(1, AuditEvent::where('action', 'auth.logout')->count());
    }

    public function test_bilinmeyen_eylem_kodu_kabul_etmez(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        \App\Support\Audit::record('ufo.geldi', $this->a['company_id'], $this->a['user_id']);
    }
}
