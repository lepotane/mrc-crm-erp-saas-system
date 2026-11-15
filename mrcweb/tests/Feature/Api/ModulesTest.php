<?php

namespace Tests\Feature\Api;

use App\Models\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * MODÜL + EKLENTİ ALTYAPISI .
 *
 * Kilitlenen sözleşme: satır yoksa modül AÇIK (lazy default); kapatılan modül
 * API'den 403 `{code: module_disabled}`, menüden ve ızgaradan kaybolur; VERİ
 * durur, yeniden açma kayıpsız döner. Örnek eklenti (notlar) keşif → kurulum →
 * widget akışıyla kilitli.
 */
class ModulesTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    /** @var array<string,mixed> */
    protected array $b = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('modul@firma.test', 'Modul Firmasi');
        $this->b = $this->register('modul@firma-b.test', 'Modul Firmasi B');
    }

    public function test_varsayilanda_tum_cekirdek_moduller_acik_ve_stok_ucalari_calisir(): void
    {
        $moduller = $this->apiGet('/api/v1/modules', $this->a['headers'])->assertOk()->json('data');

        $anahtarlar = array_column($moduller, 'key');

        foreach (['pano', 'cariler', 'urunler', 'stok', 'belgeler', 'borc-alacak', 'raporlar', 'odeme', 'doviz', 'ekip'] as $beklenen) {
            $this->assertContains($beklenen, $anahtarlar, "{$beklenen} listede olmalı");
        }

        foreach ($moduller as $m) {
            if ($m['source'] === 'core') {
                $this->assertTrue($m['enabled'], "{$m['key']} varsayılan açık olmalı");
                $this->assertTrue($m['installed'], "{$m['key']} çekirdek kurulu sayılmalı");
            }
        }

        // Keşfedilip kurulmamış eklenti listede görünür ama kapalı sayılır.
        $this->assertContains('notlar', $anahtarlar, 'Örnek eklenti keşifte görünmeli');

        // Admin config anahtarını görür (üye göremez — aşağıdaki personel testinde kilitli).
        $this->assertArrayHasKey('config', $moduller[0]);
    }

    public function test_stok_kapatilinca_uclar_module_disabled_doner_veri_durur(): void
    {
        $depoId = $this->apiPost('/api/v1/warehouses', ['name' => 'Yedek Depo'], $this->a['headers'])
            ->assertCreated()->json('warehouse.id');

        $this->apiPatch('/api/v1/modules/stok', ['enabled' => false], $this->a['headers'])
            ->assertOk()->assertJsonPath('module.enabled', false);

        $kapali = $this->apiGet('/api/v1/warehouses', $this->a['headers'])->assertForbidden();
        $this->assertSame('module_disabled', $kapali->json('code'));
        $this->assertSame('stok', $kapali->json('module'));

        $this->apiPost('/api/v1/stock-movements', [], $this->a['headers'])->assertForbidden()->assertJsonPath('code', 'module_disabled');

        // Yeniden açınca veri kayıpsız döner.
        $this->apiPatch('/api/v1/modules/stok', ['enabled' => true], $this->a['headers'])->assertOk();

        $liste = $this->apiGet('/api/v1/warehouses', $this->a['headers'])->assertOk()->json('warehouses');
        $this->assertContains($depoId, array_column($liste, 'id'));

        $this->assertSame(
            1,
            Module::where('company_id', $this->a['company_id'])->where('key', 'stok')->count(),
            'Aç-kapa satırı çoğaltmamalı'
        );
    }

    public function test_baska_firmanin_modul_karari_karismaz(): void
    {
        $this->apiPatch('/api/v1/modules/stok', ['enabled' => false], $this->a['headers'])->assertOk();

        // B firmasında stok açık kalır.
        $this->apiGet('/api/v1/warehouses', $this->b['headers'])->assertOk();
        $this->assertSame(403, $this->apiGet('/api/v1/warehouses', $this->a['headers'])->getStatusCode());
    }

    public function test_bilinmeyen_modul_404_pano_kapatilamaz_422(): void
    {
        $this->apiPatch('/api/v1/modules/olmayan-modul', ['enabled' => false], $this->a['headers'])
            ->assertNotFound();

        $this->apiPatch('/api/v1/modules/pano', ['enabled' => false], $this->a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('enabled');
    }

    public function test_personel_modul_yazamaz_listeyi_gorur(): void
    {
        $uye = $this->register('modul-uye@firma.test', 'Uye Firmasi');

        $this->apiPost('/api/v1/invitations', ['email' => 'modul-uye@firma.test', 'role' => 'Personel'], $this->a['headers'])->assertCreated();
        $token = \App\Models\Invitation::where('company_id', $this->a['company_id'])->first()->token;
        $this->apiPost('/api/v1/invitations/accept', ['token' => $token], $uye['headers'])->assertOk();
        $this->apiPost('/api/v1/companies/select', ['company_id' => $this->a['company_id']], $uye['headers'])->assertOk();

        $this->apiPatch('/api/v1/modules/stok', ['enabled' => false], $uye['headers'])->assertForbidden();
        $liste = $this->apiGet('/api/v1/modules', $uye['headers'])->assertOk()->json('data');
        $this->assertArrayNotHasKey('config', $liste[0]);
    }

    public function test_ornek_eklenti_kesif_kurulum_widget_akisi(): void
    {
        $kesif = $this->apiPost('/api/v1/plugins/discover', [], $this->a['headers'])
            ->assertOk()->json('data');

        $anahtarlar = array_column($kesif, 'key');
        $this->assertContains('notlar', $anahtarlar, 'Örnek eklenti keşfedilmeli');

        // Kurulum öncesi katalogda eklenti widget'ı yok.
        $katalog = $this->apiGet('/api/v1/dashboard/widget-catalog', $this->a['headers'])->assertOk()->json('data');
        $this->assertNotContains('notlar', array_column($katalog, 'key'));

        $this->apiPost('/api/v1/plugins/install', ['key' => 'notlar'], $this->a['headers'])
            ->assertOk()->assertJsonPath('module.key', 'notlar');

        // Kurulum sonrası katalog + ızgara eklenti widget'ını taşır.
        $katalog = $this->apiGet('/api/v1/dashboard/widget-catalog', $this->a['headers'])->assertOk()->json('data');
        $this->assertContains('notlar', array_column($katalog, 'key'));

        $izgara = $this->apiGet('/api/v1/dashboard/widgets', $this->a['headers'])->assertOk()->json('data');
        $this->assertContains('notlar', array_column($izgara, 'key'));

        // Config yazısı widget verisine yansır.
        $this->apiPatch('/api/v1/modules/notlar', ['config' => ['text' => 'Kasa sayımı cuma.']], $this->a['headers'])->assertOk();

        $izgara = $this->apiGet('/api/v1/dashboard/widgets', $this->a['headers'])->assertOk()->json('data');

        foreach ($izgara as $satir) {
            if ($satir['key'] === 'notlar') {
                $this->assertSame('Kasa sayımı cuma.', $satir['data']['text'] ?? null);
            }
        }

        // Bilinmeyen eklenti kurulumu 404.
        $this->apiPost('/api/v1/plugins/install', ['key' => 'olmayan-eklenti'], $this->a['headers'])->assertNotFound();
    }

    public function test_kapali_modulun_widgeti_izgarada_gorunmez(): void
    {
        // doviz kapanınca fx_rates havuzdan düşer (widget kayıtları durur).
        $this->apiPatch('/api/v1/modules/doviz', ['enabled' => false], $this->a['headers'])->assertOk();

        $izgara = $this->apiGet('/api/v1/dashboard/widgets', $this->a['headers'])->assertOk()->json('data');
        $this->assertNotContains('fx_rates', array_column($izgara, 'key'));
    }
}
