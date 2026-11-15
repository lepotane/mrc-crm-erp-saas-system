<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * OTURUM / CİHAZ YÖNETİMİ .
 *
 * „Hesabım nerelerde açık" sorusu: her oturumda IP + cihaz damgası vurulur, liste
 * bunları gösterir; „diğerlerini kapat" şu anki oturumu korur.
 */
class SessionDevicesTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('oturum@firma.test', 'Oturum Firmasi');
    }

    public function test_giris_oturumu_ip_damgasi_tasir(): void
    {
        $tokenlar = $this->apiGet('/api/v1/me/tokens', $this->a['headers'])
            ->assertOk()->json('tokens');

        $this->assertCount(1, $tokenlar);
        $this->assertSame('session', $tokenlar[0]['kind']);
        $this->assertTrue($tokenlar[0]['current']);
        $this->assertNotEmpty($tokenlar[0]['ip_address'], 'giriş damgasız kalmamalı');
        $this->assertNotEmpty($tokenlar[0]['device']);
    }

    public function test_uretilen_anahtar_da_damgalanir_ve_listede_gorunur(): void
    {
        $this->apiPost('/api/v1/me/tokens', ['name' => 'Masaustu'], $this->a['headers'])
            ->assertCreated();

        $tokenlar = $this->apiGet('/api/v1/me/tokens', $this->a['headers'])
            ->assertOk()->json('tokens');

        $this->assertCount(2, $tokenlar);

        $api = collect($tokenlar)->firstWhere('kind', 'api');
        $this->assertNotEmpty($api['ip_address']);
        $this->assertFalse($api['current']);
    }

    public function test_diger_oturumlari_kapat_su_ankini_korur(): void
    {
        // İkinci oturum: ayrı giriş (ayrı anahtar).
        $ikinci = $this->apiPost('/api/v1/auth/login', [
            'email' => 'oturum@firma.test',
            'password' => 'parola1234',
        ])->assertOk()->json('token');
        $ikinciHeaders = ['Authorization' => 'Bearer '.$ikinci];

        $this->apiPost('/api/v1/me/tokens', ['name' => 'Robot'], $this->a['headers'])->assertCreated();

        $this->assertCount(3, $this->apiGet('/api/v1/me/tokens', $this->a['headers'])
            ->assertOk()->json('tokens'));

        // Şu anki oturumdan „diğerlerini kapat".
        $res = $this->apiDelete('/api/v1/me/tokens/others', [], $this->a['headers'])
            ->assertOk();

        $this->assertSame(2, $res->json('revoked'));

        $kalan = $this->apiGet('/api/v1/me/tokens', $this->a['headers'])
            ->assertOk()->json('tokens');

        $this->assertCount(1, $kalan);
        $this->assertTrue($kalan[0]['current']);

        // Kapatılan anahtar artık geçmez.
        $this->apiGet('/api/v1/me', $ikinciHeaders)->assertUnauthorized();
    }

    public function test_kapatilacak_baska_oturum_yoksa_sifir_doner(): void
    {
        $res = $this->apiDelete('/api/v1/me/tokens/others', [], $this->a['headers'])->assertOk();

        $this->assertSame(0, $res->json('revoked'));
        $this->assertSame('Kapatılacak başka oturum yok.', $res->json('message'));
    }
}
