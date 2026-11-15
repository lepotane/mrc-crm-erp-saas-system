<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faz 5 — erişim anahtarı (PAT) yönetimi + yetenek (ability) sözleşmesi.
 *
 * Kilitlenen davranışlar:
 * - düz metin anahtar yalnız üretim cevabındadır, listede asla dönmez;
 * - 'read' anahtarı GET'e açıktır, yazma isteğinde 403 + çevrilmiş mesaj;
 * - 'write' / '*' anahtarı işlem yapabilir;
 * - anahtar yalnız sahibinin listesinde görünür, başkasınınki 404;
 * - iptal edilen anahtar 401 verir.
 */
class TokensTest extends TestCase
{
    use RefreshDatabase;

    protected function registerUser(string $email = 'sahip@firma-a.test', string $company = 'Firma A'): string
    {
        return $this->apiPost('/api/v1/auth/register', [
            'name' => 'Sahip Kullanıcı',
            'email' => $email,
            'password' => 'parola1234',
            'company_name' => $company,
        ])->assertCreated()->json('token');
    }

    protected function headersFrom(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /** @return array{id:int, plain:string, headers:array<string,string>} */
    protected function createToken(string $authToken, string $name, ?array $abilities = null): array
    {
        $payload = ['name' => $name];
        if ($abilities !== null) {
            $payload['abilities'] = $abilities;
        }

        $res = $this->apiPost('/api/v1/me/tokens', $payload, $this->headersFrom($authToken))->assertCreated();

        return [
            'id' => (int) $res->json('token.id'),
            'plain' => (string) $res->json('plain_text_token'),
            'headers' => $this->headersFrom((string) $res->json('plain_text_token')),
        ];
    }

    public function test_erisim_anahtari_uretilir_ve_duz_metin_yalniz_ueretimde_gorunur(): void
    {
        $token = $this->registerUser();

        $res = $this->apiPost('/api/v1/me/tokens', ['name' => 'Masaüstü istemci'], $this->headersFrom($token))
            ->assertCreated()
            ->assertJsonPath('token.name', 'Masaüstü istemci')
            ->assertJsonPath('token.abilities', ['*']);

        $plain = $res->json('plain_text_token');
        $this->assertIsString($plain);
        $this->assertMatchesRegularExpression('/^\d+\|[0-9A-Za-z]{40,}$/', $plain);
        $this->assertNotEmpty($res->json('message'));

        // liste cevabında düz metin YOK (ve token özeti de taşınmıyor)
        $list = $this->apiGet('/api/v1/me/tokens', $this->headersFrom($token))
            ->assertOk()
            ->assertJsonStructure(['tokens' => [['id', 'name', 'abilities', 'last_used_at', 'created_at', 'expires_at']]])
            ->json();

        $satir = collect($list['tokens'])->firstWhere('name', 'Masaüstü istemci');
        $this->assertNotNull($satir, 'üretilen anahtar listede görünmüyor');
        $this->assertSame(['*'], $satir['abilities']);
        $this->assertArrayNotHasKey('plain_text_token', $satir);
        $this->assertStringNotContainsString($plain, json_encode($list, JSON_UNESCAPED_UNICODE));
    }

    public function test_uretilen_anahtar_api_isteginde_kullanilabilir_ve_sonu_kullanim_isaretlenir(): void
    {
        $token = $this->registerUser();
        ['plain' => $plain] = $this->createToken($token, 'Rapor robotu', ['read']);

        $this->apiGet('/api/v1/me', $this->headersFrom($plain))->assertOk()
            ->assertJsonPath('user.email', 'sahip@firma-a.test');

        $list = $this->apiGet('/api/v1/me/tokens', $this->headersFrom($token))->assertOk()->json('tokens');
        $satir = collect($list)->firstWhere('name', 'Rapor robotu');
        $this->assertNotNull($satir);
        $this->assertNotNull($satir['last_used_at'], 'kullanım zamanı işlenmedi');
    }

    public function test_read_anahtari_get_yapabilir_post_edemez_403(): void
    {
        $token = $this->registerUser();
        ['headers' => $headers] = $this->createToken($token, 'Salt okuma', ['read']);

        $this->apiGet('/api/v1/parties', $headers)->assertOk();

        $res = $this->apiPost('/api/v1/parties', [
            'name' => 'Yazılmamalı',
            'type' => 'customer',
        ], $headers)->assertStatus(403);

        $this->assertSame('Bu erişim anahtarının bu işlem için yetkisi yok.', $res->json('message'));
        $this->assertDatabaseMissing('parties', ['name' => 'Yazılmamalı']);
    }

    public function test_write_anahtari_islem_yapabilir(): void
    {
        $token = $this->registerUser();
        ['headers' => $headers] = $this->createToken($token, 'Kasiyer', ['write']);

        $this->apiPost('/api/v1/parties', ['name' => 'Yazılabilir', 'type' => 'customer'], $headers)
            ->assertCreated();
        $this->apiGet('/api/v1/parties', $headers)->assertOk();
    }

    public function test_read_anahtari_davet_kabul_edemez(): void
    {
        // okuma anahtarı POST /invitations/accept'te de durur (yazma sayılır)
        $token = $this->registerUser();
        ['headers' => $headers] = $this->createToken($token, 'Salt okuma', ['read']);

        $this->apiPost('/api/v1/invitations/accept', ['token' => str_repeat('a', 64)], $headers)
            ->assertStatus(403);
    }

    public function test_gecersiz_yetenek_422(): void
    {
        $token = $this->registerUser();

        $this->apiPost('/api/v1/me/tokens', ['name' => 'Yanlış', 'abilities' => ['sudo']], $this->headersFrom($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('abilities.0');
    }

    public function test_baskasinin_anahtari_listelenmez_ve_sililemez_404(): void
    {
        $ownerToken = $this->registerUser('sahip@firma-a.test', 'Firma A');
        $otherToken = $this->registerUser('baska@firma-b.test', 'Firma B');

        ['id' => $id] = $this->createToken($ownerToken, 'Sahibin anahtarı');

        $list = $this->apiGet('/api/v1/me/tokens', $this->headersFrom($otherToken))->assertOk()->json('tokens');
        foreach ($list as $row) {
            $this->assertNotSame($id, $row['id']);
        }

        $this->apiDelete("/api/v1/me/tokens/{$id}", [], $this->headersFrom($otherToken))->assertStatus(404);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $id]);
    }

    public function test_anahtar_silinince_artik_calismaz_401(): void
    {
        $token = $this->registerUser();
        ['id' => $id, 'plain' => $plain] = $this->createToken($token, 'Geçici');

        $this->apiGet('/api/v1/me', $this->headersFrom($plain))->assertOk();

        $this->apiDelete("/api/v1/me/tokens/{$id}", [], $this->headersFrom($token))
            ->assertOk()
            ->assertJsonPath('message', 'Erişim anahtarı iptal edildi.');

        $this->apiGet('/api/v1/me', $this->headersFrom($plain))->assertStatus(401);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
    }

    public function test_anahtar_olmadigi_zaman_404(): void
    {
        $token = $this->registerUser();

        $this->apiDelete('/api/v1/me/tokens/999999', [], $this->headersFrom($token))->assertStatus(404);
    }

    public function test_kendi_oturum_anahtari_iptal_edilemez(): void
    {
        // Kullanıcı şu an kullandığı anahtarı silerse anında düşer; açık gerekçeyle reddedilir.
        $token = $this->registerUser();
        $currentId = $this->apiGet('/api/v1/me/tokens', $this->headersFrom($token))
            ->assertOk()
            ->json('tokens.0.id');

        $res = $this->apiDelete("/api/v1/me/tokens/{$currentId}", [], $this->headersFrom($token))->assertStatus(422);
        $this->assertSame('Bu erişim anahtarı şu anki oturumunundur; önce çıkış yapın.', $res->json('message'));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentId]);
    }

    public function test_oturum_anahtari_sureli_uretilir_cikis_unutulsa_duser(): void
    {
        $token = $this->registerUser();

        $satirlar = $this->apiGet('/api/v1/me/tokens', $this->headersFrom($token))->assertOk()->json('tokens');
        $oturum = collect($satirlar)->firstWhere('name', 'auth');

        $this->assertNotNull($oturum['expires_at'], 'oturum anahtarı süresiz üretildi');
        $this->assertGreaterThan(time() + 13 * 86400, strtotime($oturum['expires_at']));
        $this->assertLessThan(time() + 15 * 86400, strtotime($oturum['expires_at']));
    }

    public function test_liste_kind_ve_current_isareti_doner(): void    {
        $token = $this->registerUser();
        ['id' => $apiTokenId] = $this->createToken($token, 'Rapor robotu', ['read']);

        // liste, oturum anahtarıyla isteniyor → „current” olan odur
        $satirlar = $this->apiGet('/api/v1/me/tokens', $this->headersFrom($token))->assertOk()->json('tokens');

        $api = collect($satirlar)->firstWhere('id', $apiTokenId);
        $oturum = collect($satirlar)->firstWhere('name', 'auth');

        $this->assertSame('api', $api['kind']);
        $this->assertFalse($api['current']);
        $this->assertSame('session', $oturum['kind']);
        $this->assertTrue($oturum['current'], 'istekte kullanılan oturum anahtarı işaretlenmedi');
    }
}
