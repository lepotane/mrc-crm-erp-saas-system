<?php

namespace Tests\Feature\Api;

use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * İKİ FAKTÖRLÜ GİRİŞ .
 *
 * Kilitlenen davranış: sır üretilince 2FA ETKİN DEĞİLDİR (önce telefon onayı);
 * giriş 202 + sınama anahtarı döner, tam oturum yalnız kodla açılır; 5 yanlışta
 * sınama ölür; yedek kod tek kullanımlıktır.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('cift@firma.test', 'Cift Faktor Firmasi');
    }

    /** Kullanıcıyı 2FA'lı hâle getirir; yedek kodları döner. */
    protected function etkinlestir(array $headers): array
    {
        $secret = $this->apiPost('/api/v1/me/2fa/setup', [], $headers)
            ->assertOk()->json('secret');

        return [
            'secret' => $secret,
            'recovery_codes' => $this->apiPost('/api/v1/me/2fa/confirm', [
                'code' => TwoFactor::generateCode($secret),
            ], $headers)->assertOk()->json('recovery_codes'),
        ];
    }

    public function test_kurulum_sir_doner_ama_etkinlestirmez(): void
    {
        $res = $this->apiPost('/api/v1/me/2fa/setup', [], $this->a['headers'])->assertOk();

        $this->assertNotEmpty($res->json('secret'));
        $this->assertStringStartsWith('otpauth://totp/', $res->json('otpauth_url'));

        $me = $this->apiGet('/api/v1/me', $this->a['headers'])->assertOk()->json('user');
        $this->assertFalse($me['two_factor_enabled'], 'onaysız sır etkin sayılmamalı');
    }

    public function test_yanlis_kodla_onay_reddedilir(): void
    {
        $this->apiPost('/api/v1/me/2fa/setup', [], $this->a['headers'])->assertOk();

        $this->apiPost('/api/v1/me/2fa/confirm', ['code' => '000000'], $this->a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $me = $this->apiGet('/api/v1/me', $this->a['headers'])->assertOk()->json('user');
        $this->assertFalse($me['two_factor_enabled']);
    }

    public function test_onay_gecerli_kodla_etkinlesir_yedek_kod_doner(): void
    {
        $kur = $this->etkinlestir($this->a['headers']);

        $this->assertCount(TwoFactor::RECOVERY_CODE_COUNT, $kur['recovery_codes']);

        $me = $this->apiGet('/api/v1/me', $this->a['headers'])->assertOk()->json('user');
        $this->assertTrue($me['two_factor_enabled']);
    }

    public function test_ikinci_kurulum_etkinken_reddedilir(): void
    {
        $this->etkinlestir($this->a['headers']);

        $this->apiPost('/api/v1/me/2fa/setup', [], $this->a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('two_factor');
    }

    public function test_giris_2fa_varsa_sinama_doner_tam_anahtar_vermez(): void
    {
        $kur = $this->etkinlestir($this->a['headers']);

        $res = $this->apiPost('/api/v1/auth/login', [
            'email' => 'cift@firma.test',
            'password' => 'parola1234',
        ])->assertStatus(202);

        $res->assertJsonPath('requires_two_factor', true);
        $this->assertArrayNotHasKey('token', $res->json());
        $challenge = $res->json('challenge_token');
        $this->assertNotEmpty($challenge);

        // Sınama anahtarıyla normal uç çalışmaz (yalnız challenge ucu).
        $this->apiGet('/api/v1/me', ['Authorization' => 'Bearer '.$challenge])->assertForbidden();

        // Doğru kod → tam oturum.
        $tam = $this->apiPost('/api/v1/auth/2fa/challenge', [
            'code' => TwoFactor::generateCode($kur['secret']),
        ], ['Authorization' => 'Bearer '.$challenge])->assertOk();

        $this->assertNotEmpty($tam->json('token'));
        $this->apiGet('/api/v1/me', ['Authorization' => 'Bearer '.$tam->json('token')])->assertOk();

        // Sınama anahtarı tek kullanımlık: ikinci kez geçmez.
        $this->apiPost('/api/v1/auth/2fa/challenge', [
            'code' => TwoFactor::generateCode($kur['secret']),
        ], ['Authorization' => 'Bearer '.$challenge])->assertUnauthorized();
    }

    public function test_bes_yanlis_sinama_oldurur(): void
    {
        $this->etkinlestir($this->a['headers']);

        $challenge = $this->apiPost('/api/v1/auth/login', [
            'email' => 'cift@firma.test',
            'password' => 'parola1234',
        ])->assertStatus(202)->json('challenge_token');

        $headers = ['Authorization' => 'Bearer '.$challenge];

        for ($i = 1; $i <= 4; $i++) {
            $this->apiPost('/api/v1/auth/2fa/challenge', ['code' => '000000'], $headers)
                ->assertStatus(422)
                ->assertJsonPath('errors.code.0', 'Kod hatalı. Kalan deneme: '.(5 - $i));
        }

        // 5. yanlış: sınama ölür, yeniden giriş gerekir.
        $this->apiPost('/api/v1/auth/2fa/challenge', ['code' => '000000'], $headers)->assertForbidden();
        $this->apiPost('/api/v1/auth/2fa/challenge', ['code' => '000000'], $headers)->assertUnauthorized();
    }

    public function test_yedek_kodla_giris_tek_kullanimliktir(): void
    {
        $kur = $this->etkinlestir($this->a['headers']);

        $login = fn () => $this->apiPost('/api/v1/auth/login', [
            'email' => 'cift@firma.test',
            'password' => 'parola1234',
        ])->assertStatus(202)->json('challenge_token');

        $kod = $kur['recovery_codes'][0];

        $ilk = $this->apiPost('/api/v1/auth/2fa/challenge', ['recovery_code' => $kod], [
            'Authorization' => 'Bearer '.$login(),
        ])->assertOk();

        $this->assertNotEmpty($ilk->json('token'));
        $this->assertSame(7, $ilk->json('recovery_codes_remaining'));

        // Aynı kod ikinci kez geçmez.
        $this->apiPost('/api/v1/auth/2fa/challenge', ['recovery_code' => $kod], [
            'Authorization' => 'Bearer '.$login(),
        ])->assertStatus(422);
    }

    public function test_normal_oturumla_challenge_ucu_reddedilir(): void
    {
        $this->etkinlestir($this->a['headers']);

        $this->apiPost('/api/v1/auth/2fa/challenge', ['code' => '000000'], $this->a['headers'])
            ->assertForbidden();
    }

    public function test_kapatma_sifre_ister(): void
    {
        $this->etkinlestir($this->a['headers']);

        $this->apiPost('/api/v1/me/2fa/disable', ['password' => 'yanlis-sifre'], $this->a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->apiPost('/api/v1/me/2fa/disable', ['password' => 'parola1234'], $this->a['headers'])
            ->assertOk()->assertJsonPath('two_factor_enabled', false);

        // Kapandıktan sonra giriş direkt tam anahtar döner.
        $this->apiPost('/api/v1/auth/login', [
            'email' => 'cift@firma.test',
            'password' => 'parola1234',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_yedek_kod_yenileme_eskileri_oldurur(): void
    {
        $kur = $this->etkinlestir($this->a['headers']);
        $eski = $kur['recovery_codes'][0];

        $yeni = $this->apiPost('/api/v1/me/2fa/recovery-codes', ['password' => 'parola1234'], $this->a['headers'])
            ->assertOk()->json('recovery_codes');

        $this->assertCount(8, $yeni);
        $this->assertNotContains($eski, $yeni);

        $challenge = $this->apiPost('/api/v1/auth/login', [
            'email' => 'cift@firma.test',
            'password' => 'parola1234',
        ])->assertStatus(202)->json('challenge_token');

        $this->apiPost('/api/v1/auth/2fa/challenge', ['recovery_code' => $eski], [
            'Authorization' => 'Bearer '.$challenge,
        ])->assertStatus(422);
    }

    public function test_pencere_disi_kod_reddedilir(): void
    {
        // TOTP matematiği: ±1 adım geçer, ±2 red (saat kayması hoşgörüsü sınırlı).
        $secret = TwoFactor::generateSecret();
        $simdi = TwoFactor::generateCode($secret);
        $artisik = TwoFactor::generateCode($secret, (int) floor(time() / 30) + 2);
        $eksik = TwoFactor::generateCode($secret, (int) floor(time() / 30) - 2);

        $this->assertTrue(TwoFactor::verify($secret, $simdi));
        $this->assertFalse(TwoFactor::verify($secret, $artisik));
        $this->assertFalse(TwoFactor::verify($secret, $eksik));
        $this->assertFalse(TwoFactor::verify($secret, 'abcdef'));
    }
}
